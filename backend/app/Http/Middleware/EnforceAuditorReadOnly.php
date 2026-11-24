<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * v22p94 — Enforce that pure-auditor accounts are READ-ONLY everywhere.
 *
 * The role middleware only gates routes that declare `role:`; many routes are
 * auth-only, so without this an auditor could mutate data on ungated routes.
 * A user whose ONLY active role is `auditor` is blocked from any non-read
 * (POST/PUT/PATCH/DELETE) request. Users who also hold another role are governed
 * by that role, so they pass.
 */
final class EnforceAuditorReadOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        // Resolve the bearer user independently of route-level auth ordering.
        $user = $request->user() ?: auth('sanctum')->user();
        if ($user) {
            /* The roles of THAT user, in the agency being worked in (2026-11-05 leak sweep). This read
               UserRoles::names($request), which goes through $request->user() - empty here for an app
               token, because group middleware runs before auth:sanctum - so the gate saw no roles and
               never refused anyone. And an auditor here who holds another role in a different agency is
               still read-only here. The header is already one of the user's agencies (NormalizeActiveAgency). */
            $agency = (int) $request->header('X-Active-Agency-Id');
            $roles = \Illuminate\Support\Facades\DB::table('role_assignments as ra')
                ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $user->id)->where('ra.active', true)
                ->when($agency > 0, fn ($q) => $q->where(fn ($w) => $w->where('ra.role', 'platform_admin')
                    ->orWhere('ra.agency_id', $agency)->orWhere('c.agency_id', $agency)))
                ->distinct()->pluck('ra.role')->all();
            if (in_array('auditor', $roles, true) && count(array_diff($roles, ['auditor'])) === 0) {
                return response()->json(['message' => 'Auditor accounts are read-only.'], 403);
            }
        }
        return $next($request);
    }
}
