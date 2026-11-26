<?php

namespace App\Support;

use App\Http\Middleware\RejectTestVisitors;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * NO-SHOW ON A SALES MEETING (2026-11-28, Anthony: "when a scheduled meeting is here based on a sales lead and the
 * recipient did not show up, can you add something to indicate recipient didn't show and trigger an email and text
 * to reschedule the meeting with kiddietrac - wire this up").
 *
 * A "lead meeting" is either
 *   - kind 'meeting': a platform meeting from the Meetings screen (`meetings`, scope platform) whose lead is
 *     meetings.lead_id or a guest whose email is a sales lead's email / owner_email, or
 *   - kind 'booking': a website booking (`marketing_bookings`) with sales_lead_id.
 *
 * Once it has started, Sales marks it held or no_show (status), or undoes that. Marking no-show offers an email
 * (from sales@, Reply-To sales@, the sender's first name only) and a text from the KiddieTrac line (STOP honoured
 * by LineTexts::send), each to the LEAD'S OWN address/number, sent only when Sales ticks it and names it again
 * (never defaulted). Both carry a signed link to the website booking wizard (www.kiddietrac.com/?rebook=<token>);
 * a booking made through it lands on the SAME lead, the missed meeting becomes 'rescheduled', and the person who
 * ran the meeting hears about it on their bell (rebooked()).
 *
 * Test leads (test email domains, test-agency accounts, "[TEST]" / "(delete me)" names) are never emailed or
 * texted; only an explicit $testTo sample goes. Automation: autoAsk(), OFF unless PlatformSettings
 * sales.noshow_auto = 1.
 */
final class NoShow
{
    public const OUTCOMES = ['held', 'no_show', 'rescheduled'];
    public const AUTO_KEY = 'sales.noshow_auto';
    private const TOKEN_DAYS = 45;
    private const LABEL = ['held' => 'Held', 'no_show' => 'No-show', 'rescheduled' => 'Rescheduled', 'scheduled' => 'Scheduled', 'booked' => 'Booked'];

    /* ── the meeting and its lead ───────────────────────────────────────────────── */

    /** The open status for a kind: 'scheduled' (meetings) or 'booked' (website bookings). */
    public static function openStatus(string $kind): string
    {
        return $kind === 'booking' ? 'booked' : 'scheduled';
    }

    public static function table(string $kind): ?string
    {
        return ['meeting' => 'meetings', 'booking' => 'marketing_bookings'][$kind] ?? null;
    }

    /** The lead a platform meeting is with, or null. */
    public static function leadForMeeting(object $m): ?object
    {
        if (($m->scope ?? '') !== 'platform') return null;
        if (! empty($m->lead_id)) {
            $l = DB::table('sales_leads')->where('id', $m->lead_id)->whereNull('deleted_at')->first();
            if ($l) return $l;
        }
        $emails = DB::table('meeting_attendees')->where('meeting_id', $m->id)->where('kind', '!=', 'staff')
            ->pluck('email')->map(fn ($e) => strtolower(trim((string) $e)))->filter()->unique()->values()->all();
        if (! $emails) return null;
        $ph = implode(',', array_fill(0, count($emails), '?'));
        return DB::table('sales_leads')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereRaw("LOWER(email) IN ($ph)", $emails)->orWhereRaw("LOWER(owner_email) IN ($ph)", $emails))
            ->orderByRaw("status = 'open' DESC")->orderByDesc('last_activity_at')->first();
    }

    /** Lead ids for a set of platform meetings, [meetingId => leadId], in two queries. */
    public static function leadIdsForMeetings(array $meetingIds): array
    {
        if (! $meetingIds) return [];
        $out = [];
        $rows = DB::table('meetings')->whereIn('id', $meetingIds)->where('scope', 'platform')->get(['id', 'lead_id']);
        $byEmail = [];
        foreach (DB::table('meeting_attendees')->whereIn('meeting_id', $rows->pluck('id')->all() ?: [0])->where('kind', '!=', 'staff')->get(['meeting_id', 'email']) as $a) {
            $byEmail[strtolower(trim((string) $a->email))][] = (int) $a->meeting_id;
        }
        $leads = $byEmail ? DB::table('sales_leads')->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereIn(DB::raw('LOWER(email)'), array_keys($byEmail))->orWhereIn(DB::raw('LOWER(owner_email)'), array_keys($byEmail)))
            ->orderByRaw("status = 'open'")->orderBy('last_activity_at')->get(['id', 'email', 'owner_email']) : collect();
        foreach ($leads as $l) {   // later rows (open, most recent) win
            foreach ([strtolower((string) $l->email), strtolower((string) $l->owner_email)] as $e) {
                foreach ($byEmail[$e] ?? [] as $mid) $out[$mid] = (int) $l->id;
            }
        }
        foreach ($rows as $r) if ($r->lead_id) $out[(int) $r->id] = (int) $r->lead_id;
        return $out;
    }

    /**
     * The meeting as this feature reads it, or null when it is not a lead meeting:
     * {kind, id, title, starts_at, ends_at, status, lead, host_id, when, started, row}
     */
    public static function subject(string $kind, int $id): ?object
    {
        $t = self::table($kind);
        if (! $t) return null;
        $row = DB::table($t)->where('id', $id)->first();
        if (! $row) return null;
        if ($kind === 'meeting') {
            if ($row->scope !== 'platform') return null;
            $lead = self::leadForMeeting($row);
            $title = $row->title;
            $host = (int) $row->organizer_id;
        } else {
            $lead = $row->sales_lead_id ? DB::table('sales_leads')->where('id', $row->sales_lead_id)->whereNull('deleted_at')->first() : null;
            $title = BookingMail::typeName($row);
            $host = (int) ($row->host_user_id ?: ($lead->owner_id ?? 0));
        }
        if (! $lead) return null;
        $start = Carbon::parse($row->starts_at, 'UTC');
        return (object) [
            'kind' => $kind, 'id' => (int) $row->id, 'title' => (string) $title, 'starts_at' => $row->starts_at, 'ends_at' => $row->ends_at,
            'status' => (string) $row->status, 'lead' => $lead, 'host_id' => $host ?: null,
            'when' => BookingMail::whenEt($row->starts_at), 'started' => $start->lte(now()), 'row' => $row,
        ];
    }

    /** What may be done to it now: ['no_show' => bool, 'held' => bool, 'undo' => bool]. */
    public static function can(object $s): array
    {
        $open = self::openStatus($s->kind);
        return [
            'no_show' => $s->started && in_array($s->status, [$open, 'held'], true),
            'held' => $s->started && in_array($s->status, [$open, 'no_show'], true),
            'undo' => in_array($s->status, ['held', 'no_show'], true),
        ];
    }

    /* ── test leads send nothing ────────────────────────────────────────────────── */

    /** Why this lead must never be emailed or texted, or null. */
    public static function testReason(object $lead): ?string
    {
        foreach ([(string) ($lead->email ?? ''), (string) ($lead->owner_email ?? '')] as $email) {
            $email = strtolower(trim($email));
            if ($email === '') continue;
            foreach (RejectTestVisitors::TEST_DOMAINS as $re) if (preg_match($re, $email)) return 'a test email address';
            $ids = TestAgencies::testOnlyUserIds();
            if ($ids && DB::table('users')->whereIn('id', $ids)->whereRaw('LOWER(email) = ?', [$email])->exists()) return 'a test-agency account';
        }
        $words = ($lead->name ?? '') . ' ' . ($lead->company ?? '') . ' ' . ($lead->source ?? '');
        if (preg_match('/\[test\]|\(delete me\)|\btest lead\b|^\s*test\s*$/i', $words) || strtolower((string) ($lead->source ?? '')) === 'test') return 'marked as a test lead';
        return null;
    }

    /* ── who it goes to ─────────────────────────────────────────────────────────── */

    /** The lead's own email and mobile, and whether each channel can be used (with the reason when not). */
    public static function channels(object $lead): array
    {
        $email = strtolower(trim((string) ($lead->email ?? '')));
        $digits = LineTexts::digits((string) ($lead->phone ?? ''));
        $test = self::testReason($lead);
        $emailWhy = null; $smsWhy = null;
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) $emailWhy = 'This lead has no email address.';
        elseif ($test) $emailWhy = 'Not sent: this is ' . $test . '.';
        if (strlen($digits) !== 10) $smsWhy = trim((string) ($lead->phone ?? '')) === '' ? 'This lead has no mobile number.' : 'The lead\'s phone number is not a 10-digit mobile number.';
        elseif (LineTexts::stopped($digits)) $smsWhy = 'This number texted STOP to the KiddieTrac line, so it cannot be texted.';
        elseif ($test) $smsWhy = 'Not sent: this is ' . $test . '.';
        return [
            'email' => ['to' => $email ?: null, 'ok' => $emailWhy === null, 'why' => $emailWhy],
            'sms' => ['to' => strlen($digits) === 10 ? Dialer::pretty($digits) : (trim((string) ($lead->phone ?? '')) ?: null), 'digits' => strlen($digits) === 10 ? $digits : null, 'ok' => $smsWhy === null, 'why' => $smsWhy],
            'test' => $test,
        ];
    }

    /* ── the signed link ────────────────────────────────────────────────────────── */

    private static function sig(string $payload): string
    {
        return substr(hash_hmac('sha256', 'kt-rebook|' . $payload, (string) config('app.key')), 0, 16);
    }

    /** m9-71-<exp base36>-<sig>: kind+id, lead, expiry, signature. Short, so the text stays one segment-ish. */
    public static function token(object $s): string
    {
        $p = ($s->kind === 'booking' ? 'b' : 'm') . $s->id . '-' . (int) $s->lead->id . '-' . base_convert((string) now()->addDays(self::TOKEN_DAYS)->timestamp, 10, 36);
        return $p . '-' . self::sig($p);
    }

    /** ['kind','id','lead_id'] for a valid, unexpired token, else null. */
    public static function verify(?string $token): ?array
    {
        $token = trim((string) $token);
        if (! preg_match('/^([mb])(\d{1,10})-(\d{1,10})-([0-9a-z]{1,10})-([0-9a-f]{16})$/', $token, $x)) return null;
        $p = $x[1] . $x[2] . '-' . $x[3] . '-' . $x[4];
        if (! hash_equals(self::sig($p), $x[5])) return null;
        if ((int) base_convert($x[4], 36, 10) < now()->timestamp) return null;
        return ['kind' => $x[1] === 'b' ? 'booking' : 'meeting', 'id' => (int) $x[2], 'lead_id' => (int) $x[3]];
    }

    public static function rebookUrl(object $s): string
    {
        return 'https://www.kiddietrac.com/?rebook=' . self::token($s);
    }

    /** What the booking wizard fills in for a valid token (GET /marketing-site/rebook/{token}). */
    public static function prefill(string $token): ?array
    {
        $v = self::verify($token);
        if (! $v) return null;
        $lead = DB::table('sales_leads')->where('id', $v['lead_id'])->whereNull('deleted_at')->first();
        if (! $lead) return null;
        $type = 'demo';
        if ($v['kind'] === 'booking') $type = (string) (DB::table('marketing_bookings')->where('id', $v['id'])->value('type') ?: 'demo');
        return ['name' => (string) $lead->name, 'email' => (string) ($lead->email ?: ''), 'agency' => (string) ($lead->company ?: ''), 'phone' => (string) ($lead->phone ?: ''), 'type' => $type];
    }

    /* ── marking ────────────────────────────────────────────────────────────────── */

    private static function first(?string $s): string
    {
        return trim((string) strtok(trim((string) $s), ' '));
    }

    /** Set status no_show | held, or back to open ('undo'). Writes the lead's timeline. Returns the new status or an error string. */
    public static function mark(object $s, string $to, int $actorId): array
    {
        $can = self::can($s);
        $open = self::openStatus($s->kind);
        if ($to === 'undo') {
            if (! $can['undo']) return [null, 'There is nothing to undo on this meeting.'];
            $new = $open;
        } elseif (in_array($to, ['no_show', 'held'], true)) {
            if (! $s->started) return [null, 'You can mark a meeting once it has started.'];
            if ($s->status === $to) return [$to, null];
            if (! $can[$to]) return [null, 'This meeting is ' . strtolower(self::LABEL[$s->status] ?? $s->status) . ', so it cannot be marked now.'];
            $new = $to;
        } else {
            return [null, 'Choose no-show, held or undo.'];
        }
        $was = $s->status;
        $n = DB::table(self::table($s->kind))->where('id', $s->id)->where('status', $was)
            ->update(['status' => $new, 'outcome_at' => $new === $open ? null : now(), 'outcome_by' => $new === $open ? null : $actorId, 'updated_at' => now()]);
        if (! $n) return [null, 'Someone else changed this meeting just now. Refresh and try again.'];
        $who = DB::table('users')->where('id', $actorId)->value('first_name') ?: 'Sales';
        $body = match ($new) {
            'no_show' => '🙈 No-show: ' . $s->lead->name . ' did not join "' . $s->title . '" (' . $s->when . '). Marked by ' . $who . '.',
            'held' => '✅ Meeting held: "' . $s->title . '" (' . $s->when . '). Marked by ' . $who . '.',
            default => '↩️ ' . ($was === 'no_show' ? 'No-show' : 'Held') . ' undone on "' . $s->title . '" (' . $s->when . ') by ' . $who . '.',
        };
        self::logOnLead((int) $s->lead->id, $actorId, 'meeting', $body);
        Audit::write(['user_id' => $actorId, 'agency_id' => null, 'action' => 'sales_meeting.' . ($new === $open ? 'outcome_undone' : $new), 'entity_type' => $s->kind === 'booking' ? 'marketing_booking' : 'meeting',
            'entity_id' => $s->id, 'payload' => json_encode(['summary' => strip_tags($body), 'lead_id' => (int) $s->lead->id, 'was' => $was, 'now' => $new]), 'created_at' => now()]);
        return [$new, null];
    }

    private static function logOnLead(int $leadId, ?int $uid, string $type, string $body): void
    {
        try {
            DB::table('sales_activities')->insert(['lead_id' => $leadId, 'user_id' => $uid, 'type' => $type, 'body' => $body, 'done' => 1,
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_leads')->where('id', $leadId)->update(['last_activity_at' => now()]);
        } catch (Throwable $e) {
            Log::warning('no-show lead entry failed', ['lead' => $leadId, 'error' => $e->getMessage()]);
        }
    }

    /* ── the email and the text ─────────────────────────────────────────────────── */

    public static function senderName(?int $uid): string
    {
        $f = $uid ? self::first(DB::table('users')->where('id', $uid)->value('first_name')) : '';
        return $f !== '' ? $f . ' from KiddieTrac' : 'The KiddieTrac team';   // first name only (2026-10-26)
    }

    public static function subjectLine(object $s): string
    {
        return 'Sorry we missed you — pick a new time';
    }

    /** The email: [subject, html, text]. */
    public static function email(object $s, ?int $senderId, ?string $url = null): array
    {
        $url = $url ?: self::rebookUrl($s);
        $first = self::first($s->lead->name ?? '');
        $sender = self::senderName($senderId);
        $what = $s->kind === 'booking' ? 'our ' . strtolower($s->title) : '“' . $s->title . '”';
        $when = Carbon::parse($s->starts_at, 'UTC')->tz(BookingMail::TZ)->format('l, F j \a\t g:i A') . ' ET';
        $p = 'margin:0 0 14px;font-size:15.5px;line-height:1.65;color:#334155';
        $body = '<p style="' . $p . '">' . ($first !== '' ? 'Hi ' . e($first) . ',' : 'Hello,') . '</p>'
            . '<p style="' . $p . '">Sorry we missed you for ' . e($what) . ' on <strong>' . e($when) . '</strong>. These things happen, and it is no trouble at all.</p>'
            . '<p style="' . $p . '">If you would still like to talk, pick a time that suits you. It only takes a moment, and we will confirm it straight back.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:22px auto 24px"><tr>'
            . '<td class="kt-btn" align="center" bgcolor="#1F6080" style="border-radius:12px;background-color:#1F6080;background-image:linear-gradient(135deg,#1F6080,#2C8AAC)">'
            . '<a class="kt-btn" href="' . e($url) . '" style="display:inline-block;padding:16px 38px;font-size:17px;font-weight:800;letter-spacing:.2px;color:#ffffff !important;text-decoration:none;border-radius:12px">📅&nbsp; Pick a new time</a>'
            . '</td></tr></table>'
            . '<p style="' . $p . '">Rather talk first? Just reply to this email, or call us at <strong>1-855-400-9996</strong>.</p>'
            . '<p style="margin:0;font-size:15.5px;line-height:1.65;color:#334155">Talk soon,<br><strong>' . e($sender) . '</strong></p>'
            . '<p class="kt-muted" style="margin:22px 0 0;font-size:12px;line-height:1.55;color:#64748B">The button opens our booking page with your details filled in. If it does not work, copy this link into your browser: '
            . '<a href="' . e($url) . '" style="color:#1F6080;word-break:break-all">' . e($url) . '</a></p>';
        $html = EmailTemplate::wrap(null, $body, ['title' => 'Sorry we missed you', 'support_email' => 'sales@kiddietrac.com', 'preheader' => 'Pick a new time that suits you. It takes a moment.']);
        $text = ($first !== '' ? 'Hi ' . $first . ',' : 'Hello,') . "\n\n"
            . 'Sorry we missed you for ' . $what . ' on ' . $when . ". These things happen, and it is no trouble at all.\n\n"
            . "If you would still like to talk, pick a time that suits you:\n" . $url . "\n\n"
            . "Rather talk first? Just reply to this email, or call us at 1-855-400-9996.\n\nTalk soon,\n" . $sender . "\n";
        return [self::subjectLine($s), $html, $text];
    }

    /** The text from the KiddieTrac line. */
    public static function sms(object $s, ?int $senderId, ?string $url = null): string
    {
        $url = $url ?: self::rebookUrl($s);
        $first = self::first($s->lead->name ?? '');
        $host = $senderId ? self::first(DB::table('users')->where('id', $senderId)->value('first_name')) : '';
        $start = Carbon::parse($s->starts_at, 'UTC')->tz(BookingMail::TZ);
        $day = $start->isSameDay(now(BookingMail::TZ)) ? 'today' : ($start->isSameDay(now(BookingMail::TZ)->subDay()) ? 'yesterday' : 'on ' . $start->format('M j'));
        return 'KiddieTrac: Hi ' . ($first !== '' ? $first : 'there') . ', sorry we missed you ' . $day . ($host !== '' ? ' - ' . $host . ' here' : '')
            . '. Pick a new time that suits you: ' . $url . ' Reply STOP to opt out.';
    }

    /**
     * Send what Sales ticked. $toEmail / $toPhone must be the lead's own (the controller checks they were named).
     * $testTo sends ONE sample email there instead (subject [TEST]); $dryRun builds everything and sends nothing.
     * Returns ['email' => result|null, 'sms' => result|null], each result {sent: bool, why?, to}.
     */
    public static function send(object $s, bool $email, bool $sms, int $actorId, ?string $testTo = null, bool $dryRun = false): array
    {
        $ch = self::channels($s->lead);
        $out = ['email' => null, 'sms' => null];
        $url = self::rebookUrl($s);
        if ($email) {
            if ($testTo) $out['email'] = self::sendEmail($s, $testTo, $actorId, $url, true, $dryRun);
            elseif (! $ch['email']['ok']) $out['email'] = ['sent' => false, 'why' => $ch['email']['why'], 'to' => $ch['email']['to']];
            else $out['email'] = self::sendEmail($s, $ch['email']['to'], $actorId, $url, false, $dryRun);
        }
        if ($sms) {
            if (! $ch['sms']['ok']) $out['sms'] = ['sent' => false, 'why' => $ch['sms']['why'], 'to' => $ch['sms']['to']];
            elseif ($dryRun || $testTo) $out['sms'] = ['sent' => false, 'dry_run' => true, 'why' => 'Dry run: nothing was texted.', 'to' => $ch['sms']['to'], 'text' => self::sms($s, $actorId, $url)];
            else {
                $text = self::sms($s, $actorId, $url);
                try {
                    [$ok, $why] = LineTexts::send($ch['sms']['digits'], $text, $actorId);   // STOP + consent checked again there; files on the lead
                    $out['sms'] = ['sent' => (bool) $ok, 'why' => $ok ? null : ($why === LineTexts::NEEDS_PERMISSION ? 'This number has no texting permission on record.' : (string) $why), 'to' => $ch['sms']['to']];
                } catch (Throwable $e) {
                    $out['sms'] = ['sent' => false, 'why' => 'The text could not be sent.', 'to' => $ch['sms']['to']];
                }
            }
        }
        if (! $testTo && ! $dryRun && (($out['email']['sent'] ?? false) || ($out['sms']['sent'] ?? false))) {
            DB::table(self::table($s->kind))->where('id', $s->id)->update(['noshow_asked_at' => now()]);
            Audit::write(['user_id' => $actorId, 'agency_id' => null, 'action' => 'sales_meeting.reschedule_asked', 'entity_type' => $s->kind === 'booking' ? 'marketing_booking' : 'meeting',
                'entity_id' => $s->id, 'payload' => json_encode(['summary' => 'Asked ' . $s->lead->name . ' to pick a new time for "' . $s->title . '"'
                    . (($out['email']['sent'] ?? false) ? ' · email to ' . $out['email']['to'] : '') . (($out['sms']['sent'] ?? false) ? ' · text to ' . $out['sms']['to'] : ''), 'lead_id' => (int) $s->lead->id]), 'created_at' => now()]);
        }
        return $out;
    }

    private static function sendEmail(object $s, string $to, int $actorId, string $url, bool $test, bool $dryRun): array
    {
        [$subject, $html, $text] = self::email($s, $actorId, $url);
        if ($dryRun) return ['sent' => false, 'dry_run' => true, 'why' => 'Dry run: nothing was emailed.', 'to' => $to];
        $since = now()->subSeconds(2);
        try {
            Mail::html($html, function ($msg) use ($to, $subject, $text, $actorId, $test, $s) {
                MailScope::platform($msg);
                $msg->getHeaders()->addTextHeader('X-KT-Send-As', BookingMail::SALES);
                $msg->to($to, $test ? null : ($s->lead->name ?: null))->subject(($test ? '[TEST] ' : '') . $subject)
                    ->replyTo(BookingMail::SALES, self::senderName($actorId));
                $msg->text($text);
            });
        } catch (Throwable $e) {
            Log::warning('no-show email failed', ['kind' => $s->kind, 'id' => $s->id, 'error' => $e->getMessage()]);
            return ['sent' => false, 'why' => 'The email could not be sent.', 'to' => $to];
        }
        // "Sent" means delivered to the mail service, not suppressed by the gate (never-default-a-recipient).
        $log = DB::table('email_logs')->whereRaw('LOWER(to_email) = ?', [strtolower($to)])->where('created_at', '>=', $since)->orderByDesc('id')->first(['status', 'error']);
        if ($log && $log->status === 'suppressed') return ['sent' => false, 'why' => 'The email was held back by the mail settings: ' . mb_substr((string) $log->error, 0, 140), 'to' => $to];
        if ($log && $log->status === 'failed') return ['sent' => false, 'why' => 'The mail service refused it.', 'to' => $to];
        if (! $test) self::logOnLead((int) $s->lead->id, $actorId, 'email', '📧 "Sorry we missed you" email to ' . $to . ': asked them to pick a new time for "' . $s->title . '" (' . $s->when . ').');
        return ['sent' => true, 'to' => $to];
    }

    /* ── after they rebook ──────────────────────────────────────────────────────── */

    /**
     * A booking came in through a signed link (MarketingBookingController::store). Files it on the token's lead, marks
     * the missed meeting rescheduled and tells whoever ran it. Returns the lead id, or null if the token was no good
     * (the caller then files it the usual way).
     */
    public static function rebooked(object $b, array $v, string $typeName, string $whenText): ?int
    {
        try {
            $lead = DB::table('sales_leads')->where('id', $v['lead_id'])->whereNull('deleted_at')->first();
            if (! $lead) return null;
            $old = self::subject($v['kind'], $v['id']);
            if ($old && (int) $old->lead->id !== (int) $lead->id) $old = null;   // the token's lead must be the meeting's lead
            DB::table('marketing_bookings')->where('id', $b->id)->update(['sales_lead_id' => $lead->id, 'rebook_of' => $v['kind'] . ':' . $v['id'], 'updated_at' => now()]);
            DB::table('sales_activities')->insert(['lead_id' => $lead->id, 'type' => 'meeting', 'due_date' => $b->local_date, 'done' => 0,
                'body' => $typeName . ' requested on the website — ' . $whenText . ' (' . $b->duration_min . ' min, '
                    . ($b->meet_via === 'phone' ? 'phone call to ' . $b->phone : 'Teams video call') . '). Accept, modify or decline in Sales → Bookings.'
                    . "\n🔁 Rebooked from the \"pick a new time\" link" . ($old ? ' after missing "' . $old->title . '" (' . $old->when . ').' : '.')
                    . ($b->notes ? "\nThey wrote: " . $b->notes : ''),
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('sales_leads')->where('id', $lead->id)->update(['last_activity_at' => now()]);
            $notify = $lead->owner_id ? (int) $lead->owner_id : null;
            if ($old) {
                if (in_array($old->status, ['no_show', self::openStatus($old->kind)], true)) {
                    DB::table(self::table($old->kind))->where('id', $old->id)->update(['status' => 'rescheduled', 'rebooked_booking_id' => $b->id,
                        'outcome_at' => now(), 'updated_at' => now()]);
                } else {
                    DB::table(self::table($old->kind))->where('id', $old->id)->update(['rebooked_booking_id' => $b->id, 'updated_at' => now()]);
                }
                $notify = $old->host_id ?: $notify;
                Audit::write(['user_id' => null, 'agency_id' => null, 'action' => 'sales_meeting.rebooked', 'entity_type' => $old->kind === 'booking' ? 'marketing_booking' : 'meeting',
                    'entity_id' => $old->id, 'payload' => json_encode(['summary' => $lead->name . ' picked a new time (' . $whenText . ') for "' . $old->title . '"', 'lead_id' => (int) $lead->id, 'booking_id' => (int) $b->id]), 'created_at' => now()]);
            }
            if ($notify) {
                $title = '🔁 ' . ($lead->name ?: 'A lead') . ' picked a new time';
                $body = ($old ? 'After missing "' . $old->title . '". ' : '') . 'New request: ' . $typeName . ', ' . $whenText . '. Accept it in Sales → Bookings.';
                Notify::write([['user_id' => $notify, 'type' => 'booking_request', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => '#sales-bookings?id=' . (int) $b->id]), 'created_at' => now()]]);
            }
            return (int) $lead->id;
        } catch (Throwable $e) {
            Log::warning('no-show rebook filing failed', ['booking' => $b->id ?? null, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /* ── optional automation (OFF unless sales.noshow_auto = 1) ─────────────────── */

    public static function autoOn(): bool
    {
        return (string) PlatformSettings::get(self::AUTO_KEY, '0') === '1';
    }

    /**
     * Lead meetings that started 30 minutes to 24 hours ago and that nobody marked: mark no-show and send the email
     * (and text, when the lead has a mobile). Run by kt:sales-meetings every 5 minutes. $dry lists them, changes nothing.
     */
    public static function autoAsk(bool $dry = false): array
    {
        if (! self::autoOn()) return ['off' => true];
        $n = ['marked' => 0, 'email' => 0, 'sms' => 0, 'skipped' => 0, 'would' => []];
        $from = now()->subDay()->format('Y-m-d H:i:s'); $to = now()->subMinutes(30)->format('Y-m-d H:i:s');
        $todo = [];
        foreach (DB::table('meetings')->where('scope', 'platform')->where('status', 'scheduled')->where('all_day', 0)->whereNull('noshow_asked_at')
            ->whereBetween('starts_at', [$from, $to])->pluck('id') as $id) $todo[] = ['meeting', (int) $id];
        foreach (DB::table('marketing_bookings')->where('status', 'booked')->whereNull('noshow_asked_at')->whereNotNull('sales_lead_id')
            ->whereBetween('starts_at', [$from, $to])->pluck('id') as $id) $todo[] = ['booking', (int) $id];
        foreach ($todo as [$kind, $id]) {
            $s = self::subject($kind, $id);
            if (! $s) continue;
            $ch = self::channels($s->lead);
            if (! $ch['email']['ok'] && ! $ch['sms']['ok']) { $n['skipped']++; continue; }
            if ($dry) { $n['would'][] = $kind . ':' . $id; continue; }
            // Claim first, so a slow send is never repeated by the next run.
            if (! DB::table(self::table($kind))->where('id', $id)->whereNull('noshow_asked_at')->update(['noshow_asked_at' => now()])) continue;
            $actor = (int) ($s->host_id ?: PlatformSettings::get('sales.booking.email_actor', 1));
            [$new] = self::mark($s, 'no_show', $actor);
            if ($new !== 'no_show') continue;
            $n['marked']++;
            $s->status = 'no_show';
            $r = self::send($s, $ch['email']['ok'], $ch['sms']['ok'], $actor);
            if ($r['email']['sent'] ?? false) $n['email']++;
            if ($r['sms']['sent'] ?? false) $n['sms']++;
        }
        return $n;
    }
}
