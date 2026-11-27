<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p51 — Payroll-ready time-clock export.
 * Aggregates time_punches table into a per-staff total hours table for
 * a given pay-period range; supports CSV download.
 */
final class PayrollController extends Controller
{
    public function summary(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $from = Carbon::parse($request->query('from', \App\Support\AgencyTime::todayDate()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', \App\Support\AgencyTime::todayDate()->endOfMonth()->toDateString()))->endOfDay();

        $base = DB::table('time_punches as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'tp.centre_id')
            ->where('c.agency_id', $agencyId)
            // The caller's centres: a centre director's payroll is their own staff's (2026-10-05).
            ->whereIn('c.id', \App\Support\Visibility::centreIds((int) $agencyId, $request->user()) ?: [0])
            ->whereNotNull('tp.punched_out_at')
            ->whereBetween('tp.punched_in_at', [$from, $to]);
        $rows = (clone $base)
            ->select(
                'tp.user_id', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email',
                'c.id as centre_id', 'c.name as centre_name',
                DB::raw('SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) as total_minutes'),
                DB::raw('COUNT(tp.id) as punch_count')
            )
            ->groupBy('tp.user_id', 'u.first_name', 'u.last_name', 'u.email', 'c.id', 'c.name')
            ->orderBy('u.first_name')
            ->get();

        /* KiddieTrac HR timesheet rules (rounding / clip to shift): the same paid minutes as HR > Timesheets and the payroll
           run (App\Support\HrTime::paidMap). null = no rule on: the figures above, unchanged. */
        $paid = \App\Support\HrTime::paidSumsFor($base, 'tp', ['tp.user_id', 'c.id']);
        $rows->transform(function ($r) use ($paid) {
            $mins = (int) $r->total_minutes;
            if ($paid !== null) $mins = (int) ($paid[$r->user_id . '|' . $r->centre_id] ?? 0);
            $r->total_hours = round($mins / 60, 2);
            $r->total_minutes = $mins;
            return $r;
        });

        if ($request->query('format') === 'csv') return $this->csv($rows, $from, $to, (int) $agencyId);

        /* PAID, BUT NEVER CLOCKED IN.

           These rows come from time_punches, so a person who is paid without punching a
           clock — a home visitor paid per visit, a salaried manager, anyone on a flat
           amount — has no row at all, and their payslips have nowhere to appear. That is
           how attaching Lloydene King's documents to her account made her pay history
           vanish from this screen: she stopped being a contractor and never became a
           staff row.

           So the rows are the union of "clocked in this period" and "was paid in this
           period". The added ones carry 0 punches and 0 hours, which is true of them,
           and their documents, which is what somebody came here to see.

           After the CSV branch on purpose: that export is an hours report, and zero-hour
           rows are noise in a file whose only subject is hours. */
        $paidUserIds = DB::table('payroll_documents')
            ->where('agency_id', $agencyId)
            ->whereNotNull('user_id')
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->distinct()->pluck('user_id')->map(fn ($i) => (int) $i)->all();

        $clockedIds = $rows->pluck('user_id')->map(fn ($i) => (int) $i)->all();
        $paidOnly = array_values(array_diff($paidUserIds, $clockedIds));

        if ($paidOnly) {
            foreach (DB::table('users')->whereIn('id', $paidOnly)->whereNull('deleted_at')
                ->get(['id', 'first_name', 'last_name', 'email']) as $u) {
                $rows->push((object) [
                    'user_id' => (int) $u->id,
                    'user_name' => trim($u->first_name . ' ' . $u->last_name),
                    'user_email' => $u->email,
                    // No punches, so no centre came with them. Named by their documents.
                    'centre_id' => null,
                    'centre_name' => null,
                    'total_minutes' => 0,
                    'punch_count' => 0,
                    'total_hours' => 0.0,
                ]);
            }
            // Pushed rows land at the end; the report reads alphabetically.
            $rows = $rows->sortBy('user_name')->values();
        }
                // Whose payroll line this is. The summary is grouped by person and centre and
        // carried no role, so payroll could not be separated into educators and everyone
        // else — which is the first cut anybody makes when reading it. Lowest rank wins,
        // so somebody who is both an educator and a director shows as a director.
        $__rank = ['agency_admin' => 0, 'platform_admin' => 0, 'centre_director' => 1,
            'home_visitor' => 2, 'educator' => 3, 'auditor' => 4, 'guardian' => 5];
        $__label = ['agency_admin' => 'Admin', 'platform_admin' => 'Admin', 'centre_director' => 'Director',
            'educator' => 'Educator', 'home_visitor' => 'Home visitor', 'auditor' => 'Auditor', 'guardian' => 'Parent'];
        $__ids = $rows->pluck('user_id')->filter()->unique()->all();
        $__roleBy = [];
        if ($__ids) {
            foreach (DB::table('role_assignments')->whereIn('user_id', $__ids)->where('active', 1)
                ->get(['user_id', 'role']) as $__ra) {
                $__cur = $__roleBy[$__ra->user_id] ?? null;
                if ($__cur === null || ($__rank[$__ra->role] ?? 9) < ($__rank[$__cur] ?? 9)) {
                    $__roleBy[$__ra->user_id] = $__ra->role;
                }
            }
        }
        // Mutated in place: these rows stay objects in a Collection, because the total
        // below sums the collection and the CSV branch reads them with ->.
        $rows->each(function ($r) use ($__roleBy, $__label) {
            $raw = $__roleBy[(int) ($r->user_id ?? 0)] ?? null;
            $r->role = $__label[$raw] ?? 'Staff';
            // The split the payroll screen actually needs.
            $r->staff_group = in_array($raw, ['educator', 'home_visitor'], true) ? 'educators' : 'other';
        });

        /* THE DOCUMENTS FOR THESE PEOPLE, in the same answer.

           "How long did they work" and "what did we pay them" are the same question
           asked twice, and they used to live on two different tabs — so matching one to
           the other meant switching views and pairing names by eye. Fetched in one
           query for everyone on the report rather than per row. */
        $docsByUser = [];
        $staffIds = $rows->pluck('user_id')->filter()->map(fn ($i) => (int) $i)->all();
        if ($staffIds) {
            foreach (DB::table('payroll_documents')
                ->where('agency_id', $agencyId)
                ->whereIn('user_id', $staffIds)
                ->whereDate('period_end', '>=', $from->toDateString())
                ->whereDate('period_start', '<=', $to->toDateString())
                ->orderByDesc('period_end')
                ->get(['id', 'user_id', 'kind', 'period_start', 'period_end', 'gross', 'net',
                       'status', 'paid_at', 'issued_at', 'reference', 'payee_email']) as $d) {
                $docsByUser[(int) $d->user_id][] = [
                    'id' => (int) $d->id,
                    'kind' => (string) ($d->kind ?: 'payslip'),
                    'period_start' => $d->period_start,
                    'period_end' => $d->period_end,
                    'gross' => round((float) $d->gross, 2),
                    'net' => round((float) $d->net, 2),
                    'status' => (string) $d->status,
                    'paid_at' => $d->paid_at,
                    'reference' => $d->reference,
                    'email' => $d->payee_email,
                ];
            }
        }
        $rows->each(function ($r) use ($docsByUser) {
            $list = $docsByUser[(int) ($r->user_id ?? 0)] ?? [];
            $r->documents = $list;
            $r->paid_net = round(array_sum(array_column($list, 'net')), 2);
        });

        /* CONTRACTORS — paid, but never clocked.

           This endpoint answers "how many hours did people work", and a contractor has
           no answer to that: they hold no user account and punch no clock. They are
           payees on payroll_documents identified by name alone. Reporting them beside
           staff would put a blank punch count and zero hours against real money, so
           what is reported instead is what they were actually paid in the period.

           Keyed by name because that is the only identity they have — see
           ManualPayrollController, where a payee name is checked against payees this
           agency has already paid rather than accepted freely. */
        $contractors = DB::table('payroll_documents')
            ->where('agency_id', $agencyId)
            ->whereNull('user_id')
            ->whereNotNull('payee_name')
            ->whereDate('period_end', '>=', $from->toDateString())
            ->whereDate('period_start', '<=', $to->toDateString())
            ->orderByDesc('period_end')
            ->get(['id', 'payee_name', 'payee_email', 'kind', 'period_start', 'period_end',
                   'gross', 'net', 'status', 'paid_at', 'reference'])
            ->groupBy('payee_name')
            ->map(fn ($docs, $name) => [
                'payee_name' => (string) $name,
                'payee_email' => $docs->first()->payee_email,
                'documents_count' => $docs->count(),
                'gross' => round((float) $docs->sum('gross'), 2),
                'net' => round((float) $docs->sum('net'), 2),
                'first_period' => $docs->min('period_start'),
                'last_period' => $docs->max('period_end'),
                /* The documents themselves, so a contractor's payslip can be printed,
                   emailed and marked paid exactly like anybody else's. */
                'documents' => $docs->map(fn ($d) => [
                    'id' => (int) $d->id,
                    'kind' => (string) ($d->kind ?: 'payslip'),
                    'period_start' => $d->period_start,
                    'period_end' => $d->period_end,
                    'gross' => round((float) $d->gross, 2),
                    'net' => round((float) $d->net, 2),
                    'status' => (string) $d->status,
                    'paid_at' => $d->paid_at,
                    'reference' => $d->reference,
                    'email' => $d->payee_email,
                ])->values(),
            ])->values();

        return response()->json([
            'data' => $rows,
            'contractors' => $contractors,
            'contractors_total' => round((float) $contractors->sum('net'), 2),
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_hours' => round($rows->sum('total_hours'), 2),
        ]);
    }

    /* ── PAY PERIODS (2026-10-31, Anthony: "how do we see staff's payroll for each period?") ──────────────────
       The hours report answers "who worked what"; this answers "what did each pay period cost, and who got what".
       One row per period the payslips themselves carry (iLearn's or KiddieTrac's own), never a guessed calendar.
       Scope: the active agency only; a centre director sees payslips of their own centres' staff only. */

    /** Payslip rows the caller may see, as a query. Void documents never count toward pay. */
    private function docScope(Request $request, int $agencyId)
    {
        $q = DB::table('payroll_documents as pd')->where('pd.agency_id', $agencyId)->where('pd.status', '!=', 'void');
        $mine = \App\Support\Visibility::centreIds($agencyId, $request->user());
        $all = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
        sort($mine); sort($all);
        if ($mine !== $all) {
            // A director: documents filed to their centres, or belonging to staff who work there.
            $staff = DB::table('role_assignments')->whereIn('centre_id', $mine ?: [0])->where('active', 1)
                ->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
            $q->where(function ($w) use ($mine, $staff) {
                $w->whereIn('pd.centre_id', $mine ?: [0])->orWhereIn('pd.user_id', $staff ?: [0]);
            });
        }
        return $q;
    }

    /** "This pay period" / "Last pay period": from the agency's pay schedule when one is set, else from the
     *  periods its payslips actually cover. Null when neither exists - never an invented date. */
    private function quickPeriods(int $agencyId, $docs): array
    {
        $today = \App\Support\AgencyTime::todayDate();
        $cfg = PayrollSettingsController::read($agencyId);
        $out = ['source' => null, 'this' => null, 'last' => null];
        if (! empty($cfg['enabled']) && ! empty($cfg['anchor_date'])) {
            $cad = (string) ($cfg['cadence'] ?? 'biweekly');
            $len = $cad === 'weekly' ? 7 : ($cad === 'biweekly' ? 14 : 0);
            if ($len) {
                $anchor = Carbon::parse((string) $cfg['anchor_date'])->startOfDay();
                $diff = (int) $anchor->diffInDays($today, false);
                $steps = (int) ceil($diff / $len);
                $payday = $anchor->copy()->addDays($steps * $len);            // first payday on or after today
                $end = $payday; $start = $end->copy()->subDays($len - 1);
                $out = ['source' => 'schedule',
                    'this' => ['from' => $start->toDateString(), 'to' => $end->toDateString()],
                    'last' => ['from' => $start->copy()->subDays($len)->toDateString(), 'to' => $start->copy()->subDay()->toDateString()]];
                return $out;
            }
            if ($cad === 'monthly') {
                $out = ['source' => 'schedule',
                    'this' => ['from' => $today->copy()->startOfMonth()->toDateString(), 'to' => $today->copy()->endOfMonth()->toDateString()],
                    'last' => ['from' => $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(), 'to' => $today->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()]];
                return $out;
            }
            if ($cad === 'semimonthly') {
                $mid = $today->copy()->day(15);
                $this_ = $today->day <= 15 ? [$today->copy()->startOfMonth(), $mid] : [$mid->copy()->addDay(), $today->copy()->endOfMonth()];
                $last = $today->day <= 15
                    ? [$today->copy()->subMonthNoOverflow()->day(16), $today->copy()->subMonthNoOverflow()->endOfMonth()]
                    : [$today->copy()->startOfMonth(), $mid];
                return ['source' => 'schedule',
                    'this' => ['from' => $this_[0]->toDateString(), 'to' => $this_[1]->toDateString()],
                    'last' => ['from' => $last[0]->toDateString(), 'to' => $last[1]->toDateString()]];
            }
        }
        // From the payslips: the newest period that has started, and the one before it (educators first - the
        // payroll most people mean - then anyone).
        $periods = collect($docs)->filter(fn ($p) => $p->period_start && $p->period_start <= $today->toDateString())
            ->sortByDesc(fn ($p) => ($p->staff_group === 'educators' ? '1' : '0') . $p->period_end . $p->period_start)->values();
        $edu = $periods->where('staff_group', 'educators')->values();
        $pick = $edu->count() ? $edu : $periods;
        $seen = []; $list = [];
        foreach ($pick as $p) {
            $k = $p->period_start . '|' . $p->period_end;
            if (isset($seen[$k])) continue;
            $seen[$k] = 1; $list[] = ['from' => (string) $p->period_start, 'to' => (string) $p->period_end];
            if (count($list) === 2) break;
        }
        usort($list, fn ($a, $b) => strcmp($b['to'], $a['to']));
        return ['source' => $list ? 'payslips' : null, 'this' => $list[0] ?? null, 'last' => $list[1] ?? null];
    }

    /** GET /admin/payroll/periods?year= - one row per pay period with its totals. */
    public function periods(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);

        $base = $this->docScope($request, $agencyId);
        $years = (clone $base)->whereNotNull('pd.period_start')->selectRaw('DISTINCT YEAR(pd.period_start) y')
            ->pluck('y')->map(fn ($y) => (int) $y)->sortDesc()->values()->all();

        $q = (clone $base)->whereNotNull('pd.period_start');
        if ($y = (int) $request->query('year')) {
            $q->whereYear('pd.period_start', $y);
        }
        $rows = $q->selectRaw("pd.period_start, pd.period_end, COALESCE(pd.pay_frequency,'') pay_frequency,
                COALESCE(pd.staff_group,'other') staff_group,
                COUNT(*) docs, COUNT(DISTINCT COALESCE(pd.user_id, pd.payee_name)) people,
                ROUND(SUM(COALESCE(pd.gross,0)),2) gross,
                ROUND(SUM(COALESCE(pd.cpp,0)+COALESCE(pd.ei,0)+COALESCE(pd.income_tax,0)+COALESCE(pd.other_deductions,0)),2) deductions,
                ROUND(SUM(COALESCE(pd.vacation_pay,0)),2) vacation,
                ROUND(SUM(COALESCE(pd.net,0)),2) net,
                SUM(pd.status = 'paid') paid_docs, SUM(pd.status = 'issued') issued_docs,
                SUM(pd.net IS NULL AND pd.gross IS NULL) blank_docs,
                GROUP_CONCAT(DISTINCT COALESCE(NULLIF(pd.external_source,''), NULLIF(pd.source,''), 'kiddietrac')) sources,
                GROUP_CONCAT(DISTINCT pd.kind) kinds")
            ->groupBy('pd.period_start', 'pd.period_end', DB::raw("COALESCE(pd.pay_frequency,'')"), DB::raw("COALESCE(pd.staff_group,'other')"))
            ->orderByDesc('pd.period_end')->orderByDesc('pd.period_start')
            ->limit(500)->get();

        $rows->transform(function ($r) {
            $r->docs = (int) $r->docs; $r->people = (int) $r->people;
            $r->paid_docs = (int) $r->paid_docs; $r->issued_docs = (int) $r->issued_docs; $r->blank_docs = (int) $r->blank_docs;
            $r->gross = (float) $r->gross; $r->deductions = (float) $r->deductions; $r->vacation = (float) $r->vacation; $r->net = (float) $r->net;
            $r->status = $r->paid_docs === $r->docs ? 'paid' : ($r->paid_docs ? 'part_paid' : 'issued');
            $src = strtolower((string) $r->sources);
            $r->source = str_contains($src, 'ilearn') ? 'iLearn' : 'KiddieTrac';
            unset($r->sources);
            return $r;
        });

        $quickDocs = (clone $base)->whereNotNull('pd.period_start')->orderByDesc('pd.period_end')->limit(400)
            ->get(['pd.period_start', 'pd.period_end', 'pd.staff_group']);

        // Every payslip in scope once more, by person: names for each period row, and the figures for the cards.
        $docQ = (clone $base)->whereNotNull('pd.period_start')->leftJoin('users as u', 'u.id', '=', 'pd.user_id');
        if ($y) { $docQ->whereYear('pd.period_start', $y); }
        $docs = $docQ->get(['pd.period_start', 'pd.period_end', 'pd.pay_frequency', 'pd.staff_group', 'pd.user_id', 'pd.payee_name',
            'pd.gross', 'pd.net', 'pd.cpp', 'pd.ei', 'pd.income_tax', 'pd.other_deductions', 'pd.vacation_pay', 'pd.status',
            'u.first_name', 'u.last_name', 'u.photo_url']);
        // The linked staff account's own name first (iLearn payee names are often shortened or misspelt), else the payee name.
        $nameOf = function ($d) { return trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? '')) ?: trim((string) $d->payee_name) ?: 'Unnamed'; };
        $keyOf = function ($pStart, $pEnd, $freq, $grp) { return $pStart . '|' . $pEnd . '|' . (string) $freq . '|' . ((string) $grp ?: 'other'); };
        $staff = [];
        foreach ($docs as $d) {
            $k = $keyOf($d->period_start, $d->period_end, $d->pay_frequency ?? '', $d->staff_group ?: 'other');
            $who = $d->user_id ? 'u' . $d->user_id : 'n' . strtolower($nameOf($d));
            if (! isset($staff[$k][$who])) {
                $staff[$k][$who] = ['user_id' => $d->user_id ? (int) $d->user_id : null, 'name' => $nameOf($d), 'photo_url' => $d->photo_url, 'net' => 0.0];
            }
            $staff[$k][$who]['net'] += (float) $d->net;
        }
        $rows->transform(function ($r) use ($staff, $keyOf) {
            $list = array_values($staff[$keyOf($r->period_start, $r->period_end, $r->pay_frequency, $r->staff_group)] ?? []);
            usort($list, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
            $r->staff = array_map(function ($p) { $p['net'] = round($p['net'], 2); return $p; }, $list);
            return $r;
        });

        // By month (a payslip counts in the month its period ends): the selected year, else the last 12 months.
        $byMonth = [];
        foreach ($docs as $d) {
            $m = substr((string) $d->period_end, 0, 7);
            $b = $byMonth[$m] ?? ['month' => $m, 'gross' => 0.0, 'net' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'income_tax' => 0.0,
                'other_deductions' => 0.0, 'vacation_pay' => 0.0, 'docs' => 0, 'people' => []];
            foreach (['gross', 'net', 'cpp', 'ei', 'income_tax', 'other_deductions', 'vacation_pay'] as $f) { $b[$f] += (float) $d->{$f}; }
            $b['docs']++;
            $b['people'][$d->user_id ? 'u' . $d->user_id : 'n' . strtolower($nameOf($d))] = 1;
            $byMonth[$m] = $b;
        }
        ksort($byMonth);
        if ($y) {
            $months = [];
            for ($i = 1; $i <= 12; $i++) { $m = sprintf('%04d-%02d', $y, $i); $months[] = $byMonth[$m] ?? ['month' => $m, 'gross' => 0.0, 'net' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'income_tax' => 0.0, 'other_deductions' => 0.0, 'vacation_pay' => 0.0, 'docs' => 0, 'people' => []]; }
        } else {
            $last = $byMonth ? array_key_last($byMonth) : \App\Support\AgencyTime::todayDate()->format('Y-m');
            $end = Carbon::createFromFormat('Y-m-d', $last . '-01');
            $months = [];
            for ($i = 11; $i >= 0; $i--) { $m = $end->copy()->subMonthsNoOverflow($i)->format('Y-m'); $months[] = $byMonth[$m] ?? ['month' => $m, 'gross' => 0.0, 'net' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'income_tax' => 0.0, 'other_deductions' => 0.0, 'vacation_pay' => 0.0, 'docs' => 0, 'people' => []]; }
        }
        $months = array_map(function ($b) {
            foreach (['gross', 'net', 'cpp', 'ei', 'income_tax', 'other_deductions', 'vacation_pay'] as $f) { $b[$f] = round($b[$f], 2); }
            $b['people'] = count($b['people']);
            return $b;
        }, $months);

        $sumF = function ($f) use ($docs) { return round((float) $docs->sum(fn ($d) => (float) $d->{$f}), 2); };
        $open = $docs->filter(fn ($d) => $d->status !== 'paid');
        $per = [];
        foreach ($docs as $d) {
            $who = $d->user_id ? 'u' . $d->user_id : 'n' . strtolower($nameOf($d));
            $per[$who] = $per[$who] ?? ['user_id' => $d->user_id ? (int) $d->user_id : null, 'name' => $nameOf($d), 'photo_url' => $d->photo_url, 'net' => 0.0, 'docs' => 0];
            $per[$who]['net'] += (float) $d->net; $per[$who]['docs']++;
        }
        usort($per, fn ($a, $b) => $b['net'] <=> $a['net']);
        $top = array_map(function ($p) { $p['net'] = round($p['net'], 2); return $p; }, array_slice($per, 0, 5));

        return response()->json([
            'data' => $rows->values(),
            'years' => $years,
            'quick' => $this->quickPeriods($agencyId, $quickDocs),
            'totals' => [
                'gross' => round((float) $rows->sum('gross'), 2),
                'deductions' => round((float) $rows->sum('deductions'), 2),
                'vacation' => round((float) $rows->sum('vacation'), 2),
                'net' => round((float) $rows->sum('net'), 2),
                'docs' => (int) $rows->sum('docs'),
                'people' => count($per),
            ],
            'breakdown' => ['cpp' => $sumF('cpp'), 'ei' => $sumF('ei'), 'income_tax' => $sumF('income_tax'),
                'other_deductions' => $sumF('other_deductions'), 'vacation_pay' => $sumF('vacation_pay'), 'net' => $sumF('net'), 'gross' => $sumF('gross')],
            'months' => $months,
            'outstanding' => ['docs' => $open->count(), 'net' => round((float) $open->sum(fn ($d) => (float) $d->net), 2),
                'people' => $open->map(fn ($d) => $d->user_id ? 'u' . $d->user_id : 'n' . strtolower($nameOf($d)))->unique()->count()],
            'top' => $top,
        ]);
    }

    /** GET /admin/payroll/periods/detail?start=&end=&group=&frequency= - every payslip of one period, with the
     *  hours KiddieTrac's clock recorded for the same person and dates beside the hours the payslip paid. */
    public function periodDetail(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $start = (string) $request->query('start', '');
        $end = (string) $request->query('end', '');
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $end), 422, 'Pick a pay period.');

        $q = $this->docScope($request, $agencyId)
            ->leftJoin('users as u', 'u.id', '=', 'pd.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'pd.centre_id')
            ->whereDate('pd.period_start', $start)->whereDate('pd.period_end', $end);
        if (($g = (string) $request->query('group', '')) !== '') {
            $q->whereRaw("COALESCE(pd.staff_group,'other') = ?", [$g]);
        }
        if ($request->has('frequency')) {
            $q->whereRaw("COALESCE(pd.pay_frequency,'') = ?", [(string) $request->query('frequency', '')]);
        }
        $docs = $q->orderBy('pd.payee_name')->get([
            'pd.id', 'pd.user_id', 'pd.payee_name', 'pd.role_label', 'pd.staff_group', 'pd.kind', 'pd.reference',
            'pd.units', 'pd.unit_label', 'pd.ot_hours', 'pd.rate', 'pd.gross', 'pd.cpp', 'pd.ei', 'pd.income_tax',
            'pd.other_deductions', 'pd.vacation_pay', 'pd.net', 'pd.status', 'pd.paid_at', 'pd.issued_at',
            'pd.external_source', 'pd.source', 'c.name as centre_name', 'u.first_name', 'u.last_name', 'u.photo_url',
            'u.email as account_email', 'pd.payee_email',
        ]);
        // When each payslip was last emailed AND delivered (PayrollDocumentController::email's audit row).
        $lastSent = $docs->isEmpty() ? collect() : DB::table('audit_logs')
            ->where('action', 'payroll_document.emailed')->where('entity_type', 'payroll_document')
            ->whereIn('entity_id', $docs->pluck('id')->all())
            ->where('payload', 'like', '%"delivered":true%')
            ->groupBy('entity_id')->selectRaw('entity_id, MAX(created_at) as at')->pluck('at', 'entity_id');

        // The previous period of the same payroll (same group and frequency), for "vs last period".
        $prevQ = $this->docScope($request, $agencyId)->leftJoin('users as u', 'u.id', '=', 'pd.user_id')
            ->whereNotNull('pd.period_end')->whereDate('pd.period_end', '<', $start);
        if (($g2 = (string) $request->query('group', '')) !== '') { $prevQ->whereRaw("COALESCE(pd.staff_group,'other') = ?", [$g2]); }
        if ($request->has('frequency')) { $prevQ->whereRaw("COALESCE(pd.pay_frequency,'') = ?", [(string) $request->query('frequency', '')]); }
        $prevEnd = (clone $prevQ)->max('pd.period_end');
        $prev = [];
        $prevPeriod = null;
        if ($prevEnd) {
            $pRows = (clone $prevQ)->whereDate('pd.period_end', $prevEnd)->get(['pd.period_start', 'pd.user_id', 'pd.payee_name', 'pd.net', 'u.first_name', 'u.last_name']);
            foreach ($pRows as $pr) {
                $nm = trim(($pr->first_name ?? '') . ' ' . ($pr->last_name ?? '')) ?: trim((string) $pr->payee_name);
                $k = $pr->user_id ? 'u' . $pr->user_id : 'n' . strtolower($nm);
                $prev[$k] = ($prev[$k] ?? 0) + (float) $pr->net;
            }
            $prevPeriod = ['start' => (string) ($pRows->min('period_start') ?? $prevEnd), 'end' => (string) $prevEnd];
        }

        // Clock hours over the same dates, the same way the hours report counts them (finished punches, by
        // clock-in day, the caller's centres).
        $ids = $docs->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();
        $clock = [];
        if ($ids) {
            $from = Carbon::parse($start)->startOfDay(); $to = Carbon::parse($end)->endOfDay();
            $base = DB::table('time_punches as tp')->join('centres as c', 'c.id', '=', 'tp.centre_id')
                ->where('c.agency_id', $agencyId)
                ->whereIn('c.id', \App\Support\Visibility::centreIds($agencyId, $request->user()) ?: [0])
                ->whereIn('tp.user_id', $ids)->whereNotNull('tp.punched_out_at')
                ->whereBetween('tp.punched_in_at', [$from, $to]);
            $paid = \App\Support\HrTime::paidSumsFor($base, 'tp', ['tp.user_id']);   // HR timesheet rules (null = none on)
            foreach ((clone $base)
                ->groupBy('tp.user_id')
                ->selectRaw('tp.user_id, SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) mins, COUNT(*) punches')
                ->get() as $r) {
                $m = $paid !== null ? (int) ($paid[(string) $r->user_id] ?? 0) : (int) $r->mins;
                $clock[(int) $r->user_id] = ['hours' => round($m / 60, 2), 'punches' => (int) $r->punches];
            }
        }

        $rows = $docs->map(function ($d) use ($clock, $prev, $lastSent) {
            $isHours = in_array(strtolower((string) $d->unit_label), ['', 'h', 'hr', 'hrs', 'hour', 'hours'], true);
            $paidHours = $isHours ? round((float) $d->units + (float) $d->ot_hours, 2) : null;
            $c = $d->user_id ? ($clock[(int) $d->user_id] ?? ['hours' => 0.0, 'punches' => 0]) : null;
            $diff = ($paidHours !== null && $c !== null) ? round($paidHours - $c['hours'], 2) : null;
            $name = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? '')) ?: trim((string) $d->payee_name);
            $ded = round((float) $d->cpp + (float) $d->ei + (float) $d->income_tax + (float) $d->other_deductions, 2);
            return [
                'id' => (int) $d->id, 'user_id' => $d->user_id ? (int) $d->user_id : null, 'name' => $name,
                'first_name' => trim((string) ($d->first_name ?? '')) ?: (string) strtok($name, ' '),
                'email' => (string) ($d->account_email ?: $d->payee_email ?: ''),
                'last_sent' => $lastSent[(int) $d->id] ?? null,
                'photo_url' => $d->photo_url,
                'prev_net' => (function () use ($d, $name, $prev) { $k = $d->user_id ? 'u' . $d->user_id : 'n' . strtolower($name); return isset($prev[$k]) ? round($prev[$k], 2) : null; })(),
                'role' => $d->role_label ?: ($d->staff_group === 'educators' ? 'Educator' : 'Staff'),
                'group' => $d->staff_group ?: 'other', 'kind' => $d->kind, 'reference' => $d->reference,
                'centre' => $d->centre_name,
                'units' => $d->units !== null ? (float) $d->units : null, 'unit_label' => $d->unit_label,
                'ot_hours' => $d->ot_hours !== null ? (float) $d->ot_hours : null,
                'paid_hours' => $paidHours,
                'clock_hours' => $c ? $c['hours'] : null, 'clock_punches' => $c ? $c['punches'] : null,
                'hours_diff' => $diff, 'hours_flag' => $diff !== null && abs($diff) > 0.5,
                'rate' => $d->rate !== null ? (float) $d->rate : null,
                'gross' => $d->gross !== null ? (float) $d->gross : null,
                'cpp' => (float) $d->cpp, 'ei' => (float) $d->ei, 'income_tax' => (float) $d->income_tax,
                'other_deductions' => (float) $d->other_deductions, 'deductions' => $ded,
                'vacation_pay' => (float) $d->vacation_pay,
                'net' => $d->net !== null ? (float) $d->net : null,
                'status' => $d->status, 'paid_at' => $d->paid_at, 'issued_at' => $d->issued_at,
                'source' => str_contains(strtolower((string) ($d->external_source ?: $d->source)), 'ilearn') ? 'iLearn' : 'KiddieTrac',
            ];
        })->values();

