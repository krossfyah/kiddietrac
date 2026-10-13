<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * EMERGENCY MESSAGES (2026-10-13, Anthony: "for messenger to admin and to educators/parents
 * and other staff add a button to flag as emergency otherwise treat the messenger as normal.
 * Emergency will continuously ring/vibrate").
 *
 * One flag on a message — messages.is_emergency (family threads) or
 * staff_messages.is_emergency (colleague threads) — decides how loudly it arrives:
 *
 *  - normal:    an ordinary notification (one sound) for everybody. Until today every chat
 *               message was pushed as urgent, so educators' phones rang insistently for
 *               "thanks!" and a parent's screen was taken over by a photo caption.
 *  - emergency: the insistent treatment for EVERY recipient, parents included — the phone
 *               rings and vibrates until the message is opened, for at most RING_SECONDS,
 *               then it is left as a quiet "missed emergency" notification (Anthony chose
 *               "opened or 2 minutes"). kt_ring_secs carries the limit to the app
 *               (KtMessagingService, APK 54+); older APKs ring until opened.
 *
 * Anyone who can write in a thread can flag a message (staff and parents — Anthony's
 * choice). Each one is audited as message.emergency so misuse is visible.
 */
class EmergencyMessage
{
    public const RING_SECONDS = 120;

    public static function requested(Request $request): bool
    {
        return $request->boolean('emergency');
    }

    /** Extra FCM data for an emergency push. */
    public static function pushExtra(): array
    {
        return ['kt_emergency' => '1', 'kt_ring_secs' => (string) self::RING_SECONDS];
    }

    public static function title(string $who): string
    {
        return '🚨 EMERGENCY · ' . $who;
    }

    /** A web-push payload made loud: stays on screen until dealt with, long vibration. */
    public static function web(array $payload): array
    {
        return array_merge($payload, ['emergency' => 1, 'requireInteraction' => true]);
    }

    public static function audit(int $senderId, ?int $agencyId, string $kind, int $threadId, int $messageId, array $recipients): void
    {
        try {
            Audit::write([
                'user_id' => $senderId,
                'agency_id' => $agencyId,
                'action' => 'message.emergency',
                'entity_type' => $kind,
                'entity_id' => $threadId,
                'payload' => json_encode([
                    'message_id' => $messageId,
                    'recipients' => count($recipients),
                    'summary' => 'Sent a message flagged as an EMERGENCY to ' . count($recipients) . ' ' . (count($recipients) === 1 ? 'person' : 'people') . '.',
                ]),
                'ip_address' => request()->ip(),
                'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
