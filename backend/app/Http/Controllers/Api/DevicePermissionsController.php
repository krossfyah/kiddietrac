<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\DevicePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /me/device-permissions (2026-10-31, Anthony: "and the type of permissions set to use the KiddieTrac app").
 * The app (kt-device-perms.js) reports what this phone or browser lets KiddieTrac do: notifications, location,
 * camera, microphone, photos, biometric unlock. Stored on the caller's OWN device_tokens row (perms JSON +
 * perms_checked_at) for the staff readiness list. Only statuses are stored, nothing personal.
 *
 * 2026-11-02: the native app also sends install_id (+ app_build), and the report is ALWAYS kept for it in
 * device_presence (App\Support\DevicePresence), push token or not. Before, a phone that had not allowed notifications
 * had no device_tokens row, the report was answered 202 no_device and the phone stayed invisible (Sukhman's iPhone).
 */
class DevicePermissionsController extends Controller
{
    private const KEYS = ['notifications', 'location', 'location_bg', 'camera', 'microphone', 'photos', 'biometric', 'biometric_enrolled'];
    private const VALUES = ['granted', 'limited', 'denied', 'prompt', 'unknown', 'unavailable', 'yes', 'no'];

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'platform' => 'required|in:android,ios,web',
            'fcm_token' => 'nullable|string|max:400',
            'perms' => 'required|array',
            'source' => 'nullable|string|max:40',
            'install_id' => 'nullable|string|max:64',
            'app_build' => 'nullable|integer|min:0|max:65535',
        ]);
        $perms = [];
        foreach (self::KEYS as $k) {
            $v = $d['perms'][$k] ?? null;
            if (is_string($v) && in_array($v, self::VALUES, true)) $perms[$k] = $v;
        }
        $perms['_source'] = mb_substr((string) ($d['source'] ?? ''), 0, 40);
        $uid = (int) $request->user()->id;
        $ua = mb_substr((string) $request->header('User-Agent'), 0, 120);
        $q = DB::table('device_tokens')->where('user_id', $uid);
        $row = null;
        if (! empty($d['fcm_token']) && $d['platform'] !== 'web') {
            $row = (clone $q)->where('token', $d['fcm_token'])->first(['id']);
        }
        if (! $row) {
            $row = (clone $q)->where('platform', $d['platform'])->where('device_name', $ua)->orderByDesc('last_active_at')->first(['id']);
            // The app's own WebView is the only one on that phone, so its newest row is this phone. A browser that isn't
            // registered for notifications has no row of its own: store nothing rather than label someone else's browser.
            // With an install id the app is identified exactly, so no guessing at "the newest row" (it could be another phone).
            if (! $row && $d['platform'] !== 'web' && empty($d['install_id'])) {
                $row = (clone $q)->where('platform', $d['platform'])->orderByDesc('last_active_at')->first(['id']);
            }
        }
        if ($row) {
            DB::table('device_tokens')->where('id', $row->id)->update(['perms' => json_encode($perms), 'perms_checked_at' => now()]);
        }
        $presence = false;
        if ($d['platform'] !== 'web' && ! empty($d['install_id'])) {
            DevicePresence::record($uid, (string) $d['install_id'], $d['platform'], isset($d['app_build']) ? (int) $d['app_build'] : null,
                $ua, $perms, (bool) $row);
            $presence = true;
        }
        if (! $row && ! $presence) return response()->json(['stored' => false, 'reason' => 'no_device'], 202);
        return response()->json(['stored' => true, 'push' => (bool) $row]);
    }
}
