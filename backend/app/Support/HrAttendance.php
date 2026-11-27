<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 1 (2026-11-27): attendance insights. HR add-on only (HrLeave::on); agencies without
 * it are untouched.
 *
 * LATENESS. A clock-in is compared with the person's scheduled shift (shifts, agency wall-clock time). The punch
 * keeps time_punches.late_minutes (minutes after the shift start, 0 = on time or early), late_shift_id and
 * late_checked_at. Only the FIRST clock-in of a shift counts (back from a break or a second punch is not an
 * arrival); automatic punches and, by default, contractors are not compared. The agency's grace window is applied
 * when reading (late = late_minutes > grace), so changing it re-judges every punch. Stamped at clock-in
 * (CareController::punch) and, for punches entered or corrected later, the first time a report reads them.
 * Optional bell to the centre's directors (type hr, kind team = the "Team HR reminders" topic).
 *
 * ABSENCE SCORE. spells x spells x days over a rolling window (the well-known Bradford formula; never branded so in
 * the screen). A spell is one unbroken absence: back-to-back requests separated only by weekends or closed days are
 * one spell. Counts the leave types the agency picks (default: those filed as Sick). Threshold is the agency's.
 *
 * LEAVE CLASH. When someone asks for time off: who else at the same centre is already off or has asked (staff see a
 * count only, approvers the names), and the centre's optional minimum staff (centres.settings.hr_min_staff): working
 * days on which approving would leave fewer people than the minimum. min_mode warn (default) only says so; block
 * refuses the approval (TimeOffController::decide and the email buttons). Leave that needs no approval (sick) is
 * never refused, only flagged.
 *
 * Settings: agencies.settings.hr_attendance (its own key, so HrLeave::saveSettings never overwrites it).
 */
final class HrAttendance
{
    public const DEFAULTS = [
        'lateness' => ['grace_minutes' => 5, 'notify_directors' => false, 'skip_contractors' => true],
        'absence_score' => ['threshold' => 50, 'months' => 12, 'type_ids' => null],   // null = the types filed as Sick
        'clash' => ['warn' => true, 'min_mode' => 'warn'],                            // warn | block
    ];

    public const MIN_MODES = ['warn', 'block'];

    /** A shift "owns" a clock-in from this many minutes before its start until it ends. */
    private const EARLY_WINDOW_MIN = 180;

    public static function settings(int $agencyId): array
    {
        $all = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $raw = is_array($all['hr_attendance'] ?? null) ? $all['hr_attendance'] : [];
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $out[$k] = array_replace($def, array_intersect_key(is_array($raw[$k] ?? null) ? $raw[$k] : [], $def));
        }
        $l = &$out['lateness'];
        $l['grace_minutes'] = max(0, min(120, (int) $l['grace_minutes']));
        $l['notify_directors'] = (bool) $l['notify_directors'];
        $l['skip_contractors'] = (bool) $l['skip_contractors'];
        $a = &$out['absence_score'];
        $a['threshold'] = max(1, min(100000, (int) $a['threshold']));
        $a['months'] = max(1, min(24, (int) $a['months']));
        $a['type_ids'] = is_array($a['type_ids']) && $a['type_ids'] ? array_values(array_map('intval', $a['type_ids'])) : null;
        $c = &$out['clash'];
        $c['warn'] = (bool) $c['warn'];
        $c['min_mode'] = in_array($c['min_mode'], self::MIN_MODES, true) ? $c['min_mode'] : 'warn';
        unset($l, $a, $c);

