<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v12-big: Unified chat controller for parents AND providers (educators/directors).
 *
 * Schema (verified against production):
 *   conversations: id, centre_id, family_id, child_id?, subject?, last_message_at, created_at
 *   messages:      id, conversation_id, sender_id, body, attachments?, language?,
 *                  translated_body?, read_at?, delivered_at?, created_at
 *
 * Key design choices:
 *   - One conversation per family ↔ centre (with optional child_id for child-specific threads)
 *   - Parents see all conversations for any family they're guardian of
 *   - Educators see all conversations at any centre they have a role at
 *   - Read receipts: read_at gets set when the OTHER side fetches the thread
 *   - Polling: frontend hits /unread-count every 15s for the nav badge
 */
final class ChatController extends Controller
{
    /* ─── PARENT ENDPOINTS ─────────────────────────────────────── */

    /**
     * GET /api/v1/parent/chats
     * List all conversations for the logged-in parent's families.
     */
    public function parentList(Request $request): JsonResponse
    {
        $user = $request->user();

        $familyIds = DB::table('guardians')
            ->where('user_id', $user->id)
            ->pluck('family_id')
            ->all();

        if (empty($familyIds)) {
            return response()->json(['conversations' => []]);
        }

        return response()->json([
            'conversations' => $this->fetchConversations($familyIds, null, $user->id),
        ]);
    }

    /**
     * GET /api/v1/parent/chats/{conversation}
     * Open one thread. Marks all messages as read (from parent's perspective).
     */
    public function parentShow(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->parentCanAccess($user->id, $conversationId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->markRead($conversationId, $user->id);

        return response()->json($this->fetchThread($conversationId, $user->id));
    }

    /**
     * POST /api/v1/parent/chats/{conversation}/send
     * Parent sends a message into an existing conversation.
     */
    public function parentSend(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
        ]);

        if (! $this->parentCanAccess($user->id, $conversationId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attachments = $this->extractAttachment($request);
        return response()->json($this->insertMessage($conversationId, $user->id, $data['body'] ?? '', $attachments));
    }

    /**
     * POST /api/v1/parent/chats/start
     * Start (or get-or-create) a conversation between a family and its centre.
     */
    public function parentStart(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'family_id' => ['required', 'integer'],
            'child_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        // Verify guardianship
        $isGuardian = DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $data['family_id'])
            ->exists();
        if (! $isGuardian) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $family = DB::table('families')->where('id', $data['family_id'])->first();
        if (! $family) return response()->json(['message' => 'Family not found'], 404);

        // Find-or-create conversation for this (family, child) pair
        $query = DB::table('conversations')
            ->where('family_id', $data['family_id'])
            ->where('centre_id', $family->centre_id);
        if (isset($data['child_id'])) {
            $query->where('child_id', $data['child_id']);
        } else {
            $query->whereNull('child_id');
        }
        $conv = $query->first();

        if (! $conv) {
            $convId = DB::table('conversations')->insertGetId([
                'centre_id' => $family->centre_id,
                'family_id' => $data['family_id'],
                'child_id' => $data['child_id'] ?? null,
                'subject' => $data['subject'] ?? null,
                'last_message_at' => now(),
                'created_at' => now(),
            ]);
        } else {
            $convId = $conv->id;
        }

        $msg = $this->insertMessage($convId, $user->id, $data['body']);
        return response()->json(['conversation_id' => $convId, 'message' => $msg], 201);
    }

    /* ─── PROVIDER ENDPOINTS ───────────────────────────────────── */

    /**
     * GET /api/v1/provider/chats
     * List conversations for centres the educator/director has access to.
     */
    public function providerList(Request $request): JsonResponse
    {
        $user = $request->user();
        $centreIds = $this->providerCentreIds($user->id);
        if (empty($centreIds)) return response()->json(['conversations' => []]);

        $conversations = DB::table('conversations')
            ->whereIn('centre_id', $centreIds)
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get();

        return response()->json([
            'conversations' => $this->enrichConversations($conversations, $user->id),
        ]);
    }

    /**
     * GET /api/v1/provider/chats/{conversation}
     * Open one thread. Marks as read for the provider.
     */
    public function providerShow(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->markRead($conversationId, $user->id);

        return response()->json($this->fetchThread($conversationId, $user->id));
    }

