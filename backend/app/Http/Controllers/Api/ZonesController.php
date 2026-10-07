<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Activity zones tracking.
 *  Zones are named corners within a centre (Art, Reading, Sensory, Outdoor).
 *  Educators log which zone each child engaged in. Daily report shows
 *  zone-time per child + per zone.
 *
 * WHO SEES WHAT (2026-10-06). Anthony: "activity zone should show one educator selected
 * and not the others in the agency". The screen took /admin/centres, used the first
 * centre it got, and showed every zone and every child's visits there; logging took a
 * raw child id. Now, per person:
 *   - an educator: their own room(s) only — zones pinned to those rooms plus the centre's
 *     shared (room_id NULL) zones, and only the children in those rooms;
 *   - a centre director: their centre(s), every room;
 *   - an agency admin / platform admin (in the agency they switched into): the agency.
 * "View as… <a person>" is a real session for that person, so it gets exactly their
 * scope. Everyone else gets nothing. Fails closed throughout.
 */
final class ZonesController extends Controller
{
    /* SECURITY (2026-08-25): every method here took centre_id / child_id / zone_id
       straight from the request and trusted it. Being signed in was the only gate, so
       any authenticated user could read another agency's zones and log visits against
       another agency's children. Found by the re-parenting sweep after enr#66. */
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;
    use \App\Http\Concerns\ResolvesCentreContext;

