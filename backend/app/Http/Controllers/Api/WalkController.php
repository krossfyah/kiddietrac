<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Walks & outings — lightweight, educator-started field trips with live GPS.
 *
 * A "walk" is just a field_trips row (status 'active', transport 'walking') that
 * an educator / home-visitor starts on the spot. The children currently checked
 * in to their centre are auto-attached with an *approved* permission so their
 * parents may watch the live location. GPS pings reuse POST /field-trips/{id}/ping
 * and the live map reuses GET /field-trips/{id}/location.
 */
final class WalkController extends Controller
{
    use ResolvesCentreContext;

    /** Centre ids the current staff member is assigned to. */
    private function staffCentreIds(int $userId): array
    {
        return DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', 1)->whereNotNull('centre_id')
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** Children currently checked in (today, not yet checked out) in these centres. */
    private function presentChildIds(array $centreIds): array
    {
        if (empty($centreIds)) {
            return [];
        }
        $roomIds = DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all();
        if (empty($roomIds)) {
            return [];
        }
        // "Today" is the CENTRE's local day, not the server's UTC day — otherwise after
        // UTC midnight (~8pm Eastern) the day's check-ins land on the PREVIOUS UTC date
        // and every present child vanishes, so the walk/outing form showed no children
        // to select even though kids were checked in. Convert the local day to a UTC
        // range for the (UTC-stored) occurred_at column.
        $tz = \App\Support\AgencyTime::tzForCentre($centreIds[0]);
        $dayStart = \Illuminate\Support\Carbon::now($tz)->startOfDay()->utc()->format('Y-m-d H:i:s');
        $dayEnd   = \Illuminate\Support\Carbon::now($tz)->endOfDay()->utc()->format('Y-m-d H:i:s');
        return DB::table('check_events as ci')
            ->whereIn('ci.room_id', $roomIds)
            ->where('ci.event_type', 'check_in')
            ->whereBetween('ci.occurred_at', [$dayStart, $dayEnd])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                // Same-second re-scan tie-break: a check-out is "later" if its
                // timestamp is greater, OR equal but with a higher id.
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')
                        ->whereColumn('co.id', '>', 'ci.id'))))
            ->distinct('ci.child_id')
            ->pluck('ci.child_id')->map(fn ($v) => (int) $v)->all();
    }

    /** Haversine distance (metres) between two lat/lon points. */
    public static function haversine(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return 2 * $r * asin(min(1.0, sqrt($a)));
    }

    /**
     * Distance walked, estimated steps and duration for a trip, from its GPS pings.
     * Returns ['distance_m'=>int, 'steps_est'=>int, 'duration_min'=>int].
     */
    public static function walkSummary(int $tripId): array
    {
        $pings = DB::table('field_trip_pings')->where('field_trip_id', $tripId)
            ->orderBy('recorded_at')->orderBy('id')
            ->select('lat', 'lon', 'recorded_at')->get();

        $dist = 0.0;
        $prev = null;
        foreach ($pings as $p) {
            if ($prev) {
                $seg = self::haversine((float) $prev->lat, (float) $prev->lon, (float) $p->lat, (float) $p->lon);
                // Ignore GPS jitter (<3 m standing still) and implausible teleports.
                if ($seg >= 3 && $seg <= 250) {
                    $dist += $seg;
                }
            }
            $prev = $p;
        }
        $durMin = 0;
        if ($pings->count() >= 2) {
            $durMin = (int) round((strtotime($pings->last()->recorded_at) - strtotime($pings->first()->recorded_at)) / 60);
        }

        return [
            'distance_m' => (int) round($dist),
            'steps_est' => (int) round($dist / 0.72), // ~0.72 m average stride
            'duration_min' => max(0, $durMin),
        ];
    }

    /** GET /provider/walks/active — the caller's currently-active walk, if any. */
    public function active(Request $request): JsonResponse
    {
        $u = $request->user();
        $trip = DB::table('field_trips')
            ->where('staff_lead_id', $u->id)
            ->where('status', 'active')
            ->whereDate('trip_date', now())
            ->orderByDesc('id')->first();
        if (! $trip) {
            return response()->json(['active' => null]);
        }
        $children = DB::table('field_trip_permissions')->where('field_trip_id', $trip->id)->count();
        $pings = DB::table('field_trip_pings')->where('field_trip_id', $trip->id)->count();
        $sum = self::walkSummary((int) $trip->id);

        return response()->json(['active' => array_merge([
            'id' => $trip->id,
            'title' => $trip->title,
            'destination' => $trip->destination,
            'children' => $children,
            'pings' => $pings,
            'started_at' => $trip->depart_time,
        ], $sum)]);
    }

    /** GET /provider/walks/eligible-children — children clocked in TODAY (selectable for a walk). */
    public function eligibleChildren(Request $request): JsonResponse
    {
        $u = $request->user();
        $centreIds = $this->staffCentreIds($u->id);
        $ids = $this->presentChildIds($centreIds);
        if (empty($ids)) {
            return response()->json(['children' => []]);
        }
        $rows = DB::table('children')->whereIn('id', $ids)
            ->select('id', 'photo_url', 'sex',
                DB::raw("TRIM(CONCAT(first_name, ' ', COALESCE(last_name, ''))) as name"))
            ->orderBy('first_name')->get();

        return response()->json(['children' => $rows]);
    }

    /** POST /provider/walks/start — begin a walk with the SELECTED (checked-in) children. */
    public function start(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'nullable|string|max:120',
            'destination' => 'required|string|max:200',
            'child_ids' => 'required|array|min:1',
            'child_ids.*' => 'integer',
        ]);
        $u = $request->user();
        // Only children currently checked in to this educator's centre may be added.
        $present = $this->presentChildIds($this->staffCentreIds($u->id));
        $selected = array_values(array_intersect(array_map('intval', $data['child_ids']), $present));
        if (empty($selected)) {
            return response()->json(['message' => 'Pick at least one child who is checked in right now.'], 422);
        }
        $centreIds = $this->staffCentreIds($u->id);
        $centreId = $centreIds[0] ?? null;
        $agencyId = $centreId
            ? (int) DB::table('centres')->where('id', $centreId)->value('agency_id')
            : (int) $this->resolveAgencyId($request);
        if (! $centreId) {
            $centreId = (int) DB::table('centres')->where('agency_id', $agencyId)->value('id');
        }

        // Reuse an already-active walk if the educator double-taps Start.
        $existing = DB::table('field_trips')->where('staff_lead_id', $u->id)
            ->where('status', 'active')->whereDate('trip_date', now())
            ->orderByDesc('id')->first();
        if ($existing) {
            $tripId = (int) $existing->id;
        } else {
            $tripId = (int) DB::table('field_trips')->insertGetId([
                'agency_id' => $agencyId,
                'centre_id' => $centreId,
                'title' => ($data['title'] ?? '') !== '' ? $data['title'] : 'Walk / outing',
                // destination is NOT NULL — default it for a spontaneous walk.
                'destination' => ($data['destination'] ?? '') !== '' ? $data['destination'] : 'Local walk',
                'trip_date' => now()->toDateString(),
                'depart_time' => now()->format('H:i:s'),
                'transport_method' => 'walking',
                'cost_per_child' => 0,
                'staff_lead_id' => $u->id,
                'status' => 'active',
                'created_by_id' => $u->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Attach the SELECTED children with an approved permission → their parents can watch.
        $attached = 0;
        foreach ($selected as $cid) {
            $already = DB::table('field_trip_permissions')
                ->where('field_trip_id', $tripId)->where('child_id', $cid)->exists();
            if ($already) {
                continue;
            }
            $guardianUserId = DB::table('guardians as g')
                ->join('children as c', 'c.family_id', '=', 'g.family_id')
                ->where('c.id', $cid)
                ->orderByDesc('g.is_primary')
                ->value('g.user_id');
            DB::table('field_trip_permissions')->insert([
                'field_trip_id' => $tripId,
                'child_id' => $cid,
                'guardian_user_id' => $guardianUserId,
                'status' => 'approved',
                'responded_at' => now(),
                'notes' => 'Auto-attached (walk started by educator)',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $attached++;
        }

        $names = DB::table('children')->whereIn('id', $selected)
            ->selectRaw("TRIM(CONCAT(first_name, ' ', last_name)) as name")
            ->pluck('name')->all();

        return response()->json([
            'id' => $tripId,
            'children' => count($selected),
            'attached' => $attached,
            'child_names' => $names,
        ]);
    }

    /** POST /provider/walks/{id}/end — mark the walk complete. */
    public function end(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $trip = DB::table('field_trips')->where('id', $id)->first();
        abort_unless($trip, 404);
        abort_unless(
            $trip->staff_lead_id === $u->id || $this->userBelongsToAgency($u->id, (int) $trip->agency_id),
            403
        );
        DB::table('field_trips')->where('id', $id)->update([
            'status' => 'completed',
            'return_time' => now()->format('H:i:s'),
            'updated_at' => now(),
        ]);

        return response()->json(['status' => 'completed']);
    }

    /** GET /parent/walks — all recent walks (live + past 30 days) my children were on. */
    public function parentWalks(Request $request): JsonResponse
    {
        $u = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
        if ($familyIds->isEmpty()) {
            return response()->json(['walks' => []]);
        }
        $childIds = DB::table('children')->whereIn('family_id', $familyIds)->pluck('id');
        if ($childIds->isEmpty()) {
            return response()->json(['walks' => []]);
        }
        $rows = DB::table('field_trip_permissions as p')
            ->join('field_trips as t', 't.id', '=', 'p.field_trip_id')
            ->join('children as c', 'c.id', '=', 'p.child_id')
            ->whereIn('p.child_id', $childIds)
            ->where('p.status', 'approved')
            ->whereDate('t.trip_date', '>=', now()->subDays(30)->toDateString())
            ->orderByDesc('t.trip_date')->orderByDesc('t.id')
            ->select('t.id as trip_id', 't.title', 't.destination', 't.status', 't.trip_date',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', COALESCE(c.last_name, ''))) as child_name"))
            ->get()->unique('trip_id')->values();

        $out = $rows->map(function ($w) {
            $sum = self::walkSummary((int) $w->trip_id);
            $pings = DB::table('field_trip_pings')->where('field_trip_id', $w->trip_id)->count();
            return [
                'trip_id' => $w->trip_id, 'title' => $w->title, 'destination' => $w->destination,
                'status' => $w->status, 'child_name' => $w->child_name, 'trip_date' => $w->trip_date,
                'has_location' => $pings > 0,
                'distance_m' => $sum['distance_m'], 'steps_est' => $sum['steps_est'], 'duration_min' => $sum['duration_min'],
            ];
        });

        return response()->json(['walks' => $out]);
    }

    /** GET /parent/active-walks — active walks any of my children are currently on. */
    public function parentActiveWalks(Request $request): JsonResponse
    {
        $u = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
        if ($familyIds->isEmpty()) {
            return response()->json(['walks' => []]);
        }
        $childIds = DB::table('children')->whereIn('family_id', $familyIds)->pluck('id');
        if ($childIds->isEmpty()) {
            return response()->json(['walks' => []]);
        }
        $rows = DB::table('field_trip_permissions as p')
            ->join('field_trips as t', 't.id', '=', 'p.field_trip_id')
            ->join('children as c', 'c.id', '=', 'p.child_id')
            ->whereIn('p.child_id', $childIds)
            ->where('p.status', 'approved')
            ->where('t.status', 'active')
            ->whereDate('t.trip_date', now())
            ->select(
                't.id as trip_id',
                't.title',
                't.destination',
                DB::raw("TRIM(CONCAT(c.first_name, ' ', c.last_name)) as child_name")
            )
            ->get();

        return response()->json(['walks' => $rows]);
    }
}
