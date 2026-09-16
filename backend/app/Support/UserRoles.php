<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * One read of a user's active role assignments per request, shared by everything that
 * needs it.
 *
 * Measured 2026-08-29 while chasing "the system keeps freezing": `auth/me` — the simplest
 * authenticated call there is — cost ~17 database queries, and the API ceiling sat at
 * ~28 req/s while the CPU was 70% idle. The reason is that the SAME row set,
 *
 *     role_assignments WHERE user_id = ? AND active = 1
 *
 * is fetched independently by EnsureOnboarded, EnforceAuditorReadOnly, EnsureRole,
 * AuthorizesTenantAccess (twice) and getAgencyId() — and AdminController alone calls
 * getAgencyId() 53 times. Every one of those is the same handful of rows.
 *
 * ── WHY THE CACHE LIVES ON THE REQUEST ──────────────────────────────────────────────
 * NOT in a static, and this is the whole point. lsphp workers here are long-lived (one
 * was 7 hours old), so a static would survive from one request into the next INSIDE THE
 * SAME WORKER — and would then hand one user's roles to whoever the worker served next.
 * That is precisely the cross-tenant leak the standing rule exists to prevent, and it
 * would be invisible in testing because it only fires when two users share a worker.
 *
 * Request attributes are per-request by construction: the object is built for one
 * request and discarded. There is no code path by which this can outlive it.
 *
 * This changes NO authorisation logic. It returns the same rows the callers were already
 * fetching; it just stops fetching them five times.
 */
final class UserRoles
{
    private const KEY = 'kt.user_roles';

    /**
     * Active role assignments for the request's authenticated user.
     *
     * @return array<int,object>  rows with ->role, ->agency_id, ->centre_id
     */
    public static function all(Request $request): array
    {
        $user = $request->user();
        if (! $user) {
            return [];
        }

        // Keyed by user id as well as presence: a request that swaps the user mid-flight
        // (impersonation / view-as does exactly this) must not read the previous user's
        // roles back out of the bag.
        $cached = $request->attributes->get(self::KEY);
        if (is_array($cached) && ($cached['uid'] ?? null) === $user->id) {
            return $cached['rows'];
        }

        $rows = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get(['role', 'agency_id', 'centre_id'])
            ->all();

        $request->attributes->set(self::KEY, ['uid' => $user->id, 'rows' => $rows]);

        return $rows;
    }

    /** Distinct role names. */
    public static function names(Request $request): array
    {
        return array_values(array_unique(array_map(
            fn ($r) => (string) $r->role,
            self::all($request)
        )));
    }

    /** Does the user hold this role anywhere? */
    public static function has(Request $request, string $role): bool
    {
        foreach (self::all($request) as $r) {
            if ($r->role === $role) {
                return true;
            }
        }

        return false;
    }

    /** Is the user a member of this agency (any role)? */
    public static function inAgency(Request $request, int $agencyId): bool
    {
        foreach (self::all($request) as $r) {
            if ((int) $r->agency_id === $agencyId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drop the memo — call after writing role assignments inside a request, so anything
     * that re-checks afterwards sees the change rather than the pre-write picture.
     */
    public static function forget(Request $request): void
    {
        $request->attributes->remove(self::KEY);
    }
}
