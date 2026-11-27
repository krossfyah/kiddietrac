<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR DASHBOARD STATS (2026-11-27, Anthony: "HR Dashboard should have animated graphs, stats, charts").
 *
 * ONE aggregate behind Human Resources > HR dashboard (GET hr/dashboard/stats): stat tiles with a 12-month trend and the
 * change against the same point of the previous period, plus the chart series. Nothing is counted a second way:
 *  - people = HrReports::population (HrLeave::staffIds with CentreScope minus leavers; outsiders never), facts/leave
 *    dates/leave events = HrReports (B4/B5 rules), headcount on a day = the Reports' countOn rule;
 *  - absence = HrLeave::absenceDays (approved leave, working days only), lateness = B1's stamped punches with the
 *    agency grace (HrAttendance), overtime = B2's hr_overtime_requests in hours via HrTime::hours, labour cost = B8's
 *    HrRota::projected (pay viewers only), timesheets to approve = HrTime::uncovered, certificates = staff_certifications,
 *    duties = HrSafety::certFor, probation = HrReports::probationOf.
 * Scope: the caller's active agency; directors their own centres (the controller passes CentreScope ids); an optional
 * centre filter narrows inside that scope. Closed centres never show (Live::centreIds). Money only when $pay.
 */
final class HrDashboard
{
    public const PERIODS = ['month', 'quarter', 'year'];

    /** Milliseconds per section of the last build() (profiling only). */
    public static array $laps = [];
    private static float $lapAt = 0.0;

    private static function lap(string $k): void
    {
        $n = microtime(true);
        self::$laps[$k] = (int) round(($n - self::$lapAt) * 1000);
        self::$lapAt = $n;
    }

    /** [from, to, prevFrom, prevTo, label]: this period up to today, and the same span of the period before. */
    public static function periods(string $period, string $today): array
    {
        $t = Carbon::parse($today);
        $start = match ($period) {
            'quarter' => $t->copy()->firstOfQuarter(),
            'year' => $t->copy()->startOfYear(),
            default => $t->copy()->startOfMonth(),
        };
        $pStart = match ($period) {
            'quarter' => $start->copy()->subMonthsNoOverflow(3),
            'year' => $start->copy()->subYear(),
            default => $start->copy()->subMonthNoOverflow(),
        };
        $pEndCap = $start->copy()->subDay();
        $pEnd = $pStart->copy()->addDays($start->diffInDays($t));
        if ($pEnd->gt($pEndCap)) $pEnd = $pEndCap;
        $label = match ($period) { 'quarter' => 'last quarter', 'year' => 'last year', default => 'last month' };

        return [$start->toDateString(), $today, $pStart->toDateString(), $pEnd->toDateString(), $label];
    }

    private static function weekdays(string $from, string $to): int
    {
        $n = 0;
        for ($d = Carbon::parse($from), $e = Carbon::parse($to), $i = 0; $d->lte($e) && $i < 800; $d->addDay(), $i++) if ($d->isWeekday()) $n++;

        return $n;
    }

    private static function pct($a, $b, int $dp = 1): ?float
    {
        return $b > 0 ? round(100 * $a / $b, $dp) : null;
    }

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

