<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\Centre;
use App\Models\Child;
use App\Models\Family;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Generic agency-integration API (v1).
 *
 * Lets an external agency platform (first consumer: iLearn) feed its
 * centres / families / children into KiddieTrac under ITS OWN agency, with
 * idempotent upserts keyed by the caller's own `external_id`. Every request is
 * scoped to the authenticated agency_admin's agency, so one agency can never
 * read or write another's data. Reusable by any agency: obtain an agency_admin
 * API token, then POST here.
 *
 * Auth: Bearer <Sanctum token>, role agency_admin (or platform_admin).
 * Agency: the token's active agency, or override with X-Active-Agency-Id.
 * Source: set X-Integration-Source (e.g. "ilearn") so each external system's
 *         records are namespaced and never collide.
 */
class IntegrationController extends Controller
{
    /** GET /integration/ping — verify the token + see which agency it writes to. */
    public function ping(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $agency = Agency::find($agencyId);

        return response()->json([
            'ok'          => true,
            'agency_id'   => $agencyId,
            'agency_name' => $agency?->name,
            'source'      => $this->source($request),
            'now'         => now()->toIso8601String(),
        ]);
    }

    /** POST /integration/centres — upsert a centre (provider) by external_id. */
    public function upsertCentre(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'      => 'required|string|max:191',
            'name'             => 'required|string|max:191',
            'supervisor_first_name' => 'nullable|string|max:120',
            'supervisor_last_name'  => 'nullable|string|max:120',
            'license_number'   => 'nullable|string|max:120',
            'license_capacity' => 'nullable|integer|min:0',
            'address_line1'    => 'nullable|string|max:191',
            'address_line2'    => 'nullable|string|max:191',
            'city'             => 'nullable|string|max:120',
            'province'         => 'nullable|string|max:120',
            'postal_code'      => 'nullable|string|max:20',
            'country'          => 'nullable|string|max:120',
            'phone'            => 'nullable|string|max:60',
            'email'            => 'nullable|email|max:191',
            'status'           => 'nullable|string|max:40',
            'date_of_birth'    => 'nullable|date', // provider DOB
        ]);
        $source = $this->source($request);

        $centre = Centre::where('agency_id', $agencyId)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])->first();
        $created = ! $centre;

        $attrs = collect($data)->except('external_id')->filter(fn ($v) => $v !== null)->all();
        $attrs['agency_id'] = $agencyId;
        $attrs['external_id'] = $data['external_id'];
        $attrs['external_source'] = $source;
        $attrs['country'] = $attrs['country'] ?? 'Canada';
        $attrs['status'] = $attrs['status'] ?? 'active';

        if ($created) {
            $attrs['slug'] = $this->uniqueSlug($data['name']);
            $centre = Centre::create($attrs);
        } else {
            $centre->update($attrs);
        }

        // Home-childcare providers have no rooms; without at least one, an educator
        // assigned here hits "No rooms assigned" and can't work. Guarantee a room.
        $this->ensureDefaultRoom($centre);

        return response()->json([
            'ok' => true, 'entity' => 'centre', 'created' => $created,
            'id' => $centre->id, 'external_id' => $centre->external_id, 'slug' => $centre->slug,
        ], $created ? 201 : 200);
    }

    /**
     * Every centre needs ≥1 room so the room-centric educator/care flows work,
     * and its children must be ENROLLED in a room or the educator roster stays
     * empty ("no children" even with a room). Ensures both.
     */
    private function ensureDefaultRoom($centre): void
    {
        $DB = \Illuminate\Support\Facades\DB::class;
        try {
            $roomId = \Illuminate\Support\Facades\DB::table('rooms')->where('centre_id', $centre->id)->value('id');
            if (! $roomId) {
                $roomId = \Illuminate\Support\Facades\DB::table('rooms')->insertGetId([
                    'centre_id'       => $centre->id,
                    'name'            => 'Main room',
                    'age_group'       => 'preschool',
                    'age_min_months'  => 0,
                    'age_max_months'  => 72,
                    'capacity'        => $centre->license_capacity ?: 6,
                    'ratio_educators' => 1,
                    'ratio_children'  => 6,
                    'active'          => 1,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]);
            }
            // Enroll any of this centre's children who aren't in a room yet.
            $unenrolled = \Illuminate\Support\Facades\DB::table('children as c')
                ->join('families as f', 'f.id', '=', 'c.family_id')
                ->where('f.centre_id', $centre->id)->whereNull('c.deleted_at')
                ->whereNotExists(function ($q) {
                    $q->select(\Illuminate\Support\Facades\DB::raw(1))->from('enrollments')->whereColumn('enrollments.child_id', 'c.id');
                })
                ->pluck('c.id');
            foreach ($unenrolled as $cid) {
                \Illuminate\Support\Facades\DB::table('enrollments')->insert([
                    'child_id'        => $cid,
                    'room_id'         => $roomId,
                    'start_date'      => now()->toDateString(),
                    'schedule'        => json_encode(['mon', 'tue', 'wed', 'thu', 'fri']),
                    'monthly_fee'     => 0,
                    'cwelcc_eligible' => 1,
                    'created_at'      => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // never fail the sync because of the default room / enrolment
        }
    }

    /** POST /integration/families — upsert a family by external_id, linked to a centre. */
    public function upsertFamily(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'        => 'required|string|max:191',
            'centre_external_id' => 'required|string|max:191',
            'family_name'        => 'required|string|max:191',
            'primary_phone'      => 'nullable|string|max:60',
            'primary_email'      => 'nullable|email|max:191',
            'address_line1'      => 'nullable|string|max:191',
            'address_line2'      => 'nullable|string|max:191',
            'city'               => 'nullable|string|max:120',
            'province'           => 'nullable|string|max:120',
            'postal_code'        => 'nullable|string|max:20',
            'preferred_lang'     => 'nullable|string|max:20',
            'notes'              => 'nullable|string',
        ]);
        $source = $this->source($request);

        $centre = Centre::where('agency_id', $agencyId)
            ->where('external_source', $source)
            ->where('external_id', $data['centre_external_id'])->first();
        abort_unless($centre, 422, 'Unknown centre_external_id for this agency — upsert the centre first.');

        $agencyCentreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $family = Family::whereIn('centre_id', $agencyCentreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])->first();
        $created = ! $family;

        $attrs = collect($data)->except(['external_id', 'centre_external_id'])->filter(fn ($v) => $v !== null)->all();
        $attrs['centre_id'] = $centre->id;
        $attrs['external_id'] = $data['external_id'];
        $attrs['external_source'] = $source;

        if ($created) {
            $family = Family::create($attrs);
        } else {
            $family->update($attrs);
        }

        return response()->json([
            'ok' => true, 'entity' => 'family', 'created' => $created,
            'id' => $family->id, 'external_id' => $family->external_id, 'centre_id' => $centre->id,
        ], $created ? 201 : 200);
    }

    /** POST /integration/children — upsert a child by external_id, linked to a family. */
    public function upsertChild(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'        => 'required|string|max:191',
            'family_external_id' => 'required|string|max:191',
            'first_name'         => 'required|string|max:120',
            'last_name'          => 'nullable|string|max:120',
            'preferred_name'     => 'nullable|string|max:120',
            'date_of_birth'      => 'nullable|date',
            'gender'             => 'nullable|string|max:40',
            'dietary_notes'      => 'nullable|string',
            'medical_notes'      => 'nullable|string',
            'preferred_lang'     => 'nullable|string|max:20',
            'enrollment_status'  => 'nullable|string|max:40',
            'enrolled_at'        => 'nullable|date',
        ]);
        $source = $this->source($request);

        $agencyCentreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $family = Family::whereIn('centre_id', $agencyCentreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['family_external_id'])->first();
        abort_unless($family, 422, 'Unknown family_external_id for this agency — upsert the family first.');

        $agencyFamilyIds = Family::whereIn('centre_id', $agencyCentreIds)->pluck('id');
        $child = Child::whereIn('family_id', $agencyFamilyIds)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])->first();

        // Dedup at the source: if no external_id match exists but this family already
        // has a non-deleted child with the same first+last name, adopt that record
        // (link this external_id to it) rather than creating a duplicate. This is the
        // root-cause guard for cases like Weston Boyd appearing twice under one provider.
        if (! $child) {
            $fn = trim(mb_strtolower($data['first_name']));
            $ln = trim(mb_strtolower($data['last_name'] ?? ''));
            $child = Child::where('family_id', $family->id)
                ->whereRaw('LOWER(TRIM(first_name)) = ?', [$fn])
                ->whereRaw('LOWER(TRIM(COALESCE(last_name, ""))) = ?', [$ln])
                ->first();
        }
        $created = ! $child;

        $attrs = collect($data)->except(['external_id', 'family_external_id'])->filter(fn ($v) => $v !== null)->all();
        $attrs['family_id'] = $family->id;
        $attrs['external_id'] = $data['external_id'];
        $attrs['external_source'] = $source;

        if ($created) {
            $child = Child::create($attrs);
        } else {
            $child->update($attrs);
        }

        // Re-enrolment: a child that comes back as "enrolled" must shed a stale
        // withdrawn_at so it doesn't read as both enrolled and withdrawn.
        if (($attrs['enrollment_status'] ?? null) === 'enrolled' && $child->withdrawn_at) {
            $child->withdrawn_at = null;
            $child->save();
        }

        return response()->json([
            'ok' => true, 'entity' => 'child', 'created' => $created,
            'id' => $child->id, 'external_id' => $child->external_id, 'family_id' => $family->id,
        ], $created ? 201 : 200);
    }

    /**
     * POST /integration/children/deactivate — mark a child as withdrawn/graduated.
     *
     * Idempotent counterpart to upsertChild: keyed on the caller's `external_id`
     * within (agency, source). Sets `enrollment_status` (default `withdrawn`) and
     * stamps `withdrawn_at`. The record is KEPT (not soft-deleted) so attendance,
     * billing and roster history survive a child leaving. If the child was never
     * pushed here, returns 200 `found:false` (a no-op) so a batch/observer that
     * fires for an unsynced child never errors.
     */
    public function deactivateChild(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'       => 'required|string|max:191',
            'enrollment_status' => 'nullable|in:withdrawn,graduated',
            'withdrawn_at'      => 'nullable|date',
        ]);
        $source = $this->source($request);

        $agencyCentreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $agencyFamilyIds = Family::whereIn('centre_id', $agencyCentreIds)->pluck('id');
        $child = Child::whereIn('family_id', $agencyFamilyIds)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])->first();

        if (! $child) {
            return response()->json([
                'ok' => true, 'entity' => 'child', 'found' => false,
                'external_id' => $data['external_id'], 'note' => 'not present — nothing to withdraw',
            ]);
        }

        $status = $data['enrollment_status'] ?? 'withdrawn';
        $changed = $child->enrollment_status !== $status;
        $child->enrollment_status = $status;
        $child->withdrawn_at = $data['withdrawn_at'] ?? ($child->withdrawn_at ?? now());
        $child->save();

        return response()->json([
            'ok' => true, 'entity' => 'child', 'found' => true, 'changed' => $changed,
            'id' => $child->id, 'external_id' => $child->external_id,
            'enrollment_status' => $status, 'withdrawn_at' => $child->withdrawn_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /integration/guardians — upsert a parent's KiddieTrac login account
     * (User + Guardian + guardian role) linked to a family. Lets parents access
     * the parent-portal. Created users start `invited` with a random password;
     * they activate via the normal forgot-password flow (no email sent here by
     * default, so a bulk backfill doesn't spam parents).
     */
    public function upsertGuardian(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'family_external_id' => 'required|string|max:191',
            'email'              => 'required|email|max:191',
            'first_name'         => 'required|string|max:120',
            'last_name'          => 'nullable|string|max:120',
            'phone'              => 'nullable|string|max:60',
            'relationship'       => 'nullable|string|max:60',
            'is_primary'         => 'nullable|boolean',
            'can_pickup'         => 'nullable|boolean',
            'date_of_birth'      => 'nullable|date', // parent DOB
        ]);
        $source = $this->source($request);

        $agencyCentreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $family = Family::whereIn('centre_id', $agencyCentreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['family_external_id'])->first();
        abort_unless($family, 422, 'Unknown family_external_id for this agency — upsert the family first.');

        $existing = DB::table('users')->where('email', $data['email'])->whereNull('deleted_at')->first();
        $createdUser = ! $existing;
        if ($existing) {
            $userId = (int) $existing->id;
        } else {
            $userId = (int) DB::table('users')->insertGetId([
                'email'      => $data['email'],
                'password'   => \Illuminate\Support\Facades\Hash::make(Str::random(24)),
                'first_name' => $data['first_name'],
                'last_name'  => $data['last_name'] ?? '',
                'phone'      => $data['phone'] ?? null,
                'locale'     => 'en-CA',
                'timezone'   => 'America/Toronto',
                'status'     => 'invited',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('guardians')->updateOrInsert(
            ['family_id' => $family->id, 'user_id' => $userId],
            array_filter([
                'relationship'        => $data['relationship'] ?? 'guardian',
                'is_primary'          => $data['is_primary'] ?? false,
                'can_pickup'          => $data['can_pickup'] ?? true,
                'can_receive_billing' => false,
                'billing_share_pct'   => 0,
                'date_of_birth'       => $data['date_of_birth'] ?? null,
                'created_at'          => now(),
            ], fn ($v) => $v !== null)
        );
        DB::table('role_assignments')->updateOrInsert(
            ['user_id' => $userId, 'role' => 'guardian', 'agency_id' => $agencyId, 'centre_id' => null],
            ['active' => true, 'created_at' => now()]
        );

        return response()->json([
            'ok' => true, 'entity' => 'guardian', 'created_user' => $createdUser,
            'user_id' => $userId, 'family_id' => $family->id,
        ], $createdUser ? 201 : 200);
    }

    /**
     * POST /integration/invoices — upsert a parent invoice by external_id, linked
     * to a family. Read-only mirror of the source platform's invoice so the parent
     * portal can display it; KiddieTrac never collects payment on these.
     */
    public function upsertInvoice(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'         => 'required|string|max:191',
            'family_external_id'  => 'required|string|max:191',
            'number'              => 'nullable|string|max:120',
            'status'              => 'nullable|string|max:40',
            'issued_at'           => 'nullable|date',
            'due_at'              => 'nullable|date',
            'total'               => 'nullable|numeric',
            'amount_paid'         => 'nullable|numeric',
            'balance_due'         => 'nullable|numeric',
            'currency'            => 'nullable|string|max:8',
            'description'         => 'nullable|string',
            'items'               => 'nullable|array',
            'source_label'        => 'nullable|string|max:60',
            'pdf_url'             => 'nullable|string|max:500',
            'external_updated_at' => 'nullable|date',
        ]);
        $source = $this->source($request);

        $agencyCentreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $family = Family::whereIn('centre_id', $agencyCentreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['family_external_id'])->first();
        abort_unless($family, 422, 'Unknown family_external_id for this agency — upsert the family first.');

        $status  = strtolower($data['status'] ?? 'open');
        $total   = round((float) ($data['total'] ?? 0), 2);
        $paid    = round((float) ($data['amount_paid'] ?? 0), 2);
        $balance = array_key_exists('balance_due', $data) && $data['balance_due'] !== null
            ? round((float) $data['balance_due'], 2)
            : max(0, round($total - $paid, 2));

        $existing = DB::table('external_invoices')
            ->where('agency_id', $agencyId)->where('external_source', $source)
            ->where('external_id', $data['external_id'])->first();
        $created = ! $existing;

        $attrs = [
            'agency_id'           => $agencyId,
            'family_id'           => $family->id,
            'external_source'     => $source,
            'external_id'         => $data['external_id'],
            'number'              => $data['number'] ?? null,
            'status'              => $status,
            'issued_at'           => $data['issued_at'] ?? null,
            'due_at'              => $data['due_at'] ?? null,
            'total'               => $total,
            'amount_paid'         => $paid,
            'balance_due'         => $balance,
            'currency'            => $data['currency'] ?? 'CAD',
            'description'         => $data['description'] ?? null,
            'items'               => isset($data['items']) ? json_encode($data['items']) : null,
            'source_label'        => $data['source_label'] ?? ucfirst($source),
            'pdf_url'             => $data['pdf_url'] ?? null,
            // Normalize any ISO-8601 / offset datetime to a MySQL-storable string
            // (a raw "2026-07-14T14:26:30-04:00" fails a direct query-builder insert).
            'external_updated_at' => ! empty($data['external_updated_at'])
                ? \Illuminate\Support\Carbon::parse($data['external_updated_at'])->utc()->format('Y-m-d H:i:s')
                : null,
            'updated_at'          => now(),
        ];
        if ($created) {
            $attrs['created_at'] = now();
            $id = DB::table('external_invoices')->insertGetId($attrs);
        } else {
            DB::table('external_invoices')->where('id', $existing->id)->update($attrs);
            $id = $existing->id;
        }

        return response()->json([
            'ok' => true, 'entity' => 'invoice', 'created' => $created,
            'id' => $id, 'external_id' => $data['external_id'], 'family_id' => $family->id,
        ], $created ? 201 : 200);
    }

    /**
     * POST /integration/sync — batch upsert in dependency order
     * (centres -> families -> guardians -> children -> invoices -> withdrawals).
     * Each item uses the same shape as the single endpoints. Returns per-item results
     * so a partial failure never blocks the rest of the batch.
     */
    public function sync(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $source = $request->header('X-Integration-Source');

        $results = ['centres' => [], 'families' => [], 'guardians' => [], 'children' => [], 'invoices' => [], 'withdrawals' => []];
        // Order matters: enrol/upsert first, then withdraw — so a child re-pushed
        // as enrolled in the same batch isn't immediately undone by a stale withdrawal.
        // Invoices need their family to exist, so they run after families/guardians.
        $map = ['centres' => 'upsertCentre', 'families' => 'upsertFamily', 'guardians' => 'upsertGuardian', 'children' => 'upsertChild', 'invoices' => 'upsertInvoice', 'withdrawals' => 'deactivateChild'];
        foreach ($map as $key => $method) {
            foreach ((array) $request->input($key, []) as $row) {
                $sub = Request::create('/', 'POST', (array) $row);
                $sub->setUserResolver(fn () => $request->user());
                $sub->headers->set('X-Active-Agency-Id', (string) $agencyId);
                if ($source) {
                    $sub->headers->set('X-Integration-Source', $source);
                }
                try {
                    $results[$key][] = $this->{$method}($sub)->getData(true);
                } catch (\Throwable $e) {
                    $results[$key][] = ['ok' => false, 'external_id' => $row['external_id'] ?? null, 'error' => $e->getMessage()];
                }
            }
        }

        return response()->json(['ok' => true, 'agency_id' => $agencyId, 'results' => $results]);
    }

    // ── helpers (mirror ImportController's agency scoping) ──────────────────
    private function source(Request $request): string
    {
        $raw = $request->header('X-Integration-Source') ?: $request->input('source', 'external');

        return Str::slug((string) $raw) ?: 'external';
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'centre';
        $slug = $base;
        $i = 1;
        while (Centre::where('slug', $slug)->exists()) {
            $slug = $base.'-'.(++$i);
        }

        return $slug;
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $activeId = (int) $request->header('X-Active-Agency-Id');

        // The header is user-controlled. Honour it only for a platform_admin, or for
        // someone holding an active role in the agency named. Callers here also run
        // assertAgencyAdmin(), which catches this today -- but the rule holds in every
        // other resolver on the platform and should not be false in this one.
        if ($activeId) {
            $isPlatform = $user && DB::table('role_assignments')->where('user_id', $user->id)
                ->where('role', 'platform_admin')->where('active', true)->exists();
            $belongs = $user && DB::table('role_assignments')->where('user_id', $user->id)
                ->where('active', true)->where('agency_id', $activeId)->exists();
            if ($isPlatform || $belongs) {
                return $activeId;
            }
        }
        $first = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->value('agency_id');
        abort_unless($first, 400, 'No active agency for this token.');

        return (int) $first;
    }

    private function assertAgencyAdmin(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            return;
        }
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403, 'Token is not an agency_admin for this agency.');
    }
}
