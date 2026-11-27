<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Middleware\EnsureRole;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * MAY THIS PERSON CALL THAT ROUTE? (2026-11-27, Payroll showed directors "+ Generate payroll" while the route behind
 * it is agency-admin only, so the button only ever ended in "Forbidden".)
 *
 * The answer comes from the route's OWN `role:` middleware, evaluated with EnsureRole's rule, so a screen hides a
 * button with exactly the permission the server will apply - change the route and the button follows. Use it in the
 * payload a screen already loads: 'can' => ['generate' => RouteAccess::allows($request, 'POST', 'api/v1/...')].
 *
 * Fails closed: an unknown route, or one that cannot be matched, is false.
 */
final class RouteAccess
{
    public static function allows(Request $request, string $method, string $uri): bool
    {
        if (! $request->user()) {
            return false;
        }
        try {
            $route = Route::getRoutes()->match(Request::create('/' . ltrim($uri, '/'), strtoupper($method)));
        } catch (Throwable $e) {
            return false;
        }
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'role:')) {
                $roles = array_values(array_filter(array_map('trim', explode(',', substr($m, 5)))));
                if (! EnsureRole::passes($request, $roles)) {
                    return false;
                }
            }
        }

        return true;
    }
}
