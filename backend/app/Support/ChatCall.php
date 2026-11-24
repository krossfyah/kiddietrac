<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 📞 VOICE CALLS FROM A CHAT (2026-11-04). Anthony: "can we wire up voice calls through the chat", "inside the app
 * only", "staff and parents", then "it needs to be not through telynx where I need to pay, can you make this free".
 *
 * FREE: the two browsers talk to each other directly (WebRTC, what video-call websites use). This server only
 * introduces them - it carries the two "here is how to reach me" notes (the SDP offer and answer) and keeps the
 * call's state. The audio never passes through KiddieTrac or any paid service.
 *
 *   1. The caller presses 📞 (POST …/call). A chat_calls row is made and everyone else in the chat is RUNG: an urgent
 *      push (Android: the insistent ringing channel; iPhone: time-sensitive alert) and a bell row type 'call' that
 *      an open portal turns into a ringing card (kt-portal-phone.js).
 *   2. The caller's page posts its offer (POST /chat-calls/{id}/offer) and plays a ringback.
 *   3. The first person to press Answer claims the call (Cache::add) with their page's answer; everyone else's
 *      ringing stops (their card polls the status). The caller's page reads the answer and the two connect.
 *   4. Decline (everyone), the caller hanging up, or 45 s of nobody: the call ends, said in the thread.
 *
 * Getting through home routers uses free public STUN. A small share of networks (some mobile carriers, strict
 * office firewalls) need a relay (TURN): iceServers() adds Cloudflare's TURN (free up to 1,000 GB a month) once
 * CF_TURN_KEY_ID / CF_TURN_TOKEN are in .env. Without it those calls say "Couldn't connect" instead of hanging.
 *
 * The thread gets ONE line per call ("📞 Call · 2:14", "📞 Missed call from Sarah"), a system message like a nudge.
 * Not recorded. Settings → Notifications → 💬 Message Centre (agencies.settings->chat_calls), like nudges.
 */
final class ChatCall
{
    public const KEY = 'chat_calls';
    public const HOLD_SECS = 45;

    public static function defaults(): array
    {
        return ['enabled' => true, 'families' => true];
    }

    public static function clean(array $in): array
    {
        $out = self::defaults();
        foreach (['enabled', 'families'] as $k) {
            if (array_key_exists($k, $in)) $out[$k] = (bool) $in[$k];
        }
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

    public static function ensureTable(): void
    {
        if (Schema::hasTable('chat_calls')) return;
        Schema::create('chat_calls', function ($t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->nullable()->index();
            $t->string('kind', 16);                       // family | staff
            $t->unsignedBigInteger('ref_id');             // conversations.id | staff_threads.id
            $t->unsignedBigInteger('caller_id')->index();
            $t->json('recipients');
            $t->json('declined')->nullable();
            $t->string('status', 16)->default('ringing'); // ringing connecting answered ended missed declined canceled failed
            $t->longText('offer_sdp')->nullable();
            $t->longText('answer_sdp')->nullable();
            $t->unsignedBigInteger('answered_by')->nullable();
            $t->timestamp('answered_at')->nullable();
            $t->timestamp('ended_at')->nullable();
            $t->unsignedInteger('duration_seconds')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamps();
            $t->index(['kind', 'ref_id']);
            $t->index('status');
        });
    }

    // ── starting a call ───────────────────────────────────────────────────────────

    /** Call a family conversation. $fromFamily: the caller is a parent of it (the controller has checked access). */
    public static function family(int $conversationId, int $callerId, bool $fromFamily): JsonResponse
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        if (! $conv) return response()->json(['message' => 'This conversation is not there any more.'], 404);
        $agencyId = (int) DB::table('centres')->where('id', $conv->centre_id)->value('agency_id') ?: null;

        return self::start('family', $conversationId, $callerId, $fromFamily, $agencyId, ChatAudience::recipientIds($conv, $callerId));
    }

    /** Call a private thread (colleagues, or a parent's 1:1 with staff). The controller has checked participation. */
    public static function thread(int $threadId, int $callerId, bool $fromFamily): JsonResponse
    {
        $others = DB::table('staff_thread_participants')->where('thread_id', $threadId)->where('user_id', '!=', $callerId)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $agencyId = MessagingRules::agencyFor($callerId);
        if (! $agencyId) {
            foreach ($others as $o) { if ($agencyId = MessagingRules::agencyFor($o)) break; }
        }

        return self::start('staff', $threadId, $callerId, $fromFamily, $agencyId ?: null, $others);
    }

