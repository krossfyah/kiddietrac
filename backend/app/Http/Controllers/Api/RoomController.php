<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class RoomController extends Controller
{
    use ResolvesCentreContext;

    public function bootstrap(Request $request): JsonResponse
    {
        $user = $request->user();

        $centre = $this->resolveCentre($user);
        if (! $centre) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        // An educator sees only the rooms they are assigned to. Before this, every
        // educator saw EVERY room in the centre — including children they have no
        // business seeing. Assignments are made by an agency admin or director
        // (educator_rooms). If none have been made yet, they fall back to the
        // rooms of the centre they are assigned to, so the app is not empty on
        // day one; directors and admins always see the whole centre.
        $assignedRoomIds = $this->assignedRoomIds((int) $user->id);

        /* A HOME VISITOR'S ROOMS SPAN CENTRES, and that is the job rather than an edge
           case: in a home childcare agency each provider's home is its own centre with a
           single room, so Lloydene's nine assignments sit in nine centres. Filtering by
           the one resolved centre showed her one provider out of nine.

           So for her the assignments ARE the list, each labelled with its centre. The
           client already renders this shape — it builds the same centre_name-carrying
           list for admins, who are likewise not tied to one centre.

           Educators and directors are deliberately untouched: only rooms somebody
           explicitly put this person in are added, and a home visitor with no
           assignments still gets nothing (assignedRoomIds returns [] for her). */
        $isHomeVisitor = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->where('role', 'home_visitor')->exists();

        /* AND THE SAME FOR ANYONE WHOSE ROLES SPAN SEVERAL CENTRES.
           `resolveCentre()` answers with ONE centre, so a person holding a role at nine
           of them was shown the first and given no way to reach the other eight — no
           selector, because there was nothing in the list to select between. That is
           what "should have access to all centres but there is no selector" is.

           Home visitors already had this branch, for the same reason: in a home
           childcare agency each provider's home is its own centre. The shape it returns
           carries centre_name per room and the client already renders it — for admins
           too, who are likewise not tied to one centre. This just stops it being a
           home-visitor privilege.

           NOT A WIDENING. The centres are exactly those where this person holds an
           ACTIVE role, which is the same set Visibility::centreIds() already grants
           them everywhere else in the portal; someone with a role at one centre sees
           precisely what they saw before. */
        $roleCentreIds = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $spansCentres = count($roleCentreIds) > 1;

        if ($spansCentres && ! $isHomeVisitor) {
            /* Their assigned rooms where somebody has named them, otherwise every room
               of every centre they hold — the same "not empty on day one" fallback the
               single-centre branch below applies. */
            $rooms = DB::table('rooms as r')
                ->join('centres as c', 'c.id', '=', 'r.centre_id')
                ->whereIn('r.centre_id', $roleCentreIds)
                ->when(is_array($assignedRoomIds) && $assignedRoomIds !== [],
                    fn ($q) => $q->whereIn('r.id', $assignedRoomIds))
                // Their own agency only — a stale assignment must not cross a tenant.
                ->where('c.agency_id', function ($q) use ($centre) {
                    $q->from('centres')->where('id', $centre->id)->select('agency_id');
                })
                ->whereNull('c.deleted_at')
                ->where('r.active', true)
                ->orderBy('c.name')
                ->orderBy('r.age_min_months')
                ->get(['r.*', 'c.id as centre_id', 'c.name as centre_name']);
        } elseif ($isHomeVisitor && is_array($assignedRoomIds)) {
            $rooms = DB::table('rooms as r')
                ->join('centres as c', 'c.id', '=', 'r.centre_id')
                ->whereIn('r.id', $assignedRoomIds ?: [0])
                // Her own agency only — a stale assignment must not cross a tenant.
                ->where('c.agency_id', function ($q) use ($centre) {
                    $q->from('centres')->where('id', $centre->id)->select('agency_id');
                })
                ->whereNull('c.deleted_at')
                ->where('r.active', true)
                ->orderBy('c.name')
                ->get(['r.*', 'c.id as centre_id', 'c.name as centre_name']);
        } else {
            $roomsQuery = DB::table('rooms')
                ->where('centre_id', $centre->id)
                ->where('active', true)
                ->orderBy('age_min_months');

            if ($assignedRoomIds !== null) {
                $roomsQuery->whereIn('id', $assignedRoomIds ?: [0]);
            }

            $rooms = $roomsQuery->get();
        }

        return response()->json([
            'user' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'display_name' => $user->preferred_name ?: $user->first_name,
            ],
            'centre' => [
                'id' => $centre->id,
                'name' => $centre->name,
                'open_time' => $centre->open_time,
                'close_time' => $centre->close_time,
                'timezone' => 'America/Toronto',
            ],
            'rooms' => $rooms,
            'server_time' => now()->toIso8601String(),
        ]);
    }

    public function roster(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();

        if (! $room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) {
            abort(403);
        }

        // Centre access is not enough: an educator restricted to Room A must not
        // be able to pull Room B's roster by asking for it directly.
        $assignedRoomIds = $this->assignedRoomIds((int) $request->user()->id);
        if ($assignedRoomIds !== null && ! in_array((int) $roomId, $assignedRoomIds, true)) {
            abort(403);
        }

        /* The agency's day as two UTC instants, not a date. occurred_at is an
           INSTANT stored in UTC, and whereDate() buckets it by its UTC date — so
           from 8pm Toronto the roster was reading tomorrow and every child showed
           as 'away'. Resolved from this room's centre, because an agency in
           another zone must not inherit Toronto's midnight. */
        [$dayFrom, $dayTo] = AgencyTime::dayRangeForCentre((int) $room->centre_id);

        $children = DB::table('children')
            ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
            ->where('enrollments.room_id', $roomId)
            ->whereNull('enrollments.end_date')
            ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'enrollments'))
            ->where('children.enrollment_status', 'enrolled')
            ->whereNull('children.deleted_at')
            // Insurance: a child holds one enrolment per provider, and the day filter
            // above already leaves one — but a roster must never list a child twice.
            ->distinct()
            ->select(
                'children.id', 'children.first_name', 'children.last_name',
                'children.preferred_name', 'children.date_of_birth', 'children.photo_url',
                'children.gender',
                // Expected times, so the roster can show who is due and who is overdue
                // rather than only who is currently here.
                'children.expected_dropoff_time', 'children.expected_pickup_time',
            )
            ->orderBy('children.first_name')
            ->get();

        $childIds = $children->pluck('id')->all();

        /* ONLY EVENTS THAT HAVE ALREADY HAPPENED can say where a child is NOW.
           The last event of the day used to decide this, and a row dated later today —
           a mistyped correction, a device with a wrong clock, an import, or the demo
           seeder — then held the child in that state all day. Measured on Test Agency:
           a check_out stamped 20:20 made a child read as OUT at 05:16, so every
           check-in an educator recorded (201 each time) left the card unchanged.
           A child cannot have been signed out at a time that has not arrived.
           (Anthony, 2026-09-08) */
        $checkEvents = DB::table('check_events')
            ->whereIn('child_id', $childIds)
            ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
            ->where('occurred_at', '<=', now())
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('child_id');

        $allFlags = DB::table('child_health_flags')
            ->whereIn('child_id', $childIds)
            ->where('active', true)
            ->whereIn('severity', ['severe', 'life_threatening'])
            ->get()
            ->groupBy('child_id');

        $lastEvents = empty($childIds) ? collect() : $this->getLastEvents($childIds, $dayFrom, $dayTo);

        // Clock-in / activity times are stored in UTC but must be shown in the
        // agency's local zone (Eastern for Ontario), not UTC.
        $tz = AgencyTime::tzForCentre((int) $room->centre_id);

        $roster = $children->map(function ($child) use ($checkEvents, $allFlags, $lastEvents, $tz) {
            $checks = $checkEvents->get($child->id, collect());
            $lastCheck = $checks->last();
            $isAtCentre = $lastCheck && $lastCheck->event_type === 'check_in';
            $arrivedAt = $isAtCentre ? $lastCheck->occurred_at : null;
            // Presence status drives the roster card colour: 'in' (here now),
            // 'out' (arrived earlier, since signed out), 'away' (not in today).
            $status = $isAtCentre ? 'in' : ($checks->isNotEmpty() ? 'out' : 'away');
            $departedAt = (! $isAtCentre && $lastCheck && $lastCheck->event_type === 'check_out')
                ? $lastCheck->occurred_at : null;

            $flags = $allFlags->get($child->id, collect());
            $lastEvent = $lastEvents[$child->id] ?? null;

            return [
                'id' => $child->id,
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'display_name' => $child->preferred_name ?: $child->first_name,
                'initials' => strtoupper(substr($child->first_name, 0, 1).substr($child->last_name, 0, 1)),
                'photo_url' => $child->photo_url,
                'gender' => $child->gender ?? null,
                'age_human' => $this->ageHuman($child->date_of_birth),
                'is_at_centre' => $isAtCentre,
                'status' => $status,
                'arrived_at' => AgencyTime::fmt($arrivedAt, $tz),
                'departed_at' => AgencyTime::fmt($departedAt, $tz),
                'urgent_flags' => $flags->map(fn ($f) => [
                    'short_label' => strtoupper(substr($f->category, 0, 8)),
                    'severity' => $f->severity,
                    'category' => $f->category,
                ])->values(),
                'last_event' => $lastEvent ? $this->summarizeEvent($lastEvent, $tz) : null,
            ];
        });

        /* WHY the roster is empty, when it is. "No children enrolled" and "nobody is
           booked on a Saturday" are different facts and only this end can tell them
           apart: enrolled_total ignores the day filter, so the client can compare.

           The day name is resolved HERE, in the centre's zone, from the same helper that
           built the filter — so the sentence on the screen and the query behind it can
           never name different days. Asking the browser would name the device's day,
           which after 8pm is not the agency's. */
        $dayKey = \App\Support\CareSchedule::dayKey($tz);

        return response()->json([
            'room' => $room,
            'roster' => $roster,
            'day' => [
                'key' => $dayKey,
                'label' => \App\Support\CareSchedule::LABELS[$dayKey] ?? ucfirst($dayKey),
            ],
            // Open enrolments in this room REGARDLESS of the day's schedule.
            'enrolled_total' => (int) DB::table('enrollments')
                ->join('children', 'children.id', '=', 'enrollments.child_id')
                ->where('enrollments.room_id', $roomId)
                ->whereNull('enrollments.end_date')
                ->where('children.enrollment_status', 'enrolled')
                ->whereNull('children.deleted_at')
                ->distinct()
                ->count('children.id'),
        ]);
    }

    public function currentRatio(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();

        if (! $room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) {
            abort(403);
        }

        [$ratioFrom, $ratioTo] = AgencyTime::dayRangeForCentre((int) $room->centre_id);
        $childrenPresent = DB::table('check_events as ci')
            ->where('ci.room_id', $roomId)
            ->where('ci.event_type', 'check_in')
            // Agency day as instants — see AgencyTime::dayRange.
            ->where('ci.occurred_at', '>=', $ratioFrom)->where('ci.occurred_at', '<', $ratioTo)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                // A check-out is "later" if its timestamp is greater OR the same
                // second but a higher id — otherwise a same-second check-in→check-out
                // (rapid re-scan/toggle) wrongly reads as still-present and triggers a
                // phantom ratio breach even though the child is signed out.
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')
                        ->whereColumn('co.id', '>', 'ci.id'))))
            ->distinct('ci.child_id')
            ->count('ci.child_id');

        $educatorsPresent = DB::table('shifts')
            ->where('room_id', $roomId)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('status', 'active')
            ->count();

        if ($educatorsPresent === 0) {
            // No scheduled shift rows — fall back to who is actually CLOCKED IN.
            // The time clock writes `time_punches` (user_id, centre_id, punched_in_at,
            // punched_out_at); the old code read `time_entries`, which the clock never
            // populates, so a clocked-in educator counted as 0 and one checked-in child
            // showed a FALSE ratio breach. Count open punches for this centre today.
            // Agency-day instants: on the UTC date this read zero every evening and
            // reported a false ratio breach with staff actually on the floor.
            [$shiftFrom, $shiftTo] = AgencyTime::dayRangeForCentre((int) $room->centre_id);
            $clockedIn = DB::table('time_punches')
                ->where('centre_id', $room->centre_id)
                ->where('punched_in_at', '>=', $shiftFrom)->where('punched_in_at', '<', $shiftTo)
                ->whereNull('punched_out_at')
                ->count();
            $totalRooms = DB::table('rooms')
                ->where('centre_id', $room->centre_id)
                ->where('active', true)
                ->count();
            $educatorsPresent = $totalRooms > 0 ? (int) ceil($clockedIn / $totalRooms) : $clockedIn;
        }

        $required = $childrenPresent === 0
            ? 0
            : (int) ceil($childrenPresent / max(1, (int) $room->ratio_children));

        // Two independent safety limits: the educator:child RATIO, and the room's
        // licensed CAPACITY (max children regardless of staffing). A room can be
        // within ratio but over its licensed headcount, or vice-versa — surface both.
        $capacity = (int) $room->capacity;
        $overCapacity = $capacity > 0 && $childrenPresent > $capacity;
        $atCapacity = $capacity > 0 && $childrenPresent >= $capacity;
        $overBy = $overCapacity ? $childrenPresent - $capacity : 0;

        // How many children the educators on the floor can cover at this ratio.
        // "Tight" = the room is exactly at that limit (one more child would breach),
        // NOT merely at the minimum educator count — 1 educator on a 1:3 ratio with
        // 1 child has headroom for 2 more and should read OK, not "at the limit".
        $coverage = $educatorsPresent * max(1, (int) $room->ratio_children);
        $compliant = $educatorsPresent >= $required && ! $overCapacity;
        $status = match (true) {
            $educatorsPresent < $required => 'breach',
            $overCapacity => 'over_capacity',
            ($childrenPresent > 0 && $childrenPresent >= $coverage) || $atCapacity => 'tight',
            default => 'ok',
        };

        return response()->json([
            'room_id' => $room->id,
            'room_name' => $room->name,
            'children_present' => $childrenPresent,
            'educators_present' => $educatorsPresent,
            'required_educators' => $required,
            'ratio_target' => "{$room->ratio_educators}:{$room->ratio_children}",
            'capacity' => $capacity,
            'over_capacity' => $overCapacity,
            'at_capacity' => $atCapacity,
            'over_capacity_by' => $overBy,
            'compliant' => $compliant,
            'status' => $status,
        ]);
    }

    /**
     * How many children are STILL checked in across the caller's centre(s) right
     * now — used to warn an educator who is clocking out while children remain
     * signed in (they must be handed over / signed out first).
     */
    public function presentCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $centreIds = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', 1)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')->unique()->values()->all();
        if (empty($centreIds)) {
            return response()->json(['present' => 0]);
        }
        $roomIds = DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all();
        if (empty($roomIds)) {
            return response()->json(['present' => 0]);
        }
        [$pcFrom, $pcTo] = AgencyTime::dayRangeForRoom((int) $roomIds[0]);
        $present = DB::table('check_events as ci')
            ->whereIn('ci.room_id', $roomIds)
            ->where('ci.event_type', 'check_in')
            // Agency day as instants — see AgencyTime::dayRange.
            ->where('ci.occurred_at', '>=', $pcFrom)->where('ci.occurred_at', '<', $pcTo)
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                // A check-out counts as "later" if its timestamp is greater, OR the
                // same second but a higher id — otherwise a same-second check-in then
                // check-out (rapid re-scan) would wrongly read as still-present.
                ->where(fn ($w) => $w->whereColumn('co.occurred_at', '>', 'ci.occurred_at')
                    ->orWhere(fn ($w2) => $w2->whereColumn('co.occurred_at', 'ci.occurred_at')
                        ->whereColumn('co.id', '>', 'ci.id'))))
            ->distinct('ci.child_id')
            ->count('ci.child_id');

        return response()->json(['present' => $present]);
    }

    // ─── helpers ────────────────────────────────────────────────────

    /* Takes the day as UTC instants rather than a date: daily_events.occurred_at is
       an instant, so a date comparison buckets it by UTC. See AgencyTime::dayRange. */
    private function getLastEvents(array $childIds, string $dayFrom, string $dayTo)
    {
        $sub = DB::table('daily_events')
            ->whereIn('child_id', $childIds)
            ->where('occurred_at', '>=', $dayFrom)->where('occurred_at', '<', $dayTo)
            ->select('child_id', DB::raw('MAX(occurred_at) as last_at'))
            ->groupBy('child_id');

        return DB::table('daily_events as de')
            ->joinSub($sub, 'latest', fn ($j) => $j
                ->on('latest.child_id', '=', 'de.child_id')
                ->on('latest.last_at', '=', 'de.occurred_at'))
            ->select('de.child_id', 'de.event_type', 'de.payload', 'de.occurred_at')
            ->get()
            ->keyBy('child_id');
    }

    private function ageHuman(?string $dob): string
    {
        if (! $dob) {
            return '—';
        }

        $months = (int) Carbon::parse($dob)->diffInMonths(now());
        $years = intdiv($months, 12);
        $m = $months % 12;

        return $years > 0 ? "{$years}y {$m}m" : "{$months}m";
    }

    private function summarizeEvent(object $event, string $tz = 'America/Toronto'): array
    {
        $payload = is_string($event->payload)
            ? (json_decode($event->payload, true) ?? [])
            : ((array) ($event->payload ?? []));

        $summary = match ($event->event_type) {
            'meal', 'snack' => ucfirst($payload['meal'] ?? $event->event_type),
            'nap_start' => 'Started nap',
            'nap_end' => 'Woke from nap',
            'diaper' => 'Diaper ('.($payload['type'] ?? 'changed').')',
            'bathroom' => 'Bathroom',
            'activity' => $payload['name'] ?? 'Activity',
            'mood' => 'Mood: '.($payload['score'] ?? 'noted'),
            default => str_replace('_', ' ', ucfirst($event->event_type)),
        };

        return [
            'type' => $event->event_type,
            'occurred_at' => $event->occurred_at,
            'time_display' => AgencyTime::fmt($event->occurred_at, $tz),
            'summary' => $summary,
        ];
    }

    /**
     * The room ids an EDUCATOR is limited to.
     *
     * Returns null when the caller is not room-restricted (directors, agency and
     * platform admins — and educators with no assignments yet, who fall back to
     * their whole centre). Returns an array (possibly empty) when they are.
     */
    private function assignedRoomIds(int $userId): ?array
    {
        $roles = DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', true)
            ->pluck('role')->all();

        $privileged = array_intersect($roles, ['centre_director', 'agency_admin', 'platform_admin']);
        if ($privileged) {
            return null;   // sees the whole centre
        }

        if (! \Illuminate\Support\Facades\Schema::hasTable('educator_rooms')) {
            return null;
        }

        $ids = DB::table('educator_rooms')
            ->where('user_id', $userId)
            ->pluck('room_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        /* A HOME VISITOR FAILS CLOSED. null below means "unrestricted", which for an
           educator is a deliberate first-day convenience: their role attaches them to a
           centre, so no assignments yet falls back to that centre instead of an empty
           app.

           A home visitor has no centre of her own — the room assignment IS her access
           ("she must be able to access those rooms to perform her role", 2026-09-06). So
           unrestricted would hand a home visitor with ZERO assignments every room in a
           centre: a wider grant than the one asked for on behalf of a home visitor with
           nine. No rooms means no rooms. */
        if (! $ids && in_array('home_visitor', $roles, true)) {
            return [];
        }

        // No assignments made yet → not restricted (centre scope still applies).
        return $ids ?: null;
    }
}
