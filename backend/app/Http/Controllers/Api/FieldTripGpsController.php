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
        /* A BATCH, NOT ONLY ONE POINT (2026-10-28, Anthony: "with educators on a walk and for whatever reason it
           loses connection can you resume"). A phone that loses signal keeps its fixes and sends them when it is
           back, each with the time it was taken - the route has no hole where the connection dropped. A single
           point (lat/lon) still works exactly as before. */
        $data = $request->validate([
            'lat' => 'required_without:points|numeric|between:-90,90',
            'lon' => 'required_without:points|numeric|between:-180,180',
            'accuracy_m' => 'nullable|integer',
            'speed_mps' => 'nullable|numeric',
            'points' => 'nullable|array|max:300',
            'points.*.lat' => 'required_with:points|numeric|between:-90,90',
            'points.*.lon' => 'required_with:points|numeric|between:-180,180',
            'points.*.accuracy_m' => 'nullable|numeric',
            'points.*.speed_mps' => 'nullable|numeric',
            'points.*.recorded_at' => 'nullable|date',
        ]);
        $u = $request->user();
        $trip = DB::table('field_trips')->where('id', $tripId)->first();
        abort_unless($trip, 404);
        /* Authorise: the walk's lead, or staff OF THE WALK'S AGENCY. It was any active staff role anywhere, so a
           member of one agency could write location points onto another agency's walk by id. */
        $isLead = (int) $trip->staff_lead_id === (int) $u->id;
        /* Staff OF THE WALK'S CENTRE (2026-11-05 leak sweep): "a staff role anywhere + any role in the
           walk's agency" let an educator of agency A who is a parent in agency B, or staff of another
           centre, write points onto that walk. Now: a staff role at the walk's centre (or its agency's
           admin, or a room there), or a platform admin switched into its agency - authorizeCentreAccess(). */
        $isStaff = (int) ($trip->centre_id ?? 0) > 0 && $this->authorizeCentreAccess($u, (int) $trip->centre_id);
        abort_unless($isLead || $isStaff, 403);

        /* An ended walk takes no more points, and SAYS so - the phone stops sharing instead of reporting a
           location to a walk the centre already closed (ended on another device, or closed overnight). */
        if (isset($trip->status) && $trip->status !== 'active') {
            return response()->json(['status' => 'ended', 'ended' => true], 409);
        }

        $started = \Illuminate\Support\Carbon::parse((string) $trip->created_at)->subMinutes(5);
        $now = now();
        $rows = [];
        foreach (! empty($data['points']) ? $data['points'] : [[
            'lat' => $data['lat'], 'lon' => $data['lon'],
            'accuracy_m' => $data['accuracy_m'] ?? null, 'speed_mps' => $data['speed_mps'] ?? null,
        ]] as $p) {
            $at = $now;
            if (! empty($p['recorded_at'])) {
                try {
                    $at = \Illuminate\Support\Carbon::parse((string) $p['recorded_at'])->utc();
                    if ($at->gt($now)) { $at = $now; }           // a phone clock ahead of ours
                    if ($at->lt($started)) { continue; }         // older than the walk itself: not this walk
                } catch (\Throwable $e) { $at = $now; }
            }
            $rows[] = [
                'field_trip_id' => $tripId,
                'user_id' => $u->id,
                'lat' => $p['lat'], 'lon' => $p['lon'],
                'accuracy_m' => isset($p['accuracy_m']) ? (int) round((float) $p['accuracy_m']) : null,
                'speed_mps' => $p['speed_mps'] ?? null,
                'recorded_at' => $at,
            ];
        }
        if ($rows) {
            DB::table('field_trip_pings')->insert($rows);
        }
        return response()->json(['status' => 'recorded', 'count' => count($rows)]);
    }

    public function location(Request $request, int $tripId): JsonResponse
    {
        $trip = DB::table('field_trips')->where('id', $tripId)->first();
        abort_unless($trip, 404);
        // Authorisation: any guardian of an enrolled child OR staff
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
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