    /**
     * @param int[]|null $scope  null = whole agency (admins), else the centre ids the caller may see (already narrowed by a centre filter)
     */
    public static function build(int $aid, ?array $scope, string $period, string $today, bool $pay, int $me, bool $dry = false): array
    {
        $t0 = microtime(true);
        self::$laps = []; self::$lapAt = $t0;
        [$from, $to, $pFrom, $pTo, $pLabel] = self::periods($period, $today);
        $months = [];
        for ($m = Carbon::parse(substr($today, 0, 7) . '-01')->subMonthsNoOverflow(11), $i = 0; $i < 12; $i++, $m->addMonthNoOverflow()) {
            $months[] = ['key' => $m->format('Y-m'), 'label' => $m->format('M'), 'year' => $m->format('Y'),
                'from' => $m->toDateString(), 'to' => min($today, $m->copy()->endOfMonth()->toDateString())];
        }
        $m12 = $months[0]['from'];
        $span = min($m12, $pFrom);   // the earliest day any figure needs
        $centreIds = $scope === null ? Live::centreIds($aid) : array_values(array_intersect($scope, Live::centreIds($aid)));

        [$cur, $lv] = HrReports::population($aid, $scope);
        $facts = HrReports::facts($aid, array_merge($cur, $lv), $lv);
        $curF = array_intersect_key($facts, array_flip($cur));
        $ids = array_keys($facts);

        // ── people: headcount, FTE, starters, leavers, turnover ──────────────────────────────────
        $ft = 40.0;
        try { if (class_exists(HrLeaveMaths::class)) $ft = (float) (HrLeaveMaths::settings($aid)['full_time_hours'] ?? 40); } catch (\Throwable $e) {}
        $fte = 0.0; $fteMissing = 0;
        foreach ($curF as $p) {
            $h = $p['file'] && $p['file']->hours_per_week !== null ? (float) $p['file']->hours_per_week : null;
            if ($h !== null && $h > 0) $fte += min(1.0, $h / $ft);
            elseif (($p['employment_type'] ?? null) === 'full_time') $fte += 1.0;
            else $fteMissing++;
        }
        [$events, $have] = HrReports::leaveEvents($aid, $ids, $span, $today);
        foreach ($facts as $id => $p) {
            if (! isset($have[$id]) && in_array($id, $lv, true) && $p['left_on'] && $p['left_on'] >= $span && $p['left_on'] <= $today) {
                $events[] = ['user_id' => $id, 'date' => $p['left_on'], 'reason_code' => null];
            }
        }
        $inR = fn (string $d, string $a, string $b) => $d >= $a && $d <= $b;
        $starters = fn (string $a, string $b) => count(array_filter($facts, fn ($p) => $p['start_date'] && $inR($p['start_date'], $a, $b)));
        $leavers = fn (string $a, string $b) => count(array_filter($events, fn ($e) => $inR($e['date'], $a, $b)));
        $turnover = function (string $a, string $b) use ($facts, $leavers) {
            $avg = (self::countOn($facts, $a) + self::countOn($facts, $b)) / 2;

            return $avg > 0 ? round(100 * $leavers($a, $b) / $avg, 1) : null;
        };
        $hcSeries = []; $mov = [];
        foreach ($months as $m) {
            $hcSeries[] = self::countOn($facts, $m['to']);
            $mov[] = ['month' => $m['key'], 'label' => $m['label'], 'starters' => $starters($m['from'], $m['to']), 'leavers' => $leavers($m['from'], $m['to']),
                'turnover' => $turnover($m['from'], $m['to'])];
        }

        self::lap('people');
        // ── composition: centre, role, employment type, length of service ─────────────────────────
        $byCentre = []; $byRole = []; $byType = [];
        $roleOrder = ['agency_admin' => 'Admins', 'centre_director' => 'Directors', 'educator' => 'Educators', 'home_visitor' => 'Home visitors'];
        $bands = ['Under 1 year' => 0, '1 to 2 years' => 0, '2 to 5 years' => 0, '5 to 10 years' => 0, '10 years or more' => 0, 'No start date' => 0];
        foreach ($curF as $p) {
            $ck = $p['centre_id'] ?: 0;
            $byCentre[$ck] ??= ['centre_id' => $p['centre_id'], 'label' => $p['centre'] ?? 'No centre', 'total' => 0];
            $byCentre[$ck]['total']++;
            $role = 'Other';
            foreach ($roleOrder as $rk => $rl) if (in_array($rk, $p['roles'], true)) { $role = $rl; break; }
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;
            $tk = $p['employment_type'] && isset(HrLeave::EMPLOYMENT[$p['employment_type']]) ? $p['employment_type'] : 'not_set';
            $byType[$tk] = ($byType[$tk] ?? 0) + 1;
            $y = $p['start_date'] ? HrLeave::serviceYears($p['start_date'], $today) : null;
            $bands[$y === null ? 'No start date' : ($y < 1 ? 'Under 1 year' : ($y < 2 ? '1 to 2 years' : ($y < 5 ? '2 to 5 years' : ($y < 10 ? '5 to 10 years' : '10 years or more'))))]++;
        }
        usort($byCentre, fn ($a, $b) => $b['total'] <=> $a['total'] ?: strcasecmp($a['label'], $b['label']));
        $roles = [];
        foreach (array_merge(array_values($roleOrder), ['Other']) as $rl) if (! empty($byRole[$rl])) $roles[] = ['label' => $rl, 'value' => $byRole[$rl]];
        $types = [];
        foreach (array_merge(array_keys(HrLeave::EMPLOYMENT), ['not_set']) as $tk) if (! empty($byType[$tk])) $types[] = ['key' => $tk, 'label' => HrLeave::EMPLOYMENT[$tk] ?? 'Not set', 'value' => $byType[$tk]];

        self::lap('composition');
        // ── absence: approved leave days by reason and month; rate = days ÷ (people × weekdays) ─────
        $abs = HrLeave::absenceDays($aid, $span, $today, $cur);
        $reasonTot = []; $absM = []; $absIn = function (string $a, string $b) use ($abs, $inR) {
            $s = 0.0;
            foreach ($abs as $d) if ($inR($d['date'], $a, $b)) $s += $d['days'];

            return $s;
        };
        foreach ($abs as $d) {
            if ($d['date'] < $m12) continue;
            $k = substr($d['date'], 0, 7);
            $absM[$k][$d['type_name']] = ($absM[$k][$d['type_name']] ?? 0) + $d['days'];
            $reasonTot[$d['type_name']] = ($reasonTot[$d['type_name']] ?? 0) + $d['days'];
        }
        arsort($reasonTot);
        $reasons = array_keys($reasonTot);
        if (count($reasons) > 6) {   // a 7th reason folds into "Other" (fixed palette order, never a generated hue)
            $keep = array_slice($reasons, 0, 5);
            foreach ($absM as $k => $row) {
                $o = 0.0;
                foreach ($row as $rn => $v) if (! in_array($rn, $keep, true)) { $o += $v; unset($absM[$k][$rn]); }
                if ($o) $absM[$k]['Other'] = $o;
            }
            $reasons = array_merge($keep, ['Other']);
        }
        $absRows = [];
        foreach ($months as $m) {
            $r = ['month' => $m['key'], 'label' => $m['label']];
            foreach ($reasons as $rn) $r[$rn] = round((float) ($absM[$m['key']][$rn] ?? 0), 2);
            $absRows[] = $r;
        }
        $rate = function (string $a, string $b) use ($absIn, $facts) {
            $wd = self::weekdays($a, $b);
            $avg = (self::countOn($facts, $a) + self::countOn($facts, $b)) / 2;

            return $wd && $avg ? round(100 * $absIn($a, $b) / ($avg * $wd), 1) : null;
        };
        $rateSeries = array_map(fn ($m) => $rate($m['from'], $m['to']), $months);

        self::lap('absence');
        // who's off: today, this week (Mon-Sun), the next 14 days (approved + pending)
        $wkStart = Carbon::parse($today)->startOfWeek(Carbon::MONDAY)->toDateString();
        $wkEnd = Carbon::parse($today)->endOfWeek(Carbon::SUNDAY)->toDateString();
        $stripEnd = Carbon::parse($today)->addDays(13)->toDateString();
        $ahead = HrLeave::absenceDays($aid, $today, max($wkEnd, $stripEnd), $cur);
        $offToday = []; $offWeek = []; $strip = [];
        for ($d = Carbon::parse($today), $i = 0; $i < 14; $i++, $d->addDay()) $strip[$d->toDateString()] = ['date' => $d->toDateString(), 'approved' => 0, 'pending' => 0, 'names' => []];
        $seen = [];
        foreach ($ahead as $d) {
            $u = $d['user_id'];
            if ($d['date'] === $today) $offToday[$u] = true;
            if ($d['date'] >= $wkStart && $d['date'] <= $wkEnd) $offWeek[$u] = true;
            if (isset($strip[$d['date']]) && empty($seen[$d['date']][$u])) {
                $seen[$d['date']][$u] = true;
                $strip[$d['date']]['approved']++;
                $strip[$d['date']]['names'][] = ($facts[$u]['name'] ?? '') . ' · ' . $d['type_name'];
            }
        }
        foreach (DB::table('time_off_requests')->where('agency_id', $aid)->where('status', 'pending')->whereIn('user_id', $cur ?: [0])
            ->whereDate('start_at', '<=', $stripEnd)->whereDate('end_at', '>=', $today)->get() as $r) {
            $a = max($today, substr((string) $r->start_at, 0, 10));
            $b = min($stripEnd, substr((string) $r->end_at, 0, 10));
            foreach (HrLeave::workDays($aid, (int) $r->user_id, $r->centre_id ? (int) $r->centre_id : null, $a, $b) as $day) {
                if (! isset($strip[$day]) || ! empty($seen[$day][(int) $r->user_id])) continue;
                $seen[$day][(int) $r->user_id] = true;
                $strip[$day]['pending']++;
                $strip[$day]['names'][] = ($facts[(int) $r->user_id]['name'] ?? '') . ' · waiting for approval';
            }
        }

        self::lap('off');
        // ── lateness (B1): clock-ins compared with the shift, late = past the grace ──────────────────
        $grace = (int) (HrAttendance::settings($aid)['lateness']['grace_minutes'] ?? 5);
        $late = ['period' => null, 'prev' => null, 'series' => array_fill(0, 12, 0), 'on_time' => null, 'minutes' => 0, 'unchecked' => 0];
        if (Schema::hasColumn('time_punches', 'late_minutes') && $cur) {
            [$ua, $ub] = AgencyTime::spanRange($aid, $span, $today);
            // Clock-ins are compared as they happen (HrAttendance::onClockIn). Old or corrected ones not compared yet are
            // stamped AFTER the response (stampRange, as the Lateness tab does) so a first view never waits seconds on an
            // agency with years of punches; the answer says how many were still waiting and the controller caches it briefly.
            $agencyCentresQ = DB::table('centres')->where('agency_id', $aid)->pluck('id')->all();
            $late['unchecked'] = DB::table('time_punches')->whereIn('centre_id', $agencyCentresQ ?: [0])->whereIn('user_id', $cur)
                ->where('punched_in_at', '>=', $ua)->where('punched_in_at', '<', $ub)
                ->where(fn ($w) => $w->whereNull('late_checked_at')->orWhereColumn('corrected_at', '>', 'late_checked_at'))->count();
            if ($late['unchecked'] && ! $dry) {
                app()->terminating(function () use ($aid, $ua, $ub, $cur) {
                    try { HrAttendance::stampRange($aid, $ua, $ub, $cur); } catch (\Throwable $e) {}
                });
            }
            $tz = AgencyTime::tz($aid);
            $agencyCentres = $agencyCentresQ;
            $cmp = ['p' => 0, 'pl' => 0, 'q' => 0, 'ql' => 0];
            $mIdx = array_flip(array_column($months, 'key'));
            foreach (DB::table('time_punches')->whereIn('centre_id', $agencyCentres ?: [0])->whereIn('user_id', $cur)
                ->where('punched_in_at', '>=', $ua)->where('punched_in_at', '<', $ub)->whereNotNull('late_shift_id')->whereNotNull('late_minutes')
                ->get(['punched_in_at', 'late_minutes']) as $p) {
                $day = Carbon::parse($p->punched_in_at, 'UTC')->setTimezone($tz)->toDateString();
                $isLate = (int) $p->late_minutes > $grace;
                if ($inR($day, $from, $to)) { $cmp['p']++; if ($isLate) { $cmp['pl']++; $late['minutes'] += (int) $p->late_minutes; } }
                if ($inR($day, $pFrom, $pTo)) { $cmp['q']++; if ($isLate) $cmp['ql']++; }
                if ($isLate && isset($mIdx[substr($day, 0, 7)])) $late['series'][$mIdx[substr($day, 0, 7)]]++;
            }
            $late['period'] = $cmp['pl']; $late['prev'] = $cmp['ql']; $late['compared'] = $cmp['p'];
            $late['on_time'] = $cmp['p'] ? (int) round(100 * ($cmp['p'] - $cmp['pl']) / $cmp['p']) : null;
        }

        self::lap('lateness');
        // ── overtime (B2): approved hours, paid vs banked, by month ─────────────────────────────────
        $ot = ['series' => [], 'period' => 0.0, 'prev' => 0.0, 'banked_period' => 0.0, 'lieu_credit' => 0.0];
        $otM = [];
        if (Schema::hasTable('hr_overtime_requests')) {
            foreach (DB::table('hr_overtime_requests')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->whereIn('status', ['approved', 'paid'])
                ->whereBetween('work_date', [$span, $today])->get(['work_date', 'minutes', 'outcome', 'bank_hours']) as $o) {
                $d = substr((string) $o->work_date, 0, 10);
                $min = (int) $o->minutes;
                $bank = $o->outcome === 'bank';
                if ($inR($d, $from, $to)) { $ot['period'] += $min; if ($bank) { $ot['banked_period'] += $min; $ot['lieu_credit'] += (float) $o->bank_hours; } }
                if ($inR($d, $pFrom, $pTo)) $ot['prev'] += $min;
                $k = substr($d, 0, 7);
                $otM[$k][$bank ? 'banked' : 'paid'] = ($otM[$k][$bank ? 'banked' : 'paid'] ?? 0) + $min;
            }
        }
        foreach ($months as $m) $ot['series'][] = ['month' => $m['key'], 'label' => $m['label'], 'paid' => HrTime::hours($otM[$m['key']]['paid'] ?? 0), 'banked' => HrTime::hours($otM[$m['key']]['banked'] ?? 0)];
        $ot['period'] = HrTime::hours($ot['period']); $ot['prev'] = HrTime::hours($ot['prev']); $ot['banked_period'] = HrTime::hours($ot['banked_period']);
        $ot['lieu_credit'] = round($ot['lieu_credit'], 2);

        self::lap('overtime');
        // ── waiting for a decision (never the caller's own) ─────────────────────────────────────────
        $others = array_values(array_diff($cur, [$me]));
        $cnt = fn (string $table, string $status) => Schema::hasTable($table)
            ? DB::table($table)->where('agency_id', $aid)->where('status', $status)->whereIn('user_id', $others ?: [0])->count() : 0;
        $pending = ['time_off' => $cnt('time_off_requests', 'pending'), 'punch_fixes' => $cnt('hr_punch_corrections', 'pending'),
            'overtime' => $cnt('hr_overtime_requests', 'submitted'), 'claims' => $cnt('hr_claims', 'submitted'),
            'shift_claims' => Schema::hasTable('hr_open_shift_claims') ? DB::table('hr_open_shift_claims as c')->join('hr_open_shifts as o', 'o.id', '=', 'c.open_shift_id')
                ->where('c.agency_id', $aid)->where('c.status', 'pending')->where('o.status', 'open')->whereIn('o.centre_id', $centreIds ?: [0])->whereIn('c.user_id', $others ?: [0])->count() : 0,
            'timesheets' => 0];
        $lastWkEnd = Carbon::parse($wkStart)->subDay()->toDateString();
        $lastWkStart = Carbon::parse($wkStart)->subDays(14)->toDateString();
        try { if (HrTime::on($aid)) $pending['timesheets'] = count(HrTime::uncovered($aid, $centreIds, $lastWkStart, $lastWkEnd)); } catch (\Throwable $e) {}
        $pending['total'] = array_sum($pending);

        self::lap('pending');
        // ── rota (B3/B8): open shifts, shifts not yet accepted ─────────────────────────────────────
        $openShifts = Schema::hasTable('hr_open_shifts') ? DB::table('hr_open_shifts')->where('agency_id', $aid)->where('status', 'open')
            ->where('shift_date', '>=', $today)->whereIn('centre_id', $centreIds ?: [0])->count() : 0;
        $unaccepted = Schema::hasTable('hr_shift_acceptances') ? DB::table('hr_shift_acceptances')->where('agency_id', $aid)->where('status', 'pending')
            ->whereIn('centre_id', $centreIds ?: [0])->whereIn('user_id', $cur ?: [0])->count() : 0;

        self::lap('rota');
        // ── compliance: certificates (latest per person + type), H&S duties ──────────────────────────
        $soon = Carbon::parse($today)->addDays(30)->toDateString();
        $best = [];
        foreach (DB::table('staff_certifications')->whereIn('user_id', $cur ?: [0])->where('active', 1)
            ->where(fn ($w) => $w->where('agency_id', $aid)->orWhereNull('agency_id'))->get(['user_id', 'cert_type', 'title', 'expires_at']) as $c) {
            $k = $c->user_id . '|' . ($c->cert_type ?: $c->title);
            $e = $c->expires_at ? substr((string) $c->expires_at, 0, 10) : '9999-12-31';
            if (! isset($best[$k]) || $e > $best[$k]['e']) $best[$k] = ['e' => $e, 'u' => (int) $c->user_id, 't' => $c->title ?: HrOpenShifts::certLabel($c->cert_type)];
        }
        $certs = ['valid' => 0, 'expiring' => 0, 'expired' => 0, 'next' => []];
        foreach ($best as $b) {
            $st = $b['e'] < $today ? 'expired' : ($b['e'] <= $soon ? 'expiring' : 'valid');
            $certs[$st]++;
            if ($st !== 'valid') $certs['next'][] = ['name' => $facts[$b['u']]['name'] ?? '', 'title' => $b['t'], 'expires' => $b['e'], 'state' => $st];
        }
        usort($certs['next'], fn ($a, $b) => strcmp($a['expires'], $b['expires']));
        $certs['next'] = array_slice($certs['next'], 0, 6);
        $duties = ['active' => 0, 'attention' => 0];
        if (Schema::hasTable('hr_safety_duties')) {
            foreach (HrSafety::activeDuties($aid, $today)->get(['user_id', 'centre_id', 'cert_type']) as $d) {
                if (! in_array((int) $d->user_id, $cur, true)) continue;
                if ($scope !== null && $d->centre_id !== null && ! in_array((int) $d->centre_id, $centreIds, true)) continue;
                $duties['active']++;
                if ($d->cert_type && in_array(HrSafety::certFor((int) $d->user_id, $d->cert_type, $today)['state'], ['soon', 'expired', 'missing'], true)) $duties['attention']++;
            }
        }

        self::lap('compliance');
        // ── probation, onboarding, courses ───────────────────────────────────────────────────────
        $set = HrReports::settings($aid);
        $prob = ['due' => 0, 'overdue' => 0, 'on' => 0];
        foreach ($curF as $p) { $s = HrReports::probationOf($p, $set, $today)['state']; if (isset($prob[$s])) $prob[$s]++; }
        $onb = ['active' => 0, 'started_period' => 0, 'started_prev' => 0];
        if (Schema::hasTable('hr_onboardings')) {
            $onb['active'] = DB::table('hr_onboardings')->where('agency_id', $aid)->where('status', 'active')->whereIn('user_id', $cur ?: [0])->count();
            foreach (DB::table('hr_onboardings')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->whereBetween('start_date', [$span, $today])->pluck('start_date') as $sd) {
                $sd = substr((string) $sd, 0, 10);
                if ($inR($sd, $from, $to)) $onb['started_period']++;
                if ($inR($sd, $pFrom, $pTo)) $onb['started_prev']++;
            }
        }
        $course = ['assigned' => 0, 'done' => 0, 'overdue' => 0, 'pct' => null, 'period' => 0, 'prev' => 0, 'series' => array_fill(0, 12, 0)];
        if (Schema::hasTable('hr_course_assignments')) {
            $tz = AgencyTime::tz($aid);
            $mIdx = array_flip(array_column($months, 'key'));
            foreach (DB::table('hr_course_assignments')->where('agency_id', $aid)->whereIn('user_id', $cur ?: [0])->get(['due_date', 'completed_at']) as $a) {
                $course['assigned']++;
                if ($a->completed_at) {
                    $course['done']++;
                    $cd = Carbon::parse($a->completed_at, 'UTC')->setTimezone($tz)->toDateString();
                    if ($inR($cd, $from, $to)) $course['period']++;
                    if ($inR($cd, $pFrom, $pTo)) $course['prev']++;
                    if (isset($mIdx[substr($cd, 0, 7)])) $course['series'][$mIdx[substr($cd, 0, 7)]]++;
                } elseif ($a->due_date && substr((string) $a->due_date, 0, 10) < $today) {
                    $course['overdue']++;
                }
            }
            $course['pct'] = self::pct($course['done'], $course['assigned'], 0);
        }

        self::lap('talent');
        // ── leave balances and use (last 12 months), per leave type ─────────────────────────────────
        $leave = [];
        $typesById = HrLeave::types($aid)->keyBy('id');
        if ($typesById->isNotEmpty() && $cur) {
            $agg = DB::table('hr_leave_ledger')->where('agency_id', $aid)->whereIn('user_id', $cur)->whereIn('leave_type_id', $typesById->keys()->all())
                ->groupBy('leave_type_id')->get(['leave_type_id', DB::raw('SUM(amount) as bal'),
                    DB::raw("SUM(CASE WHEN kind IN ('taken','returned') AND entry_date >= '" . $m12 . "' AND entry_date <= '" . $today . "' THEN amount ELSE 0 END) as used"),
                    DB::raw('COUNT(DISTINCT user_id) as people')]);
            foreach ($agg as $g) {
                $ty = $typesById[$g->leave_type_id];
                $leave[] = ['type_id' => (int) $ty->id, 'label' => $ty->name, 'unit' => $ty->unit, 'balance' => round((float) $g->bal, 2), 'used' => round(-(float) $g->used, 2), 'people' => (int) $g->people];
            }
            usort($leave, fn ($a, $b) => ($b['used'] + max(0, $b['balance'])) <=> ($a['used'] + max(0, $a['balance'])));
        }

        self::lap('leave');
        // ── labour cost vs budget this week (B8, pay viewers only) ───────────────────────────────────
        $labour = null;
        if ($pay && class_exists(HrRota::class) && HrRota::ready() && $centreIds) {
            $labour = ['cost' => 0.0, 'budget' => 0.0, 'budgeted_centres' => 0, 'hours' => 0.0, 'no_rate' => 0, 'centres' => []];
            $names = DB::table('centres')->whereIn('id', $centreIds)->pluck('name', 'id');
            foreach ($centreIds as $cid) {
                $pj = HrRota::projected((int) $cid, $wkStart, $wkEnd);
                if (! $pj['hours'] && $pj['budget'] === null) continue;
                $labour['cost'] += $pj['cost']; $labour['hours'] += $pj['hours']; $labour['no_rate'] += $pj['no_rate'];
                if ($pj['budget'] !== null) { $labour['budget'] += $pj['budget']; $labour['budgeted_centres']++; }
                $labour['centres'][] = ['centre_id' => (int) $cid, 'label' => $names[$cid] ?? '', 'cost' => $pj['cost'], 'budget' => $pj['budget'], 'hours' => $pj['hours']];
            }
            $labour['cost'] = round($labour['cost'], 2); $labour['budget'] = round($labour['budget'], 2); $labour['hours'] = round($labour['hours'], 2);
            $labour['pct'] = $labour['budget'] > 0 ? (int) round(100 * $labour['cost'] / $labour['budget']) : null;
        }

        self::lap('labour');
        $hcPrev = self::countOn($facts, $pTo);

        return [
            'period' => ['key' => $period, 'from' => $from, 'to' => $to, 'prev_from' => $pFrom, 'prev_to' => $pTo, 'prev_label' => $pLabel],
            'months' => array_map(fn ($m) => ['key' => $m['key'], 'label' => $m['label'], 'year' => $m['year']], $months),
            'week' => ['from' => $wkStart, 'to' => $wkEnd],
            'tiles' => [
                'headcount' => ['value' => count($curF), 'prev' => $hcPrev, 'series' => $hcSeries, 'fte' => round($fte, 1), 'fte_missing' => $fteMissing, 'full_time_hours' => $ft],
                'starters' => ['value' => $starters($from, $to), 'prev' => $starters($pFrom, $pTo), 'series' => array_column($mov, 'starters')],
                'leavers' => ['value' => $leavers($from, $to), 'prev' => $leavers($pFrom, $pTo), 'series' => array_column($mov, 'leavers')],
                'turnover' => ['value' => $turnover($from, $to), 'prev' => $turnover($pFrom, $pTo), 'series' => array_map(fn ($r) => $r['turnover'] ?? 0, $mov)],
                'off' => ['today' => count($offToday), 'week' => count($offWeek), 'series' => array_map(fn ($d) => $d['approved'] + $d['pending'], array_values($strip))],
                'absence' => ['value' => $rate($from, $to), 'prev' => $rate($pFrom, $pTo), 'days' => round($absIn($from, $to), 1), 'series' => array_map(fn ($v) => $v ?? 0, $rateSeries)],
                'lateness' => $late + ['grace' => $grace],
                'pending' => $pending,
                'overtime' => ['value' => $ot['period'], 'prev' => $ot['prev'], 'banked' => $ot['banked_period'], 'lieu_credit' => $ot['lieu_credit'],
                    'series' => array_map(fn ($r) => round($r['paid'] + $r['banked'], 2), $ot['series'])],
                'shifts' => ['open' => $openShifts, 'unaccepted' => $unaccepted],
                'compliance' => ['expiring' => $certs['expiring'], 'expired' => $certs['expired'], 'valid' => $certs['valid'], 'duties' => $duties['active'], 'duties_attention' => $duties['attention']],
                'probation' => $prob,
                'onboarding' => $onb,
                'courses' => $course,
                'labour' => $labour,
                'year_end' => $pay ? self::yearEndTile($aid, $today) : null,
            ],
            'charts' => [
                'headcount' => array_map(fn ($m, $v) => ['month' => $m['key'], 'label' => $m['label'], 'value' => $v], $months, $hcSeries),
                'movement' => $mov,
                'absence' => ['reasons' => $reasons, 'rows' => $absRows],
                'leave' => $leave,
                'overtime' => $ot['series'],
                'centres' => array_values($byCentre),
                'roles' => $roles,
                'types' => $types,
                'tenure' => collect($bands)->map(fn ($n, $k) => ['label' => $k, 'value' => $n])->values()->all(),
                'certs' => $certs,
                'upcoming' => array_values($strip),
            ],
            'ms' => (int) round((microtime(true) - $t0) * 1000),
        ];
    }

