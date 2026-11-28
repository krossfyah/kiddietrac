<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v15: Platform-admin MRR / agency-billing dashboard.
 *
 * Computes:
 *   - Current MRR (sum of plan_amount_cents over active agencies)
 *   - ARR (MRR * 12)
 *   - Active / trial / past_due / cancelled agency counts
 *   - New signups this month
 *   - Churn this month (count of agencies cancelled within last 30 days)
 *   - Per-plan breakdown
 *   - 12-month MRR history (from billing_starts_at + cancelled_at)
 *   - Top N agencies by revenue
 *
 * All values are best-effort — if columns added by the v15 migration
 * don't have data yet, you'll see $0 MRR and 100% trial. That's fine;
 * the dashboard shows you the shape of the data and you populate as
 * agencies actually sign contracts.
 */
final class MrrDashboardController extends Controller
{
    /**
     * GET /api/v1/admin/mrr/overview
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->isPlatformAdmin($user)) {
            return response()->json(['message' => 'Forbidden — platform admin only'], 403);
        }

        $hasV15Cols = Schema::hasColumn('agencies', 'plan_amount_cents');

        $now = now();
        $monthStart = $now->copy()->startOfMonth();
        $thirtyDaysAgo = $now->copy()->subDays(30);

        if (! $hasV15Cols) {
            // v15 migration hasn't run yet — return a placeholder shape
            return response()->json([
                'configured' => false,
                'message'    => 'Run the v15 migration to populate billing fields on agencies.',
                'mrr_cents'  => 0,
                'arr_cents'  => 0,
                'total_agencies' => DB::table('agencies')->count(),
            ]);
        }

        // ─── Headline numbers ─────────────────────────────────────
        $mrrCents = (int) DB::table('agencies')
            ->where('billing_status', 'active')
            ->sum('plan_amount_cents');
        $arrCents = $mrrCents * 12;

        $byStatus = DB::table('agencies')
            ->select('billing_status', DB::raw('COUNT(*) as n'))
            ->groupBy('billing_status')
            ->pluck('n', 'billing_status')
            ->all();

        $statusCounts = [
            'trial'     => (int) ($byStatus['trial'] ?? 0),
            'active'    => (int) ($byStatus['active'] ?? 0),
            'past_due'  => (int) ($byStatus['past_due'] ?? 0),
            'cancelled' => (int) ($byStatus['cancelled'] ?? 0),
        ];

        // ─── This month's activity ────────────────────────────────
        $newSignups = DB::table('agencies')
            ->where('created_at', '>=', $monthStart)
            ->count();

        $cancelledThisMonth = DB::table('agencies')
            ->where('billing_status', 'cancelled')
            ->where('cancelled_at', '>=', $monthStart)
            ->count();

        $churnLast30 = DB::table('agencies')
            ->where('billing_status', 'cancelled')
            ->where('cancelled_at', '>=', $thirtyDaysAgo)
            ->count();

        // Churn rate: cancelled / (active at start of period). Use active+cancelled as denominator
        $activeAtStart = max(1, $statusCounts['active'] + $cancelledThisMonth);
        $churnRatePct = round(($cancelledThisMonth / $activeAtStart) * 100, 1);

        // ─── Per-plan breakdown ───────────────────────────────────
        $byPlan = DB::table('agencies')
            ->select(
                'plan_code',
                DB::raw('COUNT(*) as agencies'),
                DB::raw('SUM(CASE WHEN billing_status = \'active\' THEN plan_amount_cents ELSE 0 END) as mrr_cents'),
            )
            ->groupBy('plan_code')
            ->orderByDesc('mrr_cents')
            ->get()
            ->map(fn ($r) => [
                'plan_code' => $r->plan_code ?? 'free',
                'agencies'  => (int) $r->agencies,
                'mrr_cents' => (int) $r->mrr_cents,
            ])
            ->all();

        // ─── 12-month MRR history ─────────────────────────────────
        // For each month, MRR = sum of plan_amount_cents over agencies where
        //   billing_starts_at <= end-of-month AND (cancelled_at IS NULL OR cancelled_at > end-of-month)
        $history = [];
        for ($i = 11; $i >= 0; $i--) {
            $monthEnd = $now->copy()->subMonthsNoOverflow($i)->endOfMonth();
            $monthLabel = $monthEnd->format('Y-m');

            $mrrM = (int) DB::table('agencies')
                ->where('plan_amount_cents', '>', 0)
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNotNull('billing_starts_at')
                      ->where('billing_starts_at', '<=', $monthEnd);
                })
                ->where(function ($q) use ($monthEnd) {
                    $q->whereNull('cancelled_at')
                      ->orWhere('cancelled_at', '>', $monthEnd);
                })
                ->sum('plan_amount_cents');

            $history[] = ['month' => $monthLabel, 'mrr_cents' => $mrrM];
        }

        // ─── Top 10 agencies by revenue ───────────────────────────
        $topAgencies = DB::table('agencies')
            ->select('id', 'name', 'plan_code', 'plan_amount_cents', 'billing_status', 'billing_starts_at')
            ->where('billing_status', 'active')
            ->where('plan_amount_cents', '>', 0)
            ->orderByDesc('plan_amount_cents')
            ->limit(10)
            ->get()
            ->map(fn ($a) => [
                'id'                => (int) $a->id,
                'name'              => $a->name,
                'plan_code'         => $a->plan_code,
                'plan_amount_cents' => (int) $a->plan_amount_cents,
                'billing_status'    => $a->billing_status,
                'months_active'     => $this->monthsBetween($a->billing_starts_at, $now),
            ])
            ->all();

        // ─── Lifetime value approximation ─────────────────────────
        // LTV ≈ avg revenue per agency × avg lifespan (months).
        // Lifespan = avg(cancelled_at - billing_starts_at) for cancelled,
        //   or current age for still-active. This is a rough number.
        $avgArpu = $statusCounts['active'] > 0
            ? (int) round($mrrCents / $statusCounts['active'])
            : 0;

        return response()->json([
            'configured'        => true,
            'as_of'             => $now->toIso8601String(),
            'mrr_cents'         => $mrrCents,
            'arr_cents'         => $arrCents,
            'currency'          => 'CAD',
            'agencies'          => [
                'total'  => array_sum($statusCounts),
                'by_status' => $statusCounts,
            ],
            'this_month'        => [
                'new_signups'         => $newSignups,
                'cancellations'       => $cancelledThisMonth,
                'churn_last_30_days'  => $churnLast30,
                'churn_rate_pct'      => $churnRatePct,
            ],
            'arpu_cents'        => $avgArpu,
            'by_plan'           => $byPlan,
            'mrr_history_12mo'  => $history,
            'top_agencies'      => $topAgencies,
        ]);
    }

    /**
     * GET /api/v1/admin/mrr/insights — the dashboard, from what actually bills (2026-10-25).
     *
     * MRR is every live PAID licence line normalised to a month (agencies not on licences fall back to their plan
     * price). Complimentary lines are valued at list price and shown as complimentary value, trials as trial
     * pipeline - neither is MRR. Collections and receivables come from KiddieTrac's own invoices and payments.
     */
    public function insights(Request $request): JsonResponse
    {
        abort_unless($request->user() && $this->isPlatformAdmin($request->user()), 403, 'Forbidden — platform admin only');
        $tz = 'America/Toronto';
        $now = now()->setTimezone($tz);
        $today = $now->toDateString();
        $mo = fn ($cents, $interval) => \App\Support\PlatformBilling::monthlyCents((int) $cents, $interval ?: 'monthly');
        $agencies = DB::table('agencies')->whereNull('deleted_at')->get(['id', 'name', 'created_at', 'plan_amount_cents', 'billing_interval', 'billing_status', 'plan_currency'])->keyBy('id');
        $lines = DB::table('agency_licences')->get();
        $live = $lines->whereIn('status', \App\Support\Licences::LIVE);

        $byAgency = [];
        $planMix = []; $partMix = [];
        $comp = 0; $trial = 0;
        foreach ($live as $l) {
            if (! isset($agencies[$l->agency_id])) continue;
            $paidMo = $mo((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
            $listMo = (int) round((float) $l->qty * \App\Support\Licences::listCents($l, 1));
            $src = (string) $l->source;
            $a = &$byAgency[$l->agency_id];
            $a = $a ?? ['id' => (int) $l->agency_id, 'name' => $agencies[$l->agency_id]->name, 'mrr' => 0, 'comp' => 0, 'trial' => 0, 'plan' => null, 'type' => 'paid', 'renews' => null, 'auto' => true, 'lines' => 0];
            $a['lines']++;
            if ($l->kind === 'plan') { $a['plan'] = $l->name; $a['renews'] = $l->expires_at; $a['auto'] = (bool) $l->auto_renew; $a['type'] = $src === 'trial' ? 'trial' : ($src === 'comp' ? 'complimentary' : 'paid'); }
            if ($src === 'trial') { $a['trial'] += $listMo; $trial += $listMo; }
            elseif ($paidMo === 0 && $listMo > 0) { $a['comp'] += $listMo; $comp += $listMo; }
            else $a['mrr'] += $paidMo;
            $pk = $l->part_number ?: '—';
            $partMix[$pk] = $partMix[$pk] ?? ['part_number' => $pk, 'name' => $l->name, 'kind' => $l->kind, 'live' => 0, 'paying' => 0, 'mrr' => 0, 'comp' => 0];
            $partMix[$pk]['live']++;
            if ($paidMo > 0) { $partMix[$pk]['paying']++; $partMix[$pk]['mrr'] += $paidMo; } elseif ($src !== 'trial') $partMix[$pk]['comp'] += $listMo;
            unset($a);
        }
        // Agencies billed from a plan price instead of licences.
        foreach ($agencies as $ag) {
            if (isset($byAgency[$ag->id]) || \App\Support\Licences::managed((int) $ag->id)) continue;
            if ((string) $ag->billing_status !== 'active' || (int) $ag->plan_amount_cents <= 0) continue;
            $byAgency[$ag->id] = ['id' => (int) $ag->id, 'name' => $ag->name, 'mrr' => $mo((int) $ag->plan_amount_cents, $ag->billing_interval), 'comp' => 0, 'trial' => 0, 'plan' => 'Plan price', 'type' => 'paid', 'renews' => null, 'auto' => true, 'lines' => 0];
        }
        foreach ($byAgency as $id => $a) {
            $pl = $a['plan'] ?: '—';
            $planMix[$pl] = $planMix[$pl] ?? ['plan' => $pl, 'agencies' => 0, 'paying' => 0, 'mrr' => 0, 'comp' => 0, 'trial' => 0];
            $planMix[$pl]['agencies']++;
            if ($a['mrr'] > 0) $planMix[$pl]['paying']++;
            $planMix[$pl]['mrr'] += $a['mrr']; $planMix[$pl]['comp'] += $a['comp']; $planMix[$pl]['trial'] += $a['trial'];
            $c = $agencies[$id]->created_at ?? null;
            $first = $lines->where('agency_id', $id)->min('starts_at');
            $byAgency[$id]['boarded'] = $c && $first ? min(substr((string) $c, 0, 10), (string) $first) : ($first ?: ($c ? substr((string) $c, 0, 10) : null));
        }
        $mrr = array_sum(array_column($byAgency, 'mrr'));
        $paying = count(array_filter($byAgency, fn ($a) => $a['mrr'] > 0));

        // 12 months of MRR and complimentary value, from the licence lines alive at each month end.
        $history = [];
        for ($i = 11; $i >= 0; $i--) {
            $end = $now->copy()->startOfMonth()->subMonthsNoOverflow($i)->endOfMonth();
            $start = $end->copy()->startOfMonth()->toDateString(); $e = $end->toDateString();
            $m = 0; $cv = 0;
            foreach ($lines as $l) {
                if (! $l->starts_at || (string) $l->starts_at > $e) continue;
                if ($l->status === 'cancelled' && substr((string) $l->updated_at, 0, 10) <= $e) continue;
                if ($l->expires_at && (string) $l->expires_at < $start && $l->status !== 'grace') continue;
                if ((string) $l->source === 'trial') continue;
                $p = $mo((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
                if ($p > 0) $m += $p; else $cv += (int) round((float) $l->qty * \App\Support\Licences::listCents($l, 1));
            }
            if ($i === 0) { $m = max($m, $mrr); }
            $history[] = ['month' => $end->format('Y-m'), 'label' => $end->format('M'), 'mrr' => $m, 'comp' => $cv];
        }

        // Collections: invoiced vs collected, 12 months.
        $inv = DB::table('platform_invoices')->where('status', '!=', 'void')->get(['id', 'agency_id', 'amount_cents', 'amount_paid_cents', 'issued_at', 'created_at', 'due_at', 'status', 'currency']);
        $pays = DB::table('platform_invoice_payments')->whereIn('status', ['confirmed', 'refund'])->get(['amount_cents', 'paid_on', 'status']);
        $coll = [];
        for ($i = 11; $i >= 0; $i--) {
            $k = $now->copy()->startOfMonth()->subMonthsNoOverflow($i)->format('Y-m');
            $coll[$k] = ['month' => $k, 'label' => \Carbon\Carbon::parse($k . '-01')->format('M'), 'invoiced' => 0, 'collected' => 0];
        }
        foreach ($inv as $x) { $k = substr((string) ($x->issued_at ?: $x->created_at), 0, 7); if (isset($coll[$k])) $coll[$k]['invoiced'] += (int) $x->amount_cents; }
        foreach ($pays as $p) { $k = substr((string) $p->paid_on, 0, 7); if (isset($coll[$k])) $coll[$k]['collected'] += ($p->status === 'refund' ? -1 : 1) * (int) $p->amount_cents; }

        // Receivables and ageing.
        $aging = ['current' => 0, 'd1_30' => 0, 'd31_60' => 0, 'd61_90' => 0, 'd90' => 0];
        $outstanding = 0; $overdueN = 0;
        foreach ($inv as $x) {
            $bal = max(0, (int) $x->amount_cents - (int) $x->amount_paid_cents);
            if ($bal <= 0 || $x->status === 'draft') continue;
            $outstanding += $bal;
            $late = $x->due_at ? (int) \Carbon\Carbon::parse($x->due_at)->diffInDays($now->copy()->startOfDay(), false) : 0;
            if ($late <= 0) $aging['current'] += $bal; elseif ($late <= 30) $aging['d1_30'] += $bal; elseif ($late <= 60) $aging['d31_60'] += $bal; elseif ($late <= 90) $aging['d61_90'] += $bal; else $aging['d90'] += $bal;
            if ($late > 0) $overdueN++;
        }
        $thisMonth = $now->format('Y-m');

        // Movement this month.
        $newMrr = 0; $churn = 0;
        foreach ($lines as $l) {
            $p = $mo((int) round((float) $l->qty * (int) $l->unit_price_cents), $l->interval);
            if ($p <= 0) continue;
            if (substr((string) $l->starts_at, 0, 7) === $thisMonth && $l->status !== 'cancelled') $newMrr += $p;
            if ($l->status === 'cancelled' && substr((string) $l->updated_at, 0, 7) === $thisMonth) $churn += $p;
        }

        // Renewals in the next 90 days.
        $soon = array_values(array_filter($byAgency, fn ($a) => $a['renews'] && $a['renews'] >= $today && $a['renews'] <= $now->copy()->addDays(90)->toDateString()));
        usort($soon, fn ($a, $b) => strcmp($a['renews'], $b['renews']));
        $top = array_values($byAgency);
        usort($top, fn ($a, $b) => ($b['mrr'] <=> $a['mrr']) ?: ($b['comp'] <=> $a['comp']));

        return response()->json([
            'as_of' => $now->toIso8601String(), 'currency' => 'CAD',
            'kpi' => [
                'mrr' => $mrr, 'arr' => $mrr * 12, 'paying' => $paying, 'arpa' => $paying ? (int) round($mrr / $paying) : 0,
                'customers' => count($byAgency), 'trials' => count(array_filter($byAgency, fn ($a) => $a['type'] === 'trial')),
                'complimentary' => count(array_filter($byAgency, fn ($a) => $a['type'] === 'complimentary')),
                'comp_value' => $comp, 'trial_value' => $trial,
                'collected_month' => $coll[$thisMonth]['collected'] ?? 0, 'invoiced_month' => $coll[$thisMonth]['invoiced'] ?? 0,
                'outstanding' => $outstanding, 'overdue_count' => $overdueN, 'overdue' => $outstanding - $aging['current'],
                'new_mrr' => $newMrr, 'churned_mrr' => $churn, 'net_new' => $newMrr - $churn,
                'renewals_30' => count(array_filter($soon, fn ($a) => $a['renews'] <= $now->copy()->addDays(30)->toDateString())),
            ],
            'history' => $history,
            'collections' => array_values($coll),
            'aging' => $aging,
            'plans' => array_values($planMix),
            'parts' => array_values($partMix),
            'renewals' => array_slice($soon, 0, 12),
            'top' => array_slice($top, 0, 12),
        ]);
    }

    /**
     * GET /api/v1/admin/mrr/agencies
     * Paginated list of agencies with billing details.
     */
    public function agencyList(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $this->isPlatformAdmin($user)) {
            return response()->json(['message' => 'Forbidden — platform admin only'], 403);
        }

        $status = $request->query('status'); // 'active', 'trial', 'past_due', 'cancelled', or null = all
        $sort   = $request->query('sort', 'mrr');   // mrr | name | created
        $limit  = (int) $request->query('limit', 50);
        $limit  = max(1, min($limit, 200));
        /* PAGES + SEARCH (2026-11-27, help sweep): the Feature flags picker asked for limit=200 and anything past
           the 200th agency was silently missing. ?offset= pages through, ?q= searches the name or #id, and `total`
           says how many match so a caller knows whether there is more. */
        $offset = max(0, (int) $request->query('offset', 0));
        $search = trim((string) $request->query('q', ''));

        $q = DB::table('agencies')
            ->select(
                'id', 'name', 'plan_code', 'plan_amount_cents',
                'billing_status', 'billing_starts_at', 'cancelled_at', 'created_at'
            );

        if ($status) $q->where('billing_status', $status);
        if ($search !== '') {
            $term = ltrim($search, '#');
            $q->where(function ($w) use ($search, $term) {
                $w->where('name', 'like', '%' . addcslashes($search, '%_\\') . '%');
                if (ctype_digit($term)) {
                    $w->orWhere('id', (int) $term);
                }
            });
        }
        $total = (clone $q)->count();

        match ($sort) {
            'name'    => $q->orderBy('name'),
            'created' => $q->orderByDesc('created_at'),
            default   => $q->orderByDesc('plan_amount_cents'),
        };

        $rows = $q->orderBy('id')->offset($offset)->limit($limit)->get()->map(fn ($a) => [
            'id'                => (int) $a->id,
            'name'              => $a->name,
            'plan_code'         => $a->plan_code ?? 'free',
            'plan_amount_cents' => (int) ($a->plan_amount_cents ?? 0),
            'billing_status'    => $a->billing_status ?? 'trial',
            'billing_starts_at' => $a->billing_starts_at,
            'cancelled_at'      => $a->cancelled_at,
            'created_at'        => $a->created_at,
        ]);

        return response()->json([
            'count'    => $rows->count(),
            'total'    => $total,
            'offset'   => $offset,
            'agencies' => $rows,
        ]);
    }

    private function monthsBetween($startDate, $now): int
    {
        if (! $startDate) return 0;
        try {
            $s = \Carbon\Carbon::parse($startDate);
            return max(0, (int) $s->diffInMonths($now));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    private function isPlatformAdmin($user): bool
    {
        if (($user->primary_role ?? null) === 'platform_admin') return true;
        $superId = (int) env('PLATFORM_ADMIN_USER_ID', 1);
        if ($superId && (int) $user->id === $superId) return true;
        return false;
    }
}
