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
            $roles = DB::table('role_assignments')
                ->where('user_id', $user->id)->where('active', true)
                ->pluck('role')->unique()->all();
            if (in_array('auditor', $roles, true) && count(array_diff($roles, ['auditor'])) === 0) {
                return response()->json(['message' => 'Auditor accounts are read-only.'], 403);
            }
        }
        return $next($request);
    }
}
