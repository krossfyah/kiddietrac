<?php

namespace App\Http\Middleware;

use App\Support\SignInLocks;
use Closure;
use Illuminate\Http\Request;
use App\Models\PersonalAccessToken;   // the per-request shared lookup (2026-11-13)
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side half of sign-in locks (2026-11-10, App\Support\SignInLocks). A lock made while someone is signed in takes
 * effect on their next call: everything answers 423 {access_locked} except sign-out and branding. Read against the
 * ACTIVE agency, so a person locked at one agency keeps working in another; with no agency chosen, only someone locked
 * at every agency they belong to is stopped. switch_to names an agency they can still use.
 * Impersonation tokens pass (the admin viewing as somebody is not the one locked). One cheap query when no lock is open
 * anywhere. A fault here FAILS OPEN, like the password and two-factor gates beside it - a broken check must not lock
 * every agency out.
 */
class EnforceSignInLocks
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();
        if (! $bearer) return $next($request);
        try {
            if (! SignInLocks::anyOpen() || $this->allowed($request)) return $next($request);
            $pat = PersonalAccessToken::findToken($bearer);
            $user = ($pat && $pat->tokenable) ? $pat->tokenable : null;
            if (! $user || str_starts_with((string) $pat->name, 'impersonation:')) return $next($request);
            $uid = (int) $user->id;
            $aid = (int) $request->header('X-Active-Agency-Id');
            $lock = $aid ? SignInLocks::blocking($uid, $aid) : SignInLocks::atSignIn($uid);
            if (! $lock) return $next($request);
            $switch = $aid ? SignInLocks::openAgencyFor($uid) : null;
            $message = SignInLocks::message((int) $lock->agency_id);
        } catch (\Throwable $e) {
            report($e);
            return $next($request);
        }

        return response()->json(['access_locked' => true, 'message' => $message, 'switch_to' => $switch], 423);
    }

    private function allowed(Request $request): bool
    {
        $p = preg_replace('#^api/(v1/)?#', '', ltrim($request->path(), '/'));

        return in_array($p, ['', 'up', 'sanctum/csrf-cookie', 'branding', 'auth/logout', 'auth/login'], true);
    }
}
