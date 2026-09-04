<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which rooms an educator is assigned to (2026-07-13).
 *
 * An educator sees ONLY the rooms they are assigned to. Assignment is a
 * director/agency-admin decision, which is what this endpoint is for. An
 * educator with no assignments falls back to their whole centre, so a new
 * account is not stranded with an empty app on day one.
 *
 * Both ends are checked: the caller must have access to the centre they are
 * assigning within, and every room must belong to that same centre — so a
 * director cannot assign their staff into another centre's rooms.
 */
class EducatorRoomsController extends Controller
{
    use ResolvesCentreContext;

    /** GET /admin/users/{user}/rooms — the rooms of the user's centre, with what's assigned. */
    public function show(Request $request, int $user): JsonResponse
    {
        $centreId = $this->centreOf($user);
        if (! $centreId || ! $this->authorizeCentreAccess($request->user(), $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $rooms = DB::table('rooms')
            ->where('centre_id', $centreId)
            ->where('active', true)
            ->orderBy('age_min_months')
            ->get(['id', 'name', 'age_group']);

        $assigned = DB::table('educator_rooms')
            ->where('user_id', $user)
            ->pluck('room_id')
            ->map(fn ($i) => (int) $i)
            ->all();

        return response()->json([
            'centre_id' => $centreId,
            'rooms' => $rooms,
            'assigned_room_ids' => $assigned,
            // Told plainly, because the fallback surprises people otherwise.
            'note' => $assigned
                ? 'This educator sees only the rooms assigned below.'
                : 'No rooms assigned — this educator currently sees every room at their centre.',
        ]);
    }

    /** PUT /admin/users/{user}/rooms {room_ids: [1,2]} — replaces the assignment set. */
    public function update(Request $request, int $user): JsonResponse
    {
        $data = $request->validate([
            'room_ids' => 'present|array',
            'room_ids.*' => 'integer',
        ]);

        $centreId = $this->centreOf($user);
        if (! $centreId || ! $this->authorizeCentreAccess($request->user(), $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Every room must belong to THIS user's centre — otherwise a director could
        // assign their educator into another centre's rooms and hand them that
        // centre's children.
        $roomIds = array_values(array_unique(array_map('intval', $data['room_ids'])));
        if ($roomIds) {
            $valid = DB::table('rooms')
                ->whereIn('id', $roomIds)
                ->where('centre_id', $centreId)
                ->pluck('id')->map(fn ($i) => (int) $i)->all();

            if (count($valid) !== count($roomIds)) {
                return response()->json(['message' => 'Those rooms are not all at this educator\'s centre.'], 422);
            }
        }

        DB::transaction(function () use ($user, $roomIds, $request) {
            DB::table('educator_rooms')->where('user_id', $user)->delete();
            foreach ($roomIds as $rid) {
                DB::table('educator_rooms')->insert([
                    'user_id' => $user,
                    'room_id' => $rid,
                    'assigned_by_id' => $request->user()->id,
                    'created_at' => now(),
                ]);
            }
        });

        return response()->json(['ok' => true, 'assigned_room_ids' => $roomIds]);
    }

    /**
     * GET /admin/users/{user}/punches — that staff member's clock in/out history.
     *
     * The punches already existed (time_punches), but only as the person's own
     * "My hours" screen and a centre-wide payroll export. A director asking "when
     * did this educator actually work?" had nowhere to look on the person's own
     * record. Times are returned in the agency's timezone.
     */
    /**
     * PATCH /admin/users/{user}/punches/{punch}
     *
     * Correct a time punch. Nothing could do this before: the educator's own clock only
     * toggles — so "clocking out" a two-day-old shift would record two days — and there
     * was no admin route at all. Four punches have consequently been open for up to a
     * month, distorting hours and, until earlier today, permanently disabling the
     * "you must be clocked in" gate for those accounts.
     *
     * Times arrive as wall-clock strings and are interpreted in the AGENCY's timezone,
     * because that is the clock the person actually worked to. Storing what an admin in
     * another zone happened to type would silently shift the shift.
     */
    /**
     * POST /admin/users/{user}/punches — enter a shift by hand.
     *
     * There was no create. The admin routes could list punches and edit an existing one,
     * so a shift that was never clocked — flat tablet, forgotten press, someone covering at
     * short notice — could not be added at all, and payroll had no way to be made whole
     * short of editing the database directly.
     *
     * Recorded with a reason and stamped in the notes, because a hand-entered shift is a
     * claim about hours somebody will be paid for. It should be obvious later which shifts
     * were clocked and which were typed, and by whom.
     */
    public function storePunch(Request $request, int $user): JsonResponse
    {
        $centreId = $this->centreOf($user);
        if (! $centreId || ! $this->authorizeCentreAccess($request->user(), $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'punched_in_at'  => ['required', 'date'],
            'punched_out_at' => ['nullable', 'date'],
            'reason'         => ['required', 'string', 'max:200'],
        ]);

        $tz = DB::table('centres as c')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->where('c.id', $centreId)
            ->value('a.timezone') ?: 'America/Toronto';

        // Typed as the centre's wall clock; stored as UTC, like every other timestamp here.
        $toUtc = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v, $tz)->utc() : null;
        $in = $toUtc($data['punched_in_at']);
        $out = $toUtc($data['punched_out_at'] ?? null);

        if ($out && $out->lte($in)) {
            return response()->json(['message' => 'The end of the shift must be after the start.'], 422);
        }
        // A shift longer than a day is a typo — usually the wrong date on one end — and
        // silently paying it is worse than refusing it.
        if ($out && $in->diffInHours($out) > 24) {
            return response()->json(['message' => 'That shift is longer than 24 hours. Check the dates.'], 422);
        }

        // Overlapping an existing punch would double-count the hours.
        $clash = DB::table('time_punches')->where('user_id', $user)
            ->where(function ($q) use ($in, $out) {
                $end = $out ?: $in;
                $q->where(function ($w) use ($in, $end) {
                    $w->where('punched_in_at', '<=', $end->toDateTimeString())
                      ->where(function ($x) use ($in) {
                          $x->whereNull('punched_out_at')->orWhere('punched_out_at', '>=', $in->toDateTimeString());
                      });
                });
            })->exists();
        if ($clash) {
            return response()->json(['message' => 'That overlaps a shift already recorded for this person.'], 422);
        }

        $by = trim((string) (($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? ''))) ?: 'an administrator';
        $id = DB::table('time_punches')->insertGetId([
            'user_id' => $user,
            'centre_id' => $centreId,
            'punched_in_at' => $in->toDateTimeString(),
            'punched_out_at' => $out?->toDateTimeString(),
            'notes' => 'Entered manually by ' . $by . ' on ' . now()->timezone($tz)->format('j M Y')
                . ' — ' . $data['reason'],
            'created_at' => now(),
        ]);

        return response()->json(['id' => $id, 'created' => true], 201);
    }

    public function updatePunch(Request $request, int $user, int $punch): JsonResponse
    {
        $centreId = $this->centreOf($user);
        if (! $centreId || ! $this->authorizeCentreAccess($request->user(), $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $row = DB::table('time_punches')->where('id', $punch)->where('user_id', $user)->first();
        if (! $row) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'punched_in_at'  => ['nullable', 'date'],
            'punched_out_at' => ['nullable', 'date'],
            'reason'         => ['nullable', 'string', 'max:200'],
        ]);
        if (! array_key_exists('punched_in_at', $data) && ! array_key_exists('punched_out_at', $data)) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        $tz = DB::table('centres as c')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->where('c.id', $centreId)
            ->value('a.timezone') ?: 'America/Toronto';

        // Wall-clock in the agency zone → UTC, which is what the column stores.
        $toUtc = function ($v) use ($tz) {
            if ($v === null || $v === '') return null;
            return \Illuminate\Support\Carbon::parse($v, $tz)->utc()->toDateTimeString();
        };

        $newIn  = array_key_exists('punched_in_at', $data)  ? $toUtc($data['punched_in_at'])  : $row->punched_in_at;
        $newOut = array_key_exists('punched_out_at', $data) ? $toUtc($data['punched_out_at']) : $row->punched_out_at;

        if (! $newIn) return response()->json(['message' => 'A clock-in time is required.'], 422);
        if ($newOut && strtotime((string) $newOut) <= strtotime((string) $newIn)) {
            return response()->json(['message' => 'The clock-out time must be after the clock-in time.'], 422);
        }
        // A shift longer than 24 hours is almost certainly a typo, and quietly accepting
        // it puts a wrong number straight into someone's pay.
        if ($newOut && (strtotime((string) $newOut) - strtotime((string) $newIn)) > 86400) {
            return response()->json(['message' => 'That is longer than 24 hours — please check the date and time.'], 422);
        }

        $actor = $request->user();
        $actorName = trim(((string) ($actor->first_name ?? '')) . ' ' . ((string) ($actor->last_name ?? ''))) ?: 'an administrator';

        // Stamp the punch itself, so a corrected shift is visible on the row rather than
        // only in a log somebody has to know to look for.
        $stamp = 'Corrected by ' . $actorName . ' on ' . now()->setTimezone($tz)->format('j M Y')
            . (! empty($data['reason']) ? ' — ' . $data['reason'] : '');
        $notes = trim((string) ($row->notes ?? ''));
        $notes = $notes === '' ? $stamp : mb_substr($notes . ' | ' . $stamp, 0, 300);

        DB::table('time_punches')->where('id', $punch)->update([
            'punched_in_at'  => $newIn,
            'punched_out_at' => $newOut,
            'notes'          => $notes,
        ]);

        try {
            \App\Support\Audit::write([
                'user_id'     => $actor->id,
                'action'      => 'timepunch.corrected',
                'entity_type' => 'time_punch',
                'entity_id'   => $punch,
                'payload'     => json_encode([
                    'staff_user_id' => $user,
                    'from' => ['in' => $row->punched_in_at, 'out' => $row->punched_out_at],
                    'to'   => ['in' => $newIn,              'out' => $newOut],
                    'reason' => $data['reason'] ?? null,
                ]),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // The correction stands even if the audit insert fails, but do not lose it.
            \Illuminate\Support\Facades\Log::error('Punch correction audit failed', [
                'punch' => $punch, 'error' => $e->getMessage(),
            ]);
        }

        $in = \Illuminate\Support\Carbon::parse($newIn)->timezone($tz);
        $out = $newOut ? \Illuminate\Support\Carbon::parse($newOut)->timezone($tz) : null;

        return response()->json([
            'ok' => true,
            'punch' => [
                'id' => $punch,
                'day' => $in->format('D j M Y'),
                'in_time' => $in->format('g:i A'),
                'out_time' => $out?->format('g:i A'),
                'punched_out_at' => $newOut,
                'hours' => $out ? round($in->floatDiffInHours($out), 2) : null,
                'notes' => $notes,
            ],
        ]);
    }

    public function punches(Request $request, int $user): JsonResponse
    {
        $centreId = $this->centreOf($user);
        if (! $centreId || ! $this->authorizeCentreAccess($request->user(), $centreId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $tz = DB::table('centres as c')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->where('c.id', $centreId)
            ->value('a.timezone') ?: 'America/Toronto';

        $rows = DB::table('time_punches')
            ->where('user_id', $user)
            ->orderByDesc('punched_in_at')
            ->limit(90)
            ->get(['id', 'punched_in_at', 'punched_out_at', 'notes']);

        $total = 0.0;
        $punches = $rows->map(function ($p) use ($tz, &$total) {
            $in = \Illuminate\Support\Carbon::parse($p->punched_in_at)->timezone($tz);
            $out = $p->punched_out_at ? \Illuminate\Support\Carbon::parse($p->punched_out_at)->timezone($tz) : null;
            $hours = $out ? round($in->floatDiffInHours($out), 2) : null;
            if ($hours) $total += $hours;

            return [
                'id' => $p->id,
                'day' => $in->format('D j M Y'),
                'in_time' => $in->format('g:i A'),
                'out_time' => $out?->format('g:i A'),
                // For <input type="datetime-local">, in the AGENCY's zone. The display
                // strings above are for people; parsing them back into a date in the
                // browser is how off-by-one-day errors get into payroll.
                'in_local' => $in->format('Y-m-d\TH:i'),
                'out_local' => $out?->format('Y-m-d\TH:i'),
                'punched_out_at' => $p->punched_out_at,
                'hours' => $hours,
                'notes' => $p->notes,
            ];
        });

        return response()->json([
            'punches' => $punches,
            'total_hours' => round($total, 2),
            'timezone' => $tz,
        ]);
    }

    private function centreOf(int $userId): ?int
    {
        $id = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->value('centre_id');

        return $id ? (int) $id : null;
    }
}