    /**
     * POST /api/v1/provider/chats/{conversation}/send
     */
    public function providerSend(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
        ]);

        if (! $this->providerCanAccess($user->id, $conversationId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $attachments = $this->extractAttachment($request);
        return response()->json($this->insertMessage($conversationId, $user->id, $data['body'] ?? '', $attachments));
    }

    /**
     * v22p15 — POST /api/v1/provider/chats/start
     * Allow agency_admin / centre_director / educator to initiate a chat
     * with a family they have provider access to (mirror of parentStart).
     * Body: family_id (req) + child_id? + subject? + body (req).
     */
    public function providerStart(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'family_id' => ['required', 'integer'],
            'child_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $family = DB::table('families')->where('id', $data['family_id'])->whereNull('deleted_at')->first();
        if (! $family) return response()->json(['message' => 'Family not found'], 404);

        // Provider must have a role_assignment that grants access to this family's centre.
        $hasAccess = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where(function ($q) use ($family) {
                $q->where('centre_id', $family->centre_id)
                  ->orWhereExists(function ($w) use ($family) {
                      $w->select(DB::raw(1))->from('centres')
                        ->whereColumn('centres.agency_id', 'role_assignments.agency_id')
                        ->where('centres.id', $family->centre_id);
                  });
            })
            ->exists();
        if (! $hasAccess) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Find-or-create conversation for this (family, child) pair — same dedupe
        // semantics as parentStart so a parent-initiated thread and a provider-
        // initiated thread on the same child collapse into one.
        $query = DB::table('conversations')
            ->where('family_id', $data['family_id'])
            ->where('centre_id', $family->centre_id);
        if (isset($data['child_id'])) {
            $query->where('child_id', $data['child_id']);
        } else {
            $query->whereNull('child_id');
        }
        $conv = $query->first();

        if (! $conv) {
            $convId = DB::table('conversations')->insertGetId([
                'centre_id' => $family->centre_id,
                'family_id' => $data['family_id'],
                'child_id' => $data['child_id'] ?? null,
                'subject' => $data['subject'] ?? null,
                'last_message_at' => now(),
                'created_at' => now(),
            ]);
        } else {
            $convId = $conv->id;
        }

        $msg = $this->insertMessage($convId, $user->id, $data['body']);
        return response()->json(['conversation_id' => $convId, 'message' => $msg], 201);
    }

    /* ─── SHARED ENDPOINTS ─────────────────────────────────────── */

    /**
     * GET /api/v1/chats/unread-count
     * Cheap call for the nav badge — called every 15 sec via polling.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $user = $request->user();
        $count = $this->unreadForUser($user->id);
        return response()->json(['unread' => $count]);
    }

    /* ─── HELPERS ──────────────────────────────────────────────── */

    private function fetchConversations(array $familyIds, ?array $centreIds, int $userId): array
    {
        $q = DB::table('conversations');
        if (!empty($familyIds)) $q->whereIn('family_id', $familyIds);
        if (!empty($centreIds)) $q->whereIn('centre_id', $centreIds);
        $conversations = $q->orderByDesc('last_message_at')->limit(50)->get();
        return $this->enrichConversations($conversations, $userId);
    }

    private function enrichConversations($conversations, int $userId): array
    {
        if ($conversations->isEmpty()) return [];

        $ids = $conversations->pluck('id')->all();

        // Unread counts (messages where sender != me AND read_at is null)
        $unread = DB::table('messages')
            ->whereIn('conversation_id', $ids)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->select('conversation_id', DB::raw('count(*) as cnt'))
            ->groupBy('conversation_id')
            ->pluck('cnt', 'conversation_id')
            ->all();

        // Last message preview per conversation
        $lastMsg = DB::table('messages')
            ->whereIn('conversation_id', $ids)
            ->orderBy('conversation_id')
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('conversation_id')
            ->map(fn ($msgs) => $msgs->first())
            ->all();

        // Family + child + centre meta
        $familyIds = $conversations->pluck('family_id')->unique()->all();
        $childIds  = $conversations->pluck('child_id')->filter()->unique()->all();
        $centreIds = $conversations->pluck('centre_id')->unique()->all();

        $families = DB::table('families')->whereIn('id', $familyIds)->pluck('family_name', 'id')->all();
        $children = !empty($childIds)
            ? DB::table('children')->whereIn('id', $childIds)->get()->keyBy('id')
            : collect();
        $centres = DB::table('centres')->whereIn('id', $centreIds)->pluck('name', 'id')->all();

        return $conversations->map(function ($c) use ($unread, $lastMsg, $families, $children, $centres) {
            $last = $lastMsg[$c->id] ?? null;
            $child = ($c->child_id && isset($children[$c->child_id])) ? $children[$c->child_id] : null;
            return [
                'id' => $c->id,
                'centre_id' => $c->centre_id,
                'centre_name' => $centres[$c->centre_id] ?? 'Centre',
                'family_id' => $c->family_id,
                'family_name' => $families[$c->family_id] ?? 'Family',
                'child_id' => $c->child_id,
                'child_name' => $child ? trim(($child->first_name ?? '').' '.($child->last_name ?? '')) : null,
                'subject' => $c->subject,
                'last_message_at' => $c->last_message_at,
                'unread_count' => (int) ($unread[$c->id] ?? 0),
                'preview' => $last ? mb_substr($last->body, 0, 120) : null,
                'last_sender_id' => $last->sender_id ?? null,
            ];
        })->toArray();
    }

    private function fetchThread(int $conversationId, int $userId): array
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        if (!$conv) return ['conversation' => null, 'messages' => []];

        $messages = DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->get();

        // Sender info
        $senderIds = $messages->pluck('sender_id')->unique()->all();
        $senders = !empty($senderIds)
            ? DB::table('users')->whereIn('id', $senderIds)->get()->keyBy('id')
            : collect();

        $msgArr = $messages->map(function ($m) use ($senders, $userId) {
            $s = $senders[$m->sender_id] ?? null;
            return [
                'id' => $m->id,
                'body' => $m->body,
                'attachments' => $m->attachments ? (json_decode($m->attachments, true) ?: []) : [],
                'sender_id' => $m->sender_id,
                'sender_name' => $s ? trim(($s->first_name ?? '').' '.($s->last_name ?? '')) : 'Unknown',
                'is_me' => $m->sender_id == $userId,
                'created_at' => $m->created_at,
                'read_at' => $m->read_at,
            ];
        })->toArray();

        // Family / child name for header
        $family = DB::table('families')->where('id', $conv->family_id)->first();
        $child = $conv->child_id ? DB::table('children')->where('id', $conv->child_id)->first() : null;
        $centre = DB::table('centres')->where('id', $conv->centre_id)->first();

        return [
            'conversation' => [
                'id' => $conv->id,
                'subject' => $conv->subject,
                'family_id' => $conv->family_id,
                'family_name' => $family->family_name ?? 'Family',
                'centre_id' => $conv->centre_id,
                'centre_name' => $centre->name ?? 'Centre',
                'child_id' => $conv->child_id,
                'child_name' => $child ? trim(($child->first_name ?? '').' '.($child->last_name ?? '')) : null,
            ],
            'messages' => $msgArr,
        ];
    }

    private function insertMessage(int $conversationId, int $senderId, string $body, array $attachments = []): array
    {
        $now = now();
        $msgId = DB::table('messages')->insertGetId([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'body' => $body,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => $now,
        ]);
        // v22p17.2: rewritten push for the conversations data model.
        // The previous block queried a chat_thread_participants table that
        // does not exist in this schema, so chat messages were never actually
        // triggering OS notifications even when the sender's pipeline worked.
        // Recipients = every user who can see this conversation EXCEPT the sender:
        //   - All guardians on the family
        //   - All active staff (centre_director / educator) at the centre
        //   - All agency_admins of the centre's agency
        try {
            $conv = DB::table('conversations')->where('id', $conversationId)->first();
            if ($conv) {
                $centreId = (int) $conv->centre_id;
                $agencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');

                $guardianIds = DB::table('guardians')
                    ->where('family_id', $conv->family_id)
                    ->pluck('user_id')
                    ->all();

                $staffIds = DB::table('role_assignments')
                    ->where('active', true)
                    ->where(function ($q) use ($centreId, $agencyId) {
                        $q->where('centre_id', $centreId)
                          ->orWhere(function ($w) use ($agencyId) {
                              $w->where('role', 'agency_admin')
                                ->where('agency_id', $agencyId);
                          });
                    })
                    ->pluck('user_id')
                    ->all();

                $recipients = array_values(array_diff(
                    array_unique(array_merge($guardianIds, $staffIds)),
                    [$senderId]
                ));

                if (! empty($recipients)) {
                    $sender = DB::table('users')->where('id', $senderId)->first(['first_name', 'last_name']);
                    $senderName = $sender ? trim(($sender->first_name ?? '').' '.($sender->last_name ?? '')) : 'Someone';
                    $preview = $body !== ''
                        ? mb_substr($body, 0, 120)
                        : (! empty($attachments) ? '📎 sent an image' : 'New message');

                    app(\App\Services\WebPushService::class)->sendToUsers($recipients, [
                        'title' => '💬 ' . $senderName,
                        'body'  => $preview,
                        'icon'  => '/icon-192.png',
                        'url'   => '/dashboard.html#chat',
                        'tag'   => 'chat-' . $conversationId,
                    ]);

                    // v22p47: also persist a notifications row per recipient
                    // so the in-portal inbox shows the message even after the
                    // OS push notification has disappeared. Same payload shape
                    // as web push so the inbox renderer can lean on data.url.
                    $nowTs = $now;
                    $rows = [];
                    foreach ($recipients as $rid) {
                        $rows[] = [
                            'user_id' => $rid,
                            'type' => 'chat',
                            'title' => 'New message from ' . $senderName,
                            'body' => $preview,
                            'data' => json_encode([
                                'url' => '/dashboard.html#chat',
                                'conversation_id' => $conversationId,
                            ]),
                            'created_at' => $nowTs,
                        ];
                    }
                    if (!empty($rows)) DB::table('notifications')->insert($rows);
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Push from chat failed', ['error' => $e->getMessage()]);
                    }

        DB::table('conversations')->where('id', $conversationId)->update([
            'last_message_at' => $now,
        ]);

        return [
            'id' => $msgId,
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'body' => $body,
            'attachments' => $attachments,
            'created_at' => $now->toDateTimeString(),
        ];
    }

    /**
     * v22p17 — extract an optional image attachment from the request.
     * Returns array of {url, mime, name, size} or empty array.
     * Throws ValidationException on bad input.
     */
    private function extractAttachment(Request $request): array
    {
        if (! $request->hasFile('attachment')) return [];
        $request->validate([
            'attachment' => ['file', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp,gif'],
        ]);
        $file = $request->file('attachment');
        $path = $file->store('chat-attachments', 'public');
        return [[
            'url' => '/storage/' . $path,
            'mime' => $file->getMimeType(),
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
        ]];
    }

    private function markRead(int $conversationId, int $userId): void
    {
        DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function parentCanAccess(int $userId, int $conversationId): bool
    {
        return DB::table('conversations')
            ->join('guardians', 'guardians.family_id', '=', 'conversations.family_id')
            ->where('conversations.id', $conversationId)
            ->where('guardians.user_id', $userId)
            ->exists();
    }

    private function providerCanAccess(int $userId, int $conversationId): bool
    {
        $centreIds = $this->providerCentreIds($userId);
        if (empty($centreIds)) return false;
        return DB::table('conversations')
            ->where('id', $conversationId)
            ->whereIn('centre_id', $centreIds)
            ->exists();
    }

    /**
     * Centres this user can access as a provider (educator/director/agency_admin).
     */
    private function providerCentreIds(int $userId): array
    {
        $directIds = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director'])
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')
            ->all();

        // Agency admins see all centres in their agencies
        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->whereNotNull('agency_id')
            ->pluck('agency_id')
            ->all();

        if (!empty($agencyIds)) {
            $agencyCentres = DB::table('centres')
                ->whereIn('agency_id', $agencyIds)
                ->pluck('id')
                ->all();
            $directIds = array_unique(array_merge($directIds, $agencyCentres));
        }

        return $directIds;
    }

    private function unreadForUser(int $userId): int
    {
        // Find all conversation IDs this user can access
        $convIds = [];

        // As parent: via guardianship
        $famIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->all();
        if (!empty($famIds)) {
            $convIds = array_merge($convIds, DB::table('conversations')
                ->whereIn('family_id', $famIds)
                ->pluck('id')
                ->all());
        }

        // As provider: via centre roles
        $centreIds = $this->providerCentreIds($userId);
        if (!empty($centreIds)) {
            $convIds = array_merge($convIds, DB::table('conversations')
                ->whereIn('centre_id', $centreIds)
                ->pluck('id')
                ->all());
        }

        if (empty($convIds)) return 0;

        return (int) DB::table('messages')
            ->whereIn('conversation_id', array_unique($convIds))
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->count();
    }
}
