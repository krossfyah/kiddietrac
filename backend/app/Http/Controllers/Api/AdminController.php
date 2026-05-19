<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Admin controller: agency-admin level CRUD for centres, users, families, children.
 * All endpoints require role:agency_admin middleware.
 * All queries scope to the agency_admin's own agency_id.
 */
final class AdminController extends Controller
{
    // ════════════════════════════════════════════════════════════════
    //   Helpers
    // ════════════════════════════════════════════════════════════════

    private function getAgencyId(Request $request): ?int
    {
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }

    private function getCentreIds(int $agencyId): array
    {
        return DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();
    }

    private function audit(int $userId, string $action, ?string $entityType, ?int $entityId, array $payload = []): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'payload' => json_encode($payload),
            'created_at' => now(),
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   CENTRES
    // ════════════════════════════════════════════════════════════════

    public function listCentres(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centres = DB::table('centres')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        $result = $centres->map(function ($c) {
            $childrenCount = DB::table('children')
                ->join('enrollments', 'enrollments.child_id', '=', 'children.id')
                ->join('rooms', 'rooms.id', '=', 'enrollments.room_id')
                ->where('rooms.centre_id', $c->id)
                ->where('children.enrollment_status', 'enrolled')
                ->whereNull('enrollments.end_date')
                ->whereNull('children.deleted_at')
                ->distinct()
                ->count('children.id');

            $familyCount = DB::table('families')
                ->where('centre_id', $c->id)
                ->whereNull('deleted_at')
                ->count();

            $staffCount = DB::table('role_assignments')
                ->where('centre_id', $c->id)
                ->whereIn('role', ['centre_director', 'educator'])
                ->where('active', true)
                ->distinct()
                ->count('user_id');

            return [
                'id' => $c->id,
                'name' => $c->name,
                'slug' => $c->slug,
                'license_number' => $c->license_number,
                'license_capacity' => $c->license_capacity,
                'city' => $c->city,
                'province' => $c->province,
                'phone' => $c->phone,
                'email' => $c->email,
                'status' => $c->status,
                'cwelcc_enrolled' => (bool) $c->cwelcc_enrolled,
                // v22p3.4: per-centre branding
                'logo_url'     => $c->logo_url ?? null,
                'brand_color'  => $c->brand_color ?? null,
                'accent_color' => $c->accent_color ?? null,
                'tagline'      => $c->tagline ?? null,
                // v22p5.1: kiosk state surface in admin centres list
                'kiosk_enabled' => (bool) ($c->kiosk_enabled ?? false),
                'kiosk_token'   => $c->kiosk_token ?? null,
                'enrolled_count' => $childrenCount,
                'family_count' => $familyCount,
                'staff_count' => $staffCount,
                'capacity_pct' => $c->license_capacity > 0 ? round(($childrenCount / $c->license_capacity) * 100) : 0,
                'created_at' => $c->created_at,
            ];
        });

        return response()->json(['centres' => $result->all()]);
    }

    public function createCentre(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:180'],
            'license_number' => ['nullable', 'string', 'max:60'],
            'license_capacity' => ['nullable', 'integer', 'min:0', 'max:500'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:180'],
            'cwelcc_enrolled' => ['nullable', 'boolean'],
        ]);

        $slug = Str::slug($data['name']);
        // Ensure unique slug within agency
        $baseSlug = $slug;
        $i = 1;
        while (DB::table('centres')->where('agency_id', $agencyId)->where('slug', $slug)->exists()) {
            $slug = $baseSlug . '-' . ++$i;
        }

        $centreId = DB::table('centres')->insertGetId([
            'agency_id' => $agencyId,
            'name' => $data['name'],
            'slug' => $slug,
            'license_number' => $data['license_number'] ?? null,
            'license_capacity' => $data['license_capacity'] ?? 0,
            'address_line1' => $data['address_line1'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? 'ON',
            'postal_code' => $data['postal_code'] ?? null,
            'country' => 'CA',
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'cwelcc_enrolled' => !empty($data['cwelcc_enrolled']) ? 1 : 0,
            'status' => 'onboarding',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'centre.created', 'centre', $centreId, ['name' => $data['name']]);

        return response()->json(['id' => $centreId, 'message' => 'Centre created'], 201);
    }

    public function updateCentre(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not found'], 404);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:180'],
            'license_number' => ['sometimes', 'nullable', 'string', 'max:60'],
            'license_capacity' => ['sometimes', 'integer', 'min:0', 'max:500'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'province' => ['sometimes', 'nullable', 'string', 'max:40'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'cwelcc_enrolled' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:onboarding,active,paused,closed'],
            // v22p3.4: branding fields
            'brand_color'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'accent_color' => ['sometimes', 'nullable', 'string', 'max:20'],
            'tagline'      => ['sometimes', 'nullable', 'string', 'max:200'],
        ]);

        $data['updated_at'] = now();
        DB::table('centres')->where('id', $centreId)->update($data);

        $this->audit($request->user()->id, 'centre.updated', 'centre', $centreId, $data);

        return response()->json(['message' => 'Centre updated']);
    }

