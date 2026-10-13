<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OCCMS MONTHLY REPORTING — the fee subsidy Record of Attendance (ROA) and the CWELCC
 * monthly summary (2026-10-13).
 *
 * Ontario operators contracted for fee subsidy complete a Record of Attendance in OCCMS
 * (the Ontario Child Care Management System, run by the CMSMs/DSSABs) every month: for each
 * approved child, a code per day, plus vacancies and licensed vs operating capacity by age
 * group — "no later than the eighth day of the month following the month in which care was
 * provided" (York Region Child Care Fee Subsidy handbook). OCCMS has no public API, so this
 * is the working copy staff fill OCCMS from, and the record of what was entered and when.
 *
 * DERIVED, NOT MATERIALISED (the same rule as App\Support\Closures): a day's code is worked
 * out at the point of asking from check-ins (P), reported absences (S/V/A), closures (H for a
 * holiday closure, N for any other closure), withdrawal (W) and the child's schedule. Only a
 * cell somebody changed by hand is stored (roa_cells). From sign-off the grid is frozen into
 * roa_months.snapshot so a late attendance edit can never change what was signed and sent.
 *
 * Codes are the OCCMS legend printed in York's handbook (P S A V W H N O; E is information
 * only). Every CMSM uses OCCMS, but only York's handbook was read: the absence allowance is
 * per municipality (PRESETS) and editable.
 */
final class OccmsRoa
{
    /** Fee subsidy rows. 'provincial' is what the Subsidies screen writes; 'municipal' is the other OCCMS kind. */
    public const SUBSIDY_TYPES = ['provincial', 'municipal'];

    public const CODES = [
        'P' => 'Present',
        'S' => 'Sick',
        'A' => 'Absent',
        'V' => 'Vacation',
        'W' => 'Withdrawal (last day attended)',
        'H' => 'Statutory holiday',
        'N' => 'Non-paid day (centre closure)',
        'O' => 'Other (contact your CMSM first)',
    ];

    /** Sick, absent and vacation days count against the child's absence allowance (York handbook). */
    public const ABSENCE_CODES = ['A', 'S', 'V'];

    public const AGE_GROUPS = [
        'infant' => 'Infant', 'toddler' => 'Toddler', 'preschool' => 'Preschool',
        'kindergarten' => 'Kindergarten', 'school_age' => 'School age',
    ];

    /**
     * Municipality presets. allowance = absence days per calendar year (null = not known —
     * enter yours); prorate_step = days fewer for each month after January the child starts;
     * max_consecutive = most absence days in a row (null = no limit known);
     * unexplained_run = consecutive unexplained absences that must be reported.
     */
    public const PRESETS = [
        'york' => ['label' => 'York Region', 'allowance' => 48, 'prorate_step' => 3, 'max_consecutive' => null, 'unexplained_run' => 5, 'verified' => true,
            'note' => 'York Region fee subsidy handbook: 48 sick, absent and vacation days a calendar year, prorated by the month the child starts (February 45 … December 15); unused days do not carry over. Tell the CSR when a child is absent 5 days in a row without notice. ROA due by the 8th.'],
        'toronto' => ['label' => 'City of Toronto', 'allowance' => 35, 'prorate_step' => 0, 'max_consecutive' => 20, 'unexplained_run' => 5, 'verified' => false,
            'note' => 'Toronto: 35 absent days a year, at most 20 in a row. Proration for a mid-year start is not confirmed; set it from your Toronto service agreement.'],
        'peel' => ['label' => 'Region of Peel', 'allowance' => null, 'prorate_step' => 0, 'max_consecutive' => null, 'unexplained_run' => 5, 'verified' => false,
            'note' => 'Peel: the absent day allowance is in Peel\'s Service Provider Handbook (not public). Enter it below. A family past its allowance pays as a full-fee family (2026 CWELCC guideline 4.7).'],
        'other' => ['label' => 'Other / not listed', 'allowance' => 48, 'prorate_step' => 3, 'max_consecutive' => null, 'unexplained_run' => 5, 'verified' => false,
            'note' => 'Uses the York Region codes and allowance until you set your own. Check your CMSM/DSSAB\'s fee subsidy handbook.'],
    ];

