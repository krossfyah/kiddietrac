<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Account lifecycle status (2026-08-24).
 *
 * users.status answers "has this account been claimed" — invited → active, and later
 * suspended or deactivated by an administrator. It is NOT the same question as
 * users.onboarded_at, which answers "has the setup wizard been finished". Conflating the
 * two is what caused this bug: 'invited' → 'active' only ever happened at the end of the
 * wizard, so someone who set a password, signed in and signed the agreement — but never
 * finished the wizard — stayed 'invited' indefinitely. The mail suppression gate keys off
 * status, so those people silently received no email at all.
 *
 * Keeping them separate matters in both directions: OnboardingReminderCommand chases
 * whoever still has onboarded_at null, and it must go on doing that for a claimed
 * account that has setup left to do.
 */
final class AccountStatus
{
    /** Statuses that mean "this account has not been claimed yet". */
    private const UNCLAIMED = ['invited', 'not_invited'];

    /**
     * Record that an account has been claimed — call wherever a login token is issued.
     *
     * Promotion only. A suspended or deactivated account is left exactly as it is: those
     * are administrative decisions and signing in must never quietly undo one. The WHERE
     * clause enforces that in SQL rather than trusting a read-then-write.
     *
     * Never throws. A failure here must not be able to block a sign-in.
     */
    public static function markClaimed(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            DB::table('users')
                ->where('id', $userId)
                ->whereIn('status', self::UNCLAIMED)
                ->update(['status' => 'active', 'updated_at' => now()]);
        } catch (Throwable $e) {
            // Status bookkeeping must never break authentication.
        }
    }

    /** Statuses that mean "this account is closed" — the login gate rejects both. */
    public const CLOSED = ['deactivated', 'suspended'];

    /**
     * Close the roles an account holds, alongside closing the account itself.
     *
     * Call this from every off-boarding path right after users.status is set. Until
     * 2026-09-02 none of them did, so a de-boarded family's guardian kept an active
     * guardian assignment forever. That was not an access hole — the login gate reads
     * users.status, and the audiences read Audience::excludeOff() — but it left User
     * management showing closed accounts in role lists, and it meant the isolation
     * depended on every future reader remembering the second check.
     *
     * Only assignments that are active right now are touched, and each one is stamped so
     * reopen() can put back exactly what this took. Never throws: the account closure is
     * the thing that matters, and it has already happened by the time we get here.
     */
    public static function closeRoles(array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) {
            return 0;
        }

        try {
            return DB::table('role_assignments')
                ->whereIn('user_id', $userIds)
                ->where('active', 1)
                ->update(['active' => 0, 'closed_with_account_at' => now()]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /**
     * Put back the roles that closeRoles() took — and nothing else.
     *
     * The stamp is the whole point. A role an administrator revoked deliberately before
     * the off-boarding has a null stamp, so restoring the family leaves it revoked.
     * Re-activating every inactive assignment instead would hand someone back access they
     * had specifically been denied, which is the more expensive mistake of the two.
     */
    public static function reopenRoles(array $userIds): int
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (! $userIds) {
            return 0;
        }

        try {
            return DB::table('role_assignments')
                ->whereIn('user_id', $userIds)
                ->whereNotNull('closed_with_account_at')
                ->update(['active' => 1, 'closed_with_account_at' => null]);
        } catch (Throwable $e) {
            return 0;
        }
    }
}
