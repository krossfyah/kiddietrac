<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side gate for a password that was ISSUED rather than chosen.
 *
 * When an administrator resets somebody's password the portal mints a random temporary
 * one and emails it. That password has travelled through an inbox in plain text, so it
 * must be a one-time key to get in and set a real one — not a working credential the
 * account keeps. Until 2026-09-14 it was the latter: sign in with the temporary password
 * and you could use the portal with it for ever.
 *
 * While users.must_change_password is set, this answers 403 to everything except the
 * handful of endpoints needed to choose a new password or sign out. It is the same shape
 * as EnsureOnboarded and sits next to it in the api group, for the same reason: a client
 * gate is a suggestion, and anyone can edit their own localStorage.
 *
 * Deliberately NOT gated:
 *  - unauthenticated requests (public endpoints, and the login call itself)
 *  - impersonation tokens — a super admin viewing as somebody must not be trapped by
 *    that person's pending password change, which is not the admin's to complete
 *  - every auth/* route, which is where change-password, /auth/me and logout live
 *
 * Platform admins are NOT exempt. EnsureOnboarded exempts them so a half-set-up platform
 * account can still administer; here the exemption would defeat the point, because a
 * platform admin is exactly the account where a mailed temporary password matters most.
 *
 * Kill switch: PASSWORD_CHANGE_GATE=false in .env (+ php artisan config:cache).
 */
class EnsurePasswordChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('onboarding.password_gate', true)) {
            return $next($request);
        }

        // Runs in the api group BEFORE the route's auth:sanctum, so resolve the caller
        // from the bearer token here, exactly as EnsureOnboarded does.
        $bearer = $request->bearerToken();
        $pat = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        $user = ($pat && $pat->tokenable) ? $pat->tokenable : $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($pat && str_starts_with((string) $pat->name, 'impersonation:')) {
            return $next($request);
        }

        /* EXPIRY IS ENFORCED HERE, OR THE POLICY IS DECORATIVE (2026-09-21).

           The warning email tells people "you will be asked to set a new one the next
           time you sign in". Until this existed, that was a promise the code did not
           keep: the 90-day rule had a column, a nightly reminder and no teeth at all.

           Reuses the same gate as an admin-forced reset, so there is one way to be asked
           for a new password rather than two that can disagree - and one allow-list of
           endpoints somebody mid-change can still reach.

           Deliberately NOT a hard sign-out. The account keeps working the moment a new
           password is set; nothing is revoked and no data is touched. */
        $expired = false;
        if (empty($user->must_change_password)) {
            try {
                $expired = \App\Services\PasswordPolicy::isExpired($user->password_changed_at ?? null);
            } catch (\Throwable $e) {
                /* FAIL OPEN. A fault in the age check must never lock 81 people out of a
                   Monday morning; the worst case of failing open is a password that
                   lives a few days longer than policy says. */
                $expired = false;
            }

            if (! $expired) {
                return $next($request);
            }
        }

        if ($this->allowed($request)) {
            return $next($request);
        }

        /* Say WHICH of the two it is. "Your password was reset by an administrator" to
           somebody whose password simply aged out is a small lie that sends them to ask
           an admin what they did. */
        return response()->json([
            'password_change_required' => true,
            'reason' => $expired ? 'expired' : 'admin_reset',
            'message' => $expired
                ? 'Your password has reached its ' . \App\Services\PasswordPolicy::maxAgeDays()
                    . '-day limit. Please choose a new one to continue.'
                : 'Your password was reset by an administrator. Please choose a new password to continue.',
        ], 403);
    }

    /**
     * The endpoints someone mid-change must still be able to reach.
     *
     * All of auth/* rather than a list of three: change-password, set-password, reset,
     * me, logout and the agency switch all live there, and enumerating exact paths is
     * what broke the onboarding gate for every guardian on 2026-09-07. Everything under
     * auth/ is either public or scoped to the caller's own account, so opening the
     * prefix grants no access to agency data.
     */
    private function allowed(Request $request): bool
    {
        $p = preg_replace('#^api/(v1/)?#', '', ltrim($request->path(), '/'));

        if ($p === '' || $p === 'up' || $p === 'sanctum/csrf-cookie') {
            return true;
        }

        // Machine-to-machine integration accounts never have a human password change.
        if ($p === 'integration' || str_starts_with($p, 'integration/')) {
            return true;
        }

        if ($p === 'auth' || str_starts_with($p, 'auth/')) {
            return true;
        }

        // The login screen and the change-password screen both paint branding.
        if ($p === 'branding') {
            return true;
        }

        return false;
    }
}
