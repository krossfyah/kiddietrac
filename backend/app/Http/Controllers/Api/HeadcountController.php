<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audience;
use App\Support\CentreScope;
use App\Support\Live;
use App\Support\RatioRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * ACTIVE SUPERVISION HEADCOUNTS + ACTING SUPERVISOR (2026-10-13).
 *
 * Ontario O. Reg. 137/15 s.11 as amended by O. Reg. 197/26 (in force 2027-01-01): every centre
 * keeps a written supervision policy saying when, how and how often children are COUNTED —
 * including the higher-risk moments (transitions between areas, washroom trips, arrival and
 * dismissal, field trips, going in and out of the playground, parking lots, blocked
 * sightlines) — and the steps when a child is unaccounted for. New s.6.1(2): the policy names
 * who acts for the supervisor when the supervisor is off-site and something urgent happens.
 *
 * A headcount is a name-to-face check: the educator ticks each child SIGNED IN to the room
 * (or out on the walk) as they see them. EXPECTED is decided here, never by the phone — the
 * children present at the moment of saving — so a list loaded ten minutes ago cannot make a
 * child disappear from the record. Anyone not ticked is MISSING; the educator gets an urgent
 * screen with one tap to alert the director(s), the agency admins and the acting supervisors
 * (bell + push, never SMS), and a link to the serious-occurrence form.
 *
 * Scope: agency from X-Active-Agency-Id (validated, as DrillController); directors their
 * centres, educators their centres AND their assigned rooms (RoomController's rule); closed
 * centres never appear (Live). Fails closed throughout.
 */
final class HeadcountController extends Controller
{
    public const CONTEXTS = [
        'routine' => 'Routine check',
        'transition' => 'Transition',
        'washroom' => 'Washroom',
        'outdoor_out' => 'Outdoor out',
        'outdoor_in' => 'Outdoor in',
        'arrival' => 'Arrival',
        'dismissal' => 'Dismissal',
        'field_trip' => 'Field trip',
        'other' => 'Other',
    ];

    private const MANAGERS = ['agency_admin', 'platform_admin', 'centre_director'];

    // ─── scope ───────────────────────────────────────────────────────

    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            return $active;
        }
        if ($active && (clone $roles)->where(function ($w) use ($active) {
                $w->where('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id'));
            })->exists()) return $active;
        $id = (int) ((clone $roles)->whereNotNull('agency_id')->value('agency_id')
            ?: DB::table('centres')->whereIn('id', (clone $roles)->whereNotNull('centre_id')->pluck('centre_id'))->value('agency_id'));
        abort_unless($id, 403);

        return $id;
    }

    /** Open centres this caller may see, as [id => name]. */
    private function centres(Request $request, int $agencyId): array
    {
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->where('agency_id', $agencyId)->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return $q->orderBy('name')->pluck('name', 'id')->all();
    }

    private function roles(int $uid): array
    {
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->pluck('role')->all();
    }

    private function isManager(int $uid): bool
    {
        return (bool) array_intersect($this->roles($uid), self::MANAGERS);
    }

    private function isAgencyWide(int $uid): bool
    {
        return (bool) array_intersect($this->roles($uid), ['agency_admin', 'platform_admin']);
    }

    /**
     * Rooms an educator is limited to (RoomController::assignedRoomIds, same rule): null =
     * not room-restricted (managers; an educator with no assignments yet falls back to the
     * centre); a home visitor with none gets none.
     */
    private function roomScope(int $uid): ?array
    {
        $roles = $this->roles($uid);
        if (array_intersect($roles, self::MANAGERS)) return null;
        if (! Schema::hasTable('educator_rooms')) return null;
        $ids = DB::table('educator_rooms')->where('user_id', $uid)->pluck('room_id')->map(fn ($v) => (int) $v)->all();
        if (! $ids && in_array('home_visitor', $roles, true)) return [];

        return $ids ?: null;
    }

    private function roomAllowed(Request $request, int $agencyId, object $room): bool
    {
        if (! isset($this->centres($request, $agencyId)[(int) $room->centre_id])) return false;
        $rs = $this->roomScope((int) $request->user()->id);

        return $rs === null || in_array((int) $room->id, $rs, true);
    }

    private function tripAllowed(Request $request, int $agencyId, object $trip): bool
    {
        return (int) $trip->agency_id === $agencyId && isset($this->centres($request, $agencyId)[(int) $trip->centre_id]);
    }

    // ─── who is expected ─────────────────────────────────────────────

    /** Children signed in to the room right now (the ratio engine's own definition). */
    private function presentInRoom(object $room): array
    {
        $ids = RatioRules::childIdsPresent($room, Carbon::now());
        if (! $ids) return [];

        return DB::table('children')->whereIn('id', $ids)->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    /** Children on a walk / outing (approved permission rows). */
    private function onTrip(object $trip): array
    {
        return DB::table('field_trip_permissions as p')->join('children as c', 'c.id', '=', 'p.child_id')
            ->where('p.field_trip_id', $trip->id)->where('p.status', 'approved')->whereNull('c.deleted_at')
            ->distinct()->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }

    private function childRows(array $ids): array
    {
        if (! $ids) return [];

        return DB::table('children')->whereIn('id', $ids)->orderBy('first_name')->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'preferred_name', 'photo_url', 'gender'])
            ->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => trim(($c->preferred_name ?: $c->first_name) . ' ' . $c->last_name),
                'first_name' => $c->preferred_name ?: $c->first_name,
                'initials' => strtoupper(mb_substr((string) $c->first_name, 0, 1) . mb_substr((string) $c->last_name, 0, 1)),
                'photo_url' => $c->photo_url,
                'gender' => $c->gender,
            ])->values()->all();
    }

    /** [id => "First L."] for showing missing children compactly. */
    private function shortNames(array $ids): array
    {
        if (! $ids) return [];

        return DB::table('children')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'preferred_name'])
            ->mapWithKeys(fn ($c) => [(int) $c->id => trim(($c->preferred_name ?: $c->first_name) . ' ' . mb_substr((string) $c->last_name, 0, 1) . ($c->last_name ? '.' : ''))])
            ->all();
    }

    // ─── acting supervisors ──────────────────────────────────────────

    /**
     * The centre's acting supervisors, in order — only people who still hold an active role
     * at this centre (or are an agency admin of its agency) and can sign in. Someone who left
     * must not be the name on the urgent screen.
     */
    public static function actingSupervisors(int $centreId): array
    {
        if (! Schema::hasTable('centre_acting_supervisors')) return [];
        $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');

        return DB::table('centre_acting_supervisors as a')->join('users as u', 'u.id', '=', 'a.user_id')
            ->where('a.centre_id', $centreId)->whereNull('u.deleted_at')
            ->where(fn ($w) => $w->whereNull('u.status')->orWhereNotIn('u.status', Audience::OFF_STATUSES))
            ->whereExists(function ($q) use ($centreId, $agencyId) {
                $q->select(DB::raw(1))->from('role_assignments as ra')->whereColumn('ra.user_id', 'a.user_id')->where('ra.active', true)
                    ->where(fn ($w) => $w->where(fn ($x) => $x->where('ra.centre_id', $centreId)->whereIn('ra.role', ['centre_director', 'educator']))
                        ->orWhere(fn ($x) => $x->where('ra.agency_id', $agencyId)->where('ra.role', 'agency_admin')));
            })
            ->orderBy('a.position')->orderBy('a.id')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.phone'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'phone' => $u->phone ?: null])
            ->values()->all();
    }

    /** People who may be named: staff (director / educator) at the centre, plus agency admins. */
    private function actingCandidates(int $agencyId, int $centreId): array
    {
        $ids = DB::table('role_assignments')->where('active', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('centre_id', $centreId)->whereIn('role', ['centre_director', 'educator']))
                ->orWhere(fn ($x) => $x->where('agency_id', $agencyId)->where('role', 'agency_admin')))
            ->get(['user_id', 'role'])->groupBy('user_id');
        if ($ids->isEmpty()) return [];
        $label = ['centre_director' => 'Director', 'educator' => 'Educator', 'agency_admin' => 'Agency admin'];

        return DB::table('users')->whereIn('id', $ids->keys()->all())->whereNull('deleted_at')
            ->where(fn ($w) => $w->whereNull('status')->orWhereNotIn('status', Audience::OFF_STATUSES))
            ->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name),
                'role' => implode(', ', array_unique(array_map(fn ($r) => $label[$r] ?? $r, $ids[$u->id]->pluck('role')->all())))])
            ->values()->all();
    }

    private function canManageCentre(Request $request, int $agencyId, int $centreId): bool
    {
        $uid = (int) $request->user()->id;
        if (! isset($this->centres($request, $agencyId)[$centreId])) return false;
        if ($this->isAgencyWide($uid)) return true;

        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where('role', 'centre_director')->where('centre_id', $centreId)->exists();
    }

    /** GET /headcounts/acting/{centre} — the list and who could be added. Managers only. */
    public function actingShow(Request $request, int $centre): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canManageCentre($request, $agencyId, $centre), 403, 'Only an agency admin or this centre\'s director can change this.');

        return response()->json(['centre_id' => $centre, 'centre_name' => DB::table('centres')->where('id', $centre)->value('name'),
            'acting' => self::actingSupervisors($centre), 'candidates' => $this->actingCandidates($agencyId, $centre)]);
    }

    /** POST /headcounts/acting/{centre} { user_ids: [ordered] } */
    public function actingSave(Request $request, int $centre): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canManageCentre($request, $agencyId, $centre), 403, 'Only an agency admin or this centre\'s director can change this.');
        $d = $request->validate(['user_ids' => ['present', 'array', 'max:10'], 'user_ids.*' => ['integer']]);
        $allowed = array_column($this->actingCandidates($agencyId, $centre), 'id');
        $ids = array_values(array_unique(array_map('intval', $d['user_ids'])));
        foreach ($ids as $id) abort_unless(in_array($id, $allowed, true), 422, 'Pick people who work at this centre.');
        $by = (int) $request->user()->id;
        DB::transaction(function () use ($centre, $ids, $by) {
            DB::table('centre_acting_supervisors')->where('centre_id', $centre)->delete();
            foreach ($ids as $i => $uid) {
                DB::table('centre_acting_supervisors')->insert(['centre_id' => $centre, 'user_id' => $uid, 'position' => $i + 1,
                    'set_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $list = self::actingSupervisors($centre);
        $this->audit($request, $agencyId, 'centre.acting_supervisors', 'centre', $centre,
            'Acting supervisor(s) for ' . DB::table('centres')->where('id', $centre)->value('name') . ': ' . (implode(', ', array_column($list, 'name')) ?: 'none'));

        return response()->json(['ok' => true, 'acting' => $list]);
    }

    // ─── taking a headcount ──────────────────────────────────────────

    /** GET /headcounts/start?room_id= | trip_id= — the checklist. */
    public function start(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        [$kind, $obj, $centreId, $label, $expected] = $this->target($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $last = DB::table('headcounts')->where('agency_id', $agencyId)
            ->where($kind === 'room' ? 'room_id' : 'field_trip_id', $obj->id)->orderByDesc('taken_at')->first(['taken_at', 'all_accounted', 'counted_count', 'expected_count']);

        return response()->json([
            'kind' => $kind, 'room_id' => $kind === 'room' ? (int) $obj->id : null, 'trip_id' => $kind === 'trip' ? (int) $obj->id : null,
            'centre_id' => $centreId, 'centre_name' => DB::table('centres')->where('id', $centreId)->value('name'), 'label' => $label,
            'children' => $this->childRows($expected),
            'contexts' => self::CONTEXTS, 'default_context' => $kind === 'trip' ? 'field_trip' : 'routine',
            'acting' => self::actingSupervisors($centreId),
            'last' => $last ? ['local' => Carbon::parse($last->taken_at, 'UTC')->setTimezone($tz)->format('g:i A'),
                'minutes_ago' => (int) Carbon::parse($last->taken_at, 'UTC')->diffInMinutes(now()), 'all_accounted' => (bool) $last->all_accounted,
                'counted' => (int) $last->counted_count, 'expected' => (int) $last->expected_count] : null,
            'timezone' => $tz,
        ]);
    }

    /** Resolve room_id / trip_id into [kind, row, centre_id, label, expected child ids]. */
    private function target(Request $request, int $agencyId): array
    {
        $roomId = (int) $request->input('room_id');
        $tripId = (int) $request->input('trip_id');
        if ($tripId) {
            $trip = DB::table('field_trips')->where('id', $tripId)->first();
            abort_unless($trip && $this->tripAllowed($request, $agencyId, $trip), 404, 'That walk was not found.');

            return ['trip', $trip, (int) $trip->centre_id, trim((string) ($trip->title ?: 'Walk / outing')) . ($trip->destination ? ' · ' . $trip->destination : ''), $this->onTrip($trip)];
        }
        abort_unless($roomId, 422, 'Choose a room.');
        $room = DB::table('rooms')->where('id', $roomId)->first();
        abort_unless($room && $this->roomAllowed($request, $agencyId, $room), 404, 'That room was not found.');

        return ['room', $room, (int) $room->centre_id, (string) $room->name, $this->presentInRoom($room)];
    }

    /** POST /headcounts { room_id|trip_id, context, location, note, seen_ids[], shown_ids[] } */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $d = $request->validate([
            'room_id' => ['nullable', 'integer'], 'trip_id' => ['nullable', 'integer'],
            'context' => ['required', 'in:' . implode(',', array_keys(self::CONTEXTS))],
            'location' => ['nullable', 'string', 'max:200'], 'note' => ['nullable', 'string', 'max:2000'],
            'seen_ids' => ['present', 'array', 'max:500'], 'seen_ids.*' => ['integer'],
            'shown_ids' => ['nullable', 'array', 'max:500'], 'shown_ids.*' => ['integer'],
        ]);
        [$kind, $obj, $centreId, $label, $expected] = $this->target($request, $agencyId);
        abort_unless($expected, 422, $kind === 'trip' ? 'No children are attached to this walk.' : 'No children are signed in to this room right now.');

        /* A child signed in AFTER the list was opened was never on the educator's screen, so
           they could not have been ticked. Don't record them as missing — send the list back
           with them added and let the educator look for them too. */
        if (isset($d['shown_ids'])) {
            $shown = array_map('intval', $d['shown_ids']);
            $new = array_values(array_diff($expected, $shown));
            if ($new) {
                return response()->json(['refresh' => true, 'added' => $this->childRows($new),
                    'message' => count($new) . ' more ' . (count($new) === 1 ? 'child was' : 'children were') . ' signed in since you started. Find them too, then save.'], 409);
            }
        }

        $seen = array_values(array_intersect($expected, array_map('intval', $d['seen_ids'])));
        $missing = array_values(array_diff($expected, $seen));
        $uid = (int) $request->user()->id;
        $id = DB::table('headcounts')->insertGetId([
            'agency_id' => $agencyId, 'centre_id' => $centreId,
            'room_id' => $kind === 'room' ? (int) $obj->id : null, 'field_trip_id' => $kind === 'trip' ? (int) $obj->id : null,
            'taken_by' => $uid, 'context' => $d['context'], 'location' => $d['location'] ?? null,
            'expected_count' => count($expected), 'counted_count' => count($seen), 'all_accounted' => ! $missing,
            'expected_child_ids' => json_encode($expected), 'seen_child_ids' => json_encode($seen), 'missing_child_ids' => json_encode($missing),
            'note' => $d['note'] ?? null, 'taken_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, $missing ? 'headcount.missing' : 'headcount.taken', 'headcount', $id,
            'Headcount (' . self::CONTEXTS[$d['context']] . ') ' . $label . ': ' . count($seen) . ' of ' . count($expected)
            . ($missing ? ' — NOT accounted for: ' . implode(', ', $this->shortNames($missing)) : ' — all accounted for'));

        $missingRows = $this->childRows($missing);
        $tz = AgencyTime::tz($agencyId);

        return response()->json(['ok' => true, 'id' => $id, 'expected' => count($expected), 'counted' => count($seen),
            'all_accounted' => ! $missing, 'missing' => $missingRows, 'label' => $label, 'centre_id' => $centreId,
            'acting' => $missing ? self::actingSupervisors($centreId) : [],
            'local' => Carbon::now($tz)->format('Y-m-d\TH:i'), 'location' => $d['location'] ?? null, 'context_label' => self::CONTEXTS[$d['context']]]);
    }

    private function loadForAction(Request $request, int $agencyId, int $id): object
    {
        $h = DB::table('headcounts')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($h && isset($this->centres($request, $agencyId)[(int) $h->centre_id]), 404);
        if ($h->room_id && (int) $h->taken_by !== (int) $request->user()->id) {
            $rs = $this->roomScope((int) $request->user()->id);
            abort_unless($rs === null || in_array((int) $h->room_id, $rs, true), 404);
        }

        return $h;
    }

    /** The people an unaccounted-for child is escalated to: directors, agency admins, acting supervisors. */
    private function escalationIds(int $agencyId, int $centreId, int $exceptUid): array
    {
        $ids = DB::table('role_assignments')->where('active', true)
            ->where(fn ($w) => $w->where(fn ($x) => $x->where('role', 'centre_director')->where('centre_id', $centreId))
                ->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)))
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $ids = array_merge($ids, array_column(self::actingSupervisors($centreId), 'id'));

        return array_values(array_diff(array_unique($ids), [$exceptUid]));
    }

    /** POST /headcounts/{id}/alert — one tap: bell + push the director(s), admins, acting supervisors. Never SMS. */
    public function alert(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $h = $this->loadForAction($request, $agencyId, $id);
        abort_if((bool) $h->all_accounted, 422, 'Everyone was accounted for in this headcount.');
        abort_if($h->resolved_at !== null, 422, 'This headcount was already marked as resolved.');
        $uid = (int) $request->user()->id;
        $missing = $this->shortNames(json_decode((string) $h->missing_child_ids, true) ?: []);
        $where = $h->room_id ? (string) DB::table('rooms')->where('id', $h->room_id)->value('name')
            : (string) (DB::table('field_trips')->where('id', $h->field_trip_id)->value('title') ?: 'Walk / outing');
        $centre = (string) DB::table('centres')->where('id', $h->centre_id)->value('name');
        $by = trim((string) DB::table('users')->where('id', $uid)->selectRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) n")->value('n'));
        $tz = AgencyTime::tz($agencyId);
        $at = Carbon::parse($h->taken_at, 'UTC')->setTimezone($tz)->format('g:i A');
        $title = "\u{1F6A8} Child unaccounted for — " . $where;
        $body = implode(', ', $missing) . ' not seen at the ' . $at . ' headcount (' . (self::CONTEXTS[$h->context] ?? $h->context) . ', '
            . $h->counted_count . ' of ' . $h->expected_count . ')' . ($h->location ? ' near ' . $h->location : '') . ' at ' . $centre . '. Raised by ' . $by . '.';

        $to = $this->escalationIds($agencyId, (int) $h->centre_id, $uid);
        $rows = array_map(fn ($u) => ['user_id' => $u, 'type' => 'headcount_missing', 'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => '#headcounts?id=' . $id, 'headcount_id' => $id]), 'created_at' => now()], $to);
        if ($rows) \App\Support\Notify::write($rows);
        foreach ($to as $u) {
            try { app(\App\Services\FcmService::class)->sendToUser($u, $title, $body, '#headcounts?id=' . $id, true); }
            catch (\Throwable $e) { Log::info('headcount alert push failed', ['user' => $u, 'error' => $e->getMessage()]); }
        }
        DB::table('headcounts')->where('id', $id)->update(['alerted_at' => now(), 'alerted_by' => $uid, 'alert_recipients' => count($to), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'headcount.alerted', 'headcount', $id, 'Missing-child alert sent to ' . count($to) . ' people: ' . implode(', ', $missing) . ' (' . $where . ')');

        return response()->json(['ok' => true, 'notified' => count($to), 'acting' => self::actingSupervisors((int) $h->centre_id)]);
    }

    /** POST /headcounts/{id}/resolve { note } — every missing child found. Tells whoever was alerted. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $h = $this->loadForAction($request, $agencyId, $id);
        abort_if((bool) $h->all_accounted, 422, 'Everyone was accounted for in this headcount.');
        abort_if($h->resolved_at !== null, 422, 'Already marked as resolved.');
        $note = (string) ($request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? '');
        $uid = (int) $request->user()->id;
        DB::table('headcounts')->where('id', $id)->update(['resolved_at' => now(), 'resolved_by' => $uid, 'resolution_note' => $note ?: null, 'updated_at' => now()]);
        $missing = $this->shortNames(json_decode((string) $h->missing_child_ids, true) ?: []);
        if ($h->alerted_at) {
            $to = $this->escalationIds($agencyId, (int) $h->centre_id, $uid);
            $title = "\u{2705} Found: " . implode(', ', $missing);
            $body = 'All children are accounted for again' . ($note ? ' — ' . $note : '.') ;
            $rows = array_map(fn ($u) => ['user_id' => $u, 'type' => 'headcount_resolved', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#headcounts?id=' . $id, 'headcount_id' => $id]), 'created_at' => now()], $to);
            if ($rows) \App\Support\Notify::write($rows);
            foreach ($to as $u) {
                try { app(\App\Services\FcmService::class)->sendToUser($u, $title, $body, '#headcounts?id=' . $id); }
                catch (\Throwable $e) { Log::info('headcount resolve push failed', ['user' => $u, 'error' => $e->getMessage()]); }
            }
        }
        $this->audit($request, $agencyId, 'headcount.resolved', 'headcount', $id, 'Missing child found: ' . implode(', ', $missing) . ($note ? ' — ' . $note : ''));

        return response()->json(['ok' => true]);
    }

    // ─── the log and the overview ───────────────────────────────────

    /** GET /headcounts?centre_id=&room_id=&from=&to=&context=&result=missed|ok&id= */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $tz = AgencyTime::tz($agencyId);
        $today = Carbon::now($tz)->toDateString();
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('from')) ? (string) $request->input('from') : Carbon::now($tz)->subDays(6)->toDateString();
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->input('to')) ? (string) $request->input('to') : $today;
        [$fromUtc, $toUtc] = AgencyTime::spanRange($agencyId, $from, $to);
        $cid = (int) $request->input('centre_id');
        $ids = $cid && isset($centres[$cid]) ? [$cid] : array_keys($centres);
        $uid = (int) $request->user()->id;
        $rs = $this->roomScope($uid);

        $q = DB::table('headcounts as h')->leftJoin('users as u', 'u.id', '=', 'h.taken_by')->leftJoin('rooms as r', 'r.id', '=', 'h.room_id')
            ->leftJoin('field_trips as t', 't.id', '=', 'h.field_trip_id')
            ->where('h.agency_id', $agencyId)->whereIn('h.centre_id', $ids ?: [0]);
        if ((int) $request->input('id')) {
            $q->where('h.id', (int) $request->input('id'));
        } else {
            $q->where('h.taken_at', '>=', $fromUtc)->where('h.taken_at', '<', $toUtc);
        }
        if ($rs !== null) $q->where(fn ($w) => $w->whereIn('h.room_id', $rs ?: [0])->orWhere('h.taken_by', $uid));
        if ((int) $request->input('room_id')) $q->where('h.room_id', (int) $request->input('room_id'));
        if (isset(self::CONTEXTS[(string) $request->input('context')])) $q->where('h.context', (string) $request->input('context'));
        if ($request->input('result') === 'missed') $q->where('h.all_accounted', false);
        if ($request->input('result') === 'ok') $q->where('h.all_accounted', true);
        $rows = $q->orderByDesc('h.taken_at')->limit(500)->get(['h.*', 'r.name as room_name', 't.title as trip_title', 'u.first_name', 'u.last_name']);

        $allMissing = [];
        foreach ($rows as $r) $allMissing = array_merge($allMissing, json_decode((string) $r->missing_child_ids, true) ?: []);
        $names = $this->shortNames(array_values(array_unique(array_map('intval', $allMissing))));
        $people = DB::table('users')->whereIn('id', $rows->pluck('alerted_by')->merge($rows->pluck('resolved_by'))->filter()->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)]);

        $out = $rows->map(fn ($r) => [
            'id' => (int) $r->id, 'centre_id' => (int) $r->centre_id, 'centre_name' => $centres[$r->centre_id] ?? '',
            'room_id' => $r->room_id ? (int) $r->room_id : null, 'where' => $r->room_id ? $r->room_name : (($r->trip_title ?: 'Walk / outing') . ' (walk)'),
            'context' => $r->context, 'context_label' => self::CONTEXTS[$r->context] ?? $r->context, 'location' => $r->location, 'note' => $r->note,
            'expected' => (int) $r->expected_count, 'counted' => (int) $r->counted_count, 'all_accounted' => (bool) $r->all_accounted,
            'missing' => array_values(array_map(fn ($i) => $names[(int) $i] ?? ('#' . $i), json_decode((string) $r->missing_child_ids, true) ?: [])),
            'taken_by' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
            'local' => Carbon::parse($r->taken_at, 'UTC')->setTimezone($tz)->format('Y-m-d g:i A'),
            'alerted' => $r->alerted_at ? Carbon::parse($r->alerted_at, 'UTC')->setTimezone($tz)->format('g:i A') . ($r->alerted_by ? ' by ' . ($people[(int) $r->alerted_by] ?? '') : '') : null,
            'resolved' => $r->resolved_at ? Carbon::parse($r->resolved_at, 'UTC')->setTimezone($tz)->format('g:i A') . ($r->resolved_by ? ' by ' . ($people[(int) $r->resolved_by] ?? '') : '') : null,
            'resolution_note' => $r->resolution_note,
            'can_act' => ! $r->all_accounted && ! $r->resolved_at,
        ])->values();

        $roomQ = DB::table('rooms')->whereIn('centre_id', $ids ?: [0]);
        if ($rs !== null) $roomQ->whereIn('id', $rs ?: [0]);

        return response()->json(['from' => $from, 'to' => $to, 'timezone' => $tz, 'contexts' => self::CONTEXTS,
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => $i, 'name' => $n])->values(),
            'rooms' => $roomQ->orderBy('name')->get(['id', 'centre_id', 'name']),
            'headcounts' => $out, 'summary' => ['total' => $out->count(), 'missed' => $out->where('all_accounted', false)->count(),
                'open' => $out->where('can_act', true)->count()]]);
    }

    /** GET /headcounts/overview — per centre: acting supervisors; per room: here now + last headcount. */
    public function overview(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $centres = $this->centres($request, $agencyId);
        $uid = (int) $request->user()->id;
        $rs = $this->roomScope($uid);
        $tz = AgencyTime::tz($agencyId);
        [$dayFrom, $dayTo] = AgencyTime::dayRange($agencyId);
        $out = [];
        foreach ($centres as $cid => $name) {
            $rq = DB::table('rooms')->where('centre_id', $cid);
            if (Schema::hasColumn('rooms', 'active')) $rq->where(fn ($w) => $w->where('active', true)->orWhereNull('active'));
            if ($rs !== null) $rq->whereIn('id', $rs ?: [0]);
            $rooms = [];
            foreach ($rq->orderBy('name')->get() as $room) {
                $last = DB::table('headcounts')->where('room_id', $room->id)->orderByDesc('taken_at')->first(['taken_at', 'all_accounted', 'counted_count', 'expected_count', 'resolved_at']);
                $lastToday = $last && $last->taken_at >= $dayFrom;
                $rooms[] = ['id' => (int) $room->id, 'name' => $room->name, 'present' => count($this->presentInRoom($room)),
                    'last' => $lastToday ? ['local' => Carbon::parse($last->taken_at, 'UTC')->setTimezone($tz)->format('g:i A'),
                        'minutes_ago' => (int) Carbon::parse($last->taken_at, 'UTC')->diffInMinutes(now()),
                        'all_accounted' => (bool) $last->all_accounted, 'resolved' => $last->resolved_at !== null,
                        'counted' => (int) $last->counted_count, 'expected' => (int) $last->expected_count] : null];
            }
            $today = DB::table('headcounts')->where('centre_id', $cid)->where('taken_at', '>=', $dayFrom)->where('taken_at', '<', $dayTo);
            $out[] = ['id' => (int) $cid, 'name' => $name, 'acting' => self::actingSupervisors((int) $cid),
                'can_manage' => $this->canManageCentre($request, $agencyId, (int) $cid), 'rooms' => $rooms,
                'today' => ['total' => (clone $today)->count(), 'missed' => (clone $today)->where('all_accounted', false)->count(),
                    'open' => (clone $today)->where('all_accounted', false)->whereNull('resolved_at')->count()]];
        }
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return response()->json(['centres' => $out, 'is_admin' => $this->isAgencyWide($uid), 'is_manager' => $this->isManager($uid),
            'jurisdiction' => \App\Support\Jurisdictions::forAgency($agencyId)['code'] ?? null,
            'reminders' => ['enabled' => (bool) ($s['headcount_reminders']['enabled'] ?? false), 'minutes' => (int) ($s['headcount_reminders']['minutes'] ?? 30)]]);
    }

    /** POST /headcounts/settings { enabled, minutes } — agency admins. Default OFF. */
    public function settings(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->isAgencyWide((int) $request->user()->id), 403, 'Agency admins only.');
        $d = $request->validate(['enabled' => ['required', 'boolean'], 'minutes' => ['required', 'integer', 'min:10', 'max:240']]);
        DB::transaction(function () use ($agencyId, $d) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['headcount_reminders'] = ['enabled' => (bool) $d['enabled'], 'minutes' => (int) $d['minutes']];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $this->audit($request, $agencyId, 'headcount.reminders', 'agency', $agencyId, 'Headcount reminders ' . ($d['enabled'] ? 'on, every ' . $d['minutes'] . ' minutes' : 'off'));

        return response()->json(['ok' => true, 'enabled' => (bool) $d['enabled'], 'minutes' => (int) $d['minutes']]);
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
