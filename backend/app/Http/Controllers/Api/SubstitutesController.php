<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CentreScope;
use App\Support\Live;
use App\Support\SubstituteCover;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SUBSTITUTES (2026-11-28): the pool, cover requests, and the substitute's own "Open cover requests" (rules in
 * App\Support\SubstituteCover). Managers = agency admins (the agency) and centre directors (their own centres, via
 * CentreScope). Everything is read and written inside the ACTIVE agency; ids from another agency simply are not found.
 */
class SubstitutesController extends Controller
{
    use ResolvesCentreContext;

    private function agency(Request $request): int
    {
        $aid = (int) $this->resolveAgencyId($request);
        abort_unless($aid > 0, 403, 'Choose an agency first.');

        return $aid;
    }

    /** null = agency-wide (admin); else the open centres this director runs. */
    private function scope(Request $request, int $aid): ?array
    {
        $s = CentreScope::ids($request, $aid);

        return $s === null ? null : array_values(array_intersect(Live::centreIds($aid), array_map('intval', $s)));
    }

    private function mayManageCentre(?array $scope, int $centreId): bool
    {
        return $scope === null || in_array($centreId, $scope, true);
    }

    private function names(int $aid): array
    {
        return [DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all(),
            DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')->where('c.agency_id', $aid)->pluck('r.name', 'r.id')->all()];
    }

    private function shape(object $r, array $centres, array $rooms, array $people): array
    {
        return ['id' => (int) $r->id, 'centre_id' => (int) $r->centre_id, 'centre_name' => $centres[(int) $r->centre_id] ?? '',
            'room_id' => $r->room_id ? (int) $r->room_id : null, 'room_name' => $r->room_id ? ($rooms[(int) $r->room_id] ?? '') : '',
            'shift_date' => substr((string) $r->shift_date, 0, 10), 'shift_start' => substr((string) $r->shift_start, 0, 5), 'shift_end' => substr((string) $r->shift_end, 0, 5),
            'when' => SubstituteCover::when($r), 'reason' => $r->reason, 'notes' => $r->notes, 'status' => $r->status,
            'status_label' => SubstituteCover::STATUS[$r->status] ?? ucfirst((string) $r->status),
            'assigned_user_id' => $r->assigned_user_id ? (int) $r->assigned_user_id : null,
            'assigned_name' => $r->assigned_user_id ? ($people[(int) $r->assigned_user_id] ?? '') : '',
            'requested_by' => $people[(int) $r->requested_by_id] ?? '', 'claimed_at' => $r->claimed_at, 'filled_at' => $r->filled_at,
            'on_rota' => (bool) $r->shift_id, 'started' => SubstituteCover::started($r)];
    }

    private function people(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids ? DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all() : [];
    }

    // ── the pool (managers) ─────────────────────────────────────────────────────────────────

    /** GET /operations/substitutes - the pool (active and inactive), people who could be added, and the setting. */
    public function pool(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $scope = $this->scope($request, $aid);
        $live = array_flip(Live::staffIds($aid));
        [$centres] = $this->names($aid);
        $rows = DB::table('substitute_pool as sp')->join('users as u', 'u.id', '=', 'sp.user_id')
            ->where('sp.agency_id', $aid)->orderByDesc('sp.active')->orderBy('u.first_name')
            ->get(['sp.id', 'sp.user_id', 'sp.qualifications', 'sp.availability_notes', 'sp.contact_priority', 'sp.active', 'sp.notify_app', 'sp.notify_email', 'sp.centres',
                'u.first_name', 'u.last_name', 'u.email', 'u.phone'])
            // Leavers are not listed (Live): an account closed, a role switched off or an HR file marked left.
            ->filter(fn ($r) => isset($live[(int) $r->user_id]))
            // A director sees the members who cover one of their centres (no centres listed = covers every centre).
            ->filter(fn ($r) => $scope === null || ! SubstituteCover::centresOf($r->centres) || array_intersect(SubstituteCover::centresOf($r->centres), $scope))
            ->map(function ($r) use ($centres, $scope) {
                $list = SubstituteCover::centresOf($r->centres);
                $r->centre_ids = $list;
                $r->centre_names = $list ? array_values(array_filter(array_map(fn ($c) => $centres[$c] ?? null, $list))) : [];
                $r->name = trim($r->first_name . ' ' . $r->last_name);
                // A director changes only members kept to their own centres (not agency-wide ones, not shared ones).
                $r->may_edit = $scope === null || ($list && ! array_diff($list, $scope));
                unset($r->centres);
                return $r;
            })->values();
        $inPool = DB::table('substitute_pool')->where('agency_id', $aid)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $candIds = array_values(array_diff(Live::staffIds($aid, $scope), $inPool));
        $cands = $candIds ? DB::table('users')->whereIn('id', $candIds)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->values() : [];
        $myCentres = DB::table('centres')->whereIn('id', $scope ?? Live::centreIds($aid))->orderBy('name')->get(['id', 'name']);

        $rooms = DB::table('rooms')->whereIn('centre_id', $myCentres->pluck('id')->all() ?: [0])->orderBy('name')->get(['id', 'centre_id', 'name']);

        return response()->json(['data' => $rows, 'candidates' => $cands, 'centres' => $myCentres, 'rooms' => $rooms,
            'hr_on' => \App\Support\Features::on($aid, 'hr'),
            'settings' => SubstituteCover::settings($aid), 'can_manage_settings' => $scope === null, 'scoped' => $scope !== null]);
    }

    /** POST /operations/substitutes - add someone to the pool or change their entry. */
    public function savePool(Request $request): JsonResponse
    {
        $d = $request->validate([
            'user_id' => 'required|integer',
            'qualifications' => 'nullable|string|max:2000',
            'availability_notes' => 'nullable|string|max:1000',
            'contact_priority' => 'nullable|integer|min:1|max:10',
            'active' => 'nullable|boolean',
            'notify_app' => 'nullable|boolean',
            'notify_email' => 'nullable|boolean',
            'centres' => 'nullable|array',
            'centres.*' => 'integer',
        ]);
        $aid = $this->agency($request);
        $scope = $this->scope($request, $aid);
        // Only the agency's own live staff (a director: staff of their centres) - never someone from outside (2026-11-28).
        abort_unless(in_array((int) $d['user_id'], Live::staffIds($aid, $scope), true), 422, 'Choose someone from your own staff.');
        $centres = array_values(array_unique(array_map('intval', $d['centres'] ?? [])));
        $open = Live::centreIds($aid);
        abort_if(array_diff($centres, $open), 422, 'Choose centres from your agency.');
        if ($scope !== null) {
            abort_unless($centres, 422, 'Choose the centres they cover.');
            abort_if(array_diff($centres, $scope), 403, 'You can only choose centres you direct.');
        }
        $existing = DB::table('substitute_pool')->where('agency_id', $aid)->where('user_id', (int) $d['user_id'])->first();
        if ($existing && $scope !== null) {
            $was = SubstituteCover::centresOf($existing->centres);
            abort_unless($was && ! array_diff($was, $scope), 403, 'This person also covers centres you do not direct. Ask an agency admin to change them.');
        }
        $payload = ['qualifications' => $d['qualifications'] ?? null, 'availability_notes' => $d['availability_notes'] ?? null,
            'contact_priority' => $d['contact_priority'] ?? 5, 'active' => (bool) ($d['active'] ?? true),
            'notify_app' => (bool) ($d['notify_app'] ?? true), 'notify_email' => (bool) ($d['notify_email'] ?? true),
            'centres' => $centres ? json_encode($centres) : null, 'updated_at' => now()];
        if ($existing) {
            DB::table('substitute_pool')->where('id', $existing->id)->update($payload);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('substitute_pool')->insertGetId($payload + ['agency_id' => $aid, 'user_id' => (int) $d['user_id'], 'created_at' => now()]);
        }
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => $existing ? 'substitute.updated' : 'substitute.added',
            'entity_type' => 'user', 'entity_id' => (int) $d['user_id'], 'payload' => json_encode(['centres' => $centres, 'active' => $payload['active']])]);

