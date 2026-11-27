<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one way a notification row gets written.
 *
 * Built 2026-09-06 after an audit of every outbound channel against switched-off
 * accounts. Email, push and SMS were all clean — each asks Suppression before contacting
 * anybody. The in-app feed was the exception, and for a structural reason: it had **82
 * call sites inserting straight through the query builder**, which no model observer can
 * see and no single writer could gate. 331 notifications were queued for eight accounts
 * that had been deactivated, suspended or deleted, the newest written the day before the
 * audit.
 *
 * Not an active leak — AuthController refuses Audience::OFF_STATUSES at the front door,
 * so nobody in that state can sign in to read their bell. It is latent: reactivate the
 * account and a backlog naming children appears, covering exactly the period after the
 * person left. This is the choke point that stops it being written in the first place,
 * rather than swept up afterwards.
 *
 * TWO RULES, AND THEY ARE DELIBERATELY ASYMMETRIC:
 *
 *   NEVER WRITE to somebody who cannot sign in. A notification they can never read is
 *   not a message, it is only a record of their activity accumulating after they left.
 *   That covers deactivated AND suspended — the same list the front door uses.
 *
 *   NEVER DELETE what a returning person had. The sweep that clears the backlog
 *   (kiddietrac:revoke-off-tokens) only touches deactivated and deleted accounts, never
 *   a suspended one, because a suspension can be lifted. Anthony, 2026-09-06.
 *
 * A missing row is not worth breaking the action that produced it, so every failure is
 * caught and reported — the same contract Audit::write() keeps.
 */
final class Notify
{
    /* EVERY BELL ROW RINGS THE PHONE (2026-11-03, Anthony: "alerts from the bell when app on phone is idle or not
       logged in the sounds do not announce"). This class only wrote the row, and the bell's chime comes from the open
       portal polling /notifications - which a backgrounded app or a locked phone never does. About 65 writers (new
       email, countersign, time off, closures, ROA ready, birthdays...) therefore never made a sound on a phone; only
       the ones that also called FcmService themselves (chat, Safe Arrival, clock, absences) did.

       Now each row is also pushed, once per person per write, with the person's tone for its category. The push is
       sent when the request or command finishes, so a caller that pushes for itself (often urgent, with its own
       payload) goes first, and FcmService::$pushedAt tells us to stay quiet for that person. A caller can also pass
       'push' => false on a row. */
    private const PUSH_DEDUPE_SECS = 120;

    /** Bell type => sound category (NotificationSounds::CATEGORIES). Anything else is "general". */
    private const TYPE_CATEGORY = [
        'safe_arrival' => 'safety', 'absence' => 'safety', 'ratio_breach' => 'safety', 'children_still_in' => 'safety',
        'walk_started' => 'safety', 'walk_ended' => 'safety', 'walk_quiet' => 'safety', 'walk_auto_closed' => 'safety',
        'immunization_reminder' => 'safety', 'immunization_record' => 'safety', 'incident' => 'safety',
        'drill_recorded' => 'safety', 'drill_scheduled' => 'safety', 'drill_missed' => 'safety', 'fire_plan' => 'safety', 'running_late' => 'safety',
        'checkin' => 'checkin', 'checkin_reminder' => 'checkin', 'pickup_alarm' => 'checkin', 'attendance' => 'checkin', 'transfer' => 'checkin',
        'clock' => 'staff', 'clock_reminder' => 'staff', 'break_overrun' => 'staff', 'time_off' => 'staff', 'time_off_reminder' => 'staff',
        'schedule' => 'staff', 'award' => 'staff', 'educator_reward' => 'staff', 'bio_reminder' => 'staff',
        'portal_tip' => 'staff', 'staff_notify_setup' => 'staff', 'records_reminder' => 'staff', 'records_escalated' => 'staff', 'compliance' => 'staff',
        'report_card_request' => 'staff', 'report_card_reminder' => 'staff', 'report_card' => 'forms',
        'headcount_due' => 'headcount', 'headcount_strike' => 'headcount',
        'announcement' => 'announcement', 'closure' => 'announcement', 'menu' => 'announcement',
        'team_message' => 'chat', 'chat' => 'chat', 'message' => 'chat', 'sms_reply' => 'chat', 'voicemail' => 'chat',
        'nudge' => 'chat', 'child_note' => 'chat', 'support_ticket' => 'chat', 'content_report' => 'chat',
        'photo' => 'photos', 'moment' => 'photos', 'observation' => 'photos',
        'countersign_reminder' => 'forms', 'form_correction' => 'forms', 'document_shared' => 'forms',
        'family_file' => 'forms', 'occms_roa_ready' => 'forms',
        'pfcc_copay_unpaid' => 'billing', 'payment_plan' => 'billing', 'payment' => 'billing', 'invoice' => 'billing',
        'email' => 'email',
    ];

