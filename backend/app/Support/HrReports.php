<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 5 (2026-11-27): THE HR REPORTS PACK, PROBATION AND THE MISSING-INFO CHECK.
 *
 * Every report takes the caller's people (HrLeave::staffIds with CentreScope = THE list: never an outsider) and their
 * leavers (HrLeaving::leaverIds), so a director only ever sees their own centres. Nothing is recomputed differently:
 *  - leavers + last day = B4's hr_leavings (completed, not undone), else the file's end date, else the closed account;
 *  - leave liability = B4's HrLeaving::payout() per person (the same worksheet the leaving form shows);
 *  - lateness = B1's HrAttendance::lateness(); overtime = B2's hr_overtime_requests; schedule = B2's HrTime::sheet().
 * Settings agencies.settings.hr_compliance {probation_days 90, remind_days 14, invite_days 60} (own key: HrLeave::settings
 * rewrites settings.hr). Minimum wages: a small dated table (MIN_WAGES, checked MIN_WAGES_CHECKED) for the provinces and
 * states where agencies run today; a region not in it is never flagged.
 */
final class HrReports
{
    public const DEFAULTS = ['probation_days' => 90, 'remind_days' => 14, 'invite_days' => 60];

    /** General adult minimum wage per hour, by region, with the day each rate starts. Checked against the official pages. */
    public const MIN_WAGES = [
        'CA-ON' => ['name' => 'Ontario', 'rates' => [['from' => '2025-10-01', 'rate' => 17.60], ['from' => '2026-10-01', 'rate' => 17.95]],
            'source' => 'ontario.ca/minimumwage', 'note' => 'General rate. Student and homeworker rates differ.'],
        'US-OH' => ['name' => 'Ohio', 'rates' => [['from' => '2026-01-01', 'rate' => 11.00], ['from' => '2027-01-01', 'rate' => 11.40]],
            'source' => 'Ohio Department of Commerce', 'note' => 'Non-tipped employees of employers above the gross-receipts threshold; smaller employers follow the federal $7.25.'],
        'US-CA' => ['name' => 'California', 'rates' => [['from' => '2026-01-01', 'rate' => 16.90], ['from' => '2027-01-01', 'rate' => 17.40]],
            'source' => 'dir.ca.gov (Labor Commissioner)', 'note' => 'Statewide rate. Many cities and counties set a higher local minimum.'],
    ];
    public const MIN_WAGES_CHECKED = '2026-11-27';

    private const PROVINCES = ['ONTARIO' => 'ON', 'QUEBEC' => 'QC', 'BRITISH COLUMBIA' => 'BC', 'ALBERTA' => 'AB', 'MANITOBA' => 'MB', 'SASKATCHEWAN' => 'SK',
        'NOVA SCOTIA' => 'NS', 'NEW BRUNSWICK' => 'NB', 'NEWFOUNDLAND AND LABRADOR' => 'NL', 'PRINCE EDWARD ISLAND' => 'PE', 'OHIO' => 'OH', 'CALIFORNIA' => 'CA'];

