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
    private function myAgencyIds(int $uid, ?Request $request = null): array
    {
        // A platform admin's role row carries agency_id NULL — the point of the role is
        // that it is not tied to one — so this returned nothing for them and they could
        // message nobody at all.
        //
        // They get the agency they are VIEWING, not every agency. Returning all of them
        // put 89 people from two agencies in one list: a super admin looking at iLearn
        // could see, and start a conversation with, a parent from another agency, and the
        // name and role of every user on the platform was exposed in that picker.
        // Switching agency changed nothing, which is the tell.
        //
        // Fails CLOSED: no active agency and no agency of their own means no contacts,
        // rather than everybody.
        if ($this->isPlatformAdmin($uid)) {
            // Taken from the request that was handed in, not the global helper: the
            // active agency is a property of THIS call, and reaching for a global makes
            // the scoping depend on container state rather than on the caller.
            $active = (int) (($request ? $request->header('X-Active-Agency-Id') : null) ?: 0);
            if ($active && DB::table('agencies')->where('id', $active)->exists()) {
                return [$active];
            }
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
        /* Colleagues and families are different questions with different answers, so
           they are asked separately. One agency-wide rule for both let an educator at
           a single centre start a chat with every guardian in the agency. */
        $staffRoles = array_values(array_diff(self::MESSAGEABLE_ROLES, ['guardian']));
        $staff = DB::table('role_assignments')->where('active', true)
            ->whereIn('role', $staffRoles)
            ->where(function ($q) use ($agencyIds, $centreIds) {
                $q->whereIn('agency_id', $agencyIds)
                  ->orWhereIn('centre_id', $centreIds ?: [0])
                  // A platform admin's row has NULL agency_id and NULL centre_id —
                  // their remit is every agency — so neither clause above can match
                  // them and a superadmin was reachable by nobody.
                  ->orWhere('role', 'platform_admin');
            })
            ->where('user_id', '!=', $exclude)
            ->pluck('user_id')->unique()->filter()->values()->all();

        /* A service account is not a colleague. integration+… carries an agency_admin
           role, so without this it appears in "who can I message" and can be sent a
           chat nobody will ever read. */
        $staff = self::withoutServiceAccounts($staff);

        return array_values(array_unique(array_merge(
            $staff,
            $this->reachableGuardianIds($exclude, $centreIds)
        )));
    }

    /**
     * Guardians this person may start a conversation with.
     *
     * An educator or home visitor reaches the families at the centres they are
     * assigned to — not the whole agency. Agency and platform admins keep the wider
     * view, because that genuinely is their remit.
     *
     * Assignment is read from role_assignments.centre_id, which is what the data
     * actually records. A narrower link (room, keyworker) is not modelled, and
     * inventing one would lock out people who should have access.
     */
    private function reachableGuardianIds(int $uid, array $agencyCentreIds): array
    {
        $isWide = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();

        $centreIds = $isWide
            ? $agencyCentreIds
            : DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->whereNotNull('centre_id')->pluck('centre_id')->all();

        if (empty($centreIds)) {
            return [];   // no centre, no families — fail closed
        }

        return DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('f.deleted_at')
            ->whereNotNull('g.user_id')
            ->where('g.user_id', '!=', $uid)
            ->pluck('g.user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * Strip integration / no-reply accounts from a set of user ids.
     *
     * These hold real roles so they match ordinary queries, but they are inboxes, not
     * people: they cannot read a chat, and mailing them is noise.
     */
    public static function withoutServiceAccounts(array $ids): array
    {
        if (! $ids) {
            return $ids;
        }

        return DB::table('users')->whereIn('id', $ids)
            ->where(function ($q) {
                $q->whereNull('email')
                  ->orWhere(function ($w) {
                      $w->where('email', 'not like', '%integration+%')
                        ->where('email', 'not like', 'noreply@%')
                        ->where('email', 'not like', 'no-reply@%');
                  });
            })
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
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
        $ids = $this->staffUserIds($this->myAgencyIds($uid, $request), $uid);
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
            /* email comes back so the picker can tell apart people who share a name —
               one person legitimately holds several accounts here (educator, admin,
               parent), and four identical rows are unpickable. (2026-08-26) */
            ->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'photo_url', 'email'])
            ->map(fn ($u) => [
                'id'        => $u->id,
                'name'      => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'Colleague',
                'photo_url' => $u->photo_url,
                'role'      => $label[$roleBy[$u->id] ?? ''] ?? 'Staff',
                'email'     => $u->email,
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

        /* Everyone else in each thread. A group has several, so this is grouped rather
           than keyed — keyBy() silently kept ONE row per thread, which for a group meant
           the list showed a random member's name and the rest vanished. */
        $everyOther = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->whereIn('p.thread_id', $mine)->where('p.user_id', '!=', $uid)
            ->orderBy('u.first_name')
            ->get(['p.thread_id', 'u.id as uid', 'u.first_name', 'u.last_name', 'u.photo_url',
                   'u.last_seen_at',
                   'p.last_read_at as other_read_at'])
            ->groupBy('thread_id');
        // The 1:1 shape the rest of this method already expects: the single counterpart.
        $others = $everyOther->map(fn ($g) => $g->first());

        // Who each counterpart IS, so the list can say "Sarah Mitchell (Educator)".
        // One lookup for everybody rather than one per thread. Lowest rank wins, so a
        // person who is both a parent and staff shows as staff - the hat they are being
        // messaged in here.
        $otherIds = $everyOther->flatten(1)->pluck('uid')->filter()->unique()->all();
        $rank = ['agency_admin' => 0, 'platform_admin' => 0, 'centre_director' => 1,
            'home_visitor' => 2, 'educator' => 3, 'auditor' => 4, 'guardian' => 5];
        $roleLabel = ['agency_admin' => 'Admin', 'platform_admin' => 'Admin', 'centre_director' => 'Director',
            'educator' => 'Educator', 'home_visitor' => 'Home visitor', 'auditor' => 'Auditor', 'guardian' => 'Parent'];
        $roleBy = [];
        if ($otherIds) {
            foreach (DB::table('role_assignments')->whereIn('user_id', $otherIds)
                ->where('active', true)->get(['user_id', 'role']) as $ra) {
                $cur = $roleBy[$ra->user_id] ?? null;
                if ($cur === null || ($rank[$ra->role] ?? 9) < ($rank[$cur] ?? 9)) {
                    $roleBy[$ra->user_id] = $ra->role;
                }
            }
        }

        /* Which threads the OTHER person has ever spoken in. Same rule the family list
           uses: a reply is a fact about the thread, not about whichever message was
           last, so it stays visible after you answer them. */
        $repliedThreads = DB::table('staff_messages')->whereIn('thread_id', $mine)
            ->where('sender_id', '!=', $uid)->distinct()->pluck('thread_id')
            ->map(fn ($v) => (int) $v)->all();

        $threads = DB::table('staff_threads')->whereIn('id', $mine)->orderByDesc('last_message_at')->get();
        $out = [];
        foreach ($threads as $t) {
            $o = $others[$t->id] ?? null;
            $last = DB::table('staff_messages')->where('thread_id', $t->id)->orderByDesc('id')->first(['body', 'created_at', 'sender_id', 'is_system']);
            $readAt = $myPart[$t->id] ?? null;
            $unread = DB::table('staff_messages')->where('thread_id', $t->id)->where('sender_id', '!=', $uid)
                ->when($readAt, fn ($q) => $q->where('created_at', '>', $readAt))->count();
            /* Read state for the colleague list, in the same words the family rows use,
               so the Status column means one thing everywhere. 'sent' not 'delivered'
               when unread: staff threads have no device receipt, and we should not
               claim a delivery we never observed. */
            $lastFrom = null;
            $lastStatus = null;
            if ($last) {
                $isMine = ((int) $last->sender_id === $uid);
                $lastFrom = $isMine ? 'centre' : 'family';
                if ($isMine) {
                    $oRead = $o->other_read_at ?? null;
                    $lastStatus = ($oRead && strtotime((string) $oRead) >= strtotime((string) $last->created_at))
                        ? 'read' : 'sent';
                }
            }

            $members = ($everyOther[$t->id] ?? collect())->map(fn ($m) => [
                'id'    => (int) $m->uid,
                'name'  => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) ?: 'Colleague',
                'photo' => $m->photo_url,
            ])->values()->all();
            $isGroup = (bool) ($t->is_group ?? false);

            $out[] = [
                'id'         => $t->id,
                'last_from'   => $lastFrom,
                'last_status' => $lastStatus,
                'has_replied' => in_array((int) $t->id, $repliedThreads, true),
                'is_group'   => $isGroup,
                'title'      => $t->title ?? null,
                // +1 for me: "3 members" should count everyone in the room.
                'member_count' => count($members) + 1,
                'members'    => $members,
                'name'       => $isGroup
                    ? (trim((string) ($t->title ?? '')) ?: self::memberNames($members))
                    : ($o ? (trim(($o->first_name ?? '') . ' ' . ($o->last_name ?? '')) ?: 'Colleague') : 'Colleague'),
                'photo_url'  => $isGroup ? null : ($o->photo_url ?? null),
                'presence'   => $isGroup ? null : \App\Support\Presence::state($o->last_seen_at ?? null),
                'other_id'   => $isGroup ? null : ($o->uid ?? null),
                'role'       => $isGroup ? null : ($roleLabel[$roleBy[$o->uid ?? 0] ?? ''] ?? 'Staff'),
                // "You: Emily added Olivia" — a system line is the thread narrating
                // itself, so it never takes a speaker prefix.
                'preview'    => $last ? mb_substr((($last->sender_id == $uid && ! $last->is_system) ? 'You: ' : '') . strip_tags((string) $last->body), 0, 80) : '',
                'at'         => $last->created_at ?? $t->last_message_at,
                'unread'     => $unread,
            ];
        }
        return response()->json(['threads' => $out]);
    }

    /**
     * An unnamed group's fallback label: the members, then "+N" once the list would be
     * longer than a row. Never an empty string — a thread with no name at all reads as
     * a broken row rather than as a group somebody forgot to title.
     *
     * @param  array<int,array{id:int,name:string,photo:?string}>  $members  everyone but me
     */
    private static function memberNames(array $members): string
    {
        if (! $members) return 'Group';
        $first = array_map(fn ($m) => explode(' ', trim($m['name']))[0], array_slice($members, 0, 3));
        $rest = count($members) - count($first);
        return implode(', ', $first) . ($rest > 0 ? ' +' . $rest : '');
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
        if (! in_array($recipient, $this->staffUserIds($this->myAgencyIds($uid, $request), $uid), true)) {
            return response()->json(['message' => 'That person isn’t a colleague you can message.'], 403);
        }
        // Stamp the thread with the RECIPIENT's agency, not the sender's first one. For a
        // platform admin the sender's list is every agency, so taking the first would file
        // a conversation with an agency-6 director under agency 2.
        $agencyId = $this->agencyOfUser($recipient) ?? ($this->myAgencyIds($uid, $request)[0] ?? null);

        // One implementation, shared with the broadcast path — see PrivateThreads.
        $threadId = \App\Support\PrivateThreads::findOrCreate($uid, $recipient, $agencyId);
        $now = now();
        $this->post($uid, (int) $threadId, (string) $data['body'], $now);
        return response()->json(['thread_id' => $threadId], 201);
    }

    /**
     * POST /provider/team-threads/group {user_ids[], title, body?} — start a group.
     *
     * Deliberately NOT routed through PrivateThreads::findOrCreate: that returns an
     * existing 1:1 when one exists, so creating "Toddler Room Team" with a colleague you
     * already DM would have posted the group's opening message into your private thread.
     * A group is always a new thread.
     */
    public function startGroup(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $data = $request->validate([
            'user_ids'   => ['required', 'array', 'min:2', 'max:50'],
            'user_ids.*' => ['integer'],
            'title'      => ['required', 'string', 'max:80'],
            'body'       => ['nullable', 'string', 'max:5000'],
        ]);

        $ids = $this->vetColleagues($request, $uid, $data['user_ids']);
        if ($ids instanceof JsonResponse) return $ids;
        if (count($ids) < 2) {
            return response()->json(['message' => 'A group needs at least two other people.'], 422);
        }

        // The agency of the people in it, not the creator's first one — a platform admin
        // belongs to every agency, so "the first" would file the group under the wrong one.
        $agencyId = $this->agencyOfUser($ids[0]) ?? ($this->myAgencyIds($uid, $request)[0] ?? null);
        $now = now();

        $threadId = (int) DB::table('staff_threads')->insertGetId([
            'agency_id' => $agencyId,
            'title' => trim($data['title']),
            'is_group' => true,
            'created_by' => $uid,
            'last_message_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        foreach (array_merge([$uid], $ids) as $pid) {
            DB::table('staff_thread_participants')->insert([
                'thread_id' => $threadId, 'user_id' => $pid,
                // The creator has read their own opening message.
                'last_read_at' => $pid === $uid ? $now : null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        $this->systemLine($threadId, $uid, $this->nameOf($uid) . ' created “' . trim($data['title']) . '”', $now);
        if (trim((string) ($data['body'] ?? '')) !== '') {
            $this->post($uid, $threadId, (string) $data['body'], now());
        }

        return response()->json(['thread_id' => $threadId], 201);
    }

    /**
     * POST /provider/team-threads/{thread}/participants {user_ids[]} — add people.
     *
     * Adding a third person to a 1:1 PROMOTES it to a group rather than refusing: that is
     * what "add someone to this conversation" means to the person doing it. The old 1:1 is
     * not lost to them either — PrivateThreads::findOrCreate matches only threads with
     * exactly two participants, so a later private DM opens a fresh thread instead of
     * landing in front of the group.
     *
     * Everyone added can read the WHOLE history, because nothing filters messages by when
     * a participant joined. That is the intended behaviour (Anthony, 2026-08-30: "show
     * history of conversation as well") and the add dialog says so before you confirm.
     */
    public function addParticipants(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        // Only someone already in the room may bring somebody into it.
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'user_ids'   => ['required', 'array', 'min:1', 'max:50'],
            'user_ids.*' => ['integer'],
        ]);

        $ids = $this->vetColleagues($request, $uid, $data['user_ids']);
        if ($ids instanceof JsonResponse) return $ids;

        $row = DB::table('staff_threads')->where('id', $thread)->first();
        if (! $row) return response()->json(['message' => 'Not found'], 404);

        /* The thread's own agency is the bound, not the caller's. A platform admin is a
           colleague of everybody, so vetting against the CALLER alone would let them pull
           an agency-2 educator into an agency-6 group. */
        if ($row->agency_id) {
            $outsiders = [];
            foreach ($ids as $id) {
                if ((int) ($this->agencyOfUser($id) ?? 0) !== (int) $row->agency_id) $outsiders[] = $id;
            }
            if ($outsiders) {
                return response()->json(['message' => 'Those people are not in this conversation’s agency.'], 403);
            }
        }

        $already = DB::table('staff_thread_participants')->where('thread_id', $thread)
            ->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        $fresh = array_values(array_diff($ids, $already));
        if (! $fresh) {
            return response()->json(['message' => 'They are already in this conversation.', 'added' => 0], 200);
        }

        $now = now();
        foreach ($fresh as $pid) {
            DB::table('staff_thread_participants')->insert([
                'thread_id' => $thread, 'user_id' => $pid,
                // Null, not now(): everything said before they arrived is theirs to read,
                // and marking it read on their behalf would hide it behind a zero badge.
                'last_read_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        // A 1:1 that just gained a third person is a group from here on.
        if (! $row->is_group) {
            $names = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.thread_id', $thread)->orderBy('u.first_name')->pluck('u.first_name')->all();
            DB::table('staff_threads')->where('id', $thread)->update([
                'is_group' => true,
                'title' => $row->title ?: mb_substr(implode(', ', $names), 0, 80),
                'updated_at' => $now,
            ]);
        }

        $addedNames = array_map(fn ($id) => $this->nameOf($id), $fresh);
        $this->systemLine($thread, $uid,
            $this->nameOf($uid) . ' added ' . $this->listNames($addedNames), $now);
        $this->notifyAdded($fresh, $uid, $thread, $now);

        return response()->json(['added' => count($fresh)], 201);
    }

    /**
     * POST /provider/team-threads/{thread}/leave — take yourself out of a group.
     *
     * A group with no way out is a room you can be put in and never escape, which is why
     * this shipped alongside the ability to add people rather than after it. Leaving is
     * always your own decision, so it needs no permission beyond being in the thread.
     *
     * Only groups. Leaving a 1:1 would leave the other person talking to nobody with no
     * indication why — archiving is what that case already has.
     */
    public function leave(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $row = DB::table('staff_threads')->where('id', $thread)->first();
        if (! $row || ! $row->is_group) {
            return response()->json(['message' => 'You can only leave a group conversation.'], 422);
        }

        $now = now();
        // The line goes in BEFORE the row comes out: systemLine() touches the thread, and
        // writing it afterwards would be a message posted by someone no longer in it.
        $this->systemLine($thread, $uid, $this->nameOf($uid) . ' left', $now);
        DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', $uid)->delete();

        return response()->json(['left' => true], 200);
    }

    /**
     * DELETE /provider/team-threads/{thread}/participants/{user} — remove someone else.
     *
     * Deliberately NARROWER than adding. Anyone in the room may bring a colleague in;
     * cutting somebody's access to a conversation they have been part of is the group
     * OWNER's call, or a director's/admin's. Without that an educator could remove their
     * director from a thread about them.
     *
     * Note the rule is "a MEMBER who is also the creator or a director", not "any
     * director": the isParticipant gate runs first, so a director who is not in the group
     * gets the same 404 as anyone else. They cannot read it either, and reaching into a
     * conversation you cannot see is not a power worth having.
     */
    public function removeParticipant(Request $request, int $thread, int $user): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        // Removing yourself is leaving, and leaving needs no permission — so route it
        // there rather than refusing on the ownership rule below.
        if ($user === $uid) return $this->leave($request, $thread);

        $row = DB::table('staff_threads')->where('id', $thread)->first();
        if (! $row || ! $row->is_group) {
            return response()->json(['message' => 'Only a group conversation has members to remove.'], 422);
        }
        if (! $this->canManageGroup($request, $uid, $row)) {
            return response()->json(['message' => 'Only the person who created this group, or a director in it, can remove someone.'], 403);
        }
        if (! $this->isParticipant($user, $thread)) {
            return response()->json(['message' => 'They are not in this conversation.'], 422);
        }

        $now = now();
        DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', $user)->delete();
        $this->systemLine($thread, $uid, $this->nameOf($uid) . ' removed ' . $this->nameOf($user), $now);

        /* Told, but not pushed. Losing access silently is confusing — you would just find
           the conversation gone — while a phone banner announcing it would be a needless
           sting. The in-app row is the honest middle. */
        try {
            DB::table('notifications')->insert([
                'user_id' => $user, 'type' => 'team_message',
                'title'   => '👥 Removed from a group',
                'body'    => $this->nameOf($uid) . ' removed you from “' . ($row->title ?: 'a group conversation') . '”.',
                'data'    => json_encode([]),
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) { /* best-effort */ }

        return response()->json(['removed' => true], 200);
    }

    /**
     * PATCH /provider/team-threads/{thread} {title} — rename a group.
     *
     * Any participant may. A name is cosmetic, shared, reversible and visible to everyone
     * the moment it changes — none of which is true of removing a person, which is why
     * that one is restricted and this is not.
     */
    public function rename(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $row = DB::table('staff_threads')->where('id', $thread)->first();
        if (! $row || ! $row->is_group) {
            return response()->json(['message' => 'Only a group conversation has a name.'], 422);
        }

        $data = $request->validate(['title' => ['required', 'string', 'max:80']]);
        $title = trim($data['title']);
        if ($title === '') return response()->json(['message' => 'Give the group a name.'], 422);
        if ($title === (string) $row->title) return response()->json(['renamed' => false], 200);

        $now = now();
        DB::table('staff_threads')->where('id', $thread)->update(['title' => $title, 'updated_at' => $now]);
        $this->systemLine($thread, $uid, $this->nameOf($uid) . ' renamed the group to “' . $title . '”', $now);

        return response()->json(['renamed' => true, 'title' => $title], 200);
    }

    /**
     * May this person restructure the group — i.e. remove somebody from it? The creator,
     * or anyone senior enough to be running the centre. Checked against the roles the
     * caller actually holds, not against a role name passed in.
     */
    private function canManageGroup(Request $request, int $uid, $row): bool
    {
        if ((int) ($row->created_by ?? 0) === $uid) return true;
        $roles = \App\Support\UserRoles::names($request);
        foreach (['platform_admin', 'agency_admin', 'centre_director'] as $r) {
            if (in_array($r, $roles, true)) return true;
        }
        return false;
    }

    /**
     * The subset of $ids that really are colleagues the caller may message, or a 403.
     * One gate for both group paths — the 1:1 start() check was inline, and a second
     * inline copy is how one of them ends up not being updated.
     *
     * @return array<int,int>|JsonResponse
     */
    private function vetColleagues(Request $request, int $uid, array $ids)
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, fn ($i) => $i > 0 && $i !== $uid));
        if (! $ids) return response()->json(['message' => 'Pick at least one colleague.'], 422);

        $allowed = $this->staffUserIds($this->myAgencyIds($uid, $request), $uid);
        $bad = array_values(array_diff($ids, $allowed));
        if ($bad) {
            return response()->json(['message' => 'Someone you picked isn’t a colleague you can message.'], 403);
        }
        return $ids;
    }

    /** A message that is part of the conversation but from nobody — "X added Y". */
    private function systemLine(int $thread, int $uid, string $text, $now): void
    {
        DB::table('staff_messages')->insert([
            'thread_id' => $thread, 'sender_id' => $uid, 'body' => $text,
            'is_system' => true, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $thread)->update(['last_message_at' => $now, 'updated_at' => $now]);
    }

    private function nameOf(int $uid): string
    {
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
        return $u ? (trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: 'A colleague') : 'A colleague';
    }

    /** "Marcus", "Marcus and Priya", "Marcus, Priya and Olivia". */
    private function listNames(array $names): string
    {
        if (count($names) <= 1) return $names[0] ?? 'someone';
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** Tell the people who were just added, so the group does not appear silently. */
    private function notifyAdded(array $ids, int $byUid, int $thread, $now): void
    {
        $title = DB::table('staff_threads')->where('id', $thread)->value('title') ?: 'a group conversation';
        $by = $this->nameOf($byUid);
        foreach ($ids as $pid) {
            try {
                app(\App\Services\WebPushService::class)->sendToUsers([(int) $pid], [
                    'title' => '👥 Added to ' . $title,
                    'body'  => $by . ' added you to ' . $title . '.',
                    'icon'  => '/icon-192.png',
                    'url'   => '/dashboard.html#team-messages',
                    'tag'   => 'team-' . $thread,
                ]);
            } catch (\Throwable $e) { /* best-effort */ }
            try {
                app(\App\Services\FcmService::class)->sendToUser((int) $pid,
                    '👥 Added to ' . $title, $by . ' added you to ' . $title . '.', '#team-messages', true, true);
            } catch (\Throwable $e) { /* best-effort */ }

            DB::table('notifications')->insert([
                'user_id' => (int) $pid, 'type' => 'team_message',
                'title'   => '👥 Added to a group',
                'body'    => $by . ' added you to “' . $title . '”.',
                'data'    => json_encode(['thread_id' => $thread]),
                'created_at' => $now,
            ]);
        }
    }

    /** GET /provider/team-threads/{thread} — messages; marks the thread read. */
    public function show(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $msgs = DB::table('staff_messages as m')->leftJoin('users as u', 'u.id', '=', 'm.sender_id')
            ->where('m.thread_id', $thread)->orderBy('m.id')
            // attachments must be SELECTED to be readable — the mapper below reads it, and
            // without it every thread read 500'd on an undefined property.
            ->get(['m.id', 'm.sender_id', 'm.body', 'm.attachments', 'm.is_system', 'm.created_at', 'u.first_name', 'u.last_name', 'u.photo_url'])
            ->map(fn ($m) => [
                'id'      => $m->id,
                // A system line belongs to nobody, so it must not render as my own bubble
                // just because I am the one who did the adding.
                'mine'    => ! $m->is_system && $m->sender_id == $uid,
                'system'  => (bool) $m->is_system,
                'sender'  => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')) ?: 'Colleague',
                'photo'   => $m->photo_url,
                'body'    => $m->body,
                'attachments' => $m->attachments ? (json_decode($m->attachments, true) ?: []) : [],
                'at'      => $m->created_at,
            ])->values();

        /* Reactions (2026-08-25). A colleague thread could not be reacted to at all:
           emoji were only ever built for family conversations, so when broadcasts moved
           to 1:1 staff threads the feature quietly went with them - "it was once there
           and now it isn't". Loaded in ONE query for the whole thread rather than one
           per message. */
        $msgIds = $msgs->pluck('id')->all();
        if ($msgIds) {
            $rx = DB::table('message_reactions')
                ->whereIn('message_id', $msgIds)->where('kind', 'staff')->get();
            $byMsg = [];
            foreach ($rx as $r) { $byMsg[(int) $r->message_id][] = $r; }
            $msgs = $msgs->map(function ($m) use ($byMsg, $uid) {
                $m['reactions'] = $this->groupReactions($byMsg[(int) $m['id']] ?? [], $uid);
                return $m;
            })->values();
        }

        /* When the OTHER person last read this thread — read BEFORE we stamp our own
           row below, and used to tick our sent messages. The colleague thread had no
           read indicator at all because this was never reported. */
        $otherReadAt = DB::table('staff_thread_participants')
            ->where('thread_id', $thread)->where('user_id', '!=', $uid)
            ->max('last_read_at');

        DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', $uid)
            ->update(['last_read_at' => now(), 'updated_at' => now()]);

        $row = DB::table('staff_threads')->where('id', $thread)->first();
        $isGroup = (bool) ($row->is_group ?? false);

        /* The roster. A group has to be able to say who is in it — without that, "who can
           see what I am about to type" is a guess, which is not a question to leave open
           in a work conversation. `joined` comes from the participant row so the header
           can show when somebody was brought in. */
        $people = DB::table('staff_thread_participants as p')->join('users as u', 'u.id', '=', 'p.user_id')
            ->where('p.thread_id', $thread)->orderBy('u.first_name')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.photo_url', 'p.created_at as joined'])
            ->map(fn ($p) => [
                'id'     => (int) $p->id,
                'name'   => trim(($p->first_name ?? '') . ' ' . ($p->last_name ?? '')) ?: 'Colleague',
                'photo'  => $p->photo_url,
                'joined' => $p->joined,
                'me'     => (int) $p->id === $uid,
            ])->values();

        $others = $people->where('me', false)->values();

        return response()->json([
            'thread_id' => $thread,
            'is_group'  => $isGroup,
            // What this caller may do, decided here rather than re-derived in the browser
            // from a role string — the buttons and the endpoints must agree.
            'can_manage' => $isGroup && $row ? $this->canManageGroup($request, $uid, $row) : false,
            'created_by' => $row->created_by ?? null,
            'title'     => $row->title ?? null,
            'name'      => $isGroup
                ? (trim((string) ($row->title ?? '')) ?: self::memberNames($others->all()))
                : ($others->first()['name'] ?? 'Colleague'),
            'participants'  => $people,
            'member_count'  => $people->count(),
            'other_read_at' => $otherReadAt,
            'messages'  => $msgs,
        ]);
    }

    /**
     * POST /provider/team-threads/{thread}/messages/{message}/react {emoji}
     *
     * Toggles: reacting with an emoji you already used removes it, exactly as the family
     * thread behaves. message_reactions carries a `kind` so one table serves both message
     * tables - a staff message id 30 and a family message id 30 are different things, and
     * without that column they would collide.
     */
    public function react(Request $request, int $thread, int $message): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate(['emoji' => ['required', 'string', 'max:8']]);
        $emoji = trim($data['emoji']);
        if ($emoji === '') return response()->json(['message' => 'Invalid emoji'], 422);

        // The message must belong to THIS thread - otherwise the thread guard above
        // means nothing and any message id could be reacted to.
        $belongs = DB::table('staff_messages')->where('id', $message)->where('thread_id', $thread)->exists();
        if (! $belongs) return response()->json(['message' => 'Not found'], 404);

        $existing = DB::table('message_reactions')->where('message_id', $message)
            ->where('kind', 'staff')->where('user_id', $uid)->where('emoji', $emoji)->first();

        if ($existing) {
            DB::table('message_reactions')->where('id', $existing->id)->delete();
        } else {
            DB::table('message_reactions')->insert([
                'message_id' => $message, 'kind' => 'staff', 'user_id' => $uid,
                'emoji' => $emoji, 'created_at' => now(),
            ]);
        }

        $rows = DB::table('message_reactions')->where('message_id', $message)->where('kind', 'staff')->get();
        return response()->json(['ok' => true, 'reactions' => $this->groupReactions($rows, $uid)]);
    }

    /** Same shape the family thread returns, so one front-end renderer serves both. */
    private function groupReactions($rows, int $userId): array
    {
        $grouped = [];
        foreach ($rows as $r) {
            if (! isset($grouped[$r->emoji])) {
                $grouped[$r->emoji] = ['emoji' => $r->emoji, 'count' => 0, 'mine' => false];
            }
            $grouped[$r->emoji]['count']++;
            if ((int) $r->user_id === $userId) $grouped[$r->emoji]['mine'] = true;
        }

        return array_values($grouped);
    }

    /** POST /provider/team-threads/{thread}/send {body}. */
    public function send(Request $request, int $thread): JsonResponse
    {
        $uid = (int) $request->user()->id;
        if (! $this->isParticipant($uid, $thread)) return response()->json(['message' => 'Not found'], 404);
        // A message may be an attachment with no words — a photo of a rota does not
        // need a caption. required_without mirrors the family chat rule.
        $data = $request->validate([
            'body' => ['required_without:attachment', 'nullable', 'string', 'max:5000'],
        ]);
        $attachments = \App\Support\ChatAttachments::extract($request);
        $id = $this->post($uid, $thread, (string) ($data['body'] ?? ''), now(), $attachments);
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

    /** $attachments is optional, so start() — which never carries one — is untouched. */
    private function post(int $uid, int $thread, string $body, $now, array $attachments = []): int
    {
        $id = DB::table('staff_messages')->insertGetId([
            'thread_id' => $thread, 'sender_id' => $uid, 'body' => trim($body),
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $thread)->update(['last_message_at' => $now, 'updated_at' => $now]);
        // Notify the other participant(s) so it shows in their bell/inbox.
        try {
            $others = DB::table('staff_thread_participants')->where('thread_id', $thread)->where('user_id', '!=', $uid)->pluck('user_id');
            $me = DB::table('users')->where('id', $uid)->first(['first_name', 'last_name']);
            $meName = trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? '')) ?: 'A colleague';
            /* One preview for both push transports, matching the wording of the
               notification row below so the phone banner and the in-portal inbox
               never say different things. */
            $preview = trim($body) !== ''
                ? mb_substr(strip_tags($body), 0, 140)
                : ($attachments ? '📎 sent an attachment' : 'New message');
            foreach ($others as $oid) {
                /* Push to the device. Until this existed a staff message reached
                   nobody until they next opened the portal: the notification row
                   below was written, but nothing ever told the phone about it. */
                try {
                    app(\App\Services\WebPushService::class)->sendToUsers([(int) $oid], [
                        'title' => '💬 ' . $meName,
                        'body'  => $preview,
                        'icon'  => '/icon-192.png',
                        'url'   => '/dashboard.html#team-messages',
                        'tag'   => 'team-' . $thread,
                    ]);
                } catch (\Throwable $pe) {
                    \Illuminate\Support\Facades\Log::warning('Team-chat web push failed',
                        ['user' => (int) $oid, 'error' => $pe->getMessage()]);
                }
                /* The APK registers an FCM token, which web push cannot reach.
                   Separate try so an FCM fault cannot suppress web push. */
                try {
                    app(\App\Services\FcmService::class)
                        // #team-messages, not #chat: this push opened the FAMILY messenger,
                        // where the team message it was announcing does not exist.
                        ->sendToUser((int) $oid, '💬 ' . $meName, $preview, '#team-messages', true, true);
                } catch (\Throwable $fe) {
                    \Illuminate\Support\Facades\Log::warning('Team-chat FCM push failed',
                        ['user' => (int) $oid, 'error' => $fe->getMessage()]);
                }

                DB::table('notifications')->insert([
                    'user_id' => (int) $oid, 'type' => 'team_message',
                    'title'   => '💬 New team message',
                    'body'    => $meName . ': ' . (trim($body) !== ''
                        ? mb_substr(strip_tags($body), 0, 140)
                        : ($attachments ? '📎 sent an attachment' : 'New message')),
                    'data'    => json_encode(['thread_id' => $thread]),
                    'created_at' => $now,
                ]);
            }
        } catch (\Throwable $e) { /* best-effort */ }
        return $id;
    }
}