    public const DEFAULT_PRESET = 'other';
    public const DEFAULT_DUE_DAY = 8;

    /** Is this agency one OCCMS reporting applies to (Ontario)? */
    public static function applies(int $agencyId): bool
    {
        return Jurisdictions::codeFor($agencyId) === 'CA-ON';
    }

    /** The agency's settings: the preset with any overrides the agency saved. */
    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $o = is_array($s['occms_roa'] ?? null) ? $s['occms_roa'] : [];
        $preset = isset(self::PRESETS[$o['preset'] ?? '']) ? $o['preset'] : self::DEFAULT_PRESET;
        $p = self::PRESETS[$preset];
        $out = ['preset' => $preset, 'label' => $p['label'], 'note' => $p['note'], 'verified' => $p['verified']];
        foreach (['allowance', 'prorate_step', 'max_consecutive', 'unexplained_run'] as $k) {
            $out[$k] = array_key_exists($k, $o) ? ($o[$k] === null ? null : (int) $o[$k]) : $p[$k];
        }
        $out['unexplained_run'] = max(2, (int) ($out['unexplained_run'] ?? 5));
        $out['due_day'] = min(28, max(1, (int) ($o['due_day'] ?? self::DEFAULT_DUE_DAY)));
        $out['reminders'] = ($o['reminders'] ?? true) !== false;
        $out['customised'] = (bool) array_intersect(array_keys($o), ['allowance', 'prorate_step', 'max_consecutive', 'unexplained_run']);

