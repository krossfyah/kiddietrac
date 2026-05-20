<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — Chat enhancements: per-recipient read receipts + typing
 * indicators (poll-based, no WebSocket required).
 *
 *   POST /chat/conversations/{id}/read    mark all messages in conv read
 *   GET  /chat/conversations/{id}/state   read-status + typing snapshot
 *   POST /chat/conversations/{id}/typing  ping "I'm typing" presence
 */
final class ChatV2Controller extends Controller
{
    public function markRead(Request $request, int $conversationId): JsonResponse
    {
        $u = $request->user();
        $this->assertParticipant($u->id, $conversationId);
        // Insert message_reads rows for any unread messages not from this user
        $unread = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $u->id)
            ->whereNotExists(function ($q) use ($u) {
                $q->select(DB::raw(1))->from('message_reads')
                  ->whereColumn('message_reads.message_id', 'messages.id')
                  ->where('message_reads.user_id', $u->id);
            })
            ->pluck('id');
        foreach ($unread as $mid) {
            DB::table('message_reads')->insertOrIgnore([
                'message_id' => $mid, 'user_id' => $u->id, 'read_at' => now(),
            ]);
        }
        // Touch messages.read_at if the conversation is 1-on-1
        DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $u->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
        return response()->json(['marked' => $unread->count()]);
    }

    public function state(Request $request, int $conversationId): JsonResponse
    {
        $u = $request->user();
        $this->assertParticipant($u->id, $conversationId);
        // Last 50 messages with read-receipt counts
        $messages = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->orderByDesc('id')
            ->limit(50)
            ->select('id', 'sender_id', 'read_at')
            ->get();
        $messageIds = $messages->pluck('id');
        $reads = DB::table('message_reads')
            ->whereIn('message_id', $messageIds)
            ->select('message_id', 'user_id', 'read_at')
            ->get()->groupBy('message_id');
        // Typing presence — anyone who pinged 'typing' in the last 5 seconds
        $typing = DB::table('chat_presence as cp')
            ->join('users as u', 'u.id', '=', 'cp.user_id')
            ->where('cp.conversation_id', $conversationId)
            ->where('cp.user_id', '!=', $u->id)
            ->where('cp.state', 'typing')
            ->where('cp.updated_at', '>=', now()->subSeconds(5))
            ->select('u.id', DB::raw("CONCAT(u.first_name,' ',u.last_name) as name"))
            ->get();
        return response()->json([
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'reads' => optional($reads->get($m->id))->map(fn ($r) => ['user_id' => $r->user_id, 'read_at' => $r->read_at]) ?? [],
                'read_at' => $m->read_at,
            ])->values(),
            'typing_now' => $typing,
        ]);
    }

    public function ping(Request $request, int $conversationId): JsonResponse
    {
        $data = $request->validate(['state' => 'required|in:typing,idle']);
        $u = $request->user();
        $this->assertParticipant($u->id, $conversationId);
        DB::table('chat_presence')->updateOrInsert(
            ['conversation_id' => $conversationId, 'user_id' => $u->id],
            ['state' => $data['state'], 'updated_at' => now()]
        );
        return response()->json(['state' => $data['state']]);
    }

    private function assertParticipant(int $userId, int $conversationId): void
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        abort_unless($conv, 404);
        // Participant rules: any guardian on conv.family_id or any staff at conv.centre_id
        $isGuardian = DB::table('guardians')->where('user_id', $userId)
            ->where('family_id', $conv->family_id)->exists();
        $isStaff = DB::table('role_assignments')->where('user_id', $userId)
            ->where('centre_id', $conv->centre_id)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isGuardian || $isStaff, 403, 'Not a participant');
    }
}
