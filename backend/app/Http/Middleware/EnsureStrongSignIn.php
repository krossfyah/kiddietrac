<?php

namespace App\Http\Middleware;

use App\Support\StrongSignIn;
use Closure;
use Illuminate\Http\Request;
use App\Models\PersonalAccessToken;   // the per-request shared lookup (2026-11-13)
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side half of the two-factor rule (2026-11-10, PIPEDA review): see App\Support\StrongSignIn for who and when.
 * Same shape as EnsurePasswordChanged beside it - a screen gate is a suggestion, anyone can call the API directly.
 *
 * While someone is blocked, everything answers 403 {mfa_setup_required} except what they need to get out of it:
 * every auth/* route (sign-out, /auth/me, auth/mfa/* set-up), passkey enrolment, branding. Impersonation tokens pass
 * (the admin viewing as somebody is not the one who must enrol). A fault here FAILS OPEN, like the password gate.
 */
class EnsureStrongSignIn
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) return $next($request);
        try {
            $pat = PersonalAccessToken::findToken($bearer);
            $user = ($pat && $pat->tokenable) ? $pat->tokenable : null;
            if (! $user || str_starts_with((string) $pat->name, 'impersonation:')) return $next($request);
            if ($this->allowed($request)) return $next($request);
            $s = StrongSignIn::state($user);
        } catch (\Throwable $e) {
            report($e);
            return $next($request);
        }
        if (! $s['blocked']) return $next($request);

        return response()->json([
            'mfa_setup_required' => true,
            'message' => 'Your account can see children\'s and families\' records, so it needs two-factor sign-in. Set up an authenticator app or a passkey to continue.',
        ], 403);
    }

    private function allowed(Request $request): bool
    {
        $p = preg_replace('#^api/(v1/)?#', '', ltrim($request->path(), '/'));
        if ($p === '' || $p === 'up' || $p === 'sanctum/csrf-cookie' || $p === 'branding') return true;
        if ($p === 'auth' || str_starts_with($p, 'auth/')) return true;
        if ($p === 'passkeys' || str_starts_with($p, 'passkeys/')) return true;
        if ($p === 'integration' || str_starts_with($p, 'integration/')) return true;
        return false;
    }
}
