<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The shared, lightweight waitlist (external_waitlist) — admins + directors.
 * Shows entries from BOTH systems; KiddieTrac can add/edit/remove its OWN
 * (origin='kiddietrac') entries, which the partner (iLearn) pulls back via
 * /integration/waitlist/pull. Partner-origin rows are read-only here.
 */
final class ExternalWaitlistController extends Controller
{
    use ResolvesCentreContext;

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['entries' => [], 'stats' => ['total' => 0, 'waiting' => 0]]);
        }
        $rows = DB::table('external_waitlist')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderByRaw("CASE WHEN priority = 'High' THEN 0 WHEN priority = 'Normal' THEN 1 ELSE 2 END")
            ->orderBy('position')->orderByDesc('id')
            ->get();
        /* Rows linked to a real family (from a provider that closed) show where they came
           from and where they were placed. Names come from centres including archived ones. */
        $centreIds = $rows->pluck('from_centre_id')->merge($rows->pluck('assigned_centre_id'))->filter()->unique()->all();
        $centreNames = $centreIds ? DB::table('centres')->whereIn('id', $centreIds)->pluck('name', 'id')->all() : [];
        $entries = $rows->map(fn ($r) => [
            'id'            => (int) $r->id,
            'family_id'     => $r->family_id ? (int) $r->family_id : null,
            'linked'        => ! empty($r->family_id),
            'from_centre'   => $r->from_centre_id ? ($centreNames[$r->from_centre_id] ?? null) : null,
            'assigned_centre' => $r->assigned_centre_id ? ($centreNames[$r->assigned_centre_id] ?? null) : null,
            'assigned_at'   => $r->assigned_at,
            'external_id'   => $r->external_id,
            'origin'        => $r->origin,
            'editable'      => $r->origin === 'kiddietrac',
            'child_name'    => $r->child_name,
            'child_dob'     => $r->child_dob,
            'age_group'     => $r->age_group,
            'parent_name'   => $r->parent_name,
            'email'         => $r->email,
            'phone'         => $r->phone,
            'desired_start' => $r->desired_start,
            'status'        => $r->status,
            'priority'      => $r->priority,
            'source'        => $r->source,
            'notes'         => $r->notes,
        ])->values();
        return response()->json([
            'entries' => $entries,
            // Where THIS caller may place a family: open centres of the agency, in their scope.
            // assign() applies the same test, so the picker never offers a refusal.
            'centres' => $this->assignableCentres($request, (int) $agencyId),
            'waiting_for_home' => $entries->filter(fn ($e) => $e['linked'] && in_array($e['status'], \App\Support\AgencyWaitlist::OPEN_STATUSES, true))->count(),
            'stats'   => [
                'total'    => $entries->count(),
                'waiting'  => $entries->filter(fn ($e) => strtolower((string) $e['status']) === 'waiting')->count(),
                'approved' => $entries->filter(fn ($e) => strtolower((string) $e['status']) === 'approved')->count(),
                'declined' => $entries->filter(fn ($e) => strtolower((string) $e['status']) === 'declined')->count(),
                'ilearn'   => $entries->filter(fn ($e) => $e['origin'] === 'ilearn')->count(),
                'kt'       => $entries->filter(fn ($e) => $e['origin'] === 'kiddietrac')->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');
        $data = $this->validated($request);

        $id = DB::table('external_waitlist')->insertGetId(array_merge($data, [
            'agency_id'       => $agencyId,
            'origin'          => 'kiddietrac',
            'external_source' => 'kiddietrac',
            'external_id'     => 'ktwl-' . Str::lower(Str::random(14)),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]));
        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);

        DB::table('external_waitlist')->where('id', $id)
            ->update(array_merge($this->validated($request), ['updated_at' => now()]));
        return response()->json(['ok' => true, 'id' => $id]);
    }

    /** Approve / decline / re-open a lead (kebab actions). Works on any entry. */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);
        $data = $request->validate(['status' => 'required|string|in:Waiting,Contacted,Approved,Declined']);
        DB::table('external_waitlist')->where('id', $id)
            ->update(['status' => $data['status'], 'updated_at' => now()]);
        return response()->json(['ok' => true, 'id' => $id, 'status' => $data['status']]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);

        // Soft-delete (bump updated_at so the partner's pull picks up the removal).
        DB::table('external_waitlist')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'id' => $id, 'deleted' => true]);
    }

    /** Email the lead an update, and mark them Contacted. */
    public function reachOut(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_unless(! empty($row->email), 422, 'This lead has no email address on file.');
        $data = $request->validate(['message' => 'required|string|max:5000', 'subject' => 'nullable|string|max:200']);

        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'Our centre');
        $subject = trim((string) ($data['subject'] ?? '')) ?: ('An update on your waitlist enquiry — ' . $agencyName);
        $inner = '<p style="font-size:14px;color:#334155;white-space:pre-wrap;line-height:1.55;margin:0;">' . nl2br(e($data['message'])) . '</p>';
        $html = \App\Services\EmailTemplate::wrap((int) $agencyId, $inner, ['eyebrow' => 'WAITLIST', 'title' => 'A quick update', 'subtitle' => $agencyName]);
        try {
            \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($row, $subject) {
                $m->to($row->email)->subject($subject);
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
            });
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => 'Could not send: ' . $e->getMessage()], 500);
        }
        DB::table('external_waitlist')->where('id', $id)->update(['status' => 'Contacted', 'updated_at' => now()]);
        return response()->json(['ok' => true, 'message' => 'Update emailed to ' . $row->email]);
    }

    /** Convert a lead into a real family + guardian (+ child) and start enrolment. */
    public function enroll(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        /* A row linked to a real family is already IN KiddieTrac: "enrolling" it would create a
           duplicate family with a duplicate child. It is placed with Assign instead (#251). */
        abort_if(! empty($row->family_id), 409, 'This family is already in KiddieTrac. Use Assign to place them with a centre.');
        /* The family lands at a centre YOU run (2026-11-05 leak sweep): this always took the
           agency's lowest-id centre, so a director enrolled leads into somebody else's centre
           (and its roster, billing and parent invites). A centre_id sent by the client must be
           in this agency and in your scope; without one, an agency-wide admin keeps the old
           first-centre default and a director gets their own first centre. */
        $scope = \App\Support\CentreScope::ids($request, (int) $agencyId);
        $agencyCentres = DB::table('centres')->where('agency_id', $agencyId)->orderBy('id')->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($request->filled('centre_id')) {
            $centreId = (int) $request->input('centre_id');
            abort_unless(in_array($centreId, $agencyCentres, true) && \App\Support\CentreScope::canWrite($scope, $centreId),
                403, 'That centre is not one you manage.');
        } elseif ($scope === null) {
            $centreId = (int) ($agencyCentres[0] ?? 0);
        } else {
            $centreId = (int) (array_values(array_intersect($agencyCentres, $scope))[0] ?? 0);
        }
        abort_unless($centreId, 422, 'This agency has no centre to enrol into yet — add a centre first.');

        $famId = DB::transaction(function () use ($row, $centreId, $agencyId, $id) {
            $famId = DB::table('families')->insertGetId([
                'centre_id' => $centreId,
                'family_name' => $row->parent_name ?: 'New family',
                'primary_email' => $row->email ?: null,
                'primary_phone' => $row->phone ?: null,
                'preferred_lang' => 'en-CA',
                'billing_split' => 'single',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! empty($row->email)) {
                $parts = preg_split('/\s+/', trim((string) $row->parent_name), 2);
                $first = ($parts[0] ?? '') ?: 'Parent';
                $last = $parts[1] ?? '';
                $existing = DB::table('users')->where('email', $row->email)->first();
                $userId = $existing ? (int) $existing->id : (int) DB::table('users')->insertGetId([
                    'email' => $row->email,
                    'password' => \Illuminate\Support\Facades\Hash::make(Str::random(16)),
                    'first_name' => $first, 'last_name' => $last,
                    'locale' => 'en-CA', 'timezone' => 'America/Toronto', 'status' => 'invited',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('guardians')->updateOrInsert(
                    ['family_id' => $famId, 'user_id' => $userId],
                    ['relationship' => 'guardian', 'is_primary' => true, 'can_pickup' => true, 'created_at' => now()]
                );
                DB::table('role_assignments')->updateOrInsert(
                    ['user_id' => $userId, 'agency_id' => $agencyId, 'role' => 'guardian'],
                    ['centre_id' => $centreId, 'active' => 1, 'created_at' => now()]
                );
            }
            if (! empty($row->child_name)) {
                $cp = preg_split('/\s+/', trim((string) $row->child_name), 2);
                DB::table('children')->insert([
                    'family_id' => $famId,
                    'first_name' => ($cp[0] ?? '') ?: (string) $row->child_name,
                    'last_name' => $cp[1] ?? '',
                    'date_of_birth' => $row->child_dob ?: null,
                    'enrollment_status' => 'enrolled', 'enrolled_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            DB::table('external_waitlist')->where('id', $id)->update(['status' => 'Enrolled', 'updated_at' => now()]);
            return $famId;
        });

        return response()->json(['ok' => true, 'family_id' => $famId, 'status' => 'Enrolled', 'message' => 'Family created — enrolment started.']);
    }

    /**
     * POST /agency/waitlist/{id}/assign {centre_id}
     * Place a family from the agency waitlist with a new educator / centre. The family moves
     * there and its children go on that centre's own waitlist, where Enrol picks the room.
     * Admins: any open centre in the agency. Directors: only centres they manage.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $data = $request->validate(['centre_id' => ['required', 'integer']]);
        $row = DB::table('external_waitlist')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        abort_unless($row, 404);
        abort_if(empty($row->family_id), 422, 'This entry is a lead, not a family in KiddieTrac. Use Enrol parent instead.');
        abort_unless(in_array($row->status, \App\Support\AgencyWaitlist::OPEN_STATUSES, true), 409, 'This family has already been placed.');

        $centreId = (int) $data['centre_id'];
        $allowed = array_column($this->assignableCentres($request, $agencyId), 'id');
        abort_unless(in_array($centreId, $allowed, true), 403, 'That centre is not one you can place families with.');

        try {
            $res = \App\Support\AgencyWaitlist::assign($row, $centreId, (int) $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'centre' => $res['centre'], 'children' => $res['children'],
            'message' => ($row->parent_name ?: 'The family').' is now on '.$res['centre'].'\'s waitlist. Open that centre\'s waitlist and press Enrol to choose a room and start date.']);
    }

    /**
     * POST /agency/waitlist/from-families {family_ids:[...]}
     * Put families left on a CLOSED centre (no child enrolled) on the agency waitlist and tell
     * admins + directors. Agency-wide admins only: the families sit on a centre nobody now runs.
     */
    public function fromFamilies(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');
        abort_unless(\App\Support\CentreScope::ids($request, $agencyId) === null, 403, 'Only an agency admin can do this.');
        $data = $request->validate(['family_ids' => ['required', 'array', 'min:1', 'max:200'], 'family_ids.*' => ['integer']]);
        $ids = array_values(array_unique(array_map('intval', $data['family_ids'])));

        // Each family must be this agency's, still live, sitting on a CLOSED centre, with no child enrolled.
        $ok = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->whereIn('f.id', $ids)->where('c.agency_id', $agencyId)
            ->whereNull('f.deleted_at')->whereNull('f.departure_applied_at')
            ->where(fn ($q) => $q->whereNotNull('c.deleted_at')->orWhereIn('c.status', ['closed', 'archived', 'inactive', 'deleted']))
            ->whereNotExists(fn ($q) => $q->from('children as ch')->whereColumn('ch.family_id', 'f.id')
                ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at'))
            ->pluck('f.id')->map(fn ($v) => (int) $v)->all();
        $refused = array_values(array_diff($ids, $ok));
        if ($refused) {
            return response()->json(['message' => 'Only families left on a closed centre, with no child enrolled, can be moved to the agency waitlist. Nothing was changed.',
                'refused_family_ids' => $refused], 422);
        }

        $actorId = (int) $request->user()->id;
        $added = [];
        foreach ($ok as $fid) {
            $centreName = (string) DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('f.id', $fid)->value('c.name');
            $added[] = \App\Support\AgencyWaitlist::addFamily($fid, 'Their provider, '.$centreName.', closed.', $actorId);
        }
        $sent = \App\Support\AgencyWaitlist::notify($agencyId, $added, $actorId, 'Their provider closed, so they need to be placed with another educator or centre.');
        $kids = array_sum(array_map(fn ($a) => count($a['entries']), $added));

        return response()->json(['ok' => true, 'children' => $kids, 'emailed' => $sent,
            'message' => $kids.' child'.($kids === 1 ? '' : 'ren').' added to the agency waitlist.'.($sent ? ' '.$sent.' admin(s)/director(s) emailed.' : '')]);
    }

    /** @return list<array{id:int,name:string,city:?string}> open centres of the agency this caller may place families with */
    private function assignableCentres(Request $request, int $agencyId): array
    {
        if (! $agencyId) {
            return [];
        }
        $scope = \App\Support\CentreScope::ids($request, $agencyId);

        return DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotIn('status', ['closed', 'archived', 'inactive', 'deleted'])
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))
            ->orderBy('name')->get(['id', 'name', 'city'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => (string) $c->name, 'city' => $c->city])->all();
    }

    private function validated(Request $request): array
    {
        $d = $request->validate([
            'child_name'    => 'nullable|string|max:191',
            'child_dob'     => 'nullable|date',
            'age_group'     => 'nullable|string|max:60',
            'parent_name'   => 'required|string|max:191',
            'email'         => 'nullable|email|max:191',
            'phone'         => 'nullable|string|max:60',
            'desired_start' => 'nullable|date',
            'status'        => 'nullable|string|max:40',
            'priority'      => 'nullable|in:High,Normal,Low',
            'notes'         => 'nullable|string',
        ]);
        return [
            'child_name'    => $d['child_name'] ?? null,
            'child_dob'     => $d['child_dob'] ?? null,
            'age_group'     => $d['age_group'] ?? null,
            'parent_name'   => $d['parent_name'],
            'email'         => $d['email'] ?? null,
            'phone'         => $d['phone'] ?? null,
            'desired_start' => $d['desired_start'] ?? null,
            'status'        => $d['status'] ?? 'Waiting',
            'priority'      => $d['priority'] ?? 'Normal',
            'source'        => 'KiddieTrac',
            'notes'         => $d['notes'] ?? null,
        ];
    }
}
