<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Email delivery control panel (2026-08-05).
 *
 * The pre-boarding switchboard. Lets an agency admin enable/disable outgoing
 * email at three levels, so a new agency can be set up quietly and switched on
 * one centre / room at a time:
 *
 *   agency master  (agencies.settings.notifications_enabled — managed elsewhere)
 *     └─ centre    (centres.settings.email_enabled)
 *          └─ room (rooms.email_enabled)
 *
 * The ENFORCEMENT lives in App\Support\Suppression + the SuppressAgencyMail
 * mail-layer listener — this controller only reads/writes the flags. Default is
 * ON at every level, so nothing changes for a live agency until it opts a
 * centre / room OUT.
 */
class EmailDeliveryController extends Controller
{
    use ResolvesCentreContext;

    /** Full tree: agency master + every centre with its rooms and their flags. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'No active agency.'], 422);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first(['name', 'settings']);
        $aset = $agency && $agency->settings ? (json_decode((string) $agency->settings, true) ?: []) : [];
        $masterOn = ($aset['notifications_enabled'] ?? true) !== false;

        $centres = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get(['id', 'name', 'settings']);

        $centreIds = $centres->pluck('id')->all();
        $rooms = $centreIds
            ? DB::table('rooms')->whereIn('centre_id', $centreIds)->orderBy('name')
                ->get(['id', 'centre_id', 'name', 'email_enabled', 'active'])
            : collect();

        $tree = $centres->map(function ($c) use ($rooms) {
            $cset = $c->settings ? (json_decode((string) $c->settings, true) ?: []) : [];

            return [
                'id' => (int) $c->id,
                'name' => $c->name,
                'email_enabled' => ($cset['email_enabled'] ?? true) !== false,
                'rooms' => $rooms->where('centre_id', $c->id)->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => $r->name,
                    'email_enabled' => (int) ($r->email_enabled ?? 1) !== 0,
                    'active' => (int) ($r->active ?? 1) !== 0,
                ])->values(),
            ];
        })->values();

        return response()->json([
            'agency_name' => $agency->name ?? '',
            'master_enabled' => $masterOn,
            'centres' => $tree,
        ]);
    }

    /** Flip a centre's email switch (stored in centres.settings). */
    public function setCentre(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centre = DB::table('centres')
            ->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->first(['id', 'settings']);
        if (! $centre) {
            return response()->json(['message' => 'Centre not found'], 404);
        }

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        $settings = $centre->settings ? (json_decode((string) $centre->settings, true) ?: []) : [];
        $settings['email_enabled'] = $enabled;
        DB::table('centres')->where('id', $centreId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);
        Cache::forget('kt.centre_email:' . $centreId);

        return response()->json(['message' => 'Saved', 'email_enabled' => $enabled]);
    }

    /** Flip a room's email switch (rooms.email_enabled column). */
    public function setRoom(Request $request, int $roomId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $room = DB::table('rooms as r')
            ->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('r.id', $roomId)->where('c.agency_id', $agencyId)->whereNull('c.deleted_at')
            ->select('r.id')->first();
        if (! $room) {
            return response()->json(['message' => 'Room not found'], 404);
        }

        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        DB::table('rooms')->where('id', $roomId)->update([
            'email_enabled' => $enabled,
            'updated_at' => now(),
        ]);
        Cache::forget('kt.room_email:' . $roomId);

        return response()->json(['message' => 'Saved', 'email_enabled' => $enabled]);
    }
}
