<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CustomRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * CUSTOM ROLES ARE ENFORCED HERE (2026-11-28). For a person holding a custom role in the agency they are working
 * in, a route whose permission the role turns off answers 403 with a plain sentence, and a route no map knows
 * answers 403 too (fail closed). Everyone else - every built-in role - passes straight through: CustomRoles::
 * context() is null for them and nothing below runs.
 *
 * Group middleware runs before auth:sanctum, so the person comes from the sanctum guard (as EnforceAuditorReadOnly).
 * Refusals are written to permission_shadow_log (matrix_decision 'enforced') so the platform sees them on the
 * Permission check tab; that write happens after the response, like PermissionShadow's.
 */
final class EnforceCustomRole
{
    public function handle(Request $request, Closure $next): Response
    {
        $method = strtoupper($request->method());
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return $next($request);
        }
        $route = $request->route();
        if (! $route || ! is_object($route)) {
            return $next($request);
        }
        $user = $request->user() ?: auth('sanctum')->user();
        if (! $user) {
            return $next($request);
        }
        $ctx = CustomRoles::forRequest($request, (int) $user->id);
        if ($ctx === null) {
            return $next($request);   // built-in roles: untouched
        }
        $uri = $route->uri();
        if (CustomRoles::allows($ctx, $method, $uri)) {
            return $next($request);
        }
        // A public door (no sign-in needed) is never a custom role's to refuse, token or not.
        $authed = false;
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'auth:')) { $authed = true; break; }
        }
        if (! $authed) {
            return $next($request);
        }
        $key = CustomRoles::keyFor($method, $uri);
        $request->attributes->set('kt.custom_role.refused', [$key, $ctx, (int) $user->id, $method, $uri]);

        return response()->json([
            'message' => CustomRoles::refusalMessage($ctx, $key),
            'permission' => $key,
            'custom_role' => true,
        ], 403);
    }

    public function terminate(Request $request, Response $response): void
    {
        $r = $request->attributes->get('kt.custom_role.refused');
        if (! $r) {
            return;
        }
        try {
            [$key, $ctx, $uid, $method, $uri] = $r;
            $now = now();
            DB::statement(
                'INSERT INTO permission_shadow_log (day, user_id, roles, agency_id, method, route, path, permission, matrix_decision, actual_status, hits, first_seen, last_seen)
                 VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)
                 ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), actual_status = VALUES(actual_status), path = VALUES(path)',
                [
                    $now->toDateString(), $uid,
                    mb_substr('custom:' . implode(',', array_map(fn ($x) => $x['id'] . '/' . $x['base_role'], $ctx['roles'])), 0, 191),
                    $ctx['agency_id'], $method, mb_substr($uri, 0, 191), mb_substr('/' . ltrim($request->path(), '/'), 0, 255),
                    $key ?? '(unmapped)', 'enforced', 403, $now, $now,
                ]
            );
        } catch (\Throwable $e) {
            // logging a refusal must never surface
        }
    }
}
