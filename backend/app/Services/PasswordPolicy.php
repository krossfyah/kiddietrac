<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

/**
 * Central password policy (2026-07-09).
 *
 * - Complexity: 8+ chars with mixed case, a number, and a symbol (enforced
 *   server-side so weak passwords are rejected with a clear validation error,
 *   not silently accepted).
 * - No reuse within the last 6 months (checked against password_history + the
 *   current password).
 *
 * Used by every password-setting endpoint (invite set-password, reset, change,
 * admin reset) so the rules are identical everywhere.
 */
final class PasswordPolicy
{
    public const REUSE_MONTHS = 6;

    /* ── AGE AND LOCKOUT (2026-09-21) ──────────────────────────────────────────
     *
     * Merged in rather than left as a second App\Support\PasswordPolicy. Two classes
     * with the same short name, one for strength and one for age, is how somebody ends
     * up importing the wrong one and writing a rule that silently never runs - and this
     * file already says it is THE central password policy.
     *
     * Anthony: "can you check to see if we implementing the 90 day password rotation for
     * all users... and perhaps a password reset if the login attempts fail after 3 or 5
     * attempts." We were not: there was no password age recorded anywhere, 81 active
     * accounts and not one with a date against its password.
     *
     * ON 90 DAYS, for whoever reads this later: current guidance (NIST SP 800-63B
     * 5.1.1.2, and the same from NCSC and Microsoft) is AGAINST forced periodic rotation
     * - it produces Password1 then Password2. Anthony chose 90 days anyway, knowing that,
     * because SOC 2 auditors and insurers still ask for it. The decision is his; the
     * reasoning is recorded so the next person does not "discover" the trade-off and
     * quietly undo it. Note the irony that REUSE_MONTHS above is the control that
     * actually does the work here.
     *
     * ON LOCKOUT: a hard lock is a denial-of-service - anyone who knows an educator's
     * email can shut her out of drop-off by typing rubbish five times. Five failures,
     * then a FIVE MINUTE hold that clears itself, with an admin override beside it.
     */
    /** Rotate every 90 days. Override per deployment with KT_PASSWORD_MAX_AGE_DAYS. */
    public static function maxAgeDays(): int
    {
        $v = (int) (env('KT_PASSWORD_MAX_AGE_DAYS') ?: 90);

        return $v > 0 ? $v : 90;
    }

    /** Warn at these many days remaining, each sent once. */
    public const WARN_AT_DAYS = [14, 7, 1];

    /** Failed attempts before the account is held. */
    public const LOCK_AFTER = 5;

    /** How long the hold lasts. Clears itself; an admin can also lift it immediately. */
    public const LOCK_MINUTES = 5;

    /** Attempts are counted within this window, so old typos do not accumulate. */
    public const COUNT_WINDOW_MINUTES = 15;

