<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Incident;
use App\Models\IncidentAcknowledgment;
use App\Models\IncidentNote;
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
    use ResolvesCentreContext;

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
        // SECURITY (v22p94): only the child's centre staff may file an incident.
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403);

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
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

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

        // NOTE (v22p98): users has no `name` column — select first_name/last_name
        // (the old `recordedBy:id,name` / `reviewedBy:id,name` 500'd once incidents
        // actually existed). A `name` accessor on User still derives from these.
        $q = Incident::query()
            ->with(['child:id,first_name,last_name,photo_url', 'recordedBy:id,first_name,last_name', 'reviewedBy:id,first_name,last_name'])
            ->orderByDesc('occurred_at');

        // Filter by status if provided
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        // Filter by centre (incidents have no centre_id — scope via the child's family centre)
        if ($centreId = $request->query('centre_id')) {
            $centreChildIds = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', (int) $centreId)
                ->pluck('c.id')->all();
            $q->whereIn('child_id', $centreChildIds ?: [0]);
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
        } else {
            // SECURITY (v22p96/98): staff/admin see only incidents in their active
            // agency — a platform_admin is scoped to the agency they've switched
            // into. NOTE: the `incidents` table has NO centre_id column (v22p96
            // wrongly filtered on it → 500). Incidents link to a child, so scope
            // by the children whose family belongs to a centre in the agency.
            $agencyId = $this->resolveAgencyId($request);
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
            $childIds = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->whereIn('f.centre_id', $centreIds ?: [0])
                ->pluck('c.id')->all();
            $q->whereIn('child_id', $childIds ?: [0]);
        }

        $limit = min(100, max(5, (int) $request->query('limit', 30)));
        return response()->json($q->paginate($limit));
    }

    /* ============================================================
     * SHOW
     * ============================================================ */
    public function show(Request $request, int $id): JsonResponse
    {
        // v22p98: users has no `name` column — select first_name/last_name.
        $incident = Incident::with([
            'child:id,first_name,last_name,photo_url',
            'recordedBy:id,first_name,last_name',
            'reviewedBy:id,first_name,last_name',
            'acknowledgments.user:id,first_name,last_name',
            'notes.user:id,first_name,last_name',
            'attachments',
        ])->findOrFail($id);

        // Access: the child's guardians / direct centre staff (canAccessChildId), OR
        // any STAFF/ADMIN whose RESOLVED agency owns the child's centre — the SAME
        // scoping index() uses, so anything you can see in the list you can open.
        // Fixes the "server error" (a 403) a platform_admin hit opening an incident:
        // index() scopes via resolveAgencyId() but show() used authorizeCentreAccess(),
        // which checks the raw X-Active-Agency-Id header and didn't line up.
        // The agency check is STAFF-ONLY so a guardian can never read another
        // family's incident that happens to share their agency.
        $allowed = $this->canAccessChildId($request->user(), (int) $incident->child_id);
        if (! $allowed && $this->primaryRole($request->user()) !== 'guardian') {
            $childCentreAgency = (int) DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
                ->where('c.id', (int) $incident->child_id)
                ->value('ce.agency_id');
            $allowed = $childCentreAgency > 0 && $childCentreAgency === (int) $this->resolveAgencyId($request);
        }
        abort_unless($allowed, 403);

        // Notes are staff-internal — never expose them to a guardian.
        if ($this->primaryRole($request->user()) === 'guardian') {
            $incident->unsetRelation('notes');
        }

        return response()->json(['data' => $incident]);
    }

    /* ============================================================
     * ADD NOTE (staff-internal: educator / director / admin)
     * Append-only audit trail — captures author + timestamp.
     * ============================================================ */
    public function addNote(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        $user = $request->user();

        // Staff-internal only — guardians can never add or see notes.
        abort_if($this->primaryRole($user) === 'guardian', 403);
        abort_unless($this->canAccessChildId($user, (int) $incident->child_id), 403);

        $data = $request->validate([
            'note' => 'required|string|min:1|max:5000',
        ]);

        $authorName = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
        if ($authorName === '') {
            $authorName = $user->name ?? 'Staff';
        }

        $note = IncidentNote::create([
            'incident_id' => $incident->id,
            'user_id'     => $user->id,
            'author_name' => $authorName,
            'note'        => $data['note'],
            'ip_address'  => $request->ip(),
        ]);

        $note->load('user:id,first_name,last_name');

        return response()->json(['data' => $note], 201);
    }

    /* ============================================================
     * REVIEW (director: submitted -> director_reviewed)
     * ============================================================ */
    public function review(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

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
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

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

        // SECURITY (v22p94): only the child's guardians/centre staff may acknowledge.
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        if (! $incident->canBeAcknowledged()) {
            return response()->json(['error' => 'Incident is not in a state where it can be acknowledged'], 409);
        }

        $data = $request->validate([
            'signed_name'    => 'required|string|min:2|max:160',
            'comment'        => 'nullable|string|max:1000',
            // Drawn signature (base64 PNG data URL from the signature pad).
            'signature_data' => 'nullable|string|max:400000',
        ]);

        DB::transaction(function () use ($incident, $request, $data) {
            IncidentAcknowledgment::create([
                'incident_id'    => $incident->id,
                'user_id'        => $request->user()->id,
                'signed_name'    => $data['signed_name'],
                'signature_data' => $data['signature_data'] ?? null,
                'comment'        => $data['comment'] ?? null,
                'ip_address'     => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'signed_at'      => now(),
            ]);

            $incident->update([
                'status'          => 'acknowledged',
                'acknowledged_at' => now(),
            ]);
        });

        return response()->json(['data' => $incident->fresh(['acknowledgments'])]);
    }

    /* ============================================================
     * EMAIL REPORT (staff: send the incident to parent / admin / director)
     * ============================================================ */
    public function emailReport(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        $data = $request->validate([
            'to'     => ['nullable', 'array'],
            'to.*'   => ['in:parent,admin,director'],
            'extra_email' => ['nullable', 'email', 'max:190'],
        ]);
        $groups = array_values(array_unique($data['to'] ?? []));

        $child = DB::table('children')->where('id', $incident->child_id)->first();
        $centreId = DB::table('rooms')->where('id', $incident->room_id)->value('centre_id');
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;
        $agencyId = $centre->agency_id ?? null;

        $recipients = collect();
        if (in_array('parent', $groups, true) && $child) {
            $emails = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $child->family_id)->whereNotNull('u.email')->pluck('u.email');
            $recipients = $recipients->merge($emails);
        }
        if (in_array('director', $groups, true) && $centreId) {
            $emails = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $centreId)->where('ra.role', 'centre_director')->where('ra.active', true)
                ->whereNotNull('u.email')->pluck('u.email');
            $recipients = $recipients->merge($emails);
        }
        if (in_array('admin', $groups, true) && $agencyId) {
            $emails = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')->where('ra.active', true)
                ->whereNotNull('u.email')->pluck('u.email');
            $recipients = $recipients->merge($emails);
        }
        if (! empty($data['extra_email'])) {
            $recipients->push($data['extra_email']);
        }
        $recipients = $recipients->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique()->values();
        if ($recipients->isEmpty()) {
            return response()->json(['message' => 'No matching recipients found for the selected groups.'], 422);
        }

        $childName = $child ? trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? '')) : 'the child';
        $recorder = DB::table('users')->where('id', $incident->recorded_by_id)->first();
        $recorderName = $recorder ? trim(($recorder->first_name ?? '') . ' ' . ($recorder->last_name ?? '')) : 'Staff';
        $occurred = $incident->occurred_at ? \Illuminate\Support\Carbon::parse($incident->occurred_at)->format('M j, Y g:i A') : '—';

        $body = "An incident report has been shared with you for {$childName}.\n\n"
              . "• Type: " . ucwords(str_replace('_', ' ', (string) $incident->incident_type)) . "\n"
              . "• Severity: " . ucfirst((string) ($incident->severity ?: 'n/a')) . "\n"
              . "• Occurred: {$occurred}\n"
              . ($incident->location ? "• Location: {$incident->location}\n" : '')
              . "• Recorded by: {$recorderName}\n\n"
              . "What happened:\n" . (trim((string) $incident->description) ?: '(no description)') . "\n\n"
              . "Action taken:\n" . (trim((string) $incident->action_taken) ?: '(none recorded)') . "\n\n"
              . ($incident->first_aid_administered ? "First aid was administered.\n\n" : '')
              . "Please sign in to your portal to view the full report and acknowledge receipt.";

        $sent = 0;
        foreach ($recipients as $email) {
            try {
                \Illuminate\Support\Facades\Mail::to($email)->queue(
                    (new \App\Mail\AccountNotice(
                        recipientName: 'there',
                        subjectLine:   'Incident report — ' . $childName,
                        bodyText:      $body,
                        ctaLabel:      'Open in your portal',
                        ctaUrl:        config('app.url', 'https://app.kiddietrac.com'),
                    ))->onQueue('mail')
                );
                $sent++;
            } catch (\Throwable $e) {
            }
        }

        // Sending to the parent counts as notifying them.
        if (in_array('parent', $groups, true) && empty($incident->parent_notified_at)) {
            $incident->update(['parent_notified_at' => now(), 'status' => $incident->status === 'draft' ? $incident->status : 'parent_notified']);
        }

        return response()->json(['ok' => true, 'sent' => $sent, 'recipients' => $recipients->count()]);
    }

    /* ============================================================
     * CLOSE (director: any state -> closed)
     * ============================================================ */
    public function close(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

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
