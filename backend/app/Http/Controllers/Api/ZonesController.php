<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p59 — Activity zones tracking.
 *  Zones are named corners within a centre (Art, Reading, Sensory, Outdoor).
 *  Educators log which zone each child engaged in. Daily report shows
 *  zone-time per child + per zone.
 */
final class ZonesController extends Controller
{
    public function listZones(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400, 'centre_id required');
        $rows = DB::table('activity_zones')
            ->where('centre_id', $centreId)
            ->where('active', 1)
            ->orderBy('display_order')->orderBy('name')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function createZone(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => 'required|integer',
            'room_id' => 'nullable|integer',
            'name' => 'required|string|max:80',
            'icon' => 'nullable|string|max:20',
            'color' => 'nullable|string|max:20',
            'display_order' => 'nullable|integer',
        ]);
        $id = DB::table('activity_zones')->insertGetId([
            'centre_id' => $data['centre_id'],
            'room_id' => $data['room_id'] ?? null,
            'name' => $data['name'],
            'icon' => $data['icon'] ?? '🎨',
            'color' => $data['color'] ?? '#A855F7',
            'display_order' => $data['display_order'] ?? 0,
            'active' => 1,
            'created_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function logVisit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer',
            'zone_id' => 'required|integer',
            'duration_minutes' => 'nullable|integer|min:1|max:240',
            'notes' => 'nullable|string|max:500',
        ]);
        $now = Carbon::now();
        $endedAt = isset($data['duration_minutes']) ? $now->copy()->addMinutes($data['duration_minutes']) : null;
        $id = DB::table('zone_visits')->insertGetId([
            'child_id' => $data['child_id'],
            'zone_id' => $data['zone_id'],
            'visited_at' => $now,
            'ended_at' => $endedAt,
            'recorded_by_id' => $request->user()->id,
            'notes' => $data['notes'] ?? null,
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function dailyReport(Request $request): JsonResponse
    {
        $centreId = (int) $request->query('centre_id', 0);
        abort_unless($centreId, 400);
        $date = $request->query('date', Carbon::today()->toDateString());
        $start = Carbon::parse($date)->startOfDay();
        $end = $start->copy()->endOfDay();

        $visits = DB::table('zone_visits as zv')
            ->join('activity_zones as az', 'az.id', '=', 'zv.zone_id')
            ->join('children as ch', 'ch.id', '=', 'zv.child_id')
            ->where('az.centre_id', $centreId)
            ->whereBetween('zv.visited_at', [$start, $end])
            ->select('zv.*',
                'az.name as zone_name', 'az.icon', 'az.color',
                DB::raw("CONCAT(ch.first_name,' ',ch.last_name) as child_name"))
            ->orderBy('zv.visited_at')->get();

        $byZone = $visits->groupBy('zone_name')->map(fn ($g) => $g->count());
        $zTz = \App\Support\AgencyTime::tzForCentre($centreId);
        $byChild = $visits->groupBy('child_name')->map(fn ($g) => $g->map(fn ($v) => ['zone' => $v->zone_name, 'icon' => $v->icon, 'time' => \App\Support\AgencyTime::fmt($v->visited_at, $zTz)])->values());

        return response()->json([
            'date' => $date,
            'total_visits' => $visits->count(),
            'by_zone' => $byZone,
            'by_child' => $byChild,
            'visits' => $visits,
        ]);
    }
}
