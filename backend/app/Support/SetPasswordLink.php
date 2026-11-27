<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * A ONE-TIME "SET YOUR PASSWORD" LINK FOR A WELCOME EMAIL (2026-11-27, Anthony).
 *
 * Welcome emails printed a generated temporary password. They now carry this link instead. It is the
 * invite mechanism the platform already uses (AdminController::store, ResellerController::inviteAdmin):
 * a password_resets row holding only the sha256 of a random 64-hex token, user_id stamped, an expiry,
 * and set-password.html on the portal host. AuthController::setPasswordFromToken consumes it: it must
 * be unused and unexpired, it is marked used on success, and every other open link of that account is
 * voided at the same moment, so it works exactly once.
 *
 * Issuing a link NEVER touches the account's password: someone who already received a temporary
 * password can keep signing in with it. Only this account's earlier unused LINKS are voided (the
 * newest email is the one that works; the failure message says "a newer invite was sent").
 *
 * The plain token exists only in the returned URL (the email). Never log it.
 */
class SetPasswordLink
{
    public const DAYS = 7;

    public const PORTAL = 'https://app.kiddietrac.com';

    public static function issue(int $userId, string $email, ?string $ip = null): string
    {
        DB::table('password_resets')->where('user_id', $userId)->whereNull('used_at')
            ->whereNull('code_hash')->update(['used_at' => now()]);
        $token = bin2hex(random_bytes(32));
        DB::table('password_resets')->insert(array_filter([
            'email' => $email,
            'user_id' => $userId,
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS),
            'requester_ip' => $ip,
            'created_at' => now(),
        ], fn ($v) => $v !== null));

        return self::PORTAL . '/set-password.html?token=' . $token;
    }

    /** A password nobody knows, for a new account whose owner sets their own from the link. */
    public static function unusablePassword(): string
    {
        return \Illuminate\Support\Facades\Hash::make(bin2hex(random_bytes(32)));
    }
}
