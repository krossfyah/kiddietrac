<?php

namespace App\Services;

use App\Models\CheckEvent;
use App\Models\RatioViolation;
use App\Models\Room;
use App\Models\Shift;
use Carbon\Carbon;

/**
 * RatioEngine
 *
 * Ontario CCEYA ratio requirements — encoded as defaults:
 *   Infant (0–18mo):      1 educator : 3 children
 *   Toddler (18–30mo):    1 : 5
 *   Preschool (30mo–6y):  1 : 8
 *   Kindergarten:         1 : 13
 *   School age (6–12y):   1 : 15
 *
 * This service:
 *  1. Computes current ratio status for any room
 *  2. Predicts upcoming breaches from staff schedule + expected arrivals
 *  3. Logs violations for compliance reporting
 *  4. Suggests rebalancing (e.g., move floater from Room A to Room B)
 */
class RatioEngine
{
    /**
     * Current ratio snapshot for a room.
     */
    public function currentStatus(Room $room): array
    {
        $now = now();

        // Count children currently checked in to this room
        $childrenPresent = $this->presentChildrenCount($room, $now);

        // Count educators currently clocked in & assigned to this room
        $educatorsPresent = $this->presentEducatorsCount($room, $now);

        $required = $this->requiredEducators($room, $childrenPresent);
        $compliant = $educatorsPresent >= $required;

        return [
            'room_id' => $room->id,
            'room_name' => $room->name,
            'as_of' => $now->toIso8601String(),
            'children_present' => $childrenPresent,
            'educators_present' => $educatorsPresent,
            'required_educators' => $required,
            'ratio_target' => "{$room->ratio_educators}:{$room->ratio_children}",
            'compliant' => $compliant,
            'status' => match(true) {
                ! $compliant => 'breach',
                $childrenPresent / max(1, $room->ratio_children) >= $educatorsPresent - 0.5 => 'tight',
                default => 'ok',
            },
            'capacity_pct' => round($childrenPresent / max(1, $room->capacity) * 100),
        ];
    }

    /**
     * Predicts the next 4 hours of ratio status using staff schedule and
     * typical check-out patterns. Returns any forecast breaches.
     */
    public function forecast(Room $room, int $minutesAhead = 240): array
    {
        $start = now();
        $end = $start->copy()->addMinutes($minutesAhead);
        $forecasts = [];

        // Generate 15-min snapshots
        for ($t = $start->copy(); $t->lt($end); $t->addMinutes(15)) {
            // Educators scheduled to be present at this time
            $scheduledEducators = Shift::where('room_id', $room->id)
                ->where('starts_at', '<=', $t)
                ->where('ends_at', '>', $t)
                ->whereIn('status', ['scheduled', 'active'])
                ->count();

            // Best estimate of children present (assume currently-present children stay until typical pickup)
            // In practice, we'd use historical patterns per child. Simple model for now:
            $expectedChildren = $this->expectedChildrenAt($room, $t);

            $required = $this->requiredEducators($room, $expectedChildren);

            if ($scheduledEducators < $required) {
                $forecasts[] = [
                    'at' => $t->toIso8601String(),
                    'time_display' => $t->format('g:i A'),
                    'expected_children' => $expectedChildren,
                    'scheduled_educators' => $scheduledEducators,
                    'required_educators' => $required,
                    'gap' => $required - $scheduledEducators,
                    'severity' => $required - $scheduledEducators >= 2 ? 'critical' : 'warning',
                ];
            }
        }

        return $forecasts;
    }

