<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — Family-facing operations:
 *   - Pickup authorizations (non-guardian people who may collect)
 *   - Daily check-in survey
 *   - Referral program
 */
final class FamilyOperationsController extends Controller
{
    use ResolvesCentreContext;

    // ===== Pickup authorizations =====
    public function listAuth(Request $request, int $childId): JsonResponse
    {
        // SECURITY (v22p94): only the child's guardians/centre staff may view who
        // is authorised to collect them.
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $rows = DB::table('pickup_authorizations')
            ->where('child_id', $childId)
            ->where('active', 1)
            ->orderBy('full_name')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function addAuth(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'full_name' => 'required|string|max:120',
            'relationship' => 'nullable|string|max:60',
            'phone' => 'nullable|string|max:40',
            'photo_id_url' => 'nullable|string|max:500',
            'expires_at' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        abort_unless($child, 404);
        // SECURITY (v22p94): CHILD SAFETY — only this child's guardians/centre
        // staff may add someone authorised to collect them.
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);
        $id = DB::table('pickup_authorizations')->insertGetId([
            'child_id' => $data['child_id'],
            'family_id' => $child->family_id,
            'full_name' => $data['full_name'],
            'relationship' => $data['relationship'] ?? null,
            'phone' => $data['phone'] ?? null,
            'photo_id_url' => $data['photo_id_url'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
            'notes' => $data['notes'] ?? null,
            'active' => 1,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function removeAuth(Request $request, int $id): JsonResponse
    {
        $row = DB::table('pickup_authorizations')->where('id', $id)->first();
        abort_unless($row, 404);
        // SECURITY (v22p94): only the child's guardians/centre staff may revoke.
        abort_unless($this->canAccessChildId($request->user(), (int) $row->child_id), 403);
        DB::table('pickup_authorizations')->where('id', $id)->update(['active' => 0, 'updated_at' => now()]);
        return response()->json(['status' => 'removed']);
    }

    // ===== Daily check-in =====
    public function todayCheckin(Request $request, int $childId): JsonResponse
    {
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $today = Carbon::now()->toDateString();
        $row = DB::table('daily_checkins')
            ->where('child_id', $childId)
            ->where('checkin_date', $today)
            ->first();
        return response()->json(['data' => $row, 'date' => $today]);
    }

    public function submitCheckin(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'mood' => 'nullable|string|in:happy,sleepy,fussy,sick,quiet',
            'sleep_quality' => 'nullable|string|in:great,okay,restless,poor',
            'ate_breakfast' => 'nullable|boolean',
            'medication_today' => 'nullable|string|max:1000',
            'parent_notes' => 'nullable|string|max:2000',
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);
        $today = Carbon::now()->toDateString();
        DB::table('daily_checkins')->updateOrInsert(
            ['child_id' => $data['child_id'], 'checkin_date' => $today],
            [
                'submitted_by_user_id' => $request->user()->id,
                'mood' => $data['mood'] ?? null,
                'sleep_quality' => $data['sleep_quality'] ?? null,
                'ate_breakfast' => $data['ate_breakfast'] ?? null,
                'medication_today' => $data['medication_today'] ?? null,
                'parent_notes' => $data['parent_notes'] ?? null,
                'created_at' => now(),
            ]
        );
        // Notify staff at the centre
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        $family = DB::table('families')->where('id', $child->family_id)->first();
        if ($family) {
            $staffIds = DB::table('role_assignments')->where('centre_id', $family->centre_id)
                ->whereIn('role', ['educator', 'centre_director'])
                ->where('active', 1)->pluck('user_id')->unique();
            foreach ($staffIds as $sid) {
                DB::table('notifications')->insert([
                    'user_id' => $sid, 'type' => 'checkin',
                    'title' => "Daily check-in: {$child->first_name}",
                    'body' => "Mood: " . ($data['mood'] ?? '—') . ' · Sleep: ' . ($data['sleep_quality'] ?? '—'),
                    'data' => json_encode(['link' => '#today', 'child_id' => $data['child_id']]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['status' => 'saved', 'date' => $today]);
    }

    public function recentCheckins(Request $request, int $childId): JsonResponse
    {
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $rows = DB::table('daily_checkins')
            ->where('child_id', $childId)
            ->orderByDesc('checkin_date')->limit(30)->get();
        return response()->json(['data' => $rows]);
    }

    // ===== Referrals =====
    public function listReferrals(Request $request): JsonResponse
    {
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        if (!$famId) {
            $agencyId = $this->resolveAgencyId($request);
            $rows = DB::table('family_referrals as fr')
                ->leftJoin('families as f', 'f.id', '=', 'fr.referrer_family_id')
                ->leftJoin('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)
                ->orderByDesc('fr.created_at')
                ->select('fr.*', 'f.family_name as referrer_name')
                ->get();
            return response()->json(['data' => $rows]);
        }
        $rows = DB::table('family_referrals')->where('referrer_family_id', $famId)
            ->orderByDesc('created_at')->get();
        return response()->json(['data' => $rows]);
    }

    public function submitReferral(Request $request): JsonResponse
    {
        $data = $request->validate(['referred_email' => 'required|email|max:160']);
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 403);
        $id = DB::table('family_referrals')->insertGetId([
            'referrer_family_id' => $famId,
            'referred_email' => $data['referred_email'],
            'status' => 'pending',
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
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
