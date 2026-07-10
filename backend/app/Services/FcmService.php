<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) sender — pure PHP, no composer package.
 * Signs a JWT with the service-account key, exchanges it for an OAuth token
 * (cached ~55 min), and POSTs to the FCM v1 endpoint. Delivers to a closed
 * Android app with sound + vibration via the `kt_default` notification channel.
 * Credentials: env FCM_CREDENTIALS (path to service-account json) + FCM_PROJECT_ID.
 */
class FcmService
{
    private ?array $sa = null;

    private function sa(): ?array
    {
        if ($this->sa !== null) return $this->sa ?: null;
        $path = env('FCM_CREDENTIALS');
        if (!$path || !is_file($path)) { $this->sa = []; return null; }
        $this->sa = json_decode((string) file_get_contents($path), true) ?: [];
        return $this->sa ?: null;
    }

    public function configured(): bool { return $this->sa() !== null; }

    private function accessToken(): ?string
    {
        $sa = $this->sa();
        if (!$sa || empty($sa['client_email']) || empty($sa['private_key'])) return null;

        return Cache::remember('fcm_access_token', 3300, function () use ($sa) {
            $b64 = fn ($x) => rtrim(strtr(base64_encode($x), '+/', '-_'), '=');
            $now = time();
            $header = $b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
            $claim = $b64(json_encode([
                'iss' => $sa['client_email'],
                'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now, 'exp' => $now + 3600,
            ]));
            $input = $header . '.' . $claim;
            $sig = '';
            if (!openssl_sign($input, $sig, $sa['private_key'], 'sha256WithRSAEncryption')) return null;
            $jwt = $input . '.' . $b64($sig);

            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_POSTFIELDS => http_build_query([
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $jwt,
                ]),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $j = json_decode((string) $resp, true);
            if ($code !== 200 || empty($j['access_token'])) {
                Log::warning('FCM token exchange failed', ['code' => $code, 'resp' => substr((string) $resp, 0, 200)]);
                return null;
            }
            return $j['access_token'];
        });
    }

    /** Send to explicit device tokens. Returns ['sent'=>int,'failed'=>int,'error'=>?]. */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (!$tokens) return ['sent' => 0, 'failed' => 0];
        $token = $this->accessToken();
        if (!$token) return ['sent' => 0, 'failed' => count($tokens), 'error' => 'no_access_token'];

        $sa = $this->sa();
        $project = env('FCM_PROJECT_ID') ?: ($sa['project_id'] ?? null);
        if (!$project) return ['sent' => 0, 'failed' => count($tokens), 'error' => 'no_project'];
        $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

        $strData = [];
        foreach ($data as $k => $v) $strData[(string) $k] = (string) $v;

        $sent = 0; $failed = 0;
        foreach ($tokens as $t) {
            $payload = ['message' => [
                'token' => $t,
                'notification' => ['title' => $title, 'body' => $body],
                'data' => $strData,
                'android' => [
                    'priority' => 'high',
                    // kt_alerts is created NATIVELY in MainActivity (HIGH importance +
                    // loud custom sound), so it is guaranteed to exist on the new APK.
                    'notification' => ['channel_id' => 'kt_alerts', 'sound' => 'kt_notify', 'default_vibrate_timings' => true],
                ],
            ]];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
                CURLOPT_POSTFIELDS => json_encode($payload),
            ]);
            $resp = curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $sent++;
            } else {
                $failed++;
                // FCM 404 = token no longer valid → prune it.
                if ($code === 404) DB::table('device_tokens')->where('token', $t)->delete();
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /** Send to every native device a user has registered. */
    public function sendToUser(int $userId, string $title, string $body, string $link = ''): array
    {
        $tokens = DB::table('device_tokens')->where('user_id', $userId)
            ->whereIn('platform', ['android', 'ios'])->pluck('token')->all();
        return $this->sendToTokens($tokens, $title, $body, $link !== '' ? ['link' => $link] : []);
    }
}
