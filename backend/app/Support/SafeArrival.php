<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe Arrival (2026-09-29).
 *
 * Anthony, after comparing us with MyDigitalChildcare: "the safe arrival gap -- can you
 * see what we are missing with our system vs the competitor and build this out".
 *
 * What was there: a fixed 09:30 Toronto "has X arrived?" reminder for every enrolled
 * child. What Safe Arrival adds:
 *   - per child: due at the child's expected drop-off (or the agency's default) + grace;
 *   - only on days the child is SCHEDULED, at a centre that is open that day;
 *   - escalation to the room's educators and the centre's director when the parents have
 *     not answered;
 *   - a board for staff, and a record of how each case was closed.
 *
 * OFF by default for every agency: it messages parents, so an admin switches it on.
 *
 * The day's list is computed LIVE (who is scheduled, who has signed in, who is reported
 * absent); safe_arrival_checks only records the actions taken. So the board is right even
 * between runs of the job.
 */
class SafeArrival
{
    public const DEFAULTS = [
        'enabled' => false,
        'grace_minutes' => 15,          // after the expected time before parents are asked
        'default_time' => '09:00',      // for a child with no expected drop-off time
        'escalate_after_minutes' => 20, // after asking the parents, before staff are alerted
        // Phone call (2026-10-13): when the text has gone unanswered, ring the parents and
        // let them press 1 absent / 2 on the way / 3 already dropped off. SafeArrivalCall.
        'call_parents' => false,
        'call_after_minutes' => 10,     // after asking the parents, before calling them
        /* NEW CHILDREN START TOMORROW (2026-10-28, Anthony: "with new families onboarded the safe arrival should check
           the very next day and not start the day of when new families/children are boarded"). A child added to
           KiddieTrac today (their own record, in the agency's day) is not expected until tomorrow - the family is
           still being set up, and the first morning text would be about a child nobody has told them about yet.
           A child moved to a new room or enrolment is NOT new: only the child record's own date counts. */
        'start_next_day' => true,
        /* NOT HERE AFTER EVERY CHECK: MARK ABSENT AND TELL THE FAMILY (2026-11-24, Anthony: "after the last alert but make this
           configurable"). after_alert = this many minutes after the last staff alert; closing = at the centre's closing
           time; off = never (the check closes as "Not resolved" as before). App\Support\SafeArrivalAbsentMail. */
        'absent_notice' => 'after_alert',
        'absent_after_minutes' => 60,
        /* WHO IS ALERTED (2026-10-22, Anthony: "add settings for safe arrival to notify
           director, admin (right now for ilearn it should be both)"). Used by the morning
           alert and the end-of-day one. Directors = centre directors of the child's
           centre; admins = every agency admin. The room's educators are always told. Until
           now the alert only reached admins tied to that centre, and iLearn's admins are
           agency-wide, so they were never told unless the room had no educator. */
        'notify_directors' => true,
        'notify_admins' => false,
        /* WHEN AND HOW directors/admins hear (2026-10-30, Anthony: "are the centre directors and admins getting the
           notifications as well - make sure this is configurable in the portal").
             up_timing        'with_educators' = the same moment as the room's educators (as before);
                              'after' = only if the child still is not here up_after_minutes after the educators
                              were alerted (a room with no educator still goes straight up).
             up_email         'fallback' = only when no push reached them (as before) | 'always' | 'off'.
             up_sms           also text them (the mobile on their profile; the agency SMS switch and consent apply).
           The educators' own channels do not change: bell + push, email if no push reaches them. */
        'up_timing' => 'with_educators',
        'up_after_minutes' => 10,
        'up_email' => 'fallback',
        'up_sms' => false,
        /* END OF DAY — Safe Departure (2026-10-22). A child still signed in past their
           pickup (or the centre's closing time) + grace: educators, then up, then parents. */
        'departure_enabled' => false,
        'departure_grace_minutes' => 30,          // after pickup/closing, before the educators are asked
        'departure_default_time' => '18:00',      // a centre with no closing time and no child pickup time
        'departure_escalate_after_minutes' => 15, // educators unanswered -> directors/admins
        'departure_parents_after_minutes' => 15,  // still open after that -> parents asked
        /* HEADCOUNTS COUNT (2026-11-03, Anthony: "headcounts for a child should count towards the safe arrival ...
           no notification should be sent out unless required if headcount doesn't account for the child").
             'present' = a child an educator ticked in today's headcount is HERE: no parent message, no call, no
                         staff alert while that stands; the board says "counted, not signed in" so staff sign them in.
             'off'     = only a sign-in counts (the behaviour before this).
           Which headcounts count: see headcountCounted(). */
        'headcount_mode' => 'present',
        /* HOW LONG A COUNT HOLDS (2026-11-03, Anthony: "a count should expire after 2 hours without a sign-in").
           Minutes from the headcount; a newer tick restarts it. 0 = the rest of the day. After it runs out Safe
           Arrival carries on exactly as if the child had never been counted. */
        'headcount_hold_minutes' => 120,
        /* RUNNING LATE FROM A PARENT (2026-11-06, Anthony: "all 3 but make this configurable"). Who hears when a parent
           says they will drop off or pick up late: the room's educators, the centre's directors, the agency's admins. */
        'late_notify_educators' => true,
        'late_notify_directors' => true,
        'late_notify_admins' => true,
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = is_array($s['safe_arrival'] ?? null) ? $s['safe_arrival'] : [];
        $out = array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
        $out['enabled'] = (bool) $out['enabled'];
        $out['grace_minutes'] = max(0, min(180, (int) $out['grace_minutes']));
        $out['escalate_after_minutes'] = max(5, min(240, (int) $out['escalate_after_minutes']));
        $out['call_parents'] = (bool) $out['call_parents'];
        $out['start_next_day'] = (bool) $out['start_next_day'];
        $out['call_after_minutes'] = max(5, min(120, (int) $out['call_after_minutes']));
        $out['default_time'] = preg_match('/^\d{2}:\d{2}$/', (string) $out['default_time']) ? $out['default_time'] : '09:00';
        $out['notify_directors'] = (bool) $out['notify_directors'];
        $out['notify_admins'] = (bool) $out['notify_admins'];
        $out['up_timing'] = in_array($out['up_timing'], ['with_educators', 'after'], true) ? $out['up_timing'] : 'with_educators';
        $out['up_after_minutes'] = max(5, min(120, (int) $out['up_after_minutes']));
        $out['up_email'] = in_array($out['up_email'], ['fallback', 'always', 'off'], true) ? $out['up_email'] : 'fallback';
        $out['up_sms'] = (bool) $out['up_sms'];
        $out['departure_enabled'] = (bool) $out['departure_enabled'];
        $out['departure_grace_minutes'] = max(0, min(240, (int) $out['departure_grace_minutes']));
        $out['departure_escalate_after_minutes'] = max(5, min(240, (int) $out['departure_escalate_after_minutes']));
        $out['departure_parents_after_minutes'] = max(5, min(240, (int) $out['departure_parents_after_minutes']));
        $out['departure_default_time'] = preg_match('/^\d{2}:\d{2}$/', (string) $out['departure_default_time']) ? $out['departure_default_time'] : '18:00';
        $out['headcount_mode'] = in_array($out['headcount_mode'], ['off', 'present'], true) ? $out['headcount_mode'] : 'present';
        $out['absent_notice'] = in_array($out['absent_notice'], ['after_alert', 'closing', 'off'], true) ? $out['absent_notice'] : 'after_alert';
        $out['absent_after_minutes'] = max(15, min(240, (int) $out['absent_after_minutes']));
        $out['headcount_hold_minutes'] = in_array((int) $out['headcount_hold_minutes'], [0, 60, 120, 180], true) ? (int) $out['headcount_hold_minutes'] : 120;
        foreach (['late_notify_educators', 'late_notify_directors', 'late_notify_admins'] as $k) {
            $out[$k] = (bool) $out[$k];
        }

        return $out;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $s['safe_arrival'] = array_merge(self::settings($agencyId), array_intersect_key($in, self::DEFAULTS));
        DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s)]);

        return self::settings($agencyId);
    }

    public static function enabled(int $agencyId): bool
    {
        return self::settings($agencyId)['enabled'];
    }

    /**
     * Who an alert about a child goes to.
     *   $educators: the people assigned to the child's room;
     *   $up:        the centre's directors (notify_directors) and the agency's admins
     *               (notify_admins), as the agency chose.
     * Never nobody: with no one found, the agency's admins.
     *
     * @return int[]
     */
    /* NO SAFE ARRIVAL TEXTS TO PLATFORM ADMINS (2026-11-04). Anthony: "why is it receiving the safe arrival
       notifications" - he holds an agency-admin role at iLearn as well, so every alert there texted his own phone.
       A platform admin still gets the bell and the phone push (and the email fallback); only the text is not sent.
       Used by both senders: the alert (alertStaff) and the arrived / all-clear follow-up (SafeArrivalAllClear). */
    /* A DIRECTOR'S / ADMIN'S OWN SAFE ARRIVAL CHANNELS (2026-11-24, Anthony: "add for admin/directors ability to turn
       off safe arrival notifications in their user profile (calls, texts, emails and app notifications) turn off
       calls as a default"). Settings -> Notifications, stored in notification_prefs (event_key safe_arrival).
       Defaults: app, email and texts on; calls OFF. Only people holding an active agency_admin / centre_director
       role choose: a room educator is always told (somebody in the room must hear about a missing child).
       Safe Arrival never phones staff today - the calls switch governs any staff call added later (staffWants). */
    public const PREF_KEY = 'safe_arrival';
    public const PREF_DEFAULTS = ['push' => true, 'email' => true, 'sms' => true, 'voice' => false];

    /** @var array<int,array> */
    private static array $prefMemo = [];

    public static function managesSafeArrival(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director'])->exists();
    }

    /** @return array{push:bool,email:bool,sms:bool,voice:bool} */
    public static function staffPrefs(int $userId): array
    {
        if (isset(self::$prefMemo[$userId])) {
            return self::$prefMemo[$userId];
        }
        $out = self::PREF_DEFAULTS;
        try {
            $r = DB::table('notification_prefs')->where('user_id', $userId)->where('event_key', self::PREF_KEY)->first();
            if ($r) {
                foreach (['push', 'email', 'sms', 'voice'] as $k) {
                    if ($r->{$k} !== null) {
                        $out[$k] = (bool) $r->{$k};
                    }
                }
            }
        } catch (\Throwable $e) {
        }

        return self::$prefMemo[$userId] = $out;
    }

    /** May Safe Arrival reach this person by this channel (push | email | sms | voice)? */
    public static function staffWants(int $userId, string $channel): bool
    {
        try {
            if (! self::managesSafeArrival($userId)) {
                return true;   // educators: always (the room must hear)
            }
        } catch (\Throwable $e) {
            return true;
        }

        return (bool) (self::staffPrefs($userId)[$channel] ?? true);
    }

    public static function forgetPrefs(int $userId): void
    {
        unset(self::$prefMemo[$userId]);
    }

    public static function textsStaff(int $userId): bool
    {
        return ! DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')
            ->where('active', 1)->exists();
    }

    /* NO LONGER EXPECTED (2026-11-11, Anthony: "we closed Bruni as educator earlier today but why are safe arrival
       alerts being sent out to kids that does not belong to that educator"). Closing Bruni withdrew her two children
       at 9:41 AM, five minutes after their "not here" alerts went out. today() then left them out, and the 5-minute
       pass only ever looks at children on today()'s list, so their checks were never closed: the alerts stayed unread
       and at closing time the admins were told "2 not resolved today" about children who are no longer ours.
       An open check for a child who is no longer on today's list (withdrawn, enrolment ended, family closed, off
       today's schedule) is closed as 'not_expected' by SafeArrivalCommand, and no automatic call rings their parents. */
    public static function stillExpected(object $check): bool
    {
        return isset(self::today((int) $check->agency_id, null, null, (string) $check->check_date)[(int) $check->child_id]);
    }

    /** Why a child dropped off today's list, in words: "is no longer enrolled" / "is no longer booked for today". */
    public static function notExpectedReason(int $childId): string
    {
        $c = DB::table('children')->where('id', $childId)->first(['deleted_at', 'enrollment_status', 'family_id']);
        if (! $c || $c->deleted_at || $c->enrollment_status !== 'enrolled'
            || ! Live::families(DB::table('families as fa')->where('fa.id', $c->family_id), 'fa')->exists()) {
            return 'is no longer enrolled';
        }

        return 'is no longer booked for today';
    }

    public static function recipients(int $agencyId, int $centreId, ?int $roomId, array $cfg, bool $educators = true, bool $up = true, bool $fallback = true): array
    {
        $ids = [];
        if ($educators && $roomId) {
            // Only someone still on staff here (2026-11-11): a room link left behind after their staff role ended
            // (an educator kept on as a parent) is not a reason to alert them about the room's children.
            $ids = DB::table('educator_rooms as er')->join('users as u', 'u.id', '=', 'er.user_id')
                ->where('er.room_id', $roomId)->whereNull('u.deleted_at')->where('u.status', 'active')
                ->whereExists(fn ($q) => $q->from('role_assignments as ra')->whereColumn('ra.user_id', 'u.id')
                    ->where('ra.agency_id', $agencyId)->where('ra.active', 1)
                    ->whereIn('ra.role', ['educator', 'home_visitor', 'centre_director', 'agency_admin']))
                ->pluck('u.id')->all();
        }
        if ($up) {
            if ($cfg['notify_directors']) {
                // A director of this centre — and, as before, an admin assigned to it alone.
                $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)
                    ->whereIn('role', ['centre_director', 'agency_admin'])->where('centre_id', $centreId)
                    ->pluck('user_id')->all());
            }
            if ($cfg['notify_admins']) {
                $ids = array_merge($ids, DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')
                    ->where('agency_id', $agencyId)->pluck('user_id')->all());
            }
        }
        if (! $ids && $up && $fallback) {
            $ids = DB::table('role_assignments')->where('active', 1)->where('role', 'agency_admin')
                ->where('agency_id', $agencyId)->pluck('user_id')->all();
        }
        if ($ids) {
            $ids = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')->where('status', 'active')->pluck('id')->all();
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Bell + push to each person; anyone the push does not reach gets it by email, and is
     * named (2026-10-01: an alert reached neither educator whose phone token had expired).
     *
     * @return array{0:int,1:string[]} how many were alerted, and who no phone alert reached
     */
    /** The channels the agency chose for directors/admins, for alertUsers(). */
    public static function upOpts(array $cfg): array
    {
        return ['email' => $cfg['up_email'] ?? 'fallback', 'sms' => (bool) ($cfg['up_sms'] ?? false)];
    }

    /**
     * Alert the room's educators and/or the directors/admins the agency chose (2026-10-30), each group on its own
     * channels: educators bell + push (email if no push reaches them); directors/admins per up_email / up_sms.
     * Someone in both groups is alerted once, as a director/admin. With neither switch on, directors/admins are
     * reached only when the room has nobody to tell - the same rule recipients() always had.
     *
     * @return array{0:int,1:string[]} how many were alerted, and who no push reached
     */
    public static function alertTiered(int $agencyId, array $cfg, int $centreId, ?int $roomId, bool $educators, bool $up,
                                       string $title, string $body, int $childId, bool $dry = false): array
    {
        $edu = $educators ? self::recipients($agencyId, $centreId, $roomId, $cfg, true, false) : [];
        // The all-admins fallback only when the room had nobody to tell (recipients() rule): never on top of educators.
        $mgr = $up ? self::recipients($agencyId, $centreId, $roomId, $cfg, false, true, $educators && ! $edu) : [];
        $edu = array_values(array_diff($edu, $mgr));
        if ($dry) {
            return [count($edu) + count($mgr), []];
        }
        [$n1, $u1] = $edu ? self::alertUsers($agencyId, $edu, $title, $body, $childId) : [0, []];
        [$n2, $u2] = $mgr ? self::alertUsers($agencyId, $mgr, $title, $body, $childId, '#safe-arrival', self::upOpts($cfg)) : [0, []];

        return [$n1 + $n2, array_merge($u1, $u2)];
    }

    /**
     * $opts (2026-10-30): email 'fallback' (only when no push reached them; the default) | 'always' | 'off';
     * sms true = also text them. Without $opts it behaves exactly as before.
     */
    public static function alertUsers(int $agencyId, array $ids, string $title, string $body, int $childId, string $link = '#safe-arrival', array $opts = []): array
    {
        $unreached = [];
        if (\App\Support\TestAgency::isAgency($agencyId)) {   // test agency: demo mode, nobody is alerted (2026-11-24)
            \App\Support\TestAgency::note('safe arrival alert', ['agency' => $agencyId, 'child' => $childId, 'users' => count($ids)]);
            return [count($ids), []];
        }
        $emailMode = in_array($opts['email'] ?? 'fallback', ['fallback', 'always', 'off'], true) ? ($opts['email'] ?? 'fallback') : 'fallback';
        $sms = ! empty($opts['sms']);
        // arrival | departure (2026-11-02): tagged on each row so the all-clear finds exactly these alerts (SafeArrivalAllClear).
        $kind = ($opts['kind'] ?? 'arrival') === 'departure' ? 'departure' : 'arrival';
        foreach ($ids as $uid) {
            $res = [];
            $nid = null;
            $wantPush = self::staffWants((int) $uid, 'push');   // their own Safe Arrival channels (2026-11-24)
            try {
                if (! $wantPush) {
                    throw new \RuntimeException('__sa_push_off');
                }
                $before = (int) DB::table('notifications')->where('user_id', $uid)->max('id');
                Notify::write([
                    'user_id' => $uid, 'type' => 'safe_arrival', 'title' => $title, 'body' => $body,
                    'data' => json_encode(['link' => $link, 'child_id' => $childId, 'sa_kind' => $kind]), 'created_at' => now(),
                ]);
                $after = (int) DB::table('notifications')->where('user_id', $uid)->max('id');
                $nid = $after > $before ? $after : null;
                $res = app(\App\Services\FcmService::class)->sendToUser($uid, $title, $body, $link);
            } catch (\Throwable $e) {
                if ($e->getMessage() !== '__sa_push_off') {
                    \Illuminate\Support\Facades\Log::warning('Safe Arrival staff alert failed', ['child' => $childId, 'user' => $uid, 'error' => $e->getMessage()]);
                }
            }
            $reached = (int) ($res['sent'] ?? 0) > 0;
            $emailed = false;
            $texted = false;
            $u = ($reached && $emailMode !== 'always' && ! $sms) ? null
                : DB::table('users')->where('id', $uid)->first(['first_name', 'last_name', 'email', 'phone']);
            if ($u) {
                $who = trim($u->first_name . ' ' . $u->last_name);
                if (! $reached && $wantPush) {   // switched off on purpose is not "could not reach"
                    $unreached[] = $who;
                }
                if ($sms && $u->phone && self::textsStaff((int) $uid) && self::staffWants((int) $uid, 'sms')) {
                    try {
                        $texted = (bool) app(\App\Http\Controllers\Api\SmsController::class)
                            ->sendOne($agencyId, (int) $uid, (string) $u->phone, $title . ' - ' . $body, 'safe_arrival_staff');
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Safe Arrival staff text failed', ['child' => $childId, 'user' => $uid, 'error' => $e->getMessage()]);
                    }
                }
                if ($u->email && self::staffWants((int) $uid, 'email') && ($emailMode === 'always' || ($emailMode === 'fallback' && ! $reached))) {
                    try {
                        self::staffEmail($agencyId, (string) $u->email, $who, $title, $body);
                        $emailed = true;
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('Safe Arrival staff email failed', ['child' => $childId, 'user' => $uid, 'error' => $e->getMessage()]);
                    }
                }
            }
            // Which channels this alert went by, so the all-clear follows the same ones (2026-11-02).
            if ($nid) {
                try {
                    $raw = DB::table('notifications')->where('id', $nid)->value('data');
                    $d = is_string($raw) ? (json_decode($raw, true) ?: []) : [];
                    if ((int) ($d['child_id'] ?? 0) === $childId) {
                        $d['emailed'] = $emailed ? 1 : 0;
                        $d['texted'] = $texted ? 1 : 0;
                        DB::table('notifications')->where('id', $nid)->update(['data' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
                    }
                } catch (\Throwable $e) {
                }
            }
        }

        return [count($ids), $unreached];
    }

    /** The Safe Arrival mark for emails: the green shield (img/safe-arrival.svg) as a PNG (2026-10-28). */
    public const EMAIL_ICON = 'https://app.kiddietrac.com/img/safe-arrival-email.png';

    public static function staffEmail(int $agencyId, string $to, string $toName, string $title, string $body): void
    {
        if (\App\Support\TestAgency::isAgency($agencyId)) return;   // test agency: demo mode (2026-11-24)
        $toName = (string) (strtok(trim($toName), ' ') ?: '');   // first name only in email (standing rule)
        $html = \App\Services\EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">' . e($body) . '</p>'
            . '<p style="margin:14px 0 0;font-size:13.5px;color:#475569;line-height:1.6;">You are getting this by email because '
            . 'the alert could not reach your phone. Open KiddieTrac and allow notifications so the next one does.</p>',
            ['eyebrow_icon' => \App\Support\SafeArrival::EMAIL_ICON, 'eyebrow' => 'SAFE ARRIVAL', 'title' => $title, 'subtitle' => 'Attendance', 'preheader' => $body]);
        dispatch(function () use ($agencyId, $to, $toName, $html, $title) {
            \App\Services\AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $toName, $title) {
                $m->to($to, $toName ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject($title);
            });
        })->onQueue('mail');
    }

    /**
     * Today's Safe Arrival list for an agency, optionally limited to centres or rooms.
     * One row per child who is SCHEDULED today at an OPEN centre.
     *
     * @return array<int, array>  keyed by child id
     */
    public static function today(int $agencyId, ?array $centreIds = null, ?array $roomIds = null, ?string $date = null): array
    {
        $cfg = self::settings($agencyId);
        $tz = AgencyTime::tz($agencyId);
        $date = $date ?: AgencyTime::today($agencyId);
        $now = Carbon::now();
        $dayKey = strtolower(Carbon::parse($date, $tz)->format('D'));   // mon..sun
        [$from, $to] = AgencyTime::dayRange($agencyId, $date);

        // Enrolled children with an open enrolment booked for this weekday.
        $rows = DB::table('enrollments as e')
            ->join('children as c', 'c.id', '=', 'e.child_id')
            ->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->join('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->where('ce.agency_id', $agencyId)
            // Open centre, family still with us (App\Support\Live, 2026-10-09).
            ->tap(fn ($q) => \App\Support\Live::centres(\App\Support\Live::families($q, 'fa'), 'ce'))
            ->whereNull('c.deleted_at')
            ->where('c.enrollment_status', 'enrolled')
            ->where(function ($q) use ($date) { $q->whereNull('e.end_date')->orWhere('e.end_date', '>=', $date); })
            ->where(function ($q) use ($date) { $q->whereNull('e.start_date')->orWhere('e.start_date', '<=', $date); })
            ->when($centreIds !== null, fn ($q) => $q->whereIn('ce.id', $centreIds ?: [0]))
            ->when($roomIds !== null, fn ($q) => $q->whereIn('r.id', $roomIds ?: [0]))
            ->orderBy('c.first_name')
            ->get(['c.id as child_id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'c.family_id',
                'c.expected_dropoff_time', 'e.schedule', 'r.id as room_id', 'r.name as room_name', 'c.created_at as child_added_at',
                'ce.id as centre_id', 'ce.name as centre_name']);

        $open = [];     // centre id => open today?
        $out = [];
        foreach ($rows as $r) {
            if (isset($out[$r->child_id])) {
                continue;                                    // first booked enrolment wins, as CareSchedule
            }
            if (! in_array($dayKey, CareSchedule::daysOf($r->schedule), true)) {
                continue;
            }
            // Added today (or later): Safe Arrival starts tomorrow (start_next_day). created_at is UTC.
            if ($cfg['start_next_day'] && $r->child_added_at
                && Carbon::parse((string) $r->child_added_at, 'UTC')->setTimezone($tz)->toDateString() >= $date) {
                continue;
            }
            $cid = (int) $r->centre_id;
            if (! array_key_exists($cid, $open)) {
                $open[$cid] = Closures::isOperatingDay($cid, $date) && ! Closures::isClosed($cid, $date);
            }
            if (! $open[$cid]) {
                continue;
            }
            $expected = $r->expected_dropoff_time ? substr((string) $r->expected_dropoff_time, 0, 5) : $cfg['default_time'];
            $due = Carbon::parse($date . ' ' . $expected, $tz)->addMinutes($cfg['grace_minutes'])->utc();
            $out[(int) $r->child_id] = [
                'child_id' => (int) $r->child_id,
                'name' => trim(($r->preferred_name ?: $r->first_name) . ' ' . $r->last_name),
                'first_name' => $r->preferred_name ?: $r->first_name,
                'family_id' => (int) $r->family_id,
                'room_id' => (int) $r->room_id, 'room_name' => $r->room_name,
                'centre_id' => $cid, 'centre_name' => $r->centre_name,
                'expected' => $expected,
                'expected_is_default' => ! $r->expected_dropoff_time,
                'due_at' => $due,
                'arrived_at' => null, 'absent' => null, 'check' => null, 'counted' => null, 'count_expired' => null, 'late' => null,
            ];
        }
        if (! $out) {
            return [];
        }
        $ids = array_keys($out);

        // Signed in today (a check-in dated later than now does not count).
        foreach (DB::table('check_events')->whereIn('child_id', $ids)->where('event_type', 'check_in')
            ->whereBetween('occurred_at', [$from, $to])->where('occurred_at', '<=', $now)
            ->groupBy('child_id')->selectRaw('child_id, MIN(occurred_at) as at')->get() as $ev) {
            $out[(int) $ev->child_id]['arrived_at'] = $ev->at;
        }
        foreach (DB::table('child_absences')->whereIn('child_id', $ids)->whereDate('absent_on', $date)
            ->get(['child_id', 'reason', 'note']) as $a) {
            $out[(int) $a->child_id]['absent'] = ['reason' => $a->reason, 'note' => $a->note];
        }
        foreach (DB::table('safe_arrival_checks')->whereIn('child_id', $ids)->where('check_date', $date)->get() as $c) {
            $out[(int) $c->child_id]['check'] = $c;
        }
        /* RUNNING LATE (2026-11-06): staff were told the child will be in by a time. Nobody is asked or alerted
           before that time plus the grace; the child is due then instead (never earlier than they already were). */
        if (\Illuminate\Support\Facades\Schema::hasTable('safe_arrival_late')) {
            foreach (self::lateQuery($ids, $date, 'arrival')->get() as $l) {
                $by = substr((string) $l->arrive_by, 0, 5);
                $lateDue = Carbon::parse($date . ' ' . $by, $tz)->addMinutes($cfg['grace_minutes'])->utc();
                $cid = (int) $l->child_id;
                $out[$cid]['late'] = ['by' => $by, 'due_at' => $lateDue, 'note' => $l->note, 'source' => $l->source ?? 'staff',
                    'reported_by_id' => $l->reported_by_id ? (int) $l->reported_by_id : null, 'at' => $l->updated_at];
                if ($lateDue->gt($out[$cid]['due_at'])) {
                    $out[$cid]['due_at'] = $lateDue;
                }
            }
        }
        // Counted in a headcount today but not signed in (headcount_mode = present).
        if ($cfg['headcount_mode'] === 'present') {
            $kids = [];
            foreach ($out as $cid => $r) {
                if (! $r['arrived_at'] && ! $r['absent']) {
                    $kids[$cid] = $r['centre_id'];
                }
            }
            $expired = [];
            foreach (self::headcountCounted($agencyId, $kids, $from, $to, $now, $expired) as $cid => $hc) {
                $out[$cid]['counted'] = $hc;
            }
            foreach ($expired as $cid => $hc) {
                $out[$cid]['count_expired'] = $hc;    // shown on the board only; the state is as if never counted
            }
        }

        foreach ($out as &$row) {
            $row['state'] = self::state($row, $now);
        }

        return $out;
    }

    /**
     * HEADCOUNTS AS EVIDENCE (2026-11-03). For children with no sign-in today: which of them an educator has
     * physically counted, by the headcounts that can say so -
     *   - same agency, and the child's OWN centre;
     *   - taken in the agency's day ($from..$to, the AgencyTime::dayRange of the check date) and not after $now;
     *   - a ROOM headcount speaks only through its "not signed in yet" list (unsigned_offered / unsigned_seen):
     *     its main list is children already signed in, which a check-in proves anyway - and if that check-in was
     *     later removed as a mistake, a tick next to it is no evidence either;
     *   - a WALK headcount speaks through its own list (expected / seen): that list is by permission, not sign-in.
     * THE LATEST of those headcounts that had the child ON ITS LIST decides: ticked = counted; listed but not
     * ticked = not counted. So a later count that leaves the child unticked undoes an earlier one, and Safe
     * Arrival carries on as if the child had never been counted.
     * A TICK HOLDS headcount_hold_minutes from its headcount (0 = rest of the day). An older one is returned in
     * $expired instead (for the board) and counts for nothing; a newer tick is the latest, so it restarts the hold.
     *
     * @param  array<int,int>  $kids  child_id => centre_id
     * @return array<int,array{at:string,by:int,headcount_id:int,until:?string}>
     */
    public static function headcountCounted(int $agencyId, array $kids, string $from, string $to, ?Carbon $now = null, ?array &$expired = null): array
    {
        $expired = [];
        if (! $kids || ! \Illuminate\Support\Facades\Schema::hasColumn('headcounts', 'unsigned_seen_child_ids')) {
            return [];
        }
        $nowC = ($now ?: Carbon::now())->copy()->utc();
        $upto = $nowC->format('Y-m-d H:i:s');
        $hold = self::settings($agencyId)['headcount_hold_minutes'];
        $rows = DB::table('headcounts')->where('agency_id', $agencyId)
            ->whereIn('centre_id', array_values(array_unique(array_map('intval', $kids))))
            ->where('taken_at', '>=', $from)->where('taken_at', '<', $to)->where('taken_at', '<=', $upto)
            ->orderByDesc('taken_at')->orderByDesc('id')
            ->get(['id', 'centre_id', 'room_id', 'field_trip_id', 'taken_by', 'taken_at',
                'expected_child_ids', 'seen_child_ids', 'unsigned_offered_child_ids', 'unsigned_seen_child_ids']);
        $ids = fn ($j) => array_map('intval', is_string($j) ? (json_decode($j, true) ?: []) : []);
        $out = [];
        $decided = [];
        foreach ($rows as $h) {
            [$list, $seen] = $h->field_trip_id
                ? [$ids($h->expected_child_ids), $ids($h->seen_child_ids)]
                : [$ids($h->unsigned_offered_child_ids), $ids($h->unsigned_seen_child_ids)];
            foreach ($list as $cid) {
                if (isset($decided[$cid]) || ! isset($kids[$cid]) || (int) $kids[$cid] !== (int) $h->centre_id) {
                    continue;
                }
                $decided[$cid] = true;
                if (in_array($cid, $seen, true)) {
                    $until = $hold > 0 ? Carbon::parse((string) $h->taken_at, 'UTC')->addMinutes($hold) : null;
                    $hc = ['at' => (string) $h->taken_at, 'by' => (int) $h->taken_by, 'headcount_id' => (int) $h->id,
                        'until' => $until ? $until->format('Y-m-d H:i:s') : null];
                    if ($until && $nowC->greaterThanOrEqualTo($until)) {
                        $expired[$cid] = $hc;
                    } else {
                        $out[$cid] = $hc;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Today's live (not cancelled) running-late rows of one kind: arrival (drop-off) or departure (pickup).
     * Before the kind column exists every row is a drop-off, so a departure asks for nothing.
     */
    public static function lateQuery(array $childIds, string $date, string $kind = 'arrival')
    {
        $hasKind = self::lateHasKind();

        return DB::table('safe_arrival_late')->whereIn('child_id', $childIds ?: [0])->where('late_date', $date)
            ->whereNull('cancelled_at')
            ->when($hasKind, fn ($q) => $q->where('kind', $kind), fn ($q) => $q->when($kind !== 'arrival', fn ($q) => $q->whereRaw('1 = 0')));
    }

    /** When a running-late notice stops holding the alerts back: the time given plus that end of the day's grace. Null = none. */
    public static function lateDue(int $agencyId, int $childId, string $date, string $kind = 'arrival'): ?Carbon
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('safe_arrival_late')) {
            return null;
        }
        $l = self::lateQuery([$childId], $date, $kind)->first();
        if (! $l) {
            return null;
        }
        $cfg = self::settings($agencyId);
        $grace = $kind === 'departure' ? $cfg['departure_grace_minutes'] : $cfg['grace_minutes'];

        return Carbon::parse($date . ' ' . substr((string) $l->arrive_by, 0, 5), AgencyTime::tz($agencyId))->addMinutes($grace)->utc();
    }

    /**
     * Tell staff a parent said their child will be late (2026-11-06, Anthony: "notify the educator about the situation";
     * who is told: "all 3 but make this configurable"). Room educators, the centre's directors and the agency's admins,
     * each switched by late_notify_*. Bell + push only: a heads-up, not an alarm.
     *
     * @return int how many people were told
     */
    public static function notifyLate(int $agencyId, int $childId, string $kind, string $byHm, ?string $note, string $fromFirst, bool $cancelled = false): int
    {
        $cfg = self::settings($agencyId);
        $child = DB::table('children as c')->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->where('c.id', $childId)->first(['c.first_name', 'c.preferred_name', 'c.last_name', 'fa.centre_id']);
        if (! $child) {
            return 0;
        }
        // The room they are in now, else the room they are usually in.
        $roomId = DB::table('check_events')->where('child_id', $childId)->whereNotNull('room_id')
            ->orderByDesc('occurred_at')->value('room_id')
            ?: (\Illuminate\Support\Facades\Schema::hasColumn('children', 'room_id') ? DB::table('children')->where('id', $childId)->value('room_id') : null);

        $ids = [];
        if ($cfg['late_notify_educators'] && $roomId) {
            $ids = self::recipients($agencyId, (int) $child->centre_id, (int) $roomId, $cfg, true, false, false);
        }
        if ($cfg['late_notify_directors'] || $cfg['late_notify_admins']) {
            $up = ['notify_directors' => $cfg['late_notify_directors'], 'notify_admins' => $cfg['late_notify_admins']] + $cfg;
            $ids = array_merge($ids, self::recipients($agencyId, (int) $child->centre_id, null, $up, false, true, false));
        }
        $ids = array_values(array_unique($ids));
        if (! $ids) {
            return 0;
        }

        $name = trim(($child->preferred_name ?: $child->first_name) . ' ' . $child->last_name);
        $what = $kind === 'departure' ? 'pickup' : 'drop-off';
        $time = Carbon::parse('2000-01-01 ' . $byHm)->format('g:i A');
        if ($cancelled) {
            $title = "⏰ {$name}: no longer running late";
            $body = "{$fromFirst} cancelled the late {$what} notice for {$name}.";
        } else {
            $title = $kind === 'departure' ? "⏰ Late pickup: {$name}" : "⏰ Running late: {$name}";
            $body = $kind === 'departure'
                ? "{$fromFirst} will pick {$name} up by {$time}. The end-of-day alerts wait until then."
                : "{$fromFirst} says {$name} will arrive by {$time}. Safe Arrival waits until then.";
            if ($note) {
                $body .= ' Note: ' . mb_substr($note, 0, 200);
            }
        }
        $rows = [];
        foreach ($ids as $uid) {
            if (! self::staffWants((int) $uid, 'push')) {
                continue;   // app notifications switched off for Safe Arrival (2026-11-24)
            }
            $rows[] = ['user_id' => $uid, 'type' => 'running_late', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#safe-arrival', 'child_id' => $childId]), 'created_at' => now()];
        }
        if ($rows) {
            Notify::write($rows);
        }

        return count($rows);
    }

    /**
     * Log "running late, here by HH:MM" (kind arrival) or "late pickup, by HH:MM" (kind departure) for today.
     * One path for staff (the board, Today) and parents (their Today screen), so both refuse the same things.
     * $centres / $rooms scope a staff member; a parent passes null after the guardian check.
     *
     * @return array{0:?string,1:?array} [refusal message, the child's row]
     */
    public static function logLate(int $agencyId, int $childId, string $kind, string $hm, ?string $note, int $uid, string $source,
        ?array $centres = null, ?array $rooms = null): array
    {
        [$err, $row] = self::lateTarget($agencyId, $childId, $kind, $centres, $rooms);
        if ($err) {
            return [$err, $row];
        }
        $tz = AgencyTime::tz($agencyId);
        $date = AgencyTime::today($agencyId);
        $by = Carbon::parse($date . ' ' . $hm, $tz);
        if ($by->lte(Carbon::now($tz))) {
            return ['Choose a time later than now.', $row];
        }
        $note = trim((string) $note) ?: null;
        $key = ['child_id' => $childId, 'late_date' => $date] + (self::lateHasKind() ? ['kind' => $kind] : []);
        $was = DB::table('safe_arrival_late')->where($key)->first();
        DB::table('safe_arrival_late')->updateOrInsert($key, [
            'agency_id' => $agencyId, 'centre_id' => $row['centre_id'], 'arrive_by' => $hm . ':00', 'note' => $note,
            'reported_by_id' => $uid, 'cancelled_at' => null, 'cancelled_by_id' => null,
            'created_at' => $was->created_at ?? now(), 'updated_at' => now(),
        ] + (self::lateHasKind() ? ['source' => $source] : []));
        $what = $kind === 'departure' ? 'Late pickup' : 'Running late';
        self::auditLate($uid, $agencyId, $childId, $kind === 'departure' ? 'safe_departure.running_late' : 'safe_arrival.running_late',
            $what . ': ' . $row['name'] . ($kind === 'departure' ? ' picked up by ' : ' expected by ') . $by->format('g:i A')
            . ($source === 'parent' ? ' (told by a parent)' : '') . ($note ? ' - ' . $note : ''),
            ['child' => $row['name'], 'date' => $date, 'kind' => $kind, 'by' => $hm, 'note' => $note, 'source' => $source,
                'was' => $was && ! $was->cancelled_at ? substr((string) $was->arrive_by, 0, 5) : null]);
        if ($source === 'parent') {
            $first = (string) DB::table('users')->where('id', $uid)->value('first_name');
            self::notifyLate($agencyId, $childId, $kind, $hm, $note, $first !== '' ? $first : 'A parent');
        }

        return [null, $row];
    }

    /** Take back today's notice of that kind. @return array{0:?string,1:?array} */
    public static function cancelLate(int $agencyId, int $childId, string $kind, int $uid, string $source,
        ?array $centres = null, ?array $rooms = null): array
    {
        $row = self::lateRow($agencyId, $childId, $kind, $centres, $rooms);
        if (! $row) {
            return ['That child is not on today\'s list.', null];
        }
        $date = AgencyTime::today($agencyId);
        $l = self::lateQuery([$childId], $date, $kind)->first();
        if ($l) {
            DB::table('safe_arrival_late')->where('id', $l->id)
                ->update(['cancelled_at' => now(), 'cancelled_by_id' => $uid, 'updated_at' => now()]);
            self::auditLate($uid, $agencyId, $childId,
                $kind === 'departure' ? 'safe_departure.running_late_cancelled' : 'safe_arrival.running_late_cancelled',
                ($kind === 'departure' ? 'Late pickup' : 'Running late') . ' taken back: ' . $row['name']
                . ($source === 'parent' ? ' (by a parent)' : '') . ' (checks at the usual time)',
                ['child' => $row['name'], 'date' => $date, 'kind' => $kind, 'source' => $source]);
            if ($source === 'parent') {
                $first = (string) DB::table('users')->where('id', $uid)->value('first_name');
                self::notifyLate($agencyId, $childId, $kind, substr((string) $l->arrive_by, 0, 5), null, $first !== '' ? $first : 'A parent', true);
            }
        }

        return [null, $row];
    }

    /** The child's row for that end of today, or null if they are not on it (out of scope, not expected today). */
    public static function lateRow(int $agencyId, int $childId, string $kind, ?array $centres = null, ?array $rooms = null): ?array
    {
        if ($kind === 'departure') {
            $row = SafeDeparture::today($agencyId, $centres, $rooms)[$childId] ?? null;
            if ($row) {
                return $row;
            }
        }

        return self::today($agencyId, $centres, $rooms)[$childId] ?? null;
    }

    /** @return array{0:?string,1:?array} why this child cannot be marked late at that end of the day, and the row */
    private static function lateTarget(int $agencyId, int $childId, string $kind, ?array $centres, ?array $rooms): array
    {
        if (! in_array($kind, ['arrival', 'departure'], true)) {
            return ['Choose drop-off or pickup.', null];
        }
        if ($kind === 'departure' && ! self::lateHasKind()) {
            return ['Late pickup is not switched on yet.', null];
        }
        $row = self::lateRow($agencyId, $childId, $kind, $centres, $rooms);
        if (! $row) {
            return ['That child is not on today\'s list.', null];
        }
        $first = $row['first_name'];
        if ($kind === 'departure') {
            // From the end-of-day list: still_in says it. From the arrival list: arrived but not on the end-of-day list = gone home.
            if (array_key_exists('still_in', $row) ? ! $row['still_in'] : ($row['state'] ?? null) === 'arrived') {
                return [$first . ' has already been signed out today.', $row];
            }
            if (($row['state'] ?? null) === 'absent') {
                return [$first . ' is reported absent today.', $row];
            }

            return [null, $row];
        }
        if ($row['state'] === 'arrived') {
            return [$first . ' is already signed in today.', $row];
        }
        if ($row['state'] === 'absent') {
            return [$first . ' is reported absent today. Check them in if they arrive after all.', $row];
        }

        return [null, $row];
    }

    private static function lateHasKind(): bool
    {
        static $has = null;

        return $has ??= \Illuminate\Support\Facades\Schema::hasColumn('safe_arrival_late', 'kind');
    }

    private static function auditLate(int $uid, int $agencyId, int $childId, string $action, string $summary, array $extra): void
    {
        try {
            Audit::write([
                'user_id' => $uid, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode(['summary' => $summary] + $extra),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** Did staff log "running late, here by …" for this check's child and day, and has that time (plus grace) not passed yet? */
    public static function runningLate(object $check): bool
    {
        $due = self::lateDue((int) $check->agency_id, (int) $check->child_id, (string) $check->check_date, 'arrival');

        return $due !== null && now()->lt($due);
    }

    /** Is the child on this Safe Arrival check counted by a headcount right now? (the call chain asks before ringing) */
    public static function checkCounted(object $check): bool
    {
        $agencyId = (int) $check->agency_id;
        if (self::settings($agencyId)['headcount_mode'] !== 'present') {
            return false;
        }
        [$from, $to] = AgencyTime::dayRange($agencyId, (string) $check->check_date);

        return (bool) self::headcountCounted($agencyId, [(int) $check->child_id => (int) $check->centre_id], $from, $to);
    }

    /**
     * arrived | absent | resolved | counted | dropped_off | running_late | on_the_way | escalated | overdue | waiting
     * (waiting = not due yet; overdue = due, parents asked or about to be; counted = ticked in a headcount today,
     * not signed in - nothing is sent while it stands; running_late = staff logged a later arrival time that has
     * not passed yet.)
     */
    public static function state(array $row, Carbon $now): string
    {
        if ($row['arrived_at']) {
            return 'arrived';
        }
        if ($row['absent']) {
            return 'absent';
        }
        $c = $row['check'];
        if ($c && $c->resolved_at) {
            return 'resolved';
        }
        if (! empty($row['counted'])) {
            return 'counted';
        }
        // The parent answered the Safe Arrival call (SafeArrivalCall).
        $resp = $c->parent_response ?? null;
        if ($resp === 'dropped_off') {
            return 'dropped_off';
        }
        // Staff were told "running late, here by …" (safe_arrival_late): nothing is sent until then.
        $late = $row['late'] ?? null;
        if ($late && $now->lt($late['due_at'])) {
            return 'running_late';
        }
        if ($resp === 'on_the_way' && (! $c->escalated_at || Carbon::parse($c->escalated_at)->lt(Carbon::parse($c->parent_response_at)))) {
            return 'on_the_way';
        }
        // An alert sent before the running-late time ran out is old news: still not here by then = overdue again.
        if ($c && $c->escalated_at && ! ($late && Carbon::parse($c->escalated_at)->lt($late['due_at']))) {
            return 'escalated';
        }

        return $now->greaterThanOrEqualTo($row['due_at']) ? 'overdue' : 'waiting';
    }
}
