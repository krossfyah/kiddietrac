<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\PermissionMap;
use App\Support\Permissions;
use App\Support\UserRoles;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * PERMISSION CHECK, WATCH-ONLY (permissions step 1, 2026-10-06).
 *
 * After each API call, asks the roles matrix "would this person be allowed?" and compares
 * that with what actually happened. Only DISAGREEMENTS are written, one row per
 * day + user + method + route (hits / first / last seen):
 *   matrix 'deny'  but the call went through (2xx/3xx, or 422 = past the role gates)
 *   matrix 'allow' but the call was refused (403)
 *
 * It never blocks and never throws. The work runs in terminate(), after the response has
 * been sent, so the person waiting sees no added time. Off unless permissions.mode=shadow.
 */
class PermissionShadow
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->observe($request, $response);
        } catch (\Throwable $e) {
            // watch-only: a failure here must never surface
        }
    }

    private function observe(Request $request, Response $response): void
    {
        $user = $request->user();
        $route = $request->route();
        if (! $user || ! $route || Permissions::mode() !== 'shadow') {
            return;
        }
        $method = strtoupper($request->method());
        if ($method === 'HEAD' || $method === 'OPTIONS') {
            return;
        }
        $uri = $route->uri();
        $key = PermissionMap::ROUTES[$method . ' ' . $uri] ?? null;
        if (! $key || Permissions::isPlatform($request)) {
            return;
        }
        $agencyId = Permissions::agencyFor($request);
        $roles = Permissions::rolesIn($request, $agencyId);
        if (! $roles) {
            return; // e.g. a sales rep: no matrix row describes them
        }
        $status = $response->getStatusCode();
        $allowed = Permissions::allows($request, $key);
        $wentThrough = $status < 400 || $status === 422;
        $disagree = (! $allowed && $wentThrough) || ($allowed && $status === 403);
        if (! $disagree) {
            return;
        }

        $now = now();
        DB::statement(
            'INSERT INTO permission_shadow_log (day, user_id, roles, agency_id, method, route, path, permission, matrix_decision, actual_status, hits, first_seen, last_seen)
             VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?)
             ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), actual_status = VALUES(actual_status), path = VALUES(path), roles = VALUES(roles)',
            [
                $now->toDateString(), $user->id, implode(',', $roles), $agencyId, $method,
                mb_substr($uri, 0, 191), mb_substr('/' . ltrim($request->path(), '/'), 0, 255), $key,
                $allowed ? 'allow' : 'deny', $status, $now, $now,
            ]
        );
    }
}
