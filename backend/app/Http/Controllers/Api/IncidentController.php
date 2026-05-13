<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentAcknowledgment;
use App\Models\Child;
use App\Services\WebPushService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * IncidentController v20.
 *
 * Workflow:
 *   draft (educator) -> submitted (educator submits) -> director_reviewed
 *     -> parent_notified -> acknowledged (parent ACKs) -> closed (director closes)
 *
 * Endpoints:
 *   POST   /incidents                       create (educator)
 *   PATCH  /incidents/{id}                  update while draft
 *   POST   /incidents/{id}/submit           draft -> submitted
 *   GET    /incidents                       list for current user's scope
 *   GET    /incidents/{id}                  show
 *   POST   /incidents/{id}/review           submitted -> director_reviewed (director)
 *   POST   /incidents/{id}/notify-parent    director_reviewed -> parent_notified
 *   POST   /incidents/{id}/acknowledge      parent_notified -> acknowledged (parent)
 *   POST   /incidents/{id}/close            -> closed (director)
 */
class IncidentController extends Controller
{
    public function __construct(protected WebPushService $push)
    {
    }

    /* ============================================================
     * CREATE (educator)
     * ============================================================ */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id'              => 'required|integer|exists:children,id',
            'room_id'               => 'nullable|integer|exists:rooms,id',
            'incident_type'         => 'required|in:general,injury,illness,serious_occurrence,behavioural,medication_error,other',
            'severity'              => 'nullable|in:low,medium,high',
            'is_serious_occurrence' => 'nullable|boolean',
            'occurred_at'           => 'required|date',
            'location'              => 'nullable|string|max:160',
            'description'           => 'required|string|min:5|max:5000',
            'action_taken'          => 'nullable|string|max:3000',
            'follow_up_required'    => 'nullable|string|max:2000',
            'witnesses'             => 'nullable|array',
            'witnesses.*.name'      => 'nullable|string|max:120',
            'witnesses.*.role'      => 'nullable|string|max:60',
            'body_parts_affected'   => 'nullable|array',
        ]);

        // Derive centre_id from child
        $child = Child::find($data['child_id']);
        if (! $child) {
            return response()->json(['error' => 'Child not found'], 404);
        }

        $centreId = $this->resolveCentreId($child);
        if (! $centreId) {
            return response()->json(['error' => 'Cannot determine centre for child'], 422);
        }

        // serious_occurrence type implies is_serious_occurrence = true
        if (($data['incident_type'] ?? null) === 'serious_occurrence') {
            $data['is_serious_occurrence'] = true;
        }

        $incident = Incident::create(array_merge($data, [
            'centre_id'      => $centreId,
            'recorded_by_id' => $request->user()->id,
            'status'         => 'draft',
        ]));

        return response()->json(['data' => $incident], 201);
    }

    /* ============================================================
     * UPDATE (educator, while draft)
     * ============================================================ */
    public function update(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        if ($incident->status !== 'draft') {
            return response()->json([
                'error' => 'Cannot edit a submitted incident. Contact your director.',
            ], 409);
        }

        $data = $request->validate([
            'incident_type'         => 'nullable|in:general,injury,illness,serious_occurrence,behavioural,medication_error,other',
            'severity'              => 'nullable|in:low,medium,high',
            'is_serious_occurrence' => 'nullable|boolean',
            'occurred_at'           => 'nullable|date',
            'location'              => 'nullable|string|max:160',
            'description'           => 'nullable|string|min:5|max:5000',
            'action_taken'          => 'nullable|string|max:3000',
            'follow_up_required'    => 'nullable|string|max:2000',
            'witnesses'             => 'nullable|array',
            'body_parts_affected'   => 'nullable|array',
        ]);

        $incident->update($data);
        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * SUBMIT (educator: draft -> submitted)
     * ============================================================ */
    public function submit(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        if ($incident->status !== 'draft') {
            return response()->json(['error' => 'Only drafts can be submitted'], 409);
        }

        $incident->update([
            'status'       => 'submitted',
            'submitted_at' => now(),
        ]);

        // Notify directors in this centre via push
        try {
            $directorIds = DB::table('role_assignments')
                ->whereIn('role', ['centre_director', 'agency_admin'])
                ->where(function ($q) use ($incident) {
                    $q->where('centre_id', $incident->centre_id)
                      ->orWhereNull('centre_id'); // agency_admin sees all
                })
                ->pluck('user_id')
                ->unique()
                ->all();

            if (! empty($directorIds)) {
                $this->push->sendToUsers($directorIds, [
                    'title' => 'New incident report',
                    'body'  => 'An educator submitted an incident report awaiting your review.',
                    'url'   => '/dashboard.html#incidents?status=submitted',
                    'tag'   => 'incident-submitted-' . $incident->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('Incident push (director) failed: ' . $e->getMessage());
        }

        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * LIST
     * ============================================================ */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = Incident::query()
            ->with(['child:id,first_name,last_name', 'recordedBy:id,name', 'reviewedBy:id,name'])
            ->orderByDesc('occurred_at');

        // Filter by status if provided
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        // Filter by centre
        if ($centreId = $request->query('centre_id')) {
            $q->where('centre_id', (int) $centreId);
        }

        // Date range
        if ($from = $request->query('from')) {
            $q->where('occurred_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->where('occurred_at', '<=', Carbon::parse($to)->endOfDay());
        }

        // Serious occurrence filter
        if ($request->query('serious_only')) {
            $q->where('is_serious_occurrence', true);
        }

        // Role-based scoping (defensive — controller route middleware should already scope)
        $role = $this->primaryRole($user);
        if ($role === 'guardian') {
            // Parents only see incidents involving their own children, after parent_notified
            $childIds = DB::table('family_children')
                ->where('family_id', function ($q2) use ($user) {
                    $q2->select('family_id')->from('family_members')->where('user_id', $user->id);
                })
                ->pluck('child_id')
                ->all();
            $q->whereIn('child_id', $childIds);
            $q->whereIn('status', ['parent_notified', 'acknowledged', 'closed']);
        }

        $limit = min(100, max(5, (int) $request->query('limit', 30)));
        return response()->json($q->paginate($limit));
    }

    /* ============================================================
     * SHOW
     * ============================================================ */
    public function show(Request $request, int $id): JsonResponse
    {
        $incident = Incident::with([
            'child:id,first_name,last_name',
            'recordedBy:id,name',
            'reviewedBy:id,name',
            'acknowledgments.user:id,name',
            'attachments',
        ])->findOrFail($id);

        return response()->json(['data' => $incident]);
    }

    /* ============================================================
     * REVIEW (director: submitted -> director_reviewed)
     * ============================================================ */
    public function review(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        if ($incident->status !== 'submitted') {
            return response()->json(['error' => 'Only submitted incidents can be reviewed'], 409);
        }

        $data = $request->validate([
            'director_notes' => 'nullable|string|max:3000',
        ]);

        $incident->update([
            'status'         => 'director_reviewed',
            'reviewed_at'    => now(),
            'reviewed_by_id' => $request->user()->id,
            'director_notes' => $data['director_notes'] ?? $incident->director_notes,
        ]);

        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * NOTIFY PARENT (director: director_reviewed -> parent_notified)
     * Triggers push + email to parent.
     * ============================================================ */
    public function notifyParent(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        if (! in_array($incident->status, ['director_reviewed', 'submitted'], true)) {
            return response()->json(['error' => 'Incident must be reviewed before notifying parent'], 409);
        }

        $incident->update([
            'status'             => 'parent_notified',
            'parent_notified_at' => now(),
        ]);

        // Find parent user IDs from the child's family
        try {
            $parentIds = DB::table('family_members')
                ->whereIn('family_id', function ($q) use ($incident) {
                    $q->select('family_id')->from('family_children')->where('child_id', $incident->child_id);
                })
                ->pluck('user_id')
                ->unique()
                ->all();

            if (! empty($parentIds)) {
                $this->push->sendToUsers($parentIds, [
                    'title' => 'Incident report ready',
                    'body'  => 'Please review and acknowledge the incident report for your child.',
                    'url'   => '/dashboard.html#incident-detail?id=' . $incident->id,
                    'tag'   => 'incident-parent-' . $incident->id,
                ]);
            }
        } catch (\Throwable $e) {
            Log::info('Incident push (parent) failed: ' . $e->getMessage());
        }

        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * ACKNOWLEDGE (parent: parent_notified -> acknowledged)
     * Captures audit trail.
     * ============================================================ */
    public function acknowledge(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        if (! $incident->canBeAcknowledged()) {
            return response()->json(['error' => 'Incident is not in a state where it can be acknowledged'], 409);
        }

        $data = $request->validate([
            'signed_name' => 'required|string|min:2|max:160',
            'comment'     => 'nullable|string|max:1000',
        ]);

        DB::transaction(function () use ($incident, $request, $data) {
            IncidentAcknowledgment::create([
                'incident_id' => $incident->id,
                'user_id'     => $request->user()->id,
                'signed_name' => $data['signed_name'],
                'comment'     => $data['comment'] ?? null,
                'ip_address'  => $request->ip(),
                'user_agent'  => substr((string) $request->userAgent(), 0, 255),
                'signed_at'   => now(),
            ]);

            $incident->update([
                'status'          => 'acknowledged',
                'acknowledged_at' => now(),
            ]);
        });

        return response()->json(['data' => $incident->fresh(['acknowledgments'])]);
    }

    /* ============================================================
     * CLOSE (director: any state -> closed)
     * ============================================================ */
    public function close(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);

        $data = $request->validate([
            'director_notes' => 'nullable|string|max:3000',
        ]);

        $incident->update([
            'status'         => 'closed',
            'closed_at'      => now(),
            'director_notes' => $data['director_notes'] ?? $incident->director_notes,
        ]);

        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    private function resolveCentreId(Child $child): ?int
    {
        try {
            $room = $child->currentEnrollment?->room;
            if ($room && isset($room->centre_id)) return (int) $room->centre_id;
        } catch (\Throwable $e) {
            // currentEnrollment may not exist as relation
        }
        // Fallback: lookup via enrollments table
        $row = DB::table('enrollments')
            ->where('child_id', $child->id)
            ->whereNull('ended_at')
            ->join('rooms', 'enrollments.room_id', '=', 'rooms.id')
            ->orderByDesc('enrollments.started_at')
            ->select('rooms.centre_id')
            ->first();
        return $row ? (int) $row->centre_id : null;
    }

    private function primaryRole($user): ?string
    {
        if (! $user) return null;
        $roles = method_exists($user, 'rolesList') ? $user->rolesList() : ($user->roles ?? []);
        if (is_object($roles)) $roles = $roles->pluck('role')->all();
        if (! is_array($roles)) $roles = [];
        $order = ['agency_admin', 'centre_director', 'educator', 'guardian', 'auditor'];
        foreach ($order as $r) {
            if (in_array($r, $roles, true)) return $r;
        }
        return null;
    }
}
