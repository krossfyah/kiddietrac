<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Multi-day attendance pattern per child.
 * Used by ratio compliance + tuition calc + parent-side preview.
 */
final class AttendancePatternController extends Controller
{
    public function get(Request $request, int $childId): JsonResponse
    {
        $this->assertChildAccess($request, $childId);
        $row = DB::table('attendance_patterns')->where('child_id', $childId)
            ->where(function ($q) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', now());
            })
            ->orderByDesc('effective_from')->first();
        return response()->json(['data' => $row]);
    }

    public function set(Request $request, int $childId): JsonResponse
    {
        $this->assertChildAccess($request, $childId);
        $data = $request->validate([
            'monday' => 'required|boolean',
            'tuesday' => 'required|boolean',
            'wednesday' => 'required|boolean',
            'thursday' => 'required|boolean',
            'friday' => 'required|boolean',
            'saturday' => 'required|boolean',
            'sunday' => 'required|boolean',
            'effective_from' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);
        // Close the prior open pattern
        DB::table('attendance_patterns')->where('child_id', $childId)
            ->whereNull('effective_until')
            ->update(['effective_until' => $data['effective_from'], 'updated_at' => now()]);
        $id = DB::table('attendance_patterns')->insertGetId([
            'child_id' => $childId,
            'monday' => $data['monday'], 'tuesday' => $data['tuesday'],
            'wednesday' => $data['wednesday'], 'thursday' => $data['thursday'],
            'friday' => $data['friday'], 'saturday' => $data['saturday'],
            'sunday' => $data['sunday'],
            'effective_from' => $data['effective_from'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id, 'status' => 'saved']);
    }

    /** Get an overview of every enrolled child's expected days this week. */
    public function weeklyOverview(Request $request): JsonResponse
    {
        $agencyId = (int) ($request->header('X-Active-Agency-Id')
            ?: DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->value('agency_id'));
        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('attendance_patterns as ap', function ($j) {
                $j->on('ap.child_id', '=', 'ch.id')
                  ->whereNull('ap.effective_until');
            })
            ->where('c.agency_id', $agencyId)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'c.name as centre_name',
                'ap.monday', 'ap.tuesday', 'ap.wednesday', 'ap.thursday', 'ap.friday',
                'ap.saturday', 'ap.sunday')
            ->orderBy('ch.last_name')->get();
        return response()->json(['data' => $children]);
    }

    private function assertChildAccess(Request $request, int $childId): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        if ($isStaff) return;
        // Parents on the child's family
        $family = DB::table('children')->where('id', $childId)->value('family_id');
        abort_unless($family, 404);
        $hasFamily = DB::table('guardians')->where('user_id', $u->id)->where('family_id', $family)->exists();
        abort_unless($hasFamily, 403);
    }
}
