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
        $path = config('services.fcm.credentials') ?: env('FCM_CREDENTIALS');
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
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                /* 15s was the cost of ONE push, and these are sent in a LOOP inside the
                   request - one per guardian, then one per staff member. Three that hung
                   made POST /parent/absences take 46s while a parent watched a spinner to
                   report their child off sick. FCM answers in well under a second when it
                   is healthy, so 5s is generous and 2s to connect is plenty; past that the
                   push is not worth the person's time. Failing a notification is recoverable,
                   blocking the action that caused it is not. */
                CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
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
    public function sendToTokens(array $tokens, string $title, string $body, array $data = [], bool $urgent = false): array
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if (!$tokens) return ['sent' => 0, 'failed' => 0];
        $token = $this->accessToken();
        if (!$token) return ['sent' => 0, 'failed' => count($tokens), 'error' => 'no_access_token'];

        $sa = $this->sa();
        $project = config('services.fcm.project_id') ?: env('FCM_PROJECT_ID') ?: ($sa['project_id'] ?? null);
        if (!$project) return ['sent' => 0, 'failed' => count($tokens), 'error' => 'no_project'];
        $url = "https://fcm.googleapis.com/v1/projects/{$project}/messages:send";

        $strData = [];
        foreach ($data as $k => $v) $strData[(string) $k] = (string) $v;

        $sent = 0; $failed = 0;
        foreach ($tokens as $t) {
            if ($urgent) {
                // DATA-ONLY on purpose. A message carrying a 'notification' block is
                // rendered by Android itself when the app is backgrounded, and app
                // code never runs — so nothing can set FLAG_INSISTENT and the chime
                // would play exactly once. Data-only always reaches
                // KtMessagingService.onMessageReceived, which builds the insistent
                // notification (repeating sound + long vibration) on kt_urgent_v1.
                // The title/body therefore have to travel inside data.
                /* kt_fullscreen is a SEPARATE flag from kt_urgent on purpose.
                   kt_urgent already means "build the insistent notification". Taking over
                   a locked screen is a bigger claim, it needs a permission the user can
                   revoke, and Android 14 downgrades it to a heads-up when that permission
                   is missing. Keeping it as its own flag means the behaviour can be turned
                   off from the server without shipping a new APK — and an older handset
                   that does not know the key simply ignores it. See
                   docs/ANDROID-FULLSCREEN-INTENT.md. (Anthony, 2026-09-09) */
                $payload = ['message' => [
                    'token' => $t,
                    'data' => $strData + [
                        'title' => $title,
                        'body' => $body,
                        'kt_urgent' => '1',
                        'kt_fullscreen' => '1',
                    ],
                    'android' => ['priority' => 'high'],
                    // iOS has no FLAG_INSISTENT and will NOT display a data-only push
                    // at all (it is a silent background wake, and throttled). So an
                    // iPhone must be sent a real APNs alert. The closest thing to the
                    // Android "won't let you ignore it" behaviour is a time-sensitive
                    // interruption level, which breaks through Focus/Do Not Disturb.
                    // A repeating sound is only possible with Apple's Critical Alerts
                    // entitlement (request-only) — see IOS-BUILD-RUNBOOK.md.
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                            'apns-push-type' => 'alert',
                        ],
                        'payload' => [
                            'aps' => [
                                'alert' => ['title' => $title, 'body' => $body],
                                'sound' => 'kt_notify.wav',
                                'interruption-level' => 'time-sensitive',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ]];
            } else {
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
                    // Calm alerts for parents (invoices, photos): normal priority, the
                    // custom chime, no Focus override.
                    'apns' => [
                        'headers' => [
                            'apns-priority' => '10',
                            'apns-push-type' => 'alert',
                        ],
                        'payload' => [
                            'aps' => [
                                'sound' => 'kt_notify.wav',
                                'badge' => 1,
                            ],
                        ],
                    ],
                ]];
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                /* 15s was the cost of ONE push, and these are sent in a LOOP inside the
                   request - one per guardian, then one per staff member. Three that hung
                   made POST /parent/absences take 46s while a parent watched a spinner to
                   report their child off sick. FCM answers in well under a second when it
                   is healthy, so 5s is generous and 2s to connect is plenty; past that the
                   push is not worth the person's time. Failing a notification is recoverable,
                   blocking the action that caused it is not. */
                CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 2,
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

    /**
     * Send to every native device a user has registered.
     *
     * $urgent asks for the "can't be missed" treatment — the sound repeats and
     * the phone buzzes long and hard until the notification is acknowledged
     * (Android FLAG_INSISTENT, set by KtMessagingService in the APK). It is
     * applied ONLY to staff: the same code path pushes invoices and photos to
     * parents, and nobody wants their phone screaming about a nap photo.
     */
    /**
     * @param bool $webFallback When this user has no android/ios handset, deliver the
     *        same message by WEB push instead of dropping it.
     *
     *        This method queries device_tokens WHERE platform IN ('android','ios') — it
     *        cannot reach a web subscriber, by design. Measured 2026-08-26: every one of
     *        the guardians with a registered token has a `web` one, so ~11 callers
     *        (absences, invoices, photo feed, report cards, walks, withdrawals, check-in
     *        reminders…) were sending pushes that always found no device and did nothing
     *        else. Rather than patch each of them, the fallback lives here.
     *
     *        Pass FALSE from the three callers that already send web push themselves
     *        (ChatController, MessageController, CheckEventNotifier) — they build a
     *        richer payload, and a second send here would double the notification.
     */
    public function sendToUser(int $userId, string $title, string $body, string $link = '', bool $urgent = false, bool $forceUrgent = false, bool $webFallback = true): array
    {
        // Do-not-contact: a user at a suppressed (live) agency gets NOTHING while we
        // are testing. The email kill-switch didn't cover push, which is how 16
        // real iLearn parents received a phone notification on 2026-07-14.
        if (\App\Support\Suppression::isUser($userId)) {
            \App\Support\Suppression::note('push', $userId, $title);
            // Audited even though nothing was sent: "we deliberately did not tell them"
            // is exactly the fact somebody will need later.
            \App\Support\PushAudit::record('fcm', [$userId], $title, $body, 'suppressed');

            return ['sent' => 0, 'failed' => 0, 'suppressed' => true];
        }

        $tokens = DB::table('device_tokens')->where('user_id', $userId)
            ->whereIn('platform', ['android', 'ios'])->pluck('token')->all();

        // Urgent (full-screen takeover) normally only for staff; chat passes
        // forceUrgent so the takeover reaches parents too when they get a message.
        $urgent = $urgent && ($forceUrgent || $this->isStaff($userId));

        $data = [];
        if ($link !== '') $data['link'] = $link;
        if ($urgent) $data['kt_urgent'] = '1';

        if (! $tokens) {
            // No registered handset. Worth recording: a parent who "never got a push"
            // usually never had the app, and this is the only place that shows it.
            \App\Support\PushAudit::record('fcm', [$userId], $title, $body, 'no_device', [
                'link' => $link ?: null, 'urgent' => $urgent,
            ]);

            // The message still matters; only the transport was wrong.
            if ($webFallback) {
                try {
                    $n = app(\App\Services\WebPushService::class)->sendToUser($userId, [
                        'title' => $title,
                        'body'  => $body,
                        'icon'  => '/icon-192.png',
                        'url'   => '/dashboard.html' . ($link ?: ''),
                    ]);
                    if ($n > 0) {
                        return ['sent' => $n, 'failed' => 0, 'via' => 'web'];
                    }
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('Web-push fallback failed', [
                        'user' => $userId, 'error' => $e->getMessage(),
                    ]);
                }
            }

            return ['sent' => 0, 'failed' => 0];
        }

        $result = $this->sendToTokens($tokens, $title, $body, $data, $urgent);

        \App\Support\PushAudit::record('fcm', [$userId], $title, $body,
            (($result['sent'] ?? 0) > 0 ? 'sent' : 'failed'), [
                'link' => $link ?: null,
                'urgent' => $urgent,
                'devices' => count($tokens),
                'sent' => $result['sent'] ?? 0,
                'failed' => $result['failed'] ?? 0,
            ]);

        return $result;
    }

    /**
     * Anybody on staff — which is not the same as "an educator or a director".
     *
     * This named two role strings, so an agency_admin, a platform_admin or a home visitor
     * was never treated as urgent and got the quiet notification instead. The identical
     * omission was in kt-urgent-alert.js's isStaffView(), which is why an admin also never
     * saw the in-app takeover. Both are the failure described in the role-guard sweep: ask
     * whether somebody is staff, never enumerate roles, because the list rots silently
     * every time a role is added. Guardians are excluded here deliberately — a parent gets
     * urgent treatment only when the caller passes forceUrgent, which chat does.
     * (Anthony, 2026-09-09)
     */
    private function isStaff(int $userId): bool
    {
        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin',
                               'platform_admin', 'home_visitor', 'auditor'])
            ->where('active', 1)
            ->exists();
    }
}
