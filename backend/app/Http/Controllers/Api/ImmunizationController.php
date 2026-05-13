<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Immunization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * ImmunizationController v22p1.
 *
 * Per-child immunization records. Ontario daycare licensing (CCEYA) requires up-to-date
 * records or a documented medical / religious exemption.
 *
 * Director endpoints:
 *   GET    /director/immunizations               list (?child_id=N or ?overdue=1)
 *   POST   /director/immunizations               add record
 *   PATCH  /director/immunizations/{id}          update
 *   DELETE /director/immunizations/{id}          remove
 *
 * Parent endpoints:
 *   GET    /parent/children/{child}/immunizations records for their child
 */
class ImmunizationController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['immunizations' => []]);
        }

        // Scope to children enrolled at this centre. enrollments has room_id, not centre_id,
        // so go through rooms.
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id');
        $centreChildIds = DB::table('enrollments')
            ->whereIn('room_id', $roomIds)
            ->pluck('child_id')
            ->unique();

        $q = Immunization::query()
            ->whereIn('child_id', $centreChildIds)
            ->orderByDesc('administered_on');

        if ($childId = (int) $request->query('child_id')) {
            $q->where('child_id', $childId);
        }
        if ($request->boolean('overdue')) {
            $q->whereNotNull('next_due_on')
              ->where('next_due_on', '<', now()->toDateString())
              ->where('exempt', false);
        }

        $rows = $q->limit(500)->get();
        $childNames = DB::table('children')
            ->whereIn('id', $rows->pluck('child_id')->unique())
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $out = $rows->map(function ($r) use ($childNames) {
            $arr = $r->toArray();
            $arr['child_name'] = $childNames[$r->child_id] ?? '?';
            return $arr;
        });

        return response()->json(['immunizations' => $out]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'child_id'         => 'required|integer|exists:children,id',
            'vaccine'          => 'required|string|max:100',
            'dose_label'       => 'nullable|string|max:40',
            'administered_on'  => 'nullable|date',
            'lot_number'       => 'nullable|string|max:80',
            'site'             => 'nullable|string|max:80',
            'administered_by'  => 'nullable|string|max:160',
            'clinic_name'      => 'nullable|string|max:160',
            'next_due_on'      => 'nullable|date',
            'exempt'           => 'nullable|boolean',
            'exemption_reason' => 'nullable|string|max:200',
        ]);

        $data['recorded_by_id'] = $request->user()->id;
        $row = Immunization::create($data);
        return response()->json(['immunization' => $row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }
        $row = Immunization::find($id);
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // Soft scope check: the child must be enrolled at the user's centre.
        // enrollments has room_id, not centre_id — join through rooms.
        $childEnrolled = DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $row->child_id)
            ->where('rooms.centre_id', $centreId)
            ->exists();
        if (! $childEnrolled) {
            return response()->json(['message' => 'Child not in your centre'], 403);
        }

        $data = $request->validate([
            'vaccine'          => 'sometimes|string|max:100',
            'dose_label'       => 'nullable|string|max:40',
            'administered_on'  => 'nullable|date',
            'lot_number'       => 'nullable|string|max:80',
            'site'             => 'nullable|string|max:80',
            'administered_by'  => 'nullable|string|max:160',
            'clinic_name'      => 'nullable|string|max:160',
            'next_due_on'      => 'nullable|date',
            'exempt'           => 'nullable|boolean',
            'exemption_reason' => 'nullable|string|max:200',
        ]);
        $row->update($data);
        return response()->json(['immunization' => $row->fresh()]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $row = Immunization::find($id);
        if (! $row) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $childEnrolled = DB::table('enrollments')
            ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('enrollments.child_id', $row->child_id)
            ->where('rooms.centre_id', $centreId)
            ->exists();
        if (! $childEnrolled) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $row->delete();
        return response()->json(['ok' => true]);
    }

    public function parentList(Request $request, int $childId): JsonResponse
    {
        $user = $request->user();
        $isGuardian = DB::table('guardians')
            ->join('children', 'children.family_id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('children.id', $childId)
            ->exists();
        if (! $isGuardian) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $rows = Immunization::where('child_id', $childId)->orderByDesc('administered_on')->get();
        return response()->json(['immunizations' => $rows]);
    }
}
