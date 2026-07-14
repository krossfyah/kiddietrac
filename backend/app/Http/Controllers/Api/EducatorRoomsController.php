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
