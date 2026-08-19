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
     * POST /integration/waitlist — mirror ONE of the partner's waitlist leads.
     * Lightweight (no family/child records created); origin is always 'ilearn'
     * here (this endpoint only ingests the partner's own entries). Pass
     * deleted=true to soft-remove one that was cleared on the partner side.
     */
    /**
     * A payroll document issued to staff — a payslip or a payroll invoice.
     *
     * iLearn runs the payroll; KiddieTrac mirrors what was issued so staff can see their
     * own history and admins can read the whole ledger in one place. Deductions come
     * across as sent: iLearn pays net, and a mirror that kept only gross would disagree
     * with the payslip the person actually received.
     *
     * The payee is matched by EMAIL, not name — iLearn's names are free text and already
     * contain a typo across two rows of one person. A match additionally requires an
     * active role in THIS agency: an email that resolves to somebody in another agency is
     * left unlinked rather than filed across the boundary. The document is still stored,
     * because the agency's payroll history has to be complete even where a payee has no
     * KiddieTrac account.
     */
    public function upsertPayroll(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'      => 'required|string|max:120',
            'kind'             => 'required|in:payslip,invoice',
            'payee_name'       => 'nullable|string|max:120',
            'payee_email'      => 'nullable|email|max:190',
            'recipient_type'   => 'nullable|string|max:40',
            'reference'        => 'nullable|string|max:64',
            'period_start'     => 'nullable|date',
            'period_end'       => 'nullable|date',
            'pay_frequency'    => 'nullable|string|max:32',
            'units'            => 'nullable|numeric',
            'ot_hours'         => 'nullable|numeric',
            'ot_mult'          => 'nullable|numeric',
            'ot_amount'        => 'nullable|numeric',
            'unit_label'       => 'nullable|string|max:16',
            'rate'             => 'nullable|numeric',
            'gross'            => 'nullable|numeric',
            'cpp'              => 'nullable|numeric',
            'ei'               => 'nullable|numeric',
            'income_tax'       => 'nullable|numeric',
            'other_deductions' => 'nullable|numeric',
            'vacation_pay'     => 'nullable|numeric',
            'net'              => 'nullable|numeric',
            'benefits'         => 'nullable',
            'status'           => 'nullable|string|max:40',
            'issued_at'        => 'nullable|date',
            'paid_at'          => 'nullable|date',
            'notes'            => 'nullable|string',
            'currency'         => 'nullable|string|max:8',
            'deleted'          => 'nullable|boolean',
        ]);
        $source = $this->source($request);
        $key = $source . ':' . $data['kind'] . ':' . $data['external_id'];

        // A record deleted upstream is voided here rather than removed: it was really
        // issued, and a payroll ledger that silently loses rows cannot be reconciled.
        if (! empty($data['deleted'])) {
            $n = DB::table('payroll_documents')->where('external_key', $key)
                ->where('agency_id', $agencyId)
                ->update(['status' => 'void', 'updated_at' => now()]);

            return response()->json(['ok' => true, 'voided' => $n]);
        }

        // Match by email, and only to somebody who actually belongs to this agency.
        $userId = null;
        $roleLabel = null;
        $group = null;
        $matchedRole = null;
        $candidate = null;

        if (! empty($data['payee_email'])) {
            $candidate = DB::table('users')->whereRaw('LOWER(email) = ?', [strtolower($data['payee_email'])])
                ->first(['id']);
        } elseif (! empty($data['payee_name'])) {
            // No email on the record. Fall back to the name, but only when it resolves to
            // exactly ONE active person in this agency — an ambiguous name links nobody,
            // because putting one person's pay on another's record is the worst outcome here.
            $named = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.active', 1)->where('ra.agency_id', $agencyId)
                ->whereRaw("LOWER(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))) = ?",
                    [strtolower(trim((string) $data['payee_name']))])
                ->distinct()->pluck('u.id');
            if ($named->count() === 1) {
                $candidate = (object) ['id' => $named->first()];
            }
        }

        if ($candidate) {
            $inAgency = DB::table('role_assignments')->where('user_id', $candidate->id)
                ->where('active', 1)->where('agency_id', $agencyId)->exists();
            if ($inAgency) {
                $userId = (int) $candidate->id;
                $matchedRole = \App\Support\Payroll::primaryRole($userId);
                $roleLabel = \App\Support\Payroll::LABEL[$matchedRole] ?? 'Staff';
                $group = \App\Support\Payroll::groupFor($matchedRole);
            }
        }

        // Being a parent at the centre says nothing about how somebody is PAID. Where the
        // only KiddieTrac role is guardian, what iLearn calls them on the payroll wins —
        // otherwise a provider's payslip reads "Parent" and lands on the wrong payroll.
        $rtRaw = strtolower((string) ($data['recipient_type'] ?? ''));
        if ($matchedRole === 'guardian' && in_array($rtRaw, ['provider', 'employee', 'contractor', 'educator'], true)) {
            $roleLabel = ucfirst($rtRaw);
            $group = in_array($rtRaw, ['provider', 'employee', 'educator'], true) ? 'educators' : 'other';
        }

        // Unmatched, or matched but with no KiddieTrac role to read a group from: fall
        // back to what iLearn calls them. A contractor is not on the educator payroll.
        if ($group === null) {
            $rt = strtolower((string) ($data['recipient_type'] ?? ''));
            $group = in_array($rt, ['provider', 'employee', 'educator'], true) ? 'educators' : 'other';
            $roleLabel = $roleLabel ?: ($rt !== '' ? ucfirst($rt) : 'Staff');
        }

        $status = strtolower((string) ($data['status'] ?? 'issued'));
        if (in_array($status, ['paid', 'complete', 'completed'], true)) {
            $status = 'paid';
        } elseif (in_array($status, ['void', 'voided', 'cancelled', 'canceled'], true)) {
            $status = 'void';
        } else {
            $status = 'issued';   // approved / draft / open all mean "issued, not yet paid"
        }

        $benefits = $data['benefits'] ?? null;
        if (is_array($benefits)) {
            $benefits = json_encode($benefits);
        }

        $row = [
            'agency_id'        => $agencyId,
            'user_id'          => $userId,
            'staff_group'      => $group,
            'role_label'       => $roleLabel,
            'payee_name'       => $data['payee_name'] ?? null,
            'payee_email'      => $data['payee_email'] ?? null,
            'kind'             => $data['kind'],
            'reference'        => $data['reference'] ?? null,
            'period_start'     => $data['period_start'] ?? null,
            'period_end'       => $data['period_end'] ?? null,
            'pay_frequency'    => $data['pay_frequency'] ?? null,
            'units'            => round((float) ($data['units'] ?? 0), 2),
            // Nullable, not zero-defaulted: "no overtime recorded" and "zero overtime
            // hours" are different statements, and a payslip should not assert the second.
            'ot_hours'         => isset($data['ot_hours']) ? round((float) $data['ot_hours'], 2) : null,
            'ot_mult'          => isset($data['ot_mult']) ? round((float) $data['ot_mult'], 2) : null,
            'ot_amount'        => isset($data['ot_amount']) ? round((float) $data['ot_amount'], 2) : null,
            'unit_label'       => $data['unit_label'] ?? 'hours',
            'rate'             => round((float) ($data['rate'] ?? 0), 2),
            'gross'            => round((float) ($data['gross'] ?? 0), 2),
            'cpp'              => isset($data['cpp']) ? round((float) $data['cpp'], 2) : null,
            'ei'               => isset($data['ei']) ? round((float) $data['ei'], 2) : null,
            'income_tax'       => isset($data['income_tax']) ? round((float) $data['income_tax'], 2) : null,
            'other_deductions' => isset($data['other_deductions']) ? round((float) $data['other_deductions'], 2) : null,
            'vacation_pay'     => isset($data['vacation_pay']) ? round((float) $data['vacation_pay'], 2) : null,
            'net'              => isset($data['net']) ? round((float) $data['net'], 2) : null,
            'benefits'         => $benefits,
            'currency'         => $data['currency'] ?? 'CAD',
            'status'           => $status,
            'source'           => $source,
            'external_source'  => $source,
            'external_id'      => $data['external_id'],
            'external_key'     => $key,
            'issued_at'        => $data['issued_at'] ?? null,
            'paid_at'          => $data['paid_at'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'updated_at'       => now(),
        ];

        $existing = DB::table('payroll_documents')->where('external_key', $key)->first(['id', 'agency_id']);
        if ($existing) {
            // Never let a re-push move a document between agencies.
            abort_unless((int) $existing->agency_id === $agencyId, 422, 'That document belongs to another agency.');
            DB::table('payroll_documents')->where('id', $existing->id)->update($row);

            return response()->json(['ok' => true, 'created' => false, 'id' => $existing->id, 'linked' => $userId !== null]);
        }

        $row['created_at'] = now();
        $id = DB::table('payroll_documents')->insertGetId($row);

        return response()->json(['ok' => true, 'created' => true, 'id' => $id, 'linked' => $userId !== null]);
    }

    public function upsertWaitlist(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $data = $request->validate([
            'external_id'         => 'required|string|max:191',
            'child_name'          => 'nullable|string|max:191',
            'child_dob'           => 'nullable|date',
            'age_group'           => 'nullable|string|max:60',
            'parent_name'         => 'nullable|string|max:191',
            'email'               => 'nullable|string|max:191',
            'phone'               => 'nullable|string|max:60',
            'desired_start'       => 'nullable|date',
            'days_needed'         => 'nullable|string|max:120',
            'status'              => 'nullable|string|max:40',
            'priority'            => 'nullable|string|max:40',
            'source'              => 'nullable|string|max:80',
            'position'            => 'nullable|integer',
            'notes'               => 'nullable|string',
            'area_of_interest'    => 'nullable|string|max:191',
            'external_updated_at' => 'nullable|date',
            'deleted'             => 'nullable|boolean',
        ]);
        $src = $this->source($request);
        $existing = DB::table('external_waitlist')
            ->where('agency_id', $agencyId)->where('external_source', $src)
            ->where('external_id', $data['external_id'])->first();

        if (! empty($data['deleted'])) {
            if ($existing) {
                DB::table('external_waitlist')->where('id', $existing->id)
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }
            return response()->json(['ok' => true, 'entity' => 'waitlist', 'deleted' => true, 'external_id' => $data['external_id']]);
        }

        $attrs = [
            'agency_id' => $agencyId, 'origin' => 'ilearn', 'external_source' => $src,
            'external_id' => $data['external_id'],
            'child_name' => $data['child_name'] ?? null, 'child_dob' => $data['child_dob'] ?? null,
            'age_group' => $data['age_group'] ?? null,
            'parent_name' => $data['parent_name'] ?? null, 'email' => $data['email'] ?? null,
            'phone' => $data['phone'] ?? null,
            'desired_start' => $data['desired_start'] ?? null, 'days_needed' => $data['days_needed'] ?? null,
            'status' => $data['status'] ?? 'Waiting', 'priority' => $data['priority'] ?? null,
            'source' => $data['source'] ?? null, 'position' => (int) ($data['position'] ?? 0),
            'notes' => $data['notes'] ?? null, 'area_of_interest' => $data['area_of_interest'] ?? null,
            'external_updated_at' => ! empty($data['external_updated_at'])
                ? \Illuminate\Support\Carbon::parse($data['external_updated_at'])->utc()->format('Y-m-d H:i:s') : null,
            'deleted_at' => null,   // an upsert un-deletes a previously-removed entry
            'updated_at' => now(),
        ];
        // Preserve an admin's KiddieTrac-side decision on this lead — a routine
        // re-push from the source must not reset an Approved/Declined status.
        if ($existing && in_array($existing->status, ['Approved', 'Declined'], true)) {
            $attrs['status'] = $existing->status;
        }
        $created = ! $existing;
        if ($created) {
            $attrs['created_at'] = now();
            $id = DB::table('external_waitlist')->insertGetId($attrs);
        } else {
            DB::table('external_waitlist')->where('id', $existing->id)->update($attrs);
            $id = $existing->id;
        }
        return response()->json(['ok' => true, 'entity' => 'waitlist', 'created' => $created, 'id' => $id, 'external_id' => $data['external_id']], $created ? 201 : 200);
    }

    /**
     * GET /integration/waitlist/pull?since= — the reverse direction. Returns the
     * KiddieTrac-ORIGINATED waitlist entries (origin='kiddietrac') so the partner
     * can ingest them into its own waitlist. Includes soft-deleted rows (with
     * deleted=true) so removals propagate. Never returns partner-origin rows, so
     * there is no echo loop.
     */
    public function pullWaitlist(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $q = DB::table('external_waitlist')->where('agency_id', $agencyId)->where('origin', 'kiddietrac');
        if ($since = $request->query('since')) {
            $q->where('updated_at', '>=', \Illuminate\Support\Carbon::parse($since)->utc()->format('Y-m-d H:i:s'));
        }
        $rows = $q->orderBy('updated_at')->limit(500)->get();
        return response()->json(['ok' => true, 'entries' => $rows->map(function ($r) {
            return [
                'external_id' => $r->external_id, 'child_name' => $r->child_name, 'child_dob' => $r->child_dob,
                'age_group' => $r->age_group, 'parent_name' => $r->parent_name, 'email' => $r->email, 'phone' => $r->phone,
                'desired_start' => $r->desired_start, 'days_needed' => $r->days_needed, 'status' => $r->status,
                'priority' => $r->priority, 'source' => $r->source, 'position' => $r->position, 'notes' => $r->notes,
                'area_of_interest' => $r->area_of_interest, 'deleted' => ! is_null($r->deleted_at),
                'updated_at' => $r->updated_at,
            ];
        })->values()]);
    }

    /**
     * GET /integration/contacts/pull?since= — the reverse contact sync. Returns
     * the CURRENT KiddieTrac field values for this agency's synced children so
     * the partner can pull edits made in KiddieTrac back into its own records
     * (last-writer-wins is decided partner-side by comparing updated_at). Only
     * children that came FROM the partner (external_source + external_id) are
     * returned — those are the ones with a stable id to match on.
     */
    public function pullContacts(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $src = $this->source($request);
        $centreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $famIds = Family::whereIn('centre_id', $centreIds)->pluck('id');
        $q = DB::table('children')
            ->whereIn('family_id', $famIds)
            ->where('external_source', $src)
            ->whereNotNull('external_id');
        if ($since = $request->query('since')) {
            $q->where('updated_at', '>=', \Illuminate\Support\Carbon::parse($since)->utc()->format('Y-m-d H:i:s'));
        }
        $children = $q->orderBy('updated_at')->limit(2000)
            ->get(['external_id', 'first_name', 'last_name', 'date_of_birth', 'gender', 'updated_at'])
            ->map(fn ($c) => [
                'external_id'   => $c->external_id,
                'first_name'    => $c->first_name,
                'last_name'     => $c->last_name,
                'date_of_birth' => $c->date_of_birth,
                'gender'        => $c->gender,
                'updated_at'    => $c->updated_at,
            ])->values();

        return response()->json(['ok' => true, 'children' => $children]);
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

        $results = ['centres' => [], 'families' => [], 'guardians' => [], 'children' => [], 'invoices' => [], 'waitlist' => [], 'withdrawals' => []];
        // Order matters: enrol/upsert first, then withdraw — so a child re-pushed
        // as enrolled in the same batch isn't immediately undone by a stale withdrawal.
        // Invoices need their family to exist, so they run after families/guardians.
        // Waitlist is standalone (no family/child dependency).
        $map = ['centres' => 'upsertCentre', 'families' => 'upsertFamily', 'guardians' => 'upsertGuardian', 'children' => 'upsertChild', 'invoices' => 'upsertInvoice', 'waitlist' => 'upsertWaitlist', 'withdrawals' => 'deactivateChild'];
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
