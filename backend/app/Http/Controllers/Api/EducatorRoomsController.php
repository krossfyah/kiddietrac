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
