<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Presence tracking (2026-08-24).
 *
 * Stamps users.last_seen_at from ordinary API traffic. The portal already polls every
 * few seconds for unread counts and new messages, so presence costs no extra requests
 * and needs no heartbeat endpoint — being signed in and using the app IS the signal.
 *
 * Throttled to one write per user per minute through the cache. Without it, a single
 * open tab would issue ~15 writes a minute to the users table, and every open tab in
 * the agency would do the same.
 *
 * NEVER stamps for an impersonation token. A platform admin using "View as" would
 * otherwise light up the person they are viewing as online, which is both wrong and a
 * small privacy problem: it reports presence for someone who is not there.
 *
 * Never throws. Presence is decoration; it must not be able to break a request. The
 * try/catch also covers the window between deploying this file and running the
 * migration, when the column does not exist yet.
 */
class TrackPresence
{
    /** Seconds between writes for one user. */
    private const WRITE_EVERY = 60;

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        try {
            $user = $request->user();
            if (! $user) {
                return $response;
            }

            // "View as" traffic is the admin's, not this user's.
            $token = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
            if ($token && str_starts_with((string) ($token->name ?? ''), 'impersonation:')) {
                return $response;
            }

            $key = 'presence:' . $user->id;
            if (Cache::has($key)) {
                return $response;
            }
            Cache::put($key, 1, self::WRITE_EVERY);

            /* Presence, and the account-claimed promotion, in one write.

               Holding a valid token proves this account was claimed -- it cannot be
               obtained without authenticating, and impersonation is already excluded
               above. 'invited' after that point is simply wrong, and because the mail
               gate keys off status it meant real users silently got no email.

               Promotion only: a suspended or deactivated account stays as it is.
               Done as a CASE inside the existing UPDATE, so it costs no extra query. */
            DB::table('users')->where('id', $user->id)->update([
                'last_seen_at' => now(),
                'status' => DB::raw("CASE WHEN status IN ('invited','not_invited')"
                    . " THEN 'active' ELSE status END"),
            ]);
        } catch (Throwable $e) {
            // Presence must never break a request.
        }

        return $response;
    }
}
