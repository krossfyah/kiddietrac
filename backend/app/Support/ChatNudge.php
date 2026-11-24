<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 👋 NUDGE (2026-11-04). Anthony: "where is the nudge button on the chat system add this to next to the + and it
 * should shake the screen, mobile app and make a sound that catches someones attention and send app notification",
 * then "everyone, with a cooldown", "yes, break through" (quiet hours) and "lessen it to every 1 minute".
 *
 * One path for every kind of chat: a family conversation (staff → family, family → staff) and a private thread
 * (staff ↔ staff, staff ↔ parent). A nudge is
 *   - a small centred line in the thread ("👋 Sarah nudged you"), stored as a system message;
 *   - an URGENT push (FcmService: Android's insistent channel, iPhone time-sensitive = through Do Not Disturb) with
 *     its own tone category 'nudge' (NotificationSounds), unless the agency turned break-through off;
 *   - a bell row type 'nudge' (push => false: the push above is the ring) that the open portal reads through the
 *     /badges feed: kt-nudge.js shakes the screen, plays the nudge tone, vibrates and shows "Open".
 * One per conversation per sender per cooldown (default 60 s), so a nudge cannot become a siren.
 *
 * Settings → Notifications → 💬 Message Centre, stored in agencies.settings->chat_nudge (MessagingRulesController).
 */
final class ChatNudge
{
    public const KEY = 'chat_nudge';
    public const COOLDOWNS = [30, 60, 120, 300, 600, 1800, 3600];

    public static function defaults(): array
    {
        return ['enabled' => true, 'families' => true, 'cooldown' => 60, 'break_through' => true];
    }

    public static function clean(array $in): array
    {
        $out = self::defaults();
        foreach (['enabled', 'families', 'break_through'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = (bool) $in[$k];
        }
        if (isset($in['cooldown']) && in_array((int) $in['cooldown'], self::COOLDOWNS, true)) $out['cooldown'] = (int) $in['cooldown'];
        return $out;
    }

    public static function read(?int $agencyId): array
    {
        if (! $agencyId) return self::defaults();
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        return self::clean((isset($s[self::KEY]) && is_array($s[self::KEY])) ? $s[self::KEY] : []);
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = self::clean($next);
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        return $clean;
    }

    /** Nudge a family conversation. $fromFamily: the sender is a parent of it (the caller has checked access). */
    public static function family(int $conversationId, int $senderId, bool $fromFamily): JsonResponse
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        if (! $conv) return response()->json(['message' => 'This conversation is not there any more.'], 404);
        $agencyId = (int) DB::table('centres')->where('id', $conv->centre_id)->value('agency_id') ?: null;
        $recipients = \App\Support\ChatAudience::recipientIds($conv, $senderId);

        return self::send('family', $conversationId, $senderId, $fromFamily, $agencyId, $recipients, '#chat?c=' . $conversationId,
            function (string $line, $now) use ($conversationId, $senderId) {
                $id = DB::table('messages')->insertGetId([
                    'conversation_id' => $conversationId, 'sender_id' => $senderId, 'body' => $line,
                    'is_system' => 1, 'created_at' => $now, 'delivered_at' => $now,
                ]);
                DB::table('conversations')->where('id', $conversationId)->update(['last_message_at' => $now]);
                return $id;
            });
    }

    /** Nudge a private thread (colleagues, or a parent's 1:1 with staff). The caller has checked participation. */
    public static function thread(int $threadId, int $senderId, bool $fromFamily): JsonResponse
    {
        $others = DB::table('staff_thread_participants')->where('thread_id', $threadId)->where('user_id', '!=', $senderId)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $agencyId = \App\Support\MessagingRules::agencyFor($senderId);
        if (! $agencyId) {
            foreach ($others as $o) { if ($agencyId = \App\Support\MessagingRules::agencyFor($o)) break; }
        }

        return self::send('staff', $threadId, $senderId, $fromFamily, $agencyId ?: null, $others, '#chat?c=staff:' . $threadId,
            function (string $line, $now) use ($threadId, $senderId) {
                $id = DB::table('staff_messages')->insertGetId([
                    'thread_id' => $threadId, 'sender_id' => $senderId, 'body' => $line,
                    'is_system' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('staff_threads')->where('id', $threadId)->update(['last_message_at' => $now, 'updated_at' => $now]);
                return $id;
            });
    }

    private static function send(string $kind, int $id, int $senderId, bool $fromFamily, ?int $agencyId, array $recipients, string $link, callable $insert): JsonResponse
    {
        $set = self::read($agencyId);
        if (! $set['enabled']) {
            return response()->json(['message' => 'Nudges are turned off for your centre.'], 403);
        }
        if ($fromFamily && ! $set['families']) {
            return response()->json(['message' => 'Your centre does not take nudges from families. Send a message instead.'], 403);
        }
        // Not the people who blocked the sender (App\Support\UserBlocks); a nudge is never an emergency.
        $recipients = \App\Support\UserBlocks::keep($senderId, $recipients, false);
        if (! $recipients) {
            return response()->json(['message' => 'There is nobody in this conversation to nudge.'], 422);
        }

        // One nudge per conversation per sender per cooldown. The value is when it ends, so the answer can say how long.
        $cd = (int) $set['cooldown'];
        $key = 'kt_nudge:' . $kind . ':' . $id . ':' . $senderId;
        if (! Cache::add($key, time() + $cd, $cd)) {
            $left = max(1, (int) Cache::get($key, time() + $cd) - time());
            return response()->json([
                'message' => 'You just nudged. You can nudge again in ' . ($left >= 60 ? (int) ceil($left / 60) . ' min' : $left . ' s') . '.',
                'retry_after' => $left, 'cooldown' => $cd,
            ], 429);
        }

        $u = DB::table('users')->where('id', $senderId)->first(['first_name', 'last_name']);
        $first = trim((string) ($u->first_name ?? '')) ?: 'Someone';
        $full = trim($first . ' ' . (string) ($u->last_name ?? ''));
        $now = now();
        $line = '👋 ' . $first . ' sent a nudge';
        $msgId = (int) $insert($line, $now);

        $title = '👋 ' . $full . ' nudged you';
        $body = 'Tap to open the conversation.';
        $extra = ['kt_category' => 'nudge', 'kt_nudge' => '1', 'kt_ring_secs' => '6'];
        $urgent = (bool) $set['break_through'];
        foreach ($recipients as $rid) {
            // Web push for the browsers (requireInteraction keeps it up); separate try so one cannot stop the other.
            try {
                app(\App\Services\WebPushService::class)->sendToUsers([(int) $rid], [
                    'title' => $title, 'body' => $body, 'icon' => '/icon-192.png',
                    'url' => '/dashboard.html' . $link, 'tag' => 'nudge-' . $kind . '-' . $id,
                    'requireInteraction' => true, 'vibrate' => [300, 120, 300, 120, 600],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Nudge web push failed', ['user' => (int) $rid, 'e' => $e->getMessage()]);
            }
            try {
                // urgent + forceUrgent: parents get it too. false: web push sent above.
                app(\App\Services\FcmService::class)->sendToUser((int) $rid, $title, $body, $link, $urgent, $urgent, false, $extra);
            } catch (\Throwable $e) {
                Log::warning('Nudge FCM push failed', ['user' => (int) $rid, 'e' => $e->getMessage()]);
            }
        }
        $rows = [];
        foreach ($recipients as $rid) {
            $rows[] = [
                'user_id' => (int) $rid, 'type' => 'nudge', 'title' => $title, 'body' => $full . ' is waiting for you in a chat.',
                'data' => json_encode(['url' => '/dashboard.html' . $link, 'nudge' => 1, 'kt_category' => 'nudge',
                    $kind === 'family' ? 'conversation_id' : 'thread_id' => $id, 'message_id' => $msgId, 'from' => $first]),
                'created_at' => $now, 'push' => false,
            ];
        }
        \App\Support\Notify::write($rows);

        try {
            \App\Support\Audit::write(['user_id' => $senderId, 'agency_id' => $agencyId, 'action' => 'chat.nudge',
                'entity_type' => $kind === 'family' ? 'conversation' : 'staff_thread', 'entity_id' => $id,
                'payload' => json_encode(['summary' => $full . ' nudged ' . count($recipients) . ' ' . (count($recipients) === 1 ? 'person' : 'people'),
                    'message_id' => $msgId, 'recipients' => array_values($recipients)]), 'created_at' => $now]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'id' => $msgId, 'body' => $line, 'is_system' => true,
            'notified' => count($recipients), 'cooldown' => $cd, 'created_at' => $now->toDateTimeString()]);
    }
}
