<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p63 — Bluetooth thermometer reading at kiosk.
 * The browser reads from a paired BT thermometer via the Web Bluetooth
 * API and POSTs the value to this endpoint along with the child_id +
 * kiosk token. We record on check_events.temperature_c.
 */
final class KioskTemperatureController extends Controller
{
    public function record(Request $request, string $kioskToken): JsonResponse
    {
        $centre = DB::table('centres')->where('kiosk_token', $kioskToken)->first();
        abort_unless($centre && $centre->kiosk_enabled, 404, 'Kiosk not enabled');
        $data = $request->validate([
            'child_id' => 'required|integer',
            'temperature_c' => 'required|numeric|min:30|max:45',
            'method' => 'required|in:bluetooth,manual,scanner',
            'check_event_id' => 'nullable|integer',
        ]);
        // Verify child is at this centre
        $child = DB::table('children')->where('id', $data['child_id'])->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        $fam = DB::table('families')->where('id', $child->family_id)->first();
        abort_unless($fam && $fam->centre_id === (int) $centre->id, 403, 'Child not at this centre');

        // Update or insert a check_event row
        if (!empty($data['check_event_id'])) {
            DB::table('check_events')->where('id', $data['check_event_id'])->update([
                'temperature_c' => $data['temperature_c'],
                'temperature_method' => $data['method'],
            ]);
            $id = $data['check_event_id'];
        } else {
            // Create a temperature-only check_event with type 'screening'
            $id = DB::table('check_events')->insertGetId([
                'child_id' => $data['child_id'],
                'event_type' => 'temperature_screening',
                'occurred_at' => now(),
                'temperature_c' => $data['temperature_c'],
                'temperature_method' => $data['method'],
                'kiosk_source' => 1,
                'created_at' => now(),
            ]);
        }

        // If temperature is elevated (≥38°C), notify staff
        if ((float) $data['temperature_c'] >= 38) {
            $staff = DB::table('role_assignments')->where('centre_id', $centre->id)
                ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
                ->where('active', 1)->pluck('user_id')->unique();
            foreach ($staff as $sid) {
                DB::table('notifications')->insert([
                    'user_id' => $sid, 'type' => 'fever_alert',
                    'title' => '🌡 Elevated temperature: ' . $child->first_name,
                    'body' => number_format((float) $data['temperature_c'], 1) . '°C recorded via ' . $data['method'],
                    'data' => json_encode(['link' => '#today', 'child_id' => $child->id, 'check_event_id' => $id]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json([
            'id' => $id,
            'temperature_c' => $data['temperature_c'],
            'fever_alert' => (float) $data['temperature_c'] >= 38,
        ], 201);
    }
}
