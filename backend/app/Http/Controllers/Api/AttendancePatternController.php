<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v22p59 — Multi-day attendance pattern per child.
 * Used by ratio compliance + tuition calc + parent-side preview.
 */
final class AttendancePatternController extends Controller
{
    use ResolvesCentreContext;

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
        // A day is a ROTATION now, not a yes/no: full day, mornings, afternoons,
        // before school, after school, or before and after. Booleans are still
        // accepted so an older client (and the APK, which updates on its own
        // schedule) keeps working — true becomes a full day.
        $rot = 'nullable|in:' . implode(',', self::ROTATIONS);
        $data = $request->validate([
            'monday' => $rot, 'tuesday' => $rot, 'wednesday' => $rot,
            'thursday' => $rot, 'friday' => $rot, 'saturday' => $rot, 'sunday' => $rot,
            'room_id' => 'nullable|integer',
            'active' => 'nullable|boolean',
            'effective_from' => 'required|date',
            'notes' => 'nullable|string|max:500',
        ]);
        foreach (self::DAYS as $d) {
            $data[$d] = $this->normaliseRotation($request->input($d));
        }
        // Close the prior open pattern
        DB::table('attendance_patterns')->where('child_id', $childId)
            ->whereNull('effective_until')
            ->update(['effective_until' => $data['effective_from'], 'updated_at' => now()]);
        $row = [
            'child_id' => $childId,
            'effective_from' => $data['effective_from'],
            'notes' => $data['notes'] ?? null,
            'updated_at' => now(),
        ];
        foreach (self::DAYS as $d) $row[$d] = $data[$d];
        if (Schema::hasColumn('attendance_patterns', 'room_id')) $row['room_id'] = $data['room_id'] ?? null;
        if (Schema::hasColumn('attendance_patterns', 'active')) $row['active'] = $request->boolean('active', true);
        if (Schema::hasColumn('attendance_patterns', 'updated_by_id')) $row['updated_by_id'] = $request->user()->id ?? null;
        if (Schema::hasColumn('attendance_patterns', 'created_at')) $row['created_at'] = now();
        $id = DB::table('attendance_patterns')->insertGetId($row);
        return response()->json(['id' => $id, 'status' => 'saved']);
    }

    /** Get an overview of every enrolled child's expected days this week. */
    public function weeklyOverview(Request $request): JsonResponse
    {
        // SECURITY (v22p96): resolve the active agency securely — the prior raw
        // header let an agency_admin of A forge X-Active-Agency-Id=B and read
        // agency B's full child roster + attendance patterns.
        $agencyId = (int) $this->resolveAgencyId($request);
        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->leftJoin('attendance_patterns as ap', function ($j) {
                $j->on('ap.child_id', '=', 'ch.id')
                  ->whereNull('ap.effective_until');
            })
            ->leftJoin('rooms as rm', 'rm.id', '=', 'ap.room_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('ch.deleted_at')
            // EVERY child in the agency, not only those whose status is exactly
            // 'enrolled'. Dropping the others is why this screen "did not pick up
            // all the kids": a child starting next month, on hold or waitlisted
            // still has a pattern to plan around. The status travels with each row
            // so the reader can filter — the screen decides what to show, not a
            // WHERE clause nobody could see.
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.enrollment_status',
                'c.id as centre_id', 'c.name as centre_name',
                'rm.id as room_id', 'rm.name as room_name',
                'ap.id as pattern_id', 'ap.effective_from', 'ap.notes',
                'ap.monday', 'ap.tuesday', 'ap.wednesday', 'ap.thursday', 'ap.friday',
                'ap.saturday', 'ap.sunday')
            ->orderBy('c.name')->orderBy('ch.last_name')->get();

        // Normalise on the way OUT as well as in: rows written before rotations
        // existed hold '1', and a client should never have to know that.
        $children->transform(function ($r) {
            foreach (self::DAYS as $d) $r->$d = $this->normaliseRotation($r->$d);
            $r->has_pattern = !empty($r->pattern_id);
            $r->active = !empty($r->pattern_id);
            return $r;
        });

        return response()->json([
            'data' => $children,
            // The vocabulary, sent with the data so the UI never hard-codes a list
            // that then drifts from what the API accepts.
            'rotations' => self::ROTATION_LABELS,
            'statuses' => $children->pluck('enrollment_status')->filter()->unique()->values(),
        ]);
    }

    /** The rotations a day can hold. Extend here and the API, UI and validation all follow. */
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
    private const ROTATIONS = ['full', 'am', 'pm', 'before', 'after', 'bna'];
    private const ROTATION_LABELS = [
        ['key' => 'full',   'label' => 'Full day',            'short' => 'Full'],
        ['key' => 'am',     'label' => 'Morning only',        'short' => 'AM'],
        ['key' => 'pm',     'label' => 'Afternoon only',      'short' => 'PM'],
        ['key' => 'before', 'label' => 'Before school',       'short' => 'Before'],
        ['key' => 'after',  'label' => 'After school',        'short' => 'After'],
        ['key' => 'bna',    'label' => 'Before and after school', 'short' => 'B&A'],
    ];

    /**
     * One value in, one meaning out. Accepts the legacy booleans ('1'/1/true =
     * a full day) alongside the rotation codes, so older clients and rows written
     * before this existed both keep working.
     */
    private function normaliseRotation($v): ?string
    {
        if ($v === null || $v === '' || $v === false || $v === 0 || $v === '0') return null;
        if ($v === true || $v === 1 || $v === '1') return 'full';
        $v = strtolower(trim((string) $v));
        return in_array($v, self::ROTATIONS, true) ? $v : null;
    }

    private function assertChildAccess(Request $request, int $childId): void
    {
        // SECURITY (v22p96): the prior blanket `$isStaff` accepted any active staff
        // role anywhere, so any agency's staff — or a switched platform_admin —
        // could read/write any child's attendance pattern. Now guardian of the
        // child, staff of its centre, or a platform_admin scoped to its agency.
        abort_unless($this->canAccessChildScoped($request, $childId), 403);
    }
}
