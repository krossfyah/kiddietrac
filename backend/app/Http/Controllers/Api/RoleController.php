<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Support\PermissionMap;
use App\Support\Permissions;

/**
 * v22p8 Phase A — custom roles CRUD.
 *
 * Agency-scoped. agency_admin role (string check, not custom yet) is the
 * only persona allowed to hit these endpoints (route middleware enforces).
 *
 * is_system rows:  permissions editable, name/key/description editable too
 *                  but key is enforced unique-per-agency. Cannot be deleted.
 * non-system rows: fully editable + deletable when no users assigned.
 *
 * PERMISSIONS STEP 1 (2026-10-06):
 *  - Built-in roles are edited by the PLATFORM OWNER only (decision 1); an agency that
 *    needs a different set makes a custom role.
 *  - A custom role has a scope: 'centre' (default) or 'agency', and agency is allowed only
 *    when it is based on Agency admin (decision 2).
 *  - The catalog and the built-in defaults come from App\Support\PermissionMap.
 *  - Platform-only: the watch-only permission check (shadow) summary and its kill switch.
 */
final class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);
        $this->ensureSystemRoles((int) $agencyId);

        $roles = DB::table('roles')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        $isPlatform = $this->isPlatform($request);
        return response()->json([
            'roles' => $roles->map(fn ($r) => $this->serialize($r))->all(),
            'can_edit_builtin' => $isPlatform,
            'is_platform' => $isPlatform,
            'base_roles' => array_keys(PermissionMap::DEFAULTS),
            'permissions_mode' => Permissions::mode(),
        ]);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        // Live-read from the migration's static catalog so the source of truth
        // stays in one place. The migration class is anonymous so we can't
        // reference it directly; rebuild the array here in lockstep instead.
        return response()->json([
            'catalog' => $this->catalogStatic(),
        ]);
    }

    public function show(Request $request, int $roleId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $role = DB::table('roles')
            ->where('id', $roleId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();

        if (! $role) return response()->json(['message' => 'Not found'], 404);

        return response()->json(['role' => $this->serialize($role)]);
    }

    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'base_role' => ['nullable', 'string', 'in:' . implode(',', array_keys(PermissionMap::DEFAULTS))],
            'scope' => ['nullable', 'string', 'in:centre,agency'],
        ]);
        $baseRole = $data['base_role'] ?? null;
        $scope = $data['scope'] ?? 'centre';
        if ($scope === 'agency' && $baseRole !== 'agency_admin') {
            return response()->json(['message' => 'Only a role based on Agency admin can cover the whole agency. Other custom roles are limited to one centre.'], 422);
        }

        $validKeys = array_keys($this->catalogStatic());
        // Starting from a built-in role with no keys ticked = take that role's current set.
        $start = $data['permissions'];
        if (! $start && $baseRole) {
            $baseRow = DB::table('roles')->where('agency_id', $agencyId)->where('key', $baseRole)->where('is_system', true)->whereNull('deleted_at')->value('permissions');
            $start = $baseRow ? (json_decode((string) $baseRow, true) ?: []) : PermissionMap::DEFAULTS[$baseRole];
        }
        $perms = array_values(array_unique(array_intersect($start, $validKeys)));

        // Derive a stable key slug from the name; ensure unique per agency.
        $base = Str::slug($data['name'], '_');
        if ($base === '') $base = 'role_'.uniqid();
        $key = $base;
        $i = 1;
        while (DB::table('roles')->where('agency_id', $agencyId)->where('key', $key)->exists()) {
            $key = $base.'_'.(++$i);
        }

        $id = DB::table('roles')->insertGetId([
            'agency_id' => $agencyId,
            'key' => $key,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'permissions' => json_encode($perms),
            'scope' => $scope,
            'base_role' => $baseRole,
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Permissions::bump();

        return response()->json([
            'role' => $this->serialize(DB::table('roles')->find($id)),
        ], 201);
    }

    public function update(Request $request, int $roleId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $role = DB::table('roles')
            ->where('id', $roleId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $role) return response()->json(['message' => 'Not found'], 404);
        // Decision 1: built-in roles are the platform's to change. Agencies use custom roles.
        if ($role->is_system && ! $this->isPlatform($request)) {
            return response()->json(['message' => 'Built-in roles are managed by KiddieTrac. Create a custom role to give a different set of permissions.'], 403);
        }

        $rules = [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ];
        // System rows: name and key are immutable (they're referenced by code).
        if (! $role->is_system) {
            $rules['name'] = ['sometimes', 'string', 'max:120'];
            $rules['scope'] = ['sometimes', 'string', 'in:centre,agency'];
            $rules['base_role'] = ['sometimes', 'nullable', 'string', 'in:' . implode(',', array_keys(PermissionMap::DEFAULTS))];
        }

        $data = $request->validate($rules);
        if (! $role->is_system) {
            $scope = $data['scope'] ?? ($role->scope ?? 'centre');
            $base = array_key_exists('base_role', $data) ? $data['base_role'] : ($role->base_role ?? null);
            if ($scope === 'agency' && $base !== 'agency_admin') {
                return response()->json(['message' => 'Only a role based on Agency admin can cover the whole agency. Other custom roles are limited to one centre.'], 422);
            }
        }

        $update = ['updated_at' => now()];
        if (array_key_exists('name', $data)) $update['name'] = $data['name'];
        if (array_key_exists('description', $data)) $update['description'] = $data['description'];
        if (array_key_exists('scope', $data)) $update['scope'] = $data['scope'];
        if (array_key_exists('base_role', $data)) $update['base_role'] = $data['base_role'];
        if (array_key_exists('permissions', $data)) {
            $validKeys = array_keys($this->catalogStatic());
            $update['permissions'] = json_encode(array_values(array_unique(array_intersect($data['permissions'], $validKeys))));
        }

        DB::table('roles')->where('id', $roleId)->update($update);
        Permissions::bump();

        return response()->json([
            'role' => $this->serialize(DB::table('roles')->find($roleId)),
        ]);
    }

    public function destroy(Request $request, int $roleId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $role = DB::table('roles')
            ->where('id', $roleId)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $role) return response()->json(['message' => 'Not found'], 404);
        if ($role->is_system) return response()->json(['message' => 'System roles cannot be deleted'], 422);

        DB::table('roles')->where('id', $roleId)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        Permissions::bump();

        return response()->json(['message' => 'Role archived']);
    }

    /**
     * GET /admin/permission-shadow — PLATFORM ONLY. The watch-only permission check:
     * disagreements between the roles matrix and what really happened, grouped by
     * permission and role (counts, people, first / last seen), the window end date, and
     * the keys known to be too coarse (CONFLICTS).
     */
    public function shadowSummary(Request $request): JsonResponse
    {
        if (! $this->isPlatform($request)) return response()->json(['message' => 'Platform only'], 403);
        $groups = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('permission_shadow_log')) {
            $groups = DB::table('permission_shadow_log')
                ->selectRaw('permission, roles, matrix_decision, COUNT(DISTINCT user_id) as people, SUM(hits) as hits, COUNT(DISTINCT CONCAT(method, " ", route)) as routes,
                    MIN(first_seen) as first_seen, MAX(last_seen) as last_seen, GROUP_CONCAT(DISTINCT CONCAT(method, " /", route, " → ", actual_status) ORDER BY route SEPARATOR "\n") as samples')
                ->groupBy('permission', 'roles', 'matrix_decision')
                ->orderByDesc('hits')
                ->limit(500)->get()
                ->map(function ($g) {
                    $g->samples = array_slice(explode("\n", (string) $g->samples), 0, 8);
                    // Known: the key is coarser than today's gates for these roles (CONFLICTS
                    // 'some') — expected until the key is split (B2), so shown as a count.
                    $c = PermissionMap::CONFLICTS[$g->permission] ?? null;
                    $rs = explode(',', (string) $g->roles);
                    $g->known = $g->matrix_decision === 'allow' && $c && ! array_intersect($rs, $c['all']) && array_intersect($rs, $c['some']);
                    return $g;
                })->all();
        }
        return response()->json([
            'mode' => Permissions::mode(),
            'started_at' => Permissions::shadowStartedAt(),
            'ends_on' => Permissions::shadowEndsOn(),
            'days' => Permissions::SHADOW_DAYS,
            'groups' => $groups,
            'conflicts' => PermissionMap::CONFLICTS,
            'auditor_target' => PermissionMap::AUDITOR_TARGET,
            'auditor_today' => PermissionMap::DEFAULTS['auditor'] ?? [],
            'first_enforced_area' => 'Settings & platform screens',
        ]);
    }

    /** POST /admin/permission-shadow/mode {mode: off|shadow} — PLATFORM ONLY. Enforce is refused in this step. */
    public function shadowMode(Request $request): JsonResponse
    {
        if (! $this->isPlatform($request)) return response()->json(['message' => 'Platform only'], 403);
        $mode = (string) $request->input('mode');
        try {
            Permissions::setMode($mode);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'agency_id' => null, 'action' => 'permissions.mode',
            'entity_type' => 'platform_setting', 'entity_id' => null,
            'payload' => json_encode(['mode' => $mode]), 'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255), 'created_at' => now(),
        ]);
        return response()->json(['mode' => Permissions::mode(), 'ends_on' => Permissions::shadowEndsOn()]);
    }

    // ───────────────────────────────────────────────────────────────────────

    private function isPlatform(Request $request): bool
    {
        return \App\Support\UserRoles::has($request, 'platform_admin');
    }

    private function resolveAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        // v22p21: platform_admin trumps tenant scope — they can target ANY agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            // platform_admin without header — default to the first agency.
            $first = DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($first) return (int) $first;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('agency_id', $activeId)
                ->where('active', true)
                ->exists()) {
            return $activeId;
        }
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }

    private function serialize(object $r): array
    {
        return [
            'id' => (int) $r->id,
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'permissions' => json_decode($r->permissions, true) ?: [],
            'is_system' => (bool) $r->is_system,
            'scope' => $r->is_system ? null : ($r->scope ?? 'centre'),
            'base_role' => $r->base_role ?? null,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }

    /**
     * Permission catalog — duplicated from the migration so this controller
     * doesnt have to reach into an anonymous migration class. Keep these
     * two lists in sync. (Phase B will probably move to a single config file.)
     */
    /* THE FIVE SYSTEM ROLES, FOR EVERY AGENCY (2026-10-05). They were seeded once, by the
       v22p8 migration, for the agencies that existed that day (one). Every agency created
       since showed "0 records" on Roles & permissions. Same baseline as that migration;
       adds only the ones this agency is missing, never touches an existing row (whose
       permissions the agency may have edited) and never another agency's. */
    private function ensureSystemRoles(int $agencyId): void
    {
        // Step 1 (2026-10-06): the defaults are the real access today (PermissionMap).
        $names = [
            'agency_admin' => ['Agency Admin', 'Full access to every agency feature.'],
            'centre_director' => ['Centre Director', 'Operational lead at one centre.'],
            'educator' => ['Educator', 'Day-to-day care staff.'],
            'guardian' => ['Parent / Guardian', 'Self-serve parent portal.'],
            'home_visitor' => ['Home Visitor', 'Visits families and centres; files reports and inspections.'],
            'auditor' => ['Auditor', 'Read-only access for review.'],
        ];
        $baseline = [];
        foreach (PermissionMap::DEFAULTS as $key => $perms) {
            $baseline[$key] = [$names[$key][0] ?? $key, $names[$key][1] ?? null, $perms];
        }
        $have = DB::table('roles')->where('agency_id', $agencyId)->pluck('key')->all();   // incl. soft-deleted: unique key
        foreach ($baseline as $key => [$name, $desc, $perms]) {
            if (in_array($key, $have, true)) continue;
            DB::table('roles')->insertOrIgnore([
                'agency_id' => $agencyId, 'key' => $key, 'name' => $name, 'description' => $desc,
                'permissions' => json_encode($perms), 'is_system' => true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function catalogStatic(): array
    {
        return PermissionMap::CATALOG;
    }
}
