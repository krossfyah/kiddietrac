<?php

namespace App\Support;

use App\Http\Controllers\Api\MfaController;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WHO MUST SIGN IN WITH TWO STEPS, AND FROM WHEN (2026-11-10, PIPEDA review; Anthony chose "Admins, 14-day grace").
 *
 * Anyone who can see every child's file - platform admins, resellers, agency admins, directors - must have an
 * authenticator app (users.two_factor_enabled) or a passkey (user_passkeys). Until 2026-11-10 only the screen asked
 * (kt-mfa-gate.js), for agency admins and directors, so a direct API call walked past it and platform admins and
 * resellers were never asked at all.
 *
 * - agency_admin / centre_director: blocked at once, exactly as the screen gate has done since 2026-08-07.
 * - platform_admin / reseller: newly covered, so they get 14 days (users.mfa_grace_until, set the first time they
 *   are found without it) and a banner, then the same block.
 * Not covered: demo-only accounts (MfaController::demoExempt), the website demo people, integration+ service inboxes.
 * Blocked means every API call answers 403 except sign-in, two-factor and passkey set-up (EnsureStrongSignIn).
 */
final class StrongSignIn
{
    public const ROLES = ['platform_admin', 'reseller', 'agency_admin', 'centre_director'];
    /** Already gated on screen with no grace - unchanged. */
    public const AT_ONCE = ['agency_admin', 'centre_director'];
    public const GRACE_DAYS = 14;

    /** The covered roles this person holds ([] = not covered). Cached briefly: it runs on every request. */
    public static function roles(object $user): array
    {
        $uid = (int) $user->id;
        return Cache::remember('kt-mfa-roles:' . $uid, 300, function () use ($user, $uid) {
            $email = strtolower((string) ($user->email ?? ''));
            if (str_contains($email, 'integration+')) return [];
            if (PublicDemo::isUser($uid) || MfaController::demoExempt($uid)) return [];
            return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->whereIn('role', self::ROLES)->distinct()->pluck('role')->values()->all();
        });
    }

    /** Has a second step: an authenticator app or a passkey. Read live, so enrolling lifts the block at once. */
    public static function strong(object $user): bool
    {
        if (! empty($user->two_factor_enabled)) return true;
        return DB::table('user_passkeys')->where('user_id', (int) $user->id)->exists();
    }

    /** The day the grace ends for a platform admin / reseller; started the first time it is asked. */
    public static function graceUntil(object $user): string
    {
        $g = DB::table('users')->where('id', (int) $user->id)->value('mfa_grace_until');
        if (! $g) {
            $g = now()->addDays(self::GRACE_DAYS)->toDateTimeString();
            DB::table('users')->where('id', (int) $user->id)->whereNull('mfa_grace_until')->update(['mfa_grace_until' => $g]);
            $g = DB::table('users')->where('id', (int) $user->id)->value('mfa_grace_until') ?: $g;
        }
        return (string) $g;
    }

    /**
     * ['required' => bool, 'strong' => bool, 'blocked' => bool, 'grace_until' => ?string (UTC)].
     */
    public static function state(object $user): array
    {
        $roles = self::roles($user);
        // Not covered: no passkey query on every request for the people this does not apply to.
        if (! $roles) return ['required' => false, 'strong' => ! empty($user->two_factor_enabled), 'blocked' => false, 'grace_until' => null];
        if (self::strong($user)) return ['required' => true, 'strong' => true, 'blocked' => false, 'grace_until' => null];
        if (array_intersect($roles, self::AT_ONCE)) return ['required' => true, 'strong' => false, 'blocked' => true, 'grace_until' => null];
        $until = self::graceUntil($user);
        return ['required' => true, 'strong' => false, 'blocked' => now()->greaterThanOrEqualTo($until), 'grace_until' => $until];
    }
}
