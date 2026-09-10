<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

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
    /**
     * Every centre this person may OVERSEE, as opposed to the ones they work at.
     *
     * An educator or a centre director is attached to specific centres. An agency admin
     * is attached to none of them and is responsible for all of them, so reading
     * role_assignments.centre_id alone leaves them looking at an empty screen.
     *
     * A platform admin is scoped to whichever agency they are currently viewing, never
     * to everything at once — see the standing rule about agency isolation.
     */
    private function overseenCentreIds(Request $request): array
    {
        $userId = $request->user()->id;
        $ids = $this->staffCentreIds($userId);

        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)
            ->get(['role', 'agency_id']);

        $agencyIds = $roles->where('role', 'agency_admin')->pluck('agency_id')->filter()->all();

        if ($roles->contains('role', 'platform_admin')) {
            // Only the agency actually being viewed. "All agencies" is not a thing here:
            // one map of every child in the platform is exactly what must not exist.
            $active = (int) $request->header('X-Active-Agency-Id');
            if ($active) {
                $agencyIds[] = $active;
            }
        }

        if ($agencyIds) {
            $ids = array_merge($ids, DB::table('centres')->whereIn('agency_id', array_unique($agencyIds))
                ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all());
        }

        return array_values(array_unique($ids));
    }

    private function staffCentreIds(int $userId): array
    {
        return DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', 1)->whereNotNull('centre_id')
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** The agency-local timezone for this user's centre (America/Toronto default).
     *  Used for EVERY walk date/time so trip dates + depart/return times are stored
     *  and compared in the centre's zone, not the server's UTC (the recurring tz bug).
     *  Returns the default zone for non-staff (e.g. a parent), which is fine here. */
    private function staffTz(int $userId): string
    {
        $ids = $this->staffCentreIds($userId);
        return \App\Support\AgencyTime::tzForCentre($ids[0] ?? null);
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
            ->select('lat', 'lon', 'recorded_at', 'accuracy_m')->get();

        $dist = 0.0;
        $prev = null;
        foreach ($pings as $p) {
            // A fix accurate to worse than 100 m contributes noise, not distance. Skipped
            // BEFORE it becomes the previous point, so a bad fix cannot anchor a segment.
            if ($p->accuracy_m !== null && (float) $p->accuracy_m > 100) {
                continue;
            }
            if ($prev) {
                $seg = self::haversine((float) $prev->lat, (float) $prev->lon, (float) $p->lat, (float) $p->lon);
                // Ignore GPS jitter (<3 m standing still) and implausible teleports.
                if ($seg >= 3 && $seg <= 250) {
                    $dist += $seg;
                }
            }
            $prev = $p;
        }

        // Once a walk has ended its distance is settled. Returning the stored value keeps
        // the number identical everywhere it is shown, and stops it drifting if pings are
        // ever pruned — a parent was emailed this figure.
        $stored = DB::table('field_trips')->where('id', $tripId)->value('distance_km');
        if ($stored !== null) {
            $dist = (float) $stored * 1000;
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
            ->whereDate('trip_date', \Illuminate\Support\Carbon::now($this->staffTz($u->id))->toDateString())
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
    /**
     * GET /provider/walks/tracker?date=YYYY-MM-DD
     *
     * Every walk and outing on one day, across the centres this person oversees.
     *
     * Deliberately a day at a time, not a trip at a time: "who is out right now" and
     * "where did Tuesday's group go" are the same question, and a dropdown of individual
     * trips answers neither well.
     *
     * Scoped through the viewer's own centres — the standing rule. A director must never
     * see another agency's children on a map.
     */
    public function tracker(Request $request): JsonResponse
    {
        $u = $request->user();
        $centreIds = $this->overseenCentreIds($request);
        if (! $centreIds) {
            return response()->json(['date' => null, 'trips' => []]);
        }

        $tz = $this->staffTz($u->id);
        $date = $request->query('date')
            ? Carbon::parse($request->query('date'), $tz)->toDateString()
            : Carbon::now($tz)->toDateString();

        $trips = DB::table('field_trips as t')
            ->leftJoin('users as u', 'u.id', '=', 't.staff_lead_id')
            ->whereIn('t.centre_id', $centreIds)
            ->whereDate('t.trip_date', $date)
            ->orderByDesc('t.status')       // active first, then the rest
            ->orderByDesc('t.id')
            ->get([
                't.id', 't.title', 't.destination', 't.status', 't.trip_date',
                't.depart_time', 't.return_time', 't.distance_km', 't.map_token',
                't.transport_method', 't.centre_id', 't.staff_lead_id',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as lead_name"),
                'u.phone as lead_phone',
            ]);

        if ($trips->isEmpty()) {
            return response()->json(['date' => $date, 'trips' => []]);
        }

        $tripIds = $trips->pluck('id')->all();

        // The children, by name. A count tells a director nothing when a parent rings up
        // asking whether their child is on the outing.
        $kids = DB::table('field_trip_permissions as p')
            ->join('children as c', 'c.id', '=', 'p.child_id')
            ->whereIn('p.field_trip_id', $tripIds)
            ->whereNull('c.deleted_at')
            ->get(['p.field_trip_id', 'p.status', 'c.id', 'c.first_name', 'c.last_name', 'c.photo_url'])
            ->groupBy('field_trip_id');

        $centreNames = DB::table('centres')->whereIn('id', $trips->pluck('centre_id')->filter()->unique())
            ->pluck('name', 'id');

        $out = [];
        foreach ($trips as $t) {
            $first = DB::table('field_trip_pings')->where('field_trip_id', $t->id)
                ->orderBy('recorded_at')->orderBy('id')->first(['lat', 'lon', 'recorded_at']);
            $last = DB::table('field_trip_pings')->where('field_trip_id', $t->id)
                ->orderByDesc('recorded_at')->orderByDesc('id')->first(['lat', 'lon', 'recorded_at', 'accuracy_m']);
            $pingCount = DB::table('field_trip_pings')->where('field_trip_id', $t->id)->count();

            $summary = self::walkSummary((int) $t->id);

            // How stale the position is matters more than the position on an active
            // trip — a fix from 40 minutes ago is not "where they are".
            $ageMin = null;
            if ($last && $last->recorded_at) {
                $ageMin = (int) Carbon::parse($last->recorded_at)->diffInMinutes(Carbon::now());
            }

            $out[] = array_merge([
                'id' => (int) $t->id,
                'title' => $t->title,
                'destination' => $t->destination,
                'status' => $t->status,
                'trip_date' => $t->trip_date,
                'depart_time' => $t->depart_time,
                'return_time' => $t->return_time,
                'transport_method' => $t->transport_method,
                'centre_name' => $centreNames[$t->centre_id] ?? null,
                'lead' => [
                    'id' => $t->staff_lead_id ? (int) $t->staff_lead_id : null,
                    'name' => $t->lead_name ?: null,
                    'phone' => $t->lead_phone ?: null,
                ],
                'children' => collect($kids[$t->id] ?? [])->map(fn ($c) => [
                    'id' => (int) $c->id,
                    'name' => trim($c->first_name.' '.$c->last_name),
                    'photo_url' => $c->photo_url,
                    'permission' => $c->status,
                ])->values(),
                'ping_count' => $pingCount,
                // Where they set off from, and where they are now — in words.
                'from' => $first ? [
                    'lat' => (float) $first->lat, 'lon' => (float) $first->lon,
                    'at' => $first->recorded_at,
                    'address' => \App\Support\Geocode::reverse($first->lat, $first->lon),
                ] : null,
                'current' => $last ? [
                    'lat' => (float) $last->lat, 'lon' => (float) $last->lon,
                    'at' => $last->recorded_at,
                    'accuracy_m' => $last->accuracy_m,
                    'age_min' => $ageMin,
                    'address' => \App\Support\Geocode::reverse($last->lat, $last->lon),
                ] : null,
                'map_url' => $t->map_token
                    ? rtrim((string) config('app.url'), '/').'/walk-maps/'.$t->map_token.'.png'
                    : null,
            ], $summary);
        }

        return response()->json(['date' => $date, 'trips' => $out]);
    }

    /**
     * GET /provider/walks/tracker-dates — which days actually have something on them.
     *
     * So the date picker can mark the days worth visiting instead of leaving somebody
     * clicking backwards through empty ones.
     */
    public function trackerDates(Request $request): JsonResponse
    {
        $centreIds = $this->overseenCentreIds($request);
        if (! $centreIds) {
            return response()->json(['dates' => []]);
        }

        $dates = DB::table('field_trips')
            ->whereIn('centre_id', $centreIds)
            ->whereNotNull('trip_date')
            ->orderByDesc('trip_date')
            ->limit(180)
            ->pluck('trip_date')->unique()->values();

        return response()->json(['dates' => $dates]);
    }

    /**
     * GET /provider/walks/geofence
     *
     * Where "home" is, so the educator's own phone can notice it has left and offer to
     * start the walk nobody remembered to start.
     *
     * The device is given the fence and works out the distance ITSELF. It would have
     * been easier to have the app post its coordinates and let the server decide, and
     * that is exactly what makes it the wrong design: it would mean a continuous record
     * of where staff are, kept by us, for a feature whose entire job is to notice one
     * moment. Nothing here is written down, and no location is received. The server
     * learns where anyone is only once a walk actually starts — which is the point at
     * which the parents are told too.
     *
     * Returns nothing usable unless there is something to prompt ABOUT: children signed
     * in, and no walk already running.
     */
    public function geofence(Request $request): JsonResponse
    {
        $userId = (int) $request->user()->id;

        $centreId = DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', 1)->whereNotNull('centre_id')->value('centre_id');
        if (! $centreId) {
            return response()->json(['enabled' => false, 'reason' => 'no_centre']);
        }

        $centre = DB::table('centres')->where('id', $centreId)
            ->whereNull('deleted_at')->first(['id', 'name', 'latitude', 'longitude']);
        if (! $centre || $centre->latitude === null || $centre->longitude === null) {
            /* An address without coordinates cannot be fenced. Said plainly so the app
               can stay quiet rather than guessing from a postcode. */
            return response()->json(['enabled' => false, 'reason' => 'no_coordinates']);
        }

        /* Already out? Then there is nothing to offer. Matches active() exactly —
           this user's own trip, today, still running. */
        $activeWalk = DB::table('field_trips')
            ->where('staff_lead_id', $userId)
            ->where('status', 'active')
            ->whereDate('trip_date', \Illuminate\Support\Carbon::now($this->staffTz($userId))->toDateString())
            ->exists();

        /* Children actually here. Prompting to take nobody for a walk is how a feature
           teaches people to dismiss it. */
        $roomIds = DB::table('rooms')->where('centre_id', $centreId)->pluck('id');
        /* Agency day as instants. This query had the bug TWICE — the arrival and the
           "has since left" subquery each carried their own whereDate, so the test
           could be evaluated against a different day from the arrival it excluded. */
        [$walkFrom, $walkTo] = \App\Support\AgencyTime::dayRangeForCentre((int) $centreId);
        $present = DB::table('check_events as ci')
            ->whereIn('ci.room_id', $roomIds)
            ->where('ci.event_type', 'check_in')
            ->where('ci.occurred_at', '>=', $walkFrom)->where('ci.occurred_at', '<', $walkTo)
            /* use() — the subquery is a CLOSURE, and PHP closures do not inherit
               scope, so $walkFrom/$walkTo were undefined here and every call to this
               endpoint died with a 500. The outer ->where() above reads the same two
               variables and works, which is what made this look like a data problem
               rather than a missing import. Introduced while fixing the whereDate bug
               the comment above describes: the fix that split the day into instants
               put one of its two uses inside a closure. (2026-09-10) */
            ->whereNotExists(function ($q) use ($walkFrom, $walkTo) {
                $q->select(DB::raw(1))->from('check_events as co')
                  ->whereColumn('co.child_id', 'ci.child_id')
                  ->where('co.event_type', 'check_out')
                  ->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                  ->where('co.occurred_at', '>=', $walkFrom)->where('co.occurred_at', '<', $walkTo);
            })
            ->distinct()->count('ci.child_id');

        return response()->json([
            'enabled' => true,
            'centre_id' => (int) $centre->id,
            'centre_name' => $centre->name,
            'latitude' => (float) $centre->latitude,
            'longitude' => (float) $centre->longitude,
            /* Generous on purpose. A phone indoors drifts, and a false "you have left"
               is worse than a late one — it trains people to ignore the prompt. */
            'radius_m' => 200,
            'children_present' => $present,
            'walk_in_progress' => $activeWalk,
        ]);
    }

    public function eligibleChildren(Request $request): JsonResponse
    {
        $u = $request->user();
        $centreIds = $this->staffCentreIds($u->id);
        $ids = $this->presentChildIds($centreIds);
        if (empty($ids)) {
            return response()->json(['children' => []]);
        }
        // NB: the children table has `gender`, NOT `sex` — selecting `sex` threw a
        // 1054 "Unknown column 'sex'" 500, so eligibleChildren ALWAYS errored and the
        // walk/outing form showed no children. The dot avatar only needs photo/name.
        $rows = DB::table('children')->whereIn('id', $ids)
            ->select('id', 'photo_url', 'gender',
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
            ->where('status', 'active')->whereDate('trip_date', \Illuminate\Support\Carbon::now($this->staffTz($u->id))->toDateString())
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
                'trip_date' => \Illuminate\Support\Carbon::now($this->staffTz($u->id))->toDateString(),
                'depart_time' => \Illuminate\Support\Carbon::now($this->staffTz($u->id))->format('H:i:s'),
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

        // Now tell their parents, while it is still happening.
        $this->announceWalk($tripId, $selected, (int) $u->id, 'started');

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
        // Measured through walkSummary so the Daily Overview, the daily log and the
        // parent's email can never disagree about the same walk.
        $km = round(self::walkSummary($id)['distance_m'] / 1000, 2);

        DB::table('field_trips')->where('id', $id)->update([
            'status' => 'completed',
            'return_time' => \Illuminate\Support\Carbon::now($this->staffTz($u->id))->format('H:i:s'),
            'distance_km' => $km,
            'updated_at' => now(),
        ]);

        // The walk belongs in each child's day, not only on a map nobody opens.
        $this->logWalkToDay($id, $u->id);

        /* And tell the parents they are back - until 2026-08-25 the end of a walk
           announced itself to nobody at all. */
        $walkChildIds = DB::table('field_trip_permissions')->where('field_trip_id', $id)
            ->pluck('child_id')->map(fn ($v) => (int) $v)->unique()->all();
        $this->announceWalk($id, $walkChildIds, (int) $u->id, 'ended', ['distance_km' => $km]);

        return response()->json(['status' => 'completed', 'distance_km' => $km]);
    }


    /**
     * Write the finished walk into every attached child's daily log.
     *
     * Wrapped so a logging failure can never fail the walk itself — the educator has
     * already brought the children back, and a 500 at that point helps nobody.
     */
    private function logWalkToDay(int $tripId, int $byUserId): void
    {
        try {
            $trip = DB::table('field_trips')->where('id', $tripId)->first();
            if (! $trip) { return; }

            $childIds = DB::table('field_trip_permissions')->where('field_trip_id', $tripId)
                ->pluck('child_id')->map(fn ($v) => (int) $v)->unique()->all();
            if (! $childIds) { return; }

            $tz = $this->staffTz($byUserId);
            $date = substr((string) $trip->trip_date, 0, 10);
            $start = $trip->depart_time
                ? \Illuminate\Support\Carbon::parse($date.' '.$trip->depart_time, $tz)
                : \Illuminate\Support\Carbon::now($tz);
            $end = $trip->return_time
                ? \Illuminate\Support\Carbon::parse($date.' '.$trip->return_time, $tz)
                : \Illuminate\Support\Carbon::now($tz);
            $minutes = max(0, $start->diffInMinutes($end));

            $payload = [
                'trip_id' => $tripId,
                'destination' => (string) ($trip->destination ?? ''),
                'title' => (string) ($trip->title ?? 'Walk / outing'),
                'started_at' => $start->format('H:i'),
                'ended_at' => $end->format('H:i'),
                'minutes' => $minutes,
                'distance_km' => $trip->distance_km !== null ? (float) $trip->distance_km : null,
            ];

            foreach ($childIds as $cid) {
                // The room the child is actually enrolled in; the centre's first room
                // only as a fallback, because daily_events.room_id is NOT NULL.
                $roomId = DB::table('enrollments')->where('child_id', $cid)
                    ->whereNotNull('room_id')
                    ->where(function ($q) use ($date) {
                        $q->whereNull('end_date')->orWhere('end_date', '>=', $date);
                    })
                    ->orderByDesc('start_date')->value('room_id');
                if (! $roomId) {
                    $roomId = DB::table('rooms')->where('centre_id', $trip->centre_id)->value('id');
                }
                if (! $roomId) { continue; }

                // Ending the same walk twice must not log it twice.
                $already = DB::table('daily_events')->where('child_id', $cid)
                    ->where('event_type', 'walk')
                    ->where('payload', 'like', '%"trip_id":'.$tripId.'%')
                    ->exists();
                if ($already) { continue; }

                DB::table('daily_events')->insert([
                    'child_id' => $cid,
                    'room_id' => (int) $roomId,
                    'event_type' => 'walk',
                    'occurred_at' => $start->clone()->utc(),
                    'payload' => json_encode($payload),
                    'notes' => trim(($payload['destination'] !== '' ? $payload['destination'] : 'Walk')
                        .' · '.$payload['started_at'].'-'.$payload['ended_at']
                        .($payload['distance_km'] ? ' · '.$payload['distance_km'].' km' : '')),
                    'recorded_by_id' => $byUserId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('walk day-log failed', [
                'trip_id' => $tripId, 'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Push each attached child's guardians that their child has gone out.
     *
     * The walk already gives parents permission to watch the live map; without this
     * nothing tells them there is anything to watch, so it is only ever found by
     * somebody who happens to open the app mid-walk.
     *
     * Deep-links to #walks, the guardian live-map screen. Tapping the notification
     * opens the app there — signing in first if the session has lapsed, which is the
     * shell's normal behaviour for any deep link.
     *
     * Wrapped whole: a push failure must never stop an educator starting a walk. They
     * are standing at the door with their coats on.
     */
    /**
     * Tell a walk's parents that it has started, or that everyone is back.
     *
     * Supersedes notifyGuardiansWalkStarted() below, which sent an FCM push and NOTHING
     * ELSE - no bell in the portal, no email. On Amna Ahsan's walk of 25 Aug 2026 all
     * three attached children's guardians had zero registered device tokens, so the push
     * had nowhere to go and the walk was announced to nobody at all. A channel that only
     * reaches people who installed the app is not an announcement.
     *
     * And the END was announced by nothing whatsoever. The children came back and the
     * only trace was a line in the daily log that surfaces in the 18:30 summary. "Back
     * safely" is the half a parent is actually waiting for.
     *
     * Three independent channels, each wrapped so one failing cannot stop the others:
     * an in-app notification (the one everybody gets), a push (whoever has the app), and
     * an email. Suppression is deliberately NOT bypassed - a walk is routine, and an
     * agency's own delivery settings are theirs to decide.
     */
    private function announceWalk(int $tripId, array $childIds, int $byUserId, string $phase, array $extra = []): void
    {
        try {
            if (! $childIds) { return; }

            $trip = DB::table('field_trips')->where('id', $tripId)->first();
            if (! $trip) { return; }

            $ended = $phase === 'ended';
            $tz = $this->staffTz($byUserId);
            $stamp = Carbon::now($tz)->format('g:i A');
            $where = trim((string) ($trip->destination ?? '')) ?: 'a walk';
            $lead = DB::table('users')->where('id', $byUserId)->value('first_name');
            $agencyId = (int) ($trip->agency_id ?? 0);
            $km = isset($extra['distance_km']) ? (float) $extra['distance_km'] : null;

            $children = DB::table('children')->whereIn('id', $childIds)
                ->whereNull('deleted_at')
                ->get(['id', 'first_name', 'preferred_name', 'family_id']);

            $rows = [];
            $recipients = [];

            foreach ($children as $child) {
                $name = trim((string) ($child->preferred_name ?: $child->first_name));

                $title = $ended
                    ? "\u{1F3E1} " . $name . ' is back from the walk'
                    : "\u{1F6B6} " . $name . ' is out on a walk';
                $body = $ended
                    ? ($name . ' got back at ' . $stamp . '.'
                        . ($km ? ' They covered about ' . number_format($km, 1) . ' km.' : '')
                        . ' It is on their daily log.')
                    : ('Off to ' . $where . ($lead ? ' with ' . $lead : '') . ', from ' . $stamp
                        . '. Tap to follow along on the map.');

                $guardians = DB::table('guardians as g')
                    ->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $child->family_id)
                    ->whereNull('u.deleted_at')
                    ->select('g.user_id', 'u.email', 'u.first_name as gfirst')
                    ->get()->unique('user_id');

                foreach ($guardians as $g) {
                    if (! $g->user_id) { continue; }

                    // 1) The bell - the channel that does not depend on owning a phone.
                    $rows[] = [
                        'user_id' => (int) $g->user_id,
                        'type' => $ended ? 'walk_ended' : 'walk_started',
                        'title' => $title,
                        'body' => $body,
                        'data' => json_encode(['link' => '#walks', 'trip_id' => $tripId]),
                        'created_at' => now(),
                    ];

                    // 2) Push, for whoever has the app.
                    try {
                        app(\App\Services\FcmService::class)
                            ->sendToUser((int) $g->user_id, $title, $body, '#walks');
                    } catch (\Throwable $ePush) { /* one dead token must not stop the rest */ }

                    if ($g->email) {
                        $recipients[] = ['to' => $g->email, 'child' => $name];
                    }
                }
            }

            if ($rows) {
                \App\Support\Notify::write($rows);
            }

            // 3) Email.
            $this->mailWalk($agencyId, $recipients, $ended, $where, $lead, $stamp, $km);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('walk announce failed', [
                'trip_id' => $tripId, 'phase' => $phase, 'error' => $e->getMessage(),
            ]);
        }
    }

    /** The parent's note about a walk. One message per guardian, named to their child. */
    private function mailWalk(int $agencyId, array $recipients, bool $ended, string $where,
                              ?string $lead, string $stamp, ?float $km): void
    {
        if (! $recipients) { return; }
        $esc = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');

        $addresses = array_values(array_unique(array_column($recipients, 'to')));
        $bcc = \App\Support\MailOversight::bccFor($agencyId, null, $addresses);
        $i = 0;

        foreach ($recipients as $r) {
            $child = $r['child'];
            $subject = $ended ? $child . ' is back from the walk' : $child . ' is out on a walk';
            $panel = $ended ? ['#DCFCE7', '#15803D', 'BACK SAFELY'] : ['#E4EEF2', '#1F6080', 'OUT NOW'];
            $line = $ended
                ? ($child . ' got back at ' . $stamp . '.' . ($km ? ' About ' . number_format($km, 1) . ' km.' : ''))
                : ($child . ' set off at ' . $stamp . ($lead ? ' with ' . $lead : '') . '.');

            $body = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%">'
                . '<tr><td style="padding:0 0 12px;"><div style="background:' . $panel[0] . ';border-radius:10px;'
                . 'padding:14px 16px;"><strong style="color:' . $panel[1] . ';text-transform:uppercase;'
                . 'font-size:12px;letter-spacing:.06em;">' . $panel[2] . '</strong><br>'
                . '<span style="font-size:15px;color:#0F172A;">' . $esc($line) . '</span></div></td></tr>'
                . ($ended
                    ? '<tr><td style="font-size:15px;line-height:1.6;color:#334155;">Everyone is back, and the '
                        . 'walk has been added to ' . $esc($child) . '&rsquo;s daily log.</td></tr>'
                    : '<tr><td style="font-size:15px;line-height:1.6;color:#334155;">They have gone to '
                        . $esc($where) . '. You can follow along on the live map in the app while they are out, '
                        . 'and we will let you know as soon as they are back.</td></tr>')
                . '</table>';

            try {
                $html = \App\Services\EmailTemplate::wrap($agencyId, $body, [
                    'eyebrow' => $ended ? 'BACK FROM THE WALK' : 'OUT ON A WALK',
                    'title' => $subject,
                ]);
                $copyTo = \App\Support\MailOversight::firstOnly($bcc, $i++);
                \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($r, $subject, $copyTo, $agencyId) {
                    \App\Support\MailScope::agency($m, $agencyId);
                    $m->to($r['to'])->subject($subject);
                    if ($copyTo) { $m->bcc($copyTo); }
                });
            } catch (\Throwable $ex) {
                \Illuminate\Support\Facades\Log::warning('walk email failed', [
                    'to' => $r['to'], 'error' => $ex->getMessage(),
                ]);
            }
        }
    }

    /** @deprecated 2026-08-25 - superseded by announceWalk(); push-only, kept for reference. */
    private function notifyGuardiansWalkStarted(int $tripId, array $childIds, int $byUserId): void
    {
        try {
            if (! $childIds) { return; }

            $trip = DB::table('field_trips')->where('id', $tripId)->first();
            if (! $trip) { return; }

            $tz = $this->staffTz($byUserId);
            $started = \Illuminate\Support\Carbon::now($tz)->format('g:i A');
            $where = trim((string) ($trip->destination ?? '')) ?: 'a walk';
            $lead = DB::table('users')->where('id', $byUserId)->value('first_name');

            $fcm = app(\App\Services\FcmService::class);

            $children = DB::table('children')->whereIn('id', $childIds)
                ->whereNull('deleted_at')
                ->get(['id', 'first_name', 'preferred_name', 'family_id']);

            foreach ($children as $child) {
                $name = trim((string) ($child->preferred_name ?: $child->first_name));

                $guardians = DB::table('guardians as g')
                    ->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $child->family_id)
                    ->whereNull('u.deleted_at')
                    ->pluck('g.user_id')->filter()->unique();

                foreach ($guardians as $uid) {
                    $fcm->sendToUser(
                        (int) $uid,
                        '🚶 '.$name.' is out on a walk',
                        'Off to '.$where.($lead ? ' with '.$lead : '').', from '.$started
                            .'. Tap to follow along on the map.',
                        '#walks'
                    );
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('walk start push failed', [
                'trip_id' => $tripId, 'error' => $e->getMessage(),
            ]);
        }
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
            ->whereDate('t.trip_date', '>=', \Illuminate\Support\Carbon::now($this->staffTz($u->id))->subDays(30)->toDateString())
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
            ->whereDate('t.trip_date', \Illuminate\Support\Carbon::now($this->staffTz($u->id))->toDateString())
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
