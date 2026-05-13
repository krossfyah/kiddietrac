<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MessageController extends Controller
{
    /**
     * GET /api/v1/parent/messages
     * List conversations for the logged-in parent's families.
     */
    public function myConversations(Request $request): JsonResponse
    {
        $user = $request->user();

        $familyIds = DB::table('guardians')
            ->where('user_id', $user->id)
            ->pluck('family_id')
            ->all();

        if (empty($familyIds)) {
            return response()->json(['conversations' => []]);
        }

        $conversations = DB::table('conversations')
            ->whereIn('family_id', $familyIds)
            ->orderByDesc('last_message_at')
            ->get();

        $convoIds = $conversations->pluck('id')->all();
        $unreadCounts = [];
        $lastMessages = [];

        if (!empty($convoIds)) {
            $unreadCounts = DB::table('messages')
                ->whereIn('conversation_id', $convoIds)
                ->where('sender_id', '!=', $user->id)
                ->whereNull('read_at')
                ->select('conversation_id', DB::raw('count(*) as cnt'))
                ->groupBy('conversation_id')
                ->pluck('cnt', 'conversation_id')
                ->all();

            $lastMessages = DB::table('messages')
                ->whereIn('conversation_id', $convoIds)
                ->orderByDesc('created_at')
                ->get()
                ->groupBy('conversation_id')
                ->map(fn ($msgs) => $msgs->first())
                ->all();
        }

        $children = DB::table('children')
            ->whereIn('id', $conversations->pluck('child_id')->filter()->all())
            ->select('id', 'first_name', 'preferred_name')
            ->get()
            ->keyBy('id');

        return response()->json([
            'conversations' => $conversations->map(function ($c) use ($unreadCounts, $lastMessages, $children) {
                $child = $c->child_id ? ($children[$c->child_id] ?? null) : null;
                $lastMsg = $lastMessages[$c->id] ?? null;
                return [
                    'id' => $c->id,
                    'subject' => $c->subject ?? ($child ? 'About ' . ($child->preferred_name ?: $child->first_name) : 'General'),
                    'child_id' => $c->child_id,
                    'child_name' => $child ? ($child->preferred_name ?: $child->first_name) : null,
                    'last_message_at' => $c->last_message_at,
                    'last_message_display' => $c->last_message_at ? Carbon::parse($c->last_message_at)->diffForHumans() : null,
                    'last_message_preview' => $lastMsg ? \Illuminate\Support\Str::limit($lastMsg->body, 80) : null,
                    'unread_count' => (int) ($unreadCounts[$c->id] ?? 0),
                ];
            })->all(),
        ]);
    }

    /**
     * GET /api/v1/parent/messages/{conversation}
     * Get all messages in a conversation. Marks them read.
     */
    public function show(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        $convo = DB::table('conversations')->where('id', $conversationId)->first();
        if (!$convo) return response()->json(['message' => 'Not found'], 404);

        if (!$this->canAccessConversation($user, $convo)) {
            abort(403);
        }

        $messages = DB::table('messages')
            ->leftJoin('users', 'users.id', '=', 'messages.sender_id')
            ->where('messages.conversation_id', $conversationId)
            ->orderBy('messages.created_at')
            ->select(
                'messages.*',
                'users.first_name as sender_first',
                'users.last_name as sender_last',
            )
            ->get();

        // Mark messages from others as read
        DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'conversation' => $convo,
            'messages' => $messages->map(fn ($m) => [
                'id' => $m->id,
                'sender_id' => $m->sender_id,
                'sender_name' => trim(($m->sender_first ?? '') . ' ' . ($m->sender_last ?? '')),
                'is_mine' => (int) $m->sender_id === (int) $user->id,
                'body' => $m->body,
                'created_at' => $m->created_at,
                'read_at' => $m->read_at,
                'time_display' => Carbon::parse($m->created_at)->format('M j, g:i A'),
            ])->all(),
        ]);
    }

    /**
     * POST /api/v1/parent/messages
     * Parent sends a message; auto-creates conversation if needed.
     */
    public function sendToRoom(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $child = DB::table('children')->where('id', $data['child_id'])->first();
        if (!$child) return response()->json(['message' => 'Child not found'], 404);

        // Verify user is a guardian of this child
        if (!DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists()) {
            abort(403);
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();

        // Find or create conversation for this family+child
        $convoId = DB::table('conversations')
            ->where('family_id', $child->family_id)
            ->where('child_id', $child->id)
            ->value('id');

        if (!$convoId) {
            $convoId = DB::table('conversations')->insertGetId([
                'centre_id' => $family->centre_id,
                'family_id' => $child->family_id,
                'child_id' => $child->id,
                'subject' => 'About ' . ($child->preferred_name ?: $child->first_name),
                'last_message_at' => now(),
                'created_at' => now(),
            ]);
        }

        $msgId = DB::table('messages')->insertGetId([
            'conversation_id' => $convoId,
            'sender_id' => $user->id,
            'body' => $data['body'],
            'created_at' => now(),
            'delivered_at' => now(),
        ]);

        DB::table('conversations')->where('id', $convoId)->update(['last_message_at' => now()]);

        return response()->json(['conversation_id' => $convoId, 'message_id' => $msgId], 201);
    }

    /**
     * GET /api/v1/provider/conversations
     * For educator/director — list conversations at their centre.
     */
    public function educatorConversations(Request $request): JsonResponse
    {
        $user = $request->user();
        $centreId = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->value('centre_id');

        if (!$centreId) {
            return response()->json(['conversations' => []]);
        }

        $conversations = DB::table('conversations')
            ->where('centre_id', $centreId)
            ->orderByDesc('last_message_at')
            ->get();

        $convoIds = $conversations->pluck('id')->all();
        $unreadCounts = empty($convoIds) ? [] : DB::table('messages')
            ->whereIn('conversation_id', $convoIds)
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->select('conversation_id', DB::raw('count(*) as cnt'))
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id')
            ->all();

        $children = DB::table('children')
            ->whereIn('id', $conversations->pluck('child_id')->filter()->all())
            ->select('id', 'first_name', 'preferred_name', 'family_id')
            ->get()
            ->keyBy('id');

        $families = DB::table('families')
            ->whereIn('id', $conversations->pluck('family_id')->filter()->all())
            ->select('id', 'family_name')
            ->get()
            ->keyBy('id');

        return response()->json([
            'conversations' => $conversations->map(function ($c) use ($unreadCounts, $children, $families) {
                $child = $c->child_id ? ($children[$c->child_id] ?? null) : null;
                $family = $c->family_id ? ($families[$c->family_id] ?? null) : null;
                return [
                    'id' => $c->id,
                    'subject' => $c->subject,
                    'child_id' => $c->child_id,
                    'child_name' => $child ? ($child->preferred_name ?: $child->first_name) : null,
                    'family_name' => $family->family_name ?? null,
                    'last_message_at' => $c->last_message_at,
                    'last_message_display' => $c->last_message_at ? Carbon::parse($c->last_message_at)->diffForHumans() : null,
                    'unread_count' => (int) ($unreadCounts[$c->id] ?? 0),
                ];
            })->all(),
        ]);
    }

    /**
     * POST /api/v1/provider/messages
     * Educator replies to a conversation.
     */
    public function educatorReply(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'conversation_id' => ['required', 'integer'],
            'body' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        $convo = DB::table('conversations')->where('id', $data['conversation_id'])->first();
        if (!$convo) return response()->json(['message' => 'Not found'], 404);

        if (!$this->canAccessConversation($user, $convo)) {
            abort(403);
        }

        $msgId = DB::table('messages')->insertGetId([
            'conversation_id' => $convo->id,
            'sender_id' => $user->id,
            'body' => $data['body'],
            'created_at' => now(),
            'delivered_at' => now(),
        ]);

        DB::table('conversations')->where('id', $convo->id)->update(['last_message_at' => now()]);

        return response()->json(['message_id' => $msgId], 201);
    }

    private function canAccessConversation($user, object $convo): bool
    {
        // Guardian path
        if ($convo->family_id) {
            $isGuardian = DB::table('guardians')
                ->where('user_id', $user->id)
                ->where('family_id', $convo->family_id)
                ->exists();
            if ($isGuardian) return true;
        }

        // Staff path — same centre
        if ($convo->centre_id) {
            return DB::table('role_assignments')
                ->where('user_id', $user->id)
                ->where('active', true)
                ->where('centre_id', $convo->centre_id)
                ->exists();
        }

        return false;
    }
}
