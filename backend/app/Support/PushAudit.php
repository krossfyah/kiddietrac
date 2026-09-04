<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Every push notification, in the audit trail.
 *
 * Email has been audited for a long time (`email.sent`, and since 2026-08-25 the bounces
 * too). Push was not audited at all — so the one channel that reaches a parent's lock
 * screen was the one channel with no record that it had ever been sent. When somebody
 * says "I was never told my child was checked out", email could be answered from the log
 * and push could only be guessed at.
 *
 * Recorded at the three public doors — FcmService::sendToUser, WebPushService::sendToUser
 * and ::sendToUsers — rather than at the transport, because those are where the RECIPIENT
 * is still known. By the time a message reaches the token layer it is an anonymous string.
 *
 * Auditing must never cost a delivery: every call is wrapped, and a failure here is
 * swallowed. A push that went out but was not logged is bad; a push that never went out
 * because the logging broke is worse.
 */
final class PushAudit
{
    /** Cheap per-request memo — a broadcast hits the same agency dozens of times. */
    private static array $agencyCache = [];

    /**
     * @param  string  $channel  'fcm' | 'webpush'
     * @param  string  $outcome  'sent' | 'no_device' | 'suppressed' | 'failed' | 'not_configured'
     */
    public static function record(
        string $channel,
        array $userIds,
        string $title,
        string $body,
        string $outcome,
        array $extra = []
    ): void {
        try {
            $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
            if (! $userIds) {
                return;
            }

            $rows = [];
            $now = now();
            foreach ($userIds as $uid) {
                $rows[] = [
                    'user_id' => null,          // system-sent; the audit screen renders this as "System"
                    'agency_id' => self::agencyOf($uid),
                    'action' => 'push.'.$outcome,
                    'entity_type' => 'user',
                    'entity_id' => $uid,
                    'payload' => json_encode(array_merge([
                        'channel' => $channel,
                        'to_user' => $uid,
                        // Trimmed: the audit log is a record that it was sent and what it
                        // said, not a second copy of the message.
                        'title' => mb_substr($title, 0, 160),
                        'body' => mb_substr($body, 0, 240),
                    ], $extra)),
                    'created_at' => $now,
                ];
            }

            // One insert for a broadcast rather than one per recipient.
            foreach (array_chunk($rows, 200) as $chunk) {
                \App\Support\Audit::write($chunk);
            }
        } catch (\Throwable $e) {
            // Deliberately silent — see the class note.
        }
    }

    /**
     * The agency this notification belongs to, so the audit screen can scope it like every
     * other row. Staff carry it on their role assignment; a guardian only reaches it
     * through their family's centre.
     */
    private static function agencyOf(int $userId): ?int
    {
        if (array_key_exists($userId, self::$agencyCache)) {
            return self::$agencyCache[$userId];
        }

        $agencyId = null;
        try {
            $agencyId = DB::table('role_assignments')->where('user_id', $userId)
                ->where('active', true)->whereNotNull('agency_id')->value('agency_id');

            if (! $agencyId) {
                $centreId = DB::table('role_assignments')->where('user_id', $userId)
                    ->where('active', true)->whereNotNull('centre_id')->value('centre_id');
                if (! $centreId) {
                    $centreId = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                        ->where('g.user_id', $userId)->value('f.centre_id');
                }
                if ($centreId) {
                    $agencyId = DB::table('centres')->where('id', $centreId)->value('agency_id');
                }
            }
        } catch (\Throwable $e) {
            $agencyId = null;
        }

        return self::$agencyCache[$userId] = $agencyId ? (int) $agencyId : null;
    }
}
