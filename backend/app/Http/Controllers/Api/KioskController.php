<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * v22p5 — Kiosk mode.
 *
 * Two surfaces:
 *
 *   1. Public (no auth) — for tablets stationed at the centre door.
 *      GET  /api/kiosk/{token}                — centre meta + enrolled roster.
 *      POST /api/kiosk/{token}/check-event    — verify PIN, record check_event.
 *
 *   2. Director (auth, role:centre_director,agency_admin) — management.
 *      POST /api/v1/director/centres/{centre}/kiosk-token   — rotate token.
 *      POST /api/v1/director/centres/{centre}/kiosk-toggle  — enable/disable.
 *      POST /api/v1/director/guardians/{guardian}/kiosk-pin — set PIN.
 *
 * Auth model on the public side: NO sanctum. The token alone is meaningless
 * — it only lets the kiosk SEE the centre's children. The PIN gates writes.
 * Director can rotate the token if the URL leaks.
 */
final class KioskController extends Controller
{
    // ────────────────────────────────────────────────────────────────
    //   Public surface (no auth)
    // ────────────────────────────────────────────────────────────────

    public function lookup(Request $request, string $token): JsonResponse
    {
        $centre = DB::table('centres')
            ->where('kiosk_token', $token)
            ->where('kiosk_enabled', true)
            ->whereNull('deleted_at')
            ->first(['id', 'name', 'city', 'logo_url', 'brand_color', 'accent_color']);

        if (! $centre) {
            return response()->json(['message' => 'Kiosk not available'], 404);
        }

        $children = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->leftJoin('enrollments', fn ($j) => $j
                ->on('enrollments.child_id', '=', 'children.id')
                ->whereNull('enrollments.end_date'))
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('families.centre_id', $centre->id)
            ->where('children.enrollment_status', 'enrolled')
            ->whereNull('children.deleted_at')
            ->orderBy('children.first_name')
            ->get([
                'children.id', 'children.first_name', 'children.last_name',
                'children.preferred_name', 'children.photo_url',
                'rooms.id as room_id', 'rooms.name as room_name', 'rooms.color_hex as room_color',
            ]);

        $presentIds = DB::table('check_events as ci')
            ->join('children as ch', 'ch.id', '=', 'ci.child_id')
            ->join('families as fa', 'fa.id', '=', 'ch.family_id')
            ->where('fa.centre_id', $centre->id)
            ->whereDate('ci.occurred_at', now())
            ->where('ci.event_type', 'check_in')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))
                ->from('check_events as co')
                ->whereColumn('co.child_id', 'ci.child_id')
                ->where('co.event_type', 'check_out')
                ->where('co.occurred_at', '>', DB::raw('ci.occurred_at')))
            ->pluck('ci.child_id')
            ->all();

        return response()->json([
            'centre' => $centre,
            'children' => $children->map(fn ($c) => [
                'id' => $c->id,
                'display_name' => $c->preferred_name ?: $c->first_name,
                'last_name' => $c->last_name,
                'photo_url' => $c->photo_url,
                'room_id' => $c->room_id,
                'room_name' => $c->room_name,
                'room_color' => $c->room_color,
                'is_at_centre' => in_array($c->id, $presentIds, true),
            ])->values(),
        ]);
    }

    public function checkEvent(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'pin' => ['required', 'string', 'min:4', 'max:6'],
            'event_type' => ['required', 'in:check_in,check_out'],
        ]);

        $centre = DB::table('centres')
            ->where('kiosk_token', $token)
            ->where('kiosk_enabled', true)
            ->whereNull('deleted_at')
            ->first();

        if (! $centre) {
            return response()->json(['message' => 'Kiosk not available'], 404);
        }

        $child = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('children.id', $data['child_id'])
            ->where('families.centre_id', $centre->id)
            ->whereNull('children.deleted_at')
            ->first(['children.id', 'children.family_id']);

        if (! $child) {
            return response()->json(['message' => 'Child not at this centre'], 404);
        }

        // Find a guardian in the same family with a matching PIN.
        $guardians = DB::table('guardians')
            ->where('family_id', $child->family_id)
            ->where('can_pickup', true)
            ->whereNotNull('kiosk_pin_hash')
            ->get(['user_id', 'kiosk_pin_hash']);

        $matchUserId = null;
        foreach ($guardians as $g) {
            if (Hash::check($data['pin'], $g->kiosk_pin_hash)) {
                $matchUserId = (int) $g->user_id;
                break;
            }
        }

        if (! $matchUserId) {
            return response()->json(['message' => 'Invalid PIN'], 403);
        }

        // Resolve current room from active enrollment.
        $enrollment = DB::table('enrollments')
            ->where('child_id', $child->id)
            ->whereNull('end_date')
            ->orderByDesc('start_date')
            ->first(['room_id']);

        if (! $enrollment) {
            return response()->json(['message' => 'Child not enrolled in any room'], 422);
        }

        DB::table('check_events')->insert([
            'child_id' => $child->id,
            'room_id' => $enrollment->room_id,
            'event_type' => $data['event_type'],
            'occurred_at' => now(),
            'by_user_id' => $matchUserId,
            'recorded_by_id' => $matchUserId,
            'kiosk_source' => true,
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Recorded',
            'event_type' => $data['event_type'],
            'occurred_at' => now()->toIso8601String(),
        ], 201);
    }

    // ────────────────────────────────────────────────────────────────
    //   Director surface (sanctum + role middleware applied by route group)
    // ────────────────────────────────────────────────────────────────

    public function rotateToken(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')
            ->where('id', $centreId)
            ->whereNull('deleted_at')
            ->first();

        if (! $centre || ! $this->canManage($request->user(), (int) $centre->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $newToken = Str::random(48);
        DB::table('centres')->where('id', $centreId)->update([
            'kiosk_token' => $newToken,
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Token rotated',
            'kiosk_token' => $newToken,
            // Kiosk URL constructed client-side as <app-domain>/kiosk.html?token=<value>
            // — Laravel's url() helper uses APP_URL which is the api.* domain, not app.*.
        ]);
    }

    public function toggleEnabled(Request $request, int $centreId): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $centre = DB::table('centres')
            ->where('id', $centreId)
            ->whereNull('deleted_at')
            ->first();

        if (! $centre || ! $this->canManage($request->user(), (int) $centre->id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('centres')->where('id', $centreId)->update([
            'kiosk_enabled' => $data['enabled'],
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Kiosk ' . ($data['enabled'] ? 'enabled' : 'disabled')]);
    }

    public function setGuardianPin(Request $request, int $guardianId): JsonResponse
    {
        $data = $request->validate(['pin' => ['required', 'digits_between:4,6']]);

        $guardian = DB::table('guardians')->where('id', $guardianId)->first();
        if (! $guardian) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $family = DB::table('families')->where('id', $guardian->family_id)->first();
        if (! $family || ! $this->canManage($request->user(), (int) $family->centre_id)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('guardians')->where('id', $guardianId)->update([
            'kiosk_pin_hash' => Hash::make($data['pin']),
            'kiosk_pin_set_at' => now(),
        ]);

        return response()->json(['message' => 'PIN set']);
    }

    // ────────────────────────────────────────────────────────────────
    //   Helpers
    // ────────────────────────────────────────────────────────────────

    private function canManage($user, int $centreId): bool
    {
        // agency_admin: any centre in their agency.
        $agencyId = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
        if ($agencyId) {
            return DB::table('centres')
                ->where('id', $centreId)
                ->where('agency_id', $agencyId)
                ->exists();
        }

        // centre_director: only their assigned centre.
        return DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('role', 'centre_director')
            ->where('centre_id', $centreId)
            ->where('active', true)
            ->exists();
    }
}