    /**
     * The caller's scope in the active agency.
     *
     * @return array{agency:int, role:string, centres:int[], rooms:?array}  rooms null = every room of those centres
     */
    private function zoneScope(Request $request): array
    {
        $agency = (int) ($this->resolveAgencyId($request) ?: 0);
        $none = ['agency' => $agency, 'role' => 'none', 'centres' => [], 'rooms' => []];
        if (! $agency) return $none;
        $user = $request->user();
        $centres = Visibility::centreIds($agency, $user);
        $roles = DB::table('role_assignments')->where('user_id', $user->id)->where('active', 1)->pluck('role')->all();

        if (array_intersect($roles, ['platform_admin', 'agency_admin', 'auditor'])) {
            return ['agency' => $agency, 'role' => 'admin', 'centres' => $centres, 'rooms' => null];
        }
        if (in_array('centre_director', $roles, true)) {
            return ['agency' => $agency, 'role' => 'director', 'centres' => $centres, 'rooms' => null];
        }
        if (in_array('educator', $roles, true)) {
            $rooms = DB::table('educator_rooms as er')->join('rooms as r', 'r.id', '=', 'er.room_id')
                ->where('er.user_id', $user->id)->whereIn('r.centre_id', $centres ?: [0])
                ->pluck('er.room_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            // Only the centres their rooms are in — an educator with no room sees nothing.
            $roomCentres = $rooms ? DB::table('rooms')->whereIn('id', $rooms)->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all() : [];

            return ['agency' => $agency, 'role' => 'educator', 'centres' => $roomCentres, 'rooms' => $rooms];
        }

        return $none;
    }

    private function centreAllowed(array $s, int $centreId): bool
    {
        return $centreId > 0 && in_array($centreId, $s['centres'], true);
    }

    /** A zone (centre + its room, NULL = the whole centre) inside the scope. */
    private function zoneAllowed(array $s, int $centreId, $roomId): bool
    {
        if (! $this->centreAllowed($s, $centreId)) return false;
        if ($s['rooms'] === null || $roomId === null) return true;

        return in_array((int) $roomId, $s['rooms'], true);
    }

    private function childAllowed(array $s, int $childId): bool
    {
        $c = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->where('ch.id', $childId)->whereNull('ch.deleted_at')->first(['f.centre_id', 'ch.primary_room_id']);
        if (! $c || ! $this->centreAllowed($s, (int) $c->centre_id)) return false;
        if ($s['rooms'] === null) return true;

        return $c->primary_room_id !== null && in_array((int) $c->primary_room_id, $s['rooms'], true);
    }

    /** The rooms a request may look at: a requested room inside the scope, else all of the scope's rooms in that centre. */
    private function viewRooms(array $s, int $centreId, ?int $roomId): ?array
    {
        if ($roomId) {
            $inCentre = (int) DB::table('rooms')->where('id', $roomId)->value('centre_id') === $centreId;
            abort_unless($inCentre && ($s['rooms'] === null || in_array($roomId, $s['rooms'], true)), 403, 'That room is not one you can see.');

            return [$roomId];
        }

        return $s['rooms'];   // null = every room (directors/admins)
    }

    /** GET /zones/options — what this person may pick: centres, rooms, children, today. */
    public function options(Request $request): JsonResponse
    {
        $s = $this->zoneScope($request);
        $centres = $s['centres'] ? DB::table('centres')->whereIn('id', $s['centres'])->whereNull('deleted_at')->orderBy('name')->get(['id', 'name']) : collect();
        $roomQ = DB::table('rooms')->whereIn('centre_id', $s['centres'] ?: [0]);
        if ($s['rooms'] !== null) $roomQ->whereIn('id', $s['rooms'] ?: [0]);
        $rooms = $roomQ->orderBy('name')->get(['id', 'name', 'centre_id']);

        $children = [];
        $centreId = (int) $request->query('centre_id', 0);
        if ($centreId && $this->centreAllowed($s, $centreId)) {
            $roomIds = $this->viewRooms($s, $centreId, $request->query('room_id') ? (int) $request->query('room_id') : null);
            $q = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('f.centre_id', $centreId)->whereNull('ch.deleted_at')
                ->where(fn ($w) => $w->whereNull('ch.enrollment_status')->orWhere('ch.enrollment_status', 'enrolled'));
            if ($roomIds !== null) $q->whereIn('ch.primary_room_id', $roomIds ?: [0]);
            $children = $q->orderBy('ch.first_name')->orderBy('ch.last_name')
                ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.photo_url', 'ch.primary_room_id as room_id']);
        }

        return response()->json([
            'role' => $s['role'],
            'centres' => $centres,
            'rooms' => $rooms,
            'children' => $children,
            'today' => AgencyTime::today($s['agency'] ?: null),
            'can_create_whole_centre' => $s['rooms'] === null,
        ]);
    }

    public function listZones(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400, 'centre_id required');
        $s = $this->zoneScope($request);
        abort_unless($this->centreAllowed($s, $centreId), 403, 'That centre is not one you can see.');
        $rooms = $this->viewRooms($s, $centreId, $request->query('room_id') ? (int) $request->query('room_id') : null);
        $q = DB::table('activity_zones')->where('centre_id', $centreId)->where('active', 1);
        // Shared (whole-centre) zones always; room zones only for rooms in view.
        if ($rooms !== null) $q->where(fn ($w) => $w->whereNull('room_id')->orWhereIn('room_id', $rooms ?: [0]));
        $rows = $q->orderBy('display_order')->orderBy('name')->get();

        return response()->json(['data' => $rows]);
    }

