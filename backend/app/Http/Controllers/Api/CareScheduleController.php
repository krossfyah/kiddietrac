<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CareSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Who has this child, on which days.
 *
 * A family can split a week across providers — Mon–Thu with one, Friday with another —
 * and there was nowhere in the portal to say so. The result was a child parked in one
 * room all week: the Friday provider never saw her, and the weekday provider carried
 * 8 children against a capacity of 6.
 *
 * Stored as what it is: one open `enrollments` row per provider, with the week's days
 * divided between them. Both columns already existed; nothing had ever read `schedule`.
 */
class CareScheduleController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

    /** GET /admin/children/{child}/care-schedule */
    public function show(Request $request, int $childId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertChild($userId, $childId);

        $child = DB::table('children')->find($childId);
        if (! $child) {
            return response()->json(['message' => 'Child not found'], 404);
        }

        $agencyId = (int) DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $childId)->value('ce.agency_id');

        /* Every provider in the agency, with how full each already is TODAY. A room is
           not "full" in the abstract — it is full on the days people actually attend —
           but a single headline number is what an admin needs to avoid an obvious
           mistake, and the save re-checks per day anyway. */
        $rooms = DB::table('rooms as r')
            ->join('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->where('ce.agency_id', $agencyId)
            ->where('r.active', 1)
            ->orderBy('ce.name')->orderBy('r.name')
            ->get(['r.id', 'r.name', 'r.capacity', 'ce.id as centre_id', 'ce.name as centre_name']);

        $perDay = [];
        foreach (CareSchedule::DAYS as $day) {
            foreach ($rooms as $r) {
                $q = DB::table('enrollments as e')
                    ->join('children as ch', 'ch.id', '=', 'e.child_id')
                    ->where('e.room_id', $r->id)
                    ->whereNull('e.end_date')
                    ->where('ch.enrollment_status', 'enrolled')
                    ->whereNull('ch.deleted_at')
                    ->where('ch.id', '!=', $childId);   // excluding this child, so the UI shows the room WITHOUT them
                CareSchedule::constrain($q, 'e', $day);
                $perDay[$day][(int) $r->id] = $q->count();
            }
        }

        return response()->json([
            'child' => [
                'id' => (int) $child->id,
                'name' => trim(($child->preferred_name ?: $child->first_name).' '.($child->last_name ?? '')),
            ],
            'days' => CareSchedule::DAYS,
            'labels' => CareSchedule::LABELS,
            'week' => CareSchedule::week($childId),
            'summary' => CareSchedule::summary($childId),
            'today' => CareSchedule::dayKey(CareSchedule::tzForChild($childId)),
            'providers' => $rooms->map(fn ($r) => [
                'room_id' => (int) $r->id,
                'room_name' => $r->name,
                'centre_id' => (int) $r->centre_id,
                'centre_name' => $r->centre_name,
                'capacity' => (int) ($r->capacity ?: 0),
            ])->values(),
            'occupancy' => $perDay,
        ]);
    }

    /**
     * PUT /admin/children/{child}/care-schedule
     *
     * Body: { week: { mon: <room_id|null>, tue: …, … } }
     */
    public function update(Request $request, int $childId): JsonResponse
    {
        $userId = (int) $request->user()->id;
        $this->assertChild($userId, $childId);

        $data = $request->validate([
            'week' => ['required', 'array'],
            'week.*' => ['nullable', 'integer'],
        ]);

        $child = DB::table('children')->find($childId);
        if (! $child) {
            return response()->json(['message' => 'Child not found'], 404);
        }

        $agencyId = (int) DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('c.id', $childId)->value('ce.agency_id');

        // Only rooms in this agency, and only days we recognise.
        $validRooms = DB::table('rooms as r')->join('centres as ce', 'ce.id', '=', 'r.centre_id')
            ->where('ce.agency_id', $agencyId)->pluck('r.id')->map(fn ($v) => (int) $v)->all();

        $week = [];
        foreach (CareSchedule::DAYS as $day) {
            $roomId = $data['week'][$day] ?? null;
            if ($roomId === null || $roomId === '' || (int) $roomId === 0) {
                $week[$day] = null;
                continue;
            }
            $roomId = (int) $roomId;
            if (! in_array($roomId, $validRooms, true)) {
                return response()->json([
                    'message' => 'That provider is not in this agency.',
                    'errors' => ['week' => ['Unknown provider for '.($day)]],
                ], 422);
            }
            $this->assertCentre($userId, (int) DB::table('rooms')->where('id', $roomId)->value('centre_id'));
            $week[$day] = $roomId;
        }

        if (! array_filter($week)) {
            return response()->json(['message' => 'Choose a provider for at least one day.'], 422);
        }

        /* Capacity, checked PER DAY. A room with six places can take six children on
           Monday and a different six on Friday; refusing on a weekly total would block
           exactly the arrangement this feature exists to support. */
        $before = CareSchedule::week($childId);
        foreach ($week as $day => $roomId) {
            if (! $roomId) {
                continue;
            }

            /* Only where the child is actually MOVING IN. A room that is already over
               its capacity must not stop an admin recording the arrangement that
               already exists — Cassandra's room is 8 against a capacity of 6 today, and
               refusing the save would mean the portal could never be made to describe
               reality. This blocks making it worse, which is the thing worth blocking. */
            $alreadyThere = ($before[$day]['room_id'] ?? null) === (int) $roomId;
            if ($alreadyThere) {
                continue;
            }

            $room = DB::table('rooms')->find($roomId);
            $cap = (int) ($room->capacity ?: 0);
            if ($cap <= 0) {
                continue;
            }
            $q = DB::table('enrollments as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->where('e.room_id', $roomId)->whereNull('e.end_date')
                ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at')
                ->where('ch.id', '!=', $childId);
            CareSchedule::constrain($q, 'e', $day);
            $others = $q->count();
            if ($others + 1 > $cap) {
                return response()->json([
                    'message' => $room->name.' is full on '.CareSchedule::LABELS[$day]
                        .' — '.$others.' of '.$cap.' places are taken.',
                    'errors' => ['week' => [CareSchedule::LABELS[$day].': '.$room->name.' is full.']],
                ], 422);
            }
        }

        // room_id => [days]
        $byRoom = [];
        foreach ($week as $day => $roomId) {
            if ($roomId) {
                $byRoom[$roomId][] = $day;
            }
        }

        $tz = CareSchedule::tzForChild($childId);
        $today = \Illuminate\Support\Carbon::now($tz)->toDateString();

        DB::transaction(function () use ($childId, $byRoom, $today) {
            $open = DB::table('enrollments')->where('child_id', $childId)->whereNull('end_date')->get();
            $template = $open->first();

            foreach ($byRoom as $roomId => $days) {
                $existing = $open->firstWhere('room_id', $roomId);
                if ($existing) {
                    /* Reuse the row rather than closing and reopening it. The start_date
                       is when this child began with that provider, and rewriting it every
                       time somebody edits the rota would erase that. */
                    DB::table('enrollments')->where('id', $existing->id)
                        ->update(['schedule' => json_encode(array_values($days))]);
                } else {
                    DB::table('enrollments')->insert([
                        'child_id' => $childId,
                        'room_id' => $roomId,
                        'start_date' => $today,
                        'end_date' => null,
                        'schedule' => json_encode(array_values($days)),
                        // A new provider inherits the existing terms; splitting a week is
                        // not a re-negotiation of the fee.
                        'monthly_fee' => $template->monthly_fee ?? 0,
                        'cwelcc_eligible' => $template->cwelcc_eligible ?? 1,
                        'created_at' => now(),
                    ]);
                }
            }

            // A provider dropped from the rota is closed, never deleted — the child was
            // genuinely with them until today, and that has to stay on the record.
            foreach ($open as $e) {
                if (! isset($byRoom[(int) $e->room_id])) {
                    DB::table('enrollments')->where('id', $e->id)->update(['end_date' => $today]);
                }
            }
        });

        CareSchedule::syncPrimaryRoom($childId, $tz);

        try {
            \App\Support\Audit::write([
                'user_id' => $userId,
                'agency_id' => $agencyId,
                'action' => 'child.care_schedule_updated',
                'entity_type' => 'child',
                'entity_id' => $childId,
                'payload' => json_encode(['week' => $week, 'summary' => CareSchedule::summary($childId)]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Care schedule audit failed: '.$e->getMessage());
        }

        /* Flag any day that is over capacity after the save, including rooms the child
           was already in. Not an error - the arrangement is real and now recorded - but
           an admin should not have to discover it from a ratio alert later. */
        $overCapacity = [];
        foreach ($week as $day => $roomId) {
            if (! $roomId) {
                continue;
            }
            $room = DB::table('rooms')->find($roomId);
            $cap = (int) ($room->capacity ?: 0);
            if ($cap <= 0) {
                continue;
            }
            $q = DB::table('enrollments as e')
                ->join('children as ch', 'ch.id', '=', 'e.child_id')
                ->where('e.room_id', $roomId)->whereNull('e.end_date')
                ->where('ch.enrollment_status', 'enrolled')->whereNull('ch.deleted_at');
            CareSchedule::constrain($q, 'e', $day);
            $n = $q->count();
            if ($n > $cap) {
                $overCapacity[] = CareSchedule::LABELS[$day].': '.$room->name.' has '.$n.' of '.$cap;
            }
        }

        return response()->json([
            'ok' => true,
            'week' => CareSchedule::week($childId),
            'summary' => CareSchedule::summary($childId),
            'over_capacity' => $overCapacity,
            'message' => 'Care schedule saved — '.CareSchedule::summary($childId),
        ]);
    }
}
