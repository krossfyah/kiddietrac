<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Who an email address belongs to — when the honest answer is "possibly several people".
 *
 * ONE ADDRESS CAN HOLD SEVERAL ACCOUNTS HERE. That is a deliberate product decision, not
 * a data defect: usernames exist precisely so one person can hold an educator account and
 * a home-visitor account, or a live account and a demo one, under the same inbox. See
 * users.username (UNIQUE) and the dropped UNIQUE index on users.email.
 *
 * Every incident this class exists to prevent had the same shape — code asked
 * `where('email', $x)->first()`, got the lowest id, and treated it as "the" person:
 *
 *   - forgotPassword mailed a reset for the wrong account, and the link that was used
 *     set a dormant demo account's password while the real one stayed locked;
 *   - login counted switched-off accounts when deciding to demand a username, so a dead
 *     account made a live one unreachable behind a prompt;
 *   - the mail gate withheld a set-password invite for a NEW account because a DIFFERENT
 *     account on the address had already been claimed.
 *
 * Three separate bugs, one root cause, all in one week. So the rule has a name now:
 *
 *   AN ADDRESS IS NOT AN IDENTITY. Resolve to a specific account, or admit it is
 *   ambiguous and ask. Never silently pick one.
 *
 * "Live" throughout means an account somebody could actually sign into: not soft-deleted,
 * and not in Audience::OFF_STATUSES. A deactivated or deleted account cannot be signed
 * into no matter what is presented, so it must never create ambiguity and must never be
 * chosen as the answer.
 */
final class EmailAccounts
{
    /** Every account on this address that somebody could sign into, oldest first. */
    public static function live(?string $email): Collection
    {
        $e = mb_strtolower(trim((string) $email));
        if ($e === '') {
            return collect();
        }

        return DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$e])
            ->whereNull('deleted_at')
            ->whereNotIn('status', Audience::OFF_STATUSES)
            ->orderBy('id')
            ->get();
    }

    /**
     * The one account this address means — or NULL when that cannot be answered.
     *
     * Null for "nobody" and null for "more than one" on purpose: both are cases where a
     * caller must stop and do something else (ask for a username, refuse to link, decline
     * to name a person), and collapsing them into a row would be the bug all over again.
     * Callers that need to tell the two apart use live()->count().
     */
    public static function soleLive(?string $email): ?object
    {
        $all = self::live($email);

        return $all->count() === 1 ? $all->first() : null;
    }

    /** More than one signed-in-able account shares this address. */
    public static function isAmbiguous(?string $email): bool
    {
        return self::live($email)->count() > 1;
    }

    /**
     * Is this account awaiting its first password?
     *
     * Asked of an ACCOUNT rather than an address — the distinction that broke the invite
     * gate. 'invited' means an invite went out and was never accepted; 'not_invited'
     * means the account exists and no invite has been sent. Both are unclaimed.
     */
    public static function isUnclaimed(?int $userId): bool
    {
        if (! $userId) {
            return false;
        }

        return DB::table('users')
            ->where('id', $userId)
            ->whereIn('status', ['invited', 'not_invited'])
            ->exists();
    }

    /** Any account on this address still awaiting a first password. */
    public static function anyUnclaimed(?string $email): bool
    {
        $e = mb_strtolower(trim((string) $email));
        if ($e === '') {
            return false;
        }

        return DB::table('users')
            ->whereRaw('LOWER(TRIM(email)) = ?', [$e])
            ->whereNull('deleted_at')
            ->whereIn('status', ['invited', 'not_invited'])
            ->exists();
    }

    /** "Lloydene-HV", or "account #197" when they have no username yet — for a message. */
    public static function label(object $user): string
    {
        $u = trim((string) ($user->username ?? ''));

        return $u !== '' ? $u : ('account #' . ($user->id ?? '?'));
    }
}
