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
        $out['default_time'] = preg_match('/^\d{2}:\d{2}$/', (string) $out['default_time']) ? $out['default_time'] : '09:00';

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
            ->where('ce.agency_id', $agencyId)
            ->whereNull('c.deleted_at')
            ->where('c.enrollment_status', 'enrolled')
            ->where(function ($q) use ($date) { $q->whereNull('e.end_date')->orWhere('e.end_date', '>=', $date); })
            ->where(function ($q) use ($date) { $q->whereNull('e.start_date')->orWhere('e.start_date', '<=', $date); })
            ->when($centreIds !== null, fn ($q) => $q->whereIn('ce.id', $centreIds ?: [0]))
            ->when($roomIds !== null, fn ($q) => $q->whereIn('r.id', $roomIds ?: [0]))
            ->orderBy('c.first_name')
            ->get(['c.id as child_id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'c.family_id',
                'c.expected_dropoff_time', 'e.schedule', 'r.id as room_id', 'r.name as room_name',
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
                'arrived_at' => null, 'absent' => null, 'check' => null,
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

        foreach ($out as &$row) {
            $row['state'] = self::state($row, $now);
        }

        return $out;
    }

    /**
     * arrived | absent | resolved | escalated | overdue | waiting
     * (waiting = not due yet; overdue = due, parents asked or about to be.)
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
        if ($c && $c->escalated_at) {
            return 'escalated';
        }

        return $now->greaterThanOrEqualTo($row['due_at']) ? 'overdue' : 'waiting';
    }
}
