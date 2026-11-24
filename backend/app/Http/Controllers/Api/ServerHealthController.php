<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CloudwaysApi;
use App\Support\PlatformSettings;
use App\Support\ServerHealth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/** Server health — super admin only (route group role:platform_admin). */
class ServerHealthController extends Controller
{
    /** GET /platform/server-health?hours=24 — the server this API runs on. */
    public function show(Request $request): JsonResponse
    {
        $hours = max(1, min(24 * ServerHealth::KEEP_DAYS, (int) $request->query('hours', 24)));

        return response()->json(ServerHealth::snapshot() + [
            'history_hours' => $hours,
            'history' => ServerHealth::history($hours),
            'cloudways_connected' => CloudwaysApi::connected(),
            // How fast the portal is on the devices people use it from (kt-net-quality.js).
            'network' => (function () use ($hours) { try { return \App\Support\NetQuality::summary(min($hours, 24 * 7)); } catch (\Throwable $e) { return null; } })(),
            // The global search's words, learned from use (App\Support\SearchKeywords, search-keys:health).
            'search_keys' => (function () { try { return \App\Support\SearchKeywords::health(); } catch (\Throwable $e) { return null; } })(),
        ]);
    }

    /** GET /platform/server-health/cloudways?duration=1 Day — the Cloudways server, through its API. */
    public function cloudways(Request $request): JsonResponse
    {
        $durations = ['1 Hour', '12 Hours', '1 Day', '7 Days', '1 Month'];
        $d = (string) $request->query('duration', '1 Day');
        if (! in_array($d, $durations, true)) {
            $d = '1 Day';
        }
        if ($request->boolean('fresh')) {
            Cache::forget('kt-cloudways:overview:' . md5($d));
        }

        return response()->json(CloudwaysApi::overview($d) + ['durations' => $durations]);
    }

    /**
     * PUT /platform/server-health/cloudways {token?, server_id?}
     * A new token is tried against Cloudways before it is kept; it is stored encrypted
     * and never sent back.
     */
    public function saveCloudways(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['nullable', 'string', 'min:20', 'max:4000'],
            'server_id' => ['nullable', 'string', 'max:40'],
        ]);
        $token = isset($data['token']) ? trim($data['token']) : null;
        if ($token) {
            $sv = CloudwaysApi::servers($token);
            if (! $sv['ok']) {
                return response()->json(['message' => in_array($sv['status'], [401, 403], true)
                    ? 'Cloudways did not accept that access token. Check it was copied in full and has not expired.'
                    : 'Cloudways did not answer just now, so the token could not be checked. Please try again in a moment.'], 422);
            }
            PlatformSettings::setSecret(CloudwaysApi::TOKEN_KEY, $token);
        }
        if (array_key_exists('server_id', $data)) {
            PlatformSettings::set(CloudwaysApi::SERVER_KEY, (string) ($data['server_id'] ?? ''));
        }
        $this->forgetCache();
        $this->audit($request, $token ? 'Connected Cloudways (access token saved)' : 'Changed the Cloudways server shown');

        return $this->cloudways($request);
    }

    /** DELETE /platform/server-health/cloudways — forget the token. */
    public function disconnectCloudways(Request $request): JsonResponse
    {
        PlatformSettings::set(CloudwaysApi::TOKEN_KEY, '');
        PlatformSettings::set(CloudwaysApi::SERVER_KEY, '');
        $this->forgetCache();
        $this->audit($request, 'Disconnected Cloudways (access token removed)');

        return response()->json(['connected' => false]);
    }

    private function forgetCache(): void
    {
        foreach (['1 Hour', '12 Hours', '1 Day', '7 Days', '1 Month'] as $d) {
            Cache::forget('kt-cloudways:overview:' . md5($d));
        }
    }

    private function audit(Request $request, string $summary): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id, 'agency_id' => null, 'action' => 'platform.cloudways',
                'entity_type' => 'platform', 'entity_id' => null,
                'payload' => json_encode(['summary' => $summary]), 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