    /**
     * Payroll Engine P3 (2026-11-27): the T4 / T4A / RL-1 deadline (last day of February) from November 1 to March 31,
     * for pay viewers of agencies with the payroll engine on. Status: none | draft | final.
     */
    private static function yearEndTile(int $aid, string $today): ?array
    {
        try {
            if (! \App\Support\Payroll\PayrollTax::engineOn($aid) || ! \Illuminate\Support\Facades\Schema::hasTable('payroll_year_ends')) {
                return null;
            }
            $m = (int) substr($today, 5, 2);
            if ($m >= 4 && $m <= 10) {
                return null;
            }
            $y = $m <= 3 ? (int) substr($today, 0, 4) - 1 : (int) substr($today, 0, 4);
            $dl = \App\Support\Payroll\YearEnd::deadline($y);
            $st = DB::table('payroll_year_ends')->where('agency_id', $aid)->where('tax_year', $y)->value('status') ?: 'none';
            $pending = DB::table('payroll_slips')->where('agency_id', $aid)->where('tax_year', $y)->where('status', 'draft')->count();

            return ['year' => $y, 'deadline' => $dl, 'days_left' => (int) \Carbon\Carbon::parse($today)->diffInDays(\Carbon\Carbon::parse($dl), false),
                'status' => $st, 'pending' => $pending];
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