    /** user_id => [rows...] waiting to be pushed when this request or command ends. */
    private static array $pendingPush = [];

    /** @var array<string,?int> memo for agencyOf*() */
    private static array $agencyMemo = [];

    public static function agencyOfCentre(?int $centreId): ?int
    {
        if (! $centreId) return null;
        return self::$agencyMemo['c'.$centreId] ??= (($a = (int) DB::table('centres')->where('id', $centreId)->value('agency_id')) ?: null);
    }

    public static function agencyOfFamily(?int $familyId): ?int
    {
        if (! $familyId) return null;
        if (! array_key_exists('f'.$familyId, self::$agencyMemo)) {
            self::$agencyMemo['f'.$familyId] = self::agencyOfCentre((int) DB::table('families')->where('id', $familyId)->value('centre_id'));
        }
        return self::$agencyMemo['f'.$familyId];
    }

    public static function agencyOfChild(?int $childId): ?int
    {
        if (! $childId) return null;
        if (! array_key_exists('k'.$childId, self::$agencyMemo)) {
            self::$agencyMemo['k'.$childId] = self::agencyOfFamily((int) DB::table('children')->where('id', $childId)->value('family_id'));
        }
        return self::$agencyMemo['k'.$childId];
    }

    /* WHICH AGENCY A BELL ROW IS ABOUT (2026-11-24). The writer's own agency_id wins; then what the row's data names
       (agency, centre, child, family, room); then the agency the web request is working in. A platform ticket alert
       (TicketAlerts) is no agency's. NULL = platform-level or unknown, and pushes as before. */
    private static function agencyFor(array $r, array $data): ?int
    {
        try {
            if (TicketAlerts::allowsUser((int) $r['user_id'])) return null;
            if (array_key_exists('agency_id', $r)) return $r['agency_id'] ? (int) $r['agency_id'] : null;
            if (! empty($data['agency_id']) && is_numeric($data['agency_id'])) return (int) $data['agency_id'];
            if (! empty($data['centre_id']) && is_numeric($data['centre_id'])) return self::agencyOfCentre((int) $data['centre_id']);
            if (! empty($data['child_id']) && is_numeric($data['child_id'])) return self::agencyOfChild((int) $data['child_id']);
            if (! empty($data['family_id']) && is_numeric($data['family_id'])) return self::agencyOfFamily((int) $data['family_id']);
            if (! empty($data['room_id']) && is_numeric($data['room_id'])) {
                return self::agencyOfCentre((int) DB::table('rooms')->where('id', (int) $data['room_id'])->value('centre_id'));
            }
            return TestAgency::sessionAgency();
        } catch (\Throwable $e) {
            return null;
        }
    }
    private static bool $flushHooked = false;

