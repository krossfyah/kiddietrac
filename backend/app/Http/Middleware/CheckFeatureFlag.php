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

        /* THE AGENCY COMES FROM role_assignments, NOT FROM THE USER ROW (2026-09-17).
         *
         * This read `$user->agency_id`, falling back to `$user->centre_id` — and the
         * users table has NEITHER column. Both `isset()` calls were always false, so
         * `$agencyId` was always null, and null takes the "let the request through"
         * branch below. The gate could not have refused anything, on any route, ever.
         * It was never registered as a middleware alias either, so nothing noticed.
         *
         * Membership in this product lives in `role_assignments`, and a platform admin
         * works inside whichever agency they have switched into — the same rule the rest
         * of the API follows through X-Active-Agency-Id. */
        $agencyId = $this->resolveAgency($request, $user);

        if (! $agencyId) {
            // No agency context — let the request through; the controller does its own
            // auth. This is a feature gate, not an auth gate.
            return $next($request);
        }

        $flags = \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($agencyId);

        // Unknown feature, or switched on: allowed. Absent means allowed by design —
        // agencies that predate a flag must not lose the feature the day it ships.
        if (! array_key_exists($flag, $flags) || $flags[$flag]) {
            return $next($request);
        }

        $plan = DB::table('agencies')->where('id', $agencyId)->value('plan_code');

        return response()->json([
            'message' => 'This feature is not available on your current plan.',
            'feature' => $flag,
            'plan'    => $plan ?: null,
            'contact' => 'Contact your account manager to upgrade.',
        ], 403);
    }

    /**
     * Which agency is this request acting inside?
     *
     * The active-agency header is honoured only for somebody who actually holds a role
     * there, or for a platform admin — the same test the rest of the API applies, so a
     * header cannot be used to shop for an agency whose flags are more generous.
     */
    private function resolveAgency(Request $request, $user): ?int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header) {
            $mayUse = DB::table('role_assignments')
                ->where('user_id', $user->id)->where('active', true)
                ->where(function ($q) use ($header) {
                    $q->where('agency_id', $header)->orWhere('role', 'platform_admin');
                })->exists();
            if ($mayUse) {
                return $header;
            }
        }

        $direct = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereNotNull('agency_id')->value('agency_id');
        if ($direct) {
            return (int) $direct;
        }

        // Centre-only roles (educators, directors) reach their agency through the centre.
        $viaCentre = DB::table('role_assignments as ra')
            ->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $user->id)->where('ra.active', true)
            ->value('c.agency_id');

        return $viaCentre ? (int) $viaCentre : null;
    }
}
