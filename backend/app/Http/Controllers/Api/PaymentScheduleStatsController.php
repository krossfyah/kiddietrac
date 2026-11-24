<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PAYMENT SCHEDULE INSIGHTS (2026-11-02).
 *
 * Anthony: "payment schedules section add stats, pie chart, graphs".
 *
 * GET /payment-plans/stats?centre_id=&status= — one answer for the Insights panel at the top of
 * Finance → Payment schedules (screen-v22p63.js). READ ONLY: nothing here writes a row.
 *
 * SCOPE (P0): the ACTIVE agency only, resolved by ResolvesCentreContext (a platform admin must
 * have chosen one). Agency-wide for platform_admin and an agency_admin OF THIS agency; a
 * centre_director sees only the centres they direct in this agency. Anyone else gets 403.
 * The scope is decided here from role_assignments, not from the route middleware, because that
 * middleware accepts a role held in ANY agency.
 *
 * TIME: due dates are calendar dates. "Today", weeks and months are the AGENCY's (AgencyTime::tz),
 * and every bucket is built in PHP — the DB session is MST, so no SQL date functions.
 *
 * WHAT "PAID" MEANS for an instalment, in the order the schedule screen itself reads it:
 *   1. the instalment row says paid;
 *   2. it raised an invoice here → that invoice's paid part (total − balance), whole if status paid;
 *   3. it was imported (no invoice_id) → the iLearn invoice for the same family and due date
 *      (exact amount first), its amount_paid, whole if status paid. Void invoices never match.
 * Each is capped at the instalment's own amount.
 */
final class PaymentScheduleStatsController extends Controller
{
    use ResolvesCentreContext;

    private const FREQ = [
        'weekly'    => ['Weekly', 52 / 12],
        'biweekly'  => ['Every 2 weeks', 26 / 12],
        'monthly'   => ['Monthly', 1.0],
        'quarterly' => ['Quarterly', 1 / 3],
        'custom'    => ['Irregular dates', null],
        'single'    => ['One payment', null],
    ];

