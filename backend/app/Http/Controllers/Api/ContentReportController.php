<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\UserBlocks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * REPORT A MESSAGE, BLOCK A PERSON (2026-11-03, Anthony: "Build Report + Block").
 *
 * App Review (Guideline 1.2, asked for again under 2.1) wants every app with user-generated content to let people
 * report objectionable content and block abusive users, and the developer to act on reports. In KiddieTrac:
 *   - Report: any message someone else sent, in a family conversation (parent <-> centre) or a staff thread. A copy of
 *     the message is kept with the report (it survives a later delete). The agency's admins and KiddieTrac's
 *     superadmins are told in the bell; they review it in Audit log -> Chat conversations -> Reported messages.
 *   - Block: hides that person's messages from you and stops them notifying you (App\Support\UserBlocks). Emergency
 *     messages still come through. The agency's admins are told, because in a childcare centre a parent blocking an
 *     educator (or the reverse) is something the centre needs to know about.
 * You can only report or block someone through a conversation you are part of.
 */
class ContentReportController extends Controller
{
    public const REASONS = [
        'offensive'  => 'Inappropriate or offensive',
        'harassment' => 'Harassment or bullying',
        'spam'       => 'Spam or unwanted',
        'safety'     => 'A safety concern',
        'other'      => 'Something else',
    ];

