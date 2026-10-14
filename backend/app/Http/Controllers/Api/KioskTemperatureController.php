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

        /* A TEMPERATURE GOES ON THE CHILD'S OWN SIGN-IN TODAY (2026-10-14, support ticket #144).
           This used to update ANY check_events row by the id it was given — any child, any
           agency, for anyone holding a kiosk token — and, with no id, to insert a
           'temperature_screening' event that could never save (room_id, by_user_id and
           recorded_by_id are required) and that the roster would have read as the child's
           latest event. Now: the given event must be this child's, or the reading goes on
           the child's latest sign-in today; with no sign-in yet, the kiosk is told to sign the
           child in first. */
        [$today] = \App\Support\AgencyTime::dayRange((int) $centre->agency_id, \App\Support\AgencyTime::today((int) $centre->agency_id));
        $ev = DB::table('check_events')->where('child_id', $child->id)->where('event_type', 'check_in')
            ->when(! empty($data['check_event_id']), fn ($q) => $q->where('id', (int) $data['check_event_id']),
                   fn ($q) => $q->where('occurred_at', '>=', $today)->orderByDesc('occurred_at'))
            ->first(['id']);
        if (! $ev) {
            return response()->json(['message' => 'Sign the child in first, then record their temperature.'], 422);
        }
        DB::table('check_events')->where('id', $ev->id)->update([
            'temperature_c' => $data['temperature_c'],
            'temperature_method' => $data['method'],
        ]);
        $id = $ev->id;

        // If temperature is elevated (≥38°C), notify staff
        if ((float) $data['temperature_c'] >= 38) {
            $staff = DB::table('role_assignments')->where('centre_id', $centre->id)
                ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
                ->where('active', 1)->pluck('user_id')->unique();
            foreach ($staff as $sid) {
                \App\Support\Notify::write([
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