        return response()->json(['id' => $id, 'status' => $existing ? 'updated' : 'created'], $existing ? 200 : 201);
    }

    /** DELETE /operations/substitutes/{id} - take someone out of the pool (their past cover stays on record). */
    public function removePool(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $scope = $this->scope($request, $aid);
        $row = DB::table('substitute_pool')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($row, 404, 'That substitute was not found.');
        if ($scope !== null) {
            $list = SubstituteCover::centresOf($row->centres);
            abort_unless($list && ! array_diff($list, $scope), 403, 'This person also covers centres you do not direct. Ask an agency admin to remove them.');
        }
        DB::table('substitute_pool')->where('id', $id)->delete();
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'substitute.removed', 'entity_type' => 'user', 'entity_id' => (int) $row->user_id,
            'payload' => json_encode(['centres' => SubstituteCover::centresOf($row->centres)])]);

        return response()->json(['ok' => true]);
    }

    /** PUT /operations/substitutes/settings {require_confirm} - agency admins. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        abort_unless($this->scope($request, $aid) === null, 403, 'Only an agency admin can change this.');
        $d = $request->validate(['require_confirm' => 'required|boolean']);
        $was = SubstituteCover::settings($aid);
        $now = SubstituteCover::saveSettings($aid, $d);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'substitute.settings_saved', 'entity_type' => 'agency', 'entity_id' => $aid,
            'payload' => json_encode(['was' => $was, 'now' => $now])]);

        return response()->json(['settings' => $now]);
    }

    // ── cover requests (managers) ───────────────────────────────────────────────────────────

    /** GET /operations/sub-requests - newest first, the caller's centres. */
    public function requests(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $scope = $this->scope($request, $aid);
        $rows = DB::table('substitute_requests')->where('agency_id', $aid)
            ->whereIn('centre_id', $scope ?? DB::table('centres')->where('agency_id', $aid)->pluck('id')->all() ?: [0])
            ->orderByDesc('shift_date')->orderByDesc('shift_start')->limit(150)->get();
        [$centres, $rooms] = $this->names($aid);
        $people = $this->people(array_merge($rows->pluck('assigned_user_id')->all(), $rows->pluck('requested_by_id')->all()));
        $rows = $rows->map(fn ($r) => $this->shape($r, $centres, $rooms, $people))->values();

        return response()->json(['data' => $rows, 'settings' => SubstituteCover::settings($aid)]);
    }

    /** POST /operations/sub-requests {centre_id, room_id?, shift_date, shift_start, shift_end, reason?, notes?} */
    public function create(Request $request): JsonResponse
    {
        $d = $request->validate([
            'centre_id' => 'required|integer',
            'room_id' => 'nullable|integer',
            'shift_date' => 'required|date_format:Y-m-d',
            'shift_start' => 'required|date_format:H:i',
            'shift_end' => 'required|date_format:H:i|after:shift_start',
            'reason' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:2000',
        ]);
        $aid = $this->agency($request);
        $scope = $this->scope($request, $aid);
        $cid = (int) $d['centre_id'];
        abort_unless(in_array($cid, Live::centreIds($aid), true) && $this->mayManageCentre($scope, $cid), 404, 'That centre was not found.');
        if (! empty($d['room_id'])) {
            abort_unless(DB::table('rooms')->where('id', (int) $d['room_id'])->where('centre_id', $cid)->exists(), 422, 'That room is not in this centre.');
        }
        abort_if($d['shift_date'] . ' ' . $d['shift_start'] <= Carbon::now(\App\Support\AgencyTime::tzForCentre($cid))->format('Y-m-d H:i'), 422, 'Choose a shift that has not started yet.');
        $id = (int) DB::table('substitute_requests')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'room_id' => $d['room_id'] ?? null,
            'requested_by_id' => (int) $request->user()->id, 'shift_date' => $d['shift_date'], 'shift_start' => $d['shift_start'], 'shift_end' => $d['shift_end'],
            'reason' => $d['reason'] ?? null, 'notes' => $d['notes'] ?? null, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $r = DB::table('substitute_requests')->where('id', $id)->first();
        $told = SubstituteCover::announce($aid, $r, (int) $request->user()->id);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'substitute.request_posted', 'entity_type' => 'substitute_request', 'entity_id' => $id,
            'payload' => json_encode(['centre_id' => $cid, 'told' => $told])]);

        return response()->json(['id' => $id, 'notified' => $told], 201);
    }

    private function findManaged(Request $request, int $aid, int $id): object
    {
        $r = DB::table('substitute_requests')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($r && $this->mayManageCentre($this->scope($request, $aid), (int) $r->centre_id), 404, 'That cover request was not found.');

        return $r;
    }

    /** POST /operations/sub-requests/{id}/confirm - a director/admin confirms a claim (when the agency asks for that). */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $r = $this->findManaged($request, $aid, $id);
        $me = (int) $request->user()->id;
        abort_unless($r->status === 'claimed' && $r->assigned_user_id, 422, 'There is no claim waiting on this request.');
        abort_if((int) $r->assigned_user_id === $me, 403, 'Another director or an admin confirms cover you took yourself.');
        // Asked again at confirm: they may have left, stopped covering this centre, or taken another shift since.
        if ($why = SubstituteCover::refusal($aid, $r, (int) $r->assigned_user_id)) {
            abort(422, 'This cannot be confirmed now (' . lcfirst(str_replace('You ', 'they ', $why)) . '). Release it so someone else can take it.');
        }
        $shiftId = null;
        DB::transaction(function () use ($r, $aid, $me, &$shiftId) {
            $f = DB::table('substitute_requests')->where('id', $r->id)->lockForUpdate()->first();
            abort_unless($f && $f->status === 'claimed', 422, 'This request changed a moment ago. Refresh and try again.');
            $shiftId = SubstituteCover::writeShift($aid, $f, (int) $f->assigned_user_id, $me);
            DB::table('substitute_requests')->where('id', $r->id)->update(['status' => 'filled', 'filled_at' => now(), 'confirmed_by_id' => $me, 'shift_id' => $shiftId, 'updated_at' => now()]);
        });
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'substitute.claim_confirmed', 'entity_type' => 'substitute_request', 'entity_id' => $id,
            'payload' => json_encode(['user_id' => (int) $r->assigned_user_id, 'shift_id' => $shiftId])]);
        SubstituteCover::bell($aid, [(int) $r->assigned_user_id], 'Cover confirmed: ' . Carbon::parse(SubstituteCover::startsAt($r))->format('D, M j'),
            SubstituteCover::when($r) . '. You are on for this shift.', '#cover-requests?open=' . $id);

        return response()->json(['ok' => true, 'shift_id' => $shiftId]);
    }

    /** POST /operations/sub-requests/{id}/release - take the shift back from whoever claimed it; the request opens again. */
    public function release(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $r = $this->findManaged($request, $aid, $id);
        abort_unless(in_array($r->status, ['claimed', 'filled'], true) && $r->assigned_user_id, 422, 'Nobody has taken this request.');
        $d = $request->validate(['note' => 'nullable|string|max:300']);
        DB::transaction(function () use ($r) {
            SubstituteCover::cancelShift($r->shift_id ? (int) $r->shift_id : null);
            DB::table('substitute_requests')->where('id', $r->id)->update(['status' => 'open', 'assigned_user_id' => null, 'claimed_at' => null, 'filled_at' => null,
                'confirmed_by_id' => null, 'shift_id' => null, 'updated_at' => now()]);
        });
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'substitute.claim_released', 'entity_type' => 'substitute_request', 'entity_id' => $id,
            'payload' => json_encode(['user_id' => (int) $r->assigned_user_id, 'shift_id' => $r->shift_id])]);
        SubstituteCover::bell($aid, [(int) $r->assigned_user_id], 'Cover released: ' . Carbon::parse(SubstituteCover::startsAt($r))->format('D, M j'),
            SubstituteCover::when($r) . ' is no longer yours.' . (! empty($d['note']) ? ' ' . $d['note'] : ''), '#cover-requests');
        // It is open again: tell the pool, unless it has already started.
        $fresh = DB::table('substitute_requests')->where('id', $id)->first();
        $told = SubstituteCover::started($fresh) ? 0 : SubstituteCover::announce($aid, $fresh, (int) $request->user()->id);

        return response()->json(['ok' => true, 'notified' => $told]);
    }

    /** POST /operations/sub-requests/{id}/cancel - cover no longer needed. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $r = $this->findManaged($request, $aid, $id);
        abort_if($r->status === 'cancelled', 422, 'This request is already cancelled.');
        DB::transaction(function () use ($r) {
            SubstituteCover::cancelShift($r->shift_id ? (int) $r->shift_id : null);
            DB::table('substitute_requests')->where('id', $r->id)->update(['status' => 'cancelled', 'cancelled_at' => now(), 'updated_at' => now()]);
        });
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'substitute.request_cancelled', 'entity_type' => 'substitute_request', 'entity_id' => $id]);
        if ($r->assigned_user_id) {
            SubstituteCover::bell($aid, [(int) $r->assigned_user_id], 'Cover cancelled: ' . Carbon::parse(SubstituteCover::startsAt($r))->format('D, M j'),
                SubstituteCover::when($r) . ' is no longer needed.', '#cover-requests');
        }

        return response()->json(['ok' => true]);
    }

    // ── the substitute's own view ───────────────────────────────────────────────────────────

    /** GET /me/cover-requests - open requests at the centres I cover, and the cover I have taken. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $m = SubstituteCover::member($aid, $me);
        $today = \App\Support\AgencyTime::today($aid);
        [$centres, $rooms] = $this->names($aid);
        $mineRows = DB::table('substitute_requests')->where('agency_id', $aid)->where('assigned_user_id', $me)->whereIn('status', ['claimed', 'filled'])
            ->where('shift_date', '>=', $today)->orderBy('shift_date')->orderBy('shift_start')->get();
        $open = collect();
        if ($m) {
            $cover = SubstituteCover::centresOf($m->centres);
            $open = DB::table('substitute_requests')->where('agency_id', $aid)->where('status', 'open')->where('shift_date', '>=', $today)
                ->whereIn('centre_id', $cover ?: Live::centreIds($aid))->orderBy('shift_date')->orderBy('shift_start')->limit(100)->get()
                ->filter(fn ($r) => ! SubstituteCover::started($r) && in_array((int) $r->centre_id, Live::centreIds($aid), true));
        }
        $people = $this->people($open->pluck('requested_by_id')->merge($mineRows->pluck('requested_by_id'))->all());
        $shape = function ($r) use ($centres, $rooms, $people, $aid, $me) {
            $s = $this->shape($r, $centres, $rooms, $people);
            unset($s['assigned_name']);
            $s['requested_by'] = explode(' ', (string) $s['requested_by'])[0];   // staff by first name only
            if ($r->status === 'open') $s['why_not'] = SubstituteCover::refusal($aid, $r, $me);
            return $s;
        };

        return response()->json(['in_pool' => (bool) $m, 'require_confirm' => SubstituteCover::settings($aid)['require_confirm'],
            'open' => $open->map($shape)->values(), 'mine' => $mineRows->map($shape)->values()]);
    }

    /** POST /operations/sub-requests/{id}/claim - first come, first served. */
    public function claim(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $r = DB::table('substitute_requests')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($r && SubstituteCover::member($aid, $me), 404, 'That cover request was not found.');
        abort_unless($r->status === 'open', 422, 'Someone else has already taken this shift.');
        if ($why = SubstituteCover::refusal($aid, $r, $me)) abort(422, $why . '.');
        $confirm = SubstituteCover::settings($aid)['require_confirm'];
        $shiftId = null;
        DB::transaction(function () use ($r, $aid, $me, $confirm, &$shiftId) {
            $f = DB::table('substitute_requests')->where('id', $r->id)->lockForUpdate()->first();
            abort_unless($f && $f->status === 'open', 422, 'Someone else took this shift a moment ago.');
            if (! $confirm) $shiftId = SubstituteCover::writeShift($aid, $f, $me, $me);
            DB::table('substitute_requests')->where('id', $r->id)->update(['assigned_user_id' => $me, 'claimed_at' => now(),
                'status' => $confirm ? 'claimed' : 'filled', 'filled_at' => $confirm ? null : now(), 'shift_id' => $shiftId, 'updated_at' => now()]);
        });
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'substitute.claimed', 'entity_type' => 'substitute_request', 'entity_id' => $id,
            'payload' => json_encode(['needs_confirm' => $confirm, 'shift_id' => $shiftId])]);
        $name = (string) DB::table('users')->where('id', $me)->value('first_name');
        $when = SubstituteCover::when($r);
        SubstituteCover::bell($aid, SubstituteCover::managers($aid, (int) $r->centre_id, [$me]),
            $confirm ? 'Cover to confirm: ' . $name : 'Cover taken: ' . $name,
            $when . ($confirm ? '. ' . $name . ' offered to take it. Confirm or release it.' : '. ' . $name . ' is taking this shift.'), '#substitutes?open=' . $id);

        return response()->json(['ok' => true, 'status' => $confirm ? 'claimed' : 'filled', 'on_rota' => (bool) $shiftId]);
    }

    /** POST /me/cover-requests/{id}/withdraw - the substitute gives back cover they took, before it starts. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $r = DB::table('substitute_requests')->where('id', $id)->where('agency_id', $aid)->where('assigned_user_id', $me)->first();
        abort_unless($r && in_array($r->status, ['claimed', 'filled'], true), 404, 'That cover was not found.');
        abort_if(SubstituteCover::started($r), 422, 'This shift has started. Call your director instead.');
        DB::transaction(function () use ($r) {
            SubstituteCover::cancelShift($r->shift_id ? (int) $r->shift_id : null);
            DB::table('substitute_requests')->where('id', $r->id)->update(['status' => 'open', 'assigned_user_id' => null, 'claimed_at' => null, 'filled_at' => null,
                'confirmed_by_id' => null, 'shift_id' => null, 'updated_at' => now()]);
        });
        Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'substitute.withdrawn', 'entity_type' => 'substitute_request', 'entity_id' => $id]);
        $name = (string) DB::table('users')->where('id', $me)->value('first_name');
        SubstituteCover::bell($aid, SubstituteCover::managers($aid, (int) $r->centre_id, [$me]), 'Cover given back: ' . $name,
            SubstituteCover::when($r) . ' is open again.', '#substitutes?open=' . $id);
        $fresh = DB::table('substitute_requests')->where('id', $id)->first();
        SubstituteCover::announce($aid, $fresh, $me);

        return response()->json(['ok' => true]);
    }
}
