<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which rooms each educator works in.
 *
 * educator_rooms drives who sees which children on several screens, and it is currently
 * empty for the entire platform — so anything keyed on it silently returns nothing and the
 * code that reads it has had to fall back to the centre. Assigning rooms is a data job
 * nobody could do, because there was no screen for it.
 *
 * Saving REPLACES a person's assignments rather than adding to them: the screen shows the
 * complete set, so what is submitted is the complete answer. Adding would leave a room
 * silently attached after somebody unticked it.
 */
class EducatorRoomController extends Controller
{
    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->get(['role', 'agency_id']);
        $isPlatform = $roles->contains(fn ($r) => $r->role === 'platform_admin');
        $owned = $roles->pluck('agency_id')->filter()->map(fn ($v) => (int) $v)->all();

        if ($header && ($isPlatform || in_array($header, $owned, true))) {
            return $header;
        }
        if ($owned) {
            return (int) $owned[0];
        }
        abort(403, 'No agency access.');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Administrator access required.');
    }

    public function index(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        $rooms = DB::table('rooms as r')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where(function ($q) { $q->where('r.active', 1)->orWhereNull('r.active'); })
            ->orderBy('c.name')->orderBy('r.name')
            ->get(['r.id', 'r.name', 'r.age_group', 'c.id as centre_id', 'c.name as centre_name']);

        $educators = DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.active', 1)->where('ra.agency_id', $agencyId)
            ->whereIn('ra.role', ['educator', 'home_visitor'])
            ->whereNull('u.deleted_at')
            ->orderBy('u.first_name')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role',
                   'ra.centre_id', 'c.name as centre_name'])
            ->unique('id')->values();

        $assigned = DB::table('educator_rooms')
            ->whereIn('user_id', $educators->pluck('id'))
            ->get(['user_id', 'room_id'])
            ->groupBy('user_id');

        return response()->json([
            'rooms' => $rooms,
            'educators' => $educators->map(fn ($e) => [
                'id' => (int) $e->id,
                'name' => trim($e->first_name . ' ' . ($e->last_name ?? '')),
                'email' => $e->email,
                'role' => $e->role,
                'centre_id' => $e->centre_id ? (int) $e->centre_id : null,
                'centre_name' => $e->centre_name,
                'room_ids' => $assigned->get($e->id, collect())->pluck('room_id')->map(fn ($r) => (int) $r)->values(),
            ]),
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $agencyId = $this->agency($request);

        $data = $request->validate([
            'assignments' => 'required|array|max:500',
            'assignments.*.user_id' => 'required|integer',
            'assignments.*.room_ids' => 'present|array',
            'assignments.*.room_ids.*' => 'integer',
        ]);

        // Only rooms and people that belong to this agency. Without this, a crafted
        // request could attach one agency's educator to another agency's room.
        $validRooms = DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $agencyId)->pluck('r.id')->map(fn ($v) => (int) $v)->all();
        $validUsers = DB::table('role_assignments')->where('active', 1)->where('agency_id', $agencyId)
            ->whereIn('role', ['educator', 'home_visitor'])->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();

        $changed = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $validRooms, $validUsers, $request, &$changed, &$skipped) {
            foreach ($data['assignments'] as $a) {
                $uid = (int) $a['user_id'];
                if (! in_array($uid, $validUsers, true)) {
                    $skipped++;
                    continue;
                }
                $wanted = array_values(array_unique(array_filter(
                    array_map('intval', $a['room_ids']),
                    fn ($r) => in_array($r, $validRooms, true)
                )));

                $current = DB::table('educator_rooms')->where('user_id', $uid)
                    ->pluck('room_id')->map(fn ($v) => (int) $v)->all();
                sort($current);
                $sorted = $wanted;
                sort($sorted);
                if ($current === $sorted) {
                    continue;   // nothing to do; do not churn rows or timestamps
                }

                // Replace: the screen submits the complete set for this person.
                DB::table('educator_rooms')->where('user_id', $uid)->delete();
                foreach ($wanted as $roomId) {
                    DB::table('educator_rooms')->insert([
                        'user_id' => $uid,
                        'room_id' => $roomId,
                        'assigned_by_id' => $request->user()->id,
                        'created_at' => now(),
                    ]);
                }
                $changed++;
            }
        });

        return response()->json(['ok' => true, 'changed' => $changed, 'skipped' => $skipped]);
    }
}