    public static function settings(int $agencyId): array
    {
        $s = (array) ((json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [])['hr_compliance'] ?? []);
        $o = self::DEFAULTS;
        foreach ($o as $k => $v) if (isset($s[$k]) && is_numeric($s[$k])) $o[$k] = (int) $s[$k];

        return $o;
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        return DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $o = self::settings($agencyId);
            foreach (self::DEFAULTS as $k => $v) if (array_key_exists($k, $in)) $o[$k] = (int) $in[$k];
            $all['hr_compliance'] = $o;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all)]);

            return $o;
        });
    }

    // ── the people the caller may report on ────────────────────────────────────────────────────

    /** Current staff (staffIds minus leavers) and leavers, inside the caller's scope. */
    public static function population(int $agencyId, ?array $scope): array
    {
        $leavers = HrLeaving::leaverIds($agencyId, $scope);
        $current = array_values(array_diff(HrLeave::staffIds($agencyId, $scope), $leavers));

        return [$current, $leavers];
    }

    /** One row of plain facts per person: name, start, centre, job, type, leave date (leavers). @return array<int,array> */
    public static function facts(int $agencyId, array $ids, array $leavers = []): array
    {
        if (! $ids) return [];
        $users = DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'hire_date', 'deleted_at', 'status', 'updated_at', 'pay_rate', 'pay_type', 'is_contractor', 'last_login_at', 'created_at', 'email'])->keyBy('id');
        $files = DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $ids)->get()->keyBy('user_id');
        $centres = DB::table('centres')->where('agency_id', $agencyId)->get(['id', 'name', 'province', 'country'])->keyBy('id');
        $roleC = [];
        foreach (DB::table('role_assignments')->whereIn('user_id', $ids)->whereIn('role', HrLeave::STAFF_ROLES)->whereIn('centre_id', $centres->keys()->all() ?: [0])
            ->orderByDesc('active')->get(['user_id', 'centre_id', 'role']) as $r) {
            $roleC[(int) $r->user_id][] = (int) $r->centre_id;
        }
        $roles = DB::table('role_assignments')->whereIn('user_id', $ids)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->get(['user_id', 'role'])->groupBy('user_id');
        $leaveDates = self::leaveDates($agencyId, array_values(array_intersect($ids, $leavers)));
        $out = [];
        foreach ($ids as $id) {
            $u = $users[$id] ?? null;
            if (! $u) continue;
            $f = $files[$id] ?? null;
            $cid = $f && $f->primary_centre_id && isset($centres[$f->primary_centre_id]) ? (int) $f->primary_centre_id : ($roleC[$id][0] ?? null);
            $out[$id] = ['user_id' => (int) $id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'first_name' => $u->first_name,
                'start_date' => $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null,
                'centre_id' => $cid, 'centre' => $cid ? $centres[$cid]->name : null,
                'job_title' => $f->job_title ?? null, 'employment_type' => $f->employment_type ?? null,
                'roles' => ($roles[$id] ?? collect())->pluck('role')->unique()->values()->all(),
                'left_on' => $leaveDates[$id] ?? null, 'file' => $f, 'user' => $u,
                'region' => $cid ? self::region($agencyId, $centres[$cid]) : null];
        }

        return $out;
    }

    /** A leaver's last day: B4's completed leaving (not undone), else the file's end date, else the day the account closed. */
    public static function leaveDates(int $agencyId, array $ids): array
    {
        if (! $ids) return [];
        $out = [];
        foreach (DB::table('hr_leavings')->where('agency_id', $agencyId)->whereIn('user_id', $ids)->where('status', 'completed')->whereNotNull('last_day')
            ->orderBy('id')->get(['user_id', 'last_day']) as $l) {
            $out[(int) $l->user_id] = substr((string) $l->last_day, 0, 10);   // the latest completed leaving wins
        }
        foreach (DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $ids)->whereNotNull('end_date')->get(['user_id', 'end_date']) as $f) {
            $out[(int) $f->user_id] ??= substr((string) $f->end_date, 0, 10);
        }
        foreach (DB::table('users')->whereIn('id', $ids)->get(['id', 'deleted_at', 'updated_at']) as $u) {
            $tz = AgencyTime::tz($agencyId);
            $out[(int) $u->id] ??= Carbon::parse((string) ($u->deleted_at ?: $u->updated_at), 'UTC')->setTimezone($tz)->toDateString();
        }

        return $out;
    }

    /** Every past leaving (rehired people too), for starters & leavers: [user_id, last_day]. */
    public static function leaveEvents(int $agencyId, array $ids, string $from, string $to): array
    {
        $ev = [];
        $have = [];
        foreach (DB::table('hr_leavings')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->where('status', 'completed')
            ->whereBetween('last_day', [$from, $to])->get(['user_id', 'last_day', 'reason_code']) as $l) {
            $ev[] = ['user_id' => (int) $l->user_id, 'date' => substr((string) $l->last_day, 0, 10), 'reason_code' => $l->reason_code];
            $have[(int) $l->user_id] = true;
        }

        return [$ev, $have];
    }

    public static function region(int $agencyId, object $centre): ?string
    {
        $p = strtoupper(trim((string) $centre->province));
        if ($p === '') return null;
        $p = self::PROVINCES[$p] ?? $p;
        $country = strtoupper(trim((string) ($centre->country ?? '')));
        if (! in_array($country, ['CA', 'US'], true)) {
            $map = ['CANADA' => 'CA', 'UNITED STATES' => 'US', 'USA' => 'US'];
            $country = $map[$country] ?? strtoupper((string) ((json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [])['country'] ?? 'CA'));
        }

        return $country . '-' . $p;
    }

    /** The minimum wage on a day for a region, or null when the table has none. */
    public static function minWage(?string $region, string $onDate): ?array
    {
        if (! $region || ! isset(self::MIN_WAGES[$region])) return null;
        $m = self::MIN_WAGES[$region];
        $rate = null; $from = null;
        foreach ($m['rates'] as $r) if ($r['from'] <= $onDate) { $rate = $r['rate']; $from = $r['from']; }

        return $rate === null ? null : ['region' => $region, 'name' => $m['name'], 'rate' => $rate, 'since' => $from, 'note' => $m['note'], 'source' => $m['source']];
    }

    public static function minWageTable(): array
    {
        $out = [];
        foreach (self::MIN_WAGES as $k => $m) $out[] = ['region' => $k, 'name' => $m['name'], 'rates' => $m['rates'], 'note' => $m['note'], 'source' => $m['source']];

        return ['checked' => self::MIN_WAGES_CHECKED, 'regions' => $out];
    }

    // ── headcount, starters & leavers, turnover, retention, tenure ─────────────────────────────

    public static function headcount(int $agencyId, ?array $scope): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $byC = []; $byT = [];
        foreach ($f as $p) {
            $c = $p['centre'] ?? 'No centre';
            $t = HrLeave::EMPLOYMENT[$p['employment_type'] ?? ''] ?? 'Not set';
            $byC[$c] ??= ['label' => $c, 'total' => 0] + array_fill_keys(array_keys(HrLeave::EMPLOYMENT), 0) + ['not_set' => 0];
            $byC[$c]['total']++;
            $byC[$c][$p['employment_type'] && isset(HrLeave::EMPLOYMENT[$p['employment_type']]) ? $p['employment_type'] : 'not_set']++;
            $byT[$t] = ($byT[$t] ?? 0) + 1;
        }
        ksort($byC);
        arsort($byT);

        return ['total' => count($f), 'by_centre' => array_values($byC), 'by_type' => collect($byT)->map(fn ($n, $k) => ['label' => $k, 'total' => $n])->values()->all(),
            'types' => HrLeave::EMPLOYMENT];
    }

    /** Headcount on a day: started on or before it (no start date = counted) and not left before it. */
    private static function countOn(array $facts, string $day): int
    {
        $n = 0;
        foreach ($facts as $p) {
            if ($p['start_date'] && $p['start_date'] > $day) continue;
            if ($p['left_on'] && $p['left_on'] < $day) continue;
            $n++;
        }

        return $n;
    }

    public static function movement(int $agencyId, ?array $scope, string $from, string $to): array
    {
        [$cur, $lv] = self::population($agencyId, $scope);
        $all = self::facts($agencyId, array_merge($cur, $lv), $lv);
        [$events, $have] = self::leaveEvents($agencyId, array_keys($all), $from, $to);
        foreach ($all as $id => $p) {   // a leaver with no completed leaving: their file end date / account close
            if (! isset($have[$id]) && in_array($id, $lv, true) && $p['left_on'] && $p['left_on'] >= $from && $p['left_on'] <= $to) {
                $events[] = ['user_id' => $id, 'date' => $p['left_on'], 'reason_code' => null];
            }
        }
        $starters = array_values(array_filter($all, fn ($p) => $p['start_date'] && $p['start_date'] >= $from && $p['start_date'] <= $to));
        $open = self::countOn($all, $from);
        $close = self::countOn($all, $to);
        $avg = ($open + $close) / 2;
        $months = [];
        for ($m = Carbon::parse(substr($from, 0, 7) . '-01'); $m->toDateString() <= $to; $m->addMonthNoOverflow()) $months[$m->format('Y-m')] = ['month' => $m->format('F Y'), 'starters' => 0, 'leavers' => 0];
        foreach ($starters as $s) if (isset($months[substr($s['start_date'], 0, 7)])) $months[substr($s['start_date'], 0, 7)]['starters']++;
        foreach ($events as $e) if (isset($months[substr($e['date'], 0, 7)])) $months[substr($e['date'], 0, 7)]['leavers']++;
        // Retention: of the people here on the first day, how many are still here on the last.
        $base = array_filter($all, fn ($p) => (! $p['start_date'] || $p['start_date'] <= $from) && (! $p['left_on'] || $p['left_on'] >= $from));
        $stayed = array_filter($base, fn ($p) => ! $p['left_on'] || $p['left_on'] > $to);

        return ['from' => $from, 'to' => $to, 'headcount_start' => $open, 'headcount_end' => $close, 'average' => round($avg, 1),
            'starters' => count($starters), 'leavers' => count($events),
            'turnover_pct' => $avg > 0 ? round(100 * count($events) / $avg, 1) : null,
            'retention_pct' => $base ? round(100 * count($stayed) / count($base), 1) : null,
            'months' => array_values($months),
            'starter_rows' => array_map(fn ($p) => ['name' => $p['name'], 'centre' => $p['centre'], 'job_title' => $p['job_title'], 'date' => $p['start_date'], 'still_here' => ! $p['left_on']], array_values($starters)),
            'leaver_rows' => array_map(fn ($e) => ['name' => $all[$e['user_id']]['name'] ?? '', 'centre' => $all[$e['user_id']]['centre'] ?? null, 'job_title' => $all[$e['user_id']]['job_title'] ?? null,
                'date' => $e['date'], 'started' => $all[$e['user_id']]['start_date'] ?? null, 'reason' => $e['reason_code'] ? ($e['reason_code'] . ' - ' . (HrLeaving::REASONS[$e['reason_code']] ?? '')) : null,
                'years' => HrLeave::serviceYears($all[$e['user_id']]['start_date'] ?? null, $e['date'])], $events)];
    }

    /** Retention by the year people started: how many are still here, and how long the leavers stayed. */
    public static function retention(int $agencyId, ?array $scope, string $today): array
    {
        [$cur, $lv] = self::population($agencyId, $scope);
        $all = self::facts($agencyId, array_merge($cur, $lv), $lv);
        $y0 = (int) substr($today, 0, 4);
        $rows = [];
        for ($y = $y0; $y >= $y0 - 5; $y--) $rows[$y] = ['year' => (string) $y, 'started' => 0, 'still_here' => 0, 'left' => 0, 'stay_months' => [], 'past_90' => 0, 'past_365' => 0];
        foreach ($all as $p) {
            if (! $p['start_date']) continue;
            $y = (int) substr($p['start_date'], 0, 4);
            if (! isset($rows[$y])) continue;
            $rows[$y]['started']++;
            $end = $p['left_on'] ?: $today;
            $days = Carbon::parse($p['start_date'])->diffInDays(Carbon::parse($end));
            if ($p['left_on']) { $rows[$y]['left']++; $rows[$y]['stay_months'][] = $days / 30.44; } else $rows[$y]['still_here']++;
            if ($days >= 90) $rows[$y]['past_90']++;
            if ($days >= 365) $rows[$y]['past_365']++;
        }
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['year' => $r['year'], 'started' => $r['started'], 'still_here' => $r['still_here'], 'left' => $r['left'],
                'retained_pct' => $r['started'] ? round(100 * $r['still_here'] / $r['started'], 1) : null,
                'stayed_90_pct' => $r['started'] ? round(100 * $r['past_90'] / $r['started'], 1) : null,
                'stayed_year_pct' => $r['started'] ? round(100 * $r['past_365'] / $r['started'], 1) : null,
                'leavers_avg_months' => $r['stay_months'] ? round(array_sum($r['stay_months']) / count($r['stay_months']), 1) : null];
        }
        $noStart = count(array_filter($all, fn ($p) => ! $p['start_date']));

        return ['rows' => $out, 'no_start_date' => $noStart];
    }

    public static function tenure(int $agencyId, ?array $scope, string $today): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $bands = ['Under 1 year' => 0, '1 to 2 years' => 0, '2 to 5 years' => 0, '5 to 10 years' => 0, '10 years or more' => 0, 'No start date' => 0];
        $rows = []; $yrs = [];
        foreach ($f as $p) {
            $y = $p['start_date'] ? HrLeave::serviceYears($p['start_date'], $today) : null;
            $b = $y === null ? 'No start date' : ($y < 1 ? 'Under 1 year' : ($y < 2 ? '1 to 2 years' : ($y < 5 ? '2 to 5 years' : ($y < 10 ? '5 to 10 years' : '10 years or more'))));
            $bands[$b]++;
            if ($y !== null) $yrs[] = $y;
            $rows[] = ['name' => $p['name'], 'centre' => $p['centre'], 'job_title' => $p['job_title'], 'start_date' => $p['start_date'], 'years' => $y, 'band' => $b];
        }
        usort($rows, fn ($a, $b) => ($b['years'] ?? -1) <=> ($a['years'] ?? -1));
        sort($yrs);
        $median = $yrs ? (count($yrs) % 2 ? $yrs[intdiv(count($yrs), 2)] : ($yrs[count($yrs) / 2 - 1] + $yrs[count($yrs) / 2]) / 2) : null;

        return ['rows' => $rows, 'bands' => collect($bands)->map(fn ($n, $k) => ['label' => $k, 'total' => $n])->values()->all(),
            'average_years' => $yrs ? round(array_sum($yrs) / count($yrs), 1) : null, 'median_years' => $median !== null ? round($median, 1) : null];
    }

    // ── money: leave liability (pay viewers only, the caller checks) ─────────────────────────────

    public static function liability(int $agencyId, ?array $scope): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $rows = []; $total = 0.0; $noRate = 0; $byType = [];
        foreach ($f as $id => $p) {
            $po = HrLeaving::payout($agencyId, $id, true);
            $lines = array_values(array_filter($po['lines'], fn ($l) => $l['included']));
            if (! $lines) continue;
            if ($po['hourly_rate'] === null) $noRate++;
            foreach ($lines as $l) {
                $byType[$l['name']] ??= ['label' => $l['name'], 'hours' => 0.0, 'value' => 0.0, 'people' => 0];
                $byType[$l['name']]['hours'] += $l['hours'];
                $byType[$l['name']]['value'] += (float) $l['value'];
                $byType[$l['name']]['people']++;
            }
            $total += (float) $po['total'];
            $rows[] = ['name' => $p['name'], 'centre' => $p['centre'], 'balances' => implode(', ', array_map(fn ($l) => $l['name'] . ' ' . round($l['balance'], 2) . ' ' . $l['unit'], $lines)),
                'hours' => round(array_sum(array_column($lines, 'hours')), 2), 'hourly_rate' => $po['hourly_rate'], 'pay_type' => $po['pay_type'],
                'value' => $po['hourly_rate'] === null ? null : round((float) $po['total'], 2)];
        }
        usort($rows, fn ($a, $b) => ($b['value'] ?? -1) <=> ($a['value'] ?? -1));

        return ['rows' => $rows, 'total' => round($total, 2), 'no_rate' => $noRate,
            'by_type' => array_values(array_map(fn ($t) => ['label' => $t['label'], 'hours' => round($t['hours'], 2), 'value' => round($t['value'], 2), 'people' => $t['people']], $byType))];
    }

    // ── overtime, lateness, schedule (B1 / B2 data) ───────────────────────────────────────────

    public static function overtime(int $agencyId, ?array $scope, string $from, string $to): array
    {
        [$cur, $lv] = self::population($agencyId, $scope);
        $ids = array_merge($cur, $lv);
        $f = self::facts($agencyId, $ids, $lv);
        $per = [];
        foreach (DB::table('hr_overtime_requests')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->whereBetween('work_date', [$from, $to])
            ->get(['user_id', 'status', 'outcome', 'minutes', 'bank_hours']) as $o) {
            $u = (int) $o->user_id;
            $per[$u] ??= ['name' => $f[$u]['name'] ?? '', 'centre' => $f[$u]['centre'] ?? null, 'requests' => 0, 'pending' => 0, 'declined' => 0, 'approved_hours' => 0.0, 'paid_hours' => 0.0, 'banked_hours' => 0.0, 'bank_credit' => 0.0];
            $per[$u]['requests']++;
            if ($o->status === 'submitted') $per[$u]['pending']++;
            if ($o->status === 'declined') $per[$u]['declined']++;
            if (in_array($o->status, ['approved', 'paid'], true)) {
                $h = (int) $o->minutes / 60;
                $per[$u]['approved_hours'] += $h;
                if ($o->outcome === 'bank') { $per[$u]['banked_hours'] += $h; $per[$u]['bank_credit'] += (float) $o->bank_hours; } else $per[$u]['paid_hours'] += $h;
            }
        }
        $rows = array_values(array_map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r), $per));
        usort($rows, fn ($a, $b) => $b['approved_hours'] <=> $a['approved_hours']);
        $sum = fn ($k) => round(array_sum(array_column($rows, $k)), 2);

        return ['rows' => $rows, 'totals' => ['requests' => $sum('requests'), 'pending' => $sum('pending'), 'approved_hours' => $sum('approved_hours'),
            'paid_hours' => $sum('paid_hours'), 'banked_hours' => $sum('banked_hours'), 'bank_credit' => $sum('bank_credit')]];
    }

    public static function lateness(int $agencyId, ?array $scope, string $from, string $to): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $r = HrAttendance::lateness($agencyId, $from, $to, $cur);
        $cn = DB::table('centres')->where('agency_id', $agencyId)->pluck('name', 'id');
        $byC = [];
        $rows = [];
        foreach ($r['rows'] as $x) {
            $c = $f[$x['user_id']]['centre'] ?? ($cn[$x['centre_ids'][0] ?? 0] ?? 'No centre');
            $byC[$c] ??= ['label' => $c, 'compared' => 0, 'late' => 0, 'minutes' => 0, 'people_late' => 0];
            $byC[$c]['compared'] += $x['compared'];
            $byC[$c]['late'] += $x['late'];
            $byC[$c]['minutes'] += $x['minutes'];
            if ($x['late']) $byC[$c]['people_late']++;
            $rows[] = ['name' => $f[$x['user_id']]['name'] ?? '', 'centre' => $c] + array_intersect_key($x, array_flip(['compared', 'late', 'minutes', 'average', 'worst', 'on_time_pct', 'pattern']));
        }
        foreach ($byC as &$c) $c['on_time_pct'] = $c['compared'] ? (int) round(100 * ($c['compared'] - $c['late']) / $c['compared']) : null;
        unset($c);
        usort($rows, fn ($a, $b) => ($b['late'] <=> $a['late']) ?: ($b['minutes'] <=> $a['minutes']));
        ksort($byC);

        return ['grace' => $r['grace'], 'by_centre' => array_values($byC), 'rows' => $rows];
    }

    public static function schedule(int $agencyId, ?array $scope, string $from, string $to): array
    {
        [$cur, $lv] = self::population($agencyId, $scope);
        $mine = array_flip(array_merge($cur, $lv));
        $centres = Live::centres(DB::table('centres'), 'centres')->where('agency_id', $agencyId)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->get(['id', 'name']);
        $rows = []; $byC = [];
        foreach ($centres as $c) {
            $sh = HrTime::sheet($agencyId, (int) $c->id, $from, $to);
            $t = ['label' => $c->name, 'people' => 0, 'shifts' => 0, 'scheduled_hours' => 0.0, 'worked_hours' => 0.0, 'paid_hours' => 0.0, 'no_show' => 0, 'unscheduled' => 0, 'overtime_hours' => 0.0];
            foreach ($sh['rows'] as $r) {
                if (! isset($mine[$r['user_id']])) continue;
                $row = ['name' => $r['name'], 'centre' => $c->name, 'shifts' => $r['shifts'], 'scheduled_hours' => round($r['scheduled_min'] / 60, 2),
                    'worked_hours' => round($r['worked_min'] / 60, 2), 'paid_hours' => round($r['paid_min'] / 60, 2), 'variance_hours' => round($r['variance_min'] / 60, 2),
                    'no_show' => $r['no_show'], 'unscheduled' => $r['unscheduled'], 'overtime_hours' => round($r['overtime_min'] / 60, 2)];
                $rows[] = $row;
                $t['people']++;
                foreach (['shifts', 'scheduled_hours', 'worked_hours', 'paid_hours', 'no_show', 'unscheduled', 'overtime_hours'] as $k) $t[$k] += $row[$k];
            }
            if ($t['people']) $byC[] = array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $t);
        }

        return ['rows' => $rows, 'by_centre' => $byC];
    }

    // ── leave: annual summary with the previous year, team absence for a range ───────────────────

    public static function leaveSummary(int $agencyId, ?array $scope, string $today): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $s = HrLeave::settings($agencyId);
        $types = HrLeave::types($agencyId)->keyBy('id');
        $rows = [];
        $ledger = DB::table('hr_leave_ledger')->where('agency_id', $agencyId)->whereIn('user_id', $cur ?: [0])->get(['user_id', 'leave_type_id', 'entry_date', 'amount', 'kind'])->groupBy('user_id');
        foreach ($f as $id => $p) {
            $ys = HrLeave::yearStart($agencyId, $id, $today, $s, $p['start_date']);
            $ye = HrLeave::addYears($ys, 1);
            $ps = HrLeave::addYears($ys, -1);
            $mine = ($ledger[$id] ?? collect())->groupBy('leave_type_id');
            foreach ($mine as $tid => $entries) {
                $t = $types[$tid] ?? null;
                if (! $t) continue;
                $calc = function (string $a, string $b) use ($entries) {
                    $earned = 0.0; $taken = 0.0; $other = 0.0; $end = 0.0;
                    foreach ($entries as $e) {
                        $d = substr((string) $e->entry_date, 0, 10);
                        if ($d < $b) $end += (float) $e->amount;
                        if ($d < $a || $d >= $b) continue;
                        if (in_array($e->kind, ['taken', 'returned', 'shutdown'], true)) $taken -= (float) $e->amount;   // HR4B9: a closure deduction is leave taken
                        elseif (in_array($e->kind, ['grant', 'accrual', 'overtime'], true)) $earned += (float) $e->amount;
                        else $other += (float) $e->amount;
                    }

                    return ['earned' => round($earned, 2), 'taken' => round($taken, 2), 'other' => round($other, 2), 'end' => round($end, 2)];
                };
                $prev = $calc($ps, $ys);
                $now = $calc($ys, $ye);
                $rows[] = ['name' => $p['name'], 'centre' => $p['centre'], 'type' => $t->name, 'unit' => $t->unit, 'year_start' => $ys,
                    'prev_earned' => $prev['earned'], 'prev_taken' => $prev['taken'], 'prev_end' => $prev['end'],
                    'earned' => $now['earned'], 'taken' => $now['taken'], 'other' => $now['other'], 'balance' => round(HrLeave::balance($id, (int) $tid), 2)];
            }
        }
        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']) ?: strcasecmp($a['type'], $b['type']));

        return ['rows' => $rows, 'leave_year' => $s['leave_year']];
    }

    public static function teamAbsence(int $agencyId, ?array $scope, string $from, string $to): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $per = []; $byDay = [];
        foreach (HrLeave::absenceDays($agencyId, $from, $to, $cur) as $d) {
            $k = $d['request_id'];
            $per[$k] ??= ['name' => $f[$d['user_id']]['name'] ?? '', 'centre' => $f[$d['user_id']]['centre'] ?? null, 'type' => $d['type_name'], 'colour' => $d['colour'],
                'status' => 'approved', 'first' => $d['date'], 'last' => $d['date'], 'days' => 0.0];
            $per[$k]['first'] = min($per[$k]['first'], $d['date']);
            $per[$k]['last'] = max($per[$k]['last'], $d['date']);
            $per[$k]['days'] += $d['days'];
            $byDay[$d['date']][$d['user_id']] = true;
        }
        foreach (DB::table('time_off_requests')->where('agency_id', $agencyId)->where('status', 'pending')->whereIn('user_id', $cur ?: [0])
            ->whereDate('start_at', '<=', $to)->whereDate('end_at', '>=', $from)->get() as $r) {
            $t = HrLeave::typeForRequest($r);
            $a = max($from, substr((string) $r->start_at, 0, 10));
            $b = min($to, substr((string) $r->end_at, 0, 10));
            $per['p' . $r->id] = ['name' => $f[(int) $r->user_id]['name'] ?? '', 'centre' => $f[(int) $r->user_id]['centre'] ?? null,
                'type' => $t ? $t->name : (HrLeave::REQUEST_TYPES[$r->request_type] ?? $r->request_type), 'colour' => $t->colour ?? '#64748B',
                'status' => 'pending', 'first' => $a, 'last' => $b, 'days' => count(HrLeave::workDays($agencyId, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null, $a, $b))];
        }
        $rows = array_values(array_map(fn ($r) => ['days' => round($r['days'], 2)] + $r, $per));
        usort($rows, fn ($a, $b) => strcmp($a['first'], $b['first']) ?: strcasecmp($a['name'], $b['name']));
        ksort($byDay);
        $busiest = null;
        foreach ($byDay as $d => $who) if (! $busiest || count($who) > $busiest['people']) $busiest = ['date' => $d, 'people' => count($who)];

        return ['rows' => $rows, 'days' => collect($byDay)->map(fn ($who, $d) => ['date' => $d, 'people' => count($who)])->values()->all(), 'busiest' => $busiest];
    }

    // ── probation ───────────────────────────────────────────────────────────────────────────

    /** One person's probation: end date (override, else start + agency length), passed, state. */
    public static function probationOf(array $p, array $set, string $today): array
    {
        $f = $p['file'];
        $end = $f && ! empty($f->probation_end_on) ? substr((string) $f->probation_end_on, 0, 10)
            : ($p['start_date'] ? Carbon::parse($p['start_date'])->addDays($set['probation_days'])->toDateString() : null);
        $passed = $f && ! empty($f->probation_passed_on) ? substr((string) $f->probation_passed_on, 0, 10) : null;
        $state = $passed ? 'passed' : (! $end ? 'no_start' : ($end < Carbon::parse($today)->subDays(90)->toDateString() ? 'old'
            : ($end < $today ? 'overdue' : ($end <= Carbon::parse($today)->addDays(max(30, $set['remind_days']))->toDateString() ? 'due' : 'on'))));

        return ['end_on' => $end, 'passed_on' => $passed, 'state' => $state, 'override' => (bool) ($f && ! empty($f->probation_end_on)),
            'days_left' => $end ? (int) Carbon::parse($today)->diffInDays(Carbon::parse($end), false) : null];
    }

    public static function probation(int $agencyId, ?array $scope, string $today, bool $all = false): array
    {
        [$cur] = self::population($agencyId, $scope);
        $set = self::settings($agencyId);
        $rows = [];
        foreach (self::facts($agencyId, $cur) as $id => $p) {
            $pr = self::probationOf($p, $set, $today);
            if (! $all && in_array($pr['state'], ['old', 'passed'], true)) continue;
            $mgr = $p['file'] && $p['file']->manager_user_id ? DB::table('users')->where('id', $p['file']->manager_user_id)->first(['first_name', 'last_name']) : null;
            $rows[] = ['user_id' => $id, 'name' => $p['name'], 'centre' => $p['centre'], 'start_date' => $p['start_date'], 'manager' => $mgr ? trim($mgr->first_name . ' ' . $mgr->last_name) : null] + $pr;
        }
        $order = ['overdue' => 0, 'due' => 1, 'on' => 2, 'no_start' => 3, 'passed' => 4, 'old' => 5];
        usort($rows, fn ($a, $b) => ($order[$a['state']] <=> $order[$b['state']]) ?: strcmp((string) $a['end_on'], (string) $b['end_on']));

        return ['rows' => $rows, 'settings' => $set];
    }

    /** hr:file-tasks: bell each person's manager N days before their probation ends (once per end date). Returns bells. */
    public static function remindProbation(int $agencyId, bool $dry = false): int
    {
        if (! Schema::hasColumn('hr_employee_files', 'probation_reminded_for')) return 0;
        $today = AgencyTime::today($agencyId);
        $set = self::settings($agencyId);
        if ($set['remind_days'] <= 0) return 0;
        [$cur] = self::population($agencyId, null);
        $bells = 0;
        foreach (self::facts($agencyId, $cur) as $id => $p) {
            $pr = self::probationOf($p, $set, $today);
            if (! in_array($pr['state'], ['due', 'on'], true) || ! $pr['end_on'] || $pr['days_left'] > $set['remind_days'] || $pr['days_left'] < 0) continue;
            if ($p['file'] && (string) ($p['file']->probation_reminded_for ?? '') === $pr['end_on']) continue;
            $to = HrTalent::managersOf($agencyId, $id);
            foreach ($to as $uid) {
                $bells++;
                if ($dry) continue;
                Notify::write(['agency_id' => $agencyId, 'user_id' => $uid, 'type' => 'hr',
                    'title' => mb_substr('🧭 Probation ends ' . ($pr['days_left'] === 0 ? 'today' : 'in ' . $pr['days_left'] . ' day' . ($pr['days_left'] === 1 ? '' : 's')) . ': ' . $p['name'], 0, 180),
                    'body' => 'Ends ' . Carbon::parse($pr['end_on'])->format('F j, Y') . '. Confirm it passed or extend it in HR.',
                    'data' => json_encode(['hash' => 'hr?tab=dashboard', 'kind' => 'team', 'probation_user_id' => $id]), 'created_at' => now()]);
            }
            if (! $dry) {
                if ($p['file']) DB::table('hr_employee_files')->where('id', $p['file']->id)->update(['probation_reminded_for' => $pr['end_on']]);
                else DB::table('hr_employee_files')->insertOrIgnore(['agency_id' => $agencyId, 'user_id' => $id, 'status' => 'active', 'probation_reminded_for' => $pr['end_on'], 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return $bells;
    }

    // ── the missing-info check ──────────────────────────────────────────────────────────────

    /**
     * Per person: what is missing. Payroll items (pay rate, deposit details, SIN, below the minimum wage) only when $pay;
     * the SIN only when $admin (the SIN rule). Contractors are never checked against the minimum wage.
     */
    public static function missing(int $agencyId, ?array $scope, bool $pay, bool $admin, string $today): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $ids = array_keys($f);
        $profiles = DB::table('user_profiles')->whereIn('user_id', $ids ?: [0])->get(['user_id', 'emergency_contact_name', 'emergency_contact_phone'])->keyBy('user_id');
        $valid = array_flip(HrTalent::managerIds($agencyId));
        $deposit = $pay ? DB::table('staff_payout_methods')->whereIn('user_id', $ids ?: [0])->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))->pluck('user_id')->map(fn ($v) => (int) $v)->flip()->all() : [];
        $sin = $admin ? DB::table('hr_staff_sin')->where('agency_id', $agencyId)->whereIn('user_id', $ids ?: [0])->pluck('user_id')->map(fn ($v) => (int) $v)->flip()->all() : [];
        $rows = []; $counts = [];
        foreach ($f as $id => $p) {
            $miss = [];
            if (! $p['start_date']) $miss[] = ['key' => 'start_date', 'label' => 'No start date'];
            $mid = $p['file'] ? (int) $p['file']->manager_user_id : 0;
            // The agency's own admins are the top of the tree: they need no manager above them.
            if ((! $mid || ! isset($valid[$mid])) && ! in_array('agency_admin', $p['roles'], true)) $miss[] = ['key' => 'manager', 'label' => 'No manager'];
            $pr = $profiles[$id] ?? null;
            if (! $pr || ! trim((string) $pr->emergency_contact_name) || ! trim((string) $pr->emergency_contact_phone)) $miss[] = ['key' => 'emergency', 'label' => 'No emergency contact'];
            if (! $p['file'] || ! $p['file']->job_title) $miss[] = ['key' => 'job_title', 'label' => 'No job title'];
            if (! $p['file'] || ! $p['file']->employment_type) $miss[] = ['key' => 'employment_type', 'label' => 'No employment type'];
            $wage = null;
            if ($pay) {
                $u = $p['user'];
                if ($u->pay_rate === null || (float) $u->pay_rate <= 0) $miss[] = ['key' => 'pay_rate', 'label' => 'No pay rate', 'payroll' => true];
                if (! isset($deposit[$id])) $miss[] = ['key' => 'deposit', 'label' => 'No pay deposit details', 'payroll' => true];
                if ($admin && ! isset($sin[$id])) $miss[] = ['key' => 'sin', 'label' => 'No SIN / SSN on file', 'payroll' => true];
                $min = self::minWage($p['region'], $today);
                $contract = (bool) $u->is_contractor || ($p['employment_type'] === 'contract');
                if ($min && $u->pay_rate !== null && (float) $u->pay_rate > 0 && ! $contract) {
                    $hourly = ($u->pay_type ?? 'hourly') === 'salary'
                        ? (($hpw = (float) ($p['file']->hours_per_week ?? 0)) > 0 ? (float) $u->pay_rate / 52 / $hpw : null) : (float) $u->pay_rate;
                    $wage = ['hourly' => $hourly !== null ? round($hourly, 2) : null, 'minimum' => $min['rate'], 'region' => $min['name']];
                    if ($hourly !== null && $hourly + 0.001 < $min['rate']) {
                        $miss[] = ['key' => 'below_min', 'label' => 'Pay below the ' . $min['name'] . ' minimum ($' . number_format($min['rate'], 2) . ')', 'payroll' => true];
                    }
                }
            }
            foreach ($miss as $m) $counts[$m['key']] = ($counts[$m['key']] ?? 0) + 1;
            $rows[] = ['user_id' => $id, 'name' => $p['name'], 'centre' => $p['centre'], 'missing' => $miss, 'wage' => $wage,
                'payroll_missing' => count(array_filter($miss, fn ($m) => ! empty($m['payroll'])))];
        }
        usort($rows, fn ($a, $b) => (count($b['missing']) <=> count($a['missing'])) ?: strcasecmp($a['name'], $b['name']));

        return ['rows' => $rows, 'counts' => $counts, 'complete' => count(array_filter($rows, fn ($r) => ! $r['missing'])), 'people' => count($rows),
            'min_wages' => self::minWageTable(), 'checks_pay' => $pay, 'checks_sin' => $admin && $pay];
    }

    // ── invites: who has not signed in yet ─────────────────────────────────────────────────────

    public static function invites(int $agencyId, ?array $scope, string $today): array
    {
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $set = self::settings($agencyId);
        $never = array_filter($f, fn ($p) => ! $p['user']->last_login_at);
        $ids = array_keys($never);
        $resent = DB::table('audit_logs')->where('entity_type', 'user')->whereIn('entity_id', $ids ?: [0])->where('action', 'user.welcome_resent')
            ->selectRaw('entity_id, MAX(created_at) at')->groupBy('entity_id')->pluck('at', 'entity_id');
        $tz = AgencyTime::tz($agencyId);
        $rows = [];
        foreach ($never as $id => $p) {
            $sentUtc = max((string) $p['user']->created_at, (string) ($resent[$id] ?? ''));
            $sent = Carbon::parse($sentUtc, 'UTC')->setTimezone($tz)->toDateString();
            $age = (int) Carbon::parse($sent)->diffInDays(Carbon::parse($today));
            $rows[] = ['user_id' => $id, 'name' => $p['name'], 'centre' => $p['centre'], 'email' => $p['user']->email, 'status' => $p['user']->status,
                'invited_on' => $sent, 'resent' => isset($resent[$id]), 'days' => $age, 'expired' => $set['invite_days'] > 0 && $age > $set['invite_days'],
                'no_email' => ! filter_var((string) $p['user']->email, FILTER_VALIDATE_EMAIL)];
        }
        usort($rows, fn ($a, $b) => $b['days'] <=> $a['days']);

        return ['rows' => $rows, 'people' => count($f), 'signed_in' => count($f) - count($rows), 'invite_days' => $set['invite_days'],
            'expired' => count(array_filter($rows, fn ($r) => $r['expired']))];
    }

    // ── everything we hold about one person (counts; the download is B4's ZIP) ───────────────────

    public static function holdings(int $agencyId, int $userId, bool $pay, bool $admin): array
    {
        $c = function (string $table, array $where, string $label, string $where2 = 'agency') use ($agencyId, $userId) {
            if (! Schema::hasTable($table)) return null;
            $q = DB::table($table);
            foreach ($where as $k => $v) $q->where($k, $v);
            if ($where2 === 'agency' && Schema::hasColumn($table, 'agency_id')) $q->where('agency_id', $agencyId);

            return ['label' => $label, 'count' => (int) $q->count()];
        };
        $u = ['user_id' => $userId];
        $items = array_values(array_filter([
            ['label' => 'Account and contact details', 'count' => 1],
            $c('hr_employee_files', $u, 'HR file (job, employment, manager, dates)'),
            $c('user_profiles', $u, 'Profile (address, emergency contact)', 'none'),
            $c('hr_documents', $u + ['archived_at' => null], 'Documents on their file'),
            $c('hr_leave_ledger', $u, 'Leave balance entries'),
            $c('time_off_requests', $u, 'Time-off requests'),
            $c('time_punches', $u, 'Clock-ins (timesheets)', 'none'),
            $c('shifts', $u, 'Scheduled shifts', 'none'),
            $c('hr_overtime_requests', $u, 'Overtime requests'),
            $c('hr_claims', $u, 'Expense and mileage claims'),
            $c('staff_certifications', $u + ['active' => 1], 'Certifications', 'none'),
            $c('hr_reviews', $u, 'Performance reviews'),
            $c('hr_goals', $u, 'Goals'),
            $c('hr_course_assignments', $u, 'Courses'),
            $c('hr_onboardings', $u, 'Onboarding checklists'),
            $c('hr_letters', $u, 'HR letters'),
            $c('hr_injuries', $u, 'Injury records (health details sealed)'),
            $pay ? $c('hr_pay_changes', $u, 'Pay and role history') : null,
            $pay ? $c('staff_payout_methods', $u, 'Pay deposit details (encrypted)', 'none') : null,
            $admin ? $c('hr_staff_sin', $u, 'SIN / SSN (encrypted, agency admins only)') : null,
            $c('hr_leavings', $u, 'Leaving records'),
            $c('policy_acknowledgements', $u, 'Policies acknowledged'),
            $c('pd_hours', $u, 'Professional development hours'),
        ]));

        return ['items' => array_values(array_filter($items, fn ($i) => $i['count'] > 0)),
            'note' => 'What KiddieTrac holds about this person in your agency. "Download all their data" gives the records themselves (no SIN, bank details or injury health details).'];
    }

    // ── the HR setup checklist (Dashboard) ───────────────────────────────────────────────────

    public static function checklist(int $agencyId, ?array $scope, bool $pay, bool $admin, string $today): array
    {
        $types = HrLeave::types($agencyId);
        $rules = (int) DB::table('hr_leave_rules')->where('agency_id', $agencyId)->count();
        $needRules = $types->filter(fn ($t) => in_array($t->accrual, ['lump', 'pay_period'], true))->count();
        $m = self::missing($agencyId, $scope, false, false, $today);
        [$cur] = self::population($agencyId, $scope);
        $f = self::facts($agencyId, $cur);
        $noApprover = 0;
        $valid = array_flip(HrTalent::managerIds($agencyId));
        foreach ($f as $id => $p) {
            $mid = $p['file'] ? (int) $p['file']->manager_user_id : 0;
            if ($mid && isset($valid[$mid]) && $mid !== $id) continue;
            // Their centre's director approves, or (no centre / an admin) another agency admin does.
            $dirs = $p['centre_id'] && CentreScope::managers($agencyId, [$p['centre_id']])->where('ra.role', 'centre_director')->where('u.id', '!=', $id)->exists();
            if (! $dirs && (! $p['centre_id'] || in_array('agency_admin', $p['roles'], true))) {
                $dirs = CentreScope::managers($agencyId, [])->where('ra.role', 'agency_admin')->where('u.id', '!=', $id)->exists();
            }
            if (! $dirs) $noApprover++;
        }
        $inv = self::invites($agencyId, $scope, $today);
        $items = [
            ['key' => 'types', 'label' => 'Add your leave types', 'done' => $types->count() > 0, 'detail' => $types->count() . ' leave type' . ($types->count() === 1 ? '' : 's'), 'tab' => 'types', 'admin' => true],
            ['key' => 'rules', 'label' => 'Set how much leave people get', 'done' => $needRules === 0 ? $types->count() > 0 : $rules > 0, 'detail' => $rules . ' entitlement rule' . ($rules === 1 ? '' : 's'), 'tab' => 'types', 'admin' => true],
            ['key' => 'files', 'label' => 'Complete everyone\'s HR file', 'done' => $m['people'] > 0 && $m['complete'] === $m['people'], 'detail' => $m['complete'] . ' of ' . $m['people'] . ' complete', 'tab' => 'reports', 'report' => 'missing'],
            ['key' => 'approvers', 'label' => 'Give everyone a manager who approves their requests', 'done' => $noApprover === 0 && count($f) > 0, 'detail' => $noApprover ? $noApprover . ' without a manager or centre director' : 'Everyone is covered', 'tab' => 'people'],
            ['key' => 'invited', 'label' => 'Invite your staff and have them sign in', 'done' => $inv['people'] > 0 && ! $inv['rows'], 'detail' => $inv['signed_in'] . ' of ' . $inv['people'] . ' have signed in', 'tab' => 'reports', 'report' => 'invites'],
        ];
        $done = count(array_filter($items, fn ($i) => $i['done']));

        return ['items' => $items, 'done' => $done, 'total' => count($items), 'pct' => (int) round(100 * $done / count($items))];
    }
}
