<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ChatCall;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 📞 Calls from a chat, once started (App\Support\ChatCall). Any signed-in person: being the caller or one of the
 * people rung IS the permission, and anything else is "not found". Starting a call lives with each kind of chat
 * (ChatController, TeamChatController, MessageController), beside the nudge, where their access checks already are.
 */
final class ChatCallController extends Controller
{
    private function mine(Request $request, int $id): ?object
    {
        $call = ChatCall::find($id);
        return $call && ChatCall::isIn($call, (int) $request->user()->id) ? $call : null;
    }

    private function gone(): JsonResponse
    {
        return response()->json(['message' => 'That call is not there any more.'], 404);
    }

    private function sdp(Request $request): string
    {
        return (string) $request->validate(['sdp' => ['required', 'string', 'min:20', 'max:60000']])['sdp'];
    }

    /** GET /chat-calls/{id} — how the call is going (both ends look every few seconds). */
    public function show(Request $request, int $id): JsonResponse
    {
        ChatCall::sweep();
        $call = $this->mine($request, $id);
        if (! $call) return $this->gone();
        $uid = (int) $request->user()->id;
        ChatCall::seen($call, $uid);
        $out = ['call' => ChatCall::present($call, $uid)];
        // The person being rung gets the caller's offer and the connection helpers, to answer with.
        if ($offer = ChatCall::offerFor($call, $uid)) {
            $out['offer_sdp'] = $offer;
            $out['ice_servers'] = ChatCall::iceServers();
        }
        return response()->json($out);
    }

    /** POST /chat-calls/{id}/offer {sdp} — the caller's page. */
    public function offer(Request $request, int $id): JsonResponse
    {
        $call = $this->mine($request, $id);
        return $call ? ChatCall::offer($call, (int) $request->user()->id, $this->sdp($request)) : $this->gone();
    }

    /** POST /chat-calls/{id}/answer {sdp} — first to answer gets it. */
    public function answer(Request $request, int $id): JsonResponse
    {
        $call = $this->mine($request, $id);
        return $call ? ChatCall::answer($call, (int) $request->user()->id, $this->sdp($request)) : $this->gone();
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $call = $this->mine($request, $id);
        return $call ? ChatCall::decline($call, (int) $request->user()->id) : $this->gone();
    }

    public function hangup(Request $request, int $id): JsonResponse
    {
        $call = $this->mine($request, $id);
        if (! $call) return $this->gone();
        $reason = mb_substr((string) $request->input('reason', ''), 0, 40);
        if ($request->filled('cause')) {
            // Why a call did not connect, for when someone says calling "doesn't work".
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $call->agency_id, 'action' => 'chat.call_not_connected',
                'entity_type' => 'chat_call', 'entity_id' => $call->id,
                'payload' => json_encode(['summary' => 'A chat call did not connect', 'reason' => $reason, 'cause' => mb_substr((string) $request->input('cause'), 0, 200)]),
                'created_at' => now()]);
        }
        return ChatCall::hangup($call, (int) $request->user()->id, $reason);
    }

    /** POST /chat-calls/{id}/talking — a page's connection is up. */
    public function talking(Request $request, int $id): JsonResponse
    {
        $call = $this->mine($request, $id);
        if (! $call) return $this->gone();
        ChatCall::markTalking($call, (int) $request->user()->id);
        return response()->json(['ok' => true]);
    }
}
