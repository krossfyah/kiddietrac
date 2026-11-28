<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CUSTOM ROLES (2026-11-28, Anthony: "custom roles yes build it out").
 *
 * A custom role is an agency's own job title BUILT ON a built-in role (agency admin, centre director, educator,
 * home visitor or auditor). It can only take abilities AWAY from its base:
 *
 *   - role_assignments.role keeps the BASE role, so every data-scope rule in the portal (agency, CentreScope, child
 *     scope, Live, EnsureRole, the auditor read-only gate) sees exactly the base role. A custom role cannot widen
 *     data scope and can never be a platform, sales, reseller or integration role.
 *   - role_assignments.custom_role_id points at the agency's `roles` row. NULL = the built-in role, untouched.
 *   - What a base may turn off = PermissionMap::DEFAULTS[base] + CUSTOM_OFFER[base] ("offered"). Keys the role
 *     leaves unticked are REFUSED; keys outside "offered" are not the custom role's to decide, so the base's own
 *     gates decide them, exactly as for the built-in role.
 *
 * Enforced ONLY for people holding a custom role (EnforceCustomRole middleware, RouteAccess, /auth/me
 * hidden_screens). Everyone on a built-in role takes none of these paths: context() returns null for them and every
 * caller then does what it did before.
 *
 * Routes: PermissionMap::ROUTES + PermissionRoutes::EXTRA give a route its key; PermissionRoutes::OPEN (self-service)
 * and ::NONE (public / platform-only) are not narrowed. ANY OTHER ROUTE IS REFUSED to a custom-role holder (fail
 * closed) and kt:permission-map-check alerts the platform the night it appears.
 */
