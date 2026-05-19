<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * v22p8 Phase A — custom roles CRUD.
 *
 * Agency-scoped. agency_admin role (string check, not custom yet) is the
 * only persona allowed to hit these endpoints (route middleware enforces).
 *
 * is_system rows:  permissions editable, name/key/description editable too
 *                  but key is enforced unique-per-agency. Cannot be deleted.
 * non-system rows: fully editable + deletable when no users assigned.
 */
final class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $roles = DB::table('roles')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('is_system', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json([
            'roles' => $roles->map(fn ($r) => $this->serialize($r))->all(),
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
        ]);

        $validKeys = array_keys($this->catalogStatic());
        $perms = array_values(array_unique(array_intersect($data['permissions'], $validKeys)));

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
            'is_system' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

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

        $rules = [
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'max:100'],
        ];
        // System rows: name and key are immutable (they're referenced by code).
        if (! $role->is_system) {
            $rules['name'] = ['sometimes', 'string', 'max:120'];
        }

        $data = $request->validate($rules);

        $update = ['updated_at' => now()];
        if (array_key_exists('name', $data)) $update['name'] = $data['name'];
        if (array_key_exists('description', $data)) $update['description'] = $data['description'];
        if (array_key_exists('permissions', $data)) {
            $validKeys = array_keys($this->catalogStatic());
            $update['permissions'] = json_encode(array_values(array_unique(array_intersect($data['permissions'], $validKeys))));
        }

        DB::table('roles')->where('id', $roleId)->update($update);

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

        return response()->json(['message' => 'Role archived']);
    }

    // ───────────────────────────────────────────────────────────────────────

    private function resolveAgencyId(Request $request): ?int
    {
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
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }

    /**
     * Permission catalog — duplicated from the migration so this controller
     * doesnt have to reach into an anonymous migration class. Keep these
     * two lists in sync. (Phase B will probably move to a single config file.)
     */
    private function catalogStatic(): array
    {
        return [
            'centres.view' => 'View centre list and details',
            'centres.edit' => 'Edit centre settings and branding',
            'centres.create' => 'Create new centres in the agency',
            'centres.delete' => 'Delete (archive) centres',
            'families.view' => 'View all families at scoped centres',
            'families.edit' => 'Edit family records',
            'families.invite' => 'Send invitations to new families',
            'children.view' => 'View all child records',
            'children.edit' => 'Edit child records (name, medical, photo, etc.)',
            'children.archive' => 'Archive (soft-delete) child records',
            'own_children.view' => 'View own children only (parent scope)',
            'staff.view' => 'View staff list',
            'staff.invite' => 'Invite new staff',
            'staff.schedule' => 'Create + edit staff shifts and timesheets',
            'staff.certifications' => 'Manage staff certifications',
            'rooms.view' => 'View room layout and rosters',
            'rooms.edit' => 'Create / edit / archive rooms',
            'invoices.view' => 'View invoices',
            'invoices.generate' => 'Generate monthly invoice batches',
            'invoices.refund' => 'Issue refunds',
            'own_invoices.view' => 'View own invoices only (parent scope)',
            'own_invoices.pay' => 'Pay own invoices (parent scope)',
            'reports.compliance' => 'Run + export compliance reports',
            'reports.attendance' => 'Run + export attendance reports',
            'incidents.view' => 'View incident reports',
            'incidents.create' => 'Create new incident reports',
            'incidents.review' => 'Review submitted incidents',
            'incidents.notify_parent' => 'Send incident reports to parents',
            'incidents.acknowledge' => 'Acknowledge incident reports (parent scope)',
            'medications.view' => 'View medication records',
            'medications.authorize' => 'Authorize new medications',
            'observations.view' => 'View child observations',
            'observations.create' => 'Create observations',
            'lesson_plans.view' => 'View lesson plans',
            'lesson_plans.create' => 'Create + publish lesson plans',
            'attendance.record' => 'Record check-in / check-out events',
            'kiosk.manage' => 'Manage kiosk tokens + parent PINs',
            'edocuments.view' => 'View eDocuments',
            'edocuments.send' => 'Send eDocuments to families',
            'edocuments.sign' => 'Sign eDocuments (parent scope)',
            'announcements.create' => 'Create + send announcements',
            'settings.edit' => 'Edit agency settings (branding, billing, etc.)',
            'roles.manage' => 'Create + edit custom roles',
            'mfa.enforce' => 'Require MFA for other staff users',
            'audit.view' => 'View the audit log',
        ];
    }
}
