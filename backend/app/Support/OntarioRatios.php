<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * ONTARIO CENTRE RATIOS — O. Reg. 137/15 ss. 8, 8.1, 54 and Schedules 1 and 4 (2026-10-13).
 *
 * Read from the e-Laws consolidation (Word download, last amended by O. Reg. 197/26) and the
 * Child Care Centre Licensing Manual, Part 3. RatioRules::evaluate() hands a room here only
 * when the agency is CA-ON AND the location is a licensed child care centre
 * (centres.license_type = 'on_centre'). Home child care (iLearn's providers) and every other
 * jurisdiction never reach this file, so their numbers are exactly what they were.
 *
 * What it decides, per room (= one licensed age group):
 *  - the group's age category (rooms.licensed_age_group, else rooms.age_group), Schedule 1
 *    ratio and maximum group size;
 *  - s.8(3) mixed-age grouping: younger children within 20 % (toddler, preschool) or 25 %
 *    (kindergarten, primary/junior, junior) of the licensed capacity keep the group's own
 *    rules; over that, the youngest child's category applies (ratio, group size, qualified);
 *  - s.8(4) reduced ratios, only inside a configured arrival / departure / rest window, never
 *    for infants, junior school age or family age groups, never during outdoor play;
 *  - s.8(5) the supervisor (centre_director at the centre) counts fully, at half, or not at
 *    all, by the staff the whole centre needs right now;
 *  - s.8(6) two adults on site once 4+ infant-group children or 6+ other children are here;
 *  - s.8.1 + Schedule 4 family age groups: per-age arithmetic, the 2-/3-employee floors,
 *    max 15, no more than 6 under 24 months;
 *  - s.54 + Schedule 1 col. 5 qualified employees (RECE on file, not expired).
 *
 * Pure functions (requirement, familyRequirement, windowAt, supervisorWeight, minAdults,
 * share) take plain values so they can be tested without a database.
 */
final class OntarioRatios
{
    public const GROUPS = [
        'infant' => 'Infant', 'toddler' => 'Toddler', 'preschool' => 'Preschool', 'kindergarten' => 'Kindergarten',
        'primary_junior' => 'Primary/junior school age', 'junior' => 'Junior school age', 'family' => 'Family age group',
    ];

    private const RANK = ['infant' => 0, 'toddler' => 1, 'preschool' => 2, 'kindergarten' => 3, 'primary_junior' => 4, 'junior' => 5];

    private static array $ctx = [];

    public static function applies(array $profile, ?object $centre): bool
    {
        return ($profile['code'] ?? null) === 'CA-ON'
            && ($profile['ratios']['engine'] ?? null) === 'ontario'
            && $centre && (string) ($centre->license_type ?? '') === 'on_centre';
    }

    /** Schedule 1 rows keyed by band. */
    public static function schedule(): array
    {
        $out = [];
        foreach ((array) (Jurisdictions::profile('CA-ON')['ratios']['centre'] ?? []) as $b) $out[$b['band']] = $b;

        return $out;
    }

    private static function rules(): array
    {
        return (array) (Jurisdictions::profile('CA-ON')['ratios'] ?? []);
    }

    /** The age category a child of this age belongs to by age alone (used for "younger" and "youngest"). */
    public static function naturalCategory(int $months): string
    {
        return match (true) {
            $months < 18 => 'infant',
            $months < 30 => 'toddler',
            $months < 44 => 'preschool',
            $months < 68 => 'kindergarten',
            $months < 108 => 'primary_junior',
            default => 'junior',
        };
    }

    /** The room's licensed age group. rooms.licensed_age_group wins; the older enum is mapped. */
    public static function roomGroup(object $room): string
    {
        $g = (string) ($room->licensed_age_group ?? '');
        if (isset(self::GROUPS[$g])) return $g;

        return match ((string) ($room->age_group ?? '')) {
            'infant' => 'infant', 'toddler' => 'toddler', 'kindergarten' => 'kindergarten',
            'school_age' => 'primary_junior', default => 'preschool',
        };
    }

    private static function ceilDiv(int $a, int $b): int
    {
        return $b <= 0 ? 0 : intdiv($a + $b - 1, $b);
    }

    private static function ratioText(array $b): string
    {
        return (int) $b['staff'] . ':' . (int) $b['children'];
    }

    /**
     * Requirement for a licensed age group (not family). $months: one entry per child present,
     * int months or null when the date of birth is missing. $window: from windowAt() or null.
     */
    public static function requirement(string $group, array $months, int $capacity, ?array $window = null): array
    {
        $S = self::schedule();
        $R = self::rules();
        $n = count($months);
        $known = array_values(array_filter($months, fn ($m) => $m !== null));
        $groupRank = self::RANK[$group] ?? 2;

        // s.8(3) — younger children against the cap (percent of LICENSED capacity, Licensing Manual 3).
        $younger = array_values(array_filter($known, fn ($m) => self::RANK[self::naturalCategory((int) $m)] < $groupRank));
        $capPct = (int) ($R['mixed_age'][$group] ?? 0);
        $base = $capacity > 0 ? $capacity : $n;
        $allowed = $capPct > 0 ? (int) floor($base * $capPct / 100) : 0;
        $eff = $group;
        $mixed = null;
        if ($younger) {
            $over = count($younger) > $allowed;
            if ($over) {
                $eff = self::naturalCategory((int) min($younger));
            }
            $mixed = [
                'younger' => count($younger), 'allowed' => $allowed, 'cap_pct' => $capPct, 'basis' => $capacity > 0 ? 'licensed capacity' : 'children present',
                'over' => $over, 'applies' => $over ? $eff : $group,
                'note' => $over
                    ? count($younger) . ' younger child' . (count($younger) === 1 ? '' : 'ren') . ' — over the ' . $capPct . '% mixed-age limit (' . $allowed . ' of ' . $base . '), so ' . self::GROUPS[$eff] . ' rules apply'
                    : count($younger) . ' younger child' . (count($younger) === 1 ? '' : 'ren') . ' within the ' . $capPct . '% mixed-age limit (' . $allowed . ') — needs Ministry approval for mixed-age grouping',
            ];
        }
        $b = $S[$eff] ?? $S['preschool'];
        $full = $n === 0 ? 0 : self::ceilDiv($n * (int) $b['staff'], (int) $b['children']);
        $required = $full;
        $reduced = null;
        $outdoor = $window && ($window['kind'] ?? '') === 'outdoor';
        if ($window && ! $outdoor && $n > 0) {
            $never = (array) ($R['reduced_ratio']['never'] ?? []);
            $restOnly = (array) ($R['reduced_ratio']['rest_groups'] ?? []);
            $eligible = ! empty($b['reduced']) && ! in_array($eff, $never, true)
                && ($window['kind'] !== 'rest' || in_array($eff, $restOnly, true));
            if ($eligible) {
                $required = min($full, self::ceilDiv($n, (int) $b['reduced']));
                $reduced = ['kind' => $window['kind'], 'until' => $window['until'] ?? null, 'ratio' => '1:' . (int) $b['reduced'], 'full_required' => $full,
                    'label' => 'Reduced ratio — ' . $window['kind'] . ' window' . (! empty($window['until']) ? ', until ' . $window['until'] : '') . ' (1:' . (int) $b['reduced'] . ')'];
            } else {
                // Not eligible (infant / junior group, or rest time for kindergarten and older): the
                // full ratio simply applies; no label, so the room does not read as if it were relieved.
                $reduced = ['kind' => $window['kind'], 'not_applied' => true, 'label' => null];
            }
        } elseif ($outdoor && $n > 0) {
            $reduced = ['kind' => 'outdoor', 'not_applied' => true, 'label' => 'Outdoor play — full ratio'];
        }
        [$qn, $qd] = $b['qualified'] ?? [1, 1];
        $qualified = $n === 0 ? 0 : max(1, intdiv($required * (int) $qn, max(1, (int) $qd)));

        return [
            'group' => $group, 'effective' => $eff, 'n' => $n, 'unknown_age' => $n - count($known),
            'ratio_target' => self::ratioText($b), 'required_full' => $full, 'required' => $required,
            'group_limit' => (int) $b['group'], 'over_group' => $n > (int) $b['group'],
            'mixed_age' => $mixed, 'reduced' => $reduced, 'qualified_required' => $qualified,
        ];
    }

    /** s.8.1 + Schedule 4 — a licensed family age group. */
    public static function familyRequirement(array $months): array
    {
        $R = self::rules()['family_group'] ?? ['max' => 15, 'max_under_24m' => 6, 'bands' => [[0, 12, 3], [12, 24, 4], [24, 156, 8]]];
        $n = count($months);
        $u12 = 0; $u24 = 0; $sum = 0.0; $unknown = 0;
        foreach ($months as $m) {
            if ($m === null) { $unknown++; $m = 24; }
            if ($m < 12) $u12++;
            if ($m < 24) $u24++;
            foreach ($R['bands'] as [$lo, $hi, $per]) {
                if ($m >= $lo && $m < $hi) { $sum += 1 / $per; break; }
            }
        }
        // Rounded up; a hair of tolerance so 8 x 1/8 is exactly 1.
        $req = $n === 0 ? 0 : (int) ceil($sum - 1e-9);
        if ($n > 6) $req = max($req, 2);
        if ($n > 10 && $u12 > 0) $req = max($req, 3);
        if ($n > 0 && $n <= 6 && $u24 <= 3) $req = 1;

        return [
            'group' => 'family', 'effective' => 'family', 'n' => $n, 'unknown_age' => $unknown,
            'ratio_target' => 'Sch. 4 (1:3 <12 mo · 1:4 <24 mo · 1:8)', 'required_full' => $req, 'required' => $req,
            'group_limit' => (int) $R['max'], 'over_group' => $n > (int) $R['max'],
            'under_24m' => $u24, 'under_24m_max' => (int) $R['max_under_24m'], 'over_under_24m' => $u24 > (int) $R['max_under_24m'],
            'mixed_age' => null, 'reduced' => null, 'qualified_required' => $n === 0 ? 0 : ($req >= 3 ? 2 : 1),
        ];
    }

    /** Defaults filled in from the centre's opening hours. */
    public static function settings(object $centre): array
    {
        $raw = is_string($centre->settings ?? null) ? (json_decode((string) $centre->settings, true) ?: []) : [];
        $s = (array) ($raw['ratio_on'] ?? []);
        $hm = fn ($v, $d) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d/', $v) ? substr($v, 0, 5) : $d;
        $start = $hm($s['program_start'] ?? null, $hm((string) ($centre->open_time ?? ''), '07:00'));
        $end = $hm($s['program_end'] ?? null, $hm((string) ($centre->close_time ?? ''), '18:00'));
        $len = self::mins($end) - self::mins($start);
        $long = $len >= 360;
        $arrMax = $long ? 90 : 30; $depMax = $long ? 60 : 30;
        $outdoor = [];
        foreach ((array) ($s['outdoor'] ?? []) as $w) {
            $f = $hm($w['from'] ?? null, null); $t = $hm($w['to'] ?? null, null);
            if ($f && $t && self::mins($t) > self::mins($f)) $outdoor[] = ['from' => $f, 'to' => $t];
        }

        return [
            'reduced_enabled' => array_key_exists('reduced_enabled', $s) ? (bool) $s['reduced_enabled'] : true,
            'program_start' => $start, 'program_end' => $end, 'program_minutes' => $len,
            'arrival_minutes' => max(0, min($arrMax, (int) ($s['arrival_minutes'] ?? $arrMax))), 'arrival_max' => $arrMax,
            'departure_minutes' => max(0, min($depMax, (int) ($s['departure_minutes'] ?? $depMax))), 'departure_max' => $depMax,
            'rest_start' => $hm($s['rest_start'] ?? null, null),
            'rest_minutes' => max(0, min(120, (int) ($s['rest_minutes'] ?? 0))),
            'outdoor' => $outdoor,
        ];
    }

    private static function mins(string $hm): int
    {
        [$h, $m] = array_map('intval', explode(':', $hm) + [0, 0]);

        return $h * 60 + $m;
    }

    private static function clock(int $mins): string
    {
        $mins = (($mins % 1440) + 1440) % 1440;
        $h = intdiv($mins, 60); $m = $mins % 60;

        return ($h % 12 ?: 12) . ':' . str_pad((string) $m, 2, '0', STR_PAD_LEFT) . ' ' . ($h < 12 ? 'AM' : 'PM');
    }

    /** The window a local minute-of-day falls in: outdoor (blocks), arrival, departure, rest — or null. */
    public static function windowAt(array $s, int $minuteOfDay): ?array
    {
        foreach ($s['outdoor'] as $w) {
            if ($minuteOfDay >= self::mins($w['from']) && $minuteOfDay < self::mins($w['to'])) return ['kind' => 'outdoor', 'until' => self::clock(self::mins($w['to']))];
        }
        if (empty($s['reduced_enabled'])) return null;
        $st = self::mins($s['program_start']); $en = self::mins($s['program_end']);
        if ($s['arrival_minutes'] > 0 && $minuteOfDay >= $st && $minuteOfDay < $st + $s['arrival_minutes']) return ['kind' => 'arrival', 'until' => self::clock($st + $s['arrival_minutes'])];
        if ($s['departure_minutes'] > 0 && $minuteOfDay >= $en - $s['departure_minutes'] && $minuteOfDay < $en) return ['kind' => 'departure', 'until' => self::clock($en)];
        if ($s['rest_start'] && $s['rest_minutes'] > 0) {
            $rs = self::mins($s['rest_start']);
            if ($minuteOfDay >= $rs && $minuteOfDay < $rs + $s['rest_minutes']) return ['kind' => 'rest', 'until' => self::clock($rs + $s['rest_minutes'])];
        }

        return null;
    }

    /** s.8(5): 1–4 staff required → 1, 5–6 → 0.5, 7+ → 0. */
    public static function supervisorWeight(int $centreRequired): float
    {
        return $centreRequired < 5 ? 1.0 : ($centreRequired <= 6 ? 0.5 : 0.0);
    }

    /** s.8(6): adults that must be in attendance at the centre. */
    public static function minAdults(int $infantGroupChildren, int $otherChildren): int
    {
        if ($infantGroupChildren + $otherChildren === 0) return 0;

        return ($infantGroupChildren >= 4 || $otherChildren >= 6) ? 2 : 1;
    }

    /**
     * A room's share of a centre-wide pool, with the rounding the clock-in modes already use:
     * 'apportion' rounds up, 'room_assignment' rounds down. A pool with a half supervisor in it
     * rounds to the half.
     */
    public static function share(float $pool, int $nRooms, string $mode): float
    {
        $nRooms = max(1, $nRooms);
        if ($pool <= 0) return 0.0;
        $whole = abs($pool - round($pool)) < 1e-9;
        if ($mode === 'room_assignment') return $whole ? (float) intdiv((int) round($pool), $nRooms) : floor($pool * 2 / $nRooms) / 2;

        return $whole ? (float) self::ceilDiv((int) round($pool), $nRooms) : ceil($pool * 2 / $nRooms) / 2;
    }

    /** Months of age for each child id, at $when (null when no date of birth). */
    private static function monthsFor(array $ids, Carbon $when): array
    {
        if (! $ids) return [];
        $rows = DB::table('children')->whereIn('id', $ids)->get(['id', 'date_of_birth'])->keyBy('id');
        $out = [];
        foreach ($ids as $id) {
            $dob = $rows[$id]->date_of_birth ?? null;
            $out[] = $dob ? (int) floor(abs(Carbon::parse($dob)->diffInMonths($when, true))) : null;
        }

        return $out;
    }

    /** Everything centre-wide one evaluation needs, built once per centre and moment. */
    private static function context(object $centre, ?Carbon $at): array
    {
        $key = $centre->id . '|' . ($at ? $at->timestamp : 'now:' . now()->format('YmdHi'));
        if (isset(self::$ctx[$key])) return self::$ctx[$key];
        if (count(self::$ctx) > 50) self::$ctx = [];

        $when = $at ?: now();
        $tz = AgencyTime::tzForCentre((int) $centre->id);
        $local = $when->copy()->setTimezone($tz);
        $settings = self::settings($centre);
        $window = self::windowAt($settings, (int) $local->format('G') * 60 + (int) $local->format('i'));

        $rooms = DB::table('rooms')->where('centre_id', $centre->id)->where('active', true)->get();
        $per = [];
        $centreRequired = 0; $infantKids = 0; $otherKids = 0;
        foreach ($rooms as $r) {
            $ids = RatioRules::childIdsPresent($r, $at);
            $months = self::monthsFor($ids, $when);
            $g = self::roomGroup($r);
            $req = $g === 'family' ? self::familyRequirement($months) : self::requirement($g, $months, (int) $r->capacity, $window);
            $per[(int) $r->id] = $req;
            $centreRequired += $req['required'];
            if ($g === 'infant') $infantKids += count($ids);
            elseif ($g !== 'family') $otherKids += count($ids);
        }

        // Who is here. Shift rows per room first (as RatioRules), else open punches at the centre.
        $day = $local->toDateString();
        [$from, $to] = AgencyTime::dayRangeForCentre((int) $centre->id, $day);
        $shiftRows = DB::table('shifts')->whereIn('room_id', $rooms->pluck('id')->all() ?: [0])
            ->where('starts_at', '<=', $when)->where('ends_at', '>', $when)->where('status', 'active')->get(['user_id', 'room_id']);
        $pq = DB::table('time_punches')->where('centre_id', $centre->id)->where('punched_in_at', '>=', $from)->where('punched_in_at', '<', $to);
        if ($at) $pq->where('punched_in_at', '<=', $at)->where(fn ($w) => $w->whereNull('punched_out_at')->orWhere('punched_out_at', '>=', $at));
        else $pq->whereNull('punched_out_at');
        $punches = $pq->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        $supervisors = DB::table('role_assignments')->where('centre_id', $centre->id)->where('role', 'centre_director')->where('active', true)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $people = array_values(array_unique(array_merge($punches, $shiftRows->pluck('user_id')->map(fn ($v) => (int) $v)->all())));
        $today = $local->toDateString();
        $qualified = $people ? DB::table('staff_certifications')->whereIn('user_id', $people)->where('cert_type', 'RECE')->where('active', true)
            ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all() : [];

        return self::$ctx[$key] = [
            'tz' => $tz, 'local' => $local, 'settings' => $settings, 'window' => $window, 'rooms' => $rooms->keyBy('id'),
            'per' => $per, 'centre_required' => $centreRequired, 'infant_kids' => $infantKids, 'other_kids' => $otherKids,
            'shift_rows' => $shiftRows, 'punches' => $punches, 'supervisors' => $supervisors, 'qualified' => $qualified,
            'adults' => count($people),
        ];
    }

    /** Called by RatioRules::evaluate for Ontario licensed centres. $out is RatioRules' base shape. */
    public static function evaluate(object $room, ?Carbon $at, array $ids, ?array $educators, array $out, object $centre, bool $hypothetical = false): array
    {
        $full = DB::table('centres')->where('id', $centre->id)->first(['id', 'agency_id', 'license_type', 'settings', 'open_time', 'close_time']);
        $c = self::context($full ?: $centre, $at);
        $when = $at ?: now();
        $g = self::roomGroup($room);
        $req = $c['per'][(int) $room->id] ?? null;
        // Hypothetical children (tests, forecasts) or a room not in the active list: evaluate it here.
        $actualN = $req !== null ? (int) $req['n'] : 0;
        $actual = $req !== null && ! $hypothetical;
        if (! $actual) {
            $months = self::monthsFor($ids, $when);
            $req = $g === 'family' ? self::familyRequirement($months) : self::requirement($g, $months, (int) $room->capacity, $c['window']);
        }
        $n = count($ids);
        $centreRequired = $c['centre_required'] - (($c['per'][(int) $room->id]['required'] ?? 0)) + $req['required'];
        $w = self::supervisorWeight($centreRequired);

        // Staff in this room, supervisor weighted.
        $nRooms = max(1, count($c['rooms']));
        $mode = RatioRules::educatorSource((int) $full->agency_id);
        $here = []; $shared = []; $source = 'clock_ins';
        $mine = $c['shift_rows']->where('room_id', $room->id);
        if ($mine->count() > 0) {
            $here = $mine->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            $source = 'shifts';
        } elseif ($c['punches']) {
            if ($mode === 'room_assignment') {
                $assign = DB::table('educator_rooms')->whereIn('user_id', array_unique($c['punches']))->whereIn('room_id', $c['rooms']->keys()->all() ?: [0])
                    ->get(['user_id', 'room_id'])->groupBy('user_id');
                foreach ($c['punches'] as $uid) {
                    $rs = isset($assign[$uid]) ? $assign[$uid]->pluck('room_id')->map(fn ($v) => (int) $v)->unique()->values()->all() : [];
                    if (count($rs) === 1) { if ($rs[0] === (int) $room->id) $here[] = $uid; }
                    else $shared[] = $uid;
                }
                $source = 'clock_ins_by_room';
            } else {
                $shared = $c['punches'];
                $source = 'clock_ins_shared';
            }
        }
        $weight = fn (array $uids) => array_sum(array_map(fn ($u) => in_array($u, $c['supervisors'], true) ? $w : 1.0, $uids));
        $supHere = array_values(array_intersect($here, $c['supervisors']));
        $supShared = array_values(array_intersect($shared, $c['supervisors']));
        if ($educators !== null) {
            [$have, $source] = $educators;
            $have = (float) $have;
        } else {
            $have = $weight($here) + self::share($weight($shared), $nRooms, $mode);
        }
        $have = round($have, 1);
        $qHere = count(array_filter($here, fn ($u) => in_array($u, $c['qualified'], true) && (! in_array($u, $c['supervisors'], true) || $w > 0)));
        $qShared = count(array_filter($shared, fn ($u) => in_array($u, $c['qualified'], true) && (! in_array($u, $c['supervisors'], true) || $w > 0)));
        $qPresent = $qHere + (int) self::share((float) $qShared, $nRooms, $mode);

        $warnings = []; $issues = [];
        $label = self::GROUPS[$g];
        $eff = $req['effective'];
        $reason = $label . ' group → ' . $req['ratio_target'] . ', max ' . $req['group_limit'];
        if ($eff !== $g) $reason = $label . ' group, ' . self::GROUPS[$eff] . ' rules (mixed-age over limit) → ' . $req['ratio_target'] . ', max ' . $req['group_limit'];
        if ($g === 'family') $reason = 'Family age group (s.8.1) → ' . $req['required'] . ' staff for these ages, max ' . $req['group_limit'];
        if (! empty($req['reduced']['label'])) $reason .= ' · ' . $req['reduced']['label'] . (empty($req['reduced']['not_applied']) && $req['reduced']['full_required'] > $req['required'] ? ' — full ratio needs ' . $req['reduced']['full_required'] : '');

        $sup = null;
        if (($supHere || $supShared) && $n > 0) {
            $note = $w >= 1 ? 'Supervisor counts fully (centre needs ' . $centreRequired . ' staff)'
                : ($w > 0 ? 'Supervisor counts as ½ — centre needs ' . $centreRequired . ' staff (5–6)' : 'Supervisor not counted — centre needs ' . $centreRequired . ' staff (7+)');
            $sup = ['present' => true, 'weight' => $w, 'centre_required' => $centreRequired, 'note' => $note, 'shared' => ! $supHere];
            $reason .= ' · ' . $note;
        }

        if ($req['over_group']) $warnings[] = $n . ' children — over the maximum group size of ' . $req['group_limit'];
        if (! empty($req['over_under_24m'])) { $warnings[] = $req['under_24m'] . ' children under 24 months — a family age group may have no more than ' . $req['under_24m_max']; $issues[] = 'family_under_24m'; }
        if (! empty($req['mixed_age'])) $warnings[] = $req['mixed_age']['note'];
        if ($req['unknown_age']) $warnings[] = $req['unknown_age'] . ' child(ren) have no date of birth — counted in the group\'s own age category';

        // s.8(6) — adults in attendance at the centre (not for family age groups, s.8(7)).
        $delta = $actual ? 0 : $n - $actualN;
        $infantKids = $c['infant_kids'] + ($g === 'infant' ? $delta : 0);
        $otherKids = $c['other_kids'] + ($g !== 'infant' ? $delta : 0);
        $adultsNeed = $g === 'family' ? 0 : self::minAdults($infantKids, $otherKids);
        $adultsOn = max($c['adults'], (int) ceil($have));
        $minAdults = null;
        if ($adultsNeed >= 2 && $n > 0) {
            $minAdults = ['required' => 2, 'on_site' => $adultsOn, 'met' => $adultsOn >= 2,
                'note' => ($infantKids >= 4 ? $infantKids . ' children in infant groups' : $otherKids . ' children outside infant groups') . ' at the centre — 2 adults must be on site (' . $adultsOn . ' ' . ($adultsOn === 1 ? 'is' : 'are') . ')'];
            if ($adultsOn < 2) { $warnings[] = $minAdults['note']; $issues[] = 'min_adults'; }
        }

        // s.54 + Schedule 1 col. 5 / s.8.1(2)7 — qualified employees.
        $qual = null;
        if ($n > 0) {
            $qual = ['required' => $req['qualified_required'], 'present' => $qPresent, 'met' => $qPresent >= $req['qualified_required'],
                'shared' => $source !== 'shifts' && $qShared > 0, 'basis' => 'RECE on file (Certifications)'];
            if (! $qual['met']) {
                $qual['note'] = $req['qualified_required'] === 1
                    ? 'No RECE in this group — s.54 needs one qualified employee'
                    : 'Needs ' . $req['qualified_required'] . ' RECEs in this group (' . $qPresent . ' here)';
                $warnings[] = $qual['note'];
                $issues[] = 'qualified';
            }
        }

        $out['children_present'] = $n;
        $out['educators_present'] = $have;
        $out['educator_source'] = $source;
        $out['rule'] = 'ontario';
        $out['required_educators'] = $req['required'];
        $out['ratio_target'] = $req['ratio_target'];
        $out['reason'] = $reason;
        $out['group_limit'] = $req['group_limit'];
        $out['over_group'] = (bool) $req['over_group'] || ! empty($req['over_under_24m']);
        $out['group_note'] = 'Maximum group size ' . $req['group_limit'];
        $out['warnings'] = $warnings;
        $out['compliance_issues'] = $issues;
        $out['ontario'] = [
            'group' => $g, 'group_label' => $label, 'effective' => $eff, 'effective_label' => self::GROUPS[$eff],
            'required_full' => $req['required_full'], 'reduced' => $req['reduced'], 'window' => $c['window'],
            'supervisor' => $sup, 'min_adults' => $minAdults, 'mixed_age' => $req['mixed_age'], 'qualified' => $qual,
            'family' => $g === 'family' ? ['under_24m' => $req['under_24m'], 'max_under_24m' => $req['under_24m_max']] : null,
        ];

        return $out;
    }
}
