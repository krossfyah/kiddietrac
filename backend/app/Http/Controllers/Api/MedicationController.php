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

    /* ONE CENTRE WAS NEVER THE SCOPE (2026-11-13 sweep, after Anthony's "Could not save - Child not in your centre" on
       an immunization exemption). Every method here asked resolveCentreId(), which is ONE centre - an agency admin
       with no centre of their own gets the agency's first. At iLearn (seven centres) the medications screen and the
       admin's "waiting for approval" count showed centre 8 only, every other centre's order answered "not found",
       and a dose could not be logged for a child elsewhere. Now:
         - lists and reads: the children this person may see in the ACTIVE agency (Visibility::childIds);
         - changes: a child at a centre they direct (directableCentre, the rule store() already had);
         - giving a dose (/provider): the children they look after (Visibility::liveChildIds).
       All fail closed. */
    private function visibleMed(Request $request, int $id, bool $toChange = false): ?Medication
    {
        $med = Medication::find($id);
        if (! $med || ! in_array((int) $med->child_id, $this->visibleChildIds($request), true)) {
            return null;
        }
        return (! $toChange || $this->directableCentre($request, (int) $med->child_id)) ? $med : null;
    }

    /* The child's centre when this person may direct it, else 0 (2026-11-05 leak sweep, moved here from store()):
       child_id was only checked to exist, so a director could put a medication order on any agency's child.
       Agency-wide admins pass for any child of the active agency; a director must hold centre_director at the
       child's own centre. */
    private function directableCentre(Request $request, int $childId): int
    {
        $childCentre = (int) DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $childId)->value('f.centre_id');
        $agencyId = (int) $this->resolveAgencyId($request);
        $scope = $agencyId ? \App\Support\CentreScope::ids($request, $agencyId) : [];
        $ok = $childCentre && $this->canAccessChildScoped($request, $childId)
            && (int) DB::table('centres')->where('id', $childCentre)->value('agency_id') === $agencyId
            && ($scope === null || DB::table('role_assignments')->where('user_id', $request->user()->id)
                ->where('active', true)->where('role', 'centre_director')->where('centre_id', $childCentre)->exists());
        return $ok ? $childCentre : 0;
    }

    /** The children this person looks after in the active agency (the staff child-scope rule). */
    private function careChildIds(Request $request): array
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        return $agencyId ? array_map('intval', \App\Support\Visibility::liveChildIds($agencyId, $request->user())) : [];
    }

    public function index(Request $request): JsonResponse
    {
        // Every child this person may see in the active agency, not one centre's (see visibleMed).
        $q = Medication::query()
            ->whereIn('child_id', $this->visibleChildIds($request) ?: [0])
            ->orderByDesc('created_at');

        if ($childId = (int) $request->query('child_id')) {
            $q->where('child_id', $childId);
        }
        if ($status = $request->query('status')) {
            $q->where('status', $status);
        }

        $rows = $q->limit(200)->get();
        return response()->json(['medications' => $this->withPeople($this->hydrate($rows))]);
    }

    /* PICKERS, NOT TYPED USER IDS (2026-11-27). The forms asked for an "Authorizing parent user ID" and a
       "Witness user ID", and the server took any user id at all. Each medication (staff lists only, never the
       parent's) now carries the people who may fill those boxes: the parents of the child's own family, and the
       staff working at the medication's centre. authorize() and give() accept only those people. */
    private function authorizerIds(int $childId): array
    {
        return DB::table('children as c')->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->join('users as u', 'u.id', '=', 'g.user_id')->whereNull('u.deleted_at')
            ->where('c.id', $childId)->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function witnessIds(int $centreId): array
    {
        if (! $centreId) return [];
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereNull('u.deleted_at')->where('ra.active', 1)
            ->where(fn ($q) => $q->where(fn ($w) => $w->whereIn('ra.role', ['centre_director', 'educator'])->where('ra.centre_id', $centreId))
                ->orWhere(fn ($w) => $w->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId)))
            ->pluck('u.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function withPeople($meds)
    {
        if ($meds->isEmpty()) return $meds;
        $byChild = []; $byCentre = [];
        foreach ($meds as $m) {
            $byChild[(int) $m['child_id']] ??= $this->authorizerIds((int) $m['child_id']);
            $byCentre[(int) $m['centre_id']] ??= $this->witnessIds((int) $m['centre_id']);
        }
        $ids = array_unique(array_merge([], ...array_values($byChild), ...array_values($byCentre)));
        $names = DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)]);
        $people = fn (array $list) => collect($list)->map(fn ($id) => ['id' => $id, 'name' => $names[$id] ?? ''])
            ->filter(fn ($p) => $p['name'] !== '')->sortBy('name')->values()->all();
        return $meds->map(function ($m) use ($byChild, $byCentre, $people) {
            $m['authorizers'] = $people($byChild[(int) $m['child_id']] ?? []);
            $m['witnesses'] = $people($byCentre[(int) $m['centre_id']] ?? []);
            return $m;
        });
    }

    public function activeForProvider(Request $request): JsonResponse
    {
        // The children this person looks after (Visibility::liveChildIds, the staff child-scope rule).
        $rows = Medication::query()
            ->whereIn('child_id', $this->careChildIds($request) ?: [0])
            ->where('status', 'active')
            ->orderBy('child_id')
            ->limit(200)
            ->get();

        return response()->json(['medications' => $this->withPeople($this->hydrate($rows))]);
    }

    /**
     * POST /parent/children/{child}/medications — a parent asks the centre to administer
     * something.
     *
     * Files into the SAME queue as a staff-created medication: status 'pending_auth',
     * waiting on a director. A parent asking is not the centre agreeing, and nothing here
     * can make a medication administrable without that authorisation step.
     */
    public function parentStore(Request $request, int $child): JsonResponse
    {
        // Their OWN child, or nothing. A guardian row is the only claim we accept.
        $famId = DB::table('guardians')->where('user_id', $request->user()->id)->value('family_id');
        abort_unless($famId, 403, 'No family linked');
        $childRow = DB::table('children')->where('id', $child)->whereNull('deleted_at')
            ->where('family_id', $famId)->first(['id', 'first_name', 'preferred_name', 'family_id']);
        abort_unless($childRow, 404, 'Child not found');

        $data = $request->validate([
            'name'                   => 'required|string|max:200',
            'strength'               => 'nullable|string|max:100',
            'route'                  => 'nullable|string|max:40',
            'dosage'                 => 'required|string|max:200',
            'frequency'              => 'required|string|max:200',
            'reason'                 => 'nullable|string|max:200',
            'starts_on'              => 'required|date',
            'expires_on'             => 'nullable|date|after_or_equal:starts_on',
            'special_instructions'   => 'nullable|string|max:2000',
            'requires_refrigeration' => 'nullable|boolean',
            'is_prescription'        => 'nullable|boolean',
            'prescribing_physician'  => 'nullable|string|max:160',
            'parent_signature_data'  => 'nullable|string|max:200000',
        ]);

        /* The CHILD's centre, never the request's. A parent carries no centre context worth
           trusting, and this is what puts the row in front of the right staff. */
        $centreId = DB::table('families')->where('id', $childRow->family_id)->value('centre_id');
        abort_unless($centreId, 422, 'This child is not linked to a centre yet');

        $data['child_id']      = (int) $childRow->id;
        $data['centre_id']     = (int) $centreId;
        $data['created_by_id'] = $request->user()->id;
        $data['route']         = $data['route'] ?? 'oral';
        $data['status']        = 'pending_auth';
        /* Signature stamp (2026-11-27): the parent's IP and approximate place, fixed when they sign. */
        if (! empty($data['parent_signature_data'])) {
            $st = \App\Support\SignatureStamp::capture($request);
            $data['parent_signed_ip'] = $st['ip'];
            $data['parent_signed_location'] = $st['location'];
        }

        $med = Medication::create($data);

        $childName = $childRow->preferred_name ?: $childRow->first_name;
        $this->notifyStaffOfRequest((int) $centreId, (string) $childName, (string) $data['name'], (int) $med->id);

        try {
            \App\Support\Audit::write('medication.requested', [
                'summary' => trim($childName) . ' — ' . $data['name'] . ' ' . ($data['dosage'] ?? ''),
                'input'   => $data,
            ]);
        } catch (\Throwable $e) { /* an audit gap must never cost the request */ }

        return response()->json(['medication' => $med], 201);
    }

    /**
     * Tell the centre's staff a parent has asked for something to be administered.
     *
     * Directors, agency admins and educators who can reach that centre — the roles Anthony
     * named. In-app only: this lands in a queue somebody works through, and it is not
     * urgent enough to mail or push at whatever hour a parent happens to file it. Wrapped
     * so a notification failure can never lose the request itself.
     */
    private function notifyStaffOfRequest(int $centreId, string $childName, string $medName, int $medId): void
    {
        try {
            $agencyId = DB::table('centres')->where('id', $centreId)->value('agency_id');
            $staff = DB::table('role_assignments')
                ->where('active', true)
                ->whereIn('role', ['centre_director', 'agency_admin', 'educator', 'platform_admin'])
                ->where(function ($q) use ($centreId, $agencyId) {
                    $q->where('centre_id', $centreId);
                    if ($agencyId) {
                        // Agency-wide roles carry no centre_id but still own this centre.
                        $q->orWhere(function ($q2) use ($agencyId) {
                            $q2->whereNull('centre_id')->where('agency_id', $agencyId);
                        });
                    }
                })
                ->pluck('user_id')->unique()->values();

            if ($staff->isEmpty()) { return; }

            $now  = now();
            $rows = $staff->map(fn ($uid) => [
                'user_id'    => (int) $uid,
                'type'       => 'medication.requested',
                'title'      => 'Medication request from a parent',
                'body'       => $childName . ' — ' . $medName . '. Needs authorisation before it can be given.',
                'data'       => json_encode(['medication_id' => $medId, 'centre_id' => $centreId]),
                'created_at' => $now,
            ])->all();

            DB::table('notifications')->insert($rows);
        } catch (\Throwable $e) { /* never let the notification cost the medication */ }
    }

    public function store(Request $request): JsonResponse
    {
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

        $childCentre = $this->directableCentre($request, (int) $data['child_id']);
        if (! $childCentre) {
            return response()->json(['message' => 'You can only add medications for children at a centre you direct.'], 403);
        }

        // The CHILD's centre (2026-11-13): it was the caller's "first" centre, so an agency admin's order for a child
        // at another centre was filed where that centre's staff never saw it.
        $data['centre_id']     = $childCentre;
        $data['created_by_id'] = $request->user()->id;
        $data['route']         = $data['route'] ?? 'oral';
        $data['status']        = 'pending_auth';

        $med = Medication::create($data);
        return response()->json(['medication' => $med], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $med = $this->visibleMed($request, $id);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }
        $one = $this->hydrate(collect([$med]))->first();
        /* Signature stamp (2026-11-27), on the single authorization only - never the lists. A signature saved by
           authorize() was made then; one saved with the parent's request, when it was filed. */
        if ($one && ! empty($med->parent_signature_data)) {
            $ag = DB::table('centres')->where('id', $med->centre_id)->value('agency_id');
            $one['parent_signature_stamp'] = \App\Support\SignatureStamp::lines(
                $med->authorized_at ?: $med->created_at, $med->parent_signed_ip, $med->parent_signed_location, $ag ? (int) $ag : null);
        }
        return response()->json(['medication' => $one]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $med = $this->visibleMed($request, $id, true);
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
        $med = $this->visibleMed($request, $id, true);
        if (! $med) {
            return response()->json(['message' => 'Medication not found'], 404);
        }

        $data = $request->validate([
            'authorized_by_id'        => 'required|integer|exists:users,id',
            'parent_signature_data'   => 'nullable|string|max:200000',
        ]);
        // Only a parent of this child's own family can be the one who authorized it (see authorizerIds).
        if (! in_array((int) $data['authorized_by_id'], $this->authorizerIds((int) $med->child_id), true)) {
            return response()->json(['message' => 'Choose one of this child\'s parents or guardians.'], 422);
        }

        $med->update([
            'authorized_by_id'      => $data['authorized_by_id'],
            'parent_signature_data' => $data['parent_signature_data'] ?? null,
            // Signature stamp (2026-11-27): follows the signature this call stores (or clears).
            'parent_signed_ip'       => ! empty($data['parent_signature_data']) ? \App\Support\SignatureStamp::capture($request)['ip'] : null,
            'parent_signed_location' => ! empty($data['parent_signature_data']) ? \App\Support\SignatureStamp::capture($request)['location'] : null,
            'authorized_at'         => now(),
            'status'                => 'active',
        ]);

        return response()->json(['medication' => $med->fresh()]);
    }

    public function discontinue(Request $request, int $id): JsonResponse
    {
        $med = $this->visibleMed($request, $id, true);
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
        $data = $request->validate([
            'medication_id' => 'required|integer|exists:medications,id',
            'dose_given'    => 'required|string|max:200',
            'outcome'       => 'nullable|in:given,refused,missed,partial',
            'notes'         => 'nullable|string|max:2000',
            'witness_id'    => 'nullable|integer|exists:users,id',
            'administered_at' => 'nullable|date',
        ]);

        // A child this person looks after - at any of their centres, not the "first" one (2026-11-13).
        $med = Medication::find($data['medication_id']);
        if (! $med || ! in_array((int) $med->child_id, $this->careChildIds($request), true)) {
            return response()->json(['message' => 'Medication not in your centre'], 403);
        }
        if ($med->status !== 'active') {
            return response()->json([
                'message' => 'This medication is not active yet. A director must record the parent\'s authorization before doses are logged.',
            ], 422);
        }
        // A witness is someone working at this centre, and not the person giving the dose.
        if (! empty($data['witness_id'])
            && ((int) $data['witness_id'] === (int) $request->user()->id
                || ! in_array((int) $data['witness_id'], $this->witnessIds((int) $med->centre_id), true))) {
            return response()->json(['message' => 'Choose a witness who works at this centre (not yourself).'], 422);
        }

        $log = MedicationLog::create([
            'medication_id'      => $med->id,
            'child_id'           => $med->child_id,
            'centre_id'          => $med->centre_id,
            'administered_at'    => $data['administered_at'] ?? now(),
            'dose_given'         => $data['dose_given'],
            'outcome'            => $data['outcome'] ?? 'given',
            'notes'              => $data['notes'] ?? null,
            'administered_by_id' => $request->user()->id,
            'witness_id'         => $data['witness_id'] ?? null,
        ]);

        try {
            $out = ['given' => 'given', 'refused' => 'refused', 'missed' => 'missed', 'partial' => 'partly given'][$data['outcome'] ?? 'given'] ?? 'given';
            \App\Support\ChildMomentNotify::after([[
                'child_id' => (int) $med->child_id, 'kind' => 'medication', 'emoji' => '💊',
                'label' => 'Medication ' . $out, 'text' => trim(($med->name ?? $med->medication_name ?? '') . ' · ' . $data['dose_given'], ' ·'),
                'link' => '#medications',
            ]]);
            $log->parent_notified_at = now();
            $log->save();
        } catch (\Throwable $e) {}

        return response()->json(['log' => $log], 201);
    }

    public function logs(Request $request, int $id): JsonResponse
    {
        $med = $this->visibleMed($request, $id);
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
