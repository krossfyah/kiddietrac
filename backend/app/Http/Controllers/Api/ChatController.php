<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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

        $perPage = min(100, max(5, (int) $request->query('per_page', 25)));
        $page = max(1, (int) $request->query('page', 1));

        $base = DB::table('conversations')
            ->whereNull('deleted_at')                 // hide deleted conversations
            ->whereIn('centre_id', $centreIds);

        // Searching has to run over the whole set, not the page — otherwise it only
        // finds what was already on screen, which is not searching.
        if ($q = trim((string) $request->query('q', ''))) {
            $famIds = DB::table('families')->whereIn('centre_id', $centreIds)
                ->where('family_name', 'like', '%'.$q.'%')->pluck('id');
            $kidIds = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->whereIn('f.centre_id', $centreIds)
                ->where(function ($w) use ($q) {
                    $w->where('ch.first_name', 'like', '%'.$q.'%')
                      ->orWhere('ch.last_name', 'like', '%'.$q.'%');
                })->pluck('ch.id');
            $base->where(function ($w) use ($famIds, $kidIds, $q) {
                $w->whereIn('family_id', $famIds->all() ?: [0])
                  ->orWhereIn('child_id', $kidIds->all() ?: [0])
                  ->orWhere('subject', 'like', '%'.$q.'%');
            });
        }

        $total = (clone $base)->count();

        // id as a tiebreaker, or paging is not stable. A broadcast stamps every
        // conversation with the same last_message_at, and tied rows have no guaranteed
        // order between two queries — page 2 was repeating rows from page 1 and hiding
        // an equal number that nobody would ever see.
        $conversations = $base->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get();

        return response()->json([
            'conversations' => $this->enrichConversations($conversations, $user->id),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
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
     * POST /api/v1/provider/chats/{conversation}/nudge
     * A one-tap "please check your messages" ping from the centre to the family —
     * the provider-side mirror of the parent nudge (👋). Reuses insertMessage so
     * the family's guardians get the same urgent FCM full-screen takeover any
     * other chat message triggers; no separate notification path to drift.
     */
    public function providerNudge(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $sender = DB::table('users')->where('id', $user->id)->first(['first_name', 'last_name']);
        $name = $sender ? trim(($sender->first_name ?? '') . ' ' . ($sender->last_name ?? '')) : 'Your centre';
        if ($name === '') $name = 'Your centre';
        $body = '👋 ' . $name . ' sent a nudge — please check your messages when you have a moment.';
        return response()->json($this->insertMessage($conversationId, $user->id, $body));
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

        // Platform admins can start a conversation with any family. Everyone else
        // needs a role_assignment that grants access to this family's centre.
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();
        if (! $isPlatform) {
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
        $q = DB::table('conversations')->whereNull('deleted_at');   // hide deleted conversations
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

        // Last message preview per conversation. delivered_at and read_at come with
        // it now: after sending to 41 families the question is who has actually seen
        // it, and previously the only way to find out was to open all 41.
        $lastMsg = DB::table('messages')
            ->whereIn('conversation_id', $ids)
            ->orderBy('conversation_id')
            ->orderByDesc('created_at')
            ->get(['id', 'conversation_id', 'sender_id', 'body', 'created_at',
                   'delivered_at', 'read_at', 'family_read_at', 'is_system'])
            ->groupBy('conversation_id')
            ->map(fn ($msgs) => $msgs->first())
            ->all();

        // Family + child + centre meta
        // Which users are guardians of these families — so "did the family read it"
        // can be answered for a message ANY staff member sent, not only my own. A
        // director asking whether a broadcast landed is the main case, and they did not
        // send it.
        $familyIds = $conversations->pluck('family_id')->unique()->all();
        $guardianByFamily = DB::table('guardians')->whereIn('family_id', $familyIds)
            ->get(['family_id', 'user_id'])
            ->groupBy('family_id')
            ->map(fn ($rows) => $rows->pluck('user_id')->map(fn ($v) => (int) $v)->all())
            ->all();
        /* Presence for a family thread: the most recently seen guardian on that
           family. Several guardians share one thread, so what an educator wants to
           know is whether ANYONE on the family is reachable. Built from the guardian
           ids already gathered above -- one extra query for the whole page. */
        $presence = [];
        try {
            $guardianIds = collect($guardianByFamily)->flatten()->unique()->filter()->all();
            $seenById = ! empty($guardianIds)
                ? DB::table('users')->whereIn('id', $guardianIds)->whereNull('deleted_at')
                    ->pluck('last_seen_at', 'id')->all()
                : [];
            foreach ($guardianByFamily as $famId => $uids) {
                $newest = null;
                foreach ($uids as $uid) {
                    $seen = $seenById[$uid] ?? null;
                    if ($seen && ($newest === null || $seen > $newest)) {
                        $newest = $seen;
                    }
                }
                $presence[$famId] = \App\Support\Presence::state($newest);
            }
        } catch (\Throwable $e) {
            // Presence is decoration; the list must still render without it.
        }

        $childIds  = $conversations->pluck('child_id')->filter()->unique()->all();
        $centreIds = $conversations->pluck('centre_id')->unique()->all();

        /* Which conversations the FAMILY has ever spoken in. Independent of who spoke
           last: a reply is a fact about the thread, and hiding it whenever staff sent
           something afterwards is what made the Status column look inconsistent. */
        $senderPairs = DB::table('messages')->whereIn('conversation_id', $ids)
            ->select('conversation_id', 'sender_id', DB::raw('MAX(created_at) as last_at'))
            ->groupBy('conversation_id', 'sender_id')->get()->groupBy('conversation_id');

        // Names for whoever sent the newest message in each thread, so the preview can
        // attribute it. One lookup for the page, not one per row.
        $lastSenderIds = collect($lastMsg)->pluck('sender_id')->filter()->unique()->all();
        $senderNames = ! empty($lastSenderIds)
            ? DB::table('users')->whereIn('id', $lastSenderIds)
                ->selectRaw("id, TRIM(CONCAT(COALESCE(first_name,''),' ',COALESCE(last_name,''))) as n")
                ->pluck('n', 'id')->all()
            : [];

        $families = DB::table('families')->whereIn('id', $familyIds)->pluck('family_name', 'id')->all();
        $children = !empty($childIds)
            ? DB::table('children')->whereIn('id', $childIds)->get()->keyBy('id')
            : collect();
        $centres = DB::table('centres')->whereIn('id', $centreIds)->pluck('name', 'id')->all();

        return $conversations->map(function ($c) use ($unread, $lastMsg, $families, $children, $centres, $userId, $guardianByFamily, $senderPairs, $senderNames, $presence) {
            $last = $lastMsg[$c->id] ?? null;

            /* Status of the last message, when the CENTRE sent it — not only when I did.
               A director checking whether a broadcast was read did not send it
               themselves, and that is the case this exists for. No tick on a message the
               family sent us: "read" there is just the unread count restated, and a tick
               would imply we had told them something we had not. */
            $lastStatus = null;
            $lastFrom = null;
            if ($last) {
                $famGuardians = $guardianByFamily[$c->family_id] ?? [];
                $fromStaff = ! in_array((int) $last->sender_id, $famGuardians, true);
                $lastFrom = $fromStaff ? 'centre' : 'family';
                if ($fromStaff) {
                    /* A reply is the strongest read receipt there is — they answered.
                       Checked FIRST, and derived from timestamps rather than a stamp,
                       so every historical thread reports correctly without backfilling
                       the old read_at (which was contaminated by staff opening threads).
                       family_read_at still covers the case where they read without
                       replying. */
                    $famReplyAfter = collect($senderPairs[$c->id] ?? [])
                        ->filter(fn ($r) => in_array((int) $r->sender_id, $famGuardians, true))
                        ->contains(fn ($r) => strtotime((string) $r->last_at) >= strtotime((string) $last->created_at));

                    $lastStatus = ($famReplyAfter || ($last->family_read_at ?? null)) ? 'read'
                        : ($last->delivered_at ? 'delivered' : 'sent');
                }
            }
            $child = ($c->child_id && isset($children[$c->child_id])) ? $children[$c->child_id] : null;
            return [
                'last_status' => $lastStatus,
                'last_from' => $lastFrom,     // 'centre' | 'family' — so the column is never blank
                // True if anyone on the family's side has ever replied in this thread.
                'has_replied' => collect($senderPairs[$c->id] ?? [])->contains(
                    fn ($r) => in_array((int) $r->sender_id, $guardianByFamily[$c->family_id] ?? [], true)
                ),
                'last_read_at' => $last->read_at ?? null,
                'last_delivered_at' => $last->delivered_at ?? null,
                'id' => $c->id,
                'centre_id' => $c->centre_id,
                'centre_name' => $centres[$c->centre_id] ?? 'Centre',
                'family_id' => $c->family_id,
                'family_name' => $families[$c->family_id] ?? 'Family',
                'presence' => $presence[$c->family_id] ?? 'offline',
                'child_id' => $c->child_id,
                'child_name' => $child ? trim(($child->first_name ?? '').' '.($child->last_name ?? '')) : null,
                'child_photo_url' => $child->photo_url ?? null,
                'subject' => $c->subject,
                'last_message_at' => $c->last_message_at,
                'unread_count' => (int) ($unread[$c->id] ?? 0),
                'preview' => $last ? mb_substr($last->body, 0, 120) : null,
                'last_sender_id' => $last->sender_id ?? null,
                /* Who said it. Null when the sender is a guardian of this family —
                   the row is already labelled with the family name, so repeating it
                   adds nothing. Set for a colleague posting into a family thread,
                   which otherwise looked exactly like the family replying. */
                'last_sender_name' => ($last && $lastFrom === 'centre'
                    && (int) $last->sender_id !== (int) $userId)
                    ? ($senderNames[$last->sender_id] ?? null) : null,
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

        // Emoji reactions for all messages in this thread, grouped by message id.
        $msgIds = $messages->pluck('id')->all();
        $reactsByMsg = (!empty($msgIds)
            ? DB::table('message_reactions')->whereIn('message_id', $msgIds)->get()
            : collect())->groupBy('message_id');

        $msgArr = $messages->map(function ($m) use ($senders, $userId, $reactsByMsg) {
            $s = $senders[$m->sender_id] ?? null;
            $isMe = $m->sender_id == $userId;
            $deleted = ! empty($m->deleted_at);
            return [
                'id' => $m->id,
                'body' => $deleted ? null : $m->body,
                'attachments' => $deleted ? [] : ($m->attachments ? (json_decode($m->attachments, true) ?: []) : []),
                'sender_id' => $m->sender_id,
                'sender_name' => $s ? trim(($s->first_name ?? '').' '.($s->last_name ?? '')) : 'Unknown',
                'sender_photo_url' => $s->photo_url ?? null,   // so the chat shows real photos, not just initials
                'is_me' => $isMe,
                'deleted' => $deleted,
                'can_delete' => $isMe && ! $deleted,
                'reactions' => $this->groupReactions($reactsByMsg[$m->id] ?? collect(), (int) $userId),
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
                'child_photo_url' => $child->photo_url ?? null,
            ],
            'messages' => $msgArr,
            'typing_users' => $this->typingUsers($conversationId, $userId),
        ];
    }

    /** Record the caller as typing (cache-backed, ~8s). Access already checked. */
    private function markTyping(int $conversationId, $user): void
    {
        $key = 'kt_typing:' . $conversationId;
        $now = time();
        $map = Cache::get($key, []);
        if (!is_array($map)) $map = [];
        foreach ($map as $uid => $e) { if (!isset($e['at']) || ($now - $e['at']) > 8) unset($map[$uid]); }
        $map[(int) $user->id] = [
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'Someone',
            'at'   => $now,
        ];
        Cache::put($key, $map, now()->addSeconds(30));
    }

    /** Names of OTHER participants typing within the last 6s. */
    private function typingUsers(int $conversationId, int $exceptId): array
    {
        $map = Cache::get('kt_typing:' . $conversationId, []);
        if (!is_array($map)) return [];
        $now = time(); $out = [];
        foreach ($map as $uid => $e) {
            if ((int) $uid === $exceptId) continue;
            if (!isset($e['at']) || ($now - $e['at']) > 6) continue;
            $out[] = $e['name'] ?? 'Someone';
        }
        return array_values(array_unique($out));
    }

    /** POST /parent/chats/{c}/typing — parent typing ping. */
    public function parentTyping(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->parentCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        $this->markTyping($conversationId, $user);
        return response()->json(['ok' => true]);
    }

    /** POST /provider/chats/{c}/typing — provider (educator/director/home-visitor) typing ping. */
    public function providerTyping(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        $this->markTyping($conversationId, $user);
        return response()->json(['ok' => true]);
    }

    /** DELETE /parent/chats/{c}/messages/{m} — a guardian removes their own message. */
    public function parentDeleteMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $user = $request->user();
        if (! $this->parentCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doDeleteMessage($conversationId, $messageId, (int) $user->id);
    }

    /** DELETE /provider/chats/{c}/messages/{m} — an educator/director/admin removes their own message. */
    public function providerDeleteMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doDeleteMessage($conversationId, $messageId, (int) $user->id);
    }

    /** DELETE /parent/chats/{c} — remove an entire conversation from the guardian's list. */
    public function parentDeleteConversation(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->parentCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doDeleteConversation($conversationId);
    }

    /** DELETE /provider/chats/{c} — remove an entire conversation from the staff list. */
    public function providerDeleteConversation(Request $request, int $conversationId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doDeleteConversation($conversationId);
    }

    /** Soft-delete a whole conversation (it drops off everyone's list; recoverable in the DB). */
    private function doDeleteConversation(int $conversationId): JsonResponse
    {
        $conv = DB::table('conversations')->where('id', $conversationId)->first();
        if (! $conv) return response()->json(['message' => 'Not found'], 404);
        if (empty($conv->deleted_at)) {
            DB::table('conversations')->where('id', $conversationId)->update(['deleted_at' => now()]);
        }
        return response()->json(['ok' => true]);
    }

    /** POST /parent/chats/{c}/messages/{m}/react — toggle a guardian's emoji reaction. */
    public function parentReactMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $user = $request->user();
        if (! $this->parentCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doReact($conversationId, $messageId, (int) $user->id, (string) $request->input('emoji', ''));
    }

    /** POST /provider/chats/{c}/messages/{m}/react — toggle a staff member's emoji reaction. */
    public function providerReactMessage(Request $request, int $conversationId, int $messageId): JsonResponse
    {
        $user = $request->user();
        if (! $this->providerCanAccess($user->id, $conversationId)) return response()->json(['message' => 'Forbidden'], 403);
        return $this->doReact($conversationId, $messageId, (int) $user->id, (string) $request->input('emoji', ''));
    }

    /** Toggle an emoji reaction on a message (add, or remove if the caller already reacted with it). */
    private function doReact(int $conversationId, int $messageId, int $userId, string $emoji): JsonResponse
    {
        $emoji = trim($emoji);
        if ($emoji === '' || mb_strlen($emoji) > 8) return response()->json(['message' => 'Invalid emoji'], 422);
        $m = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->first();
        if (! $m || ! empty($m->deleted_at)) return response()->json(['message' => 'Not found'], 404);
        $existing = DB::table('message_reactions')
            ->where('message_id', $messageId)->where('user_id', $userId)->where('emoji', $emoji)->first();
        if ($existing) {
            DB::table('message_reactions')->where('id', $existing->id)->delete();
        } else {
            DB::table('message_reactions')->insert([
                'message_id' => $messageId, 'user_id' => $userId, 'emoji' => $emoji, 'created_at' => now(),
            ]);
        }
        $rows = DB::table('message_reactions')->where('message_id', $messageId)->get();
        return response()->json(['ok' => true, 'reactions' => $this->groupReactions($rows, $userId)]);
    }

    /** @param \Illuminate\Support\Collection $rows */
    private function groupReactions($rows, int $userId): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            if (! isset($grouped[$r->emoji])) $grouped[$r->emoji] = ['emoji' => $r->emoji, 'count' => 0, 'mine' => false];
            $grouped[$r->emoji]['count']++;
            if ((int) $r->user_id === $userId) $grouped[$r->emoji]['mine'] = true;
        }
        return array_values($grouped);
    }

    /** Soft-delete a message the caller sent (only their own). */
    private function doDeleteMessage(int $conversationId, int $messageId, int $userId): JsonResponse
    {
        $m = DB::table('messages')->where('id', $messageId)->where('conversation_id', $conversationId)->first();
        if (! $m) return response()->json(['message' => 'Not found'], 404);
        if ((int) $m->sender_id !== $userId) return response()->json(['message' => 'You can only delete your own messages.'], 403);
        if (! empty($m->deleted_at)) return response()->json(['ok' => true]);
        DB::table('messages')->where('id', $messageId)->update(['deleted_at' => now()]);
        return response()->json(['ok' => true]);
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

                    // v23: ALSO push to native FCM device tokens (the Capacitor
                    // Android/iOS app). The WebPushService call above only reaches
                    // VAPID web-push subscribers (browser PWAs); the app registers
                    // an FCM token (device_tokens.platform = android/ios), so
                    // without this the app NEVER got OS notifications for realtime
                    // chat — you'd hear the in-app ping but see nothing in the bar.
                    // Wrapped separately so an FCM hiccup can't break web push.
                    try {
                        $fcm = app(\App\Services\FcmService::class);
                        foreach ($recipients as $rid) {
                            $fcm->sendToUser((int) $rid, '💬 ' . $senderName, $preview, '#chat', true, true, false);   // false: web push already sent above; urgent+forceUrgent = takeover for staff AND parents
                        }
                    } catch (\Throwable $fe) {
                        \Illuminate\Support\Facades\Log::warning('FCM push from chat failed', ['error' => $fe->getMessage()]);
                    }

                    // v22p47: also persist a notifications row per recipient
                    // so the in-portal inbox shows the message even after the
                    // OS push notification has disappeared. Same payload shape
                    // as web push so the inbox renderer can lean on data.url.
                    // Context for the recipient: who sent it, about which child, at
                    // which centre. A parent seeing only "New message from Sarah"
                    // has to open it to find out which of their children it is about.
                    $ctxChild = null;
                    $ctxCentre = null;
                    try {
                        $ctx = DB::table('conversations as cv')
                            ->leftJoin('children as ch', 'ch.id', '=', 'cv.child_id')
                            ->leftJoin('centres as ce', 'ce.id', '=', 'cv.centre_id')
                            ->where('cv.id', $conversationId)
                            ->first(['ch.first_name as child_name', 'ce.name as centre_name']);
                        $ctxChild = $ctx->child_name ?? null;
                        $ctxCentre = $ctx->centre_name ?? null;
                    } catch (\Throwable $ce) {
                    }
                    $ctxSuffix = trim(
                        ($ctxChild ? ' · ' . $ctxChild : '')
                        . ($ctxCentre ? ' · ' . $ctxCentre : '')
                    );

                    $nowTs = $now;
                    $rows = [];
                    foreach ($recipients as $rid) {
                        $rows[] = [
                            'user_id' => $rid,
                            'type' => 'chat',
                            'title' => '💬 ' . $senderName . $ctxSuffix,
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
        // Shared with the staff threads. The voice-note container handling this used
        // to hold is subtle enough that a second copy would have drifted apart —
        // see App\Support\ChatAttachments.
        return \App\Support\ChatAttachments::extract($request);
    }

    private function markRead(int $conversationId, int $userId): void
    {
        // Clears THIS viewer's unread badge — every viewer, staff included.
        DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        /* The receipt shown to the centre is a different claim — "the family has seen
           this" — and only a guardian of this family can make it true. read_at cannot
           carry both meanings: it also drives each viewer's unread badge, so narrowing
           it would leave staff with permanently unread threads. */
        $isGuardian = DB::table('conversations')
            ->join('guardians', 'guardians.family_id', '=', 'conversations.family_id')
            ->where('conversations.id', $conversationId)
            ->where('guardians.user_id', $userId)
            ->exists();
        if (! $isGuardian) {
            return;
        }

        DB::table('messages')
            ->where('conversation_id', $conversationId)
            ->where('sender_id', '!=', $userId)
            ->whereNull('family_read_at')
            ->update(['family_read_at' => now()]);
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
        // Platform admins can access every centre (their role_assignment carries no
        // agency_id/centre_id, so the role-scoped joins below would exclude them) —
        // BUT only within the agency they've SWITCHED INTO. Without this the chat
        // list showed every agency's conversations to a super admin regardless of
        // the active-agency switch (cross-tenant leak). No agency selected → none.
        $isPlatform = DB::table('role_assignments')
            ->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            $active = 0;
            try { $active = (int) request()->header('X-Active-Agency-Id'); } catch (\Throwable $e) {}
            if ($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists()) {
                return DB::table('centres')->where('agency_id', $active)->pluck('id')->all();
            }

            // No agency selected. Rather than showing nothing, fall back to the agencies
            // this person actually administers in their own right — someone who is both a
            // platform admin AND an agency admin was getting an empty Messenger whenever
            // the header was missing, which includes the moment before the agency
            // switcher initialises. Strictly narrower than the branch above: it grants
            // only what they already hold, and never a tenant they have no role in.
            $ownAgencies = DB::table('role_assignments')
                ->where('user_id', $userId)->where('role', 'agency_admin')
                ->where('active', true)->whereNotNull('agency_id')
                ->pluck('agency_id')->all();

            return $ownAgencies
                ? DB::table('centres')->whereIn('agency_id', $ownAgencies)->pluck('id')->all()
                : [];
        }

        $directIds = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director'])
            ->where('active', true)
            ->whereNotNull('centre_id')
            ->pluck('centre_id')
            ->all();

        // Agency admins — and agency-attached home visitors — see all centres in
        // their agencies (home_visitor has no centre_id, only an agency_id).
        $agencyIds = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['agency_admin', 'home_visitor'])
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

    /**
     * POST /provider/chats/broadcast — one message, a whole audience.
     *
     * Admins and directors only. Everything is resolved through App\Support\Audience, the
     * same definition Announcements uses, so "all educators" cannot come to mean two
     * different things in two places.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $user = $request->user();

        $roles = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('active', true)->pluck('role')->all();
        $mayBroadcast = (bool) array_intersect($roles, ['agency_admin', 'centre_director', 'platform_admin']);
        abort_unless($mayBroadcast, 403, 'Only admins and directors can send to everyone.');

        $data = $request->validate([
            'audience' => ['required', 'in:'.implode(',', \App\Support\Audience::KEYS)],
            'centre_id' => ['nullable', 'integer'],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:5000'],
            // Sending to every family is not undoable, so the client has to mean it.
            'confirm' => ['nullable', 'boolean'],
        ]);

        $agencyId = $this->broadcastAgencyId($request, $roles);
        abort_unless($agencyId, 403, 'No agency for this account.');

        // A director may only address their own centre; an agency admin the whole agency.
        $centreId = $data['centre_id'] ?? null;
        if (! array_intersect($roles, ['agency_admin', 'platform_admin'])) {
            $own = DB::table('role_assignments')->where('user_id', $user->id)
                ->where('role', 'centre_director')->where('active', true)
                ->whereNotNull('centre_id')->pluck('centre_id')->all();
            abort_unless($own, 403, 'No centre for this account.');
            if ($centreId) {
                abort_unless(in_array((int) $centreId, array_map('intval', $own), true), 403,
                    'That is not your centre.');
            } elseif (count($own) === 1) {
                $centreId = (int) $own[0];
            }
        }
        if ($centreId) {
            $ok = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists();
            abort_unless($ok, 403, 'That centre is not in your agency.');
        }

        $audience = $data['audience'];
        $body = trim($data['body']);
        $subject = ($data['subject'] ?? null) ?: null;

        // A dry count first, so the UI can say "this goes to 36 families" BEFORE it goes.
        if (empty($data['confirm'])) {
            return response()->json([
                'preview' => true,
                'audience' => $audience,
                'label' => \App\Support\Audience::label($audience),
                'families' => in_array($audience, ['parents', 'all'], true)
                    ? count(\App\Support\Audience::families($agencyId, $centreId)) : 0,
                'staff' => $audience === 'parents' ? 0
                    : count(\App\Support\Audience::resolve(
                        $audience === 'all' ? 'staff' : $audience, $agencyId, $centreId)),
            ]);
        }

        $familyCount = 0;
        $staffCount = 0;
        // Gathered as we go and pushed once at the end. Parents and staff land on
        // different screens, so they are kept apart.
        $pushParents = [];
        $pushStaff = [];

        // ── parents ─────────────────────────────────────────────────────
        if (in_array($audience, ['parents', 'all'], true)) {
            /* ONE PRIVATE THREAD PER PARENT — not one message into each family's shared
               thread.

               A `conversations` row is a centre-to-family thread: every colleague at
               that centre can read it and post in it. Broadcasting into 43 of those
               meant an educator could answer in families that were not hers, and the
               replies came back into shared threads rather than to the sender. A
               broadcast is from one person to another, so it belongs where only those
               two can see it — which is exactly what the staff half below already does.

               Existing family conversations are untouched; this only changes where a
               BROADCAST lands. */
            foreach (\App\Support\Audience::resolve('parents', $agencyId, $centreId) as $gid) {
                $gid = (int) $gid;
                if (! $gid || $gid === (int) $user->id) {
                    continue;
                }
                try {
                    $threadId = \App\Support\PrivateThreads::findOrCreate((int) $user->id, $gid, $agencyId);
                    \App\Support\PrivateThreads::post((int) $user->id, $threadId, $body);

                    $pushParents[] = $gid;
                    DB::table('notifications')->insert([
                        'user_id' => $gid,
                        'type' => 'message',
                        'title' => 'Message from your centre',
                        'body' => mb_substr($body, 0, 200),
                        // A thread now, not a shared conversation.
                        'data' => json_encode(['link' => '#messages', 'thread_id' => $threadId]),
                        'created_at' => now(),
                    ]);
                    $familyCount++;
                } catch (\Throwable $e) {
                    // One parent failing must not stop the other forty-three.
                    Log::warning('Broadcast to parent failed', [
                        'user' => $gid, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // ── staff ───────────────────────────────────────────────────────
        if ($audience !== 'parents') {
            $key = $audience === 'all' ? 'staff' : $audience;
            $ids = \App\Support\Audience::resolve($key, $agencyId, $centreId);
            $ids = array_values(array_diff($ids, [(int) $user->id]));   // not back to yourself

            foreach ($ids as $uid) {
                try {
                    // A REAL message in a 1:1 team thread, not just a bell. A bell is
                    // invisible to the missed-message emailer and has nothing to reply
                    // to — the parent half of this broadcast lands in a real thread, and
                    // the staff half should behave the same way.
                    /* Same helper the parent half uses. The old copy here also
                       filtered on t.agency_id, which split one pair's history across
                       agencies; a 1:1 conversation is one conversation. */
                    $threadId = \App\Support\PrivateThreads::findOrCreate((int) $user->id, (int) $uid, $agencyId);
                    \App\Support\PrivateThreads::post((int) $user->id, $threadId, $body);

                    DB::table('notifications')->insert([
                        'user_id' => $uid,
                        'type' => 'message',
                        'title' => $subject ?: 'Message from '.trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
                        'body' => mb_substr($body, 0, 200),
                        'data' => json_encode(['link' => '#team-chat', 'thread_id' => $threadId]),
                        'created_at' => now(),
                    ]);
                    $pushStaff[] = (int) $uid;
                    $staffCount++;
                } catch (\Throwable $e) {
                    Log::warning('Broadcast to staff failed', ['user' => $uid, 'error' => $e->getMessage()]);
                }
            }
        }

        /* Push it to their phones.
           The message and the bell were being written and nothing else — so a broadcast
           to 56 people showed up in the portal and never buzzed a single handset. Both
           transports, the same pair the announcement path uses: FCM for the Android app,
           web push for the browser PWA. Deep-linked per audience, because a parent's copy
           is in #messages and a staff copy is in #team-chat.

           Best-effort throughout: a phone that cannot be reached must never cost the
           message, which is already delivered by this point. */
        $pushTitle = $subject ?: ('Message from '.(trim(($user->first_name ?? '').' '.($user->last_name ?? '')) ?: 'your centre'));
        $pushBody = mb_substr($body, 0, 180);

        foreach ([['#messages', array_unique($pushParents)], ['#team-chat', array_unique($pushStaff)]] as [$link, $ids]) {
            if (! $ids) {
                continue;
            }
            try {
                $fcm = app(\App\Services\FcmService::class);
                foreach ($ids as $uid) {
                    $fcm->sendToUser((int) $uid, $pushTitle, $pushBody, $link, true);
                }
            } catch (\Throwable $e) {
                Log::warning('Broadcast FCM push failed', ['error' => $e->getMessage()]);
            }
            try {
                app(\App\Services\WebPushService::class)->sendToUsers(array_values($ids), [
                    'title' => $pushTitle,
                    'body' => $pushBody,
                    'icon' => '/icon-192.png',
                    'url' => '/dashboard.html'.$link,
                    'tag' => 'kt-message',
                ]);
            } catch (\Throwable $e) {
                Log::warning('Broadcast web push failed', ['error' => $e->getMessage()]);
            }
        }

        \App\Support\Audit::write([
            'user_id' => $user->id,
            'agency_id' => $agencyId,
            'action' => 'chat.broadcast',
            'entity_type' => 'conversation',
            'payload' => json_encode([
                'summary' => trim(($user->first_name ?? '').' '.($user->last_name ?? ''))
                    .' messaged '.\App\Support\Audience::label($audience)
                    .' — '.$familyCount.' family thread(s), '.$staffCount.' staff',
                'audience' => $audience,
                'centre_id' => $centreId,
                'families' => $familyCount,
                'staff' => $staffCount,
                'pushed' => count(array_unique(array_merge($pushParents, $pushStaff))),
            ]),
            'ip_address' => substr((string) $request->ip(), 0, 45),
            'created_at' => now(),
        ]);

        return response()->json([
            'sent' => true,
            'families' => $familyCount,
            'staff' => $staffCount,
            'pushed' => count(array_unique(array_merge($pushParents, $pushStaff))),
            'label' => \App\Support\Audience::label($audience),
        ]);
    }

    /** The agency this broadcast belongs to — never a header taken on trust. */
    private function broadcastAgencyId(Request $request, array $roles): ?int
    {
        $userId = $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');

        if ($header) {
            $allowed = in_array('platform_admin', $roles, true)
                || DB::table('role_assignments')->where('user_id', $userId)
                    ->where('agency_id', $header)->where('active', true)->exists();
            if ($allowed && DB::table('agencies')->where('id', $header)->exists()) {
                return $header;
            }
        }

        $own = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        if ($own) {
            return (int) $own;
        }

        // A director attached only to a centre still has an agency, through it.
        $viaCentre = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('ra.active', true)->value('c.agency_id');

        return $viaCentre ? (int) $viaCentre : null;
    }

}