    public function archiveCentre(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not found'], 404);

        DB::table('centres')->where('id', $centreId)->update([
            'deleted_at' => now(),
            'status' => 'closed',
        ]);

        $this->audit($request->user()->id, 'centre.archived', 'centre', $centreId, []);
        return response()->json(['message' => 'Centre archived']);
    }

    // ════════════════════════════════════════════════════════════════
    //   USERS
    // ════════════════════════════════════════════════════════════════

    public function listUsers(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $roleFilter = $request->input('role');
        $searchQuery = $request->input('q');

        // Find all user_ids that have any role at this agency or its centres
        $userIdsQuery = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) {
                    $q->orWhereIn('centre_id', $centreIds);
                }
            });

        if ($roleFilter) {
            $userIdsQuery->where('role', $roleFilter);
        }

        $userIds = $userIdsQuery->pluck('user_id')->unique()->all();

        // Also include guardians of families at this agency's centres
        if ($roleFilter === null || $roleFilter === 'guardian') {
            $guardianUserIds = DB::table('guardians')
                ->join('families', 'families.id', '=', 'guardians.family_id')
                ->whereIn('families.centre_id', $centreIds ?: [0])
                ->pluck('guardians.user_id')
                ->all();
            $userIds = array_unique(array_merge($userIds, $guardianUserIds));
        }

        if (empty($userIds)) {
            return response()->json(['users' => []]);
        }

        $usersQuery = DB::table('users')
            ->whereIn('id', $userIds)
            ->whereNull('deleted_at');

        if ($searchQuery) {
            $usersQuery->where(function ($q) use ($searchQuery) {
                $q->where('email', 'like', "%{$searchQuery}%")
                  ->orWhere('first_name', 'like', "%{$searchQuery}%")
                  ->orWhere('last_name', 'like', "%{$searchQuery}%");
            });
        }

        $users = $usersQuery->orderBy('first_name')->limit(200)->get();

        // Get all roles for these users
        $allAssignments = DB::table('role_assignments')
            ->whereIn('user_id', $users->pluck('id'))
            ->where('active', true)
            ->get()
            ->groupBy('user_id');

        $allGuardianLinks = DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->whereIn('guardians.user_id', $users->pluck('id'))
            ->select('guardians.user_id', 'families.centre_id', 'families.family_name')
            ->get()
            ->groupBy('user_id');

        $result = $users->map(function ($u) use ($allAssignments, $allGuardianLinks) {
            $roles = ($allAssignments[$u->id] ?? collect())->pluck('role')->unique()->values()->all();
            $guardianLinks = $allGuardianLinks[$u->id] ?? collect();
            if ($guardianLinks->isNotEmpty() && !in_array('guardian', $roles)) {
                $roles[] = 'guardian';
            }

            // v22p3.4: surface profile_extras (incl. role_extras) so the
            // Manage modal can render educator credentials, etc.
            $extras = null;
            if (! empty($u->profile_extras)) {
                $extras = is_string($u->profile_extras)
                    ? (json_decode($u->profile_extras, true) ?: null)
                    : $u->profile_extras;
            }
            return [
                'id' => $u->id,
                'email' => $u->email,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'phone' => $u->phone,
                'photo_url' => $u->photo_url,
                'status' => $u->status,
                'last_login_at' => $u->last_login_at,
                'onboarded_at' => $u->onboarded_at ?? null,
                'profile_extras' => $extras,
                'roles' => $roles,
                'created_at' => $u->created_at,
            ];
        });

        return response()->json(['users' => $result->all()]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'email' => ['required', 'email', 'max:180', 'unique:users,email'],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor'],
            'centre_id' => ['nullable', 'integer'],
            'send_invite' => ['nullable', 'boolean'],
        ]);

        // Validate centre belongs to this agency if provided
        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        // Random temporary password (user resets via invite email or forgot-password)
        $tempPassword = Str::random(24);

        $userId = DB::table('users')->insertGetId([
            'email' => $data['email'],
            'password' => Hash::make($tempPassword),
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'phone' => $data['phone'] ?? null,
            'status' => 'invited',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_assignments')->insert([
            'user_id' => $userId,
            'role' => $data['role'],
            'agency_id' => $data['role'] === 'agency_admin' ? $agencyId : null,
            'centre_id' => $data['role'] !== 'agency_admin' ? ($data['centre_id'] ?? null) : null,
            'active' => 1,
            'created_at' => now(),
        ]);

        $this->audit($request->user()->id, 'user.created', 'user', $userId, [
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        // TODO: send invite email here if $data['send_invite']
        // For now, we just create the user; admin can use the forgot-password flow to send them a reset link

        return response()->json([
            'id' => $userId,
            'message' => 'User created',
            'note' => 'Have the user click "Forgot password" on the login page to set their password.',
        ], 201);
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        // Verify this user belongs to the admin's agency
        $belongs = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where(function ($q) use ($agencyId) {
                $centreIds = $this->getCentreIds($agencyId);
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->exists();

        if (!$belongs) return response()->json(['message' => 'User not in your agency'], 403);

        $data = $request->validate([
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'status' => ['sometimes', 'in:active,invited,suspended,deactivated'],
        ]);

        $data['updated_at'] = now();
        DB::table('users')->where('id', $userId)->update($data);

        $this->audit($request->user()->id, 'user.updated', 'user', $userId, $data);
        return response()->json(['message' => 'User updated']);
    }

    public function setUserRole(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor'],
            'centre_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ]);

        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        DB::table('role_assignments')->updateOrInsert(
            [
                'user_id' => $userId,
                'role' => $data['role'],
                'agency_id' => $data['role'] === 'agency_admin' ? $agencyId : null,
                'centre_id' => $data['role'] !== 'agency_admin' ? ($data['centre_id'] ?? null) : null,
            ],
            ['active' => $data['active'] ?? true]
        );

        $this->audit($request->user()->id, 'user.role_set', 'user', $userId, $data);
        return response()->json(['message' => 'Role updated']);
    }

    // ════════════════════════════════════════════════════════════════
    //   FAMILIES
    // ════════════════════════════════════════════════════════════════

    public function listFamilies(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $centreFilter = $request->input('centre_id');

        $query = DB::table('families')
            ->leftJoin('centres', 'centres.id', '=', 'families.centre_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->whereNull('families.deleted_at')
            ->select('families.*', 'centres.name as centre_name');

        if ($centreFilter) {
            $query->where('families.centre_id', $centreFilter);
        }

        $families = $query->orderBy('families.family_name')->limit(200)->get();

        $result = $families->map(function ($f) {
            $childCount = DB::table('children')
                ->where('family_id', $f->id)
                ->whereNull('deleted_at')
                ->count();
            $guardianCount = DB::table('guardians')->where('family_id', $f->id)->count();
            $balance = DB::table('invoices')
                ->where('family_id', $f->id)
                ->whereIn('status', ['sent', 'partial', 'overdue'])
                ->sum('balance_due');

            return [
                'id' => $f->id,
                'family_name' => $f->family_name,
                'centre_id' => $f->centre_id,
                'centre_name' => $f->centre_name,
                'primary_phone' => $f->primary_phone,
                'primary_email' => $f->primary_email,
                'city' => $f->city,
                'child_count' => $childCount,
                'guardian_count' => $guardianCount,
                'outstanding_balance' => (float) $balance,
                'created_at' => $f->created_at,
            ];
        });

        return response()->json(['families' => $result->all()]);
    }

    public function showFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centreIds = $this->getCentreIds($agencyId);

        $family = DB::table('families')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->where('id', $familyId)
            ->whereNull('deleted_at')
            ->first();

        if (!$family) return response()->json(['message' => 'Not found'], 404);

        $children = DB::table('children')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->get();

        $guardians = DB::table('guardians')
            ->join('users', 'users.id', '=', 'guardians.user_id')
            ->where('guardians.family_id', $familyId)
            ->select('guardians.*', 'users.email', 'users.first_name', 'users.last_name', 'users.phone', 'users.status')
            ->get();

        return response()->json([
            'family' => $family,
            'children' => $children,
            'guardians' => $guardians,
        ]);
    }

    /**
     * v22p11 — agency_admin can create a family at any centre in their agency.
     */
    public function createFamily(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $data = $request->validate([
            'family_name' => ['required', 'string', 'max:120'],
            'centre_id' => ['required', 'integer'],
            'primary_email' => ['nullable', 'email', 'max:180'],
            'primary_phone' => ['nullable', 'string', 'max:40'],
            'address_line1' => ['nullable', 'string', 'max:200'],
            'address_line2' => ['nullable', 'string', 'max:200'],
            'city' => ['nullable', 'string', 'max:80'],
            'province' => ['nullable', 'string', 'max:40'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'preferred_lang' => ['nullable', 'string', 'max:10'],
            'billing_split' => ['nullable', 'in:single,split_50_50,custom'],
            'notes' => ['nullable', 'string'],
        ]);

        // Centre must belong to this agency.
        $centreOwned = DB::table('centres')
            ->where('id', $data['centre_id'])
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->exists();
        if (! $centreOwned) {
            return response()->json([
                'message' => 'Centre not in your agency.',
                'errors' => ['centre_id' => ['You do not have access to that centre.']],
            ], 422);
        }

        $id = DB::table('families')->insertGetId(array_merge($data, [
            'preferred_lang' => $data['preferred_lang'] ?? 'en-CA',
            'billing_split' => $data['billing_split'] ?? 'single',
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        $this->audit($request->user()->id, 'create_family', 'family', $id, ['centre_id' => $data['centre_id']]);

        return response()->json([
            'family' => DB::table('families')->where('id', $id)->first(),
            'message' => 'Family created',
        ], 201);
    }

    /**
     * v22p11 — agency_admin can edit any family in their agency.
     */
    public function updateFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        $centreIds = $this->getCentreIds($agencyId);

        $family = DB::table('families')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->where('id', $familyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $family) return response()->json(['message' => 'Not found'], 404);

        $data = $request->validate([
            'family_name' => ['sometimes', 'string', 'max:120'],
            'centre_id' => ['sometimes', 'integer'],
            'primary_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'primary_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'address_line1' => ['sometimes', 'nullable', 'string', 'max:200'],
            'address_line2' => ['sometimes', 'nullable', 'string', 'max:200'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80'],
            'province' => ['sometimes', 'nullable', 'string', 'max:40'],
            'postal_code' => ['sometimes', 'nullable', 'string', 'max:12'],
            'preferred_lang' => ['sometimes', 'nullable', 'string', 'max:10'],
            'billing_split' => ['sometimes', 'in:single,split_50_50,custom'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        // If centre is being moved, verify the new centre is in this agency too.
        if (isset($data['centre_id']) && ! in_array((int) $data['centre_id'], $centreIds, true)) {
            return response()->json([
                'message' => 'Cannot move family to a centre outside your agency.',
                'errors' => ['centre_id' => ['Centre not in your agency.']],
            ], 422);
        }

        $data['updated_at'] = now();
        DB::table('families')->where('id', $familyId)->update($data);

        $this->audit($request->user()->id, 'update_family', 'family', $familyId, array_keys($data));

        return response()->json([
            'family' => DB::table('families')->where('id', $familyId)->first(),
            'message' => 'Family updated',
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   AGENCY ANALYTICS (richer than dashboard)
    // ════════════════════════════════════════════════════════════════

    public function analytics(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);

        // 30 days of new enrollments
        $thirtyDaysAgo = Carbon::now()->subDays(30);
        $newEnrollments = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->where('children.enrolled_at', '>=', $thirtyDaysAgo)
            ->whereNull('children.deleted_at')
            ->count();

        // 30 days of withdrawals
        $withdrawals = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->whereIn('families.centre_id', $centreIds ?: [0])
            ->where('children.withdrawn_at', '>=', $thirtyDaysAgo)
            ->count();

        // Revenue this month
        $monthStart = Carbon::now()->startOfMonth();
        $revenueThisMonth = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->whereIn('invoices.centre_id', $centreIds ?: [0])
            ->where('payments.status', 'succeeded')
            ->where('payments.paid_at', '>=', $monthStart)
            ->sum('payments.amount');

        // Outstanding receivables
        $outstanding = DB::table('invoices')
            ->whereIn('centre_id', $centreIds ?: [0])
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->sum('balance_due');

        // Total active users by role
        $byRole = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->select('role', DB::raw('count(distinct user_id) as cnt'))
            ->groupBy('role')
            ->pluck('cnt', 'role')
            ->all();

        return response()->json([
            'last_30_days' => [
                'new_enrollments' => $newEnrollments,
                'withdrawals' => $withdrawals,
                'net' => $newEnrollments - $withdrawals,
            ],
            'this_month' => [
                'revenue' => (float) $revenueThisMonth,
                'outstanding' => (float) $outstanding,
            ],
            'users_by_role' => $byRole,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   v22p1.2 — user lifecycle: delete, reset password, resend welcome
    // ════════════════════════════════════════════════════════════════

    /**
     * Verify a target user is within the admin's agency (staff via role_assignments,
     * or guardian via families.centre_id).
     */
    private function userBelongsToAgency(int $userId, int $agencyId): bool
    {
        $centreIds = $this->getCentreIds($agencyId);

        $hasStaffRole = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->exists();
        if ($hasStaffRole) return true;

        if (empty($centreIds)) return false;
        return DB::table('guardians')
            ->join('families', 'families.id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $userId)
            ->whereIn('families.centre_id', $centreIds)
            ->exists();
    }

    /**
     * DELETE /admin/users/{user}
     * Soft-delete the user, deactivate role assignments, revoke tokens.
     * Does not cascade to families or children — those keep their guardian link
     * intact for audit (but the user can no longer log in).
     */
    public function destroyUser(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if ($userId === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account.'], 422);
        }
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        DB::transaction(function () use ($userId) {
            DB::table('role_assignments')->where('user_id', $userId)->update([
                'active' => false,
            ]);
            // Revoke any sanctum tokens so the user is logged out instantly.
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->update([
                'status' => 'deactivated',
                'deleted_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $this->audit($request->user()->id, 'user.deleted', 'user', $userId, ['email' => $user->email]);

        return response()->json(['message' => 'User deleted', 'id' => $userId]);
    }

    /**
     * POST /admin/users/{user}/reset-password
     * Generate a fresh temporary password, set it on the user, and email them
     * a notice with the temp password and a "use Forgot password" link.
     * Body: { send_email?: bool, set_status_invited?: bool }
     */
    public function resetUserPassword(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $data = $request->validate([
            'send_email'           => ['nullable', 'boolean'],
            'set_status_invited'   => ['nullable', 'boolean'],
        ]);
        $sendEmail = $data['send_email'] ?? true;

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        // 12-char, mixed-case + digits. The user is encouraged to change it via Forgot password.
        $tempPassword = Str::random(12);

        DB::transaction(function () use ($userId, $tempPassword, $data) {
            $upd = ['password' => Hash::make($tempPassword), 'updated_at' => now()];
            if (!empty($data['set_status_invited'])) {
                $upd['status'] = 'invited';
            }
            DB::table('users')->where('id', $userId)->update($upd);
            // Revoke existing sessions so the old password really stops working.
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
        });

        $emailed = false;
        if ($sendEmail) {
            $emailed = $this->sendAccountEmail(
                $user->email,
                trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'Your Kiddietrac password has been reset',
                "Your administrator has reset your Kiddietrac password.\n\n" .
                "Temporary password: {$tempPassword}\n\n" .
                "Sign in at https://app.kiddietrac.com and use the 'Forgot password' link to choose a new one."
            );
        }

        $this->audit($request->user()->id, 'user.password_reset', 'user', $userId, [
            'email_sent' => $emailed,
        ]);

        return response()->json([
            'message'       => $emailed ? 'Password reset; email sent.' : 'Password reset.',
            'temp_password' => $tempPassword,
            'email_sent'    => $emailed,
        ]);
    }

    /**
     * POST /admin/users/{user}/resend-welcome
     * Re-send the welcome invite email. Always generates a fresh temp password
     * since the previous one was effectively lost.
     */
    public function resendWelcome(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $user = DB::table('users')->where('id', $userId)->whereNull('deleted_at')->first();
        if (!$user) return response()->json(['message' => 'User not found'], 404);

        $tempPassword = Str::random(12);

        DB::transaction(function () use ($userId, $tempPassword) {
            DB::table('users')->where('id', $userId)->update([
                'password'   => Hash::make($tempPassword),
                'status'     => 'invited',
                'updated_at' => now(),
            ]);
            DB::table('personal_access_tokens')->where('tokenable_id', $userId)->delete();
        });

        $emailed = $this->sendAccountEmail(
            $user->email,
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'Welcome to Kiddietrac',
            "Welcome to Kiddietrac!\n\n" .
            "Your account is ready at https://app.kiddietrac.com\n\n" .
            "Temporary password: {$tempPassword}\n\n" .
            "We recommend signing in then using 'Forgot password' to set your own."
        );

        $this->audit($request->user()->id, 'user.welcome_resent', 'user', $userId, [
            'email_sent' => $emailed,
        ]);

        return response()->json([
            'message'       => $emailed ? 'Welcome email sent.' : 'Welcome email failed to send (saved temp password — share manually).',
            'temp_password' => $tempPassword,
            'email_sent'    => $emailed,
        ]);
    }

    /**
     * POST /admin/users/{user}/reopen-onboarding
     * Clear onboarded_at so the user is presented with the wizard again on
     * next dashboard load. Used when the admin wants the user to top up
     * their profile (eg, an educator's expired First Aid date).
     * v22p3.5.
     */
    public function reopenOnboarding(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }
        DB::table('users')->where('id', $userId)->update([
            'onboarded_at' => null,
            'updated_at'   => now(),
        ]);
        $this->audit($request->user()->id, 'user.onboarding_reopened', 'user', $userId);
        return response()->json(['message' => 'Onboarding reopened — the user will see the wizard on their next sign-in.']);
    }

    /**
     * POST /admin/centres/{centre}/logo
     * Upload a centre logo (jpg/png/webp/svg, max 2 MB). v22p3.4.
     */
    public function uploadCentreLogo(Request $request, int $centreId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        $centre = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (!$centre) return response()->json(['message' => 'Centre not found'], 404);

        $request->validate([
            'logo' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,svg', 'max:2048'],
        ]);

        $file = $request->file('logo');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('public/centre-logos', $name);

        $publicPath = '/storage/centre-logos/' . $name;
        DB::table('centres')->where('id', $centreId)->update([
            'logo_url'   => $publicPath,
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'centre.logo_updated', 'centre', $centreId, ['path' => $publicPath]);

        return response()->json([
            'logo_url' => $publicPath,
            'message'  => 'Centre logo updated',
        ]);
    }

    /**
     * POST /admin/users/{user}/avatar
     * Upload an avatar image (jpg/png/webp, max 2 MB) and persist photo_url.
     * v22p3.2.
     */
    public function uploadAvatar(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

        $request->validate([
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $file = $request->file('avatar');
        $ext  = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs('public/avatars', $name);

        $publicPath = '/storage/avatars/' . $name;
        DB::table('users')->where('id', $userId)->update([
            'photo_url'  => $publicPath,
            'updated_at' => now(),
        ]);

        $this->audit($request->user()->id, 'user.avatar_updated', 'user', $userId, ['path' => $publicPath]);

        return response()->json([
            'photo_url' => $publicPath,
            'message'   => 'Avatar updated',
        ]);
    }

    /**
     * Send a transactional account email via the branded layout
     * (app/Mail/AccountNotice + emails/account-notice.blade.php). Returns
     * true on dispatch, false if the mailer threw. Errors are logged.
     *
     * v22p3.3: was Mail::raw() — now uses the branded HTML layout with
     * logo, primary colour, and footer (privacy + terms + contact).
     */
    private function sendAccountEmail(string $to, string $name, string $subject, string $body): bool
    {
        try {
            $mailable = new \App\Mail\AccountNotice(
                recipientName: $name ?: 'there',
                subjectLine:   $subject,
                bodyText:      $body,
                ctaLabel:      'Sign in to Kiddietrac',
                ctaUrl:        config('app.url', 'https://app.kiddietrac.com'),
            );
            Mail::to($to, $name ?: null)->send($mailable);
            return true;
        } catch (\Throwable $e) {
            Log::warning('sendAccountEmail failed', [
                'to' => $to, 'subject' => $subject, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