    /**
     * The "smart" part — suggest a rebalancing for an upcoming or current breach.
     * Looks for floater staff, lightly-staffed-but-overstaffed adjacent rooms, etc.
     */
    public function suggestRebalance(Room $room): array
    {
        $current = $this->currentStatus($room);
        if ($current['compliant']) return [];

        $suggestions = [];
        $centre = $room->centre;

        // 1. Find floaters on shift right now
        $availableFloaters = Shift::whereHas('user.roleAssignments', fn($q) =>
                $q->where('centre_id', $centre->id)->where('role', 'educator'))
            ->where('role', 'floater')
            ->where('starts_at', '<=', now())
            ->where('ends_at', '>', now())
            ->with('user:id,first_name,last_name')
            ->get();

        foreach ($availableFloaters as $shift) {
            $suggestions[] = [
                'type' => 'deploy_floater',
                'user_id' => $shift->user->id,
                'user_name' => $shift->user->first_name . ' ' . $shift->user->last_name,
                'reason' => "Floater available — currently unassigned",
                'priority' => 1,
            ];
        }

        // 2. Find adjacent rooms with extra capacity
        $otherRooms = Room::where('centre_id', $centre->id)
            ->where('id', '!=', $room->id)
            ->where('active', true)
            ->get();

        foreach ($otherRooms as $other) {
            $otherStatus = $this->currentStatus($other);
            if (($otherStatus['educators_present'] - $otherStatus['required_educators']) >= 1) {
                $suggestions[] = [
                    'type' => 'borrow_from_room',
                    'from_room_id' => $other->id,
                    'from_room_name' => $other->name,
                    'spare_capacity' => $otherStatus['educators_present'] - $otherStatus['required_educators'],
                    'reason' => "{$other->name} has {$otherStatus['educators_present']} on floor, only needs {$otherStatus['required_educators']}",
                    'priority' => 2,
                ];
            }
        }

        // 3. Director / on-call notification
        if (empty($suggestions)) {
            $suggestions[] = [
                'type' => 'alert_director',
                'reason' => 'No internal resources available — director must be paged',
                'priority' => 3,
            ];
        }

        return $suggestions;
    }

    /**
     * Records a violation for compliance reporting.
     */
    public function recordViolation(Room $room, array $details): RatioViolation
    {
        return RatioViolation::create([
            'room_id' => $room->id,
            'occurred_at' => now(),
            'expected_educators' => $details['required_educators'],
            'actual_educators' => $details['educators_present'],
            'children_count' => $details['children_present'],
            'severity' => $details['gap'] >= 2 ? 'serious' : 'minor',
            'notes' => $details['notes'] ?? null,
        ]);
    }

    // ──────────────── Helpers ────────────────

    protected function presentChildrenCount(Room $room, Carbon $at): int
    {
        // Children with most recent check_event = check_in for today, no check_out after
        return \DB::table('check_events as ce1')
            ->where('ce1.room_id', $room->id)
            ->where('ce1.event_type', 'check_in')
            ->whereDate('ce1.occurred_at', $at->toDateString())
            ->whereNotExists(function ($q) use ($at) {
                $q->select(\DB::raw(1))
                  ->from('check_events as ce2')
                  ->whereColumn('ce2.child_id', 'ce1.child_id')
                  ->where('ce2.event_type', 'check_out')
                  ->where('ce2.occurred_at', '>', \DB::raw('ce1.occurred_at'))
                  ->where('ce2.occurred_at', '<=', $at);
            })
            ->count();
    }

    protected function presentEducatorsCount(Room $room, Carbon $at): int
    {
        return Shift::where('room_id', $room->id)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->where('status', 'active')
            ->count();
    }

    protected function requiredEducators(Room $room, int $childrenPresent): int
    {
        if ($childrenPresent === 0) return 0;
        return (int) ceil($childrenPresent / $room->ratio_children);
    }

    protected function expectedChildrenAt(Room $room, Carbon $at): int
    {
        // Simplistic: assume same children stay until 5pm (typical pickup window starts)
        // Better: model each child's historical pickup time.
        $hour = $at->hour;
        $current = $this->presentChildrenCount($room, now());

        if ($hour < 16) return $current; // before 4pm — stable
        if ($hour < 17) return (int) round($current * 0.7); // 4-5pm: 30% picked up
        if ($hour < 18) return (int) round($current * 0.3); // 5-6pm: 70% picked up
        return 0;
    }
}