    /**
     * Insert one notification row, or a list of them.
     *
     * Rows addressed to an account that cannot sign in are dropped silently: the caller
     * asked for somebody to be told, and the honest answer is that there is nobody there
     * to tell.
     */
    public static function write(array $row): void
    {
        if ($row === []) {
            return;
        }

        $rows = array_is_list($row) ? $row : [$row];
        $keep = [];
        $userIds = [];

        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['user_id'])) {
                continue;
            }
            $userIds[] = (int) $r['user_id'];
        }
        if (! $userIds) {
            return;
        }

        /* Asked once for the whole batch. A daily digest writes one row per recipient,
           and a query per row would turn a fan-out into a much bigger one. */
        $off = self::switchedOff(array_values(array_unique($userIds)));

        /* STAFF ROWS ONLY TO STAFF (2026-11-06, NotifyAudience). An account whose staff role was switched off kept
           getting clock reminders and the staff team chat. Fail closed: no answer = no staff-only rows. */
        $staffIds = [];
        $staffAsk = [];
        foreach ($rows as $r) {
            if (is_array($r) && ! empty($r['user_id']) && NotifyAudience::ofType($r['type'] ?? null) === 'staff') {
                $staffAsk[] = (int) $r['user_id'];
            }
        }
        if ($staffAsk) {
            try {
                $staffIds = NotifyAudience::staffUsers(array_values(array_unique($staffAsk)));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $topicAsk = [];
        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['user_id'])) {
                continue;
            }
            if (isset($off[(int) $r['user_id']])) {
                continue;
            }
            if (NotifyAudience::ofType($r['type'] ?? null) === 'staff' && ! isset($staffIds[(int) $r['user_id']])) {
                continue;
            }
            /* Immunization and drill notices go only to the roles the agency left switched on (2026-11-09,
               App\Support\StaffTopicNotify - Settings → Notification settings → Immunizations & drills). */
            $topic = StaffTopicNotify::TYPES[(string) ($r['type'] ?? '')] ?? null;
            if ($topic !== null) {
                $tk = $topic . '|' . (int) $r['user_id'];
                if (! array_key_exists($tk, $topicAsk)) {
                    try { $topicAsk[$tk] = StaffTopicNotify::allowsAnywhere($topic, (int) $r['user_id']); }
                    catch (\Throwable $e) { report($e); $topicAsk[$tk] = true; }
                }
                if (! $topicAsk[$tk]) {
                    continue;
                }
            }
            // Every row is stamped, so a caller that forgets does not get 1970 or a
            // MySQL default on a different clock. See App\Support\Audit for that story.
            if (empty($r['created_at'])) {
                $r['created_at'] = now();
            }
            $wantsPush = ! array_key_exists('push', $r) || $r['push'] !== false;
            unset($r['push']);   // not a column
            if ($wantsPush) {
                $r['__push'] = true;
            }
            /* Every alert carries how loud it is (AlertSeverity: high = red, warning = amber, info = teal), so the
               takeover, toasts and pushes colour it the same way. A caller's own severity wins. 2026-10-30. */
            try {
                $d = $r['data'] ?? null;
                $arr = is_array($d) ? $d : (is_string($d) && $d !== '' ? (json_decode($d, true) ?: []) : []);
                if (is_array($arr) && empty($arr['severity'])) {
                    $arr['severity'] = AlertSeverity::of($r['type'] ?? null, $r['title'] ?? null, $arr);
                    $r['data'] = is_array($d) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            } catch (\Throwable $e) {
                // Colour is never worth losing the notification.
            }
            /* ONE KEY FOR WHERE A TAP GOES (2026-11-04, Anthony: "all notifications should allow me to click and goto the
               correct section"). Writers name it link, url, href or hash; the phone push read all four, but the
               notification list and the toasts read only link/url, so a compliance, new-family, immunization or co-pay
               alert opened nothing from inside the app. A row without link/url gets `link` from href/hash here. */
            try {
                $d = $r['data'] ?? null;
                $arr = is_array($d) ? $d : (is_string($d) && $d !== '' ? (json_decode($d, true) ?: []) : []);
                if (is_array($arr) && empty($arr['link']) && empty($arr['url'])) {
                    $to = (string) ($arr['href'] ?? $arr['hash'] ?? '');
                    if ($to !== '') {
                        $arr['link'] = ($to[0] === '#' || $to[0] === '/' || str_starts_with($to, 'http')) ? $to : '#' . $to;
                        $r['data'] = is_array($d) ? $arr : json_encode($arr, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    }
                }
            } catch (\Throwable $e) {
            }
            // Every row names its agency (same keys on every row: one bulk insert).
            $dd = $r['data'] ?? null;
            $r['agency_id'] = self::agencyFor($r, is_array($dd) ? $dd : (is_string($dd) && $dd !== '' ? (json_decode($dd, true) ?: []) : []));
            // The person switched this kind of notice off in "Notify me about" (StaffNotifyPrefs, 2026-11-26): no bell row, no push.
            if (StaffNotifyPrefs::holdsBack($r, is_array($dd) ? $dd : (is_string($dd) && $dd !== '' ? (json_decode($dd, true) ?: []) : []))) {
                continue;
            }
            if (TicketAlerts::allowsUser((int) $r['user_id'])) {
                $r['__platform'] = true;   // a platform ticket alert: its push goes out even from a test agency
            }
            $keep[] = $r;
        }

        if (! $keep) {
            return;
        }

        // In each person's language (2026-10-22, MessageTranslator); English if slow.
        foreach ($keep as &$k) {
            if (TestAgency::isAgency($k['agency_id'] ?? null)) {
                continue;   // a test agency spends no AI (2026-11-24)
            }
            $loc = MessageTranslator::forUser((int) $k['user_id']);
            if ($loc && (isset($k['title']) || isset($k['body']))) {
                [$t, $b] = MessageTranslator::many([(string) ($k['title'] ?? ''), (string) ($k['body'] ?? '')], $loc, 5);
                if (isset($k['title'])) { $k['title'] = $t; }
                if (isset($k['body'])) { $k['body'] = $b; }
            }
        }
        unset($k);

        try {
            /* THE ONE PLACE ALLOWED TO TOUCH THIS TABLE DIRECTLY — and the one line a
               mechanical sweep must never rewrite. The sweep that converted the other
               call sites turned this into a call to write(), which recursed until the
               process exhausted 128MB and died with no output and nothing in the log.
               A sweep has to skip the file implementing the thing it is sweeping for. */
            $push = [];
            foreach ($keep as &$k) {
                if (! empty($k['__push'])) {
                    // A test agency's row stays in the bell but never rings a phone (2026-11-24).
                    if (! TestAgency::isAgency($k['agency_id'] ?? null) || ! empty($k['__platform'])) {
                        $push[] = $k;
                    } else {
                        TestAgency::note('push', ['user' => (int) $k['user_id'], 'agency' => $k['agency_id'], 'type' => $k['type'] ?? null]);
                    }
                }
                unset($k['__push'], $k['__platform']);
            }
            unset($k);
            DB::table('notifications')->insert($keep);
        } catch (\Throwable $e) {
            // A notification is never worth failing the thing that produced it.
            report($e);
            return;
        }

        try {
            foreach ($push as $k) {
                self::$pendingPush[(int) $k['user_id']][] = $k;
            }
            if ($push && ! self::$flushHooked) {
                self::$flushHooked = true;
                app()->terminating(static fn () => self::flushPush());
                // A queue worker runs many jobs before it terminates: send after each job.
                \Illuminate\Support\Facades\Event::listen(\Illuminate\Queue\Events\JobProcessed::class, static fn () => self::flushPush());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Send the waiting bell rows to each person's phone, one push per person. */
    public static function flushPush(): void
    {
        $all = self::$pendingPush;
        self::$pendingPush = [];
        if (! $all) {
            return;
        }
        $fcm = app(\App\Services\FcmService::class);
        foreach ($all as $uid => $rows) {
            try {
                // The caller already pushed this person itself (its own, often richer or urgent, payload).
                $at = \App\Services\FcmService::$pushedAt[$uid] ?? 0;
                if ($at && time() - $at < self::PUSH_DEDUPE_SECS) {
                    continue;
                }
                $first = $rows[0];
                $d = $first['data'] ?? null;
                $data = is_array($d) ? $d : (is_string($d) && $d !== '' ? (json_decode($d, true) ?: []) : []);
                $type = (string) ($first['type'] ?? '');
                $cat = (string) ($data['kt_category'] ?? (self::TYPE_CATEGORY[$type] ?? 'general'));
                $link = (string) ($data['link'] ?? $data['url'] ?? $data['href'] ?? $data['hash'] ?? '');
                if ($link !== '' && $link[0] !== '#' && $link[0] !== '/' && ! str_starts_with($link, 'http')) {
                    $link = '#' . $link;
                }
                $title = trim((string) ($first['title'] ?? '')) ?: 'KiddieTrac';
                $body = trim((string) ($first['body'] ?? ''));
                if (count($rows) > 1) {
                    $title .= ' (+' . (count($rows) - 1) . ' more)';
                }
                // kt_tone: a tone the person chose for this one source (a mailbox's own new-mail sound, 2026-11-05).
                $send = static fn () => $fcm->sendToUser((int) $uid, $title, $body, $link, false, false, true, ['kt_category' => $cat]
                    + (! empty($data['kt_tone']) ? ['kt_tone' => (string) $data['kt_tone']] : []));
                // A platform ticket alert flushes after TicketAlerts has finished: send it inside its scope again.
                ! empty($first['__platform']) ? TicketAlerts::asPlatform($send) : $send();
            } catch (\Throwable $e) {
                // A ring is never worth more than the row already in the bell.
                report($e);
            }
        }
    }

    /**
     * Which of these accounts cannot sign in, as [user_id => true].
     *
     * Audience::OFF_STATUSES is the ONE definition of "switched off" — the login guard,
     * the mail layer and the token sweep all read it. Keeping a local list here is
     * exactly how the front door and the mail layer once drifted apart.
     */
    private static function switchedOff(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        try {
            return DB::table('users')
                ->whereIn('id', $userIds)
                ->where(function ($q) {
                    $q->whereIn('status', Audience::OFF_STATUSES)->orWhereNotNull('deleted_at');
                })
                ->pluck('id')->flip()->map(fn () => true)->all();
        } catch (\Throwable $e) {
            report($e);

            /* FAIL CLOSED IS WRONG HERE. If this lookup breaks, dropping every
               notification would silence the whole portal — parents would stop being told
               their child arrived. The exposure this guards against is latent and swept
               nightly; silence is immediate and total. So a failure lets the rows
               through and is reported. */
            return [];
        }
    }
}
