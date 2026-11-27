<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * WHO A CAMPAIGN WENT TO, AND WHAT HAPPENED (2026-11-27, Anthony: "add a view to see who it was sent to and
 * successfully delivered, read, or error/bounce").
 *
 * WHAT IS REALLY KNOWN, and nothing more is claimed:
 *  - Sent: our mail service accepted it (email_logs 'sent'). Microsoft / Gmail give no delivery receipt, so
 *    "delivered" cannot be confirmed; a message is shown as "Sent, no bounce" until a bounce comes back.
 *  - Bounced: the recipient's mail server refused it. mail:ingest-bounces reads the failure notices hourly and
 *    marks the exact email_logs row (bounced_at + reason).
 *  - Failed: our side could not hand it over (sender disconnected, network) - email_logs 'failed' or the send threw.
 *  - Held back: the mail rules stopped it (not yet accepted their invite, demo address, test agency, email off).
 *    email_logs 'suppressed' with the reason.
 *  - Opened: the house open pixel (SuppressAgencyMail::attachOpenPixel, our own server, no third party) loaded.
 *    Approximate: images blocked = no open; some mail apps preload images = an open nobody made.
 *  - Clicks are not tracked (links are not rewritten).
 *
 * Rows are written per recipient as the send happens (record()); a campaign sent before this existed is
 * rebuilt from its in-app notices and matching email_logs rows (backfill()).
 */
class CampaignSends
{
    public static function ready(): bool
    {
        static $ok = null;
        return $ok ??= Schema::hasTable('marketing_campaign_sends');
    }

    /**
     * Record one recipient's send. $message is the Illuminate\Mail\Message the send built (null when it threw).
     * The house logger keys the email_logs row on the X-KT-Track token the pixel listener put on the message;
     * a message the mail rules held back has no token and its 'suppressed' row is found by address + subject.
     */
    public static function record(object $c, object $r, string $subject, $message, ?\Throwable $error, Carbon $startedAt): ?string
    {
        // Returns the outcome it recorded ('sent', 'suppressed', 'failed', 'unknown'), or null when nothing was recorded.
        if (! self::ready() || empty($r->email)) return null;
        try {
            $token = null;
            try {
                $sym = $message ? $message->getSymfonyMessage() : null;
                $h = $sym ? $sym->getHeaders() : null;
                if ($h && $h->has('X-KT-Track')) $token = trim((string) $h->get('X-KT-Track')->getBodyAsString()) ?: null;
            } catch (\Throwable $e) {
            }
            $log = null;
            if ($token) {
                $log = DB::table('email_logs')->where('tracking_token', $token)->orderByDesc('id')->first(['id', 'status', 'error']);
            }
            if (! $log) {
                $log = DB::table('email_logs')->where('to_email', $r->email)->where('subject', $subject)
                    ->where('created_at', '>=', $startedAt->copy()->subMinute())->orderByDesc('id')->first(['id', 'status', 'error']);
            }
            if ($error) {
                $outcome = 'failed';
                $why = $error->getMessage();
            } elseif ($log && in_array($log->status, ['suppressed', 'failed', 'captured'], true)) {
                $outcome = $log->status === 'captured' ? 'suppressed' : $log->status;
                $why = $log->error;
            } else {
                $outcome = $log ? 'sent' : 'unknown';
                $why = null;
            }
            DB::table('marketing_campaign_sends')->updateOrInsert(
                ['campaign_id' => (int) $c->id, 'email' => mb_strtolower(trim((string) $r->email))],
                [
                    'agency_id' => (int) $c->agency_id ?: null,
                    'user_id' => isset($r->id) ? (int) $r->id : null,
                    'first_name' => mb_substr((string) ($r->first_name ?? ''), 0, 80) ?: null,
                    'last_name' => mb_substr((string) ($r->last_name ?? ''), 0, 80) ?: null,
                    'outcome' => $outcome,
                    'error' => $why !== null ? mb_substr((string) $why, 0, 500) : null,
                    'tracking_token' => $token,
                    'email_log_id' => $log->id ?? null,
                    'source' => 'send',
                    'sent_at' => now(),
                ]
            );

            return $outcome;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('campaign send record', ['campaign' => $c->id ?? null, 'e' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * A campaign emailed before 2026-11-27 has no rows: rebuild them from what was recorded. Recipients = its in-app
     * notices (one per person, written at send) and, for each, the email_logs row to that address with the campaign's
     * subject in the send window. Only when nothing is on file yet; never invents a status (no log row = 'unknown').
     */
    public static function backfill(object $c): int
    {
        if (! self::ready() || empty($c->sent_at) || ! in_array($c->channel, ['email', 'both'], true)) return 0;
        if (DB::table('marketing_campaign_sends')->where('campaign_id', $c->id)->exists()) return 0;
        $userIds = DB::table('notifications')->where('type', 'marketing')
            ->whereRaw("JSON_EXTRACT(data, '$.campaign_id') = ?", [(int) $c->id])->pluck('user_id')->unique()->all();
        if (! $userIds) return 0;
        $from = Carbon::parse($c->sent_at)->subMinutes(10);
        $to = Carbon::parse($c->email_sent_at ?: $c->sent_at)->addDays(2);
        $subjects = array_values(array_filter([(string) $c->subject, (string) $c->title]));
        $n = 0;
        foreach (DB::table('users')->whereIn('id', $userIds)->get(['id', 'email', 'first_name', 'last_name']) as $u) {
            if (empty($u->email)) continue;
            $log = DB::table('email_logs')->where('to_email', $u->email)->whereBetween('created_at', [$from, $to])
                ->where(function ($w) use ($subjects, $u) {
                    foreach ($subjects as $s) {
                        $w->orWhere('subject', CampaignMerge::text($s, $u->first_name))->orWhere('subject', 'like', '%' . addcslashes(mb_substr(CampaignMerge::text($s, $u->first_name), 0, 100), '%_\\') . '%');
                    }
                })->orderBy('id')->first(['id', 'status', 'error', 'tracking_token', 'created_at']);
            DB::table('marketing_campaign_sends')->insertOrIgnore([
                'campaign_id' => (int) $c->id, 'agency_id' => (int) $c->agency_id ?: null, 'user_id' => (int) $u->id,
                'email' => mb_strtolower(trim((string) $u->email)),
                'first_name' => mb_substr((string) $u->first_name, 0, 80) ?: null, 'last_name' => mb_substr((string) $u->last_name, 0, 80) ?: null,
                'outcome' => $log ? ($log->status === 'sent' || $log->status === 'bounced' ? 'sent' : ($log->status === 'captured' ? 'suppressed' : $log->status)) : 'unknown',
                'error' => $log && $log->status !== 'sent' ? mb_substr((string) $log->error, 0, 500) : null,
                'tracking_token' => $log->tracking_token ?? null, 'email_log_id' => $log->id ?? null,
                'source' => 'backfill', 'sent_at' => $log->created_at ?? $c->email_sent_at ?? $c->sent_at,
            ]);
            $n++;
        }

        return $n;
    }

    /** Status of one row, from the live log (opens and bounces arrive after the send). */
    public static function status(object $s, ?object $log): array
    {
        if ($log && $log->status === 'bounced') return ['bounced', self::plain($log->error, 'bounced'), $log->bounced_at];
        if ($s->outcome === 'failed' || ($log && $log->status === 'failed')) return ['failed', self::plain($s->error ?: ($log->error ?? null), 'failed'), $s->sent_at];
        if ($s->outcome === 'suppressed' || ($log && in_array($log->status, ['suppressed', 'captured'], true))) {
            return ['held', self::plain($s->error ?: ($log->error ?? null), 'held'), $s->sent_at];
        }
        if ($log && $log->status === 'sent') {
            return ! empty($log->opened_at) ? ['opened', null, $log->opened_at] : ['sent', null, $log->created_at];
        }

        return ['unknown', 'No email record was found for this person, so what happened is not known.', $s->sent_at];
    }

    /** An error or hold-back reason in plain words. Never shows a raw server line. */
    public static function plain(?string $e, string $kind): string
    {
        $t = (string) $e;
        $rules = [
            '/does not exist|RecipientNotFound|mailbox (?:not found|unavailable)|user unknown|no such user|5\.1\.1|5\.1\.10/i' => 'The email address does not exist.',
            '/DNS domain .* does not exist|InfoDomainNonexistent|5\.4\.310|host not found|domain not found/i' => 'The email domain does not exist (check the spelling after the @).',
            '/mailbox full|quota|over quota|5\.2\.2/i' => 'Their mailbox is full.',
            '/relay access denied|not permitted to relay|5\.7\.|blocked|spam|policy/i' => 'Their mail server refused it (blocked or a spam rule).',
            /* Since 2026-11-27 an agency's family campaigns DO reach people who have not accepted their invite
               (MailScope::campaign), so this hold-back can only be from before then - worded so, not as a rule. */
            '/not accepted their invite|not onboarded/i' => 'Not sent at the time: they had not accepted their invite yet. Campaigns now reach them.',
            '/demo|non-existent address|\.invalid|\.test\b/i' => 'Not sent: a demo or made-up address.',
            '/test agency|suppressed agency|MAIL_SUPPRESS_AGENCIES/i' => 'Not sent: email is switched off for this agency (test agency or not live yet).',
            '/master switch/i' => 'Not sent: notifications and emails are switched off in Settings.',
            '/switched OFF|email delivery/i' => 'Not sent: email is switched off for their centre or room.',
            '/suspended|deactivated/i' => 'Not sent: their account is suspended or deactivated.',
            '/unsubscrib|opted out|do[- ]not[- ]contact|turned off/i' => 'Not sent: they turned these emails off.',
            '/Integration account/i' => 'Not sent: a system account with no mailbox.',
            '/Gmail access .* revoked|not connected yet|Reconnect/i' => 'Not sent: the sending Gmail account needs reconnecting (Sender accounts).',
            '/cURL error|Could not resolve host|timed out|Connection could not be established/i' => 'Not sent: our mail service could not be reached at the time.',
        ];
        foreach ($rules as $rx => $words) {
            if ($t !== '' && preg_match($rx, $t)) return $words;
        }
        return match ($kind) {
            'bounced' => 'Their mail server sent it back.',
            'failed' => 'It could not be sent.',
            default => 'Not sent: held back by the email rules.',
        };
    }
}