    /** Days until this password must change; negative means already overdue. */
    public static function daysLeft(?string $changedAt): ?int
    {
        if (! $changedAt) {
            return null;
        }

        try {
            $at = \Illuminate\Support\Carbon::parse($changedAt);

            /* A stamp in the future is not a password with extra life - it is a bad row,
               and it silently grants an exemption from rotation. (A backfill that
               staggered forwards instead of backwards put 17 accounts there.) Treat it as
               changed now: the account still gets a full cycle, but a bounded one. */
            if ($at->isFuture()) {
                $at = now();
            }

            $due = $at->addDays(self::maxAgeDays());

            /* Whole days, floored towards the deadline: 0 means it expires today, and
               today still counts as in date. */
            return (int) floor(now()->diffInDays($due, false));
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function isExpired(?string $changedAt): bool
    {
        $left = self::daysLeft($changedAt);

        return $left !== null && $left < 0;
    }

    /** When an administrator last lifted the hold on this identifier, if ever. */
    public static function unlockedAt(string $login): ?string
    {
        $login = mb_strtolower(trim($login));
        try {
            foreach (DB::table('audit_logs')->where('action', 'security.login_unlocked')
                ->where('created_at', '>=', now()->subHours(6))
                ->orderByDesc('id')->limit(40)->get(['created_at', 'payload']) as $r) {
                $d = json_decode((string) $r->payload, true) ?: [];
                if (mb_strtolower(trim((string) ($d['login'] ?? ''))) === $login) {
                    return (string) $r->created_at;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    /**
     * Is this account being held after too many failures, and for how much longer?
     *
     * Counted from the audit log rather than a counter column: the rows are written
     * already, they survive a deploy, and a counter that has to be reset correctly on
     * every success is one more thing to get wrong. Keyed on the LOGIN IDENTIFIER, not
     * the user id, because a failed attempt often does not resolve to a user at all.
     *
     * @return int  minutes remaining, 0 when not locked
     */
    public static function lockedFor(string $login): int
    {
        $login = mb_strtolower(trim($login));
        if ($login === '') {
            return 0;
        }

        try {
            $since = now()->subMinutes(self::COUNT_WINDOW_MINUTES);
            /* created_at comes back WITH the payload. The first version looked it up per
               row, which is an N+1 on the login path - the one request that must never be
               the slow one. */
            $rows = DB::table('audit_logs')
                ->where('action', 'login_failed')
                ->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit(60)
                ->get(['created_at', 'payload']);

            /* AN ADMIN CAN LIFT IT. Recorded as an audit row rather than a column so
               that clearing a lock is itself an audited act - "who let this account back
               in, and when" is exactly the question an auditor asks. Failures older than
               the most recent unlock no longer count. */
            $clearedAt = self::unlockedAt($login);

            $clearedTs = $clearedAt ? strtotime($clearedAt) : 0;
            $fails = 0;
            $lastFailAt = null;
            foreach ($rows as $row) {
                $d = json_decode((string) $row->payload, true) ?: [];
                if (mb_strtolower(trim((string) ($d['login'] ?? ''))) !== $login) {
                    continue;
                }
                if ($clearedTs && strtotime((string) $row->created_at) <= $clearedTs) {
                    continue;
                }
                $fails++;
                /* Rows arrive newest first, so the first one that counts is the latest. */
                $lastFailAt = $lastFailAt ?: (string) $row->created_at;
            }

            if ($fails < self::LOCK_AFTER) {
                return 0;
            }

            /* The hold runs from the most recent failure THAT COUNTS, so hammering it
               keeps it shut rather than letting the window slide out from under the
               attacker - and an admin unlock is not undone by attempts it already
               forgave. Taken from the rows above; no second query. */
            if (! $lastFailAt) { return 0; }

            $until = \Illuminate\Support\Carbon::parse($lastFailAt)->addMinutes(self::LOCK_MINUTES);
            $mins = (int) ceil(now()->diffInSeconds($until, false) / 60);

            return $mins > 0 ? $mins : 0;
        } catch (\Throwable $e) {
            /* FAIL OPEN, deliberately. If the audit table is unavailable, refusing every
               login would turn a logging problem into an outage for 81 people. The rate
               limiter in AppServiceProvider is still in front of this. */
            return 0;
        }
    }

    /** The validation rule object for a password field. */
    public static function rule(): Password
    {
        return Password::min(8)->mixedCase()->numbers()->symbols();
    }

    /**
     * Reject a password that matches the user's current password or any used in
     * the last 6 months. Throws a ValidationException keyed to $field so the API
     * returns a normal 422 with a clear message.
     */
    public static function assertNotRecentlyUsed(int $userId, string $plain, string $field = 'password'): void
    {
        // Guard Hash::check — it throws on a non-bcrypt/malformed stored hash,
        // which must never turn a password change into a 500.
        $matches = static function (string $plain, $hash): bool {
            if (! is_string($hash) || $hash === '') {
                return false;
            }
            try {
                return Hash::check($plain, $hash);
            } catch (\Throwable $e) {
                return false;
            }
        };

        $current = DB::table('users')->where('id', $userId)->value('password');
        if ($matches($plain, $current)) {
            throw ValidationException::withMessages([
                $field => ["You're already using that password. Please choose a new one."],
            ]);
        }

        if (! Schema::hasTable('password_history')) {
            return;
        }
        $since = now()->subMonths(self::REUSE_MONTHS);
        $hashes = DB::table('password_history')
            ->where('user_id', $userId)
            ->where('created_at', '>=', $since)
            ->pluck('password_hash');

        foreach ($hashes as $hash) {
            if ($matches($plain, $hash)) {
                throw ValidationException::withMessages([
                    $field => ['You have used this password in the last 6 months. Please choose a different one.'],
                ]);
            }
        }
    }

    /** Record a newly-set password hash so it counts toward the reuse window. */
    public static function record(int $userId, string $hash): void
    {
        if (! Schema::hasTable('password_history')) {
            return;
        }
        DB::table('password_history')->insert([
            'user_id'       => $userId,
            'password_hash' => $hash,
            'created_at'    => now(),
        ]);
        // Keep history bounded — only the reuse window + a small buffer is needed.
        $cutoff = now()->subMonths(self::REUSE_MONTHS + 6);
        DB::table('password_history')->where('user_id', $userId)->where('created_at', '<', $cutoff)->delete();
    }
}
