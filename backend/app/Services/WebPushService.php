<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * v14: WebPushService — sends Web Push messages.
 *
 * MANUAL SETUP REQUIRED:
 *   1. cd ~/kiddietrac/backend && composer require minishlink/web-push
 *   2. Generate VAPID keys:
 *      php -r "require 'vendor/autoload.php'; \$k = Minishlink\WebPush\VAPID::createVAPIDKeys(); echo 'VAPID_PUBLIC_KEY=' . \$k['publicKey'] . PHP_EOL . 'VAPID_PRIVATE_KEY=' . \$k['privateKey'] . PHP_EOL;"
 *   3. Append both lines to .env, plus VAPID_SUBJECT=mailto:you@example.com
 *   4. php artisan config:clear
 *
 * Until those steps are done, isConfigured() returns false and
 * push delivery is silently skipped (notifications still get
 * created in the DB and emails still get sent).
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
     * @param array $payload  { title, body, icon?, badge?, url?, tag? }
     * @return int  Number of pushes attempted
     */
    public function sendToUser(int $userId, array $payload): int
    {
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
        if (! $this->isConfigured() || empty($userIds)) return 0;

        $tokens = DB::table('device_tokens')
            ->whereIn('user_id', $userIds)
            ->where('platform', 'web')
            ->pluck('token', 'id')
            ->all();

        if (empty($tokens)) return 0;

        return $this->sendBatch($tokens, $payload);
    }

    private function sendBatch(array $tokensById, array $payload): int
    {
        $auth = [
            'VAPID' => [
                'subject' => env('VAPID_SUBJECT', 'mailto:noreply@kiddietrac.com'),
                'publicKey' => env('VAPID_PUBLIC_KEY'),
                'privateKey' => env('VAPID_PRIVATE_KEY'),
            ],
        ];

        try {
            $webPushClass = '\Minishlink\WebPush\WebPush';
            $subClass = '\Minishlink\WebPush\Subscription';
            $webPush = new $webPushClass($auth);

            $sentCount = 0;
            $invalidIds = [];

            foreach ($tokensById as $id => $tokenJson) {
                $sub = json_decode($tokenJson, true);
                if (! is_array($sub) || empty($sub['endpoint'])) {
                    $invalidIds[] = $id;
                    continue;
                }

                try {
                    $subscription = $subClass::create($sub);
                    $webPush->queueNotification($subscription, json_encode($payload));
                    $sentCount++;
                } catch (\Throwable $e) {
                    Log::warning('Push queue failed', ['token_id' => $id, 'error' => $e->getMessage()]);
                    $invalidIds[] = $id;
                }
            }

            foreach ($webPush->flush() as $report) {
                if (! $report->isSuccess()) {
                    $endpoint = $report->getRequest()->getUri()->__toString();
                    Log::info('Push delivery failed', [
                        'endpoint' => substr($endpoint, 0, 80),
                        'reason' => $report->getReason(),
                    ]);
                    // 410 Gone — remove invalid subscriptions
                    if ($report->getResponse() && in_array($report->getResponse()->getStatusCode(), [404, 410])) {
                        foreach ($tokensById as $id => $tj) {
                            if (strpos($tj, $endpoint) !== false) $invalidIds[] = $id;
                        }
                    }
                }
            }

            if (!empty($invalidIds)) {
                DB::table('device_tokens')->whereIn('id', array_unique($invalidIds))->delete();
            }

            return $sentCount;
        } catch (\Throwable $e) {
            Log::error('WebPushService send failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }
}
