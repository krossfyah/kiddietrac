<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SAFE ARRIVAL / SAFE DEPARTURE "ALL CLEAR" (2026-11-02, approved by Anthony).
 *
 * Before this, a check that closed after staff had been alerted closed SILENTLY: the child arrived, a parent reported
 * the absence, or someone resolved it on the board, and the educators and directors who got the red "not here" alert
 * were never told. Their alert stayed unread on the bell and the phone badge.
 *
 * Now, when a morning (arrival) or end-of-day (departure) check closes AFTER staff were alerted:
 *   - every staff alert for that child and check is marked read (the badge clears) - including the alert of whoever
 *     resolved it on the board;
 *   - everyone who got an alert, except the person who resolved it, gets one "✅ all clear" through the same channels:
 *     bell (written already read, 2026-11-10) + push (a quiet sign-in/out tone, never the urgent ring), email only if they got the alert by email, a text
 *     only if they got the alert by text.
 * Parents are never messaged by this. A check that closes before any staff alert sends nothing.
 *
 * Who was alerted is read from the alerts themselves (notifications type safe_arrival, link #safe-arrival - parents'
 * rows link #today - for this child, since the check was opened). SafeArrival::alertUsers tags each row with the kind
 * (arrival/departure) and the channels it went by (emailed/texted); rows from before the tag are matched by title and
 * by the email/SMS logs.
 *
 * It only runs where a check is being CLOSED right now (the 5-minute job, the board, the phone call), so checks that
 * closed before this shipped never get a retroactive all-clear.
 */
final class SafeArrivalAllClear
{
    /** A quiet "signed in / out" tone - never the safety alarm, never urgent. */
    public const CATEGORY = 'checkin';

    /** Did staff get an alert for this check? (Only then is there anything to clear.) */
    public static function wasAlerted(string $kind, object $check): bool
    {
        if ($kind === 'departure') {
            return ! empty($check->educator_alerted_at) || ! empty($check->escalated_at) || ! empty($check->auto_signed_out_at);
        }

        return ! empty($check->escalated_at) || ! empty($check->up_alerted_at);
    }

    /** arrival | departure, for an alert row written before alertUsers tagged its kind. */
    public static function kindOf(object $row, array $data): string
    {
        if (! empty($data['sa_kind'])) {
            return (string) $data['sa_kind'];
        }
        $t = mb_strtolower((string) $row->title);

        return (str_contains($t, 'still signed in') || str_contains($t, 'pickup not confirmed') || str_contains($t, '🕔'))
            ? 'departure' : 'arrival';
    }

    /**
     * The staff alert rows for this child and this check.
     *
     * @return array<int, object> notification rows (id, user_id, title, data, read_at, created_at, d = decoded data)
     */
    public static function alertRows(string $kind, object $check): array
    {
        $childId = (int) $check->child_id;
        // Since the check was opened (a departure check is written just AFTER its first alert, hence the margin).
        $floor = Carbon::parse((string) $check->created_at)->subMinutes(15);
        $out = [];
        foreach (DB::table('notifications')->where('type', 'safe_arrival')->where('created_at', '>=', $floor)
            ->where('data', 'like', '%#safe-arrival%')
            ->where(fn ($q) => $q->where('data', 'like', '%"child_id":' . $childId . '%')->orWhere('data', 'like', '%"child_id":"' . $childId . '"%'))
            ->orderBy('id')->get(['id', 'user_id', 'title', 'data', 'read_at', 'created_at']) as $r) {
            $d = is_string($r->data) ? (json_decode($r->data, true) ?: []) : (array) $r->data;
            if ((int) ($d['child_id'] ?? 0) !== $childId || ($d['link'] ?? '') !== '#safe-arrival' || ! empty($d['sa_clear'])) {
                continue;
            }
            if (self::kindOf($r, $d) !== $kind) {
                continue;
            }
            $r->d = $d;
            $out[] = $r;
        }

        return $out;
    }

    /**
     * What closing this check would do: whether staff were alerted, the alert rows, everyone alerted, and who gets the
     * all-clear (everyone alerted but the person who closed it).
     */
    public static function plan(string $kind, object $check, ?int $actorId = null): array
    {
        if (! self::wasAlerted($kind, $check)) {
            return ['alerted' => false, 'rows' => [], 'users' => [], 'to' => []];
        }
        $rows = self::alertRows($kind, $check);
        $users = array_values(array_unique(array_map(fn ($r) => (int) $r->user_id, $rows)));
        $to = array_values(array_filter($users, fn ($u) => $u !== (int) $actorId));

        return ['alerted' => true, 'rows' => $rows, 'users' => $users, 'to' => $to];
    }

    /** Mark these alert rows read, so the bell and the phone badge clear. */
    public static function markRead(array $rows): int
    {
        $ids = array_values(array_map(fn ($r) => (int) $r->id, array_filter($rows, fn ($r) => $r->read_at === null)));
        if (! $ids) {
            return 0;
        }

        return DB::table('notifications')->whereIn('id', $ids)->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** "Anthony, Priya" - first names of these users, for the job's log line. */
    public static function names(array $ids): string
    {
        if (! $ids) {
            return 'nobody';
        }

        return DB::table('users')->whereIn('id', $ids)->orderBy('first_name')->pluck('first_name')
            ->map(fn ($n) => (string) $n)->implode(', ');
    }

    /**
     * Close the alerts for this check and send the all-clear. Never throws: the close itself must not fail.
     *
     * @return array{alerted:bool,read:int,to:int[],sent:int}
     */
    public static function send(int $agencyId, string $kind, object $check, string $title, string $body, ?int $actorId = null): array
    {
        $out = ['alerted' => false, 'read' => 0, 'to' => [], 'sent' => 0];
        try {
            $p = self::plan($kind, $check, $actorId);
            $out['alerted'] = $p['alerted'];
            if (! $p['alerted'] || ! $p['rows']) {
                return $out;
            }
            $out['read'] = self::markRead($p['rows']);
            $out['to'] = $p['to'];
            $byUser = [];
            foreach ($p['rows'] as $r) {
                $byUser[(int) $r->user_id][] = $r;
            }
            $eyebrow = $kind === 'departure' ? 'SAFE DEPARTURE' : 'SAFE ARRIVAL';
            foreach ($p['to'] as $uid) {
                [$emailed, $texted] = self::channelsOf($uid, $byUser[$uid] ?? []);
                // Their own Safe Arrival channels (2026-11-24): follow-ups obey them like the alert did.
                $emailed = $emailed && SafeArrival::staffWants((int) $uid, 'email');
                $texted = $texted && SafeArrival::staffWants((int) $uid, 'sms');
                $res = [];
                try {
                    if (! SafeArrival::staffWants((int) $uid, 'push')) {
                        throw new \RuntimeException('__sa_push_off');
                    }
                    Notify::write([
                        'user_id' => $uid, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                        'data' => json_encode(['link' => '#safe-arrival', 'child_id' => (int) $check->child_id, 'sa_kind' => $kind,
                            'sa_clear' => 1, 'check_id' => (int) $check->id, 'kt_category' => self::CATEGORY, 'severity' => 'info'],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        // Written already read (2026-11-10, Anthony): the push still tells them, but an all-clear is
                        // news, not something to do - it must not sit in the unread pile once the alert is cleared.
                        'created_at' => now(), 'read_at' => now(),
                    ]);
                    $res = app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, '#safe-arrival', false, false, true,
                        ['kt_category' => self::CATEGORY]);
                } catch (\Throwable $e) {
                    if ($e->getMessage() !== '__sa_push_off') Log::warning('Safe Arrival all-clear push failed', ['child' => $check->child_id, 'user' => $uid, 'error' => $e->getMessage()]);
                }
                if ((int) ($res['sent'] ?? 0) > 0) {
                    $out['sent']++;
                }
                if (! $emailed && ! $texted) {
                    continue;
                }
                $u = DB::table('users')->where('id', $uid)->first(['first_name', 'email', 'phone']);
                if (! $u) {
                    continue;
                }
                if ($emailed && $u->email) {
                    try {
                        self::email($agencyId, (string) $u->email, (string) $u->first_name, $title, $body, $eyebrow);
                    } catch (\Throwable $e) {
                        Log::warning('Safe Arrival all-clear email failed', ['child' => $check->child_id, 'user' => $uid, 'error' => $e->getMessage()]);
                    }
                }
                if ($texted && $u->phone && SafeArrival::textsStaff((int) $uid)) {
                    try {
                        app(\App\Http\Controllers\Api\SmsController::class)
                            ->sendOne($agencyId, (int) $uid, (string) $u->phone, $title . ' - ' . $body, 'safe_arrival_staff');
                    } catch (\Throwable $e) {
                        Log::warning('Safe Arrival all-clear text failed', ['child' => $check->child_id, 'user' => $uid, 'error' => $e->getMessage()]);
                    }
                }
            }
            // The person who closed it gets no all-clear, but their phone icon should drop the alert now.
            if ($actorId && in_array((int) $actorId, $p['users'], true)) {
                try {
                    Badges::syncIos((int) $actorId, Badges::totalFor((int) $actorId));
                } catch (\Throwable $e) {
                }
            }
            try {
                Audit::write([
                    'user_id' => $actorId, 'agency_id' => $agencyId,
                    'action' => $kind === 'departure' ? 'safe_departure.all_clear' : 'safe_arrival.all_clear',
                    'entity_type' => 'child', 'entity_id' => (int) $check->child_id,
                    'payload' => json_encode(['summary' => 'All clear sent to ' . count($p['to']) . ' staff (' . self::names($p['to']) . '); '
                        . $out['read'] . ' alert(s) marked read: ' . $title, 'check_id' => (int) $check->id, 'to' => $p['to']],
                        JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) {
            }
        } catch (\Throwable $e) {
            Log::warning('Safe Arrival all-clear failed', ['check' => $check->id ?? null, 'kind' => $kind, 'error' => $e->getMessage()]);
        }

        return $out;
    }

    /**
     * Did this person get the alert by email / by text? From the tag alertUsers puts on each alert row; for older rows
     * (and the phone-call notices) from the email and SMS logs.
     *
     * @return array{0:bool,1:bool}
     */
    public static function channelsOf(int $uid, array $rows): array
    {
        $emailed = false;
        $texted = false;
        $untagged = [];
        foreach ($rows as $r) {
            if (array_key_exists('emailed', $r->d)) {
                $emailed = $emailed || ! empty($r->d['emailed']);
                $texted = $texted || ! empty($r->d['texted']);
            } else {
                $untagged[] = $r;
            }
        }
        if ($untagged && (! $emailed || ! $texted)) {
            $titles = array_values(array_unique(array_map(fn ($r) => (string) $r->title, $untagged)));
            // Wide: the logs and the bell are not always stamped on the same clock.
            $since = Carbon::parse((string) $untagged[0]->created_at)->subHours(8);
            $u = DB::table('users')->where('id', $uid)->first(['email']);
            if (! $emailed && $u && $u->email) {
                $emailed = DB::table('email_logs')->where('to_email', $u->email)->whereIn('subject', $titles)
                    ->where('created_at', '>=', $since)->exists();
            }
            if (! $texted) {
                $texted = DB::table('sms_messages')->where('to_user_id', $uid)->where('category', 'safe_arrival_staff')
                    ->where('created_at', '>=', $since)
                    ->where(function ($q) use ($titles) {
                        foreach ($titles as $t) {
                            $q->orWhere('body', 'like', str_replace(['%', '_'], ['\%', '\_'], $t) . '%');
                        }
                    })->exists();
            }
        }

        return [$emailed, $texted];
    }

    private static function email(int $agencyId, string $to, string $firstName, string $title, string $body, string $eyebrow): void
    {
        $toName = (string) (strtok(trim($firstName), ' ') ?: '');   // first name only in email (standing rule)
        $html = \App\Services\EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
            . '<p style="margin:14px 0 0;font-size:13.5px;line-height:1.6;">This closes the alert you were sent earlier about this child.</p>',
            ['eyebrow_icon' => SafeArrival::EMAIL_ICON, 'eyebrow' => $eyebrow, 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
            });
        })->onQueue('mail');
    }

    // ── The words ────────────────────────────────────────────────────────────

    private static function room(?string $room): string
    {
        return $room ? " ({$room})" : '';
    }

    private const DONE = ' All clear - no further action needed.';

    /** The child was signed in. */
    public static function arrivedText(string $name, ?string $room, string $time): array
    {
        return ["✅ Safe Arrival: {$name} arrived at {$time}",
            "{$name}" . self::room($room) . " was signed in at {$time}." . self::DONE];
    }

    /** An absence was recorded (by a parent in the app, or by staff outside the board). */
    public static function absentText(string $name, ?string $room, ?int $reportedBy): array
    {
        $by = '';
        if ($reportedBy) {
            $isGuardian = DB::table('guardians')->where('user_id', $reportedBy)->exists()
                && ! DB::table('role_assignments')->where('user_id', $reportedBy)->where('active', 1)
                    ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin', 'home_visitor'])->exists();
            $first = (string) DB::table('users')->where('id', $reportedBy)->value('first_name');
            $by = $isGuardian ? 'reported by a parent' : ($first !== '' ? "reported by {$first}" : 'reported by staff');
        }

        return ["✅ Safe Arrival: {$name} is absent today" . ($by !== '' ? " — {$by}" : ''),
            "{$name}" . self::room($room) . ' is marked absent today' . ($by !== '' ? " ({$by})" : '') . '.' . self::DONE];
    }

    /** " Note: …." - the staff member's note as a sentence of its own. */
    private static function note(?string $note): string
    {
        $note = trim((string) $note);
        if ($note === '') {
            return '';
        }

        return ' Note: ' . $note . (preg_match('/[.!?]$/u', $note) ? '' : '.');
    }

    /** Closed on the board: absent | parent_contacted | other. */
    public static function resolvedText(string $name, ?string $room, string $resolution, string $staffFirst, ?string $note): array
    {
        $label = ['absent' => 'marked absent', 'parent_contacted' => 'parent contacted', 'other' => 'closed'][$resolution] ?? 'closed';
        $who = $staffFirst !== '' ? $staffFirst : 'staff';
        return ["✅ Safe Arrival: {$name} — {$label} by {$who}",
            "{$who} closed the Safe Arrival check for {$name}" . self::room($room) . ": {$label}."
            . self::note($note) . self::DONE];
    }

    /** The child was signed out after the end-of-day alert. */
    public static function signedOutText(string $name, ?string $room, string $time): array
    {
        return ["✅ Safe Departure: {$name} signed out at {$time}",
            "{$name}" . self::room($room) . " was signed out at {$time}." . self::DONE];
    }

    /** Closed on the board: picked_up (at a time) | still_here | other. */
    public static function departureResolvedText(string $name, ?string $room, string $resolution, ?string $time, string $staffFirst, ?string $note): array
    {
        $label = ['picked_up' => 'picked up' . ($time ? " at {$time}" : ''), 'still_here' => 'still here (late pickup)', 'other' => 'closed'][$resolution] ?? 'closed';
        $who = $staffFirst !== '' ? $staffFirst : 'staff';
        return ["✅ Safe Departure: {$name} — {$label}, confirmed by {$who}",
            "{$who} closed the end-of-day check for {$name}" . self::room($room) . ": {$label}."
            . self::note($note) . self::DONE];
    }
}
