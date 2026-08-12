<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side onboarding gate — the airtight backstop to the client wizard gate.
 * While a user's `onboarded_at` is null, the API returns 403 for everything
 * except the handful of endpoints needed to complete onboarding / the NDA / sign
 * out. So even a user who tampers with the SPA (devtools, edited storage, back
 * button, deep link) can't reach any of their data without finishing onboarding.
 *
 * Never gates: unauthenticated (public) requests, already-onboarded users,
 * platform admins, or a super admin's "View as" impersonation token.
 *
 * Kill switch: ONBOARDING_GATE=false in .env (+ php artisan config:cache).
 */
class EnsureOnboarded
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('onboarding.gate', true)) {
            return $next($request);
        }

        // Resolve the caller. This middleware runs in the api group BEFORE the
        // route's auth:sanctum, so resolve from the bearer token ourselves
        // (falling back to a stateful/cookie session if that's how they're in).
        $bearer = $request->bearerToken();
        $pat = $bearer ? PersonalAccessToken::findToken($bearer) : null;
        $user = ($pat && $pat->tokenable) ? $pat->tokenable : $request->user();

        if (! $user) {
            return $next($request); // public endpoint / not signed in — let it through
        }

        // Super admin "View as": the impersonation token must see the target's
        // account even if that target hasn't onboarded.
        if ($pat && str_starts_with((string) $pat->name, 'impersonation:')) {
            return $next($request);
        }

        // Already onboarded → no gate.
        if (! empty($user->onboarded_at)) {
            return $next($request);
        }

        // Platform admins are never trapped.
        $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            return $next($request);
        }

        // Not onboarded → only onboarding-related endpoints are allowed.
        if ($this->allowed($request)) {
            return $next($request);
        }

        return response()->json([
            'onboarding_required' => true,
            'message'             => 'Please finish setting up your account before continuing.',
        ], 403);
    }

    /** The minimal set of endpoints a not-yet-onboarded user may call. */
    private function allowed(Request $request): bool
    {
        // Strip the api/ (and optional v1/) prefix → e.g. 'auth/me/onboarding'.
        $p = preg_replace('#^api/(v1/)?#', '', ltrim($request->path(), '/'));
        $m = strtoupper($request->method());

        if ($p === '' || $p === 'up' || $p === 'sanctum/csrf-cookie') {
            return true;
        }
        // Machine-to-machine integration endpoints (iLearn feed etc.) are token +
        // role gated already and are NEVER a human completing onboarding — the
        // service account has no onboarding. Must stay open regardless.
        if ($p === 'integration' || str_starts_with($p, 'integration/')) {
            return true;
        }
        // All auth/* — /auth/me, /auth/me/onboarding, /auth/me/avatar,
        // /auth/agreement(+/sign,/decline), /auth/logout, /auth/social/*.
        if ($p === 'auth' || str_starts_with($p, 'auth/')) {
            return true;
        }
        // App chrome the onboarding screen needs.
        if ($p === 'branding') {
            return true;
        }
        // Agency-admin onboarding steps (country, white-label/features, team invites).
        if ($p === 'admin/country') {
            return true;
        }
        if ($p === 'admin/users' && $m === 'POST') {
            return true;
        }
        if (preg_match('#^admin/agencies/\d+/features$#', $p)) {
            return true;
        }

        return false;
    }
}
