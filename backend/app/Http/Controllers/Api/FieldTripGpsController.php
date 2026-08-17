<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Field-trip GPS tracking.
 *   POST /field-trips/{id}/ping     driver phone posts location
 *   GET  /field-trips/{id}/location latest location + trail
 */
final class FieldTripGpsController extends Controller
{
    use ResolvesCentreContext;

    public function ping(Request $request, int $tripId): JsonResponse
    {
        $data = $request->validate([
            'lat' => 'required|numeric|between:-90,90',
            'lon' => 'required|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer',
            'speed_mps' => 'nullable|numeric',
        ]);
        $u = $request->user();
        $trip = DB::table('field_trips')->where('id', $tripId)->first();
        abort_unless($trip, 404);
        // Authorise: must be staff_lead OR a centre staff member
        $isLead = $trip->staff_lead_id === $u->id;
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isLead || $isStaff, 403);

        DB::table('field_trip_pings')->insert([
            'field_trip_id' => $tripId,
            'user_id' => $u->id,
            'lat' => $data['lat'], 'lon' => $data['lon'],
            'accuracy_m' => $data['accuracy_m'] ?? null,
            'speed_mps' => $data['speed_mps'] ?? null,
            'recorded_at' => now(),
        ]);
        return response()->json(['status' => 'recorded']);
    }

    public function location(Request $request, int $tripId): JsonResponse
    {
        $trip = DB::table('field_trips')->where('id', $tripId)->first();
        abort_unless($trip, 404);
        // Authorisation: any guardian of an enrolled child OR staff
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        if ($isStaff) {
            // SECURITY (v22p96): staff may only track a trip in their OWN agency;
            // a platform_admin only one in the agency they've switched into. Was a
            // global staff bypass that exposed any trip's live GPS trail by id.
            $isStaff = $this->isPlatformAdminUser($u)
                ? (int) $trip->agency_id === (int) $this->resolveAgencyId($request)
                : $this->userBelongsToAgency($u->id, (int) $trip->agency_id);
        }
        if (!$isStaff) {
            $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
            $childIds = DB::table('children')->whereIn('family_id', $familyIds)->pluck('id');
            $allowed = DB::table('field_trip_permissions')
                ->where('field_trip_id', $tripId)
                ->whereIn('child_id', $childIds)
                ->where('status', 'approved')->exists();
            abort_unless($allowed, 403);
        }

        $latest = DB::table('field_trip_pings')
            ->where('field_trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->first();
        $trail = DB::table('field_trip_pings')
            ->where('field_trip_id', $tripId)
            ->orderByDesc('recorded_at')
            ->limit(100)
            ->select('lat', 'lon', 'recorded_at')
            ->get()->reverse()->values();
        // Distance walked / estimated steps / duration (for parent + admin reports).
        $summary = WalkController::walkSummary($tripId);

        return response()->json([
            'latest' => $latest,
            'trail' => $trail,
            'distance_m' => $summary['distance_m'],
            'steps_est' => $summary['steps_est'],
            'duration_min' => $summary['duration_min'],
            'trip' => [
                'id' => $trip->id, 'title' => $trip->title, 'destination' => $trip->destination,
                'depart_time' => $trip->depart_time, 'return_time' => $trip->return_time,
                'status' => $trip->status,
            ],
        ]);
    }
}
