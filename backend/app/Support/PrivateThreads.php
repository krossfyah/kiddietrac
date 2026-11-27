<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Private one-to-one threads.
 *
 * Backed by `staff_threads` / `staff_thread_participants`, which despite the name are
 * not staff-only: the tables carry no role constraint, and every read path guards on
 * participation (`isParticipant`, and a thread list built from your own participant
 * rows). Nobody who is not in the thread can see or post in it — including other staff
 * at the same centre. That property is what a broadcast needs so a message to a parent
 * is genuinely between the two of you, unlike a `conversations` row, which is a shared
 * centre-to-family thread any colleague at that centre may read and answer.
 *
 * Extracted from TeamChatController::start() so the broadcast path can reuse it rather
 * than grow a second copy. Duplicated privacy logic is expensive: the platform-admin
 * agency scoping (v22p96 / v22p98) had to be discovered and fixed twice precisely
 * because it existed in two places.
 */
class PrivateThreads
{
    /**
     * A THREAD BELONGS TO THE AGENCY IT WAS STARTED IN (2026-11-27, leak guard finding).
     *
     * Threads 69 and 98 were filed under iLearn (2) but were DMs between Anthony and people
     * who are ONLY in Test Agency 6. The callers guessed the agency from somebody's FIRST
     * role row ("the recipient's agency"): when the recipient was Anthony, whose first row is
     * iLearn's agency_admin, an agency-6 admin's message to him was filed under iLearn.
     *
     * The rule now: the thread takes the ACTIVE agency of the request that starts it (the
     * caller passes it in; never derived from a person), and every participant must belong
     * to that agency. A platform admin may join a thread in any agency, because they can
     * work in any agency, but they never decide which agency a thread is in.
     */
    public static function mayJoin(int $userId, int $agencyId): bool
    {
        if ($userId <= 0 || $agencyId <= 0) return false;
        if (DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return true;
        }
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $role = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where(fn ($q) => $q->where('agency_id', $agencyId)->orWhereIn('centre_id', $centres ?: [0]))
            ->exists();
        if ($role) return true;

        // A parent: a guardian of a family at one of the agency's centres.
        return DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $userId)->whereIn('f.centre_id', $centres ?: [0])->exists();
    }

    /** The user ids in $userIds that may NOT be in a thread of $agencyId. */
    public static function outsiders(array $userIds, int $agencyId): array
    {
        return array_values(array_filter(array_map('intval', $userIds), fn ($u) => ! self::mayJoin($u, $agencyId)));
    }

    /**
     * The 1:1 thread between two people IN THIS AGENCY, creating it if it does not exist.
     *
     * "The 1:1 thread" means a thread they are both in that has EXACTLY two
     * participants — without that count a group thread containing both of them would
     * match, and a broadcast would land in front of an audience.
     *
     * Two people who work together in two agencies have one thread per agency: a
     * conversation is that agency's record, and one shared thread would show agency A's
     * messages while working in agency B.
     *
     * @throws \DomainException when the agency is unknown or either person is not in it
     */
    public static function findOrCreate(int $userA, int $userB, ?int $agencyId = null): int
    {
        if ($userA === $userB) {
            throw new \InvalidArgumentException('A private thread needs two different people.');
        }
        // Fail CLOSED: no agency means no thread, never a guessed one.
        $agencyId = (int) ($agencyId ?? 0);
        if ($agencyId <= 0) {
            throw new \DomainException('A private thread needs the agency it belongs to.');
        }
        if (self::outsiders([$userA, $userB], $agencyId)) {
            throw new \DomainException('Both people must belong to the agency this conversation is in.');
        }

        $existing = DB::table('staff_thread_participants as a')
            ->join('staff_thread_participants as b', 'a.thread_id', '=', 'b.thread_id')
            ->join('staff_threads as t', 't.id', '=', 'a.thread_id')
            ->where('t.agency_id', $agencyId)
            ->where('a.user_id', $userA)->where('b.user_id', $userB)
            ->whereRaw('(SELECT COUNT(*) FROM staff_thread_participants p WHERE p.thread_id = a.thread_id) = 2')
            ->value('a.thread_id');

        if ($existing) {
            return (int) $existing;
        }

        $now = now();
        $threadId = DB::table('staff_threads')->insertGetId([
            'agency_id' => $agencyId,
            'created_by' => $userA,
            'last_message_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([$userA, $userB] as $uid) {
            DB::table('staff_thread_participants')->insert([
                'thread_id' => $threadId,
                'user_id' => $uid,
                // The initiator has obviously read their own opening message, so a
                // thread they just created does not come back marked unread to them.
                'last_read_at' => $uid === $userA ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return (int) $threadId;
    }

    /** Put a message in the thread and move it to the top of both inboxes. */
    public static function post(int $senderId, int $threadId, string $body, array $attachments = []): int
    {
        $now = now();
        $id = DB::table('staff_messages')->insertGetId([
            'thread_id' => $threadId,
            'sender_id' => $senderId,
            'body' => $body,
            'attachments' => $attachments ? json_encode($attachments) : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('staff_threads')->where('id', $threadId)
            ->update(['last_message_at' => $now, 'updated_at' => $now]);

        return (int) $id;
    }

    /** Everyone in the thread except the sender — who to notify. */
    public static function others(int $threadId, int $exceptUserId): array
    {
        return DB::table('staff_thread_participants')->where('thread_id', $threadId)
            ->where('user_id', '!=', $exceptUserId)->pluck('user_id')
            ->map(fn ($v) => (int) $v)->all();
    }
}
