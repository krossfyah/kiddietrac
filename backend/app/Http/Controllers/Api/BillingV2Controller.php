<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p56 — Procare-parity billing:
 *   - Vacation hold credits
 *   - Tuition increase scheduling + advance notification
 *   - Pro-rated tuition for partial months
 */
final class BillingV2Controller extends Controller
{
    // ===================================
    // Vacation holds (pause tuition)
    // ===================================
    public function listHolds(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Centre scope + live families (2026-11-27, help-writer check): the list filtered by agency only, so a centre
           director saw every centre's holds, and families that had left or whose centre had closed stayed listed. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $q = DB::table('vacation_holds as vh')
            ->join('families as f', 'f.id', '=', 'vh.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId);
        \App\Support\Live::families($q, 'f');
        \App\Support\Live::centres($q, 'c');
        if ($scope !== null) {
            $q->whereIn('f.centre_id', $scope ?: [0]);
        }
        $rows = $q->orderByDesc('vh.start_date')
            ->select('vh.*', 'f.family_name', 'c.name as centre_name')
            ->limit(200)
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function requestHold(Request $request): JsonResponse
    {
        $data = $request->validate([
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:120',
        ]);
        $u = $request->user();
        $familyId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($familyId, 403, 'No family linked');
        $id = DB::table('vacation_holds')->insertGetId([
            'family_id' => $familyId,
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Notify directors
        $family = DB::table('families')->where('id', $familyId)->first();
        $directorIds = DB::table('role_assignments')
            ->where('centre_id', $family->centre_id)
            ->whereIn('role', ['centre_director', 'agency_admin'])
            ->where('active', 1)
            ->pluck('user_id')->unique();
        foreach ($directorIds as $did) {
            \App\Support\Notify::write([
                'user_id' => $did, 'type' => 'vacation_hold',
                'title' => "Vacation hold requested by " . ($family->family_name ?? 'a family'),
                'body' => Carbon::parse($data['start_date'])->format('M j') . ' – ' . Carbon::parse($data['end_date'])->format('M j'),
                'data' => json_encode(['link' => '#vacation-holds', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    /** Staff/director adds a vacation hold on behalf of a specific family. */
    public function createForFamily(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => 'required|integer',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'reason' => 'nullable|string|max:120',
        ]);
        $family = DB::table('families')->where('id', $data['family_id'])->whereNull('deleted_at')->first();
        abort_unless($family, 404, 'Family not found');
        $agencyId = (int) DB::table('centres')->where('id', $family->centre_id)->value('agency_id');
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($q) use ($agencyId, $family) {
                $q->where('agency_id', $agencyId)->orWhere('centre_id', $family->centre_id)->orWhere('role', 'platform_admin');
            })->exists();
        abort_unless($ok, 403, 'Forbidden');
        /* Same rule as deciding a hold (2026-11-05 leak sweep): the check above accepted any role in the
           family's agency (an educator, a director of another centre) and a platform admin in any agency. */
        abort_unless($this->mayDecideForFamily($request, (int) $data['family_id']), 403, 'Forbidden');
        $id = DB::table('vacation_holds')->insertGetId([
            'family_id' => $data['family_id'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'reason' => $data['reason'] ?? null,
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $gids = DB::table('guardians')->where('family_id', $data['family_id'])->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'vacation_hold',
                'title' => 'A vacation hold was added to your account',
                'body' => Carbon::parse($data['start_date'])->format('M j') . ' – ' . Carbon::parse($data['end_date'])->format('M j'),
                'data' => json_encode(['link' => '#billing', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['id' => $id], 201);
    }

    public function decideHold(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|in:approved,denied',
            'credit_amount' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:500',
        ]);
        $hold = DB::table('vacation_holds')->where('id', $id)->first();
        abort_unless($hold, 404);
        /* The hold's family must be at a centre you direct, in this agency (2026-11-05 leak sweep): the hold was
           changed by id with no scope, so any director or admin could approve (and set the credit on) another
           centre's or another agency's vacation hold. */
        abort_unless($this->mayDecideForFamily($request, (int) $hold->family_id), 403);
        // Auto-compute credit if approved + no override given
        $credit = $data['credit_amount'] ?? null;
        if ($data['status'] === 'approved' && $credit === null) {
            $days = Carbon::parse($hold->end_date)->diffInDays(Carbon::parse($hold->start_date)) + 1;
            // estimate $30/day daily rate as fallback
            $credit = $days * 30;
        }
        DB::table('vacation_holds')->where('id', $id)->update([
            'status' => $data['status'],
            'decided_by_id' => $request->user()->id,
            'decided_at' => now(),
            'credit_amount' => $credit,
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ]);
        // Notify requester
        $gids = DB::table('guardians')->where('family_id', $hold->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'vacation_hold',
                'title' => "Vacation hold " . $data['status'],
                'body' => $data['status'] === 'approved' ? "Credit of $" . number_format((float) $credit, 2) . " will be applied." : ($data['notes'] ?? 'Unable to approve at this time.'),
                'data' => json_encode(['link' => '#billing', 'hold_id' => $id]),
                'created_at' => now(),
            ]);
        }
        return response()->json(['status' => $data['status'], 'credit_amount' => $credit]);
    }

    // ===================================
    // Tuition increase scheduling
    // ===================================
    public function listIncreases(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        /* Centre scope (2026-11-27): a director sees their own centres' increases and the agency-wide ones (they apply
           to their centre too), never another centre's. Increases for a closed centre are not listed. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $open = \App\Support\Live::centreIds($agencyId);
        $visible = $scope === null ? $open : array_values(array_intersect($open, $scope));
        $rows = DB::table('tuition_increases as ti')->leftJoin('centres as c', 'c.id', '=', 'ti.centre_id')
            ->where('ti.agency_id', $agencyId)
            ->where(fn ($w) => $w->whereNull('ti.centre_id')->orWhereIn('ti.centre_id', $visible ?: [0]))
            ->orderByDesc('ti.effective_date')->select('ti.*', 'c.name as centre_name')->get();
        return response()->json(['data' => $rows]);
    }

    public function scheduleIncrease(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'nullable|integer',
            'effective_date' => 'required|date|after:today',
            'increase_type' => 'required|in:percent,flat',
            'amount' => 'required|numeric|min:0',
            'notify_families_days_before' => 'nullable|integer|min:0|max:365',
            'notes' => 'nullable|string|max:2000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        /* Authorise the destination (2026-11-27): centre_id was written unchecked (any agency's centre), and a director
           with no centre_id created an AGENCY-WIDE increase. A director must name one of their own open centres (their
           only one is used when they have just one); an agency admin may name any open centre of this agency or none. */
        $scope = \App\Support\CentreScope::ids($request, $agencyId);
        $centreId = isset($data['centre_id']) && $data['centre_id'] ? (int) $data['centre_id'] : null;
        if ($centreId === null && $scope !== null && count($scope) === 1) {
            $centreId = (int) $scope[0];
        }
        if ($centreId !== null && ! in_array($centreId, \App\Support\Live::centreIds($agencyId), true)) {
            return response()->json(['message' => 'That centre is not open in this agency.'], 422);
        }
        if (! \App\Support\CentreScope::canWrite($scope, $centreId)) {
            return response()->json(['message' => $centreId === null ? 'Choose one of your centres for this increase.' : 'You can only schedule increases for your own centres.'], $centreId === null ? 422 : 403);
        }
        $id = DB::table('tuition_increases')->insertGetId([
            'agency_id' => $agencyId,
            'centre_id' => $centreId,
            'effective_date' => $data['effective_date'],
            'increase_type' => $data['increase_type'],
            'amount' => $data['amount'],
            'notify_families_days_before' => $data['notify_families_days_before'] ?? 60,
            'notes' => $data['notes'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    // ===================================
    // Pro-rated tuition
    // ===================================
    public function proratePreview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'base_fee' => 'required|numeric|min:0',
        ]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);
        /* The child must be at a centre you may see, in this agency (2026-11-05 leak sweep): child_id was read
           unchecked, so the preview disclosed any agency's child's enrolment date by id. */
        $agencyId = $this->resolveAgencyId($request);
        $childCentre = (int) DB::table('families')->where('id', (int) $child->family_id)->value('centre_id');
        abort_unless($childCentre
            && (int) DB::table('centres')->where('id', $childCentre)->value('agency_id') === $agencyId
            && \App\Support\CentreScope::canRead(\App\Support\CentreScope::ids($request, $agencyId), $childCentre), 403);
        $start = Carbon::parse($data['period_start']);
        $end = Carbon::parse($data['period_end']);
        $totalDays = Carbon::parse($end->copy()->startOfMonth())->daysInMonth;
        $attendStart = Carbon::parse($child->enrolled_at ?? $start)->max($start);
        $attendEnd = $end;
        $daysAttended = max(0, (int) $attendStart->diffInDays($attendEnd) + 1);
        $base = (float) $data['base_fee'];
        $prorated = round(($daysAttended / $totalDays) * $base, 2);
        return response()->json([
            'base_fee' => $base,
            'days_attended' => $daysAttended,
            'total_days' => $totalDays,
            'prorated_fee' => $prorated,
            'savings' => round($base - $prorated, 2),
        ]);
    }

    /* Agency admin / platform admin of the family's agency (the active one), or the centre director of the
       family's own centre (2026-11-05 leak sweep). */
    private function mayDecideForFamily(Request $request, int $familyId): bool
    {
        $centreId = (int) DB::table('families')->where('id', $familyId)->value('centre_id');
        if (! $centreId) return false;
        $agencyId = $this->resolveAgencyId($request);
        if ((int) DB::table('centres')->where('id', $centreId)->value('agency_id') !== $agencyId) return false;
        if (\App\Support\CentreScope::ids($request, $agencyId) === null) return true;
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where('role', 'centre_director')->where('centre_id', $centreId)->exists();
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
}