    public function createZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'room_id' => 'nullable|integer',
            'name' => 'required|string|max:80',
            'icon' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:20',
            'display_order' => 'nullable|integer',
        ]);
        $this->assertCentre((int) $request->user()->id, (int) $data['centre_id']);
        $s = $this->zoneScope($request);
        abort_unless($this->centreAllowed($s, (int) $data['centre_id']), 403, 'That centre is not one you can see.');
        // An educator adds zones to their own room(s); a whole-centre zone is a director's call.
        if ($s['rooms'] !== null) {
            abort_unless(! empty($data['room_id']) && in_array((int) $data['room_id'], $s['rooms'], true), 403, 'Choose one of your rooms.');
        }

        // A zone pinned to a room must be a room of that same centre, or the zone
        // straddles two tenants and its visits are unattributable.
        if (! empty($data['room_id'])) {
            $roomCentre = (int) DB::table('rooms')->where('id', (int) $data['room_id'])->value('centre_id');
            abort_unless($roomCentre === (int) $data['centre_id'], 422, 'That room is not in this centre.');
        }

        $id = DB::table('activity_zones')->insertGetId([
            'centre_id' => $data['centre_id'],
            'room_id' => $data['room_id'] ?? null,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? '🎨',
            'color' => $data['color'] ?? '#A855F7',
            'display_order' => $data['display_order'] ?? 0,
            'active' => 1,
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function logVisit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'zone_id' => 'required|integer',
            'duration_minutes' => 'nullable|integer|min:1|max:240',
            'notes' => 'nullable|string|max:500',
        ]);

        // Both ends, not just one: the child must be reachable by this user, and the
        // zone must belong to a centre they can reach. Guarding only the child would
        // still let a visit be filed into another agency's zone, and vice versa.
        $this->assertChild((int) $request->user()->id, (int) $data['child_id']);

        $zone = DB::table('activity_zones')->where('id', (int) $data['zone_id'])->first(['centre_id', 'room_id']);
        abort_unless($zone && (int) $zone->centre_id > 0, 422, 'That zone does not exist.');
        $this->assertCentre((int) $request->user()->id, (int) $zone->centre_id);

        // And within the person's own rooms: an educator logs for their rooms' children,
        // in their rooms' (or the centre's shared) zones.
        $s = $this->zoneScope($request);
        abort_unless($this->zoneAllowed($s, (int) $zone->centre_id, $zone->room_id), 403, 'That zone is not in one of your rooms.');
        abort_unless($this->childAllowed($s, (int) $data['child_id']), 403, 'That child is not in one of your rooms.');
        $childCentre = (int) DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->where('ch.id', (int) $data['child_id'])->value('f.centre_id');
        abort_unless($childCentre === (int) $zone->centre_id, 422, 'That child is not at this centre.');

        $now = Carbon::now();
        $endedAt = isset($data['duration_minutes']) ? $now->copy()->addMinutes($data['duration_minutes']) : null;
        $id = DB::table('zone_visits')->insertGetId([
            'child_id' => $data['child_id'],
            'zone_id' => $data['zone_id'],
            'visited_at' => $now,
            'ended_at' => $endedAt,
            'recorded_by_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function dailyReport(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400);
        // It had no check at all: any signed-in user could read any centre's day by id.
        $s = $this->zoneScope($request);
        abort_unless($this->centreAllowed($s, $centreId), 403, 'That centre is not one you can see.');
        $rooms = $this->viewRooms($s, $centreId, $request->query('room_id') ? (int) $request->query('room_id') : null);

        // "Today" is the centre's day, not the server's (UTC rolls over at 8pm in Toronto).
        $zTz = AgencyTime::tzForCentre($centreId);
        $date = $request->query('date') ?: Carbon::now($zTz)->toDateString();
        $start = Carbon::parse($date, $zTz)->startOfDay()->utc();
        $end = Carbon::parse($date, $zTz)->endOfDay()->utc();

        $q = DB::table('zone_visits as zv')
            ->join('activity_zones as az', 'az.id', '=', 'zv.zone_id')
            ->join('children as ch', 'ch.id', '=', 'zv.child_id')
            ->where('az.centre_id', $centreId)
            ->whereBetween('zv.visited_at', [$start, $end]);
        if ($rooms !== null) {
            $q->where(fn ($w) => $w->whereNull('az.room_id')->orWhereIn('az.room_id', $rooms ?: [0]))
              ->whereIn('ch.primary_room_id', $rooms ?: [0]);
        }
        $visits = $q->select('zv.*',
                'az.name as zone_name', 'az.icon', 'az.color',
                DB::raw("CONCAT(ch.first_name,' ',ch.last_name) as child_name"))
            ->orderBy('zv.visited_at')->get();

        $byZone = $visits->groupBy('zone_name')->map(fn ($g) => $g->count());
        $byZoneId = $visits->groupBy('zone_id')->map(fn ($g) => $g->count());
        $byChild = $visits->groupBy('child_name')->map(fn ($g) => $g->map(fn ($v) => ['zone' => $v->zone_name, 'icon' => $v->icon, 'time' => AgencyTime::fmt($v->visited_at, $zTz)])->values());

        return response()->json([
            'date' => $date,
            'total_visits' => $visits->count(),
            'by_zone' => $byZone,
            'by_zone_id' => $byZoneId,
            'by_child' => $byChild,
            'visits' => $visits,
        ]);
    }
}