        return $out;
    }

    /** "2026-10" → the OCCMS due date for that month's ROA (the due day of the following month). */
    public static function dueOn(string $month, int $dueDay): string
    {
        $d = Carbon::createFromFormat('Y-m-d', $month . '-01')->addMonthNoOverflow()->startOfMonth();

        return $d->day(min($dueDay, $d->daysInMonth))->toDateString();
    }

    /** Absence days allowed for the calendar year, given the month the child's subsidy started that year. */
    public static function allowanceFor(array $set, int $startMonth): ?int
    {
        if ($set['allowance'] === null) return null;

        return max(0, (int) $set['allowance'] - max(0, (int) $set['prorate_step']) * (max(1, $startMonth) - 1));
    }

    /** Age group from date of birth, when the child has no room (York table; kindergarten is a placement, not an age). */
    public static function ageGroupFromDob(?string $dob, string $onDate): ?string
    {
        if (! $dob) return null;
        $m = Carbon::parse($dob)->diffInMonths(Carbon::parse($onDate));
        if ($m < 18) return 'infant';
        if ($m < 30) return 'toddler';
        if ($m < 72) return 'preschool';

        return 'school_age';
    }

    /**
     * CWELCC eligibility end date (O. Reg. 137/15 s.1 as applied in Peel's 2026 CWELCC guideline,
     * tables 2 and 3): under 6 is eligible; a child who turns 6 between January 1 and June 30 and is in
     * a preschool, kindergarten or family age group stays eligible until June 30; one who turns 6
     * later, until the end of their birth month; in a school-age (primary/junior) room, until the end
     * of the birth month. Returns the last eligible day, or null when the date of birth is unknown.
     */
    public static function cwelccUntil(?string $dob, ?string $ageGroup): ?string
    {
        if (! $dob) return null;
        $six = Carbon::parse($dob)->addYears(6);
        if ($ageGroup !== 'school_age' && $six->month <= 6) {
            return $six->copy()->month(6)->day(30)->toDateString();
        }

        return $six->copy()->endOfMonth()->toDateString();
    }

    /**
     * The month's grid for one centre.
     *
     * @return array{children: array, days: array, capacity: array, roa: ?object, live_diff: int, settings: array}
     */
    public static function build(int $agencyId, int $centreId, string $month): array
    {
        $set = self::settings($agencyId);
        $tz = AgencyTime::tz($agencyId);
        $today = AgencyTime::today($agencyId);
        $mStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $mEnd = $mStart->copy()->endOfMonth();
        $ms = $mStart->toDateString();
        $me = $mEnd->toDateString();
        $yearStart = $mStart->format('Y') . '-01-01';

        $roa = DB::table('roa_months')->where('centre_id', $centreId)->where('month', $month)->first();
        $closures = Closures::map([$centreId], $ms, $me)[$centreId] ?? [];

        // The days of the month, with what the centre itself was doing.
        $opByDow = [];
        $days = [];
        for ($d = $mStart->copy(); $d->lte($mEnd); $d->addDay()) {
            $date = $d->toDateString();
            $dow = $d->isoWeekday();
            if (! array_key_exists($dow, $opByDow)) $opByDow[$dow] = Closures::isOperatingDay($centreId, $date);
            $cl = $closures[$date] ?? null;
            $days[] = ['date' => $date, 'd' => (int) $d->day, 'dow' => substr($d->format('D'), 0, 2), 'operating' => $opByDow[$dow],
                'closure' => $cl ? ['code' => ($cl->closure_type ?? '') === 'holiday' ? 'H' : 'N', 'reason' => Closures::reason($cl)] : null,
                'future' => $date > $today];
        }

        $live = self::liveChildren($agencyId, $centreId, $month, $set, $today);
        $snap = ($roa && $roa->snapshot && in_array($roa->status, ['signed_off', 'submitted'], true)) ? json_decode((string) $roa->snapshot, true) : null;

        $liveDiff = 0;
        if (is_array($snap) && isset($snap['children'])) {
            // Frozen: show what was signed. Count the cells live data now disagrees with.
            $byId = [];
            foreach ($live as $c) $byId[$c['child_id']] = $c;
            foreach ($snap['children'] as $c) {
                $l = $byId[$c['child_id']] ?? null;
                foreach ($c['codes'] as $i => $code) {
                    if (($l['codes'][$i] ?? null) !== $code) $liveDiff++;
                }
            }
            $children = $snap['children'];
            $capacity = $snap['capacity'] ?? self::capacity($centreId, $month, $roa, $children, $today);
        } else {
            $children = $live;
            $capacity = self::capacity($centreId, $month, $roa, $children, $today);
        }

        return ['children' => $children, 'days' => $days, 'capacity' => $capacity, 'roa' => $roa, 'live_diff' => $liveDiff,
            'settings' => $set, 'frozen' => is_array($snap), 'tz' => $tz, 'today' => $today];
    }

    /** Every subsidized child of the centre in the month, with codes worked out from today's data. */
    public static function liveChildren(int $agencyId, int $centreId, string $month, array $set, string $today): array
    {
        $mStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $mEnd = $mStart->copy()->endOfMonth();
        $ms = $mStart->toDateString();
        $me = $mEnd->toDateString();
        $year = (int) $mStart->format('Y');
        $yearStart = $year . '-01-01';

        // The subsidies granted under this centre that cover any day of the month.
        $subs = DB::table('subsidies as s')
            ->join('children as ch', 'ch.id', '=', 's.child_id')
            ->join('families as f', 'f.id', '=', DB::raw('COALESCE(s.family_id, ch.family_id)'))
            ->join('centres as c', 'c.id', '=', DB::raw('COALESCE(s.centre_id, f.centre_id)'))
            ->where('c.agency_id', $agencyId)->where('c.id', $centreId)
            ->whereIn('s.type', self::SUBSIDY_TYPES)->where('s.active', 1)
            ->where('s.valid_from', '<=', $me)
            ->where(fn ($w) => $w->whereNull('s.valid_to')->orWhere('s.valid_to', '>=', $ms))
            // An archived child stays on the months they were here; not on later ones.
            ->where(fn ($w) => $w->whereNull('ch.deleted_at')->orWhere('ch.deleted_at', '>=', $ms . ' 00:00:00'))
            ->orderByDesc('s.valid_from')
            ->get(['s.child_id', 's.case_number', 'ch.first_name', 'ch.last_name', 'ch.preferred_name', 'ch.date_of_birth', 'ch.withdrawn_at', 'ch.enrollment_status']);
        if ($subs->isEmpty()) return [];
        $ids = $subs->pluck('child_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

        // Every fee subsidy period these children had this year (for the year-to-date allowance).
        $periods = [];
        foreach (DB::table('subsidies')->whereIn('child_id', $ids)->whereIn('type', self::SUBSIDY_TYPES)->where('active', 1)
            ->where('valid_from', '<=', $me)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $yearStart))
            ->get(['child_id', 'valid_from', 'valid_to']) as $p) {
            $periods[(int) $p->child_id][] = [max($yearStart, (string) $p->valid_from), min($me, (string) ($p->valid_to ?: $me))];
        }

        // Check-ins, in the agency's own days.
        $tz = AgencyTime::tz($agencyId);
        [$fromUtc, $toUtc] = AgencyTime::spanRange($agencyId, $yearStart, $me);
        $present = [];
        foreach (DB::table('check_events')->whereIn('child_id', $ids)->where('event_type', 'check_in')
            ->where('occurred_at', '>=', $fromUtc)->where('occurred_at', '<', $toUtc)->get(['child_id', 'occurred_at']) as $e) {
            $present[(int) $e->child_id][Carbon::parse($e->occurred_at, 'UTC')->setTimezone($tz)->toDateString()] = true;
        }
        $absent = [];
        foreach (DB::table('child_absences')->whereIn('child_id', $ids)->whereBetween('absent_on', [$yearStart, $me])->get(['child_id', 'absent_on', 'reason']) as $a) {
            $absent[(int) $a->child_id][substr((string) $a->absent_on, 0, 10)] = (string) ($a->reason ?? '');
        }
        $enrols = [];
        foreach (DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')->whereIn('e.child_id', $ids)
            ->where('e.start_date', '<=', $me)->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', $yearStart))
            ->orderByRaw('r.centre_id = ? desc', [$centreId])->orderByDesc('e.start_date')
            ->get(['e.child_id', 'e.start_date', 'e.end_date', 'e.schedule', 'r.centre_id', 'r.age_group', 'r.name as room_name']) as $e) {
            $e->days = CareSchedule::daysOf($e->schedule);
            $enrols[(int) $e->child_id][] = $e;
        }
        $edits = [];
        foreach (DB::table('roa_cells')->where('centre_id', $centreId)->whereIn('child_id', $ids)->whereBetween('day', [$yearStart, $me])
            ->get(['child_id', 'day', 'code']) as $c) {
            $edits[(int) $c->child_id][substr((string) $c->day, 0, 10)] = (string) $c->code;
        }
        // Earlier months of the year that were signed off: what was signed is what counts.
        $frozen = [];
        foreach (DB::table('roa_months')->where('centre_id', $centreId)->where('month', '>=', $year . '-01')->where('month', '<', $month)
            ->whereIn('status', ['signed_off', 'submitted'])->whereNotNull('snapshot')->get(['month', 'snapshot']) as $r) {
            $sn = json_decode((string) $r->snapshot, true);
            foreach ((array) ($sn['children'] ?? []) as $c) {
                $base = Carbon::createFromFormat('Y-m-d', $r->month . '-01');
                foreach ((array) ($c['codes'] ?? []) as $i => $code) {
                    $frozen[(int) $c['child_id']][$base->copy()->addDays((int) $i)->toDateString()] = $code;
                }
            }
        }
        $closures = Closures::map([$centreId], $yearStart, $me)[$centreId] ?? [];
        $opByDow = [];
        $dayKeys = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

        $out = [];
        $seen = [];
        foreach ($subs as $s) {
            $cid = (int) $s->child_id;
            if (isset($seen[$cid])) continue;   // newest subsidy row first: its case number
            $seen[$cid] = true;
            $mine = $periods[$cid] ?? [];
            $inSub = function (string $date) use ($mine): bool {
                foreach ($mine as [$a, $b]) if ($date >= $a && $date <= $b) return true;
                return false;
            };
            $withdrawn = $s->withdrawn_at ? substr((string) $s->withdrawn_at, 0, 10) : null;
            $ce = $enrols[$cid] ?? [];

            $codes = [];   // date => [code|null, source]
            for ($d = Carbon::parse($yearStart); $d->lte($mEnd); $d->addDay()) {
                $date = $d->toDateString();
                if (isset($frozen[$cid]) && array_key_exists($date, $frozen[$cid])) { $codes[$date] = [$frozen[$cid][$date], 'signed']; continue; }
                if ($date > $today || ! $inSub($date) || ($withdrawn && $date > $withdrawn)) { $codes[$date] = [null, '']; continue; }
                $c = null; $src = '';
                $dow = $d->isoWeekday();
                if (! array_key_exists($dow, $opByDow)) $opByDow[$dow] = Closures::isOperatingDay($centreId, $date);
                $scheduled = ! $ce;   // no enrolment on file: every operating day
                foreach ($ce as $e) {
                    if ((string) $e->start_date > $date || ($e->end_date && (string) $e->end_date < $date)) continue;
                    if (in_array($dayKeys[$dow], $e->days, true)) { $scheduled = true; break; }
                }
                if ($withdrawn && $date === $withdrawn) { $c = 'W'; $src = 'withdrawal'; }
                elseif (isset($present[$cid][$date])) { $c = 'P'; $src = 'present'; }
                // A weekend or a day the child is not booked is no care day, closure or not.
                elseif (! $opByDow[$dow] || ! $scheduled) { $c = null; $src = 'off'; }
                elseif (isset($closures[$date])) { $c = ($closures[$date]->closure_type ?? '') === 'holiday' ? 'H' : 'N'; $src = 'closure'; }
                elseif (array_key_exists($date, $absent[$cid] ?? [])) {
                    $r = $absent[$cid][$date];
                    $c = $r === 'sick' ? 'S' : ($r === 'holiday' ? 'V' : 'A');
                    $src = 'reported';
                } else { $c = 'A'; $src = 'unexplained'; }
                if (isset($edits[$cid]) && array_key_exists($date, $edits[$cid])) {
                    $c = $edits[$cid][$date] === '' ? null : $edits[$cid][$date];
                    $src = 'edited';
                }
                $codes[$date] = [$c, $src];
            }

            // This month's row.
            $row = [];
            $srcs = [];
            $sub = [];   // was the child subsidized here that day (and not yet withdrawn)?
            $tot = array_fill_keys(array_keys(self::CODES), 0);
            for ($d = $mStart->copy(); $d->lte($mEnd); $d->addDay()) {
                $date = $d->toDateString();
                [$c, $src] = $codes[$date] ?? [null, ''];
                $row[] = $c;
                $srcs[] = $src;
                $sub[] = $inSub($date) && ! ($withdrawn && $date > $withdrawn);
                if ($c !== null && isset($tot[$c])) $tot[$c]++;
            }
            $ytd = 0;
            foreach ($codes as [$c]) if (in_array($c, self::ABSENCE_CODES, true)) $ytd++;
            $first = null;
            foreach ($mine as [$a]) $first = $first === null ? $a : min($first, $a);
            $startMonth = $first ? (int) substr($first, 5, 2) : 1;
            $allow = self::allowanceFor($set, $startMonth);

            // Runs: unexplained absences in a row (reportable), and absences in a row over the cap.
            $runs = [];
            $unexp = []; $abs = [];
            $flush = function (array &$run, string $type, int $min) use (&$runs, $ms) {
                if (count($run) >= $min && end($run) >= $ms) $runs[] = ['type' => $type, 'from' => $run[0], 'to' => end($run), 'days' => count($run)];
                $run = [];
            };
            foreach ($codes as $date => [$c, $src]) {
                if ($c === null || $c === 'H' || $c === 'N') continue;   // not a care day: neither extends nor breaks a run
                // Unexplained: worked out as absent with nothing reported. A code staff set by hand is theirs to explain.
                $isUnexp = $c === 'A' && ($src === 'unexplained' || ($src === 'signed' && ! array_key_exists($date, $absent[$cid] ?? [])));
                if ($isUnexp) $unexp[] = $date; else $flush($unexp, 'unexplained', (int) $set['unexplained_run']);
                if (in_array($c, self::ABSENCE_CODES, true)) $abs[] = $date;
                elseif ($set['max_consecutive']) $flush($abs, 'consecutive', (int) $set['max_consecutive'] + 1);
                else $abs = [];
            }
            $flush($unexp, 'unexplained', (int) $set['unexplained_run']);
            if ($set['max_consecutive']) $flush($abs, 'consecutive', (int) $set['max_consecutive'] + 1);

            // Age group: the room this centre has them in during the month, else from date of birth.
            $group = null; $room = null;
            foreach ($ce as $e) {
                if ((int) $e->centre_id === $centreId && (string) $e->start_date <= $me && (! $e->end_date || (string) $e->end_date >= $ms)) { $group = $e->age_group; $room = $e->room_name; break; }
            }
            $group = $group ?: self::ageGroupFromDob($s->date_of_birth, min($me, $today));

            $remaining = $allow === null ? null : $allow - $ytd;
            $out[] = [
                'child_id' => $cid,
                'name' => trim($s->last_name . ', ' . ($s->first_name ?: '')),
                'case_number' => $s->case_number,
                'dob' => $s->date_of_birth ? substr((string) $s->date_of_birth, 0, 10) : null,
                'age_group' => $group,
                'room' => $room,
                'withdrawn_on' => $withdrawn && $withdrawn <= $me ? $withdrawn : null,
                'codes' => $row,
                'src' => $srcs,
                'sub' => $sub,
                'totals' => $tot,
                'absences_month' => $tot['A'] + $tot['S'] + $tot['V'],
                'ytd_used' => $ytd,
                'allowance' => $allow,
                'remaining' => $remaining,
                'warn' => $allow === null ? null : ($ytd >= $allow ? 'over' : ($allow > 0 && $ytd >= 0.8 * $allow ? 'near' : null)),
                'runs' => $runs,
            ];
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    /** Licensed and operating capacity, enrolment and vacancies by age group. */
    public static function capacity(int $centreId, string $month, ?object $roa, array $children, string $today): array
    {
        $mEnd = Carbon::createFromFormat('Y-m-d', $month . '-01')->endOfMonth()->toDateString();
        $ref = min($mEnd, $today);
        $licensed = DB::table('rooms')->where('centre_id', $centreId)->where('active', 1)
            ->selectRaw('age_group, SUM(capacity) as cap')->groupBy('age_group')->pluck('cap', 'age_group')->all();
        $op = $roa && $roa->capacity ? (json_decode((string) $roa->capacity, true) ?: []) : [];
        if (! $op) {   // carried forward from the latest earlier month that had it
            $prev = DB::table('roa_months')->where('centre_id', $centreId)->where('month', '<', $month)->whereNotNull('capacity')->orderByDesc('month')->value('capacity');
            $op = $prev ? (json_decode((string) $prev, true) ?: []) : [];
        }
        $enrolled = self::enrolledByGroup($centreId, $ref);
        $subsidized = [];
        foreach ($children as $c) if (! empty($c['age_group'])) $subsidized[$c['age_group']] = ($subsidized[$c['age_group']] ?? 0) + 1;

        $rows = [];
        foreach (self::AGE_GROUPS as $g => $label) {
            $lic = (int) ($licensed[$g] ?? 0);
            $en = count($enrolled[$g] ?? []);
            if (! $lic && ! $en && ! isset($op[$g]) && empty($subsidized[$g])) continue;
            $oc = isset($op[$g]) ? (int) $op[$g] : $lic;
            $rows[] = ['group' => $g, 'label' => $label, 'licensed' => $lic, 'operating' => $oc, 'operating_set' => isset($op[$g]),
                'enrolled' => $en, 'subsidized' => (int) ($subsidized[$g] ?? 0), 'vacancies' => max(0, $oc - $en)];
        }

        return ['as_of' => $ref, 'rows' => $rows];
    }

    /** Children enrolled in this centre's rooms on a day, by the room's age group: [group => [child rows]]. */
    public static function enrolledByGroup(int $centreId, string $onDate): array
    {
        $rows = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->where('r.centre_id', $centreId)
            ->where('e.start_date', '<=', $onDate)->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', $onDate))
            ->where(fn ($w) => $w->whereNull('ch.deleted_at')->orWhere('ch.deleted_at', '>', $onDate . ' 23:59:59'))
            ->where(fn ($w) => $w->whereNull('ch.withdrawn_at')->orWhere('ch.withdrawn_at', '>=', $onDate))
            ->where('ch.enrollment_status', '!=', 'waitlist')
            ->orderBy('e.start_date')
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth', 'ch.family_id', 'r.age_group', 'e.cwelcc_eligible']);
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[(int) $r->id])) continue;   // counted once, in their first room
            $seen[(int) $r->id] = true;
            $out[(string) $r->age_group][] = $r;
        }

        return $out;
    }

    /** The CWELCC monthly summary for a centre: enrolment by age group, eligible or not, and capacity. */
    public static function cwelcc(int $agencyId, int $centreId, string $month): array
    {
        $today = AgencyTime::today($agencyId);
        $mStart = Carbon::createFromFormat('Y-m-d', $month . '-01');
        $ms = $mStart->toDateString();
        $me = $mStart->copy()->endOfMonth()->toDateString();
        $ref = min($me, $today);
        $centre = DB::table('centres')->where('id', $centreId)->first(['id', 'name', 'cwelcc_enrolled', 'license_number', 'license_capacity']);
        $enrolled = self::enrolledByGroup($centreId, $ref);
        $famIds = [];
        foreach ($enrolled as $list) foreach ($list as $r) $famIds[] = (int) $r->family_id;
        $famCwelcc = DB::table('cwelcc_enrolments')->whereIn('family_id', $famIds ?: [0])
            ->where('enrolled_from', '<=', $me)->where(fn ($w) => $w->whereNull('enrolled_to')->orWhere('enrolled_to', '>=', $ms))
            ->pluck('family_id')->map(fn ($v) => (int) $v)->flip()->all();
        $soon = Carbon::parse($today)->addMonthsNoOverflow(2)->endOfMonth()->toDateString();

        $rows = [];
        $ageing = [];
        $mismatch = [];
        $tot = ['enrolled' => 0, 'eligible' => 0, 'not_eligible' => 0, 'unknown' => 0, 'family_enrolled' => 0];
        foreach (self::AGE_GROUPS as $g => $label) {
            $list = $enrolled[$g] ?? [];
            $r = ['group' => $g, 'label' => $label, 'enrolled' => count($list), 'eligible' => 0, 'not_eligible' => 0, 'unknown' => 0, 'family_enrolled' => 0];
            foreach ($list as $c) {
                $name = trim($c->first_name . ' ' . $c->last_name);
                $dob = $c->date_of_birth ? substr((string) $c->date_of_birth, 0, 10) : null;
                $until = self::cwelccUntil($dob, $g);
                $fam = isset($famCwelcc[(int) $c->family_id]);
                if ($fam) $r['family_enrolled']++;
                if ($until === null) { $r['unknown']++; continue; }
                $ok = $centre && $centre->cwelcc_enrolled && $until >= $me && (int) $c->cwelcc_eligible !== 0;
                $ok ? $r['eligible']++ : $r['not_eligible']++;
                if ($until >= $today && $until <= $soon) $ageing[] = ['name' => $name, 'group' => $label, 'until' => $until];
                if ($ok && ! $fam) $mismatch[] = ['name' => $name, 'group' => $label, 'issue' => 'Age-eligible, but the family has no CWELCC enrolment for this month'];
                if (! $ok && $fam && $until < $me) $mismatch[] = ['name' => $name, 'group' => $label, 'issue' => 'Family is enrolled in CWELCC, but the child aged out on ' . $until];
            }
            if (! $r['enrolled']) continue;
            foreach ($tot as $k => $v) $tot[$k] += $r[$k];
            $rows[] = $r;
        }
        usort($ageing, fn ($a, $b) => strcmp($a['until'], $b['until']));
        $roa = DB::table('roa_months')->where('centre_id', $centreId)->where('month', $month)->first();

        return ['as_of' => $ref, 'centre_cwelcc' => (bool) ($centre->cwelcc_enrolled ?? false), 'rows' => $rows, 'totals' => $tot,
            'capacity' => self::capacity($centreId, $month, $roa, [], $today), 'ageing_out' => $ageing, 'check' => $mismatch,
            'license_capacity' => (int) ($centre->license_capacity ?? 0)];
    }
}