        return $out;
    }

    /** Merge-write agencies.settings.hr_attendance under a row lock, so another settings save is never lost. */
    public static function saveSettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true);
            $all = is_array($all) ? $all : [];
            $all['hr_attendance'] = $in;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    /** Each open centre's minimum staff, [centre_id => n], only where one is set. */
    public static function minStaff(int $agencyId): array
    {
        $out = [];
        foreach (DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0])->get(['id', 'settings']) as $c) {
            $s = json_decode((string) $c->settings, true);
            $n = is_array($s) ? (int) ($s['hr_min_staff'] ?? 0) : 0;
            if ($n > 0) $out[(int) $c->id] = $n;
        }

        return $out;
    }

    public static function saveMinStaff(int $centreId, ?int $n): void
    {
        DB::transaction(function () use ($centreId, $n) {
            $row = DB::table('centres')->where('id', $centreId)->lockForUpdate()->first(['settings']);
            $s = json_decode((string) ($row->settings ?? ''), true);
            $s = is_array($s) ? $s : [];
            if ($n && $n > 0) $s['hr_min_staff'] = $n; else unset($s['hr_min_staff']);
            DB::table('centres')->where('id', $centreId)->update(['settings' => json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });
    }

    private static function ready(): bool
    {
        static $r = null;

        return $r ??= rescue(fn () => Schema::hasColumn('time_punches', 'late_minutes'), false, false);
    }

    // ── Lateness ──────────────────────────────────────────────────────────────────────

    /**
     * Compare one clock-in with the shift it belongs to and store the answer on the punch. Idempotent.
     *
     * @return array|null ['late' => minutes, 'grace' => m, 'shift_start' => local, 'room' => name, 'agency_id' => id] when
     *                    the punch was compared with a shift; null otherwise.
     */
    public static function stamp(int $punchId): ?array
    {
        if (! self::ready()) return null;
        $p = DB::table('time_punches')->where('id', $punchId)->first(['id', 'user_id', 'centre_id', 'punched_in_at', 'source']);
        if (! $p || ! $p->punched_in_at || ! $p->centre_id) return null;
        $aid = (int) DB::table('centres')->where('id', $p->centre_id)->value('agency_id');
        if (! $aid || ! HrLeave::on($aid)) return null;
        $none = ['late_minutes' => null, 'late_shift_id' => null, 'late_checked_at' => now()];
        $s = self::settings($aid)['lateness'];
        if ($p->source === 'auto' || ($s['skip_contractors'] && HrClock::contractorIds($aid, [(int) $p->user_id]))) {
            DB::table('time_punches')->where('id', $punchId)->update($none);
            return null;
        }
        $tz = AgencyTime::tz($aid);
        $in = Carbon::parse($p->punched_in_at, 'UTC')->setTimezone($tz);
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('id')->all();
        $shift = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')
            ->where('s.user_id', $p->user_id)->whereIn('r.centre_id', $centres ?: [0])
            ->whereNotIn('s.status', ['cancelled', 'canceled', 'declined'])
            ->where('s.starts_at', '<=', $in->copy()->addMinutes(self::EARLY_WINDOW_MIN)->format('Y-m-d H:i:s'))
            ->where('s.ends_at', '>', $in->format('Y-m-d H:i:s'))
            ->orderBy('s.starts_at')->first(['s.id', 's.starts_at', 's.ends_at', 'r.name as room']);
        if (! $shift) {
            DB::table('time_punches')->where('id', $punchId)->update($none);
            return null;
        }
        $start = Carbon::parse($shift->starts_at, $tz);
        // Only the first clock-in of the shift is an arrival: any earlier punch from the shift's window on means this is a return.
        $from = $start->copy()->subMinutes(self::EARLY_WINDOW_MIN)->utc()->format('Y-m-d H:i:s');
        $earlier = DB::table('time_punches')->where('user_id', $p->user_id)->where('id', '!=', $p->id)
            ->where('punched_in_at', '>=', $from)
            ->where(fn ($w) => $w->where('punched_in_at', '<', $p->punched_in_at)->orWhere(fn ($x) => $x->where('punched_in_at', $p->punched_in_at)->where('id', '<', $p->id)))
            ->exists();
        if ($earlier) {
            DB::table('time_punches')->where('id', $punchId)->update($none);
            return null;
        }
        $late = max(0, intdiv($in->getTimestamp() - $start->getTimestamp(), 60));
        DB::table('time_punches')->where('id', $punchId)->update(['late_minutes' => $late, 'late_shift_id' => (int) $shift->id, 'late_checked_at' => now()]);

        return ['late' => $late, 'grace' => (int) $s['grace_minutes'], 'shift_start' => $start->format('g:i A'), 'clocked_in' => $in->format('g:i A'),
            'room' => $shift->room, 'agency_id' => $aid, 'notify' => (bool) $s['notify_directors']];
    }

    /**
     * At clock-in (CareController::punch): stamp the punch and, when the agency asks for it and the person is past the
     * grace window, bell the centre's directors (no director: the agency admins). Never the person, never an outsider
     * (Notify drops them), never throws into the clock-in.
     */
    public static function onClockIn(int $punchId): void
    {
        try {
            $r = self::stamp($punchId);
            if (! $r || ! $r['notify'] || $r['late'] <= $r['grace']) return;
            $p = DB::table('time_punches')->where('id', $punchId)->first(['user_id', 'centre_id']);
            $aid = (int) $r['agency_id'];
            $u = DB::table('users')->where('id', $p->user_id)->first(['first_name', 'preferred_name']);
            $first = trim((string) (($u->preferred_name ?? '') ?: ($u->first_name ?? ''))) ?: 'A team member';
            $mgrs = CentreScope::managers($aid, [(int) $p->centre_id])->where('ra.role', 'centre_director')->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            if (! $mgrs) $mgrs = CentreScope::managers($aid, [])->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            $rows = [];
            foreach (array_diff(array_unique($mgrs), [(int) $p->user_id]) as $m) {
                $rows[] = ['user_id' => $m, 'agency_id' => $aid, 'type' => 'hr',
                    'title' => $first . ' clocked in ' . self::mins($r['late']) . ' late',
                    'body' => 'Shift started at ' . $r['shift_start'] . ($r['room'] ? ' (' . $r['room'] . ')' : '') . '; clocked in at ' . $r['clocked_in'] . '.',
                    'data' => json_encode(['link' => '#hr?tab=absence&view=late', 'kind' => 'team']), 'created_at' => now()];
            }
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function mins(int $m): string
    {
        if ($m < 60) return $m . ' min';
        $h = intdiv($m, 60);
        $r = $m % 60;

        return $h . ' h' . ($r ? ' ' . $r . ' min' : '');
    }

    /** Stamp punches in a UTC range that were never compared, or were corrected since. Bounded; no notices. */
    public static function stampRange(int $agencyId, string $fromUtc, string $toUtc, array $userIds): int
    {
        if (! self::ready()) return 0;
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $ids = DB::table('time_punches')->whereIn('centre_id', $centres ?: [0])->whereIn('user_id', $userIds ?: [0])
            ->where('punched_in_at', '>=', $fromUtc)->where('punched_in_at', '<', $toUtc)
            ->where(fn ($w) => $w->whereNull('late_checked_at')->orWhereColumn('corrected_at', '>', 'late_checked_at'))
            ->orderBy('punched_in_at')->limit(3000)->pluck('id');
        foreach ($ids as $id) self::stamp((int) $id);

        return count($ids);
    }

    /**
     * The lateness report: per person, the clock-ins compared with a shift in [from, to] (agency dates).
     * @param int[] $people
     */
    public static function lateness(int $agencyId, string $from, string $to, array $people, ?int $centreId = null): array
    {
        $s = self::settings($agencyId)['lateness'];
        $grace = (int) $s['grace_minutes'];
        [$a, $b] = AgencyTime::spanRange($agencyId, $from, $to);
        self::stampRange($agencyId, $a, $b, $people);
        $centres = $centreId ? [$centreId] : DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $tz = AgencyTime::tz($agencyId);
        $rows = DB::table('time_punches')->whereIn('centre_id', $centres ?: [0])->whereIn('user_id', $people ?: [0])
            ->where('punched_in_at', '>=', $a)->where('punched_in_at', '<', $b)
            ->whereNotNull('late_shift_id')->whereNotNull('late_minutes')
            ->get(['id', 'user_id', 'centre_id', 'punched_in_at', 'late_minutes', 'late_shift_id']);
        $per = [];
        $days = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];
        foreach ($rows as $r) {
            $u = (int) $r->user_id;
            $per[$u] ??= ['user_id' => $u, 'compared' => 0, 'late' => 0, 'minutes' => 0, 'worst' => 0, 'dow' => array_fill(0, 7, 0), 'centres' => []];
            $per[$u]['compared']++;
            $per[$u]['centres'][(int) $r->centre_id] = true;
            if ((int) $r->late_minutes > $grace) {
                $m = (int) $r->late_minutes;
                $per[$u]['late']++;
                $per[$u]['minutes'] += $m;
                $per[$u]['worst'] = max($per[$u]['worst'], $m);
                $per[$u]['dow'][Carbon::parse($r->punched_in_at, 'UTC')->setTimezone($tz)->dayOfWeekIso - 1]++;
            }
        }
        $out = [];
        foreach ($per as $p) {
            $pattern = '';
            if ($p['late'] >= 3) {
                $top = array_keys($p['dow'], max($p['dow']))[0];
                $n = $p['dow'][$top];
                $pattern = $n * 2 >= $p['late'] && $n >= 2 ? 'Mostly ' . $days[$top] . ' (' . $n . ' of ' . $p['late'] . ')' : 'Spread across the week';
            } elseif ($p['late'] > 0) {
                $pattern = 'Now and then';
            }
            $out[] = ['user_id' => $p['user_id'], 'compared' => $p['compared'], 'late' => $p['late'], 'minutes' => $p['minutes'],
                'average' => $p['late'] ? (int) round($p['minutes'] / $p['late']) : 0, 'worst' => $p['worst'],
                'on_time_pct' => $p['compared'] ? (int) round(100 * ($p['compared'] - $p['late']) / $p['compared']) : null,
                'pattern' => $pattern, 'by_day' => $p['dow'], 'centre_ids' => array_keys($p['centres'])];
        }

        return ['rows' => $out, 'grace' => $grace];
    }

    /** One person's compared clock-ins in [from, to], newest first. */
    public static function latenessDetail(int $agencyId, int $userId, string $from, string $to): array
    {
        $grace = (int) self::settings($agencyId)['lateness']['grace_minutes'];
        [$a, $b] = AgencyTime::spanRange($agencyId, $from, $to);
        self::stampRange($agencyId, $a, $b, [$userId]);
        $tz = AgencyTime::tz($agencyId);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');

        return DB::table('time_punches as p')->leftJoin('shifts as s', 's.id', '=', 'p.late_shift_id')->leftJoin('rooms as r', 'r.id', '=', 's.room_id')
            ->whereIn('p.centre_id', $centres->keys()->all() ?: [0])->where('p.user_id', $userId)
            ->where('p.punched_in_at', '>=', $a)->where('p.punched_in_at', '<', $b)->whereNotNull('p.late_shift_id')->whereNotNull('p.late_minutes')
            ->orderByDesc('p.punched_in_at')->limit(400)
            ->get(['p.id', 'p.centre_id', 'p.punched_in_at', 'p.late_minutes', 's.starts_at', 'r.name as room'])
            ->map(function ($r) use ($tz, $grace, $centres) {
                $in = Carbon::parse($r->punched_in_at, 'UTC')->setTimezone($tz);
                return ['date' => $in->toDateString(), 'weekday' => $in->format('l'), 'shift_start' => $r->starts_at ? Carbon::parse($r->starts_at, $tz)->format('g:i A') : null,
                    'clocked_in' => $in->format('g:i A'), 'late_minutes' => (int) $r->late_minutes, 'late' => (int) $r->late_minutes > $grace,
                    'room' => $r->room, 'centre' => $centres[$r->centre_id] ?? null];
            })->values()->all();
    }

    // ── Absence score ─────────────────────────────────────────────────────────────────

    /** The leave type ids the score counts (the agency's pick, else every type filed as Sick). @return int[] */
    public static function scoreTypeIds(int $agencyId, ?array $settings = null): array
    {
        $s = ($settings ?? self::settings($agencyId))['absence_score'];
        if ($s['type_ids']) {
            return DB::table('hr_leave_types')->where('agency_id', $agencyId)->whereIn('id', $s['type_ids'])->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        return DB::table('hr_leave_types')->where('agency_id', $agencyId)->where('maps_request_type', 'sick')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * spells x spells x days per person, over the months ending on $to.
     * @param int[] $people
     * @return array{window_from:string, window_to:string, threshold:int, months:int, types:array, people:array<int,array>}
     */
    public static function scores(int $agencyId, string $to, array $people): array
    {
        $set = self::settings($agencyId);
        $s = $set['absence_score'];
        $from = Carbon::parse($to)->subMonthsNoOverflow((int) $s['months'])->addDay()->toDateString();
        $typeIds = array_flip(self::scoreTypeIds($agencyId, $set));
        $defaultSick = ! $s['type_ids'];
        $byUser = [];
        foreach (HrLeave::absenceDays($agencyId, $from, $to, $people) as $d) {
            $counts = $d['leave_type_id'] !== null ? isset($typeIds[$d['leave_type_id']]) : ($defaultSick && ($d['request_type'] ?? null) === 'sick');
            if ($counts) $byUser[$d['user_id']][] = $d;
        }
        $out = [];
        foreach ($byUser as $uid => $list) {
            usort($list, fn ($x, $y) => strcmp($x['date'], $y['date']) ?: ($x['request_id'] <=> $y['request_id']));
            $spells = 0;
            $daysOff = 0.0;
            $prev = null;
            $seen = [];
            foreach ($list as $d) {
                if (isset($seen[$d['date']])) continue;   // two requests on one day count that day once
                $seen[$d['date']] = true;
                $daysOff += (float) $d['days'];
                if (! $prev || ! self::continuous($agencyId, (int) $uid, $prev, $d)) $spells++;
                $prev = $d;
            }
            $out[(int) $uid] = ['spells' => $spells, 'days' => round($daysOff, 2), 'score' => (int) round($spells * $spells * $daysOff)];
        }

        return ['window_from' => $from, 'window_to' => $to, 'threshold' => (int) $s['threshold'], 'months' => (int) $s['months'],
            'types' => DB::table('hr_leave_types')->whereIn('id', array_keys($typeIds) ?: [0])->orderBy('sort')->pluck('name')->all(), 'people' => $out];
    }

    /** Does $b carry on the same unbroken absence as $a? Same request, or nothing but weekends / closed days between. */
    private static function continuous(int $agencyId, int $userId, array $a, array $b): bool
    {
        if ($a['request_id'] === $b['request_id']) return true;
        $start = Carbon::parse($a['date'])->addDay();
        $end = Carbon::parse($b['date'])->subDay();
        if ($start->gt($end)) return true;
        if ($start->diffInDays($end) > 10) return false;
        $closed = array_flip(HrLeave::closedDays($agencyId, $userId, $b['centre_id'], $start->toDateString(), $end->toDateString()));
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if (! $d->isWeekend() && ! isset($closed[$d->toDateString()])) return false;
        }

        return true;
    }

    // ── Leave clash + minimum staff ───────────────────────────────────────────────────

    /** The centres a request is judged at: its own, else the file's primary centre, else every open centre they work at. */
    private static function centresFor(int $agencyId, int $userId, ?int $centreId): array
    {
        $open = Live::centreIds($agencyId);
        if ($centreId && in_array($centreId, $open, true)) return [$centreId];
        $f = HrLeave::file($agencyId, $userId);
        if ($f && $f->primary_centre_id && in_array((int) $f->primary_centre_id, $open, true)) return [(int) $f->primary_centre_id];

        return DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->whereIn('centre_id', $open ?: [0])->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->sort()->values()->slice(0, 6)->all();
    }

    /**
     * Who else is off, and the minimum-staff check, for a request (an existing one or the form's dates).
     * $names = the approver's view (names, types, dates); otherwise counts and dates only.
     */
    public static function clash(int $agencyId, int $userId, ?int $centreId, string $start, string $end, bool $allDay, ?int $exceptRequestId = null, bool $names = false): array
    {
        $set = self::settings($agencyId)['clash'];
        $mins = self::minStaff($agencyId);
        $centreNames = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        $out = ['centres' => [], 'others' => 0, 'short' => false, 'min_mode' => $set['min_mode'], 'warn' => $set['warn']];
        if ($end < $start) return $out;
        $mine = $allDay ? HrLeave::workDays($agencyId, $userId, $centreId, $start, $end) : [$start];
        foreach (self::centresFor($agencyId, $userId, $centreId) as $cid) {
            $staff = HrLeave::staffIds($agencyId, [$cid]);
            $colleagues = array_values(array_diff($staff, [$userId]));
            $reqs = DB::table('time_off_requests')->where('agency_id', $agencyId)->whereIn('user_id', $colleagues ?: [0])
                ->whereIn('status', ['approved', 'pending'])->whereDate('start_at', '<=', $end)->whereDate('end_at', '>=', $start)
                ->when($exceptRequestId, fn ($q) => $q->where('id', '!=', $exceptRequestId))->orderBy('start_at')->get();
            $users = DB::table('users')->whereIn('id', $reqs->pluck('user_id')->all() ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $others = [];
            $offOn = [];   // date => [uid => true] approved, all-day, on a working day of theirs
            foreach ($reqs as $r) {
                $rs = substr((string) $r->start_at, 0, 10);
                $re = substr((string) $r->end_at, 0, 10);
                $rAll = (bool) $r->all_day || empty($r->start_time);
                $theirs = $rAll ? HrLeave::workDays($agencyId, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null, $rs, $re) : [$rs];
                $overlap = array_values(array_intersect($theirs, $mine));
                if (! $overlap) continue;
                if ($r->status === 'approved' && $rAll) foreach ($overlap as $d) $offOn[$d][(int) $r->user_id] = true;
                $t = HrLeave::typeForRequest($r);
                $u = $users[$r->user_id] ?? null;
                $others[] = ['user_id' => (int) $r->user_id, 'name' => $u ? trim($u->first_name . ' ' . $u->last_name) : '',
                    'status' => $r->status, 'type' => $t->name ?? (HrLeave::REQUEST_TYPES[$r->request_type] ?? ucfirst((string) $r->request_type)),
                    'when' => \App\Http\Controllers\Api\TimeOffController::whenPhrase($r), 'days' => count($overlap), 'part_day' => ! $rAll];
            }
            $min = $mins[$cid] ?? null;
            $short = [];
            if ($min && $allDay) {
                $total = count(array_unique(array_merge($staff, [$userId])));
                foreach ($mine as $d) {
                    $on = $total - count($offOn[$d] ?? []) - 1;   // the requester is off too
                    if ($on < $min) $short[] = ['date' => $d, 'working' => max(0, $on), 'min' => $min];
                }
            }
            $people = count(array_unique(array_column($others, 'user_id')));
            $out['others'] += $people;
            $out['short'] = $out['short'] || (bool) $short;
            $c = ['centre_id' => $cid, 'centre_name' => $centreNames[$cid] ?? null, 'others_count' => $people, 'min_staff' => $min,
                'staff_total' => count(array_unique(array_merge($staff, [$userId]))), 'short_days' => $short];
            if ($names) $c['others'] = $others;
            $out['centres'][] = $c;
        }

        return $out;
    }

    /** The clash for an existing request (an approver's view). */
    public static function forRequest(object $r, bool $names = true): array
    {
        return self::clash((int) $r->agency_id, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null,
            substr((string) $r->start_at, 0, 10), substr((string) $r->end_at, 0, 10), (bool) $r->all_day || empty($r->start_time), (int) $r->id, $names);
    }

    /**
     * BLOCK MODE: the refusal for approving a request that would take a centre below its minimum staff, or null.
     * Only pending requests being approved by a person; leave with no approval step is never refused.
     */
    public static function minStaffRefusal(object $r): ?string
    {
        try {
            if (! HrLeave::on((int) $r->agency_id) || self::settings((int) $r->agency_id)['clash']['min_mode'] !== 'block') return null;
            $c = self::forRequest($r, false);
            foreach ($c['centres'] as $x) {
                if (! $x['short_days']) continue;
                $d = $x['short_days'][0];
                $n = count($x['short_days']);

                return 'Approving this leaves ' . ($x['centre_name'] ?: 'the centre') . ' with ' . $d['working'] . ' staff on ' . Carbon::parse($d['date'])->format('M j')
                    . ($n > 1 ? ' and ' . ($n - 1) . ' more day' . ($n > 2 ? 's' : '') : '') . ' (minimum ' . $d['min'] . '). Your agency\'s rule blocks it: arrange cover, change the minimum under HR > Settings, or decline.';
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return null;
    }

    /** The approver email line: "Also off at Maple: Sofia (approved), Noah (asked). Below the minimum staff on 1 day." First names only. */
    public static function emailLine(object $r): ?string
    {
        try {
            if (! HrLeave::on((int) $r->agency_id)) return null;
            $c = self::forRequest($r, true);
            if (! $c['warn'] && ! $c['short']) return null;
            $bits = [];
            foreach ($c['centres'] as $x) {
                $who = [];
                foreach ($x['others'] ?? [] as $o) {
                    $first = trim(explode(' ', $o['name'])[0] ?? '');
                    if ($first) $who[$first . ' (' . ($o['status'] === 'approved' ? 'off' : 'asked') . ')'] = true;
                }
                if ($who && $c['warn']) $bits[] = 'Also away at ' . ($x['centre_name'] ?: 'the centre') . ': ' . implode(', ', array_keys($who)) . '.';
                if ($x['short_days']) {
                    $n = count($x['short_days']);
                    $bits[] = 'Approving leaves ' . ($x['centre_name'] ?: 'the centre') . ' below its minimum of ' . $x['min_staff'] . ' staff on ' . $n . ' day' . ($n > 1 ? 's' : '') . '.';
                }
            }

            return $bits ? implode(' ', $bits) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
