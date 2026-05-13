<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Medication;
use App\Models\MedicationLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * MedicationController v22p1.
 *
 * Standing medication orders (CCEYA-aligned) + each-dose administration log.
 *
 * Director endpoints:
 *   GET    /director/medications                  list (?child_id=N to filter)
 *   POST   /director/medications                  create (status starts pending_auth)
 *   GET    /director/medications/{id}             show
 *   PATCH  /director/medications/{id}             update
 *   POST   /director/medications/{id}/authorize   record parent authorization
 *   POST   /director/medications/{id}/discontinue mark discontinued
 *   GET    /director/medications/{id}/logs        dose history
 *
 * Provider endpoints:
 *   GET    /provider/medications                  list active meds in my centre
 *   POST   /provider/medications/give             log a dose (replaces v20 501 stub)
 *
 * Parent endpoints:
 *   GET    /parent/children/{child}/medications   active meds for their child
 */
class MedicationController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['medications' => []]);
        }

        $q = Medication::query()
            ->where('centre_id', $centreId)
            ->orderByDesc('created_at');

        if ($childId = (int) $request->query('child_id')) {
            $q->where('child_id', $childId);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $rows = $q->limit(200)->get();
        return response()->json(['medications' => $this->hydrate($rows)]);
    }

    public function activeForProvider(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['medications' => []]);
        }

        $rows = Medication::query()
            ->where('centre_id', $centreId)
            ->where('status', 'active')
            ->orderBy('child_id')
            ->limit(200)
            ->get();

        return response()->json(['medications' => $this->hydrate($rows)]);
    }

    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'child_id'                => 'required|integer|exists:children,id',
            'name'                    => 'required|string|max:200',
            'strength'                => 'nullable|string|max:100',
            'route'                   => 'nullable|string|max:40',
            'dosage'                  => 'required|string|max:200',
            'frequency'               => 'required|string|max:200',
            'reason'                  => 'nullable|string|max:200',
            'starts_on'               => 'required|date',
            'expires_on'              => 'nullable|date|after_or_equal:starts_on',
            'special_instructions'    => 'nullable|string|max:2000',
            'requires_refrigeration'  => 'nullable|boolean',
            'storage_location'        => 'nullable|string|max:100',
            'is_prescription'         => 'nullable|boolean',
            'prescribing_physician'   => 'nullable|string|max:160',
        ]);

        $data['centre_id']     = $centreId;
        $data['created_by_id'] = $request->user()->id;
        $data['route']         = $data['route'] ?? 'oral';
        $data['status']        = 'pending_auth';

        $med = Medication::create($data);
        return response()->json(['medication' => $med], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $med = Medication::where('centre_id', $centreId)->find($id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }
        return response()->json(['medication' => $this->hydrate(collect([$med]))->first()]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $med = Medication::where('centre_id', $centreId)->find($id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }

        $data = $request->validate([
            'name'                    => 'sometimes|string|max:200',
            'strength'                => 'nullable|string|max:100',
            'route'                   => 'nullable|string|max:40',
            'dosage'                  => 'sometimes|string|max:200',
            'frequency'               => 'sometimes|string|max:200',
            'reason'                  => 'nullable|string|max:200',
            'starts_on'               => 'sometimes|date',
            'expires_on'              => 'nullable|date',
            'special_instructions'    => 'nullable|string|max:2000',
            'requires_refrigeration'  => 'nullable|boolean',
            'storage_location'        => 'nullable|string|max:100',
            'is_prescription'         => 'nullable|boolean',
            'prescribing_physician'   => 'nullable|string|max:160',
        ]);
        $med->update($data);
        return response()->json(['medication' => $med->fresh()]);
    }

    public function authorize(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $med = Medication::where('centre_id', $centreId)->find($id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }

        $data = $request->validate([
            'authorized_by_id'        => 'required|integer|exists:users,id',
            'parent_signature_data'   => 'nullable|string|max:200000',
        ]);

        $med->update([
            'authorized_by_id'      => $data['authorized_by_id'],
            'parent_signature_data' => $data['parent_signature_data'] ?? null,
            'authorized_at'         => now(),
            'status'                => 'active',
        ]);

        return response()->json(['medication' => $med->fresh()]);
    }

    public function discontinue(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $med = Medication::where('centre_id', $centreId)->find($id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }
        $med->update(['status' => 'discontinued']);
        return response()->json(['medication' => $med->fresh()]);
    }

    /**
     * POST /provider/medications/give
     * Replaces the v20 501 stub. Educator (or director) logs a dose given.
     */
    public function give(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (! $centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $data = $request->validate([
            'medication_id' => 'required|integer|exists:medications,id',
            'dose_given'    => 'required|string|max:200',
            'outcome'       => 'nullable|in:given,refused,missed,partial',
            'notes'         => 'nullable|string|max:2000',
            'witness_id'    => 'nullable|integer|exists:users,id',
            'administered_at' => 'nullable|date',
        ]);

        $med = Medication::where('centre_id', $centreId)->find($data['medication_id']);
        if (! $med) {
            return response()->json(['message' => 'Medication not in your centre'], 403);
        }
        if ($med->status !== 'active') {
            return response()->json([
                'message' => 'Medication is not active (status: ' . $med->status . '). Director must authorize before doses are recorded.',
            ], 422);
        }

        $log = MedicationLog::create([
            'medication_id'      => $med->id,
            'child_id'           => $med->child_id,
            'centre_id'          => $centreId,
            'administered_at'    => $data['administered_at'] ?? now(),
            'dose_given'         => $data['dose_given'],
            'outcome'            => $data['outcome'] ?? 'given',
            'notes'              => $data['notes'] ?? null,
            'administered_by_id' => $request->user()->id,
            'witness_id'         => $data['witness_id'] ?? null,
        ]);

        return response()->json(['log' => $log], 201);
    }

    public function logs(Request $request, int $id): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        $med = Medication::where('centre_id', $centreId)->find($id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }

        $logs = MedicationLog::query()
            ->where('medication_id', $med->id)
            ->orderByDesc('administered_at')
            ->limit(200)
            ->get();

        $userIds = $logs->pluck('administered_by_id')->merge($logs->pluck('witness_id'))->filter()->unique();
        $names = DB::table('users')
            ->whereIn('id', $userIds)
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $out = $logs->map(function ($l) use ($names) {
            return [
                'id'              => $l->id,
                'administered_at' => $l->administered_at,
                'dose_given'      => $l->dose_given,
                'outcome'         => $l->outcome,
                'notes'           => $l->notes,
                'administered_by' => $names[$l->administered_by_id] ?? null,
                'witness'         => $l->witness_id ? ($names[$l->witness_id] ?? null) : null,
            ];
        });

        return response()->json(['logs' => $out]);
    }

    public function parentList(Request $request, int $childId): JsonResponse
    {
        $user = $request->user();
        // Confirm user is guardian of this child
        $isGuardian = DB::table('guardians')
            ->join('children', 'children.family_id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('children.id', $childId)
            ->exists();
        if (! $isGuardian) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rows = Medication::query()
            ->where('child_id', $childId)
            ->whereIn('status', ['active', 'pending_auth'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
        return response()->json(['medications' => $this->hydrate($rows)]);
    }

    /**
     * Attach child names + creator names + recent dose count to a collection of meds.
     */
    private function hydrate($rows)
    {
        if ($rows->isEmpty()) {
            return collect();
        }
        $childIds   = $rows->pluck('child_id')->unique();
        $creatorIds = $rows->pluck('created_by_id')->merge($rows->pluck('authorized_by_id'))->filter()->unique();
        $medIds     = $rows->pluck('id');

        $children = DB::table('children')
            ->whereIn('id', $childIds)
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $names = DB::table('users')
            ->whereIn('id', $creatorIds)
            ->pluck(DB::raw("CONCAT(first_name, ' ', last_name)"), 'id');

        $doseCounts = DB::table('medication_logs')
            ->select('medication_id', DB::raw('COUNT(*) as c'), DB::raw('MAX(administered_at) as last_dose'))
            ->whereIn('medication_id', $medIds)
            ->groupBy('medication_id')
            ->get()
            ->keyBy('medication_id');

        return $rows->map(function ($m) use ($children, $names, $doseCounts) {
            $arr = $m->toArray();
            $arr['child_name']      = $children[$m->child_id] ?? '?';
            $arr['created_by_name'] = $names[$m->created_by_id] ?? null;
            $arr['authorized_by_name'] = $m->authorized_by_id ? ($names[$m->authorized_by_id] ?? null) : null;
            $arr['dose_count']      = $doseCounts->has($m->id) ? (int) $doseCounts[$m->id]->c : 0;
            $arr['last_dose_at']    = $doseCounts->has($m->id) ? $doseCounts[$m->id]->last_dose : null;
            return $arr;
        });
    }
}
