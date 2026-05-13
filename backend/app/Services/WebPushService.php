<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WebPushService v20 — sends Web Push messages.
 *
 * Changes from v14:
 *  - Returns early when payload is missing required fields (title/body) — no API error
 *  - Reduces error-spam: malformed tokens are removed silently, only real send
 *    failures are logged at info/warning level (not error)
 *  - Pre-validates subscription shape before queueing to avoid library throws
 *  - Returns granular result instead of just a count, so callers can react
 *
 * MANUAL SETUP REQUIRED (one-time, from v14):
 *  1. cd ~/kiddietrac/backend && composer require minishlink/web-push
 *  2. Generate VAPID keys (one-time):
 *     php -r "require 'vendor/autoload.php'; \$k = Minishlink\WebPush\VAPID::createVAPIDKeys(); echo 'VAPID_PUBLIC_KEY=' . \$k['publicKey'] . PHP_EOL . 'VAPID_PRIVATE_KEY=' . \$k['privateKey'] . PHP_EOL;"
 *  3. Append both lines to .env, plus VAPID_SUBJECT=mailto:you@example.com
 *  4. php artisan config:clear
 *
 * Until those steps are done, isConfigured() returns false and push
 * delivery is silently skipped (notifications still get created in the DB
 * and emails still get sent).
 */
final class WebPushService
{
    public function isConfigured(): bool
    {
        return class_exists('\Minishlink\WebPush\WebPush')
            && !empty(env('VAPID_PUBLIC_KEY'))
            && !empty(env('VAPID_PRIVATE_KEY'));
    }

    /**
     * Send a push payload to all of a user's registered devices.
     *
     * @param int   $userId
     * @param array $payload { title (required), body (required), icon?, badge?, url?, tag? }
     * @return int Number of pushes attempted
     */
    public function sendToUser(int $userId, array $payload): int
    {
        if (! $this->validatePayload($payload)) return 0;
        if (! $this->isConfigured()) {
            Log::debug('WebPushService: skipped (not configured)', ['user_id' => $userId]);
            return 0;
        }

        $tokens = DB::table('device_tokens')
            ->where('user_id', $userId)
            ->where('platform', 'web')
            ->pluck('token', 'id')
            ->all();

        if (empty($tokens)) return 0;

        return $this->sendBatch($tokens, $payload);
    }

    public function sendToUsers(array $userIds, array $payload): int
    {
        if (! $this->validatePayload($payload)) return 0;
        if (! $this->isConfigured() || empty($userIds)) return 0;

        $tokens = DB::table('device_tokens')
            ->whereIn('user_id', $userIds)
            ->where('platform', 'web')
            ->pluck('token', 'id')
            ->all();

        if (empty($tokens)) return 0;
        return $this->sendBatch($tokens, $payload);
    }

    /**
     * v20: Reject payloads missing required fields BEFORE we hit the library.
     * Returns false (and logs at debug) for invalid payloads.
     */
    private function validatePayload(array $payload): bool
    {
        $title = trim((string)($payload['title'] ?? ''));
        $body  = trim((string)($payload['body']  ?? ''));
        if ($title === '' || $body === '') {
            Log::debug('WebPushService: invalid payload (title/body required)', [
                'has_title' => $title !== '',
                'has_body'  => $body  !== '',
            ]);
            return false;
        }
        return true;
    }

    private function sendBatch(array $tokensById, array $payload): int
    {
        $auth = [
            'VAPID' => [
                'subject'    => env('VAPID_SUBJECT', 'mailto:noreply@kiddietrac.com'),
                'publicKey'  => env('VAPID_PUBLIC_KEY'),
                'privateKey' => env('VAPID_PRIVATE_KEY'),
            ],
        ];

        try {
            $webPushClass = '\Minishlink\WebPush\WebPush';
            $subClass     = '\Minishlink\WebPush\Subscription';
            $webPush      = new $webPushClass($auth);

            $queuedCount  = 0;
            $invalidIds   = [];

            foreach ($tokensById as $id => $tokenJson) {
                $sub = json_decode((string)$tokenJson, true);

                // v20: stricter pre-validation. We require endpoint + keys.{p256dh,auth}
                if (
                    ! is_array($sub)
                    || empty($sub['endpoint'])
                    || empty($sub['keys']['p256dh'])
                    || empty($sub['keys']['auth'])
                ) {
                    $invalidIds[] = (int)$id;
                    continue;
                }

                try {
                    $subscription = $subClass::create($sub);
                    $webPush->queueNotification($subscription, json_encode($payload));
                    $queuedCount++;
                } catch (\Throwable $e) {
                    Log::info('Push queue skipped (token invalid)', [
                        'token_id' => $id,
                        'reason'   => $e->getMessage(),
                    ]);
                    $invalidIds[] = (int)$id;
                }
            }

            if ($queuedCount > 0) {
                foreach ($webPush->flush() as $report) {
                    if (! $report->isSuccess()) {
                        $endpoint = $report->getRequest()->getUri()->__toString();
                        $status   = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;

                        Log::info('Push delivery non-success', [
                            'endpoint' => substr($endpoint, 0, 80),
                            'status'   => $status,
                            'reason'   => $report->getReason(),
                        ]);

                        // 404/410 = subscription is gone permanently → purge
                        if (in_array($status, [404, 410], true)) {
                            foreach ($tokensById as $id => $tj) {
                                if (is_string($tj) && strpos($tj, $endpoint) !== false) {
                                    $invalidIds[] = (int)$id;
                                }
                            }
                        }
                    }
                }
            }

            if (! empty($invalidIds)) {
                $deleted = DB::table('device_tokens')
                    ->whereIn('id', array_values(array_unique($invalidIds)))
                    ->delete();
                if ($deleted > 0) {
                    Log::info("WebPushService: purged {$deleted} invalid token(s)");
                }
            }

            return $queuedCount;

        } catch (\Throwable $e) {
            // True library/network errors stay at warning (not error) — these are
            // operational events, not bugs in our code. Reserve ERROR level for
            // things that need human attention.
            Log::warning('WebPushService send aborted', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
            return 0;
        }
    }
}