    private const METHOD = [
        'card' => 'Card', 'pad' => 'Bank debit (PAD)', 'interac' => 'Interac e-Transfer',
        'cheque' => 'Cheque', 'cash' => 'Cash', 'other' => 'Other',
    ];

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 401);
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        if (! $agencyId) {
            return response()->json(['message' => 'Select an agency first.'], 403);
        }

        $scope = $this->centreScope((int) $user->id, $agencyId);   // null = agency-wide, [] = nothing
        abort_if(is_array($scope) && ! $scope, 403, 'Payment schedule insights are for directors and administrators.');

        $tz = AgencyTime::tz($agencyId);
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['currency', 'plan_currency']);
        $currency = strtoupper((string) ($agency->currency ?? $agency->plan_currency ?? '')) ?: 'CAD';
        $now = Carbon::now($tz);
        $today = $now->format('Y-m-d');

        /* Centres this caller may pick from (in scope, live). */
        $centreQ = \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId);
        if (is_array($scope)) {
            $centreQ->whereIn('id', $scope);
        }
        $centreNames = $centreQ->orderBy('name')->pluck('name', 'id')->map(fn ($v) => (string) $v)->all();
        $allowedCentres = array_map('intval', array_keys($centreNames));

        $centreId = (int) $request->query('centre_id', 0);
        if ($centreId && ! in_array($centreId, $allowedCentres, true)) {
            $centreId = 0;   // an id outside the caller's scope is ignored, never honoured
        }
        $status = (string) $request->query('status', '');
        if (! in_array($status, ['', 'active', 'behind', 'completed', 'cancelled', 'running'], true)) {
            $status = '';
        }

        /* ── Plans in scope ─────────────────────────────────────────────── */
        /* Open centres and families that have not left only (App\Support\Live): a schedule left
           behind on a closed family is history, not money this agency is collecting. */
        $plans = \App\Support\Live::families(DB::table('payment_plans as p')
            ->join('families as f', 'f.id', '=', 'p.family_id'), 'f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereIn('f.centre_id', $centreId ? [$centreId] : ($allowedCentres ?: [0]))
            ->get(['p.id', 'p.family_id', 'p.status', 'p.total_amount', 'f.centre_id', 'f.autopay_enabled'])
            ->keyBy('id');
        $planIds = $plans->keys()->all();

        $insts = $planIds ? DB::table('payment_plan_installments as pi')
            ->leftJoin('invoices as i', 'i.id', '=', 'pi.invoice_id')
            ->whereIn('pi.payment_plan_id', $planIds)
            ->where('pi.status', '!=', 'cancelled')
            ->orderBy('pi.due_date')
            ->get(['pi.id', 'pi.payment_plan_id', 'pi.due_date', 'pi.amount', 'pi.status', 'pi.invoice_id',
                   'i.status as inv_status', 'i.total as inv_total', 'i.balance_due as inv_balance', 'i.invoice_number'])
            : collect();

        /* External (iLearn) invoices for imported instalments, matched as listForFamily does. */
        $famIds = $plans->pluck('family_id')->unique()->values()->all();
        $ext = $famIds ? DB::table('external_invoices')
            ->where('agency_id', $agencyId)
            ->whereIn('family_id', $famIds)
            ->whereNotIn(DB::raw('LOWER(status)'), ['void', 'voided', 'cancelled'])
            ->get(['id', 'family_id', 'status', 'due_at', 'total', 'amount_paid', 'payments'])
            ->groupBy(fn ($r) => $r->family_id . '|' . substr((string) $r->due_at, 0, 10))
            : collect();

        $rows = [];
        foreach ($insts as $r) {
            $amt = round((float) $r->amount, 2);
            $plan = $plans->get($r->payment_plan_id);
            $paid = 0.0;
            if (strtolower((string) $r->status) === 'paid') {
                $paid = $amt;
            } elseif ($r->invoice_id && $r->invoice_number) {
                $ist = strtolower((string) $r->inv_status);
                if ($ist === 'paid') {
                    $paid = $amt;
                } elseif (! in_array($ist, ['void', 'draft'], true)) {
                    $paid = max(0.0, (float) $r->inv_total - (float) $r->inv_balance);
                }
            } else {
                $cands = $ext->get($plan->family_id . '|' . substr((string) $r->due_date, 0, 10));
                if ($cands && $cands->isNotEmpty()) {
                    $hit = $cands->first(fn ($c) => abs((float) $c->total - $amt) < 0.005) ?: $cands->first();
                    $paid = strtolower((string) $hit->status) === 'paid' ? $amt : max(0.0, (float) $hit->amount_paid);
                }
            }
            $paid = min($amt, round($paid, 2));
            $rows[] = [
                'plan' => (int) $r->payment_plan_id,
                'due' => substr((string) $r->due_date, 0, 10),
                'amount' => $amt,
                'paid' => $paid,
                'open' => round(max(0.0, $amt - $paid), 2),
            ];
        }

        /* ── Per plan: status, frequency, monthly rate ─────────────────── */
        $byPlan = [];
        foreach ($rows as $x) {
            $byPlan[$x['plan']][] = $x;
        }
        $planInfo = [];
        foreach ($plans as $p) {
            $list = $byPlan[$p->id] ?? [];
            $open = array_filter($list, fn ($x) => $x['open'] > 0.005);
            $overdue = array_filter($open, fn ($x) => $x['due'] < $today);
            if (strtolower((string) $p->status) === 'cancelled') {
                $st = 'cancelled';
            } elseif (! $open) {
                $st = 'completed';
            } elseif ($overdue) {
                $st = 'behind';
            } else {
                $st = 'active';
            }
            [$freq, $rate] = $this->frequency($list);
            $planInfo[$p->id] = ['status' => $st, 'freq' => $freq, 'rate' => $rate];
        }

        /* Status filter. "running" = active + behind (what is still being collected). */
        $keep = array_filter(array_keys($planInfo), function ($id) use ($planInfo, $status) {
            $st = $planInfo[$id]['status'];
            return $status === '' || $st === $status || ($status === 'running' && in_array($st, ['active', 'behind'], true));
        });
        $keep = array_flip($keep);
        $rows = array_values(array_filter($rows, fn ($x) => isset($keep[$x['plan']])));

        /* ── KPIs ──────────────────────────────────────────────────────── */
        $counts = ['active' => 0, 'behind' => 0, 'completed' => 0, 'cancelled' => 0];
        $freqMix = [];
        $monthly = 0.0;
        $runningFams = [];
        $centreAgg = [];
        $behindFams = [];
        foreach ($keep as $id => $_) {
            $pi = $planInfo[$id];
            $p = $plans->get($id);
            $counts[$pi['status']]++;
            if (in_array($pi['status'], ['active', 'behind'], true)) {
                $runningFams[(int) $p->family_id] = true;
                $monthly += $pi['rate'];
                $f = $pi['freq'];
                $freqMix[$f] = $freqMix[$f] ?? ['count' => 0, 'monthly' => 0.0];
                $freqMix[$f]['count']++;
                $freqMix[$f]['monthly'] += $pi['rate'];
                $cid = (int) $p->centre_id;
                $centreAgg[$cid] = $centreAgg[$cid] ?? ['monthly' => 0.0, 'schedules' => 0, 'open' => 0.0];
                $centreAgg[$cid]['monthly'] += $pi['rate'];
                $centreAgg[$cid]['schedules']++;
            }
            if ($pi['status'] === 'behind') {
                $behindFams[(int) $p->family_id] = true;
            }
        }

        /* Autopay: the family card (AutopayRunner::candidates) or any guardian's own (split payers). */
        $autopay = 0;
        if ($runningFams) {
            $guardianAuto = DB::table('guardians')->whereIn('family_id', array_keys($runningFams))
                ->where('autopay_enabled', 1)->pluck('family_id')->map(fn ($v) => (int) $v)->unique()->all();
            foreach ($plans as $p) {
                if (isset($runningFams[(int) $p->family_id]) && ((int) $p->autopay_enabled === 1 || in_array((int) $p->family_id, $guardianAuto, true))) {
                    $runningFams[(int) $p->family_id] = 'auto';
                }
            }
            $autopay = count(array_filter($runningFams, fn ($v) => $v === 'auto'));
        }

        $d30 = $now->copy()->addDays(30)->format('Y-m-d');
        $m30 = $now->copy()->subDays(30)->format('Y-m-d');
        $next30 = 0.0; $next30n = 0; $overdueAmt = 0.0; $overdueN = 0; $missed30 = 0.0; $missed30n = 0; $outstanding = 0.0;
        foreach ($rows as $x) {
            if ($x['open'] <= 0.005) {
                continue;
            }
            $outstanding += $x['open'];
            if ($x['due'] >= $today && $x['due'] <= $d30) {
                $next30 += $x['open']; $next30n++;
            }
            if ($x['due'] < $today) {
                $overdueAmt += $x['open']; $overdueN++;
                if ($x['due'] >= $m30) {
                    $missed30 += $x['open']; $missed30n++;
                }
            }
            $p = $plans->get($x['plan']);
            if (isset($centreAgg[(int) $p->centre_id])) {
                $centreAgg[(int) $p->centre_id]['open'] += $x['open'];
            }
        }

        /* ── Upcoming by week (13 weeks from this Monday, agency tz) ───── */
        $wk0 = $now->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $weeks = [];
        for ($i = 0; $i < 13; $i++) {
            $s = $wk0->copy()->addWeeks($i);
            $weeks[] = ['start' => $s->format('Y-m-d'), 'end' => $s->copy()->addDays(6)->format('Y-m-d'), 'amount' => 0.0, 'count' => 0];
        }
        foreach ($rows as $x) {
            if ($x['open'] <= 0.005 || $x['due'] < $today) {
                continue;
            }
            foreach ($weeks as &$w) {
                if ($x['due'] >= $w['start'] && $x['due'] <= $w['end']) {
                    $w['amount'] += $x['open']; $w['count']++;
                    break;
                }
            }
            unset($w);
        }
        foreach ($weeks as &$w) { $w['amount'] = round($w['amount'], 2); } unset($w);

        /* ── Last 6 months: what fell due vs what of it was paid ─────── */
        $hist = [];
        $m0 = $now->copy()->startOfMonth();
        for ($i = 5; $i >= 0; $i--) {
            $k = $m0->copy()->subMonthsNoOverflow($i)->format('Y-m');
            $hist[$k] = ['month' => $k, 'scheduled' => 0.0, 'collected' => 0.0, 'count' => 0];
        }
        foreach ($rows as $x) {
            $k = substr($x['due'], 0, 7);
            if (isset($hist[$k])) {
                $hist[$k]['scheduled'] += $x['amount'];
                $hist[$k]['collected'] += $x['paid'];
                $hist[$k]['count']++;
            }
        }
        $hist = array_values(array_map(fn ($h) => array_merge($h, ['scheduled' => round($h['scheduled'], 2), 'collected' => round($h['collected'], 2)]), $hist));

        /* ── How families paid (last 6 months, by amount) ─────────────── */
        $since = $m0->copy()->subMonthsNoOverflow(5);   // agency-local first of the month, 6 months back
        $sinceUtc = $since->copy()->utc()->format('Y-m-d H:i:s');
        $methods = [];
        $failed30 = 0;
        $keptPlanIds = array_keys($keep);
        $keptInvoiceIds = $keptPlanIds ? DB::table('payment_plan_installments')
            ->whereIn('payment_plan_id', $keptPlanIds)->whereNotNull('invoice_id')
            ->pluck('invoice_id')->map(fn ($v) => (int) $v)->unique()->values()->all() : [];
        if ($keptInvoiceIds) {
            foreach (DB::table('payments')->whereIn('invoice_id', $keptInvoiceIds)
                ->where('status', 'succeeded')->where('paid_at', '>=', $sinceUtc)
                ->get(['method', 'amount']) as $pm) {
                $this->addMethod($methods, $this->methodKey((string) $pm->method), (float) $pm->amount);
            }
            $failed30 = DB::table('payments')->whereIn('invoice_id', $keptInvoiceIds)->where('status', 'failed')
                ->where('created_at', '>=', $now->copy()->subDays(30)->utc()->format('Y-m-d H:i:s'))->count();
        }
        /* Imported: the payments iLearn lists on each matched invoice (date-only, already local). */
        $keptExt = [];
        foreach ($insts as $r) {
            if (! isset($keep[(int) $r->payment_plan_id]) || $r->invoice_id) {
                continue;
            }
            $plan = $plans->get($r->payment_plan_id);
            $cands = $ext->get($plan->family_id . '|' . substr((string) $r->due_date, 0, 10));
            if ($cands && $cands->isNotEmpty()) {
                $hit = $cands->first(fn ($c) => abs((float) $c->total - (float) $r->amount) < 0.005) ?: $cands->first();
                $keptExt[$hit->id] = $hit;
            }
        }
        $sinceYmd = $since->format('Y-m-d');
        foreach ($keptExt as $e) {
            $list = json_decode((string) $e->payments, true);
            if (! is_array($list)) {
                continue;
            }
            foreach ($list as $pm) {
                $d = substr((string) ($pm['date'] ?? ''), 0, 10);
                if ($d === '' || $d < $sinceYmd) {
                    continue;
                }
                $this->addMethod($methods, $this->methodKey((string) ($pm['method'] ?? '')), (float) ($pm['amount'] ?? 0));
            }
        }
        $methodMix = [];
        foreach (self::METHOD as $k => $label) {
            if (isset($methods[$k]) && $methods[$k]['amount'] > 0.004) {
                $methodMix[] = ['key' => $k, 'label' => $label, 'amount' => round($methods[$k]['amount'], 2), 'count' => $methods[$k]['count']];
            }
        }

        $freqOut = [];
        foreach (self::FREQ as $k => [$label]) {
            if (isset($freqMix[$k])) {
                $freqOut[] = ['key' => $k, 'label' => $label, 'count' => $freqMix[$k]['count'], 'monthly' => round($freqMix[$k]['monthly'], 2)];
            }
        }

        $byCentre = [];
        foreach ($centreAgg as $cid => $a) {
            $byCentre[] = ['id' => $cid, 'name' => $centreNames[$cid] ?? ('Centre #' . $cid),
                'monthly' => round($a['monthly'], 2), 'schedules' => $a['schedules'], 'outstanding' => round($a['open'], 2)];
        }
        usort($byCentre, fn ($a, $b) => $b['monthly'] <=> $a['monthly']);

        $running = $counts['active'] + $counts['behind'];
        $famN = count($runningFams);

        /* Centres offered in the filter: in scope AND holding at least one schedule. */
        $withPlans = \App\Support\Live::families(DB::table('payment_plans as p')->join('families as f', 'f.id', '=', 'p.family_id'), 'f')
            ->whereIn('f.centre_id', $allowedCentres ?: [0])->distinct()->pluck('f.centre_id')->map(fn ($v) => (int) $v)->all();
        $centreOpts = [];
        foreach ($centreNames as $id => $name) {
            if (in_array((int) $id, $withPlans, true)) {
                $centreOpts[] = ['id' => (int) $id, 'name' => $name];
            }
        }

        return response()->json([
            'currency' => $currency,
            'timezone' => $tz,
            'today' => $today,
            'generated_at' => Carbon::now()->utc()->toIso8601String(),
            'filters' => ['centre_id' => $centreId ?: null, 'status' => $status],
            'centres' => $centreOpts,
            'scope' => $scope === null ? 'agency' : 'centres',
            'kpi' => [
                'schedules' => count($keep),
                'running' => $running,
                'active' => $counts['active'],
                'behind' => $counts['behind'],
                'completed' => $counts['completed'],
                'cancelled' => $counts['cancelled'],
                'families' => $famN,
                'behind_families' => count($behindFams),
                'monthly' => round($monthly, 2),
                'avg_per_family' => $famN ? round($monthly / $famN, 2) : null,
                'next30' => round($next30, 2),
                'next30_count' => $next30n,
                'outstanding' => round($outstanding, 2),
                'overdue' => round($overdueAmt, 2),
                'overdue_count' => $overdueN,
                'missed30' => round($missed30, 2),
                'missed30_count' => $missed30n,
                'failed30' => $failed30,
                'autopay_families' => $autopay,
                'manual_families' => $famN - $autopay,
            ],
            'status_mix' => [
                ['key' => 'active', 'label' => 'On track', 'count' => $counts['active']],
                ['key' => 'behind', 'label' => 'Behind', 'count' => $counts['behind']],
                ['key' => 'completed', 'label' => 'Completed', 'count' => $counts['completed']],
                ['key' => 'cancelled', 'label' => 'Cancelled', 'count' => $counts['cancelled']],
            ],
            'frequency_mix' => $freqOut,
            'method_mix' => $methodMix,
            'method_since' => $sinceYmd,
            'upcoming_weeks' => $weeks,
            'history' => $hist,
            'by_centre' => $byCentre,
            'not_tracked' => ['Paused schedules (a schedule is active, completed or cancelled; there is no pause)'],
        ]);
    }

    /** null = agency-wide; int[] = the centres this person directs in this agency ([] = none). */
    private function centreScope(int $userId, int $agencyId): ?array
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->get(['role', 'agency_id', 'centre_id']);
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')) {
            return null;   // resolveAgencyId already confined them to the agency they chose
        }
        if ($roles->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->agency_id === $agencyId)) {
            return null;
        }
        $mine = $roles->filter(fn ($r) => $r->role === 'centre_director' && $r->centre_id)
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! $mine) {
            return [];
        }
        return \App\Support\Live::centres(DB::table('centres'))->where('agency_id', $agencyId)
            ->whereIn('id', $mine)->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** [frequency key, normalised amount per month] from the spacing of a schedule's live instalments. */
    private function frequency(array $list): array
    {
        $n = count($list);
        if ($n === 0) {
            return ['custom', 0.0];
        }
        $avg = array_sum(array_column($list, 'amount')) / $n;
        if ($n === 1) {
            return ['single', 0.0];
        }
        $days = [];
        $dates = array_values(array_unique(array_column($list, 'due')));
        sort($dates);
        for ($i = 1; $i < count($dates); $i++) {
            $days[] = (int) round((strtotime($dates[$i] . ' 12:00:00') - strtotime($dates[$i - 1] . ' 12:00:00')) / 86400);
        }
        if (! $days) {
            return ['single', 0.0];
        }
        sort($days);
        $med = $days[intdiv(count($days), 2)];
        $key = $med <= 10 ? 'weekly' : ($med <= 17 ? 'biweekly' : (($med >= 26 && $med <= 35) ? 'monthly' : (($med >= 85 && $med <= 95) ? 'quarterly' : 'custom')));
        if ($key === 'custom') {
            /* Irregular: the schedule's total spread over the months it spans (plus one gap). */
            $span = (strtotime(end($dates)) - strtotime($dates[0])) / 86400 + $med;
            $months = max(1.0, $span / 30.4375);
            return ['custom', array_sum(array_column($list, 'amount')) / $months];
        }
        return [$key, $avg * self::FREQ[$key][1]];
    }

    private function methodKey(string $m): string
    {
        $m = strtolower($m);
        if (str_contains($m, 'interac') || str_contains($m, 'e-transfer') || str_contains($m, 'etransfer')) return 'interac';
        if (str_contains($m, 'ach') || str_contains($m, 'pad') || str_contains($m, 'eft') || str_contains($m, 'debit') || str_contains($m, 'bank')) return 'pad';
        if (str_contains($m, 'card') || str_contains($m, 'visa') || str_contains($m, 'master') || str_contains($m, 'amex') || str_contains($m, 'credit')) return 'card';
        if (str_contains($m, 'cheque') || str_contains($m, 'check')) return 'cheque';
        if (str_contains($m, 'cash')) return 'cash';
        return 'other';
    }

    private function addMethod(array &$methods, string $k, float $amt): void
    {
        $methods[$k] = $methods[$k] ?? ['amount' => 0.0, 'count' => 0];
        $methods[$k]['amount'] += $amt;
        $methods[$k]['count']++;
    }
}
