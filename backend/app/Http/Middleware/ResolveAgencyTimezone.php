<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\AgencyTime;
use App\Support\AuditScope;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every API request knows its agency's timezone (2026-10-21).
 *
 * Anthony: "is the portal checking for timezone that the agency is set to and is there a
 * middleware setup to ensure this never happens again on the entire portal". The server
 * clock is UTC and must stay UTC, so "today" has to be asked of the AGENCY: this binds the
 * request's agency to App\Support\AgencyTime, and AgencyTime::today() / now() /
 * todayDate() / boundaryUtc() / tz() with no agency id then answer in that agency's zone.
 *
 * Which agency: AuditScope's rules (a platform admin's selected agency via
 * X-Active-Agency-Id, validated; everyone else their own agency, never a header they send).
 * Lazy: nothing is looked up until something asks, and authentication (which runs after
 * this group middleware) has happened by then. Costs nothing on requests that never ask.
 */
final class ResolveAgencyTimezone
{
    public function handle(Request $request, Closure $next): Response
    {
        AgencyTime::bindRequest(static function () use ($request): ?int {
            $user = $request->user();

            return $user ? AuditScope::resolve((int) $user->id, $request) : null;
        });

        try {
            return $next($request);
        } finally {
            // Never let one request's agency leak into the next one handled by the same worker.
            AgencyTime::bindRequest(null);
        }
    }
}
