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
use App\Support\CustomRoles;
use App\Support\PermissionRoutes;

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
 *
 * CUSTOM ROLES (2026-11-28): a custom role is always BASED ON a built-in role (App\Support\CustomRoles::BASES) and
 * keeps a subset of what that base offers; it is enforced for whoever holds it (EnforceCustomRole). Its reach comes
 * from the base (agency for Agency admin and Auditor, otherwise the centres the person is placed at), so `scope` is
 * derived, never chosen. Duplicate, delete (refused while anyone holds it), and every change is audited.
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
        $holders = CustomRoles::holderCounts((int) $agencyId);

        $isPlatform = $this->isPlatform($request);
        $offered = [];
        foreach (CustomRoles::BASES as $b) {
            $offered[$b] = CustomRoles::offered($b);
        }
        return response()->json([
            'roles' => $roles->map(fn ($r) => $this->serialize($r, $holders))->all(),
            'can_edit_builtin' => $isPlatform,
            'is_platform' => $isPlatform,
            'base_roles' => CustomRoles::BASES,
            'base_labels' => CustomRoles::BASE_LABELS,
            // What a role based on each built-in may keep or turn off, the groups the screen shows them in, and the
            // screen each permission opens (the "what this person will see" preview).
            'offered' => $offered,
            'groups' => PermissionMap::GROUPS,
            'screens' => PermissionRoutes::SCREENS,
            'permissions_mode' => Permissions::mode(),
            'custom_enforced' => true,
        ]);
    }

    public function permissionCatalog(Request $request): JsonResponse
    {
        return response()->json([
            'catalog' => $this->catalogStatic(),
            'groups' => PermissionMap::GROUPS,
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

        $out = $this->serialize($role, CustomRoles::holderCounts((int) $agencyId));
        if (! $role->is_system) {
            // Who holds it (this agency's people only).
            $out['holder_list'] = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.custom_role_id', $role->id)->where('ra.active', true)
                ->select('u.id', 'u.first_name', 'u.last_name')->distinct()->orderBy('u.first_name')->limit(200)->get()
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name)])->all();
            $out['hidden_screens'] = CustomRoles::hiddenScreensForSet((string) $role->base_role, $out['permissions']);
        }
        return response()->json(['role' => $out]);
    }

    /**
     * POST /admin/roles - a custom role, always BASED ON a built-in one (2026-11-28). `permissions` = the keys it
     * keeps; only keys its base offers survive. Empty = keep everything its base offers.
     */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'max:100'],
            'base_role' => ['required', 'string', 'in:' . implode(',', CustomRoles::BASES)],
        ], [
            'base_role.required' => 'Pick the built-in role this one is based on.',
            'base_role.in' => 'A custom role can be based on Agency admin, Centre director, Educator, Home visitor or Auditor.',
        ]);
        $name = trim((string) $data['name']);
        if ($this->nameTaken((int) $agencyId, $name)) {
            return response()->json(['message' => 'There is already a role called "' . $name . '". Pick another name.'], 422);
        }
        $base = (string) $data['base_role'];
        $perms = $data['permissions'] ? CustomRoles::clean($base, $data['permissions']) : CustomRoles::offered($base);
        $id = $this->insertCustom((int) $agencyId, $name, $data['description'] ?? null, $base, $perms);
        $this->auditRole($request, (int) $agencyId, 'roles.custom_created', $id, [
            'name' => $name, 'base_role' => $base, 'kept' => count($perms), 'turned_off' => CustomRoles::refusedFor($base, $perms),
        ]);

        return response()->json(['role' => $this->serialize(DB::table('roles')->find($id), [])], 201);
    }

    /** POST /admin/roles/{role}/duplicate {name?} - a copy of a built-in role (as a custom role on it) or of a custom one. */
    public function duplicate(Request $request, int $roleId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);
        $src = DB::table('roles')->where('id', $roleId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $src) return response()->json(['message' => 'Not found'], 404);
        $base = $src->is_system ? (string) $src->key : (string) $src->base_role;
        if (! in_array($base, CustomRoles::BASES, true)) {
            return response()->json(['message' => 'A custom role cannot be based on ' . $src->name . '.'], 422);
        }
        $data = $request->validate(['name' => ['nullable', 'string', 'max:120']]);
        $name = trim((string) ($data['name'] ?? '')) ?: ('Copy of ' . $src->name);
        if ($this->nameTaken((int) $agencyId, $name)) {
            return response()->json(['message' => 'There is already a role called "' . $name . '". Pick another name.'], 422);
        }
        $perms = $src->is_system
            ? CustomRoles::offered($base)
            : CustomRoles::clean($base, json_decode((string) $src->permissions, true) ?: []);
        $id = $this->insertCustom((int) $agencyId, $name, $src->is_system ? null : $src->description, $base, $perms);
        $this->auditRole($request, (int) $agencyId, 'roles.custom_duplicated', $id, [
            'name' => $name, 'from_role_id' => (int) $src->id, 'from' => $src->name, 'base_role' => $base,
        ]);

        return response()->json(['role' => $this->serialize(DB::table('roles')->find($id), [])], 201);
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
        if (! $role->is_system) {
            $rules['name'] = ['sometimes', 'string', 'max:120'];
            $rules['base_role'] = ['sometimes', 'string', 'in:' . implode(',', CustomRoles::BASES)];
        }
        $data = $request->validate($rules);

        $update = ['updated_at' => now()];
        $before = json_decode((string) $role->permissions, true) ?: [];
        if ($role->is_system) {
            if (array_key_exists('description', $data)) $update['description'] = $data['description'];
            if (array_key_exists('permissions', $data)) {
                $update['permissions'] = json_encode(array_values(array_unique(array_intersect($data['permissions'], array_keys($this->catalogStatic())))));
            }
        } else {
            $base = (string) ($data['base_role'] ?? $role->base_role);
            if ($base !== $role->base_role && (CustomRoles::holderCounts((int) $agencyId)[(int) $role->id] ?? 0) > 0) {
                return response()->json(['message' => 'People hold this role, so its base cannot change. Make a new role with the other base instead.'], 422);
            }
            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') return response()->json(['message' => 'Give the role a name.'], 422);
                if ($this->nameTaken((int) $agencyId, $name, (int) $role->id)) {
                    return response()->json(['message' => 'There is already a role called "' . $name . '". Pick another name.'], 422);
                }
                $update['name'] = $name;
            }
            if (array_key_exists('description', $data)) $update['description'] = $data['description'];
            $update['base_role'] = $base;
            $update['scope'] = in_array($base, ['agency_admin', 'auditor'], true) ? 'agency' : 'centre';
            $kept = array_key_exists('permissions', $data) ? $data['permissions'] : $before;
            $update['permissions'] = json_encode(CustomRoles::clean($base, $kept));
        }

        DB::table('roles')->where('id', $roleId)->update($update);
        Permissions::bump();
        $after = json_decode((string) ($update['permissions'] ?? $role->permissions), true) ?: [];
        $this->auditRole($request, (int) $agencyId, $role->is_system ? 'roles.builtin_updated' : 'roles.custom_updated', (int) $role->id, array_filter([
            'name' => $update['name'] ?? $role->name,
            'renamed_from' => isset($update['name']) && $update['name'] !== $role->name ? $role->name : null,
            'base_role' => $update['base_role'] ?? $role->base_role,
            'base_changed_from' => isset($update['base_role']) && $update['base_role'] !== $role->base_role ? $role->base_role : null,
            'turned_on' => array_values(array_diff($after, $before)),
            'turned_off' => array_values(array_diff($before, $after)),
        ], fn ($v) => $v !== null && $v !== []));

        return response()->json([
            'role' => $this->serialize(DB::table('roles')->find($roleId), CustomRoles::holderCounts((int) $agencyId)),
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
        if ($role->is_system) return response()->json(['message' => 'Built-in roles cannot be deleted.'], 422);
        // Never from under its holders (2026-11-28). Deactivated people's old rows count too: reactivating them
        // must not bring back a role that is gone.
        $held = DB::table('role_assignments')->where('custom_role_id', $role->id)->distinct()->count('user_id');
        if ($held > 0) {
            return response()->json(['message' => $held . ' ' . ($held === 1 ? 'person holds' : 'people hold') . ' "' . $role->name
                . '" (counting deactivated accounts). Give them another role in User management first.', 'holders' => $held], 422);
        }

        DB::table('roles')->where('id', $roleId)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        Permissions::bump();
        $this->auditRole($request, (int) $agencyId, 'roles.custom_deleted', (int) $role->id, ['name' => $role->name, 'base_role' => $role->base_role]);

        return response()->json(['message' => 'Role deleted']);
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

    private function serialize(object $r, array $holders = []): array
    {
        $perms = json_decode($r->permissions, true) ?: [];
        $base = $r->is_system ? (string) $r->key : (string) ($r->base_role ?? '');
        return [
            'id' => (int) $r->id,
            'key' => $r->key,
            'name' => $r->name,
            'description' => $r->description,
            'permissions' => $perms,
            // A built-in role's reach today (its row + the areas added since the matrix was written), for display.
            'reaches' => $r->is_system && in_array($base, CustomRoles::BASES, true)
                ? array_values(array_unique(array_merge($perms, PermissionMap::CUSTOM_OFFER[$base] ?? [])))
                : null,
            'is_system' => (bool) $r->is_system,
            'scope' => $r->is_system ? null : (in_array($base, ['agency_admin', 'auditor'], true) ? 'agency' : 'centre'),
            'base_role' => $r->is_system ? null : ($r->base_role ?? null),
            'can_be_base' => $r->is_system && in_array((string) $r->key, CustomRoles::BASES, true),
            'holders' => $r->is_system ? null : (int) ($holders[(int) $r->id] ?? 0),
            'turned_off' => $r->is_system || ! $base ? [] : CustomRoles::refusedFor($base, $perms),
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }

    private function nameTaken(int $agencyId, string $name, int $exceptId = 0): bool
    {
        return DB::table('roles')->where('agency_id', $agencyId)->whereNull('deleted_at')->where('id', '<>', $exceptId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists();
    }

    private function insertCustom(int $agencyId, string $name, ?string $description, string $base, array $perms): int
    {
        // A stable key slug from the name, unique per agency (archived rows included: the unique index covers them).
        $slug = Str::slug($name, '_');
        if ($slug === '') $slug = 'role_' . uniqid();
        $key = $slug;
        $i = 1;
        while (DB::table('roles')->where('agency_id', $agencyId)->where('key', $key)->exists()) {
            $key = $slug . '_' . (++$i);
        }
        $id = DB::table('roles')->insertGetId([
            'agency_id' => $agencyId, 'key' => $key, 'name' => $name, 'description' => $description,
            'permissions' => json_encode(array_values($perms)),
            'scope' => in_array($base, ['agency_admin', 'auditor'], true) ? 'agency' : 'centre',
            'base_role' => $base, 'is_system' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Permissions::bump();
        return (int) $id;
    }

    private function auditRole(Request $request, int $agencyId, string $action, int $roleId, array $payload): void
    {
        \App\Support\Audit::write([
            'user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
            'entity_type' => 'role', 'entity_id' => $roleId, 'payload' => json_encode($payload), 'created_at' => now(),
        ]);
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
