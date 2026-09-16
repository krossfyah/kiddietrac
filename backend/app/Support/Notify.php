<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The one way a notification row gets written.
 *
 * Built 2026-09-06 after an audit of every outbound channel against switched-off
 * accounts. Email, push and SMS were all clean — each asks Suppression before contacting
 * anybody. The in-app feed was the exception, and for a structural reason: it had **82
 * call sites inserting straight through the query builder**, which no model observer can
 * see and no single writer could gate. 331 notifications were queued for eight accounts
 * that had been deactivated, suspended or deleted, the newest written the day before the
 * audit.
 *
 * Not an active leak — AuthController refuses Audience::OFF_STATUSES at the front door,
 * so nobody in that state can sign in to read their bell. It is latent: reactivate the
 * account and a backlog naming children appears, covering exactly the period after the
 * person left. This is the choke point that stops it being written in the first place,
 * rather than swept up afterwards.
 *
 * TWO RULES, AND THEY ARE DELIBERATELY ASYMMETRIC:
 *
 *   NEVER WRITE to somebody who cannot sign in. A notification they can never read is
 *   not a message, it is only a record of their activity accumulating after they left.
 *   That covers deactivated AND suspended — the same list the front door uses.
 *
 *   NEVER DELETE what a returning person had. The sweep that clears the backlog
 *   (kiddietrac:revoke-off-tokens) only touches deactivated and deleted accounts, never
 *   a suspended one, because a suspension can be lifted. Anthony, 2026-09-06.
 *
 * A missing row is not worth breaking the action that produced it, so every failure is
 * caught and reported — the same contract Audit::write() keeps.
 */
final class Notify
{
    /**
     * Insert one notification row, or a list of them.
     *
     * Rows addressed to an account that cannot sign in are dropped silently: the caller
     * asked for somebody to be told, and the honest answer is that there is nobody there
     * to tell.
     */
    public static function write(array $row): void
    {
        if ($row === []) {
            return;
        }

        $rows = array_is_list($row) ? $row : [$row];
        $keep = [];
        $userIds = [];

        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['user_id'])) {
                continue;
            }
            $userIds[] = (int) $r['user_id'];
        }
        if (! $userIds) {
            return;
        }

        /* Asked once for the whole batch. A daily digest writes one row per recipient,
           and a query per row would turn a fan-out into a much bigger one. */
        $off = self::switchedOff(array_values(array_unique($userIds)));

        foreach ($rows as $r) {
            if (! is_array($r) || empty($r['user_id'])) {
                continue;
            }
            if (isset($off[(int) $r['user_id']])) {
                continue;
            }
            // Every row is stamped, so a caller that forgets does not get 1970 or a
            // MySQL default on a different clock. See App\Support\Audit for that story.
            if (empty($r['created_at'])) {
                $r['created_at'] = now();
            }
            $keep[] = $r;
        }

        if (! $keep) {
            return;
        }

        try {
            /* THE ONE PLACE ALLOWED TO TOUCH THIS TABLE DIRECTLY — and the one line a
               mechanical sweep must never rewrite. The sweep that converted the other
               call sites turned this into a call to write(), which recursed until the
               process exhausted 128MB and died with no output and nothing in the log.
               A sweep has to skip the file implementing the thing it is sweeping for. */
            DB::table('notifications')->insert($keep);
        } catch (\Throwable $e) {
            // A notification is never worth failing the thing that produced it.
            report($e);
        }
    }

    /**
     * Which of these accounts cannot sign in, as [user_id => true].
     *
     * Audience::OFF_STATUSES is the ONE definition of "switched off" — the login guard,
     * the mail layer and the token sweep all read it. Keeping a local list here is
     * exactly how the front door and the mail layer once drifted apart.
     */
    private static function switchedOff(array $userIds): array
    {
        if (! $userIds) {
            return [];
        }

        try {
            return DB::table('users')
                ->whereIn('id', $userIds)
                ->where(function ($q) {
                    $q->whereIn('status', Audience::OFF_STATUSES)->orWhereNotNull('deleted_at');
                })
                ->pluck('id')->flip()->map(fn () => true)->all();
        } catch (\Throwable $e) {
            report($e);

            /* FAIL CLOSED IS WRONG HERE. If this lookup breaks, dropping every
               notification would silence the whole portal — parents would stop being told
               their child arrived. The exposure this guards against is latent and swept
               nightly; silence is immediate and total. So a failure lets the rows
               through and is reported. */
            return [];
        }
    }
}
