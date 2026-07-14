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
                'users.photo_url as sender_photo',
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
            'messages' => $messages->map(function ($m) use ($user) {
                $mine = (int) $m->sender_id === (int) $user->id;
                $deleted = !empty($m->deleted_at);
                $system = !empty($m->is_system);
                $atts = ($deleted || !$m->attachments) ? [] : (json_decode($m->attachments, true) ?: []);
                return [
                    'id' => $m->id,
                    'sender_id' => $m->sender_id,
                    'sender_name' => trim(($m->sender_first ?? '') . ' ' . ($m->sender_last ?? '')),
                    'sender_photo_url' => $m->sender_photo ?: null,
                    'is_mine' => $mine,
                    'is_system' => $system,
                    'body' => $deleted ? null : $m->body,
                    'attachments' => $atts,
                    'deleted' => $deleted,
                    'edited' => !empty($m->edited_at),
                    'can_edit' => $mine && !$deleted && !$system && empty($atts),
                    'can_delete' => $mine && !$deleted && !$system,
                    'created_at' => $m->created_at,
                    'read_at' => $m->read_at,
                    'time_display' => Carbon::parse($m->created_at)->format('M j, g:i A'),
                ];
            })->all(),
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
            'body' => ['nullable', 'string', 'max:4000', 'required_without:attachment'],
            // Accept a photo OR a voice note (webm/ogg/mp4/mpeg audio from the browser recorder).
            'attachment' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp,image/gif,audio/webm,audio/ogg,audio/mp4,audio/mpeg,audio/aac,audio/wav,video/webm', 'max:16384'],
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

        // Optional image attachment (stored on the public disk → /storage/…).
        $attachments = [];
        if ($request->hasFile('attachment')) {
            $file = $request->file('attachment');
            $isAudio = str_starts_with((string) $file->getMimeType(), 'audio') || $file->getMimeType() === 'video/webm';
            $name = $file->getClientOriginalName();
            if ($name === '' || $name === null) { $name = ($isAudio ? 'voice.webm' : 'photo.jpg'); }
            $path = $file->storeAs(
                'messages/' . now()->format('Y/m'),
                $user->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $name),
                'public'
            );
            $attachments[] = ['type' => $isAudio ? 'audio' : 'image', 'url' => '/storage/' . $path];
        }

        $msgId = DB::table('messages')->insertGetId([
            'conversation_id' => $convoId,
            'sender_id' => $user->id,
            'body' => $data['body'] ?? '',
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => now(),
            'delivered_at' => now(),
        ]);

        DB::table('conversations')->where('id', $convoId)->update(['last_message_at' => now()]);

        // Notify the centre's staff (educators / director) of the parent's message.
        $preview = !empty($data['body'])
            ? mb_substr($data['body'], 0, 120)
            : (($attachments[0]['type'] ?? '') === 'audio' ? '🎤 Voice note' : '📷 Photo');
        $staffIds = DB::table('role_assignments')->where('centre_id', $family->centre_id)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])->pluck('user_id')->unique();
        foreach ($staffIds as $sid) {
            if ((int) $sid === (int) $user->id) continue;
            DB::table('notifications')->insert([
                'user_id' => $sid, 'type' => 'message',
                // Name the parent AND the child: "New message from a parent" tells
                // an educator with thirty families nothing they can act on.
                'title' => '💬 ' . trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                    . ' · ' . ($child->first_name ?? 'a child'),
                'body' => $preview,
                'data' => json_encode(['link' => '#chat', 'conversation_id' => $convoId]),
                'created_at' => now(),
            ]);
            try {
                app(\App\Services\FcmService::class)->sendToUser(
                    (int) $sid,
                    '💬 ' . trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) . ' · ' . ($child->first_name ?? 'a child'),
                    $preview, '#chat', true
                );
            } catch (\Throwable $e) {}
        }

        $this->audit($request, 'message.sent', $convoId, ['message_id' => $msgId, 'child_id' => $child->id, 'has_attachment' => !empty($attachments)]);
        return response()->json(['conversation_id' => $convoId, 'message_id' => $msgId], 201);
    }

    /**
     * GET /api/v1/parent/messages/unread-count
     * Unread messages across the guardian's own conversations (for the bottom-nav badge).
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['unread' => 0]);
        $convoIds = DB::table('conversations')->whereIn('family_id', $familyIds)->pluck('id')->all();
        if (empty($convoIds)) return response()->json(['unread' => 0]);
        $n = DB::table('messages')->whereIn('conversation_id', $convoIds)
            ->where('sender_id', '!=', $user->id)->whereNull('read_at')->count();
        return response()->json(['unread' => (int) $n]);
    }

    /**
     * POST /api/v1/parent/messages/nudge  { child_id }
     * A gentle "please reply" ping to the child's centre team (in-app + push). Throttled.
     */
    public function nudge(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['child_id' => ['required', 'integer']]);
        $child = DB::table('children')->where('id', $data['child_id'])->first();
        if (!$child) return response()->json(['message' => 'Child not found'], 404);
        if (!DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists()) {
            abort(403);
        }
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $sn = DB::table('users')->where('id', $user->id)->first();
        $parentName = trim(($sn->first_name ?? '') . ' ' . ($sn->last_name ?? '')) ?: 'A parent';
        $childName = $child->preferred_name ?: $child->first_name;

        // Find or create the conversation so the nudge is recorded in the thread.
        $convoId = DB::table('conversations')->where('family_id', $child->family_id)->where('child_id', $child->id)->value('id');
        if (!$convoId) {
            $convoId = DB::table('conversations')->insertGetId([
                'centre_id' => $family->centre_id, 'family_id' => $child->family_id, 'child_id' => $child->id,
                'subject' => 'About ' . $childName, 'last_message_at' => now(), 'created_at' => now(),
            ]);
        }

        // Log the nudge AS a message in the chat history (system message, timestamped).
        $msgId = DB::table('messages')->insertGetId([
            'conversation_id' => $convoId, 'sender_id' => $user->id,
            'body' => '👋 ' . $parentName . ' nudged the team for a reply.',
            'is_system' => 1, 'created_at' => now(), 'delivered_at' => now(),
        ]);
        DB::table('conversations')->where('id', $convoId)->update(['last_message_at' => now()]);

        $staffIds = DB::table('role_assignments')->where('centre_id', $family->centre_id)->where('active', true)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])->pluck('user_id')->unique();
        $body = $parentName . ' is waiting to hear back about ' . $childName . '.';
        foreach ($staffIds as $sid) {
            if ((int) $sid === (int) $user->id) continue;
            DB::table('notifications')->insert([
                'user_id' => $sid, 'type' => 'nudge',
                'title' => '👋 Nudge from ' . $parentName . ' · ' . $childName,
                'body' => $body,
                'data' => json_encode(['link' => '#chat', 'conversation_id' => $convoId]),
                'created_at' => now(),
            ]);
            try { app(\App\Services\FcmService::class)->sendToUser((int) $sid, '👋 Nudge from ' . $parentName, $body, '#chat', true); } catch (\Throwable $e) {}
        }
        $this->audit($request, 'message.nudge', $convoId, ['message_id' => $msgId, 'child_id' => $child->id, 'notified' => $staffIds->count()]);
        return response()->json(['success' => true, 'notified' => $staffIds->count(), 'message_id' => $msgId]);
    }

    /**
     * PATCH /api/v1/parent/messages/{message}  { body }
     * Edit your own text message. Audit-logged for compliance.
     */
    public function editMessage(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate(['body' => ['required', 'string', 'max:4000']]);
        $m = DB::table('messages')->where('id', $message)->first();
        if (!$m) return response()->json(['message' => 'Not found'], 404);
        if ((int) $m->sender_id !== (int) $user->id) abort(403);
        if (!empty($m->deleted_at) || !empty($m->is_system)) return response()->json(['message' => 'This message can’t be edited.'], 422);
        if ($m->attachments) return response()->json(['message' => 'Messages with attachments can’t be edited.'], 422);

        DB::table('messages')->where('id', $message)->update(['body' => $data['body'], 'edited_at' => now()]);
        $this->audit($request, 'message.edited', $m->conversation_id, ['message_id' => $message]);
        return response()->json(['success' => true]);
    }

    /**
     * DELETE /api/v1/parent/messages/{message}
     * Soft-delete your own message (both sides then see “message deleted”).
     */
    public function deleteMessage(Request $request, int $message): JsonResponse
    {
        $user = $request->user();
        $m = DB::table('messages')->where('id', $message)->first();
        if (!$m) return response()->json(['message' => 'Not found'], 404);
        if ((int) $m->sender_id !== (int) $user->id) abort(403);
        if (!empty($m->deleted_at)) return response()->json(['success' => true]);

        DB::table('messages')->where('id', $message)->update(['deleted_at' => now()]);
        $this->audit($request, 'message.deleted', $m->conversation_id, ['message_id' => $message]);
        return response()->json(['success' => true]);
    }

    /**
     * Compliance audit trail for every chat action (sent / edited / deleted /
     * nudge) — recorded to audit_logs with the actor, conversation + participants.
     */
    private function audit(Request $request, string $action, ?int $conversationId, array $extra = []): void
    {
        try {
            $convo = $conversationId ? DB::table('conversations')->where('id', $conversationId)->first() : null;
            $participants = [];
            if ($convo) {
                $guardianIds = DB::table('guardians')->where('family_id', $convo->family_id)->pluck('user_id')->all();
                $staffIds = DB::table('role_assignments')->where('centre_id', $convo->centre_id)->where('active', true)
                    ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])->pluck('user_id')->all();
                $participants = array_values(array_unique(array_merge($guardianIds, $staffIds)));
            }
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'action' => $action,
                'entity_type' => 'conversation',
                'entity_id' => $conversationId,
                'payload' => json_encode(array_merge($extra, ['participants' => $participants])),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never let auditing break the request */ }
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

        // Notify the family's guardians of the reply (in-app + push).
        if ($convo->family_id) {
            $preview = mb_substr($data['body'], 0, 120);
            foreach (DB::table('guardians')->where('family_id', $convo->family_id)->pluck('user_id') as $gid) {
                if ((int) $gid === (int) $user->id) continue;
                DB::table('notifications')->insert([
                    'user_id' => $gid, 'type' => 'message',
                    'title' => 'New message from your centre',
                    'body' => $preview,
                    'data' => json_encode(['link' => '#messages', 'conversation_id' => $convo->id]),
                    'created_at' => now(),
                ]);
                try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, 'New message from your centre 💬', $preview, '#messages'); } catch (\Throwable $e) {}
            }
        }

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
