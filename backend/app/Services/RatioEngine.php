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
        return $this->statusAt($room, now());
    }

    /**
     * The ratio as it stood at a given moment.
     *
     * currentStatus() was the same arithmetic with now() baked in, so nothing could
     * ask what the ratio HAD been — which is what an attendance correction needs. If
     * an educator missed three sign-ins, the room was over ratio at the time and the
     * record said it was fine; correcting attendance without re-checking the ratio
     * fixes the smaller half of that.
     *
     * Educator presence is counted from SHIFTS. Worth knowing when reading the
     * output: shifts are barely used here (25 rows, none active), while educators
     * actually clock in through time_punches — so a historical evaluation will report
     * zero educators for most rooms and must not be treated as proof of a breach.
     * That is why evaluateDay() below records nothing unless children were present
     * AND a shift existed to compare against.
     */
    public function statusAt(Room $room, Carbon $at): array
    {
        $now = $at;

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
            'historical' => ! $now->isSameMinute(now()),
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

            /* How many children may be PRESENT at once — distinct from `capacity`
               (physical places) and from enrolment, which may lawfully exceed both.
               Null = not configured; an unset room is never "over". */
            'max_concurrent_children' => $room->max_concurrent_children,
            'over_concurrent_limit' => $room->max_concurrent_children !== null
                && $childrenPresent > (int) $room->max_concurrent_children,
            'concurrent_status' => $room->max_concurrent_children === null
                ? 'not_set'
                : ($childrenPresent > (int) $room->max_concurrent_children
                    ? 'over'
                    : ($childrenPresent === (int) $room->max_concurrent_children ? 'at_limit' : 'ok')),
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

    /**
     * Walk a past day in 15-minute steps and record any stretch that was over ratio.
     *
     * Called after attendance is corrected, because the correction may reveal a breach
     * that the incomplete record hid: three children signed in late means the room was
     * over ratio at the time and nobody knew.
     *
     * DELIBERATELY CONSERVATIVE. Educator presence comes from `shifts`, which this
     * installation barely populates — most rooms would evaluate as "zero educators"
     * and every occupied minute would look like a breach. Manufacturing hundreds of
     * false compliance violations against a childcare provider is far worse than
     * recording none, so a sample is only judged when there is a shift to judge it
     * against. Where shifts are not kept, this records nothing and says so.
     *
     * Previously auto-recorded rows for the same room and day are cleared first, so
     * correcting twice does not accumulate duplicates. Rows entered by a human are
     * left alone — they are somebody's finding, not this method's.
     *
     * @return array{samples:int, breaches:int, skipped_no_shift:bool}
     */
    public function evaluateDay(Room $room, Carbon $day): array
    {
        $start = $day->copy()->startOfDay();
        $end = $day->copy()->endOfDay();

        /* Something to measure staffing against — a roster, or people clocked in.
           With neither, "no breaches" would mean "never checked", and recording that
           as a clean day against a childcare provider is worse than recording nothing. */
        $hasShifts = Shift::where('room_id', $room->id)
            ->where('starts_at', '<=', $end)->where('ends_at', '>=', $start)->exists();
        $hasPunches = \DB::table('time_punches')
            ->where('centre_id', $room->centre_id)
            ->whereBetween('punched_in_at', [$start, $end])->exists();
        if (! $hasShifts && ! $hasPunches) {
            return ['samples' => 0, 'breaches' => 0, 'skipped_no_shift' => true];
        }

        // Only the hours the room was actually occupied.
        $first = \DB::table('check_events')->where('room_id', $room->id)
            ->whereBetween('occurred_at', [$start, $end])->min('occurred_at');
        $last = \DB::table('check_events')->where('room_id', $room->id)
            ->whereBetween('occurred_at', [$start, $end])->max('occurred_at');
        if (! $first || ! $last) {
            return ['samples' => 0, 'breaches' => 0, 'skipped_no_shift' => false];
        }

        \DB::table('ratio_violations')
            ->where('room_id', $room->id)
            ->whereBetween('occurred_at', [$start, $end])
            ->where('notes', 'like', 'Recalculated after an attendance correction%')
            ->delete();

        $cursor = Carbon::parse($first)->startOfMinute();
        $stop = Carbon::parse($last);
        $samples = 0;
        $breaches = 0;
        $openBreach = null;

        $close = function ($openBreach, $at) use ($room, &$breaches) {
            $mins = max(1, (int) Carbon::parse($openBreach['from'])->diffInMinutes($at));
            \DB::table('ratio_violations')->insert([
                'room_id' => $room->id,
                'occurred_at' => $openBreach['from'],
                'resolved_at' => $at,
                'expected_educators' => $openBreach['required'],
                'actual_educators' => $openBreach['actual'],
                'children_count' => $openBreach['children'],
                'duration_min' => $mins,
                'severity' => $mins >= 60 ? 'high' : ($mins >= 15 ? 'medium' : 'low'),
                'notes' => 'Recalculated after an attendance correction on '
                    . now()->toDateTimeString() . ' UTC.',
                'created_at' => now(),
            ]);
            $breaches++;
        };

        while ($cursor->lessThanOrEqualTo($stop)) {
            $s = $this->statusAt($room, $cursor->copy());
            $samples++;
            if (! $s['compliant'] && $s['children_present'] > 0) {
                if (! $openBreach) {
                    $openBreach = [
                        'from' => $cursor->copy(),
                        'required' => $s['required_educators'],
                        'actual' => $s['educators_present'],
                        'children' => $s['children_present'],
                    ];
                }
            } elseif ($openBreach) {
                $close($openBreach, $cursor->copy());
                $openBreach = null;
            }
            $cursor->addMinutes(15);
        }
        if ($openBreach) {
            $close($openBreach, $stop);
        }

        return ['samples' => $samples, 'breaches' => $breaches, 'skipped_no_shift' => false];
    }

    protected function presentChildrenCount(Room $room, Carbon $at): int
    {
        /* The agency's day CONTAINING $at, as UTC instants. $at is a moment, and
           whereDate() bucketed it by its UTC date — so an evening ratio check read the
           next day and reported an empty room. Resolved once: two calls could straddle
           midnight and bound two different days. */
        $ratioTz = \App\Support\AgencyTime::tzForCentre((int) $room->centre_id);
        [$dayFrom, $dayTo] = \App\Support\AgencyTime::dayRangeForCentre(
            (int) $room->centre_id,
            $at->copy()->setTimezone($ratioTz)->toDateString()
        );

        // Children with most recent check_event = check_in for today, no check_out after
        return \DB::table('check_events as ce1')
            ->where('ce1.room_id', $room->id)
            ->where('ce1.event_type', 'check_in')
            /* The agency's day containing $at, as instants. $at is a UTC moment and
               whereDate bucketed it by the UTC date, so an evening ratio check read
               the next day and reported an empty room. */
            ->where('ce1.occurred_at', '>=', $dayFrom)
            ->where('ce1.occurred_at', '<', $dayTo)
            /* The arrival has to have HAPPENED by $at.
               Without this the query counted every check-in on the date regardless of
               time, so asking "how many children were here at 09:00" also counted the
               child who arrived at 15:00. Harmless while $at was always now() — the
               future has no rows — and wrong the moment anything asks about a past
               moment, which is exactly what a backdated correction needs. */
            ->where('ce1.occurred_at', '<=', $at)
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

    /**
     * Educators present in this room at a moment.
     *
     * Shifts first — when a centre rosters properly that is the precise answer. But
     * this installation has 25 shift rows and none active, while educators clock in
     * through `time_punches` (hundreds of rows). Counting shifts alone therefore
     * returned 0 for every room, and every occupied room reported a ratio BREACH that
     * was not happening. Measured on 2026-09-01: both occupied rooms, falsely.
     *
     * RoomController already carried this fallback and its own note about the same
     * false breach; RatioEngine never got it, so the two disagreed about the same
     * room at the same instant. Same rule in both places now.
     *
     * The punch count is apportioned across the centre's active rooms, because a
     * punch says somebody is AT THE CENTRE, not which room they are standing in —
     * the honest resolution of the data we actually have.
     */
    protected function presentEducatorsCount(Room $room, Carbon $at): int
    {
        $scheduled = Shift::where('room_id', $room->id)
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->where('status', 'active')
            ->count();
        if ($scheduled > 0) {
            return $scheduled;
        }

        /* Open at $at: punched in by then, and either not yet out or out afterwards.
           Scoped to the same day so a punch somebody forgot to close weeks ago cannot
           silently staff every room since. */
        /* $dayFrom/$dayTo were resolved above for the child count — the same agency day
           containing $at. Using them here keeps staff and children on ONE day, which is
           the whole point of a ratio. */
        $clockedIn = \DB::table('time_punches')
            ->where('centre_id', $room->centre_id)
            ->where('punched_in_at', '>=', $dayFrom)->where('punched_in_at', '<', $dayTo)
            ->where('punched_in_at', '<=', $at)
            ->where(function ($q) use ($at) {
                $q->whereNull('punched_out_at')->orWhere('punched_out_at', '>=', $at);
            })
            ->count();
        if ($clockedIn === 0) {
            return 0;
        }

        $rooms = \DB::table('rooms')->where('centre_id', $room->centre_id)
            ->where('active', true)->count();

        return $rooms > 0 ? (int) ceil($clockedIn / $rooms) : $clockedIn;
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
