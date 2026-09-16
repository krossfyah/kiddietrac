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

        $centreId = $this->centreIdForChild($child);
        if (! $centreId) {
            return response()->json(['error' => 'Cannot determine centre for child'], 422);
        }

        // serious_occurrence type implies is_serious_occurrence = true
        if (($data['incident_type'] ?? null) === 'serious_occurrence') {
            $data['is_serious_occurrence'] = true;
        }

        /* room_id is NOT NULL with no default, and the form does not send one —
           an educator should not have to restate which room a child is in. Derived
           from the child's placement, which this schema stores in two places. */
        if (empty($data['room_id'])) {
            $data['room_id'] = $this->resolveRoomId((int) $data['child_id'], $centreId);
        }
        if (empty($data['room_id'])) {
            return response()->json([
                'error' => 'This child is not placed in a room yet, so the incident cannot be filed against one. '
                    . 'Add the child to a room first, or pick the room on the form.',
            ], 422);
        }

        /* NOT centre_id — the table has no such column, and inserting it threw
           "Unknown column 'centre_id'" on every filing. The centre is derived from
           the child wherever it is needed, which is what the list endpoints below
           already do. $centreId above stays as the guard that the child HAS a
           centre; it is a validation, not a field. */
        $incident = Incident::create(array_merge($data, [
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

        /* MISSING GUARD: this had none. findOrFail then update, so any staff member
           in any agency could submit any incident by id — and submitting emails and
           pushes that centre's directors. */
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        if ($incident->status !== 'draft') {
            return response()->json(['error' => 'Only drafts can be submitted'], 409);
        }

        /* The filer's signature. Required: an incident report is the record of
           something that happened to a child, and it should carry an attestation
           from the person who wrote it. A signature that can be skipped is not one. */
        $data = $request->validate([
            'signature'      => ['required', 'string', 'max:400000'],
            'signature_name' => ['nullable', 'string', 'max:160'],
        ], [
            'signature.required' => 'Please sign the report before submitting it.',
        ]);

        // A canvas PNG and nothing else — never a URL, never arbitrary markup.
        if (! preg_match('~^data:image/(png|jpeg);base64,[A-Za-z0-9+/=\s]+$~', $data['signature'])) {
            return response()->json([
                'message' => 'That signature could not be read. Please sign again.',
            ], 422);
        }

        $user = $request->user();
        $signedName = trim((string) ($data['signature_name'] ?? ''))
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        $incident->update([
            'status'                  => 'submitted',
            'submitted_at'            => now(),
            'recorder_signature_data' => $data['signature'],
            'recorder_signed_name'    => $signedName ?: null,
            'recorder_signed_at'      => now(),
            'recorder_signature_ip'   => substr((string) $request->ip(), 0, 64),
        ]);

        /* Notify the directors of the centre this child belongs to.

           This read `$incident->centre_id`, which is null on a model whose table has
           no such column — so the centre's own directors never matched and only
           agency admins were notified, via the orWhereNull branch. Resolved from the
           child instead, the same way every list endpoint in this controller does. */
        try {
            $incidentCentreId = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('c.id', $incident->child_id)
                ->value('f.centre_id');

            $directorIds = DB::table('role_assignments')
                ->whereIn('role', ['centre_director', 'agency_admin'])
                ->where(function ($q) use ($incidentCentreId) {
                    $q->where('centre_id', $incidentCentreId)
                      ->orWhereNull('centre_id'); // agency_admin sees all
                })
                ->pluck('user_id')
                ->unique()
                ->all();

            if (! empty($directorIds)) {
                /* Say what it is on the lock screen. "An incident report awaiting
                   review" is indistinguishable from a newsletter; a name, a type and
                   a room is something a director can act on without opening it. */
                $kid = DB::table('children')->where('id', $incident->child_id)
                    ->selectRaw("TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) n")->value('n');
                $roomName = DB::table('rooms')->where('id', $incident->room_id)->value('name');
                $serious = (bool) $incident->is_serious_occurrence;

                $title = $serious ? '🚨 SERIOUS OCCURRENCE — action needed' : '🚨 Incident report — action needed';
                $body  = trim(($kid ?: 'A child') . ' · '
                    . ucwords(str_replace('_', ' ', (string) $incident->incident_type))
                    . ($roomName ? ' · ' . $roomName : ''))
                    . '. Tap to review and action this incident.';
                $url   = '/dashboard.html#incidents?status=submitted';

                // The browser channel.
                $this->push->sendToUsers($directorIds, [
                    'title' => $title,
                    'body'  => $body,
                    'url'   => $url,
                    'tag'   => 'incident-submitted-' . $incident->id,
                ]);

                /* And the phones. WebPush only reaches an open browser; the app
                   installs register FCM tokens, and a director away from a desk is
                   exactly who this needs to reach. Wrapped separately so one channel
                   failing does not cost the other. */
                foreach ($directorIds as $did) {
                    try {
                        app(\App\Services\FcmService::class)->sendToUser((int) $did, $title, $body, '#incidents');
                    } catch (\Throwable $e) {
                        Log::info('incident FCM failed', ['user' => $did, 'error' => $e->getMessage()]);
                    }
                }
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
        /* By child, and by family — the two questions a record screen asks.
           canAccessChildId() below still governs what may be seen; this only makes
           the question askable. */
        if ($childId = $request->query('child_id')) {
            abort_unless($this->canAccessChildId($request->user(), (int) $childId), 403);
            $q->where('child_id', (int) $childId);
        }
        if ($familyId = $request->query('family_id')) {
            $ids = DB::table('children')->where('family_id', (int) $familyId)->pluck('id')->all();
            // An empty family must return nothing, not everything.
            $q->whereIn('child_id', $ids ?: [0]);
        }

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

        /* Role-based scoping, FAIL CLOSED: not staff means family. This used to
           read `primaryRole($user) === 'guardian'`, and primaryRole() returned null
           for everyone, so every parent took the staff branch below and was scoped
           to their whole AGENCY instead of their own children. */
        if (! $this->isStaff($user)) {
            /* Parents only see incidents involving their own children, and only once
               the family has been told.

               PHANTOM TABLES: this read `family_children` and `family_members`,
               NEITHER OF WHICH EXISTS — so every parent opening Incidents got a
               500, for as long as the screen has been there. A child belongs to a
               family; a `guardians` row links a user to that family. Same join
               notifyParent() uses. */
            $childIds = DB::table('children as c')
                ->join('guardians as g', 'g.family_id', '=', 'c.family_id')
                ->where('g.user_id', $user->id)
                ->whereNull('c.deleted_at')
                ->pluck('c.id')->unique()->values()->all();
            // No children on file must return NOTHING, never everything.
            $q->whereIn('child_id', $childIds ?: [0]);
            $q->whereIn('status', ['parent_notified', 'acknowledged', 'closed']);
        } else {
            // SECURITY (v22p96/98): staff/admin see only incidents in their active
            // agency — a platform_admin is scoped to the agency they've switched
            // into. NOTE: the `incidents` table has NO centre_id column (v22p96
            // wrongly filtered on it → 500). Incidents link to a child, so scope
            // by the children whose family belongs to a centre in the agency.
            /* The centres this person actually works at — not every centre the
               company owns. Scoping staff by agency is a TENANT boundary standing in
               for a PERSON boundary, and it is what let an educator at centre 18 read
               30 incidents belonging to centre 16 (2026-09-03). iLearn has nine
               centres; every educator there could read all nine.

               visibleChildIds() answers this once, by role, and fails closed — an
               admin still gets the whole agency, a director and an educator get their
               own centres, anything unrecognised gets nothing. */
            $childIds = $this->visibleChildIds($request);

            /* An educator still sees anything THEY filed, even if the child has since
               moved rooms or centres. It is their own report and their own account of
               what happened; hiding it from the person who wrote it helps nobody. */
            $q->where(function ($w) use ($childIds, $user) {
                $w->whereIn('child_id', $childIds ?: [0])
                  ->orWhere('recorded_by_id', $user->id);
            });
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

        /* The same centre rule as the list. This fallback used to ask only "is the
           child in my AGENCY", so a record hidden from the list could still be opened
           by id — a list filter that the detail endpoint does not honour is not a
           filter at all. Their own report is always theirs to open. */
        if (! $allowed && $this->isStaff($request->user())) {
            $allowed = in_array((int) $incident->child_id, $this->visibleChildIds($request), true)
                || (int) $incident->recorded_by_id === (int) $request->user()->id;
        }
        abort_unless($allowed, 403);

        /* Notes are staff-internal. Only a demonstrable staff role sees them —
           the old `=== 'guardian'` test never fired, so families were being handed
           the internal note thread on any incident they could open. */
        if (! $this->isStaff($request->user())) {
            $incident->unsetRelation('notes');
        }

        return response()->json(['data' => $incident]);
    }

    /* ============================================================
     * ADD NOTE (staff-internal: educator / director / admin)
     * Append-only audit trail — captures author + timestamp.
     * ============================================================ */
    /**
     * PATCH /incidents/{id}/status — move an incident deliberately.
     *
     * The fixed review → notify → close path assumes incidents happen in order.
     * They do not: a status gets set by mistake, a parent rings back after it was
     * closed, a report needs reopening. A director can move it, and every move
     * records WHY as a note — a status that changed with no visible cause is worse
     * than one that is wrong.
     */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        $data = $request->validate([
            'status' => ['required', 'in:draft,submitted,director_reviewed,parent_notified,acknowledged,closed'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $from = (string) $incident->status;
        $to = $data['status'];
        if ($from === $to) {
            return response()->json(['message' => 'It is already at that status.'], 422);
        }

        $stamps = ['status' => $to, 'updated_at' => now()];
        // Keep the milestone stamps honest — they are what the timeline reads.
        if ($to === 'submitted' && ! $incident->submitted_at) { $stamps['submitted_at'] = now(); }
        if ($to === 'director_reviewed' && ! $incident->reviewed_at) { $stamps['reviewed_at'] = now(); }
        if ($to === 'parent_notified' && ! $incident->parent_notified_at) { $stamps['parent_notified_at'] = now(); }
        if ($to === 'closed') { $stamps['closed_at'] = now(); }
        $incident->update($stamps);

        $who = trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? ''));
        DB::table('incident_notes')->insert([
            'incident_id'  => $incident->id,
            'user_id'      => $request->user()->id,
            'author_name'  => $who ?: 'Staff',
            'note'         => 'Status changed from ' . str_replace('_', ' ', $from) . ' to ' . str_replace('_', ' ', $to)
                            . (($data['reason'] ?? '') !== '' ? ' — ' . $data['reason'] : ''),
            'kind'         => 'note',
            'ip_address'   => substr((string) $request->ip(), 0, 64),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        return response()->json(['data' => $incident->fresh()]);
    }

    /**
     * GET /incidents/{id}/report.pdf — the incident as a document.
     *
     * On the AGENCY's branding, not KiddieTrac's: this is the provider's record,
     * and it is what goes to a parent or a licensing visit. Rendered from the same
     * data the screen shows, so the paper and the portal can never disagree.
     */
    public function reportPdf(Request $request, int $id)
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        $child = DB::table('children')->where('id', $incident->child_id)->first();
        $centreId = DB::table('rooms')->where('id', $incident->room_id)->value('centre_id');
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;
        $agency = ($centre->agency_id ?? null) ? DB::table('agencies')->where('id', $centre->agency_id)->first() : null;
        $recorder = DB::table('users')->where('id', $incident->recorded_by_id)->first();
        $notes = DB::table('incident_notes')->where('incident_id', $id)->orderBy('created_at')->get();

        $logo = $agency->brand_logo_url ?? ($agency->logo_url ?? null);
        if ($logo && ! preg_match('~^https?://~i', (string) $logo)) {
            $logo = 'https://app.kiddietrac.com/' . ltrim((string) $logo, '/');
        }

        $html = view('pdf.incident-report', [
            'incident' => $incident,
            'child'    => $child,
            'centre'   => $centre,
            'agency'   => $agency,
            'recorder' => $recorder,
            'notes'    => $notes,
            'logo'     => $logo,
        ])->render();

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        $name = 'Incident-' . $incident->id . '-'
            . preg_replace('/[^A-Za-z0-9]+/', '-', trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''))) . '.pdf';

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        $user = $request->user();

        // Staff-internal only — guardians can never add or see notes.
        abort_unless($this->isStaff($user), 403);
        abort_unless($this->canAccessChildId($user, (int) $incident->child_id), 403);

        $data = $request->validate([
            'note' => 'required|string|min:1|max:5000',
            /* An interaction is not an internal remark — it is evidence the family
               or the educator was actually spoken to, so it records who and how. */
            'kind'           => 'nullable|in:note,interaction',
            'contact_with'   => 'nullable|in:parent,educator,director,other',
            'contact_name'   => 'nullable|string|max:160',
            'contact_method' => 'nullable|in:in_person,phone,email,message',
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

        /* File the report on the child's record now that the family has been told.
           Fire-and-forget: telling a parent their child was hurt must not fail
           because a PDF could not be rendered. */
        \App\Services\IncidentReportFiler::file($incident->fresh());

        /* The child's guardians — via `guardians`, joined to the child's family.
           This read `family_members` and `family_children`, NEITHER OF WHICH EXISTS:
           the query threw, the catch below swallowed it, and the incident was marked
           parent_notified having notified nobody. The share-by-email path further
           down this file has always used the query below; now both agree. */
        try {
            $parentIds = DB::table('guardians as g')
                ->join('children as c', 'c.family_id', '=', 'g.family_id')
                ->where('c.id', $incident->child_id)
                ->whereNotNull('g.user_id')
                ->pluck('g.user_id')
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

    /**
     * POST /parent/incidents/{id}/feedback — the family's own words on the record.
     *
     * Their account of it, and/or a request to meet. Both land on the incident with
     * the parent's name attached, and both alert the centre — a family asking to
     * talk about their child being hurt should not wait for somebody to notice.
     */
    public function parentFeedback(Request $request, int $id): JsonResponse
    {
        $incident = Incident::findOrFail($id);
        abort_unless($this->canAccessChildId($request->user(), (int) $incident->child_id), 403);

        $data = $request->validate([
            'feedback'        => ['nullable', 'string', 'max:4000'],
            'request_meeting' => ['nullable', 'boolean'],
            'meeting_note'    => ['nullable', 'string', 'max:1000'],
        ]);

        $wantsMeeting = (bool) ($data['request_meeting'] ?? false);
        $feedback = trim((string) ($data['feedback'] ?? ''));
        if ($feedback === '' && ! $wantsMeeting) {
            return response()->json(['message' => 'Write something, or ask for a meeting.'], 422);
        }

        $user = $request->user();
        $who = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Parent';
        $child = DB::table('children')->where('id', $incident->child_id)->first();
        $childName = trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));
        $added = [];

        $row = [
            'incident_id' => $incident->id,
            'user_id'     => $user->id,
            'author_name' => $who,
            'contact_with' => 'parent',
            'contact_name' => $who,
            'ip_address'  => substr((string) $request->ip(), 0, 64),
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        if ($feedback !== '') {
            DB::table('incident_notes')->insert($row + ['note' => $feedback, 'kind' => 'parent_feedback']);
            $added[] = 'feedback';
        }
        if ($wantsMeeting) {
            $note = trim((string) ($data['meeting_note'] ?? ''));
            DB::table('incident_notes')->insert($row + [
                'note' => 'Requested a meeting to discuss this incident.' . ($note !== '' ? ' ' . $note : ''),
                'kind' => 'meeting_request',
            ]);
            $added[] = 'meeting request';
        }

        /* Tell the centre. A meeting request is the urgent one — that is a family
           waiting for somebody to come back to them. */
        try {
            $centreId = DB::table('rooms')->where('id', $incident->room_id)->value('centre_id');
            $directorIds = DB::table('role_assignments')
                ->whereIn('role', ['centre_director', 'agency_admin'])
                ->where('active', true)
                ->where(fn ($q) => $q->where('centre_id', $centreId)->orWhereNull('centre_id'))
                ->pluck('user_id')->unique()->all();

            $title = $wantsMeeting
                ? '📅 A family has asked to meet'
                : '💬 A parent replied to an incident report';
            $body = $childName . ' — ' . ($wantsMeeting
                ? $who . ' would like to meet about this incident.'
                : $who . ' has added their own account to the record.');

            foreach ($directorIds as $did) {
                \App\Support\Notify::write([
                    'user_id' => $did,
                    'type'    => 'incident',
                    'title'   => $title,
                    'body'    => $body,
                    'data'    => json_encode(['link' => '#incident-detail?id=' . $incident->id, 'incident_id' => $incident->id]),
                    'created_at' => now(),
                ]);
                try {
                    app(\App\Services\FcmService::class)->sendToUser((int) $did, $title, $body, '#incidents');
                } catch (\Throwable $e) { /* a push must never cost the parent their words */ }
            }
        } catch (\Throwable $e) {
            Log::info('incident parent-feedback notify failed: ' . $e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'recorded' => $added,
            'message' => $wantsMeeting
                ? 'Thank you. Your centre has been told you would like to meet, and will be in touch.'
                : 'Thank you — your comments are on the record and your centre has been told.',
        ], 201);
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
    /**
     * The opening line a parent reads, chosen by what actually happened.
     *
     * A scrape and an allergic reaction do not deserve the same sentence. A single
     * template would eventually reassure somebody about something that warranted
     * concern, so each type says its own thing, and anything unrecognised falls
     * back to the careful wording rather than the breezy one.
     */
    private function parentOpening(string $type, ?string $severity): string
    {
        $serious = in_array((string) $severity, ['high'], true);

        $byType = [
            'injury' => $serious
                ? 'We are writing to let you know that your child was hurt today. They were looked after straight away, and we want you to have the full picture of what happened.'
                : 'We want to let you know about a small injury your child had today. They were comforted and looked after straight away, and they are doing fine.',
            'illness' => 'We are letting you know that your child was unwell today. They were cared for and kept comfortable, and we watched them closely.',
            'allergic_reaction' => 'We are writing to you promptly because your child had an allergic reaction today. Our staff acted immediately, and we want you to have every detail.',
            'medication_error' => 'We need to tell you about a mistake we made with your child\'s medication today. We are sorry. Here is exactly what happened and what we did about it.',
            'behavioural' => 'We want to share something that happened with your child today, so that you hear it from us and we can work through it together.',
            'general' => 'We want to let you know about something that happened with your child today. They were looked after, and we want you to have the details.',
            'serious_occurrence' => 'We are contacting you about a serious occurrence involving your child today. Everything we did is set out below, and we are here to talk it through whenever you are ready.',
        ];

        return $byType[$type] ?? $byType['general'];
    }

    /** What a parent should do next — an invitation, not an instruction. */
    private function parentClosing(): string
    {
        /* NOT "reply to this email" — it is sent from a no-reply address, so that
           instruction was false at the moment a parent most needs an answer. The
           centre's real contact details are in the footer below. */
        return "If anything here does not match what your child told you, or you simply want to talk it through, "
             . "please speak to us at pick-up or use the contact details at the bottom of this email.\n\n"
             . "You can also open the full report in your portal and confirm you have seen it.";
    }

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

        $facts = "• What happened: " . ucwords(str_replace('_', ' ', (string) $incident->incident_type)) . "\n"
               . "• When: {$occurred}\n"
               . ($incident->location ? "• Where: {$incident->location}\n" : '')
               . "• Recorded by: {$recorderName}\n";

        /* Written for a parent: what happened to their child and that they were
           cared for, before any of the record-keeping. */
        $parentBody = $this->parentOpening((string) $incident->incident_type, $incident->severity) . "\n\n"
              . "In {$recorderName}'s words:\n" . (trim((string) $incident->description) ?: '(no description recorded)') . "\n\n"
              . "What we did:\n" . (trim((string) $incident->action_taken) ?: '(none recorded)') . "\n\n"
              . ($incident->first_aid_administered ? "First aid was given at the time.\n\n" : '')
              . $facts . "\n"
              . $this->parentClosing();

        /* Written for a colleague who has to do something about it. */
        $staffBody = "ACTION NEEDED — an incident report for {$childName} is waiting for you.\n\n"
              . $facts
              . "• Severity: " . ucfirst((string) ($incident->severity ?: 'n/a')) . "\n"
              . "• Status: " . ucwords(str_replace('_', ' ', (string) $incident->status)) . "\n"
              . ($incident->is_serious_occurrence ? "• SERIOUS OCCURRENCE — CCEYA reporting obligations may apply.\n" : '')
              . "\nWhat happened:\n" . (trim((string) $incident->description) ?: '(no description)') . "\n\n"
              . "Action taken:\n" . (trim((string) $incident->action_taken) ?: '(none recorded)') . "\n\n"
              . "Please review it in the portal, add any notes, and notify the family if that has not been done. "
              . "Until it is reviewed and the parent is notified, this incident is not closed.";

        // Which groups are staff — they get the staff wording, everyone else the parent's.
        $staffEmails = collect();
        if ($centreId) {
            $staffEmails = $staffEmails->merge(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.centre_id', $centreId)->whereIn('ra.role', ['centre_director', 'agency_admin'])
                ->where('ra.active', true)->whereNotNull('u.email')->pluck('u.email'));
        }
        if ($agencyId) {
            $staffEmails = $staffEmails->merge(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')
                ->where('ra.active', true)->whereNotNull('u.email')->pluck('u.email'));
        }
        $staffEmails = $staffEmails->map(fn ($e) => mb_strtolower(trim((string) $e)))->filter()->unique();

        $portal = 'https://app.kiddietrac.com/dashboard.html#incidents';

        /* The SAME wrapper the daily summaries use — the header parents and
           educators recognise, and the agency's own branding on a white-label
           account. An incident report is the last email that should look like it
           came from somewhere else. */
        $agencyRow  = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $agencyName = trim((string) ($agencyRow->name ?? ''));
        $fromName   = $agencyName !== '' ? $agencyName : (string) ($centre->name ?? 'your childcare centre');

        /* Guardian first names, so the email can address the person it is about
           somebody's child rather than opening "Hi there". */
        $nameByEmail = [];
        if ($child) {
            foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $child->family_id)->whereNotNull('u.email')
                ->get(['u.email', 'u.first_name', 'u.preferred_name']) as $g) {
                $nameByEmail[mb_strtolower(trim((string) $g->email))] =
                    trim((string) ($g->preferred_name ?: $g->first_name));
            }
        }

        /* A heading, so a fact can be found rather than read to. */
        $h = fn (string $label) => '<div style="font-size:12px;font-weight:800;letter-spacing:.6px;'
            . 'text-transform:uppercase;color:#1F6FB2;margin:22px 0 6px;">' . e($label) . '</div>';

        $para = fn (string $text) => '<p style="margin:0 0 12px;font-size:15px;line-height:1.6;color:#2A3D5F;">'
            . nl2br(e(trim($text))) . '</p>';

        $cta = fn (string $label) => '<p style="margin:26px 0 0;"><a href="' . e($portal) . '" '
            . 'style="display:inline-block;background:#1F6FB2;color:#ffffff;text-decoration:none;'
            . 'font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">' . e($label) . '</a></p>';

        $signOff = '<p style="margin:26px 0 0;font-size:15px;line-height:1.6;color:#2A3D5F;">'
            . 'Kind regards,<br><strong style="color:#0B1A33;">' . e($fromName) . ' Team</strong></p>';

        $factsHtml = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" '
            . 'style="width:100%;margin:4px 0 0;font-size:14px;color:#2A3D5F;">'
            . '<tr><td style="padding:3px 14px 3px 0;color:#64748B;white-space:nowrap;">What happened</td><td style="padding:3px 0;"><strong>'
                . e(ucwords(str_replace('_', ' ', (string) $incident->incident_type))) . '</strong></td></tr>'
            . '<tr><td style="padding:3px 14px 3px 0;color:#64748B;">When</td><td style="padding:3px 0;">' . e($occurred) . '</td></tr>'
            . ($incident->location ? '<tr><td style="padding:3px 14px 3px 0;color:#64748B;">Where</td><td style="padding:3px 0;">' . e($incident->location) . '</td></tr>' : '')
            . '<tr><td style="padding:3px 14px 3px 0;color:#64748B;">Recorded by</td><td style="padding:3px 0;">' . e($recorderName) . '</td></tr>'
            . '</table>';

        $sent = 0;
        foreach ($recipients as $email) {
            $isStaff = $staffEmails->contains($email);
            $subject = $isStaff
                ? 'ACTION NEEDED: incident report — ' . $childName
                : 'About ' . $childName . ' today';

            $greetName = $nameByEmail[$email] ?? '';

            if ($isStaff) {
                $inner = $para('Hello,')
                    . '<p style="margin:0 0 4px;font-size:15px;line-height:1.6;">'
                    . '<strong style="color:#B3261E;">An incident report for ' . e($childName) . ' is waiting for your review.</strong></p>'
                    . ($incident->is_serious_occurrence
                        ? '<p style="margin:12px 0;padding:11px 15px;background:#FBEAE8;border-left:3px solid #B3261E;'
                          . 'font-size:14px;color:#7A1710;border-radius:0 8px 8px 0;"><strong>Serious occurrence</strong> '
                          . '&mdash; CCEYA reporting obligations may apply.</p>'
                        : '')
                    . $h('The report') . $factsHtml
                    . $h('Severity') . $para(ucfirst((string) ($incident->severity ?: 'not set')))
                    . $h('Status') . $para(ucwords(str_replace('_', ' ', (string) $incident->status)))
                    . $h('What happened') . $para((string) ($incident->description ?: '(no description)'))
                    . $h('Action taken') . $para((string) ($incident->action_taken ?: '(none recorded)'))
                    . $h('What we need from you')
                    . $para('Review it in the portal, add any notes, and notify the family if that has not been done. '
                          . 'Until it is reviewed and the parent is notified, this incident is not closed.')
                    . $cta('Review this incident')
                    . $signOff;
            } else {
                $inner = $para($greetName !== '' ? 'Hello ' . $greetName . ',' : 'Hello,')
                    . $para($this->parentOpening((string) $incident->incident_type, $incident->severity))
                    . $h('In ' . $recorderName . "'s words") . $para((string) ($incident->description ?: '(no description recorded)'))
                    . $h('What we did') . $para((string) ($incident->action_taken ?: '(none recorded)'))
                    . ($incident->first_aid_administered ? $para('First aid was given at the time.') : '')
                    . $h('The details') . $factsHtml
                    . $h('What you can do')
                    . $para('Everything about this report lives in your portal — the full details, '
                          . 'confirming you have seen it, and asking us to talk it through.')
                    /* Deep links, not actions. A link in an email must never BE the
                       acknowledgment: that is a signature kept as evidence, and it has to
                       be the parent who made it — not whoever forwarded the message, and
                       not a mail scanner opening links to check them. */
                    . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:6px 0 0;">'
                    . '<tr><td style="padding:0 8px 8px 0;">'
                      . '<a href="' . e($portal) . '" style="display:inline-block;background:#1F6FB2;color:#ffffff;'
                      . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">'
                      . 'View the full report</a></td></tr>'
                    . '<tr><td style="padding:0 8px 8px 0;">'
                      . '<a href="' . e($portal) . '" style="display:inline-block;background:#ffffff;color:#1F6FB2;'
                      . 'text-decoration:none;font-weight:700;font-size:14px;padding:11px 22px;border-radius:10px;'
                      . 'border:1.5px solid #1F6FB2;">Confirm I have seen it</a></td></tr>'
                    . '<tr><td style="padding:0 8px 0 0;">'
                      . '<a href="' . e($portal) . '" style="display:inline-block;background:#ffffff;color:#1F6FB2;'
                      . 'text-decoration:none;font-weight:700;font-size:14px;padding:11px 22px;border-radius:10px;'
                      . 'border:1.5px solid #1F6FB2;">Ask to meet, or add your own comments</a></td></tr>'
                    . '</table>'
                    . $h('If you have any questions')
                    . $para('Please speak to us at pick-up, or contact ' . $fromName
                          . ' using the details at the bottom of this email. We would far rather answer a question '
                          . 'than leave you wondering.')
                    . $signOff;
            }

            $html = \App\Services\EmailTemplate::wrap($agencyId, $inner,
                [
                    'eyebrow'   => $isStaff ? 'ACTION NEEDED' : 'INCIDENT REPORT',
                    'title'     => $isStaff ? 'Incident awaiting review' : 'About ' . $childName . ' today',
                    'subtitle'  => $occurred,
                    'preheader' => $isStaff
                        ? 'An incident report for ' . $childName . ' needs your review.'
                        : 'A short note about ' . $childName . ' from today.',
                ]);

            /* The parent's copy is BCC'd to the people who will be asked about it —
               the centre's directors, the agency admins, and the educator who wrote
               it. When a parent replies or raises it at pick-up, whoever they reach
               should already know what was sent. BCC, not CC, so the family never
               sees a list of staff addresses. */
            $bcc = [];
            if (! $isStaff) {
                $bcc = $staffEmails->all();
                $authorEmail = DB::table('users')->where('id', $incident->recorded_by_id)->value('email');
                if ($authorEmail) {
                    $bcc[] = mb_strtolower(trim((string) $authorEmail));
                }
                // Never BCC the recipient their own message.
                $bcc = array_values(array_unique(array_diff($bcc, [$email])));
            }

            try {
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($email, $subject, $bcc, $agencyId) {
                    \App\Support\MailScope::agency($m, $agencyId);
                    $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
                    $m->to($email)->subject($subject);
                    if ($bcc) {
                        $m->bcc($bcc);
                    }
                });
                $sent++;
            } catch (\Throwable $e) {
                Log::warning('incident email failed', ['incident' => $incident->id, 'error' => $e->getMessage()]);
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

        abort_unless($this->isStaff($request->user()), 403);

        /* Closing says "this is resolved and the file can rest" — a statement that
           should carry a signature, the same as filing it does. */
        $data = $request->validate([
            'director_notes' => 'nullable|string|max:3000',
            'signature'      => ['required', 'string', 'max:400000'],
            'signature_name' => ['nullable', 'string', 'max:160'],
        ], [
            'signature.required' => 'Please sign before closing this incident.',
        ]);

        if (! preg_match('~^data:image/(png|jpeg);base64,[A-Za-z0-9+/=\s]+$~', $data['signature'])) {
            return response()->json([
                'message' => 'That signature could not be read. Please sign again.',
            ], 422);
        }

        $user = $request->user();
        $signedName = trim((string) ($data['signature_name'] ?? ''))
            ?: trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));

        $incident->update([
            'status'                => 'closed',
            'closed_at'             => now(),
            'director_notes'        => $data['director_notes'] ?? $incident->director_notes,
            'closer_signature_data' => $data['signature'],
            'closer_signed_name'    => $signedName ?: null,
            'closer_signed_at'      => now(),
            'closer_signature_ip'   => substr((string) $request->ip(), 0, 64),
        ]);

        /* Re-file the report, now that it carries the closing sign-off and the
           resolution note. Replaces the copy written when the family was notified. */
        \App\Services\IncidentReportFiler::file($incident->fresh());

        /* On the record as a note too, so the closing shows up in the same thread as
           everything else that happened rather than only as a status flip. */
        try {
            DB::table('incident_notes')->insert([
                'incident_id'  => $incident->id,
                'user_id'      => $user->id,
                'author_name'  => $signedName ?: 'Staff',
                'kind'         => 'note',
                'note'         => 'Incident closed and signed off.'
                    . (trim((string) ($data['director_notes'] ?? '')) !== ''
                        ? ' ' . trim((string) $data['director_notes']) : ''),
                'ip_address'   => substr((string) $request->ip(), 0, 64),
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        } catch (\Throwable $e) {
            Log::info('incident close note failed: ' . $e->getMessage());
        }

        return response()->json(['data' => $incident->fresh()]);
    }

    /* ============================================================
     * Helpers
     * ============================================================ */
    /**
     * Which room is this child in?
     *
     * Placement lives in two places in this schema and either can be the one that
     * is set, so both are consulted: the open enrolment (end_date IS NULL) first,
     * because it is the record of the current arrangement, then the denormalised
     * `children.primary_room_id`. As a last resort a centre with exactly ONE room
     * has no ambiguity to resolve — but a centre with several does, and guessing
     * there would file the incident against the wrong room, so it gives up and the
     * caller asks.
     */
    private function resolveRoomId(int $childId, ?int $centreId): ?int
    {
        $room = DB::table('enrollments')
            ->where('child_id', $childId)
            ->whereNull('end_date')
            ->orderByDesc('id')
            ->value('room_id');
        if ($room) {
            return (int) $room;
        }

        $room = DB::table('children')->where('id', $childId)->value('primary_room_id');
        if ($room) {
            return (int) $room;
        }

        if ($centreId) {
            $rooms = DB::table('rooms')->where('centre_id', $centreId)->pluck('id');
            if ($rooms->count() === 1) {
                return (int) $rooms->first();
            }
        }

        return null;
    }

    /* NOT resolveCentreId(): that name belongs to ResolvesCentreContext, which asks
       "which centre is this PERSON at". This asks "which centre is this CHILD in".
       Defining it here overrode the trait's, so the trait's own fallback passed a User
       into a Child parameter and every guardian's incident list 500'd. */
    private function centreIdForChild(Child $child): ?int
    {
        try {
            $room = $child->currentEnrollment?->room;
            if ($room && isset($room->centre_id)) return (int) $room->centre_id;
        } catch (\Throwable $e) {
            // currentEnrollment may not exist as relation
        }
        // Fallback: lookup via enrollments table
        /* end_date, not ended_at — `enrollments` has no such column, so this
           fallback threw the moment the relation path above missed. */
        $row = DB::table('enrollments')
            ->where('child_id', $child->id)
            ->whereNull('end_date')
            ->join('rooms', 'enrollments.room_id', '=', 'rooms.id')
            ->orderByDesc('enrollments.started_at')
            ->select('rooms.centre_id')
            ->first();
        return $row ? (int) $row->centre_id : null;
    }

    /** The roles a person actually holds. `role_assignments` is the source of truth. */
    private function activeRoles($user): array
    {
        if (! $user) {
            return [];
        }
        return DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->pluck('role')->map(fn ($r) => (string) $r)->unique()->values()->all();
    }

    /**
     * Does this person hold a STAFF role?
     *
     * FAILS CLOSED, and the security branches in this controller ask this rather
     * than comparing primaryRole() to a string. The previous helper called
     * $user->rolesList() — which does not exist on User — and fell back to
     * $user->roles, which is null, so it returned NULL for everybody. Three
     * decisions written as `=== 'guardian'` / `!== 'guardian'` therefore inverted,
     * and a guardian was scoped as staff: 30 other families' draft incidents came
     * back on the parent list. Anything that cannot prove it is staff is a family.
     */
    private function isStaff($user): bool
    {
        return (bool) array_intersect($this->activeRoles($user), [
            'platform_admin', 'agency_admin', 'centre_director',
            'educator', 'home_visitor', 'auditor',
        ]);
    }

    private function primaryRole($user): ?string
    {
        $roles = $this->activeRoles($user);
        // Staff first: someone holding both is staff, and keeps staff powers.
        $order = ['platform_admin', 'agency_admin', 'centre_director',
                  'educator', 'home_visitor', 'auditor', 'guardian'];
        foreach ($order as $r) {
            if (in_array($r, $roles, true)) {
                return $r;
            }
        }
        return null;
    }
}
