<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Safe Departure — the end-of-day half of Safe Arrival (2026-10-22).
 *
 * Anthony: "if children were not clocked out do we have it wired for safe arrival to go
 * through the escalation path of checking where the child is?" It was not. A child still
 * signed in after closing was signed out automatically at the agency's sign-off time
 * (20:00 at iLearn) as if they had gone home — 79 times at iLearn in the 60 days before
 * this — and nobody was ever asked where the child actually was.
 *
 * Now, for every child still signed in past their pickup time (children.expected_pickup_time,
 * else the centre's closing time, else the agency default) plus a grace period:
 *   1. the room's educators are asked to account for the child;
 *   2. no answer after departure_escalate_after_minutes: the directors and/or admins the
 *      agency chose (notify_directors / notify_admins) are alerted;
 *   3. still open departure_parents_after_minutes later: the parents are asked.
 * A normal sign-out closes it. Staff close it on the Safe Arrival board: picked up (at a
 * time, which signs the child out), still here, or other with a note.
 *
 * Anthony's decisions (2026-10-22): educator first, then up; parents only if staff do not
 * resolve; at iLearn admins (no directors exist there); the automatic sign-off still
 * closes the day, but an open check is marked "pickup not confirmed", stays on the board
 * and admins are alerted again (autoSignedOut()).
 *
 * Like Safe Arrival, the list is computed LIVE; safe_departure_checks records actions.
 */
class SafeDeparture
{
    /**
     * Children still signed in today, plus anyone with a check today.
     *
     * @return array<int, array> keyed by child id
     */
    public static function today(int $agencyId, ?array $centreIds = null, ?array $roomIds = null, ?string $date = null): array
    {
        $cfg = SafeArrival::settings($agencyId);
        $tz = AgencyTime::tz($agencyId);
        $date = $date ?: AgencyTime::today($agencyId);
        [$from, $to] = AgencyTime::dayRange($agencyId, $date);
        $now = Carbon::now();

        $base = fn () => DB::table('children as c')
            ->join('families as fa', 'fa.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')
            ->where('ce.agency_id', $agencyId)
            ->tap(fn ($q) => Live::centres(Live::families($q, 'fa'), 'ce'))
            ->whereNull('c.deleted_at')
            ->when($centreIds !== null, fn ($q) => $q->whereIn('ce.id', $centreIds ?: [0]));

        // The last thing that happened to each child today decides whether they are still in.
        $last = [];
        foreach ($base()->join('check_events as e', 'e.child_id', '=', 'c.id')
            ->whereBetween('e.occurred_at', [$from, $to])->where('e.occurred_at', '<=', $now)
            ->orderBy('e.occurred_at')->orderBy('e.id')
            ->get(['e.id', 'e.child_id', 'e.room_id', 'e.event_type', 'e.occurred_at', 'e.is_automatic']) as $e) {
            $last[(int) $e->child_id] = $e;
        }
        $firstIn = [];
        foreach (DB::table('check_events')->whereIn('child_id', array_keys($last) ?: [0])->where('event_type', 'check_in')
            ->whereBetween('occurred_at', [$from, $to])->orderBy('occurred_at')->get(['child_id', 'occurred_at']) as $e) {
            /* The arrival of the visit they are IN, not the day's first (2026-11-05): a child
               out at 10 and back at 6:30 PM is an evening visit, and the grace runs from 6:30. */
            $firstIn[(int) $e->child_id] = $e->occurred_at;
        }
        $checks = [];
        foreach (DB::table('safe_departure_checks')->where('agency_id', $agencyId)->where('check_date', $date)
            ->when($centreIds !== null, fn ($q) => $q->whereIn('centre_id', $centreIds ?: [0]))->get() as $c) {
            $checks[(int) $c->child_id] = $c;
        }

        $ids = [];
        foreach ($last as $cid => $e) {
            if (self::isIn($e) || isset($checks[$cid])) {
                $ids[] = $cid;
            }
        }
        $ids = array_values(array_unique(array_merge($ids, array_keys($checks))));
        if (! $ids) {
            return [];
        }

        $kids = $base()->whereIn('c.id', $ids)
            ->get(['c.id as child_id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'c.family_id',
                'c.expected_pickup_time', 'ce.id as centre_id', 'ce.name as centre_name', 'ce.close_time']);
        $roomNames = DB::table('rooms')->pluck('name', 'id');
        /* LATE PICKUP (2026-11-06): a parent or staff said the child will be picked up by a time. The end-of-day
           alerts wait for that time plus the grace (never earlier than they already would). */
        $late = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('safe_arrival_late')) {
            foreach (SafeArrival::lateQuery($ids, $date, 'departure')->get() as $l) {
                $late[(int) $l->child_id] = $l;
            }
        }

        $out = [];
        foreach ($kids as $k) {
            $cid = (int) $k->child_id;
            $e = $last[$cid] ?? null;
            $c = $checks[$cid] ?? null;
            $roomId = $e && $e->room_id ? (int) $e->room_id : ($c && $c->room_id ? (int) $c->room_id : null);
            if ($roomIds !== null && ! in_array($roomId, $roomIds, true)) {
                continue;
            }
            $source = $k->expected_pickup_time ? 'pickup' : ($k->close_time ? 'closing' : 'default');
            $expected = substr((string) ($k->expected_pickup_time ?: ($k->close_time ?: $cfg['departure_default_time'])), 0, 5);
            $due = Carbon::parse($date . ' ' . $expected, $tz)->addMinutes($cfg['departure_grace_minutes'])->utc();
            // Signed in after the usual pickup (an evening visit): give them the grace from then.
            $inAt = $firstIn[$cid] ?? ($c->checked_in_at ?? null);
            if ($inAt && Carbon::parse($inAt)->gt($due)) {
                $due = Carbon::parse($inAt)->addMinutes($cfg['departure_grace_minutes']);
            }
            $lateRow = null;
            if (isset($late[$cid])) {
                $l = $late[$cid];
                $by = substr((string) $l->arrive_by, 0, 5);
                $lateDue = Carbon::parse($date . ' ' . $by, $tz)->addMinutes($cfg['departure_grace_minutes'])->utc();
                $lateRow = ['by' => $by, 'due_at' => $lateDue, 'note' => $l->note, 'source' => $l->source ?? 'staff',
                    'reported_by_id' => $l->reported_by_id ? (int) $l->reported_by_id : null, 'at' => $l->updated_at];
                if ($lateDue->gt($due)) {
                    $due = $lateDue;
                }
            }
            $row = [
                'child_id' => $cid,
                'name' => trim(($k->preferred_name ?: $k->first_name) . ' ' . $k->last_name),
                'first_name' => $k->preferred_name ?: $k->first_name,
                'family_id' => (int) $k->family_id,
                'room_id' => $roomId, 'room_name' => $roomId ? ($roomNames[$roomId] ?? '') : '',
                'centre_id' => (int) $k->centre_id, 'centre_name' => $k->centre_name,
                'expected' => $expected, 'expected_source' => $source,
                'due_at' => $due,
                'checked_in_at' => $inAt,
                'still_in' => $e ? self::isIn($e) : false,
                'last_event' => $e,
                'check' => $c,
                'late' => $lateRow,
            ];
            $row['state'] = self::state($row, $now);
            if ($row['state'] === 'gone') {
                continue;
            }
            $out[$cid] = $row;
        }

        return $out;
    }

    public static function isIn(object $e): bool
    {
        return $e->event_type === 'check_in';
    }

    /**
     * waiting | running_late | overdue | escalated | parents | unconfirmed | checked_out | resolved | gone
     *   waiting     still in, not due yet
     *   running_late still in; a late pickup time was given and has not passed
     *   overdue     due; the room's educators have been (or are about to be) alerted
     *   escalated   directors/admins alerted
     *   parents     parents asked too
     *   unconfirmed signed out automatically with the alert still open
     *   checked_out a person signed the child out after the alert (the job closes it)
     *   gone        signed out, never alerted — not shown
     */
    public static function state(array $row, Carbon $now): string
    {
        $c = $row['check'];
        if ($c && $c->resolved_at) {
            return 'resolved';
        }
        if (! $row['still_in']) {
            if (! $c) {
                return 'gone';
            }
            $auto = (bool) ($row['last_event']->is_automatic ?? false);

            return ($auto || $c->auto_signed_out_at) ? 'unconfirmed' : 'checked_out';
        }
        // Late pickup given and its time not passed: nobody is chased, even if an alert went out before it was given.
        if (! empty($row['late']) && $now->lt($row['late']['due_at'])) {
            return 'running_late';
        }
        if ($c && $c->parents_notified_at) {
            return 'parents';
        }
        if ($c && $c->escalated_at) {
            return 'escalated';
        }

        return $now->greaterThanOrEqualTo($row['due_at']) ? 'overdue' : 'waiting';
    }

    /**
     * The automatic sign-off has just closed this child's day (AutoSignOffCommand). If an
     * end-of-day check is open, the pickup was never confirmed: say so on the record, keep
     * the check open on the board, and alert the directors/admins again.
     *
     * @return bool whether the sign-off was marked "not confirmed"
     */
    public static function autoSignedOut(int $agencyId, int $childId, Carbon $at, int $eventId): bool
    {
        $tz = AgencyTime::tz($agencyId);
        $date = $at->copy()->timezone($tz)->toDateString();
        $c = DB::table('safe_departure_checks')->where('child_id', $childId)->where('check_date', $date)->first();
        if (! $c || $c->resolved_at || $c->auto_signed_out_at) {
            return false;
        }
        DB::table('safe_departure_checks')->where('id', $c->id)->update([
            'status' => 'unconfirmed', 'auto_signed_out_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('check_events')->where('id', $eventId)->update([
            'notes' => 'Auto sign-off — pickup NOT confirmed (the end-of-day Safe Arrival alert was not answered).',
        ]);

        $child = DB::table('children')->where('id', $childId)->first(['first_name', 'preferred_name', 'last_name']);
        $first = $child ? ($child->preferred_name ?: $child->first_name) : 'This child';
        $name = $child ? trim($first . ' ' . $child->last_name) : 'A child';
        $cfg = SafeArrival::settings($agencyId);
        $ids = SafeArrival::recipients($agencyId, (int) $c->centre_id, $c->room_id ? (int) $c->room_id : null, $cfg, false, true);
        $title = "⚠️ Pickup not confirmed: {$name}";
        $body = "{$name} was signed out automatically at " . $at->copy()->timezone($tz)->format('g:i A')
            . ' because no sign-out was recorded, and nobody answered the end-of-day alert. '
            . "Please confirm where {$first} is, then record the pickup time in Safe Arrival.";
        [, $unreached] = SafeArrival::alertUsers($agencyId, $ids, $title, $body, $childId, '#safe-arrival', SafeArrival::upOpts($cfg) + ['kind' => 'departure']);
        if ($unreached) {
            DB::table('safe_departure_checks')->where('id', $c->id)
                ->update(['staff_unreached' => mb_substr(implode(', ', $unreached), 0, 500)]);
        }
        try {
            Audit::write([
                'user_id' => null, 'agency_id' => $agencyId, 'action' => 'safe_departure.unconfirmed',
                'entity_type' => 'child', 'entity_id' => $childId,
                'payload' => json_encode(['summary' => "Signed out automatically with the end-of-day alert still open for {$name}; "
                    . count($ids) . ' staff alerted again.', 'date' => $date]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return true;
    }
}
