<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Staff-to-staff direct messaging (#38) — 1:1 threads within an agency. Colleagues
 * (admins, directors, educators, home visitors) can message each other, separate
 * from the family/parent chat. Participants model, so groups are a later add.
 */
final class TeamChatController extends Controller
{
    private const STAFF_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'auditor', 'platform_admin'];

    /**
     * Everyone in the agency who can be messaged — staff AND parents.
     *
     * Parents were excluded because family conversations live in the separate parent chat.
     * But an admin or director regularly needs to reach a parent about something that is
     * not a particular child's day, and had no way to start that from here.
     */
    private const MESSAGEABLE_ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'auditor', 'platform_admin', 'guardian'];

    /** Is this a platform admin — somebody whose remit is every agency? */
    private function isPlatformAdmin(int $uid): bool
    {
        return DB::table('role_assignments')->where('user_id', $uid)
            ->where('role', 'platform_admin')->where('active', true)->exists();
    }

    /** Agencies the current user belongs to (direct role or via a centre). */
    private function myAgencyIds(int $uid): array
    {
        // A platform admin's role row carries agency_id NULL — the point of the role is
        // that it is not tied to one — so this returned nothing for them and they could
        // message nobody at all. Anthony only had contacts because he ALSO holds an
        // agency_admin role at agency 2; a pure platform admin got an empty list.
        if ($this->isPlatformAdmin($uid)) {
            return DB::table('agencies')->pluck('id')->map(fn ($i) => (int) $i)->values()->all();
        }

        $direct = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('agency_id')->pluck('agency_id');
        $viaCentre = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)->whereNotNull('ra.centre_id')->pluck('c.agency_id');
        return $direct->merge($viaCentre)->unique()->filter()->values()->all();
    }

    /** User ids of staff colleagues in the given agencies (excludes $exclude). */
    private function staffUserIds(array $agencyIds, int $exclude): array
    {
        if (! $agencyIds) return [];
        $centreIds = DB::table('centres')->whereIn('agency_id', $agencyIds)->pluck('id')->all();
        return DB::table('role_assignments')->where('active', true)
            ->whereIn('role', self::MESSAGEABLE_ROLES)
            ->where(function ($q) use ($agencyIds, $centreIds) {
                $q->whereIn('agency_id', $agencyIds)->orWhereIn('centre_id', $centreIds ?: [0]);
            })
            ->where('user_id', '!=', $exclude)
            ->pluck('user_id')->unique()->filter()->values()->all();
    }

    /** The agency a user actually belongs to, for filing a thread against. */
    private function agencyOfUser(int $uid): ?int
    {
        $direct = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        if ($direct) {
            return (int) $direct;
        }
        $viaCentre = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)->value('c.agency_id');

        return $viaCentre ? (int) $viaCentre : null;
    }

    /** GET /provider/team-contacts — colleagues the user can start a chat with. */
    public function contacts(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $ids = $this->staffUserIds($this->myAgencyIds($uid), $uid);
        if (! $ids) return response()->json(['contacts' => []]);

        // A user may hold several roles — show the most senior for the label.
        // Lowest rank wins, so somebody who is both a parent and staff shows as staff —
        // that is the hat they are being messaged in here. Parents rank last so the
        // colleagues people message daily stay at the top of the list.
        $rank = ['agency_admin' => 0, 'platform_admin' => 0, 'centre_director' => 1, 'home_visitor' => 2, 'educator' => 3, 'auditor' => 4, 'guardian' => 5];
        $roleBy = [];
        foreach (DB::table('role_assignments')->whereIn('user_id', $ids)->where('active', true)->whereIn('role', self::MESSAGEABLE_ROLES)->get(['user_id', 'role']) as $r) {
            $cur = $roleBy[$r->user_id] ?? null;
            if ($cur === null || ($rank[$r->role] ?? 9) < ($rank[$cur] ?? 9)) $roleBy[$r->user_id] = $r->role;
        }
        $label = ['agency_admin' => 'Admin', 'platform_admin' => 'Admin', 'centre_director' => 'Director', 'educator' => 'Educator', 'home_visitor' => 'Home visitor', 'auditor' => 'Auditor', 'guardian' => 'Parent'];

        $contacts = DB::table('users')->whereIn('id', $ids)->whereNull('deleted_at')
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'photo_url'])
            ->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Colleague',
                'photo_url' => $u->photo_url,
                'role'      => $label[$roleBy[$u->id] ?? ''] ?? 'Staff',
            ])->values();
        return response()->json(['contacts' => $contacts]);
    }

    /** GET /provider/team-threads — the user's 1:1 threads with previews + unread. */
    public function threads(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $mine = DB::table('staff_thread_participants')->where('user_id', $uid)->pluck('thread_id')->all();
        if (! $mine) return response()->json(['threads' => []]);

        $myPart = DB::table('staff_thread_participants')->where('user_id', $uid)->whereIn('thread_id', $mine)
            ->pluck('last_read_at', 'thread_id');

        // The OTHER participant of each thread (1:1).
        $others = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.thread_id', $mine)->where('p.user_id', '!=', $uid)
            ->get(['p.thread_id', 'u.id as uid', 'u.first_name', 'u.last_name', 'u.photo_url'])
            ->keyBy('thread_id');

        $threads = DB::table('staff_threads')->whereIn('id', $mine)->orderByDesc('last_message_at')->get();
        $out = [];
        foreach ($threads as $t) {
            $o = $others[$t->id] ?? null;
            $last = DB::table('staff_messages')->where('thread_id', $t->id)->orderByDesc('id')->first(['body', 'created_at', 'sender_id']);
            $readAt = $myPart[$t->id] ?? null;
            $unread = DB::table('staff_messages')->where('thread_id', $t->id)->where('sender_id', '!=', $uid)
                ->when($readAt, fn ($q) => $q->where('created_at', '>', $readAt))->count();
            $out[] = [
                'id'         => $t->id,
                'name'       => $o ? (trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')) ?: 'Colleague') : 'Colleague',
                'photo_url'  => $o->photo_url ?? null,
                'other_id'   => $o->uid ?? null,
                'preview'    => $last ? mb_substr(($last->sender_id == $uid ? 'You: ' : '') . strip_tags((string) $last->body), 0, 80) : '',
                'at'         => $last->created_at ?? $t->last_message_at,
                'unread'     => $unread,
            ];
        }
        return response()->json(['threads' => $out]);
    }

    /** POST /provider/team-threads/start {recipient_user_id, body} — find/create 1:1. */
    public function start(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $data = $request->validate([
            'recipient_user_id' => ['required', 'integer'],
            'body'              => ['required', 'string', 'max:5000'],
        ]);
        $recipient = (int) $data['recipient_user_id'];
        if ($recipient === $uid) return response()->json(['message' => 'You can’t message yourself.'], 422);

        // Recipient must be a colleague in a shared agency.
        if (! in_array($recipient, $this->staffUserIds($this->myAgencyIds($uid), $uid), true)) {
            return response()->json(['message' => 'That person isn’t a colleague you can message.'], 403);
        }
        // Stamp the thread with the RECIPIENT's agency, not the sender's first one. For a
        // platform admin the sender's list is every agency, so taking the first would file
        // a conversation with an agency-6 director under agency 2.
        $agencyId = $this->agencyOfUser($recipient) ?? ($this->myAgencyIds($uid)[0] ?? null);

        // Existing 1:1 thread = a thread both are in, with exactly 2 participants.
        $threadId = DB::table('staff_thread_participants as a')
            ->join('staff_thread_participants as b', 'a.thread_id', '=', 'b.thread_id')
            ->where('a.user_id', $uid)->where('b.user_id', $recipient)
            ->whereRaw('(SELECT COUNT(*) FROM staff_thread_participants p WHERE p.thread_id = a.thread_id) = 2')
            ->value('a.thread_id');

        $now = now();
        if (! $threadId) {
            $threadId = DB::table('staff_threads')->insertGetId([
                'agency_id' => $agencyId, 'created_by' => $uid, 'last_message_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ([$uid, $recipient] as $p) {
                DB::table('staff_thread_participants')->insert([
                    'thread_id' => $threadId, 'user_id' => $p, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $this->post($uid, (int) $threadId, (string) $data['body'], $now);
        return response()->json(['thread_id' => $threadId], 201);
    }

    /** GET /provider/team-threads/{thread} — messages; marks the thread read. */
    public function show(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $msgs = DB::table('staff_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.thread_id', $thread)->orderBy('m.id')
            ->get(['m.id', 'm.sender_id', 'm.body', 'm.created_at', 'u.first_name', 'u.last_name', 'u.photo_url'])
            ->map(fn ($m) => [
                'id'      => $m->id,
                'mine'    => $m->sender_id == $uid,
                'sender'  => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) ?: 'Colleague',
                'photo'   => $m->photo_url,
                'body'    => $m->body,
                'at'      => $m->created_at,
            ])->values();

        DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', $uid)
            ->update(['last_read_at' => now(), 'updated_at' => now()]);

        $other = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.thread_id', $thread)->where('p.user_id', '!=', $uid)
            ->first(['u.first_name', 'u.last_name']);
        return response()->json([
            'thread_id' => $thread,
            'name'      => $other ? (trim(($other->first_name ?? '') . ' ' . ($other->last_name ?? '')) ?: 'Colleague') : 'Colleague',
            'messages'  => $msgs,
        ]);
    }

    /** POST /provider/team-threads/{thread}/send {body}. */
    public function send(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $id = $this->post($uid, $thread, (string) $data['body'], now());
        return response()->json(['id' => $id], 201);
    }

    /** GET /provider/team-threads/unread-count — total unread for the nav badge. */
    public function unreadCount(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $parts = DB::table('staff_thread_participants')->where('user_id', $uid)->get(['thread_id', 'last_read_at']);
        $total = 0;
        foreach ($parts as $p) {
            $total += DB::table('staff_messages')->where('thread_id', $p->thread_id)->where('sender_id', '!=', $uid)
                ->when($p->last_read_at, fn ($q) => $q->where('created_at', '>', $p->last_read_at))->count();
        }
        return response()->json(['unread' => $total]);
    }

    private function isParticipant(int $uid, int $thread): bool
    {
        return DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', $uid)->exists();
    }

    private function post(int $uid, int $thread, string $body, $now): int
    {
        $id = DB::table('staff_messages')->insertGetId([
            'thread_id' => $thread, 'sender_id' => $uid, 'body' => trim($body),
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $thread)->update(['last_message_at' => $now, 'updated_at' => $now]);
        // Notify the other participant(s) so it shows in their bell/inbox.
        try {
            $others = DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', '!=', $uid)->pluck('user_id');
            $me = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
            $meName = trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: 'A colleague';
            foreach ($others as $oid) {
                DB::table('notifications')->insert([
                    'user_id' => (int) $oid, 'type' => 'team_message',
                    'title'   => '💬 New team message',
                    'body'    => $meName . ': ' . mb_substr(strip_tags($body), 0, 140),
                    'data'    => json_encode(['thread_id' => $thread]),
                    'created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) { /* best-effort */ }
        return $id;
    }
}
