<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class RoomManagementController extends Controller
{
    use ResolvesCentreContext;

    /**
     * GET /api/v1/director/rooms
     */
    public function index(Request $request): JsonResponse
    {
        /* EVERY CENTRE THIS PERSON MAY SEE (2026-11-13 sweep): resolveCentreId() is ONE centre - an agency admin's is
           the agency's first - so the admin's "Add child" room list offered centre 8's rooms for a family at any of
           iLearn's seven centres, and the enrolment was then refused. ?centre_id narrows to one of those centres (the
           family's, from that dialog); a centre outside them gives nothing. Each room says its centre. Fails closed. */
        $ids = $this->visibleCentreIds($request);
        if ($want = (int) $request->query('centre_id')) {
            $ids = in_array($want, $ids, true) ? [$want] : [];
        }

        $rooms = DB::table('rooms')
            ->leftJoin('centres', 'centres.id', '=', 'rooms.centre_id')
            ->whereIn('rooms.centre_id', $ids ?: [0])
            ->orderBy('centres.name')
            ->orderBy('rooms.age_min_months')
            ->get(['rooms.*', 'centres.name as centre_name']);

        return response()->json(['rooms' => $rooms]);
    }

    /**
     * POST /api/v1/director/rooms
     */
    public function store(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }
        if (!$this->mayManageRooms($request, $centreId)) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'age_group' => ['required', 'in:infant,toddler,preschool,kindergarten,school_age'],
            'age_min_months' => ['required', 'integer', 'min:0', 'max:144'],
            'age_max_months' => ['required', 'integer', 'gte:age_min_months', 'max:144'],
            'capacity' => ['required', 'integer', 'min:1', 'max:80'],
            'ratio_educators' => ['integer', 'min:1', 'max:10'],
            'ratio_children' => ['required', 'integer', 'min:1', 'max:40'],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'tagline' => ['nullable', 'string', 'max:200'],
        ]);

        $id = DB::table('rooms')->insertGetId([
            ...$data,
            'centre_id' => $centreId,
            'ratio_educators' => $data['ratio_educators'] ?? 1,
            'color_hex' => $data['color_hex'] ?? '#8EC73C',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'room_id' => $id,
            'room' => DB::table('rooms')->where('id', $id)->first(),
        ], 201);
    }

    /**
     * PATCH /api/v1/director/rooms/{room}
     */
    public function update(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();
        if (!$room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)
            || !$this->mayManageRooms($request, (int) $room->centre_id)) {
            abort(403);
        }

        $data = $request->validate([
            'name' => ['string', 'max:120'],
            'age_group' => ['in:infant,toddler,preschool,kindergarten,school_age'],
            'age_min_months' => ['integer', 'min:0', 'max:144'],
            'age_max_months' => ['integer', 'max:144'],
            'capacity' => ['integer', 'min:1', 'max:80'],
            'ratio_educators' => ['integer', 'min:1', 'max:10'],
            'ratio_children' => ['integer', 'min:1', 'max:40'],
            'color_hex' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'description' => ['nullable', 'string', 'max:500'],
            'tagline' => ['nullable', 'string', 'max:200'],
            'active' => ['boolean'],
        ]);

        DB::table('rooms')->where('id', $roomId)->update([
            ...$data,
            'updated_at' => now(),
        ]);

        return response()->json([
            'room' => DB::table('rooms')->where('id', $roomId)->first(),
        ]);
    }

    /**
     * DELETE /api/v1/director/rooms/{room}
     * Soft-deactivate, don't actually delete (preserves history).
     */
    public function destroy(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();
        if (!$room) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)
            || !$this->mayManageRooms($request, (int) $room->centre_id)) {
            abort(403);
        }

        // Check if there are active enrollments
        $activeEnrollments = DB::table('enrollments')
            ->where('room_id', $roomId)
            ->whereNull('end_date')
            ->count();

        if ($activeEnrollments > 0) {
            return response()->json([
                'message' => "Cannot delete room — {$activeEnrollments} child(ren) currently enrolled. Move them first.",
            ], 422);
        }

        DB::table('rooms')->where('id', $roomId)->update([
            'active' => false,
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Room deactivated']);
    }

    /**
     * POST /director/rooms/{room}/logo
     * v22p3.4: upload a per-room logo (mascot, classroom theme image).
     */
    public function uploadLogo(Request $request, int $roomId): JsonResponse
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();
        if (!$room) return response()->json(['message' => 'Not found'], 404);
        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)
            || !$this->mayManageRooms($request, (int) $room->centre_id)) abort(403);

        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ]);
        $file = $request->file('logo');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) \Illuminate\Support\Str::uuid() . '.' . $ext;
        $file->storeAs('room-logos', $name, 'public');
        $publicPath = '/storage/room-logos/' . $name;
        DB::table('rooms')->where('id', $roomId)->update([
            'logo_url'   => $publicPath,
            'updated_at' => now(),
        ]);

        return response()->json([
            'logo_url' => $publicPath,
            'message'  => 'Room logo updated',
        ]);
    }

    /**
     * Rooms are the centre's structure, so only its director or an agency admin changes them
     * (2026-11-05 leak sweep). authorizeCentreAccess() says yes to any staff role at the
     * centre, so an educator there - or a director of another centre who is an educator
     * here - could rename, resize, re-ratio or deactivate its rooms.
     */
    private function mayManageRooms(Request $request, int $centreId): bool
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $centreAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        if ($agencyId <= 0 || $centreAgency !== $agencyId) {
            return false;
        }
        if (\App\Support\CentreScope::ids($request, $agencyId) === null) {
            return true;
        }
        return in_array('centre_director', \App\Support\AgencyRole::names($request), true)
            && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where('role', 'centre_director')->where('centre_id', $centreId)->exists();
    }
}
