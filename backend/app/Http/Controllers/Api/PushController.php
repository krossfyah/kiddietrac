<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v14: Web Push subscription.
 *
 * Uses existing `device_tokens` table with platform='web'.
 * The `token` column stores the JSON-encoded PushSubscription
 * { endpoint, keys: { p256dh, auth } } from the browser.
 *
 * Actual push DELIVERY happens via App\Services\WebPushService.
 * That service requires `composer require minishlink/web-push`
 * AND VAPID keys in .env — both manual setup steps after deploy.
 */
final class PushController extends Controller
{
    /**
     * GET /api/v1/push/public-key
     * Returns the VAPID public key the browser needs to subscribe.
     */
    public function publicKey(): JsonResponse
    {
        $key = env('VAPID_PUBLIC_KEY');
        return response()->json([
            'public_key' => $key,
            'configured' => !empty($key),
        ]);
    }

    /**
     * POST /api/v1/push/subscribe
     * Browser sends the PushSubscription object after asking permission.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subscription' => ['required', 'array'],
            'subscription.endpoint' => ['required', 'url', 'max:500'],
            'subscription.keys' => ['required', 'array'],
            'subscription.keys.p256dh' => ['required', 'string'],
            'subscription.keys.auth' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();
        $endpoint = $data['subscription']['endpoint'];
        $tokenJson = json_encode($data['subscription']);

        // Truncate if longer than column allows (500 chars)
        if (strlen($tokenJson) > 500) {
            // Some endpoints are very long; store just the endpoint as the unique token
            // and a compact form in device_name
            $tokenJson = substr($tokenJson, 0, 500);
        }

        $existing = DB::table('device_tokens')->where('token', $tokenJson)->first();
        if ($existing) {
            DB::table('device_tokens')->where('id', $existing->id)->update([
                'user_id' => $user->id,
                'last_active_at' => now(),
            ]);
            return response()->json(['success' => true, 'token_id' => $existing->id, 'updated' => true]);
        }

        try {
            $tokenId = DB::table('device_tokens')->insertGetId([
                'user_id' => $user->id,
                'token' => $tokenJson,
                'platform' => 'web',
                'device_name' => $data['device_name'] ?? $request->header('User-Agent'),
                'last_active_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Push subscribe failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not save subscription'], 500);
        }

        return response()->json(['success' => true, 'token_id' => $tokenId]);
    }

    /**
     * POST /api/v1/push/device
     * Native (Capacitor / FCM) registration — stores the FCM registration token
     * in device_tokens (platform=android/ios) so the backend can push to the app
     * even when it's fully closed. Called by kt-native-push.js after register().
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'max:500'],
            'platform' => ['nullable', 'string', 'max:20'],
        ]);
        $user = $request->user();
        $platform = in_array($data['platform'] ?? '', ['android', 'ios'], true) ? $data['platform'] : 'android';

        $existing = DB::table('device_tokens')->where('token', $data['token'])->first();
        if ($existing) {
            DB::table('device_tokens')->where('id', $existing->id)->update([
                'user_id' => $user->id, 'platform' => $platform, 'last_active_at' => now(),
            ]);
            return response()->json(['success' => true, 'token_id' => $existing->id, 'updated' => true]);
        }
        try {
            $tokenId = DB::table('device_tokens')->insertGetId([
                'user_id' => $user->id,
                'token' => $data['token'],
                'platform' => $platform,
                'device_name' => substr((string) $request->header('User-Agent'), 0, 120),
                'last_active_at' => now(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Device register failed', ['error' => $e->getMessage()]);
            return response()->json(['message' => 'Could not save device'], 500);
        }
        return response()->json(['success' => true, 'token_id' => $tokenId]);
    }

    /**
     * POST /api/v1/push/test-fcm
     * Sends a test FCM push to the current user's native devices — use this from
     * the phone after the rebuild to confirm sound + vibration.
     */
    public function testFcm(Request $request): JsonResponse
    {
        $user = $request->user();
        $svc = app(\App\Services\FcmService::class);
        if (!$svc->configured()) {
            return response()->json(['message' => 'FCM not configured on the server.'], 503);
        }
        // A SELF-test bypasses agency suppression — it's the user checking their own
        // device, not an automated send to a live agency's families. Send straight
        // to the user's app tokens.
        $tokens = \Illuminate\Support\Facades\DB::table('device_tokens')
            ->where('user_id', $user->id)->whereIn('platform', ['android', 'ios'])
            ->pluck('token')->all();
        if (!$tokens) {
            return response()->json([
                'sent' => 0, 'failed' => 0, 'no_device' => true,
                'message' => 'No app device is registered yet. Open the app on your phone and allow notifications when prompted, then try again.',
            ]);
        }
        $result = $svc->sendToTokens(
            $tokens,
            'KiddieTrac test 🔔',
            'If your phone buzzed and made a sound, push is working!',
            ['link' => '#home'],
            false
        );
        return response()->json($result);
    }

    /**
     * POST /api/v1/push/unsubscribe
     */
    public function unsubscribe(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['nullable', 'string'],
        ]);
        $user = $request->user();

        $q = DB::table('device_tokens')->where('user_id', $user->id)->where('platform', 'web');
        if (!empty($data['endpoint'])) {
            $q->where('token', 'like', '%' . $data['endpoint'] . '%');
        }
        $count = $q->delete();

        return response()->json(['success' => true, 'removed' => $count]);
    }

    /**
     * POST /api/v1/push/test
     * Send a test push to the current user's devices. Only works if VAPID keys
     * are configured AND minishlink/web-push is installed.
     */
    public function test(Request $request): JsonResponse
    {
        $user = $request->user();
        $service = app(\App\Services\WebPushService::class);

        if (! $service->isConfigured()) {
            return response()->json([
                'sent' => 0,
                'message' => 'Push not configured — run: composer require minishlink/web-push, then set VAPID_PUBLIC_KEY and VAPID_PRIVATE_KEY in .env',
            ], 503);
        }

        $sent = $service->sendToUser($user->id, [
            'title' => '🔔 Test from Kiddietrac',
            'body' => 'Push notifications are working!',
            'icon' => '/icon-192.png',
            'url' => '/dashboard.html',
        ]);

        return response()->json(['sent' => $sent]);
    }
}
