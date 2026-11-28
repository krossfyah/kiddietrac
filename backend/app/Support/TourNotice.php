<?php

namespace App\Support;

use App\Services\AgencyMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TELLING THE FAMILY WHEN A TOUR CHANGES (2026-11-28). The Tours screen changed a booking's status or time and the
 * prospective family heard nothing.
 *
 *   confirmed            -> TourMail::confirmed
 *   time moved (not cancelled/completed/no-show) -> TourMail::rescheduled (the old time struck through)
 *   cancelled            -> TourMail::cancelled
 *   completed            -> TourMail::thanks, only when the agency turned it on (agencies.settings.tours.thank_you_email)
 *   requested / no_show  -> nothing
 *
 * The recipient is the booking's own parent_email, never a default. Sent through the agency's mailer with the
 * agency stamped (MailScope::agency), so SuppressAgencyMail applies every switch, including TEST AGENCIES SEND
 * NOTHING (TestAgency). "Sent" means the email log says sent: the outcome is read back from email_logs.
 */
final class TourNotice
{
    public const DEFAULTS = ['thank_you_email' => false];

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['tours'] ?? null) ? $all['tours'] : [];

        return ['thank_you_email' => (bool) ($raw['thank_you_email'] ?? false)];
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $all['tours'] = array_replace(is_array($all['tours'] ?? null) ? $all['tours'] : [], ['thank_you_email' => (bool) ($in['thank_you_email'] ?? false)]);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** Which email this change calls for, or null. */
    public static function kindFor(object $before, object $after): ?string
    {
        $was = (string) $before->status;
        $now = (string) $after->status;
        $moved = substr((string) $before->tour_at, 0, 16) !== substr((string) $after->tour_at, 0, 16);
        if ($now !== $was) {
            if ($now === 'confirmed') return 'confirmed';   // the email carries the (new) time
            if ($now === 'cancelled') return 'cancelled';
            if ($now === 'completed') return self::settings((int) $after->agency_id)['thank_you_email'] ? 'thanks' : null;
            if ($now === 'requested' && $moved) return 'rescheduled';
            return null;
        }
        if ($moved && in_array($now, ['requested', 'confirmed'], true)) return 'rescheduled';

        return null;
    }

    /** The built email for a kind. @return array{subject:string, html:string} */
    public static function build(string $kind, object $t, ?string $oldUtc = null): array
    {
        return match ($kind) {
            'confirmed' => TourMail::confirmed($t),
            'rescheduled' => TourMail::rescheduled($t, $oldUtc),
            'cancelled' => TourMail::cancelled($t),
            'thanks' => TourMail::thanks($t),
        };
    }

    /**
     * Send it to the booking's own address and say what really happened.
     *
     * @return array{sent:bool, status:string, to:string, reason:?string}
     */
    public static function send(string $kind, object $t, ?string $oldUtc = null): array
    {
        $to = trim((string) ($t->parent_email ?? ''));
        if (! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['sent' => false, 'status' => 'no_email', 'to' => $to, 'reason' => 'This booking has no valid email address.'];
        }
        $agencyId = (int) $t->agency_id;
        $centreMail = (string) DB::table('centres')->where('id', (int) $t->centre_id)->value('email');
        $t->reply_to = filter_var($centreMail, FILTER_VALIDATE_EMAIL) ? $centreMail : null;
        $m = self::build($kind, $t, $oldUtc);
        $since = now()->subSeconds(5);
        try {
            $svc = AgencyMailer::forAgency($agencyId);
            $from = $svc->fromAddress();
            $fn = $svc->fromName();
            $reply = $t->reply_to;
            $svc->html($m['html'], function ($msg) use ($to, $from, $fn, $m, $agencyId, $reply, $t) {
                MailScope::agency($msg, $agencyId);
                $msg->to($to, (string) ($t->parent_name ?? ''))->from($from, $fn)->subject($m['subject']);
                if ($reply) $msg->replyTo($reply, FamilyFacingName::org((int) $t->centre_id, $agencyId, ''));
            });
        } catch (\Throwable $e) {
            Log::warning('Tour email failed', ['tour' => $t->id ?? null, 'error' => $e->getMessage()]);

            return ['sent' => false, 'status' => 'failed', 'to' => $to, 'reason' => 'The email could not be sent. Please try again later.'];
        }
        $log = DB::table('email_logs')->where('to_email', $to)->where('created_at', '>=', $since)->orderByDesc('id')->first(['status', 'error']);
        $status = $log ? (string) $log->status : 'unknown';
        $sent = $status === 'sent';

        return ['sent' => $sent, 'status' => $status, 'to' => $to,
            'reason' => $sent ? null : ($status === 'suppressed' ? 'Email is switched off for this agency, so the family was not emailed.' : 'The email was not confirmed as sent.')];
    }
}