final class CustomRoles
{
    /** The built-in roles a custom role can be based on. Never guardian, platform_admin, sales_rep, reseller_admin, integration. */
    public const BASES = ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'auditor'];

    public const BASE_LABELS = [
        'agency_admin' => 'Agency admin', 'centre_director' => 'Centre director', 'educator' => 'Educator',
        'home_visitor' => 'Home visitor', 'auditor' => 'Auditor',
    ];

    private const REQ_KEY = 'kt.custom_role.ctx';

    /** What a role based on $base may keep or turn off (sorted keys). Parent-scope keys are never a staff role's. */
    public static function offered(string $base): array
    {
        if (! in_array($base, self::BASES, true)) {
            return [];
        }
        $keys = array_merge(PermissionMap::DEFAULTS[$base] ?? [], PermissionMap::CUSTOM_OFFER[$base] ?? []);
        $keys = array_values(array_unique(array_filter($keys, fn ($k) => isset(PermissionMap::CATALOG[$k]) && ! str_starts_with($k, 'own_')
            && $k !== 'incidents.acknowledge')));
        sort($keys);

        return $keys;
    }

    /** The keys a role keeps, cleaned: only offered keys survive. */
    public static function clean(string $base, array $keys): array
    {
        $keep = array_values(array_intersect(self::offered($base), array_map('strval', $keys)));
        sort($keep);

        return $keep;
    }

    /** The keys a role row turns off. */
    public static function refusedFor(string $base, array $kept): array
    {
        return array_values(array_diff(self::offered($base), $kept));
    }

    /**
     * The custom-role picture for this person in this agency, or NULL when they hold no custom role there (the
     * built-in path: nothing is narrowed). ['refused' => keys, 'roles' => [{id, name, base_role}], 'agency_id'].
     *
     * Refused = the keys turned off in EVERY staff role the person holds in the agency (a built-in staff role there
     * turns nothing off, so holding one keeps its whole set). Parent keys stay with a staff member who is also a parent.
     * A custom role that is missing, archived, from another agency or on a different base refuses everything it could
     * (fail closed).
     */
    public static function context(int $userId, ?int $agencyId): ?array
    {
        if ($userId <= 0) {
            return null;
        }
        $rows = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->get(['role', 'agency_id', 'centre_id', 'custom_role_id']);
        if (! $rows->contains(fn ($r) => $r->custom_role_id !== null)) {
            return null;
        }
        if ($rows->contains(fn ($r) => $r->role === 'platform_admin')) {
            return null;   // the platform owner is never narrowed
        }
        if (! $agencyId) {
            // No agency in view: fall back to the agency of their custom role (fail closed rather than unnarrowed).
            $first = $rows->first(fn ($r) => $r->custom_role_id !== null);
            $agencyId = (int) DB::table('roles')->where('id', $first->custom_role_id)->value('agency_id');
            if (! $agencyId) {
                return null;
            }
        }
        $centres = AgencyRole::centreIds($agencyId);
        $inAgency = $rows->filter(fn ($r) => (int) $r->agency_id === $agencyId || ($r->centre_id && in_array((int) $r->centre_id, $centres, true)));
        $staff = $inAgency->filter(fn ($r) => in_array($r->role, self::BASES, true));
        if (! $staff->contains(fn ($r) => $r->custom_role_id !== null)) {
            return null;   // their custom role belongs to another agency: here they are on built-in roles only
        }
        $roleRows = DB::table('roles')->whereIn('id', $staff->pluck('custom_role_id')->filter()->unique()->all())
            ->get(['id', 'agency_id', 'name', 'base_role', 'permissions', 'deleted_at', 'is_system'])->keyBy('id');

        $refused = null;
        $held = [];
        foreach ($staff as $r) {
            if ($r->custom_role_id === null) {
                $off = [];
            } else {
                $cr = $roleRows->get((int) $r->custom_role_id);
                if ($cr && ! $cr->deleted_at && ! $cr->is_system && (int) $cr->agency_id === $agencyId && $cr->base_role === $r->role) {
                    $off = self::refusedFor((string) $r->role, json_decode((string) $cr->permissions, true) ?: []);
                    $held[(int) $cr->id] = ['id' => (int) $cr->id, 'name' => (string) $cr->name, 'base_role' => (string) $cr->base_role];
                } else {
                    $off = self::offered((string) $r->role);   // broken link: refuse all it could
                    $held[(int) $r->custom_role_id] = ['id' => (int) $r->custom_role_id, 'name' => 'Custom role (unavailable)', 'base_role' => (string) $r->role];
                }
            }
            $refused = $refused === null ? $off : array_values(array_intersect($refused, $off));
        }
        $refused = $refused ?? [];
        if ($inAgency->contains(fn ($r) => $r->role === 'guardian')) {
            $refused = array_values(array_diff($refused, PermissionMap::DEFAULTS['guardian'] ?? []));
        }
        sort($refused);

        return ['agency_id' => $agencyId, 'refused' => $refused, 'roles' => array_values($held)];
    }

    /** The context for a request, memoised on it (never a static: workers are long-lived). */
    public static function forRequest(Request $request, ?int $userId = null): ?array
    {
        $userId = $userId ?: (int) optional($request->user() ?: auth('sanctum')->user())->id;
        $agencyId = (int) $request->header('X-Active-Agency-Id') ?: null;
        $memo = $request->attributes->get(self::REQ_KEY);
        if (is_array($memo) && $memo['uid'] === $userId && $memo['aid'] === $agencyId) {
            return $memo['ctx'];
        }
        $ctx = rescue(fn () => self::context($userId, $agencyId), null, false);
        $request->attributes->set(self::REQ_KEY, ['uid' => $userId, 'aid' => $agencyId, 'ctx' => $ctx]);

        return $ctx;
    }

    /**
     * The permission a route belongs to: a key, 'open' (self-service, never narrowed), 'none' (public or
     * platform-only: the route's own gate decides) or null (unmapped: refused to custom roles).
     */
    public static function keyFor(string $method, string $uri): ?string
    {
        $k = strtoupper($method) . ' ' . ltrim($uri, '/');
        if (isset(PermissionMap::ROUTES[$k])) {
            return PermissionMap::ROUTES[$k];
        }
        if (isset(PermissionRoutes::EXTRA[$k])) {
            return PermissionRoutes::EXTRA[$k];
        }
        if (isset(PermissionRoutes::OPEN[$k])) {
            return 'open';
        }
        if (isset(PermissionRoutes::NONE[$k])) {
            return 'none';
        }

        return null;
    }

    /** May a custom-role context call this route? (true when the route is not the custom role's to decide). */
    public static function allows(?array $ctx, string $method, string $uri): bool
    {
        if ($ctx === null) {
            return true;
        }
        $key = self::keyFor($method, $uri);
        if ($key === 'open' || $key === 'none') {
            return true;
        }
        if ($key === null) {
            return false;   // fail closed
        }

        return ! in_array($key, $ctx['refused'], true);
    }

    /** Screens a custom role hides: those whose permission it turns off. */
    public static function hiddenScreens(?array $ctx): array
    {
        if ($ctx === null || ! $ctx['refused']) {
            return [];
        }
        $off = array_flip($ctx['refused']);
        $out = [];
        foreach (PermissionRoutes::SCREENS as $hash => $key) {
            if (isset($off[$key])) {
                $out[] = $hash;
            }
        }

        return $out;
    }

    /** The hidden screens for a base + kept set (the Roles screen preview). */
    public static function hiddenScreensForSet(string $base, array $kept): array
    {
        return self::hiddenScreens(['refused' => self::refusedFor($base, self::clean($base, $kept))]);
    }

    /** A plain sentence for a refusal. */
    public static function refusalMessage(?array $ctx, ?string $key): string
    {
        $names = $ctx ? implode(', ', array_map(fn ($r) => $r['name'], $ctx['roles'])) : 'your role';
        if ($key === null) {
            return 'This part of KiddieTrac is not set up for custom roles yet, so "' . $names . '" cannot open it. Ask your agency admin.';
        }
        $label = PermissionMap::CATALOG[$key] ?? $key;

        return 'Your role "' . $names . '" does not include: ' . $label . '. Ask your agency admin if you need it.';
    }

    /** Fingerprint for PlanStamp: changes when this person's custom-role answer changes ('' for built-in roles). */
    public static function stamp(int $userId, ?int $agencyId): string
    {
        $ctx = rescue(fn () => self::context($userId, $agencyId), null, false);

        return $ctx === null ? '' : 'cr:' . implode(',', $ctx['refused']) . '|' . json_encode($ctx['roles']);
    }

    /** How many people hold each custom role (active assignments), keyed by role id. */
    public static function holderCounts(int $agencyId): array
    {
        return DB::table('role_assignments as ra')->join('roles as r', 'r.id', '=', 'ra.custom_role_id')
            ->where('r.agency_id', $agencyId)->where('ra.active', true)
            ->groupBy('ra.custom_role_id')->selectRaw('ra.custom_role_id as id, COUNT(DISTINCT ra.user_id) as n')
            ->pluck('n', 'id')->map(fn ($v) => (int) $v)->all();
    }
}