    private static function start(string $kind, int $refId, int $callerId, bool $fromFamily, ?int $agencyId, array $recipients): JsonResponse
    {
        self::ensureTable();
        $set = self::read($agencyId);
        if (! $set['enabled']) {
            return response()->json(['message' => 'Calls are turned off for your centre.'], 403);
        }
        if ($fromFamily && ! $set['families']) {
            return response()->json(['message' => 'Your centre does not take calls from the app. Send a message instead.'], 403);
        }
        // Not the people who blocked the caller (App\Support\UserBlocks).
        $recipients = array_values(array_unique(array_map('intval', UserBlocks::keep($callerId, $recipients, false))));
        if (! $recipients) {
            return response()->json(['message' => 'There is nobody in this conversation to call.'], 422);
        }

        // A call that never got going does not block the next one.
        self::sweep();
        $busy = DB::table('chat_calls')->where('caller_id', $callerId)->whereIn('status', ['ringing', 'connecting', 'answered'])->exists();
        if ($busy) {
            return response()->json(['message' => 'You are already in a call. Hang it up first.'], 409);
        }

        $now = now();
        $id = DB::table('chat_calls')->insertGetId([
            'agency_id' => $agencyId, 'kind' => $kind, 'ref_id' => $refId, 'caller_id' => $callerId,
            'recipients' => json_encode($recipients), 'status' => 'ringing',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $call = self::find($id);

        $u = DB::table('users')->where('id', $callerId)->first(['first_name', 'last_name']);
        $first = trim((string) ($u->first_name ?? '')) ?: 'Someone';
        $full = trim($first . ' ' . (string) ($u->last_name ?? ''));
        $link = self::chatLink($call) . '&call=' . $id;
        $title = '📞 ' . $full . ' is calling';
        $body = 'Tap to answer in KiddieTrac.';
        foreach ($recipients as $rid) {
            try {
                app(\App\Services\WebPushService::class)->sendToUsers([$rid], [
                    'title' => $title, 'body' => $body, 'icon' => '/icon-192.png', 'url' => '/dashboard.html' . $link,
                    'tag' => 'call-' . $id, 'requireInteraction' => true, 'vibrate' => [400, 200, 400, 200, 400],
                ]);
            } catch (\Throwable $e) {
                Log::warning('Chat call web push failed', ['user' => $rid, 'e' => $e->getMessage()]);
            }
            try {
                // Rings like the phone line's app ring: urgent, through quiet hours, 30 s of ringing.
                app(\App\Services\FcmService::class)->sendToUser($rid, $title, $body, $link, true, true, false,
                    ['kt_category' => 'call', 'kt_call' => '1', 'kt_ring_secs' => '30']);
            } catch (\Throwable $e) {
                Log::warning('Chat call FCM push failed', ['user' => $rid, 'e' => $e->getMessage()]);
            }
        }
        $rows = [];
        foreach ($recipients as $rid) {
            $rows[] = ['user_id' => $rid, 'type' => 'call', 'title' => $title, 'body' => $full . ' is calling you from a chat.',
                'data' => json_encode(['url' => '/dashboard.html' . $link, 'call_id' => $id, 'kt_category' => 'call',
                    'from' => $full, 'kind' => $kind, 'ref_id' => $refId]),
                'created_at' => $now, 'push' => false];
        }
        Notify::write($rows);
        self::audit($call, $callerId, 'chat.call', $full . ' called ' . count($recipients) . ' ' . (count($recipients) === 1 ? 'person' : 'people') . ' from a chat');

        return response()->json(['ok' => true, 'call' => self::present($call, $callerId), 'hold_secs' => self::HOLD_SECS,
            'ice_servers' => self::iceServers()], 201);
    }

    // ── the people in it ──────────────────────────────────────────────────────────

    public static function find(int $id): ?object
    {
        self::ensureTable();
        return DB::table('chat_calls')->where('id', $id)->first();
    }

    public static function recipientsOf(object $call): array
    {
        return array_map('intval', json_decode((string) $call->recipients, true) ?: []);
    }

    public static function isIn(object $call, int $uid): bool
    {
        return (int) $call->caller_id === $uid || in_array($uid, self::recipientsOf($call), true);
    }

    /** What a page needs to draw the call. The answer goes only to the caller; the offer only to the people rung. */
    public static function present(object $call, int $me): array
    {
        $name = fn ($id) => $id ? trim((string) DB::table('users')->where('id', $id)->selectRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) as n")->value('n')) : null;
        // Their display picture on the call screen (2026-11-04, Anthony: "match display pic or avatar when using the call feature").
        $photo = function ($id) { $p = $id ? DB::table('users')->where('id', $id)->value('photo_url') : null; return $p ? ProtectedMedia::sign((string) $p) : null; };
        $recips = self::recipientsOf($call);
        $mine = (int) $call->caller_id === $me;
        $other = $mine ? ($call->answered_by ? (int) $call->answered_by : (count($recips) === 1 ? $recips[0] : null)) : (int) $call->caller_id;

        return [
            'id' => (int) $call->id, 'status' => $call->status, 'kind' => $call->kind, 'ref_id' => (int) $call->ref_id,
            'link' => self::chatLink($call), 'mine' => $mine,
            'caller' => $name((int) $call->caller_id), 'other' => $other ? $name($other) : (count($recips) . ' people'),
            'caller_photo' => $photo((int) $call->caller_id), 'other_photo' => $other ? $photo($other) : null,
            'answered_by_me' => (int) $call->answered_by === $me, 'answered_by' => $call->answered_by ? $name((int) $call->answered_by) : null,
            'duration' => $call->duration_seconds ? (int) $call->duration_seconds : null,
            'answered_at' => $call->answered_at, 'created_at' => $call->created_at,
            'answer_sdp' => $mine && $call->status === 'connecting' ? $call->answer_sdp : null,
            'has_offer' => ! empty($call->offer_sdp),
        ];
    }

    private static function chatLink(object $call): string
    {
        return $call->kind === 'staff' ? '#chat?c=staff:' . (int) $call->ref_id : '#chat?c=' . (int) $call->ref_id;
    }

    /** STUN (free, public) and, when configured, Cloudflare TURN for the networks that need a relay. */
    public static function iceServers(): array
    {
        $out = [['urls' => ['stun:stun.cloudflare.com:3478', 'stun:stun.l.google.com:19302']]];
        $key = (string) env('CF_TURN_KEY_ID', '');
        $tok = (string) env('CF_TURN_TOKEN', '');
        if ($key === '' || $tok === '') return $out;
        $hit = Cache::get('kt-turn-creds');
        if (! $hit) {
            try {
                $r = Http::withToken($tok)->timeout(6)
                    ->post('https://rtc.live.cloudflare.com/v1/turn/keys/' . rawurlencode($key) . '/credentials/generate', ['ttl' => 86400]);
                $ice = $r->json('iceServers');
                if ($r->successful() && is_array($ice)) {
                    $hit = isset($ice['urls']) ? [$ice] : $ice;
                    Cache::put('kt-turn-creds', $hit, 6 * 3600);
                }
            } catch (\Throwable $e) {
                Log::warning('Chat call TURN credentials failed', ['e' => $e->getMessage()]);
            }
        }
        return $hit ? array_merge($out, $hit) : $out;
    }

    // ── the two pages introducing themselves ──────────────────────────────────────

    /** The caller's page: how to reach it. Only the caller, only while ringing. */
    public static function offer(object $call, int $uid, string $sdp): JsonResponse
    {
        if ((int) $call->caller_id !== $uid) return response()->json(['message' => 'That call is not yours.'], 404);
        if ($call->status !== 'ringing') return response()->json(['message' => 'That call has ended.', 'call' => self::present($call, $uid)], 410);
        DB::table('chat_calls')->where('id', $call->id)->update(['offer_sdp' => self::sdp($sdp), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** The offer, for a person being rung (only while it rings). */
    /* AN SDP ENDS WITH A LINE BREAK (2026-11-04, every call failed when answered). Laravel's TrimStrings middleware
       strips the trailing CRLF off request input, and a browser refuses a description whose last line is unterminated
       ("Failed to parse SessionDescription. a=ssrc:… msid:…" on Safia's phone, calls #5 and #7). Put it back. */
    public static function sdp(string $sdp): string
    {
        return rtrim($sdp, "\r\n") . "\r\n";
    }

    public static function offerFor(object $call, int $uid): ?string
    {
        return in_array($uid, self::recipientsOf($call), true) && $call->status === 'ringing' ? $call->offer_sdp : null;
    }

    /** The first to answer gets the call; their page's answer goes back to the caller. */
    public static function answer(object $call, int $uid, string $sdp): JsonResponse
    {
        if (! in_array($uid, self::recipientsOf($call), true)) {
            return response()->json(['message' => 'That call is not for you.'], 404);
        }
        self::sweep();
        $call = self::find((int) $call->id);
        if ($call->status !== 'ringing') {
            $taken = in_array($call->status, ['connecting', 'answered'], true);
            return response()->json(['ok' => false, 'reason' => $taken ? 'taken' : 'gone',
                'message' => $taken ? 'Someone else answered this call.' : 'That call has ended.'], $taken ? 409 : 410);
        }
        if (! Cache::add('kt-ccall-claim:' . $call->id, $uid, 600)) {
            return response()->json(['ok' => false, 'reason' => 'taken', 'message' => 'Someone else answered this call.'], 409);
        }
        DB::table('chat_calls')->where('id', $call->id)->where('status', 'ringing')
            ->update(['status' => 'connecting', 'answered_by' => $uid, 'answer_sdp' => self::sdp($sdp), 'updated_at' => now()]);
        $call = self::find((int) $call->id);
        self::audit($call, $uid, 'chat.call_answered', 'Answered a chat call');

        return response()->json(['ok' => true, 'call' => self::present($call, $uid)]);
    }

    /** One person says no. When everyone has, the caller hears it end. */
    public static function decline(object $call, int $uid): JsonResponse
    {
        if (! in_array($uid, self::recipientsOf($call), true)) {
            return response()->json(['message' => 'That call is not for you.'], 404);
        }
        $declined = array_map('intval', json_decode((string) $call->declined, true) ?: []);
        $declined = array_values(array_unique(array_merge($declined, [$uid])));
        DB::table('chat_calls')->where('id', $call->id)->update(['declined' => json_encode($declined), 'updated_at' => now()]);
        if ($call->status === 'ringing' && ! array_diff(self::recipientsOf($call), $declined)) {
            if (DB::table('chat_calls')->where('id', $call->id)->where('status', 'ringing')
                ->update(['status' => 'declined', 'ended_at' => now(), 'updated_at' => now()])) {
                self::line(self::find((int) $call->id));
            }
        }

        return response()->json(['ok' => true, 'call' => self::present(self::find((int) $call->id), $uid)]);
    }

    /** Either end hangs up (or its page gave up connecting): the call ends for both. */
    public static function hangup(object $call, int $uid, string $reason = ''): JsonResponse
    {
        if ($call->status === 'ringing') {
            if ((int) $call->caller_id === $uid) {
                $st = $reason === 'not-connected' ? 'failed' : 'canceled';
                if (DB::table('chat_calls')->where('id', $call->id)->where('status', 'ringing')
                    ->update(['status' => $st, 'ended_at' => now(), 'updated_at' => now()])) {
                    $call = self::find((int) $call->id);
                    self::line($call);
                    if ($st === 'canceled') self::missedBell($call);
                }
            }
        } elseif (in_array($call->status, ['connecting', 'answered'], true) && ((int) $call->caller_id === $uid || (int) $call->answered_by === $uid)) {
            $talked = $call->status === 'answered' && $call->answered_at && $reason !== 'not-connected';
            $dur = $talked ? max(1, now()->getTimestamp() - strtotime((string) $call->answered_at)) : null;
            if (DB::table('chat_calls')->where('id', $call->id)->whereIn('status', ['connecting', 'answered'])
                ->update(['status' => $talked ? 'ended' : 'failed', 'duration_seconds' => $dur, 'ended_at' => now(), 'updated_at' => now()])) {
                self::line(self::find((int) $call->id));
            }
        }

        return response()->json(['ok' => true, 'call' => self::present(self::find((int) $call->id), $uid)]);
    }

    /** A page says the two are talking (its connection is up). */
    public static function markTalking(object $call, int $uid): void
    {
        if (((int) $call->answered_by === $uid || (int) $call->caller_id === $uid) && $call->status === 'connecting') {
            DB::table('chat_calls')->where('id', $call->id)->where('status', 'connecting')
                ->update(['status' => 'answered', 'answered_at' => now(), 'updated_at' => now()]);
        }
    }

    /** A page in the call is still there (its status look, every few seconds). */
    public static function seen(object $call, int $uid): void
    {
        if ($call->status === 'answered' && ((int) $call->answered_by === $uid || (int) $call->caller_id === $uid)) {
            DB::table('chat_calls')->where('id', $call->id)->where('status', 'answered')->update(['updated_at' => now()]);
        }
    }

    /**
     * Calls nobody is looking after any more, closed and said in the thread: ringing past the hold (missed),
     * connecting for a minute (failed), or a talking call neither page has looked at for 2 minutes (ended).
     */
    public static function sweep(): void
    {
        try {
            $stale = DB::table('chat_calls')
                ->where(fn ($q) => $q->where('status', 'ringing')->where('created_at', '<', now()->subSeconds(self::HOLD_SECS + 5)))
                ->orWhere(fn ($q) => $q->where('status', 'connecting')->where('updated_at', '<', now()->subSeconds(60)))
                ->orWhere(fn ($q) => $q->where('status', 'answered')->where('updated_at', '<', now()->subMinutes(2)))
                ->limit(20)->get();
            foreach ($stale as $c) {
                $talked = $c->status === 'answered' && $c->answered_at;
                $st = $c->status === 'ringing' ? 'missed' : ($talked ? 'ended' : 'failed');
                $dur = $talked ? max(1, strtotime((string) $c->updated_at) - strtotime((string) $c->answered_at)) : null;
                if (DB::table('chat_calls')->where('id', $c->id)->where('status', $c->status)
                    ->update(['status' => $st, 'duration_seconds' => $dur, 'ended_at' => now(), 'updated_at' => now()])) {
                    $c = self::find((int) $c->id);
                    self::line($c);
                    if ($st === 'missed') self::missedBell($c);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Chat call sweep failed', ['e' => $e->getMessage()]);
        }
    }

    // ── what the thread says ──────────────────────────────────────────────────────

    /** One centred line per finished call, written once. */
    private static function line(?object $call): void
    {
        if (! $call || $call->message_id || ! in_array($call->status, ['ended', 'missed', 'declined', 'canceled', 'failed'], true)) return;
        $first = trim((string) DB::table('users')->where('id', $call->caller_id)->value('first_name')) ?: 'Someone';
        $d = (int) $call->duration_seconds;
        $text = match ($call->status) {
            'ended' => '📞 Call · ' . intdiv($d, 60) . ':' . str_pad((string) ($d % 60), 2, '0', STR_PAD_LEFT),
            'declined' => '📞 ' . $first . ' called · declined',
            'failed' => '📞 ' . $first . ' called · could not connect',
            default => '📞 Missed call from ' . $first,
        };
        $now = now();
        try {
            if ($call->kind === 'staff') {
                $mid = DB::table('staff_messages')->insertGetId(['thread_id' => $call->ref_id, 'sender_id' => $call->caller_id, 'body' => $text,
                    'is_system' => 1, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('staff_threads')->where('id', $call->ref_id)->update(['last_message_at' => $now, 'updated_at' => $now]);
            } else {
                $mid = DB::table('messages')->insertGetId(['conversation_id' => $call->ref_id, 'sender_id' => $call->caller_id, 'body' => $text,
                    'is_system' => 1, 'created_at' => $now, 'delivered_at' => $now]);
                DB::table('conversations')->where('id', $call->ref_id)->update(['last_message_at' => $now]);
            }
            DB::table('chat_calls')->where('id', $call->id)->whereNull('message_id')->update(['message_id' => $mid]);
        } catch (\Throwable $e) {
            Log::warning('Chat call line not written', ['call' => $call->id, 'e' => $e->getMessage()]);
        }
    }

    /** A missed call stays in the bell (the ringing one was only for the moment). */
    private static function missedBell(object $call): void
    {
        $full = trim((string) DB::table('users')->where('id', $call->caller_id)->selectRaw("CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,'')) as n")->value('n')) ?: 'Someone';
        $rows = [];
        foreach (self::recipientsOf($call) as $rid) {
            $rows[] = ['user_id' => $rid, 'type' => 'call_missed', 'title' => '📞 Missed call from ' . $full,
                'body' => 'Open the chat to call back or reply.',
                'data' => json_encode(['url' => '/dashboard.html' . self::chatLink($call), 'call_id' => (int) $call->id, 'kt_category' => 'chat']),
                'created_at' => now()];
        }
        try { Notify::write($rows); } catch (\Throwable $e) {}
    }

    private static function audit(object $call, int $uid, string $action, string $summary): void
    {
        try {
            Audit::write(['user_id' => $uid, 'agency_id' => $call->agency_id, 'action' => $action,
                'entity_type' => $call->kind === 'family' ? 'conversation' : 'staff_thread', 'entity_id' => $call->ref_id,
                'payload' => json_encode(['summary' => $summary, 'call_id' => (int) $call->id]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }
}
