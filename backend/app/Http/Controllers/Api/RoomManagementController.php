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
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) {
            return response()->json(['rooms' => []]);
        }

        $rooms = DB::table('rooms')
            ->where('centre_id', $centreId)
            ->orderBy('age_min_months')
            ->get();

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

        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) {
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

        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) {
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
        if (!$this->authorizeCentreAccess($request->user(), (int) $room->centre_id)) abort(403);

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
}
