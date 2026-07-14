<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
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
        $roomsQuery = DB::table('rooms')
            ->where('centre_id', $centre->id)
            ->where('active', true)
            ->orderBy('age_min_months');

        $assignedRoomIds = $this->assignedRoomIds((int) $user->id);
        if ($assignedRoomIds !== null) {
            $roomsQuery->whereIn('id', $assignedRoomIds ?: [0]);
        }

        $rooms = $roomsQuery->get();

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

        $today = now()->toDateString();

        $children = DB::table('children')
            ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
            ->where('enrollments.room_id', $roomId)
            ->whereNull('enrollments.end_date')
            ->where('children.enrollment_status', 'enrolled')
            ->whereNull('children.deleted_at')
            ->select(
                'children.id', 'children.first_name', 'children.last_name',
                'children.preferred_name', 'children.date_of_birth', 'children.photo_url',
            )
            ->orderBy('children.first_name')
            ->get();

        $childIds = $children->pluck('id')->all();

        $checkEvents = DB::table('check_events')
            ->whereIn('child_id', $childIds)
            ->whereDate('occurred_at', $today)
            ->orderBy('occurred_at')
            ->get()
            ->groupBy('child_id');

        $allFlags = DB::table('child_health_flags')
            ->whereIn('child_id', $childIds)
            ->where('active', true)
            ->whereIn('severity', ['severe', 'life_threatening'])
            ->get()
            ->groupBy('child_id');

        $lastEvents = empty($childIds) ? collect() : $this->getLastEvents($childIds, $today);

        $roster = $children->map(function ($child) use ($checkEvents, $allFlags, $lastEvents) {
            $checks = $checkEvents->get($child->id, collect());
            $lastCheck = $checks->last();
            $isAtCentre = $lastCheck && $lastCheck->event_type === 'check_in';
            $arrivedAt = $isAtCentre ? Carbon::parse($lastCheck->occurred_at) : null;

            $flags = $allFlags->get($child->id, collect());
            $lastEvent = $lastEvents[$child->id] ?? null;

            return [
                'id' => $child->id,
                'first_name' => $child->first_name,
                'last_name' => $child->last_name,
                'display_name' => $child->preferred_name ?: $child->first_name,
                'initials' => strtoupper(substr($child->first_name, 0, 1).substr($child->last_name, 0, 1)),
                'photo_url' => $child->photo_url,
                'age_human' => $this->ageHuman($child->date_of_birth),
                'is_at_centre' => $isAtCentre,
                'arrived_at' => $arrivedAt?->format('g:i A'),
                'urgent_flags' => $flags->map(fn ($f) => [
                    'short_label' => strtoupper(substr($f->category, 0, 8)),
                    'severity' => $f->severity,
                    'category' => $f->category,
                ])->values(),
                'last_event' => $lastEvent ? $this->summarizeEvent($lastEvent) : null,
            ];
        });

        return response()->json([
            'room' => $room,
            'roster' => $roster,
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

        $childrenPresent = DB::table('check_events as ci')
            ->where('ci.room_id', $roomId)
            ->where('ci.event_type', 'check_in')
            ->whereDate('ci.occurred_at', now())
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '>', DB::raw('ci.occurred_at')))
            ->distinct('ci.child_id')
            ->count('ci.child_id');

        $educatorsPresent = DB::table('shifts')
            ->where('room_id', $roomId)
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->where('status', 'active')
            ->count();

        if ($educatorsPresent === 0) {
            $clockedIn = DB::table('time_entries')
                ->where('centre_id', $room->centre_id)
                ->whereDate('clocked_in_at', now())
                ->whereNull('clocked_out_at')
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

        $compliant = $educatorsPresent >= $required;
        $status = match (true) {
            ! $compliant => 'breach',
            $educatorsPresent - $required <= 0 && $childrenPresent > 0 => 'tight',
            default => 'ok',
        };

        return response()->json([
            'room_id' => $room->id,
            'room_name' => $room->name,
            'children_present' => $childrenPresent,
            'educators_present' => $educatorsPresent,
            'required_educators' => $required,
            'ratio_target' => "{$room->ratio_educators}:{$room->ratio_children}",
            'compliant' => $compliant,
            'status' => $status,
        ]);
    }

    // ─── helpers ────────────────────────────────────────────────────

    private function getLastEvents(array $childIds, string $date)
    {
        $sub = DB::table('daily_events')
            ->whereIn('child_id', $childIds)
            ->whereDate('occurred_at', $date)
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

    private function summarizeEvent(object $event): array
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
            'time_display' => Carbon::parse($event->occurred_at)->format('g:i A'),
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

        // No assignments made yet → not restricted (centre scope still applies).
        return $ids ?: null;
    }
}
