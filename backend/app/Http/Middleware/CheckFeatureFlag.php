<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * v15: Feature flag middleware.
 *
 * Usage in routes:
 *   Route::middleware('feature:lesson_plans')->group(function () {
 *       Route::get('/provider/lesson-plans', ...);
 *   });
 *
 * Resolves the agency from the authenticated user, reads
 * agencies.feature_flags (JSON), and either lets the request through
 * or returns 403 with a clear "feature not available on your plan"
 * message.
 *
 * Default behavior when the flag is absent / null / agencies row
 * is missing: ALLOW. This is important — we don't want existing
 * agencies that haven't had flags configured yet to get locked out
 * of features they've been using. New flags need to be opted-OUT,
 * not opted-IN.
 */
final class CheckFeatureFlag
{
    public function handle(Request $request, Closure $next, string $flag): Response
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        // Resolve agency: users.agency_id is the most common shape; if not present,
        // fall back to users.centre_id -> centres.agency_id.
        $agencyId = null;
        if (isset($user->agency_id)) {
            $agencyId = $user->agency_id;
        } elseif (isset($user->centre_id)) {
            $row = DB::table('centres')->where('id', $user->centre_id)->first(['agency_id']);
            if ($row) $agencyId = $row->agency_id;
        }

        if (! $agencyId) {
            // No agency context — let the request through, the controller will
            // do its own auth. We're a feature gate, not an auth gate.
            return $next($request);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first(['feature_flags', 'plan_code']);
        if (! $agency) return $next($request);

        $flags = [];
        if (! empty($agency->feature_flags)) {
            try {
                $flags = json_decode($agency->feature_flags, true) ?: [];
            } catch (\Throwable $e) {
                $flags = [];
            }
        }

        // Allow if: flag isn't set (unknown = allow), or flag is truthy
        if (! array_key_exists($flag, $flags) || $flags[$flag]) {
            return $next($request);
        }

        return response()->json([
            'message' => 'This feature is not available on your current plan.',
            'feature' => $flag,
            'plan'    => $agency->plan_code ?? null,
            'contact' => 'Contact your account manager to upgrade.',
        ], 403);
    }
}
