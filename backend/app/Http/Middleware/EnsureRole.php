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

        $userRoles = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->pluck('role')
            ->unique()
            ->all();

        foreach ($roles as $required) {
            if (in_array($required, $userRoles, true)) {
                return $next($request);
            }
        }

        return response()->json([
            'message' => 'Forbidden. Required role: '.implode(' or ', $roles),
            'your_roles' => $userRoles,
        ], 403);
    }
}