        $sum = fn ($k) => round((float) $rows->sum($k), 2);
        return response()->json([
            'start' => $start, 'end' => $end,
            'prev_period' => $prevPeriod,
            'data' => $rows,
            'totals' => [
                'people' => $rows->count(), 'paid_hours' => $sum('paid_hours'), 'clock_hours' => $sum('clock_hours'),
                'gross' => $sum('gross'), 'cpp' => $sum('cpp'), 'ei' => $sum('ei'), 'income_tax' => $sum('income_tax'),
                'other_deductions' => $sum('other_deductions'), 'deductions' => $sum('deductions'),
                'vacation_pay' => $sum('vacation_pay'), 'net' => $sum('net'),
                'flagged' => $rows->where('hours_flag', true)->count(),
            ],
        ]);
    }

    /* ── PAYROLL REPORT (2026-10-31, Anthony: "in reports, add a payroll report") ─────────────────────────────
       Reports → Payroll report. Same payslips and the same scope as Pay periods (docScope: the active agency; a
       director sees their own centres' staff only), filtered and cut five ways: by period, by person, every payslip,
       payslip hours against clock hours, and what is issued but not yet paid. One call returns every view so the
       screen switches views without another round trip, and every export covers all filtered rows. */
    public function report(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $isDate = fn ($v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v);
        $from = (string) $request->query('from', ''); $to = (string) $request->query('to', '');

        $q = $this->docScope($request, $agencyId)
            ->leftJoin('users as u', 'u.id', '=', 'pd.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'pd.centre_id');
        // A payslip belongs to the range when its pay period overlaps it (undated payslips by their issue date).
        if ($isDate($from)) {
            $q->where(function ($w) use ($from) {
                $w->whereDate('pd.period_end', '>=', $from)
                  ->orWhere(function ($x) use ($from) { $x->whereNull('pd.period_end')->whereDate('pd.issued_at', '>=', $from); });
            });
        }
        if ($isDate($to)) {
            $q->where(function ($w) use ($to) {
                $w->whereDate('pd.period_start', '<=', $to)
                  ->orWhere(function ($x) use ($to) { $x->whereNull('pd.period_start')->whereDate('pd.issued_at', '<=', $to); });
            });
        }
        if (($g = (string) $request->query('group', '')) !== '') { $q->whereRaw("COALESCE(pd.staff_group,'other') = ?", [$g]); }
        if (($cid = (int) $request->query('centre_id', 0)) > 0) { $q->where('pd.centre_id', $cid); }
        if (($uid = (int) $request->query('user_id', 0)) > 0) { $q->where('pd.user_id', $uid); }
        if (in_array($st = (string) $request->query('status', ''), ['issued', 'paid'], true)) { $q->where('pd.status', $st); }
        $src = (string) $request->query('source', '');
        if ($src === 'ilearn') { $q->whereRaw("LOWER(COALESCE(pd.external_source, pd.source, '')) LIKE '%ilearn%'"); }
        if ($src === 'kiddietrac') { $q->whereRaw("LOWER(COALESCE(pd.external_source, pd.source, '')) NOT LIKE '%ilearn%'"); }

        $docs = $q->orderByDesc('pd.period_end')->orderBy('pd.payee_name')->get([
            'pd.id', 'pd.user_id', 'pd.payee_name', 'pd.role_label', 'pd.staff_group', 'pd.pay_frequency',
            'pd.period_start', 'pd.period_end', 'pd.units', 'pd.unit_label', 'pd.ot_hours', 'pd.rate', 'pd.gross',
            'pd.cpp', 'pd.ei', 'pd.income_tax', 'pd.other_deductions', 'pd.vacation_pay', 'pd.net', 'pd.status',
            'pd.paid_at', 'pd.issued_at', 'pd.external_source', 'pd.source', 'pd.centre_id',
            'c.name as centre_name', 'u.first_name', 'u.last_name', 'u.photo_url',
        ]);

        // Clock hours per person over each payslip's own period - one query per distinct period.
        $mine = \App\Support\Visibility::centreIds($agencyId, $request->user()) ?: [0];
        $clock = [];
        foreach ($docs->filter(fn ($d) => $d->user_id && $d->period_start && $d->period_end)->groupBy(fn ($d) => $d->period_start . '|' . $d->period_end) as $k => $grp) {
            [$ps, $pe] = explode('|', $k);
            $ids = $grp->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $base = DB::table('time_punches as tp')->join('centres as c', 'c.id', '=', 'tp.centre_id')
                ->where('c.agency_id', $agencyId)->whereIn('c.id', $mine)->whereIn('tp.user_id', $ids)
                ->whereNotNull('tp.punched_out_at')
                ->whereBetween('tp.punched_in_at', [Carbon::parse($ps)->startOfDay(), Carbon::parse($pe)->endOfDay()]);
            $paid = \App\Support\HrTime::paidSumsFor($base, 'tp', ['tp.user_id']);   // HR timesheet rules (null = none on)
            foreach ((clone $base)
                ->groupBy('tp.user_id')->selectRaw('tp.user_id, SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) mins')
                ->get() as $r) {
                $m = $paid !== null ? (int) ($paid[(string) $r->user_id] ?? 0) : (int) $r->mins;
                $clock[$k . '|' . (int) $r->user_id] = round($m / 60, 2);
            }
        }

        $rows = $docs->map(function ($d) use ($clock) {
            $name = trim(($d->first_name ?? '') . ' ' . ($d->last_name ?? '')) ?: trim((string) $d->payee_name) ?: 'Unnamed';
            $isHours = in_array(strtolower((string) $d->unit_label), ['', 'h', 'hr', 'hrs', 'hour', 'hours'], true) && $d->units !== null;
            $paidHours = $isHours ? round((float) $d->units + (float) $d->ot_hours, 2) : null;
            $ck = $d->user_id && $d->period_start ? ($clock[$d->period_start . '|' . $d->period_end . '|' . (int) $d->user_id] ?? 0.0) : null;
            $diff = ($paidHours !== null && $ck !== null) ? round($paidHours - $ck, 2) : null;
            return [
                'id' => (int) $d->id, 'user_id' => $d->user_id ? (int) $d->user_id : null, 'name' => $name, 'photo_url' => $d->photo_url,
                'role' => $d->role_label ?: ($d->staff_group === 'educators' ? 'Educator' : 'Staff'),
                'group' => $d->staff_group ?: 'other', 'frequency' => $d->pay_frequency,
                'period_start' => $d->period_start, 'period_end' => $d->period_end, 'centre' => $d->centre_name,
                'paid_hours' => $paidHours, 'clock_hours' => $ck, 'hours_diff' => $diff, 'hours_flag' => $diff !== null && abs($diff) > 0.5,
                'rate' => $d->rate !== null ? (float) $d->rate : null,
                'gross' => round((float) $d->gross, 2), 'cpp' => round((float) $d->cpp, 2), 'ei' => round((float) $d->ei, 2),
                'income_tax' => round((float) $d->income_tax, 2), 'other_deductions' => round((float) $d->other_deductions, 2),
                'deductions' => round((float) $d->cpp + (float) $d->ei + (float) $d->income_tax + (float) $d->other_deductions, 2),
                'vacation_pay' => round((float) $d->vacation_pay, 2), 'net' => round((float) $d->net, 2),
                'status' => $d->status, 'paid_at' => $d->paid_at, 'issued_at' => $d->issued_at,
                'source' => str_contains(strtolower((string) ($d->external_source ?: $d->source)), 'ilearn') ? 'iLearn' : 'KiddieTrac',
            ];
        })->values();

        $money = ['gross', 'cpp', 'ei', 'income_tax', 'other_deductions', 'deductions', 'vacation_pay', 'net'];
        $sumOf = function ($set) use ($money) { $o = []; foreach ($money as $m) { $o[$m] = round((float) collect($set)->sum($m), 2); } return $o; };

        $summary = $rows->groupBy(fn ($r) => $r['period_start'] . '|' . $r['period_end'] . '|' . $r['frequency'] . '|' . $r['group'])
            ->map(function ($set) use ($sumOf) {
                $f = $set->first();
                return ['period_start' => $f['period_start'], 'period_end' => $f['period_end'], 'frequency' => $f['frequency'], 'group' => $f['group'],
                    'payslips' => $set->count(), 'people' => $set->pluck('name')->unique()->count(),
                    'paid' => $set->where('status', 'paid')->count(), 'staff' => $set->pluck('name')->unique()->values()->all()] + $sumOf($set);
            })->sortByDesc('period_end')->values();

        $staff = $rows->groupBy(fn ($r) => $r['user_id'] ? 'u' . $r['user_id'] : 'n' . strtolower($r['name']))
            ->map(function ($set) use ($sumOf) {
                $f = $set->first();
                return ['user_id' => $f['user_id'], 'name' => $f['name'], 'photo_url' => $f['photo_url'], 'role' => $f['role'], 'centre' => $f['centre'],
                    'payslips' => $set->count(), 'outstanding' => round((float) $set->where('status', '!=', 'paid')->sum('net'), 2),
                    'last_paid' => $set->where('status', 'paid')->max('paid_at'), 'last_period_end' => $set->max('period_end')] + $sumOf($set);
            })->sortByDesc('net')->values();

        $hours = $rows->filter(fn ($r) => $r['paid_hours'] !== null || ($r['clock_hours'] ?? 0) > 0)->values();
        $outstanding = $rows->where('status', '!=', 'paid')->values();

        $months = [];
        foreach ($rows as $r) {
            $m = substr((string) ($r['period_end'] ?: $r['issued_at']), 0, 7);
            if (! $m) continue;
            $months[$m] = $months[$m] ?? ['month' => $m, 'gross' => 0.0, 'net' => 0.0, 'payslips' => 0];
            $months[$m]['gross'] += $r['gross']; $months[$m]['net'] += $r['net']; $months[$m]['payslips']++;
        }
        ksort($months);
        $months = array_values(array_map(fn ($m) => ['month' => $m['month'], 'gross' => round($m['gross'], 2), 'net' => round($m['net'], 2), 'payslips' => $m['payslips']], $months));

        // Filter choices from what the caller may see (never other centres' staff).
        $opts = $this->docScope($request, $agencyId)->leftJoin('users as u', 'u.id', '=', 'pd.user_id')
            ->get(['pd.user_id', 'pd.payee_name', 'u.first_name', 'u.last_name', 'pd.staff_group', 'u.deleted_at as u_deleted', 'u.status as u_status']);
        $person = fn ($o) => ['id' => (int) $o->user_id,
            'name' => trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')) ?: trim((string) $o->payee_name)];
        // Staff who have left keep their past pay (T4s, records), but under their own heading (support ticket #208).
        $gone = fn ($o) => $o->u_deleted !== null || $o->u_status === 'deactivated';
        $people = $opts->filter(fn ($o) => $o->user_id && ! $gone($o))->map($person)->unique('id')->sortBy('name')->values();
        $departedPeople = $opts->filter(fn ($o) => $o->user_id && $gone($o))->map($person)->unique('id')->sortBy('name')->values();
        // Open centres only (support ticket #208: closed centres were in this picker). Their past payslips still list above.
        $centres = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)->whereIn('id', $mine)->orderBy('name')->get(['id', 'name']);

        return response()->json([
            'filters' => ['from' => $from, 'to' => $to, 'people' => $people, 'departed_people' => $departedPeople, 'centres' => $centres,
                'groups' => $opts->map(fn ($o) => $o->staff_group ?: 'other')->unique()->values()],
            'totals' => $sumOf($rows) + ['payslips' => $rows->count(), 'people' => $rows->pluck('name')->unique()->count(),
                'paid' => $rows->where('status', 'paid')->count(), 'outstanding_count' => $outstanding->count(),
                'outstanding_net' => round((float) $outstanding->sum('net'), 2), 'flagged' => $rows->where('hours_flag', true)->count()],
            'months' => $months, 'summary' => $summary, 'staff' => $staff, 'detail' => $rows,
            'hours' => $hours, 'outstanding' => $outstanding,
        ]);
    }

    private function csv($rows, Carbon $from, Carbon $to, int $agencyId = 0): StreamedResponse
    {
        $name = 'payroll-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv';
        $ref = $agencyId ? \App\Support\RefCode::users(collect($rows)->pluck('user_id')->all(), $agencyId, 'S') : [];   // 2026-10-14
        return response()->streamDownload(function () use ($rows, $ref) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Staff name', 'Staff ID', 'Email', 'Centre', 'Punch count', 'Total minutes', 'Total hours']);
            foreach ($rows as $r) {
                fputcsv($h, [
                    $r->user_name, $ref[(int) $r->user_id] ?? '', $r->user_email, $r->centre_name ?? '',
                    $r->punch_count, $r->total_minutes, $r->total_hours,
                ]);
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