    /** The conversation as the caller may see it, or null: [agency_id, label]. */
    private function context(int $uid, string $kind, int $convId): ?array
    {
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($kind === 'staff') {
            $t = DB::table('staff_threads')->where('id', $convId)->first(['id', 'agency_id', 'title']);
            if (! $t) return null;
            $member = DB::table('staff_thread_participants')->where('thread_id', $convId)->where('user_id', $uid)->exists();
            return ($member || $isPlatform) ? ['agency_id' => (int) $t->agency_id ?: null] : null;
        }
        $c = DB::table('conversations')->where('id', $convId)->first(['id', 'centre_id', 'family_id', 'channel']);
        if (! $c) return null;
        if (! $isPlatform && \App\Support\MessagingRules::officeHiddenFrom($uid, $c)) return null;   // office thread: directors/admins only
        $agencyId = (int) DB::table('centres')->where('id', $c->centre_id)->value('agency_id') ?: null;
        $ok = $isPlatform
            || ($c->family_id && DB::table('guardians')->where('user_id', $uid)->where('family_id', $c->family_id)->exists())
            || DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(function ($w) use ($c, $agencyId) {
                    $w->where('centre_id', $c->centre_id)
                      ->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId));
                })->exists();
        return $ok ? ['agency_id' => $agencyId] : null;
    }

    /** The message, held to its conversation: [sender_id, excerpt, is_system]. */
    private function message(string $kind, int $convId, int $msgId): ?object
    {
        if ($kind === 'staff') {
            return DB::table('staff_messages')->where('id', $msgId)->where('thread_id', $convId)
                ->first(['id', 'sender_id', 'body', 'attachments', 'is_system']);
        }
        return DB::table('messages')->where('id', $msgId)->where('conversation_id', $convId)
            ->first(['id', 'sender_id', 'body', 'attachments', DB::raw('0 as is_system')]);
    }

    private static function name(?int $uid): string
    {
        if (! $uid) return 'Someone';
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
        return $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Someone') : 'Someone';
    }

    /** Agency admins of the agency and KiddieTrac's superadmins - the people who review reports. */
    private static function reviewers(?int $agencyId, array $except): array
    {
        $ids = DB::table('role_assignments')->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where('role', 'platform_admin');
                if ($agencyId) $w->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId));
            })->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all();
        $ids = DB::table('users')->whereIn('id', $ids ?: [0])->whereNull('deleted_at')->where('status', 'active')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
        return array_values(array_diff($ids, array_map('intval', $except)));
    }

    /* NOT FOR THE APP REVIEW SANDBOX (2026-11-04). Anthony: "I keep getting a notification about Jordan Ellis blocking
       Taylor". Jordan Ellis and Taylor Morgan are the made-up App Review accounts: Apple's reviewer (and the review
       video recording) reports and blocks between them to see the feature work. The report and the block still happen
       and are still kept and audited - only the alert to admins (who include every platform admin) is not sent. */
    private static function reviewSandbox(int $uid): bool
    {
        $email = (string) DB::table('users')->where('id', $uid)->value('email');
        return str_ends_with(strtolower($email), '@appreview.kiddietrac.com');
    }

    private static function bell(array $userIds, string $title, string $body, array $data): void
    {
        if (! $userIds) return;
        $now = now();
        \App\Support\Notify::write(array_map(fn ($id) => [
            'user_id' => $id, 'type' => 'content_report', 'title' => $title, 'body' => $body,
            'data' => json_encode($data + ['link' => '#audit-logs?tab=chats']), 'created_at' => $now,
        ], $userIds));
    }

    private function doBlock(int $uid, int $target, ?int $agencyId): bool
    {
        $new = DB::table('user_blocks')->insertOrIgnore([
            'user_id' => $uid, 'blocked_user_id' => $target, 'agency_id' => $agencyId, 'created_at' => now(),
        ]) > 0;
        UserBlocks::forget($uid);
        return $new;
    }

    /** POST /me/content-reports {kind, conversation_id, message_id, reason, note?, block?} */
    public function report(Request $request): JsonResponse
    {
        $d = $request->validate([
            'kind' => 'required|in:family,staff',
            'conversation_id' => 'required|integer|min:1',
            'message_id' => 'required|integer|min:1',
            'reason' => 'required|in:' . implode(',', array_keys(self::REASONS)),
            'note' => 'nullable|string|max:1000',
            'block' => 'sometimes|boolean',
        ]);
        $uid = (int) $request->user()->id;
        $ctx = $this->context($uid, $d['kind'], (int) $d['conversation_id']);
        $msg = $ctx ? $this->message($d['kind'], (int) $d['conversation_id'], (int) $d['message_id']) : null;
        if (! $msg) return response()->json(['message' => 'That message could not be found.'], 404);
        $sender = (int) $msg->sender_id;
        if ($sender === $uid || ! empty($msg->is_system)) {
            return response()->json(['message' => 'You can only report a message someone else sent.'], 422);
        }
        $atts = $msg->attachments ? (json_decode($msg->attachments, true) ?: []) : [];
        $excerpt = mb_substr((string) $msg->body, 0, 1500)
            . ($atts ? "\n[attachments: " . implode(', ', array_map(fn ($a) => (string) ($a['url'] ?? $a['name'] ?? 'file'), $atts)) . ']' : '');
        $block = (bool) ($d['block'] ?? false);

        $id = DB::table('content_reports')->insertGetId([
            'agency_id' => $ctx['agency_id'], 'reporter_id' => $uid, 'reported_user_id' => $sender,
            'kind' => $d['kind'], 'conversation_id' => (int) $d['conversation_id'], 'message_id' => (int) $d['message_id'],
            'reason' => $d['reason'], 'note' => $d['note'] ?? null, 'excerpt' => $excerpt, 'blocked' => $block,
            'status' => 'open', 'created_at' => now(),
        ]);
        if ($block) $this->doBlock($uid, $sender, $ctx['agency_id']);

        $who = self::name($uid); $whom = self::name($sender);
        if (! self::reviewSandbox($uid)) self::bell(self::reviewers($ctx['agency_id'], [$uid, $sender]),
            '🚩 Message reported: ' . $whom,
            $who . ' reported a message from ' . $whom . ' (' . self::REASONS[$d['reason']] . ')' . ($block ? ' and blocked them.' : '.'),
            ['content_report_id' => $id, 'kind' => $d['kind'], 'conversation_id' => (int) $d['conversation_id']]);

        return response()->json(['ok' => true, 'id' => $id, 'blocked' => $block]);
    }

    /** POST /me/blocks {user_id, kind, conversation_id} */
    public function block(Request $request): JsonResponse
    {
        $d = $request->validate([
            'user_id' => 'required|integer|min:1',
            'kind' => 'required|in:family,staff',
            'conversation_id' => 'required|integer|min:1',
        ]);
        $uid = (int) $request->user()->id; $target = (int) $d['user_id'];
        if ($target === $uid) return response()->json(['message' => 'You cannot block yourself.'], 422);
        $ctx = $this->context($uid, $d['kind'], (int) $d['conversation_id']);
        // Only someone who is in this conversation with you: they sent in it, or (staff) they are a member.
        $inIt = $ctx && ($d['kind'] === 'staff'
            ? DB::table('staff_thread_participants')->where('thread_id', $d['conversation_id'])->where('user_id', $target)->exists()
            : DB::table('messages')->where('conversation_id', $d['conversation_id'])->where('sender_id', $target)->exists());
        if (! $inIt) return response()->json(['message' => 'That person could not be found in this conversation.'], 404);

        if ($this->doBlock($uid, $target, $ctx['agency_id']) && ! self::reviewSandbox($uid)) {
            $who = self::name($uid); $whom = self::name($target);
            self::bell(self::reviewers($ctx['agency_id'], [$uid, $target]),
                '⛔ ' . $who . ' blocked ' . $whom,
                $who . ' blocked ' . $whom . ' in messages. ' . $whom . '’s messages are hidden from ' . explode(' ', $who)[0] . ', except emergencies.',
                ['kind' => $d['kind'], 'conversation_id' => (int) $d['conversation_id']]);
        }
        return response()->json(['ok' => true, 'blocked' => true]);
    }

    /** DELETE /me/blocks/{user} */
    public function unblock(Request $request, int $user): JsonResponse
    {
        $uid = (int) $request->user()->id;
        DB::table('user_blocks')->where('user_id', $uid)->where('blocked_user_id', $user)->delete();
        UserBlocks::forget($uid);
        return response()->json(['ok' => true, 'blocked' => false]);
    }

    /** GET /me/blocks - the people I have blocked. */
    public function myBlocks(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $rows = DB::table('user_blocks as b')->join('users as u', 'u.id', '=', 'b.blocked_user_id')
            ->where('b.user_id', $uid)->orderBy('u.first_name')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'b.created_at']);
        return response()->json(['blocks' => $rows->map(fn ($r) => [
            'user_id' => (int) $r->id, 'name' => trim($r->first_name . ' ' . $r->last_name), 'since' => $r->created_at,
        ])->values()]);
    }

    /** The agency this admin reviews - same rule as ChatAuditController (platform: the active agency). */
    private function adminAgency(Request $request): ?int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        if (\App\Support\UserRoles::has($request, 'platform_admin')) {
            return ($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists()) ? $active : null;
        }
        $admin = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('active', true);
        if ($active && (clone $admin)->where('agency_id', $active)->exists()) return $active;
        return (int) ($admin->value('agency_id') ?: 0) ?: null;
    }

    /** GET /admin/content-reports?status=open|handled|all */
    public function adminIndex(Request $request): JsonResponse
    {
        $agencyId = $this->adminAgency($request);
        if (! $agencyId) return response()->json(['message' => 'Reported messages are for agency admins.', 'reports' => []], 403);
        $status = $request->query('status', 'open');
        $q = DB::table('content_reports')->where('agency_id', $agencyId)->orderByDesc('id')->limit(200);
        if (in_array($status, ['open', 'handled'], true)) $q->where('status', $status);
        $rows = $q->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('reporter_id')->merge($rows->pluck('reported_user_id'))->merge($rows->pluck('handled_by'))->filter()->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $nm = fn ($id) => $id && isset($names[$id]) ? trim($names[$id]->first_name . ' ' . $names[$id]->last_name) : null;
        return response()->json([
            'open' => DB::table('content_reports')->where('agency_id', $agencyId)->where('status', 'open')->count(),
            'reports' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'kind' => $r->kind, 'conversation_id' => (int) $r->conversation_id,
                'reporter' => $nm($r->reporter_id), 'reported' => $nm($r->reported_user_id),
                'reason' => self::REASONS[$r->reason] ?? $r->reason, 'note' => $r->note, 'excerpt' => $r->excerpt,
                'blocked' => (bool) $r->blocked, 'status' => $r->status, 'created_at' => $r->created_at,
                'handled_by' => $nm($r->handled_by), 'handled_note' => $r->handled_note, 'handled_at' => $r->handled_at,
            ])->values(),
        ]);
    }

    /** PATCH /admin/content-reports/{id} {status: handled|open, note?} */
    public function adminUpdate(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->adminAgency($request);
        $d = $request->validate(['status' => 'required|in:open,handled', 'note' => 'nullable|string|max:1000']);
        $n = DB::table('content_reports')->where('id', $id)->where('agency_id', $agencyId ?: -1)->update([
            'status' => $d['status'],
            'handled_by' => $d['status'] === 'handled' ? (int) $request->user()->id : null,
            'handled_at' => $d['status'] === 'handled' ? now() : null,
            'handled_note' => $d['note'] ?? null,
        ]);
        return $n ? response()->json(['ok' => true]) : response()->json(['message' => 'Not found'], 404);
    }
}
