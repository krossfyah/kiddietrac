<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — CACFP / Canadian child nutrition program meal-count tracking.
 * Each row = one (child, date, meal_type) served. Monthly report by tier
 * is the reimbursement-claim filing data.
 */
final class CacfpController extends Controller
{
    public function dailyRoster(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400, 'centre_id required');
        // SECURITY (v22p96): the centre must belong to the caller's active agency
        // (platform_admin: the agency they've switched into). Was an unscoped read
        // by centre_id — any caller could enumerate any centre's roster + tier.
        $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($centreAgency && $centreAgency === (int) $this->resolveAgencyId($request), 403);
        $date = Carbon::parse($request->query('date', Carbon::today()->toDateString()))->toDateString();

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('f.centre_id', $centreId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'f.cacfp_tier')
            ->orderBy('ch.last_name')->get();

        $meals = DB::table('cacfp_meals')->where('centre_id', $centreId)->where('meal_date', $date)->get();
        $byChild = $meals->groupBy('child_id');

        $rows = $children->map(function ($ch) use ($byChild) {
            $cacheMeals = $byChild->get($ch->id, collect())->keyBy('meal_type');
            return [
                'child_id' => $ch->id,
                'child_name' => $ch->first_name . ' ' . $ch->last_name,
                'cacfp_tier' => $ch->cacfp_tier,
                'breakfast' => isset($cacheMeals['breakfast']) ? (bool) $cacheMeals['breakfast']->served : false,
                'morning_snack' => isset($cacheMeals['morning_snack']) ? (bool) $cacheMeals['morning_snack']->served : false,
                'lunch' => isset($cacheMeals['lunch']) ? (bool) $cacheMeals['lunch']->served : false,
                'afternoon_snack' => isset($cacheMeals['afternoon_snack']) ? (bool) $cacheMeals['afternoon_snack']->served : false,
                'dinner' => isset($cacheMeals['dinner']) ? (bool) $cacheMeals['dinner']->served : false,
            ];
        });
        return response()->json(['date' => $date, 'centre_id' => $centreId, 'data' => $rows]);
    }

    public function setMeal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'child_id' => 'required|integer',
            'meal_date' => 'required|date',
            'meal_type' => 'required|in:breakfast,morning_snack,lunch,afternoon_snack,dinner',
            'served' => 'required|boolean',
        ]);
        $family = DB::table('families')->where('id',
            DB::table('children')->where('id', $data['child_id'])->value('family_id'))->first();
        DB::table('cacfp_meals')->updateOrInsert(
            [
                'child_id' => $data['child_id'],
                'meal_date' => $data['meal_date'],
                'meal_type' => $data['meal_type'],
            ],
            [
                'centre_id' => $data['centre_id'],
                'served' => $data['served'] ? 1 : 0,
                'eligibility_tier' => $family->cacfp_tier ?? null,
                'recorded_by_id' => $request->user()->id,
                'created_at' => now(),
            ]
        );
        return response()->json(['status' => 'saved']);
    }

    public function setFamilyTier(Request $request, int $familyId): JsonResponse
    {
        $data = $request->validate([
            'cacfp_tier' => 'required|in:free,reduced,paid',
            'eligibility_date' => 'nullable|date',
        ]);
        // SECURITY (v22p95): the subsidy tier is financial — may only be set on a
        // family whose centre is in the caller's own agency (platform_admin: any).
        $agencyId = $this->resolveAgencyId($request);
        $fam = DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)
            ->select('c.agency_id')
            ->first();
        abort_unless($fam, 404);
        $isPlatform = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->where('role', 'platform_admin')->exists();
        abort_unless($isPlatform || (int) $fam->agency_id === (int) $agencyId, 403);
        DB::table('families')->where('id', $familyId)->update([
            'cacfp_tier' => $data['cacfp_tier'],
            'cacfp_eligibility_date' => $data['eligibility_date'] ?? now()->toDateString(),
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'updated']);
    }

    public function monthlyReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rows = DB::table('cacfp_meals as cm')
            ->join('centres as c', 'c.id', '=', 'cm.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereBetween('cm.meal_date', [$start, $end])
            ->where('cm.served', 1)
            ->select('cm.meal_type', 'cm.eligibility_tier', 'c.name as centre_name',
                DB::raw('COUNT(*) as meal_count'))
            ->groupBy('cm.meal_type', 'cm.eligibility_tier', 'c.name')
            ->get();

        $totals = [
            'free' => $rows->where('eligibility_tier', 'free')->sum('meal_count'),
            'reduced' => $rows->where('eligibility_tier', 'reduced')->sum('meal_count'),
            'paid' => $rows->where('eligibility_tier', 'paid')->sum('meal_count'),
            'no_tier' => $rows->whereNull('eligibility_tier')->sum('meal_count'),
        ];
        $totals['grand_total'] = array_sum($totals);
        return response()->json(['month' => $month, 'data' => $rows, 'totals' => $totals]);
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
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
