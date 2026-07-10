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
