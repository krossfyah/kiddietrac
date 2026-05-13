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

        $q = DB::table('agencies')
            ->select(
                'id', 'name', 'plan_code', 'plan_amount_cents',
                'billing_status', 'billing_starts_at', 'cancelled_at', 'created_at'
            );

        if ($status) $q->where('billing_status', $status);

        match ($sort) {
            'name'    => $q->orderBy('name'),
            'created' => $q->orderByDesc('created_at'),
            default   => $q->orderByDesc('plan_amount_cents'),
        };

        $rows = $q->limit($limit)->get()->map(fn ($a) => [
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
