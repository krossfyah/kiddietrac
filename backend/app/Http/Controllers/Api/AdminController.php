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
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        // v22p21: platform_admin trumps tenant scope — they can target ANY agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            // SECURITY (v22p98): a platform_admin must EXPLICITLY select an agency.
            // The old "default to the first agency" silently returned iLearn's
            // centres/families/contacts to a super-admin whenever the active-agency
            // header was missing (fresh tab, race, or a call that bypasses app.js)
            // — e.g. the announcement composer's centre picker leaked iLearn. Return
            // null (→ "No agency access") instead of defaulting to a real tenant.
            return ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists())
                ? $activeId : null;
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
                'address_line1' => $c->address_line1,
                'address_line2' => $c->address_line2,
                'city' => $c->city,
                'province' => $c->province,
                'postal_code' => $c->postal_code,
                'date_of_birth' => $c->date_of_birth ?? null,
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
            'supervisor_first_name' => ['nullable', 'string', 'max:80'],
            'supervisor_last_name' => ['nullable', 'string', 'max:80'],
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
            'supervisor_first_name' => $data['supervisor_first_name'] ?? null,
            'supervisor_last_name' => $data['supervisor_last_name'] ?? null,
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

    /** Can this user archive/restore/delete the given centre? platform_admin →
     *  any; agency_admin → centres in their agency; centre_director → their centre. */
    private function canManageCentre(Request $request, $centre): bool
    {
        $uid = $request->user()->id;
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return true;
        }
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'agency_admin')->where('agency_id', $centre->agency_id)->where('active', true)->exists()) {
            return true;
        }
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'centre_director')->where('centre_id', $centre->id)->where('active', true)->exists()) {
            return true;
        }
        return false;
    }

    public function archiveCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->whereNull('deleted_at')->first();
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        DB::table('centres')->where('id', $centreId)->update(['deleted_at' => now(), 'status' => 'closed']);
        $this->audit($request->user()->id, 'centre.archived', 'centre', $centreId, []);
        return response()->json(['message' => 'Centre archived']);
    }

    public function restoreCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->whereNotNull('deleted_at')->first();
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Archived centre not found'], 404);
        }
        DB::table('centres')->where('id', $centreId)->update(['deleted_at' => null, 'status' => 'active']);
        $this->audit($request->user()->id, 'centre.restored', 'centre', $centreId, []);
        return response()->json(['message' => 'Centre restored']);
    }

    public function permanentDeleteCentre(Request $request, int $centreId): JsonResponse
    {
        $centre = DB::table('centres')->where('id', $centreId)->first(); // includes archived
        if (!$centre || !$this->canManageCentre($request, $centre)) {
            return response()->json(['message' => 'Centre not found'], 404);
        }
        // Guard: never permanently destroy a centre that still has children.
        $hasKids = DB::table('children')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->where('families.centre_id', $centreId)
            ->whereNull('children.deleted_at')
            ->exists();
        if ($hasKids) {
            return response()->json(['message' => 'This centre still has children — archive it instead, or remove the children first.'], 422);
        }
        DB::table('centres')->where('id', $centreId)->delete();
        $this->audit($request->user()->id, 'centre.deleted', 'centre', $centreId, ['name' => $centre->name ?? null]);
        return response()->json(['message' => 'Centre permanently deleted']);
    }

    // ════════════════════════════════════════════════════════════════
    //   AUDIT LOG VIEWER — v22p39
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/audit-logs
     *
     * Filterable, paginated audit-log viewer for agency admins. Each row
     * is enriched with the actor's name / email so the UI doesn't need
     * separate lookups.
     *
     * Scope: by default returns logs where the actor's role_assignment OR
     * the entity itself is within the caller's agency. platform_admin sees
     * every row across the platform.
     *
     * Query params:
     *   entity_type — filter to one type (user, centre, agency, invoice, ...)
     *   action      — filter to a single action (user.created, ...)
     *   user_id     — filter to events by this actor
     *   since       — ISO datetime, lower bound on created_at
     *   until       — ISO datetime, upper bound on created_at
     *   q           — free-text match on action or payload
     *   limit       — page size (default 50, max 200)
     *   offset      — pagination offset
     */
    /** "post:api/v1/admin/centres" → "Created · admin › centres"; "user.deleted" → "User deleted". */
    private function humanizeAuditAction(?string $action): string
    {
        $action = (string) $action;
        $failed = str_contains($action, '[fail]');
        $action = trim(str_replace('[fail]', '', $action));
        if (preg_match('#^(post|put|patch|delete):(.+)$#', $action, $m)) {
            $verb = ['post' => 'Created', 'put' => 'Updated', 'patch' => 'Updated', 'delete' => 'Deleted'][$m[1]] ?? ucfirst($m[1]);
            $path = trim(str_replace('/', ' › ', preg_replace('#^api/v1/#', '', $m[2])));
            return $verb . ' · ' . $path . ($failed ? '  (failed)' : '');
        }
        return ucfirst(str_replace(['.', '_'], ' ', $action)) . ($failed ? ' (failed)' : '');
    }

    /** Human name for the entity an audit row touched (payload first, then a live lookup). */
    private function describeAuditEntity(?string $type, ?int $id, ?string $payload): ?string
    {
        $data = $payload ? (json_decode($payload, true) ?: []) : [];
        $input = is_array($data['input'] ?? null) ? $data['input'] : [];
        $fromPayload = $data['name'] ?? $data['email'] ?? $data['to'] ?? ($input['name'] ?? $input['email'] ?? $input['family_name'] ?? null);
        if (is_string($fromPayload) && trim($fromPayload) !== '') {
            return $fromPayload;
        }
        if (! $type || ! $id) {
            return null;
        }
        $map = ['users' => 'user', 'user' => 'user', 'agencies' => 'agency', 'agency' => 'agency', 'centres' => 'centre', 'centre' => 'centre', 'children' => 'child', 'child' => 'child', 'families' => 'family', 'family' => 'family'];
        $t = $map[strtolower($type)] ?? strtolower($type);
        try {
            switch ($t) {
                case 'user':
                    $r = DB::table('users')->where('id', $id)->first(['first_name', 'last_name', 'email']);
                    if (! $r) return null;
                    $n = trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? ''));
                    return $n !== '' ? $n : $r->email;
                case 'agency': return DB::table('agencies')->where('id', $id)->value('name') ?: null;
                case 'centre': return DB::table('centres')->where('id', $id)->value('name') ?: null;
                case 'family': return DB::table('families')->where('id', $id)->value('family_name') ?: null;
                case 'child':
                    $r = DB::table('children')->where('id', $id)->first(['first_name', 'last_name']);
                    return $r ? (trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')) ?: null) : null;
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    /** Short human summary of what changed, drawn from the audit payload. */
    private function summarizeAuditPayload(?string $payload): ?string
    {
        $data = $payload ? (json_decode($payload, true) ?: []) : [];
        if (! is_array($data)) return null;
        $bits = [];
        if (isset($data['status']) && ((int) $data['status'] < 200 || (int) $data['status'] >= 300)) {
            $bits[] = 'HTTP ' . $data['status'];
        }
        $input = is_array($data['input'] ?? null) ? $data['input'] : (isset($data['method']) ? [] : $data);
        $skip = ['method', 'path', 'status', 'input', 'active_agency_id', 'fields', 'user_agent', 'ip_address'];
        $fields = [];
        foreach ($input as $k => $v) {
            if (in_array($k, $skip, true) || ! is_scalar($v) || $v === '' || $v === null) continue;
            $val = is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v;
            if (mb_strlen($val) > 40) $val = mb_substr($val, 0, 40) . '…';
            $fields[] = $k . ': ' . $val;
            if (count($fields) >= 5) break;
        }
        if (isset($data['fields']) && is_array($data['fields'])) {
            $fields = array_merge($fields, array_map('strval', array_slice($data['fields'], 0, 6)));
        }
        if ($fields) $bits[] = implode(', ', $fields);
        return $bits ? implode(' · ', $bits) : null;
    }

    public function auditLogs(Request $request)
    {
        // v22p47: viewable by agency_admin AND centre_director. Agency admins see
        // the whole agency; directors see only the centre(s) they direct.
        $caller = $request->user();
        $callerRoles = DB::table('role_assignments')
            ->where('user_id', $caller->id)->where('active', true)
            ->pluck('role')->all();
        $callerIsPlatformAdmin = in_array('platform_admin', $callerRoles, true);
        $callerIsAgencyAdmin   = in_array('agency_admin', $callerRoles, true);
        $callerIsDirector      = in_array('centre_director', $callerRoles, true);
        if (!$callerIsPlatformAdmin && !$callerIsAgencyAdmin && !$callerIsDirector) {
            return response()->json(['logs' => [], 'total' => 0]);
        }

        $entityType = $request->input('entity_type');
        $action     = $request->input('action');
        $userId     = (int) $request->input('user_id');
        $since      = $request->input('since');
        $until      = $request->input('until');
        $q          = trim((string) $request->input('q', ''));
        $limit      = min(200, max(1, (int) $request->input('limit', 50)));
        $offset     = max(0, (int) $request->input('offset', 0));

        // Build agency-scoped user id list (actors who belong to this agency).
        // SECURITY (v22p96): ALWAYS scope to the active agency — a platform_admin
        // is treated as an agency_admin of whichever agency they've switched into
        // (getAgencyId resolves the X-Active-Agency-Id header), so audit logs are
        // never shown across tenants. The prior `$scopedUserIds = null` bypass
        // surfaced every agency's audit trail to platform_admins — removed.
        $scopedUserIds = null;
        if ($callerIsPlatformAdmin || $callerIsAgencyAdmin) {
            // Whole active agency: actors scoped by agency_id or any agency centre.
            $agencyId = $this->getAgencyId($request);
            if (!$agencyId) return response()->json(['logs' => [], 'total' => 0]);
            $centreIds = $this->getCentreIds($agencyId);
            $scopedUserIds = DB::table('role_assignments')
                ->where('active', true)
                ->where(function ($x) use ($agencyId, $centreIds) {
                    $x->where('agency_id', $agencyId);
                    if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
                })
                ->pluck('user_id')->unique()->values()->all();
        } else {
            // centre_director: limit to the centre(s) they actually direct.
            $centreIds = DB::table('role_assignments')
                ->where('user_id', $caller->id)->where('role', 'centre_director')
                ->where('active', true)->whereNotNull('centre_id')
                ->pluck('centre_id')->unique()->values()->all();
            $scopedUserIds = DB::table('role_assignments')
                ->where('active', true)->whereIn('centre_id', $centreIds ?: [0])
                ->pluck('user_id')->unique()->values()->all();
        }
        // Include guardian users tied to the in-scope centres + the viewer.
        $guardianIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();
        $scopedUserIds = array_values(array_unique(array_merge($scopedUserIds, $guardianIds, [(int) $caller->id])));

        $base = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->orderByDesc('al.created_at');

        if ($scopedUserIds !== null) {
            $base->whereIn('al.user_id', $scopedUserIds ?: [0]);
        }
        if ($entityType) $base->where('al.entity_type', $entityType);
        if ($action)     $base->where('al.action', $action);
        if ($userId)     $base->where('al.user_id', $userId);
        if ($since)      $base->where('al.created_at', '>=', $since);
        if ($until)      $base->where('al.created_at', '<=', $until);
        if ($q !== '')   $base->where(function ($x) use ($q) {
            $x->where('al.action', 'like', "%{$q}%")
              ->orWhere('al.payload', 'like', "%{$q}%");
        });

        $total = (clone $base)->count('al.id');

        $rows = $base->limit($limit)->offset($offset)->get([
            'al.id', 'al.action', 'al.entity_type', 'al.entity_id',
            'al.payload', 'al.ip_address', 'al.created_at',
            'al.user_id',
            DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'system') as actor_name"),
            'u.email as actor_email',
        ]);

        // Enrich each row: readable action, the affected entity's NAME (from
        // payload first so it survives deletes, else a live lookup), and a short
        // human summary of what changed.
        $rows = $rows->map(function ($r) {
            $r->action_label = $this->humanizeAuditAction($r->action);
            $r->entity_name  = $this->describeAuditEntity($r->entity_type, $r->entity_id ? (int) $r->entity_id : null, $r->payload);
            $r->summary      = $this->summarizeAuditPayload($r->payload);
            return $r;
        });

        // Distinct values for the UI filter dropdowns (capped at 60 each)
        $distinctActions = (clone $base)->distinct()->limit(60)->pluck('al.action');
        $distinctEntities = (clone $base)->distinct()->limit(40)->pluck('al.entity_type')->filter()->values();

        // v22p46: CSV export — pulls up to 5000 rows (above the 200 default)
        // so a date-range export can produce a full history slice in one shot.
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            $csvRows = (clone $base)->limit(5000)->offset(0)->get([
                'al.id', 'al.action', 'al.entity_type', 'al.entity_id',
                'al.payload', 'al.ip_address', 'al.created_at', 'al.user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email, 'system') as actor_name"),
                'u.email as actor_email',
            ]);
            return $this->streamCsv('audit-log', [
                'When', 'Action', 'Entity type', 'Entity ID',
                'Actor', 'Email', 'IP', 'Payload',
            ], $csvRows->map(fn ($r) => [
                $r->created_at, $r->action, $r->entity_type, $r->entity_id,
                $r->actor_name, $r->actor_email, $r->ip_address, $r->payload,
            ])->all());
        }

        return response()->json([
            'logs' => $rows,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'filters' => [
                'actions' => $distinctActions,
                'entity_types' => $distinctEntities,
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   PRESENCE — v22p42
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/presence
     *
     * Returns the list of currently-online user_ids in the agency, derived
     * from personal_access_tokens.last_used_at within the last 5 minutes.
     * Cheap proxy for 'who is in the portal right now' without needing a
     * websocket or a dedicated presence table.
     *
     * platform_admin sees everyone; agency_admin sees only their agency.
     */
    public function presence(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['online' => [], 'window_minutes' => 5]);

        $cutoff = now()->subMinutes(5);
        $base = DB::table('personal_access_tokens as t')
            ->where('t.tokenable_type', \App\Models\User::class)
            ->whereNotNull('t.last_used_at')
            ->where('t.last_used_at', '>=', $cutoff)
            ->groupBy('t.tokenable_id');

        // SECURITY (v22p96): presence is ALWAYS scoped to the active agency,
        // platform_admin included (getAgencyId resolves the switched-into agency).
        // The prior platform bypass showed every online user across all tenants.
        $centreIds = $this->getCentreIds($agencyId);
        $scoped = DB::table('role_assignments')
            ->where('active', true)
            ->where(function ($x) use ($agencyId, $centreIds) {
                $x->where('agency_id', $agencyId);
                if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->values()->all();
        $guardianIds = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->pluck('g.user_id')->all();
        $scoped = array_values(array_unique(array_merge($scoped, $guardianIds)));
        $base->whereIn('t.tokenable_id', $scoped ?: [0]);

        $rows = $base->get(['t.tokenable_id', DB::raw('MAX(t.last_used_at) as last_seen')]);

        return response()->json([
            'online' => $rows->map(fn ($r) => [
                'user_id' => (int) $r->tokenable_id,
                'last_seen_at' => $r->last_seen,
            ])->values(),
            'window_minutes' => 5,
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   COMPLIANCE — v22p47
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/compliance
     *
     * Aggregates three of the most-asked-for compliance signals into one
     * payload so the admin dashboard can render a single 'what needs
     * attention' surface without firing N requests.
     *
     *   expired_certs[]      — staff_certifications.expires_at past or
     *                          within 30 days, joined to user + cert_type
     *   mfa_laggards[]       — agency_admin + centre_director users in this
     *                          agency without two_factor_secret set
     *   centre_ratios[]      — for every centre: licensed capacity vs
     *                          enrolled headcount and a status pill
     *
     * Scoped to the caller's agency. platform_admin sees every agency-
     * filtered slice for whichever X-Active-Agency-Id is set (same
     * pattern as the rest of AdminController).
     */
    public function compliance(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $now = now();
        $soon = $now->copy()->addDays(30);

        // Staff users whose role is pinned to this agency or one of its centres
        $staffUserIds = DB::table('role_assignments')
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('agency_id', $agencyId);
                if (!empty($centreIds)) $q->orWhereIn('centre_id', $centreIds);
            })
            ->pluck('user_id')->unique()->all();

        // Expired or expiring certs
        $expiredCerts = empty($staffUserIds) ? collect() : DB::table('staff_certifications as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('c.user_id', $staffUserIds)
            ->where('c.active', true)
            ->whereNotNull('c.expires_at')
            ->where('c.expires_at', '<=', $soon->toDateString())
            ->orderBy('c.expires_at')
            ->limit(200)
            ->get([
                'c.id', 'c.cert_type', 'c.certifier', 'c.issued_at', 'c.expires_at',
                'c.user_id',
                DB::raw("COALESCE(NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), ''), u.email) as user_name"),
                'u.email as user_email',
                DB::raw("CASE WHEN c.expires_at < '{$now->toDateString()}' THEN 'expired' ELSE 'expiring_soon' END as status"),
            ]);

        // MFA laggards — directors + agency admins without two_factor_secret
        $mfaLaggards = empty($staffUserIds) ? collect() : DB::table('users as u')
            ->whereIn('u.id', function ($q) use ($agencyId, $centreIds) {
                $q->select('user_id')->from('role_assignments')
                    ->whereIn('role', ['agency_admin', 'centre_director'])
                    ->where('active', true)
                    ->where(function ($x) use ($agencyId, $centreIds) {
                        $x->where('agency_id', $agencyId);
                        if (!empty($centreIds)) $x->orWhereIn('centre_id', $centreIds);
                    });
            })
            ->whereNull('u.two_factor_secret')
            ->whereNull('u.deleted_at')
            ->orderBy('u.first_name')
            ->limit(200)
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.last_login_at']);

        // Centre capacity vs enrolled
        $centreRatios = empty($centreIds) ? collect() : collect($centreIds)->map(function ($cid) {
            $centre = DB::table('centres')->where('id', $cid)->first();
            if (!$centre) return null;
            $enrolled = DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', $cid)
                ->where('c.enrollment_status', 'enrolled')
                ->whereNull('c.deleted_at')
                ->count();
            $cap = (int) ($centre->license_capacity ?? 0);
            $pct = $cap > 0 ? round(($enrolled / $cap) * 100, 1) : 0;
            $status = $pct >= 100 ? 'over_capacity' : ($pct >= 95 ? 'tight' : 'ok');
            return [
                'centre_id' => $centre->id,
                'centre_name' => $centre->name,
                'enrolled' => $enrolled,
                'license_capacity' => $cap,
                'pct' => $pct,
                'status' => $status,
            ];
        })->filter()->values();

        return response()->json([
            'expired_certs' => $expiredCerts,
            'mfa_laggards' => $mfaLaggards,
            'centre_ratios' => $centreRatios,
            'summary' => [
                'expired_certs' => $expiredCerts->where('status', 'expired')->count(),
                'expiring_soon' => $expiredCerts->where('status', 'expiring_soon')->count(),
                'mfa_laggards' => $mfaLaggards->count(),
                'over_capacity' => $centreRatios->where('status', 'over_capacity')->count(),
                'tight' => $centreRatios->where('status', 'tight')->count(),
            ],
        ]);
    }

    // ════════════════════════════════════════════════════════════════
    //   AGENCY-WIDE CHILDREN — v22p47
    // ════════════════════════════════════════════════════════════════

    /**
     * GET /api/v1/admin/children
     *
     * Cross-centre list of every child in the agency. Same enrolled /
     * waitlist / withdrawn filter as the per-centre director endpoint,
     * but agency-scoped so admins don't have to drill into a centre to
     * see (or export) the full roster. Supports ?format=csv.
     */
    public function listAgencyChildren(Request $request)
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['children' => []]);
        $centreIds = $this->getCentreIds($agencyId);
        if (empty($centreIds)) return response()->json(['children' => []]);

        $q = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('c.deleted_at')
            ->orderBy('c.last_name')->orderBy('c.first_name');

        if ($status = $request->input('status')) {
            $q->where('c.enrollment_status', $status);
        }

        $rows = $q->limit(2000)->get([
            'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name',
            'c.date_of_birth', 'c.enrollment_status', 'c.enrolled_at',
            'c.allergies', 'c.health_alerts',
            'f.id as family_id', 'f.family_name',
            'ce.id as centre_id', 'ce.name as centre_name',
        ])->all();

        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('children', [
                'ID', 'First name', 'Last name', 'Preferred', 'Date of birth',
                'Status', 'Enrolled', 'Family', 'Centre',
                'Allergies', 'Health alerts',
            ], array_map(fn ($r) => [
                $r->id, $r->first_name, $r->last_name, $r->preferred_name,
                $r->date_of_birth, $r->enrollment_status, $r->enrolled_at,
                $r->family_name, $r->centre_name,
                $r->allergies, $r->health_alerts,
            ], $rows));
        }

        return response()->json(['children' => $rows]);
    }

    // ════════════════════════════════════════════════════════════════
    //   USERS
    // ════════════════════════════════════════════════════════════════

    public function listUsers(Request $request)
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $centreIds = $this->getCentreIds($agencyId);
        $roleFilter = $request->input('role');
        $searchQuery = $request->input('q');

        // SECURITY (v22p96): the Users view is ALWAYS scoped to the active agency
        // — including for platform_admins. getAgencyId() already resolves the
        // X-Active-Agency-Id header to the agency the super-admin has switched
        // INTO, so a platform_admin sees one agency at a time (the one selected),
        // never every tenant's users mixed together. To manage another agency's
        // users they switch their active agency to it. The prior v22p27 bypass
        // (drop the filter entirely for platform_admins) leaked every user on the
        // platform into whatever agency context was active — removed.
        $userIdsQuery = DB::table('role_assignments')->where('active', true)
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
        // (v22p96: always scoped to the active agency, platform_admin included).
        if ($roleFilter === null || $roleFilter === 'guardian') {
            $guardianQuery = DB::table('guardians')
                ->join('families', 'families.id', '=', 'guardians.family_id')
                ->whereIn('families.centre_id', $centreIds ?: [0]);
            $guardianUserIds = $guardianQuery->pluck('guardians.user_id')->all();
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

        $rows = $result->all();

        // v22p45: CSV export — ?format=csv streams an Excel-friendly file
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('users', [
                'ID', 'Name', 'Email', 'Phone', 'Status', 'Roles', 'Last login', 'Created',
            ], array_map(fn ($u) => [
                $u['id'], $u['name'], $u['email'], $u['phone'] ?? '',
                $u['status'], implode(' / ', $u['roles']),
                $u['last_login_at'] ?? '', $u['created_at'],
            ], $rows));
        }

        return response()->json(['users' => $rows]);
    }

    /**
     * v22p45 — shared CSV streamer. Used by listUsers + listFamilies and
     * any future list endpoint that wants a download. UTF-8 BOM so Excel
     * opens special characters cleanly.
     */
    private function streamCsv(string $label, array $header, array $rows): StreamedResponse
    {
        $filename = $label . '-' . now()->format('Y-m-d') . '.csv';
        return new StreamedResponse(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $r) fputcsv($out, $r);
            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            // v22p31: uniqueness check ignores soft-deleted rows so re-creating
            // a previously-deleted user reuses the same record (see revive
            // block below). Without this rule, the previous Safia Ali deletion
            // would 422 'email already taken' on every recreate attempt.
            'email' => ['required', 'email', 'max:180', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor,platform_admin'],
            'centre_id' => ['nullable', 'integer'],
            'send_invite' => ['nullable', 'boolean'],
        ]);

        // v22p23: only platform_admin can mint another platform_admin.
        if ($data['role'] === 'platform_admin') {
            $callerIsPlatform = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'platform_admin')
                ->where('active', true)
                ->exists();
            if (! $callerIsPlatform) {
                return response()->json([
                    'message' => 'Only platform admins can create platform admins.',
                    'errors' => ['role' => ['Insufficient privilege for that role.']],
                ], 403);
            }
        }

        // v22p18: non-admin roles MUST be tied to a specific centre — otherwise
        // the role_assignment row has no scope and the user becomes invisible
        // in listUsers (its WHERE clause requires agency_id OR centre_id).
        if (! in_array($data['role'], ['agency_admin', 'platform_admin'], true) && empty($data['centre_id'])) {
            return response()->json([
                'message' => 'Centre is required for this role.',
                'errors' => ['centre_id' => ['Please pick a centre for centre directors, educators, and auditors.']],
            ], 422);
        }

        // Validate centre belongs to this agency if provided
        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        // Random temporary password (user resets via invite email or forgot-password)
        $tempPassword = Str::random(24);

        // v22p31: if a soft-deleted user with this email already exists, revive
        // it rather than creating a duplicate row. Re-create-after-delete is the
        // most natural admin workflow for fixing a misadded user, and persisting
        // a stable user.id keeps audit logs, payments, and message history
        // attached to the same person on re-add.
        $existing = DB::table('users')->where('email', $data['email'])->whereNotNull('deleted_at')->first();
        $isRevive = (bool) $existing;
        if ($isRevive) {
            $userId = (int) $existing->id;
            DB::table('users')->where('id', $userId)->update([
                'deleted_at' => null,
                'password' => Hash::make($tempPassword),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'phone' => $data['phone'] ?? null,
                'status' => 'invited',
                'updated_at' => now(),
            ]);
            // Drop the old (deactivated) role_assignment rows for this user; a
            // fresh one is inserted below. This avoids a recreated educator
            // accidentally inheriting a stale platform_admin row.
            // Never strip roles from the protected super-admin account.
            if (!$this->isProtectedEmail($data['email'] ?? null)) {
                DB::table('role_assignments')->where('user_id', $userId)->delete();
            }
        } else {
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
        }

        // v22p18 + v22p23: stamp agency_id so the user is discoverable in
        // listUsers (filter requires agency_id OR centre_id). platform_admin
        // is exempt and gets both NULL since it spans the entire platform.
        $assignmentAgencyId = $data['role'] === 'platform_admin' ? null : $agencyId;
        $assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin'], true))
            ? ($data['centre_id'] ?? null)
            : null;
        DB::table('role_assignments')->insert([
            'user_id' => $userId,
            'role' => $data['role'],
            'agency_id' => $assignmentAgencyId,
            'centre_id' => $assignmentCentreId,
            'active' => 1,
            'created_at' => now(),
        ]);

        $this->audit($request->user()->id, $isRevive ? 'user.revived' : 'user.created', 'user', $userId, [
            'email' => $data['email'],
            'role' => $data['role'],
        ]);

        // Email the user a set-password invite. This was previously an
        // unimplemented TODO — invited users never received anything, which is
        // why enrollment invites silently "sent" but never arrived. Mirrors
        // PlatformController's agency-admin invite (password_resets token +
        // branded HTML + email_logs row).
        $inviteSent = false;
        if (!empty($data['send_invite'])) {
            try {
                $token = bin2hex(random_bytes(32));
                DB::table('password_resets')->insert([
                    'email'      => $data['email'],
                    'token'      => hash('sha256', $token),
                    'expires_at' => now()->addDays(7),
                    'created_at' => now(),
                ]);
                $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?? 'Kiddietrac');
                $this->sendUserInvite($data['email'], $data['first_name'], $data['last_name'], $agencyName,
                    'https://app.kiddietrac.com/set-password.html?token=' . $token);
                $inviteSent = true;
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('User invite email failed', ['email' => $data['email'], 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'id' => $userId,
            'message' => $isRevive
                ? 'User restored — they previously existed and have been reinstated with the role you selected.'
                : 'User created',
            'revived' => $isRevive,
            'invite_sent' => $inviteSent,
            'note' => $inviteSent
                ? 'An invite email with a set-password link has been sent.'
                : 'Have the user click "Forgot password" on the login page to set their password.',
        ], 201);
    }

    /**
     * Email a newly-created user a branded set-password link. Synchronous send
     * (sendmail) + logs to email_logs with a tracking token; the X-KT-Logged
     * header stops the global MessageSent listener writing a duplicate row.
     */
    private function sendUserInvite(string $email, string $firstName, string $lastName, string $agencyName, string $link): void
    {
        $trackToken = bin2hex(random_bytes(16));
        $apiBase = preg_replace('#/api/v1/?$#', '', rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/'));
        $pixel = '<img src="' . $apiBase . '/api/v1/e/o/' . $trackToken . '" width="1" height="1" alt="" style="display:none;border:0;">';
        $first = htmlspecialchars($firstName);
        $safeLink = htmlspecialchars($link);
        $safeAgency = htmlspecialchars($agencyName);

        $body = '<p style="margin:0 0 14px;">Hi ' . $first . ',</p>'
            . '<p style="margin:0 0 16px;">You\'ve been invited to <strong>' . $safeAgency . '</strong> on Kiddietrac. '
            . 'Set your password below and you\'ll be taken straight into your account.</p>'
            . \App\Services\EmailTemplate::button('Set my password →', $link)
            . '<p style="margin:16px 0 0;font-size:12px;color:#64748B;">Or paste this into your browser:<br>'
            . '<a href="' . $safeLink . '" style="color:#1F6080;">' . $safeLink . '</a></p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;">This link expires in 7 days. If you weren\'t expecting this, you can ignore this email.</p>'
            . $pixel;

        $html = \App\Services\EmailTemplate::wrap(null, $body, [
            'eyebrow'   => 'YOU\'RE INVITED',
            'title'     => $agencyName,
            'subtitle'  => 'Set your password to get started',
            'preheader' => 'Set your password and sign in to ' . $agencyName . ' on Kiddietrac.',
        ]);

        \Illuminate\Support\Facades\Mail::html($html, function ($m) use ($email, $firstName, $lastName) {
            $m->to($email, trim($firstName . ' ' . $lastName))
              ->from('noreply@kiddietrac.com', 'Kiddietrac')
              ->replyTo('support@kiddietrac.com', 'Kiddietrac Support')
              ->subject('You\'re invited to Kiddietrac — set your password');
            $m->getHeaders()->addTextHeader('X-KT-Logged', '1');
            $m->getHeaders()->addTextHeader('List-Unsubscribe', '<mailto:support@kiddietrac.com>');
        });
        if (\Illuminate\Support\Facades\Schema::hasTable('email_logs')) {
            DB::table('email_logs')->insert([
                'to_email' => $email, 'to_name' => trim($firstName . ' ' . $lastName),
                'from_email' => 'noreply@kiddietrac.com', 'subject' => 'You\'re invited to Kiddietrac — set your password',
                'mailer' => config('mail.default'), 'status' => 'sent', 'tracking_token' => $trackToken,
                'opens' => 0, 'created_at' => now(),
            ]);
        }
    }

    public function updateUser(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        // v22p30: route the tenancy check through the shared helper so platform_admin
        // gets the same cross-agency bypass as destroyUser/resetUserPassword/etc.
        // Helper also covers the guardian path (families.centre_id), which the prior
        // inline check missed — agency_admins can now also update guardians via this
        // endpoint, matching the rest of the surface.
        if (!$this->userBelongsToAgency($userId, $agencyId)) {
            return response()->json(['message' => 'User not in your agency'], 403);
        }

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

    /** platform_admin / agency_admin — profile extras + notes for a user. */
    public function userProfile(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        $p = DB::table('user_profiles')->where('user_id', $userId)->first();
        $notes = DB::table('user_notes')->where('user_id', $userId)->orderByDesc('created_at')->limit(200)->get();
        return response()->json([
            'profile' => $p ? [
                'address' => $p->address,
                'date_of_birth' => $p->date_of_birth,
                'emergency_contact_name' => $p->emergency_contact_name,
                'emergency_contact_phone' => $p->emergency_contact_phone,
                'emergency_contact_relation' => $p->emergency_contact_relation,
            ] : null,
            'notes' => $notes,
        ]);
    }

    /** Upsert a user's profile extras. */
    public function updateUserProfile(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        $data = $request->validate([
            'address' => ['sometimes', 'nullable', 'string', 'max:300'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'emergency_contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'emergency_contact_phone' => ['sometimes', 'nullable', 'string', 'max:60'],
            'emergency_contact_relation' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);
        $data['user_id'] = $userId;
        $data['updated_at'] = now();
        if (DB::table('user_profiles')->where('user_id', $userId)->exists()) {
            DB::table('user_profiles')->where('user_id', $userId)->update($data);
        } else {
            $data['created_at'] = now();
            DB::table('user_profiles')->insert($data);
        }
        $this->audit($request->user()->id, 'user.profile_updated', 'user', $userId, []);
        return response()->json(['message' => 'Profile saved']);
    }

    /** Add a timestamped note to a user. */
    public function addUserNote(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);
        if (!$this->userBelongsToAgency($userId, $agencyId)) return response()->json(['message' => 'User not in your agency'], 403);
        $data = $request->validate(['note' => ['required', 'string', 'max:2000']]);
        $actor = $request->user();
        $name = trim((string) (($actor->first_name ?? '') . ' ' . ($actor->last_name ?? ''))) ?: ($actor->name ?? 'Admin');
        $id = DB::table('user_notes')->insertGetId([
            'user_id' => $userId,
            'note' => $data['note'],
            'created_by' => $actor->id,
            'created_by_name' => $name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($actor->id, 'user.note_added', 'user', $userId, []);
        return response()->json(['message' => 'Note added', 'id' => $id]);
    }

    public function setUserRole(Request $request, int $userId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'role' => ['required', 'in:agency_admin,centre_director,educator,auditor,platform_admin'],
            'centre_id' => ['nullable', 'integer'],
            'active' => ['nullable', 'boolean'],
        ]);

        // v22p23: platform_admin role can only be granted by a platform_admin.
        if ($data['role'] === 'platform_admin') {
            $callerIsPlatform = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'platform_admin')
                ->where('active', true)
                ->exists();
            if (! $callerIsPlatform) {
                return response()->json([
                    'message' => 'Only platform admins can grant the platform_admin role.',
                    'errors' => ['role' => ['Insufficient privilege.']],
                ], 403);
            }
        }

        if (!empty($data['centre_id'])) {
            $centre = DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->first();
            if (!$centre) return response()->json(['message' => 'Invalid centre'], 422);
        }

        // v22p23: scope handling mirrors createUser — platform_admin spans all
        // (both NULL); agency_admin scopes by agency only; others by centre too.
        $assignmentAgencyId = $data['role'] === 'platform_admin' ? null : $agencyId;
        $assignmentCentreId = (! in_array($data['role'], ['agency_admin', 'platform_admin'], true))
            ? ($data['centre_id'] ?? null)
            : null;

        DB::table('role_assignments')->updateOrInsert(
            [
                'user_id' => $userId,
                'role' => $data['role'],
                'agency_id' => $assignmentAgencyId,
                'centre_id' => $assignmentCentreId,
            ],
            ['active' => $data['active'] ?? true]
        );

        $this->audit($request->user()->id, 'user.role_set', 'user', $userId, $data);
        return response()->json(['message' => 'Role updated']);
    }

    // ════════════════════════════════════════════════════════════════
    //   FAMILIES
    // ════════════════════════════════════════════════════════════════

    public function listFamilies(Request $request)
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

        $rows = $result->all();

        // v22p45: CSV export — ?format=csv
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            return $this->streamCsv('families', [
                'ID', 'Family name', 'Centre', 'Primary email', 'Primary phone',
                'Address', 'City', 'Children', 'Guardians', 'Outstanding balance',
            ], array_map(fn ($f) => [
                $f['id'] ?? '',
                $f['family_name'] ?? '',
                $f['centre_name'] ?? '',
                $f['primary_email'] ?? '',
                $f['primary_phone'] ?? '',
                $f['address_line1'] ?? '',
                $f['city'] ?? '',
                $f['child_count'] ?? 0,
                $f['guardian_count'] ?? 0,
                $f['outstanding_balance'] ?? 0,
            ], $rows));
        }

        return response()->json(['families' => $rows]);
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
            // v22p36: optional nested guardians + children for the multi-step wizard.
            // Omitting them keeps the legacy single-step behaviour (family only).
            'guardians' => ['nullable', 'array'],
            'guardians.*.email' => ['required_with:guardians', 'email', 'max:180'],
            'guardians.*.first_name' => ['required_with:guardians', 'string', 'max:80'],
            'guardians.*.last_name' => ['nullable', 'string', 'max:80'],
            'guardians.*.phone' => ['nullable', 'string', 'max:40'],
            'guardians.*.relationship' => ['nullable', 'in:mother,father,guardian,grandparent,foster,other'],
            'guardians.*.is_primary' => ['nullable', 'boolean'],
            'guardians.*.can_pickup' => ['nullable', 'boolean'],
            'children' => ['nullable', 'array'],
            'children.*.first_name' => ['required_with:children', 'string', 'max:80'],
            'children.*.last_name' => ['required_with:children', 'string', 'max:80'],
            'children.*.preferred_name' => ['nullable', 'string', 'max:80'],
            'children.*.date_of_birth' => ['required_with:children', 'date'],
            'children.*.gender' => ['nullable', 'in:female,male,non_binary,prefer_not_to_say,other'],
            'children.*.enrollment_status' => ['nullable', 'in:waitlist,enrolled,withdrawn,graduated'],
            // v22p… onboarding wizard — rich child + emergency-contact data.
            'children.*.allergies' => ['nullable', 'string', 'max:1000'],
            'children.*.dietary_restrictions' => ['nullable', 'string', 'max:1000'],
            'children.*.medical_notes' => ['nullable', 'string', 'max:2000'],
            'children.*.doctor_name' => ['nullable', 'string', 'max:120'],
            'children.*.doctor_phone' => ['nullable', 'string', 'max:40'],
            'children.*.school' => ['nullable', 'string', 'max:160'],
            'emergency_contacts' => ['nullable', 'array'],
            'emergency_contacts.*.name' => ['required_with:emergency_contacts', 'string', 'max:120'],
            'emergency_contacts.*.relationship' => ['nullable', 'string', 'max:60'],
            'emergency_contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'emergency_contacts.*.alt_phone' => ['nullable', 'string', 'max:40'],
            'emergency_contacts.*.can_pickup' => ['nullable', 'boolean'],
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

        $guardians = $data['guardians'] ?? [];
        $children = $data['children'] ?? [];
        $emergency = $data['emergency_contacts'] ?? [];
        unset($data['guardians'], $data['children'], $data['emergency_contacts']);

        // Reject duplicate guardian emails within the same submission so we do
        // not create two guardian rows that collapse onto one user.
        $seenEmails = [];
        foreach ($guardians as $g) {
            $em = strtolower(trim($g['email']));
            if (isset($seenEmails[$em])) {
                return response()->json([
                    'message' => 'Duplicate guardian email in submission: '.$g['email'],
                    'errors' => ['guardians' => ['Each guardian must have a unique email address.']],
                ], 422);
            }
            $seenEmails[$em] = true;
        }

        try {
            $result = DB::transaction(function () use ($data, $guardians, $children, $emergency, $agencyId) {
                $famId = DB::table('families')->insertGetId(array_merge($data, [
                    'preferred_lang' => $data['preferred_lang'] ?? 'en-CA',
                    'billing_split' => $data['billing_split'] ?? 'single',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));

                $guardianIds = [];
                foreach (array_values($guardians) as $i => $g) {
                    $email = strtolower(trim($g['email']));
                    // Reuse an existing (non-deleted) user with this email so we
                    // never duplicate accounts or clobber an existing login.
                    $existing = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->first();
                    if ($existing) {
                        $uid = (int) $existing->id;
                        DB::table('users')->where('id', $uid)->update(array_filter([
                            'first_name' => $g['first_name'] ?? null,
                            'last_name' => $g['last_name'] ?? null,
                            'phone' => $g['phone'] ?? null,
                            'updated_at' => now(),
                        ], fn ($v) => $v !== null));
                    } else {
                        $uid = (int) DB::table('users')->insertGetId([
                            'email' => $g['email'],
                            'password' => Hash::make(Str::random(24)),
                            'first_name' => $g['first_name'],
                            'last_name' => $g['last_name'] ?? '',
                            'phone' => $g['phone'] ?? null,
                            'locale' => 'en-CA',
                            'timezone' => 'America/Toronto',
                            'status' => 'invited',
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    DB::table('role_assignments')->updateOrInsert(
                        ['user_id' => $uid, 'role' => 'guardian', 'agency_id' => $agencyId, 'centre_id' => null],
                        ['active' => true, 'created_at' => now()]
                    );

                    $isPrimary = ! empty($g['is_primary']) || $i === 0;
                    DB::table('guardians')->updateOrInsert(
                        ['family_id' => $famId, 'user_id' => $uid],
                        [
                            'relationship' => $g['relationship'] ?? 'guardian',
                            'is_primary' => $isPrimary,
                            'can_pickup' => array_key_exists('can_pickup', $g) ? (bool) $g['can_pickup'] : true,
                            'can_receive_billing' => true,
                            'billing_share_pct' => $isPrimary ? 100.00 : 0.00,
                            'created_at' => now(),
                        ]
                    );
                    $guardianIds[] = $uid;
                }

                $childIds = [];
                foreach ($children as $c) {
                    $status = $c['enrollment_status'] ?? 'enrolled';
                    $childIds[] = (int) DB::table('children')->insertGetId([
                        'family_id' => $famId,
                        'first_name' => $c['first_name'],
                        'last_name' => $c['last_name'],
                        'preferred_name' => $c['preferred_name'] ?? $c['first_name'],
                        'date_of_birth' => $c['date_of_birth'],
                        'gender' => $c['gender'] ?? 'prefer_not_to_say',
                        // allergies + dietary_restrictions are JSON (json_valid CHECK) — store as arrays.
                        'allergies' => ! empty($c['allergies']) ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $c['allergies']))))) : null,
                        'dietary_restrictions' => ! empty($c['dietary_restrictions']) ? json_encode(array_values(array_filter(array_map('trim', explode(',', (string) $c['dietary_restrictions']))))) : null,
                        'medical_notes' => $c['medical_notes'] ?? null,
                        'doctor_name' => $c['doctor_name'] ?? null,
                        'doctor_phone' => $c['doctor_phone'] ?? null,
                        'school' => $c['school'] ?? null,
                        'preferred_lang' => 'en-CA',
                        'enrollment_status' => $status,
                        'enrolled_at' => $status === 'enrolled' ? now()->toDateString() : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $emergencyIds = [];
                if (\Illuminate\Support\Facades\Schema::hasTable('emergency_contacts')) {
                    foreach ($emergency as $ec) {
                        $emergencyIds[] = (int) DB::table('emergency_contacts')->insertGetId([
                            'family_id' => $famId,
                            'name' => $ec['name'],
                            'relationship' => $ec['relationship'] ?? null,
                            'phone' => $ec['phone'] ?? null,
                            'alt_phone' => $ec['alt_phone'] ?? null,
                            'can_pickup' => ! empty($ec['can_pickup']),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }
                }

                return ['family_id' => $famId, 'guardian_ids' => $guardianIds, 'child_ids' => $childIds];
            });
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Could not create family: '.$e->getMessage(),
            ], 500);
        }

        $this->audit($request->user()->id, 'create_family', 'family', $result['family_id'], [
            'centre_id' => $data['centre_id'],
            'guardians' => count($result['guardian_ids']),
            'children' => count($result['child_ids']),
        ]);

        return response()->json([
            'family' => DB::table('families')->where('id', $result['family_id'])->first(),
            'guardians' => count($result['guardian_ids']),
            'children' => count($result['child_ids']),
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

    /**
     * v22p46 — DELETE /api/v1/admin/families/{family}
     * Soft-deletes the family row. Children + guardian links stay intact so
     * historical reports and audit logs keep working. Caller must own the
     * family's centre via the existing agency_admin / platform_admin gates.
     */
    public function destroyFamily(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $callerIsPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();

        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        if (!$family) return response()->json(['message' => 'Not found'], 404);

        if (!$callerIsPlatformAdmin) {
            $centreIds = $this->getCentreIds($agencyId);
            if (!in_array($family->centre_id, $centreIds, true)) {
                return response()->json(['message' => 'Family not in your agency'], 403);
            }
        }

        DB::table('families')->where('id', $familyId)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit($request->user()->id, 'family.deleted', 'family', $familyId, [
            'family_name' => $family->family_name,
        ]);

        return response()->json(['message' => 'Family deleted', 'id' => $familyId]);
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
     *
     * SECURITY (v22p96): membership is checked against the ACTIVE agency for
     * everyone, platform_admins included. The prior v22p30 short-circuit
     * (`if platform_admin return true`) let a super-admin read (userProfile) and
     * mutate (delete/reset-password/resend/reopen/avatar) any user in any tenant
     * regardless of which agency they were viewing — a cross-tenant leak. Because
     * $agencyId comes from getAgencyId() (which resolves the X-Active-Agency-Id a
     * platform_admin has switched into), a super-admin still manages any agency —
     * one at a time, by selecting it. To act on another agency's user, switch to
     * that agency first.
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
    /**
     * Emails that are permanently protected: always super-admin, never deletable.
     * Guarded across every delete path so the platform always retains an owner.
     */
    private const PROTECTED_SUPER_ADMINS = ['mr.anthonyhosein@gmail.com'];

    private function isProtectedEmail(?string $email): bool
    {
        return $email !== null && in_array(strtolower(trim($email)), self::PROTECTED_SUPER_ADMINS, true);
    }

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

        // Protected super-admin account — can never be deleted or deactivated.
        if ($this->isProtectedEmail($user->email ?? null)) {
            return response()->json(['message' => 'This is the protected super-admin account and cannot be deleted.'], 422);
        }

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
        $file->storeAs('centre-logos', $name, 'public');

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
        $file->storeAs('avatars', $name, 'public');

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
