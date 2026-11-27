<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class EnsureRole
{
    /**
     * Restrict route to users who hold at least one of the given roles.
     *
     * Usage: ->middleware('role:educator,centre_director,agency_admin')
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (self::passes($request, $roles)) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Forbidden. Required role: '.implode(' or ', $roles),
            'your_roles' => \App\Support\AgencyRole::names($request),
        ], 403);
    }

    /**
     * THE ROLE RULE ITSELF, for the middleware and for App\Support\RouteAccess (a screen asking "may this person
     * call that route?" so it can hide a button - one rule, never a copy in the front end).
     *
     * @param  string[] $roles
     */
    public static function passes(Request $request, array $roles): bool
    {
        // Roles held in the agency this request is acting in (X-Active-Agency-Id), not anywhere (2026-10-31).
        $userRoles = \App\Support\AgencyRole::names($request);

        // v22p21: platform_admin is above all standard tenant roles.
        // It passes any role check, no matter what is listed in the
        // middleware string. Use it sparingly — it bypasses agency scoping.
        if (in_array('platform_admin', $userRoles, true)) {
            return true;
        }

        return (bool) array_intersect($roles, $userRoles);
    }
}
