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
    /**
     * GET /integration/audit — what do you actually hold for me?
     *
     * A push integration can only ever report that it SENT something. Whether it
     * arrived, and whether it is still there, are different questions, and until now
     * nothing could ask them. That gap was not theoretical: 19 parent invoices for two
     * active families sat missing here for three weeks (2026-08-31). The observer that
     * pushes them had missed them once, and the only scheduled reconcile used a rolling
     * one-week window — so by the time anybody could have noticed, the window had moved
     * past and nothing would ever pick them up again. Silent, permanent, and invisible
     * from both ends.
     *
     * So this returns the ids, not just the counts. Counts tell you THAT something is
     * wrong; ids tell you WHICH, which is the difference between an alarm and a fix.
     * Agency-scoped and namespaced by source like everything else here.
     */
    public function audit(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $source = $this->source($request);

        $centreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $famRows = Family::withTrashed()->whereIn('centre_id', $centreIds)
            ->where('external_source', $source)->whereNotNull('external_id')
            ->get(['id', 'external_id', 'deleted_at']);

        // Every family of ours, including de-enrolled ones — the caller needs to tell
        // "you never sent it" apart from "we removed it", which are opposite problems.
        $active = $famRows->whereNull('deleted_at');
        $gone = $famRows->whereNotNull('deleted_at');

        return response()->json([
            'ok' => true,
            'agency_id' => $agencyId,
            'source' => $source,
            'at' => now()->toIso8601String(),
            'families' => [
                'active' => $active->count(),
                'de_enrolled' => $gone->count(),
                'active_external_ids' => $active->pluck('external_id')->values(),
                'de_enrolled_external_ids' => $gone->pluck('external_id')->values(),
            ],
            /* Scoped on agency_id + external_source, which both mirrors carry, rather
               than through family_id. A family join would quietly drop every invoice
               belonging to a family the caller has since de-enrolled -- and "my invoice
               vanished" is precisely the alarm this endpoint exists to raise. */
            'invoices' => [
                'count' => DB::table('external_invoices')->where('agency_id', $agencyId)
                    ->where('external_source', $source)->count(),
                'external_ids' => DB::table('external_invoices')->where('agency_id', $agencyId)
                    ->where('external_source', $source)->pluck('external_id')->values(),
            ],
            'payroll' => [
                'count' => DB::table('payroll_documents')->where('agency_id', $agencyId)
                    ->where('external_source', $source)->whereNotNull('external_id')->count(),
                'external_ids' => DB::table('payroll_documents')->where('agency_id', $agencyId)
                    ->where('external_source', $source)->whereNotNull('external_id')
                    ->pluck('external_id')->values(),
            ],
        ]);
    }

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
                    /* Named for the provider, not "Main room". Every centre in a
                       home-childcare agency IS a provider, so nine identical
                       "Main room" entries told an admin nothing about whose room
                       they were looking at. Admins can rename it afterwards —
                       this only sets what it starts as. */
                    'name'            => trim((string) ($centre->name ?? '')) ?: 'Main room',
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
                $this->placeChildInRoom((int) $cid, (int) $roomId);
            }
        } catch (\Throwable $e) {
            // never fail the sync because of the default room / enrolment
        }
    }

    /**
     * Put a child in a room — BOTH halves of it, idempotently.
     *
     * "Being in a room" is stored twice, and the portal reads the two separately:
     * children.primary_room_id drives the educator's room list, the ratios and the day
     * brief, while the centre roster, room roster and QR check-in INNER JOIN enrollments.
     * Writing only one leaves a child who shows up in some screens and not others, with
     * nothing anywhere to say why — which is far harder to notice than a child who is
     * plainly missing everywhere.
     *
     * Returns the room id it settled on, or null if it could not place the child.
     */
    private function placeChildInRoom(int $childId, int $roomId): ?int
    {
        try {
            $child = \Illuminate\Support\Facades\DB::table('children')->find($childId);
            if (! $child) {
                return null;
            }

            if (! $child->primary_room_id) {
                \Illuminate\Support\Facades\DB::table('children')->where('id', $childId)
                    ->update(['primary_room_id' => $roomId, 'updated_at' => now()]);
            }

            $hasEnrolment = \Illuminate\Support\Facades\DB::table('enrollments')
                ->where('child_id', $childId)->exists();

            if (! $hasEnrolment) {
                /* The start date must not be in the FUTURE. app.timezone is UTC, so
                   now()->toDateString() after ~8pm Toronto returns tomorrow — and a
                   roster filtering start_date <= today would then skip the child for a
                   day. Prefer the date the child was actually enrolled, and fall back to
                   the centre's own clock rather than the server's. */
                $tz = \Illuminate\Support\Facades\DB::table('centres as ce')
                    ->join('families as f', 'f.centre_id', '=', 'ce.id')
                    ->where('f.id', $child->family_id)->value('ce.timezone') ?: 'America/Toronto';

                $start = $child->enrolled_at
                    ? substr((string) $child->enrolled_at, 0, 10)
                    : now($tz)->toDateString();

                \Illuminate\Support\Facades\DB::table('enrollments')->insert([
                    'child_id'        => $childId,
                    'room_id'         => $roomId,
                    'start_date'      => $start,
                    'schedule'        => json_encode(['mon', 'tue', 'wed', 'thu', 'fri']),
                    'monthly_fee'     => 0,
                    'cwelcc_eligible' => 1,
                    'created_at'      => now(),
                ]);
            }

            return $roomId;
        } catch (\Throwable $e) {
            // never fail a sync over placement
            return null;
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

        /* Put the child in a room, HERE, on the child's own sync.
           This was only ever done by ensureDefaultRoom(), which runs from upsertCentre.
           So a child who arrived after their centre had already been synced — the normal
           case, because centres are created once and children keep arriving — was never
           placed at all. That is how Briar Mills reached the portal marked "enrolled"
           while her educator's list stayed empty: nothing was broken at sync time, the
           placement step simply never ran again. (Anthony, 2026-08-26) */
        $placed = null;
        if (($child->enrollment_status ?? 'enrolled') === 'enrolled') {
            $roomId = \Illuminate\Support\Facades\DB::table('rooms')
                ->where('centre_id', $family->centre_id)->where('active', 1)
                ->orderBy('id')->value('id');
            if ($roomId) {
                $placed = $this->placeChildInRoom((int) $child->id, (int) $roomId);
            }
        }

        /* A record appearing in the portal with no trace of where it came from is its
           own problem: when a family turned up unplaced there was nothing in the audit
           log to explain it, and the obvious reading — that an admin had added them and
           skipped a step — was wrong. */
        try {
            \App\Support\Audit::write([
                'user_id'     => optional($request->user())->id,
                'agency_id'   => $agencyId,
                'action'      => $created ? 'integration.child_created' : 'integration.child_updated',
                'entity_type' => 'child',
                'entity_id'   => $child->id,
                /* payload, not summary — audit_logs has no summary column, and the
                   surrounding try/catch would have swallowed that quietly, leaving this
                   whole audit silently doing nothing. */
                'payload'     => json_encode([
                    'name'      => trim(($child->first_name ?? '').' '.($child->last_name ?? '')),
                    'source'    => $source,
                    'family_id' => $family->id,
                    'placed_in_room' => $placed,
                ]),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // auditing must never fail a sync
        }

        /* Tell the admins there is a family to set up.
           Fired from the CHILD sync, not the family sync, because the checklist is only
           worth sending once the children are known — a notice listing nothing to do,
           followed a second later by children arriving, is worse than no notice at all.
           Guarded to once per family for its whole life: this runs from a daily cron and
           an observer, and a reminder that arrives every morning gets filtered. */
        if ($created) {
            \App\Services\NewFamilyNotice::forSyncedFamily((int) $family->id, $agencyId);
            // The bell, beside the email. No actor: this runs from a cron, and naming a
            // person who did not do it is worse than naming nobody.
            \App\Services\FamilyJoinedNotice::fire((int) $family->id, null);
        }

        return response()->json([
            'ok' => true, 'entity' => 'child', 'created' => $created,
            'id' => $child->id, 'external_id' => $child->external_id, 'family_id' => $family->id,
            'room_id' => $placed,
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

        /* ...and close the enrolment as well.
           Room membership lives in two tables and this wrote only one of them, so a child
           withdrawn through the sync kept an open `enrollments` row: still a member of the
           room as far as every query that joins enrolments is concerned. Four of the eight
           orphaned rows found on 2026-08-30 came from here — their families were never
           de-enrolled, so nothing else could have caused it.

           Dated to the child's own withdrawn_at, matching the family de-enrolment path. */
        $lastDay = $child->withdrawn_at instanceof \DateTimeInterface
            ? $child->withdrawn_at->format('Y-m-d')
            : substr((string) $child->withdrawn_at, 0, 10);
        if ($lastDay) {
            DB::table('enrollments')
                ->where('child_id', $child->id)
                ->whereNull('end_date')
                // Never close an enrolment before it began — that would be a
                // negative-length placement, and two such records already exist.
                ->where(function ($q) use ($lastDay) {
                    $q->whereNull('start_date')->orWhere('start_date', '<=', $lastDay);
                })
                ->update(['end_date' => $lastDay]);
        }

        return response()->json([
            'ok' => true, 'entity' => 'child', 'found' => true, 'changed' => $changed,
            'id' => $child->id, 'external_id' => $child->external_id,
            'enrollment_status' => $status, 'withdrawn_at' => $child->withdrawn_at?->toIso8601String(),
        ]);
    }

    /**
     * POST /integration/families/deactivate — de-enrol a family that the source
     * system has switched off.
     *
     * iLearn now carries a per-parent "onboard to KiddieTrac" flag. Turning it off
     * has to mean something here, and the honest meaning is the one the portal
     * already has a word for: de-enrolment. Not a delete — de-enrolling keeps the
     * children, the invoices and the history, and restoreFamily() brings it all back
     * if the flag goes on again.
     *
     * IT DELEGATES TO AdminController::destroyFamily RATHER THAN REPEATING IT. That
     * method is not a soft delete with a nice name — it ends open enrolments, closes
     * the guardians' logins, tells the affected rooms, honours a FUTURE last day as a
     * booking rather than a departure, computes the last day in the AGENCY's timezone,
     * and writes the audit row. A second implementation reached through the API would
     * be that list minus whatever was forgotten, drifting further apart with every
     * change to either one.
     *
     * THE MONEY GUARD IS DELIBERATELY NOT BYPASSED. destroyFamily answers 422 with
     * `requires_balance_acknowledgement` and the figure when the family still owes,
     * and that answer is passed straight back to the caller so it can put the number
     * in front of a person — the same warn-don't-block bargain the de-enrolment dialog
     * makes. An integration is not a reason to skip the safeguard; it is a second
     * doorway that has to honour it.
     */
    public function deactivateFamily(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate([
            'external_id'          => 'required|string|max:191',
            'reason'               => 'nullable|string|max:500',
            'last_day'             => 'nullable|date',
            'acknowledged_balance' => 'nullable|boolean',
        ]);
        $source = $this->source($request);

        $centreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        $family = Family::whereIn('centre_id', $centreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])
            ->whereNull('deleted_at')
            ->first();

        /* Idempotent on purpose. The caller may retry, or may be switching off a
           family that never reached us — neither is an error, and answering 404
           would leave the far end unable to tell "already done" from "broken". */
        if (! $family) {
            return response()->json([
                'ok' => true, 'entity' => 'family', 'found' => false,
                'external_id' => $data['external_id'],
                'note' => 'not present (or already de-enrolled) — nothing to do',
            ]);
        }

        /* destroyFamily() resolves the agency from the header, and an integration
           caller has no reason to have sent one. Set it to the agency this request
           has ALREADY been authorised against, two lines above — not to anything the
           caller supplied. */
        $request->headers->set('X-Active-Agency-Id', (string) $agencyId);
        $request->merge([
            'reason_code'          => 'other',
            'reason'               => $data['reason']
                ?? 'Onboarding to KiddieTrac was switched off in ' . $source . '.',
            'last_day'             => $data['last_day'] ?? null,
            'acknowledged_balance' => (bool) ($data['acknowledged_balance'] ?? false),
        ]);

        return app(AdminController::class)->destroyFamily($request, (int) $family->id);
    }

    /**
     * POST /integration/families/restore — the flag went back on.
     *
     * Without this, re-enabling a family would fall through to the ordinary upsert,
     * which cannot see a de-enrolled record (every lookup filters deleted_at) and so
     * would build a SECOND family beside the first — the children orphaned from their
     * invoices and history, and two rows where a parent expects one. Duplicate
     * families are the specific mess that made the lifecycle work necessary in the
     * first place; a reversible switch has to reverse, not re-create.
     */
    public function restoreFamilyExternal(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        $data = $request->validate(['external_id' => 'required|string|max:191']);
        $source = $this->source($request);

        $centreIds = Centre::where('agency_id', $agencyId)->pluck('id');
        // withTrashed: the whole point is to find one that IS de-enrolled.
        $family = Family::withTrashed()->whereIn('centre_id', $centreIds)
            ->where('external_source', $source)
            ->where('external_id', $data['external_id'])
            ->first();

        if (! $family) {
            return response()->json([
                'ok' => true, 'entity' => 'family', 'found' => false,
                'external_id' => $data['external_id'],
                'note' => 'never synced here — the ordinary upsert will create it',
            ]);
        }
        if (! $family->deleted_at) {
            return response()->json([
                'ok' => true, 'entity' => 'family', 'found' => true, 'restored' => false,
                'id' => $family->id, 'note' => 'already active — nothing to restore',
            ]);
        }

        $request->headers->set('X-Active-Agency-Id', (string) $agencyId);

        return app(AdminController::class)->restoreFamily($request, (int) $family->id);
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
            /* The source withdrew this invoice. Stated rather than inferred — the sync
               pushes in chunks, so an invoice missing from a batch means nothing. */
            'deleted'             => 'nullable|boolean',
        ]);
        $source = $this->source($request);

        /* WITHDRAWN UPSTREAM.

           Handled before the family lookup below, which aborts 422 on an unknown
           family: an invoice being withdrawn may belong to a family that has since
           been de-enrolled, and a tidy-up must not need the thing it is tidying up
           after.

           Voided rather than removed — the row keeps its history, and $isOpenStatus()
           already treats 'void' as not-open, so the balance leaves the outstanding
           figures without anything being destroyed. Never creates: an invoice we never
           mirrored has nothing to withdraw. */
        if (! empty($data['deleted'])) {
            $row = DB::table('external_invoices')
                ->where('agency_id', $agencyId)->where('external_source', $source)
                ->where('external_id', $data['external_id'])->first();

            if (! $row) {
                return response()->json([
                    'ok' => true, 'entity' => 'invoice', 'withdrawn' => true,
                    'existed' => false, 'external_id' => $data['external_id'],
                ]);
            }

            $wasOpen = ! in_array(strtolower((string) $row->status), ['paid', 'void'], true);
            DB::table('external_invoices')->where('id', $row->id)->update([
                'status'      => 'void',
                'balance_due' => 0,
                'updated_at'  => now(),
            ]);

            try {
                \App\Support\Audit::write([
                    'agency_id'   => $agencyId,
                    'action'      => 'invoice.withdrawn_upstream',
                    'entity_type' => 'external_invoice',
                    'entity_id'   => $row->id,
                    'payload'     => json_encode([
                        'external_id' => $data['external_id'],
                        'number'      => $row->number,
                        'family_id'   => $row->family_id,
                        'was_status'  => $row->status,
                        'was_balance' => (float) $row->balance_due,
                        'summary'     => 'Voided because ' . $source . ' deleted it at source'
                            . ($wasOpen ? ' — it was still showing as owed.' : '.'),
                    ]),
                    'created_at'  => now(),
                ]);
            } catch (\Throwable $e) { /* never fail a sync over its own audit row */ }

            return response()->json([
                'ok' => true, 'entity' => 'invoice', 'withdrawn' => true,
                'existed' => true, 'id' => $row->id, 'external_id' => $data['external_id'],
            ]);
        }

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
            /* The source system's OWN payslip, when it renders one. We draw a payslip
               from the figures above, which is close but not the same paper -- theirs
               carries the branding, the non-employee clause, year-to-date totals and
               terms. Given a url, PayrollDocumentController::pdf() serves that instead.
               url NOT active_url: the far end may be unreachable from here at validation
               time, and a payslip is not worth rejecting over a DNS blip. */
            'pdf_url'           => 'nullable|url|max:500',
            // The FORMAT of the password, never the password itself.
            'pdf_password_hint' => 'nullable|string|max:200',
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
            /* Where the source system's own payslip lives, and how to open it. Null
               for anything KiddieTrac produces itself, so nothing changes for those. */
            'pdf_url'           => $data['pdf_url'] ?? null,
            'pdf_password_hint' => $data['pdf_password_hint'] ?? null,
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
    /**
     * GET /integration/pull/new — families and children that ORIGINATED in KiddieTrac.
     *
     * The counterpart to pullContacts. That one returns records the caller already owns,
     * so it can refresh their field values; this one returns the records the caller has
     * never seen, so it can create them.
     *
     * `since` is required in spirit if not in syntax: without it this hands back every
     * KiddieTrac-native family in the agency, which for a first run is usually not what
     * anyone wants. The consumer is expected to pass its high-water mark.
     */
    public function pullNew(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);

        // Everything below hangs off this list, so a caller can only ever reach families
        // inside the agency its token belongs to.
        $centres = DB::table('centres')->where('agency_id', $agencyId)
            ->get(['id', 'name', 'external_id', 'external_source'])->keyBy('id');
        $centreIds = $centres->keys()->all();
        if (! $centreIds) {
            return response()->json(['ok' => true, 'families' => [], 'children' => []]);
        }

        $since = $request->query('since');
        $sinceSql = $since
            ? \Illuminate\Support\Carbon::parse($since)->utc()->format('Y-m-d H:i:s')
            : null;

        $decode = function ($v) {
            if ($v === null || $v === '') {
                return null;
            }
            $j = json_decode((string) $v, true);

            // allergies / dietary_restrictions are JSON arrays here but plain text in
            // iLearn, so they are flattened on the way out rather than at the far end.
            return is_array($j) ? (implode(', ', array_filter($j)) ?: null) : (string) $v;
        };

        $mapChild = fn ($c) => [
            'kt_id'             => (int) $c->id,
            'first_name'        => $c->first_name,
            'last_name'         => $c->last_name,
            'date_of_birth'     => $c->date_of_birth,
            'gender'            => $c->gender,
            'allergies'         => $decode($c->allergies ?? null),
            'dietary'           => $decode($c->dietary_restrictions ?? null),
            'medical_notes'     => $c->medical_notes,
            'enrollment_status' => $c->enrollment_status,
            'enrolled_at'       => $c->enrolled_at,
            'updated_at'        => $c->updated_at,
        ];

        $childCols = ['id', 'family_id', 'first_name', 'last_name', 'date_of_birth', 'gender',
            'allergies', 'dietary_restrictions', 'medical_notes', 'enrollment_status',
            'enrolled_at', 'updated_at'];

        // ── 1. Families created here, with their guardians and children ──────
        $famQ = DB::table('families')
            ->whereIn('centre_id', $centreIds)
            ->whereNull('external_source')          // no external origin => created here
            ->whereNull('deleted_at');
        if ($sinceSql) {
            // A family is "changed" if its OWN row changed, or if any of its children
            // did. Adding a child does not touch the family row, so filtering on
            // families.updated_at alone means the family syncs once and every child
            // added afterwards is invisible — in neither this list nor the orphan list
            // below, which only covers families that came FROM the consumer.
            $famQ->where(function ($q) use ($sinceSql) {
                $q->where('families.updated_at', '>=', $sinceSql)
                    ->orWhereExists(function ($w) use ($sinceSql) {
                        $w->select(DB::raw(1))->from('children')
                            ->whereColumn('children.family_id', 'families.id')
                            ->whereNull('children.deleted_at')
                            ->where('children.updated_at', '>=', $sinceSql);
                    });
            });
        }
        $families = $famQ->orderBy('updated_at')->limit(500)->get();
        $famIds = $families->pluck('id')->all();

        $kidsByFamily = $famIds
            ? DB::table('children')->whereIn('family_id', $famIds)->whereNull('deleted_at')
                ->get($childCols)->groupBy('family_id')
            : collect();

        $guardsByFamily = $famIds
            ? DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $famIds)->whereNull('u.deleted_at')
                ->get(['g.family_id', 'g.relationship', 'g.is_primary',
                       'u.first_name', 'u.last_name', 'u.email', 'u.phone'])
                ->groupBy('family_id')
            : collect();

        $famOut = $families->map(function ($f) use ($centres, $kidsByFamily, $guardsByFamily, $mapChild) {
            $centre = $centres->get($f->centre_id);

            return [
                'kt_id'              => (int) $f->id,
                'family_name'        => $f->family_name,
                'primary_email'      => $f->primary_email ?? null,
                'primary_phone'      => $f->primary_phone ?? null,
                'address_line1'      => $f->address_line1 ?? null,
                'city'               => $f->city ?? null,
                'province'           => $f->province ?? null,
                'postal_code'        => $f->postal_code ?? null,
                'centre_name'        => $centre->name ?? null,
                // The consumer maps its own provider from this, so a KiddieTrac centre
                // that itself has no external origin gives them nothing to match on —
                // said plainly here rather than silently omitted.
                'centre_external_id' => $centre->external_id ?? null,
                'created_at'         => $f->created_at,
                'updated_at'         => $f->updated_at,
                'guardians'          => ($guardsByFamily[$f->id] ?? collect())->map(fn ($g) => [
                    'first_name'   => $g->first_name,
                    'last_name'    => $g->last_name,
                    'email'        => $g->email,
                    'phone'        => $g->phone,
                    'relationship' => $g->relationship,
                    'is_primary'   => (bool) $g->is_primary,
                ])->values(),
                'children'           => ($kidsByFamily[$f->id] ?? collect())->map($mapChild)->values(),
            ];
        })->values();

        // ── 2. Children created here inside a family that came from elsewhere ─
        // A new sibling added in KiddieTrac to a family the consumer already owns. It
        // must attach to the existing parent, not create a second one — hence the
        // family's external id rather than its KiddieTrac id.
        $extFamilies = DB::table('families')->whereIn('centre_id', $centreIds)
            ->whereNotNull('external_id')->whereNull('deleted_at')
            ->get(['id', 'external_id', 'external_source'])->keyBy('id');

        $orphanOut = collect();
        if ($extFamilies->isNotEmpty()) {
            $kidQ = DB::table('children')
                ->whereIn('family_id', $extFamilies->keys()->all())
                ->whereNull('external_source')       // the CHILD was created here
                ->whereNull('deleted_at');
            if ($sinceSql) {
                $kidQ->where('updated_at', '>=', $sinceSql);
            }
            $orphanOut = $kidQ->orderBy('updated_at')->limit(500)->get($childCols)
                ->map(function ($c) use ($mapChild, $extFamilies) {
                    $row = $mapChild($c);
                    $fam = $extFamilies->get($c->family_id);
                    $row['family_external_id'] = $fam->external_id ?? null;
                    $row['family_external_source'] = $fam->external_source ?? null;

                    return $row;
                })->values();
        }

        /* ── 3. DE-ACTIVATIONS: things that have STOPPED here ──────────────────
           Until 2026-08-25 this feed only ever said what had been CREATED or CHANGED.
           A child withdrawn, a family de-enrolled or a provider closed simply stopped
           appearing, and "stopped appearing" is indistinguishable from "unchanged" to a
           poller — so iLearn kept them Active for ever. Departures have to be stated,
           not implied by absence.

           Only genuine endings are listed. A SUSPENDED family is deliberately excluded:
           a suspension is an explicitly reversible pause, and flipping the far end to
           Inactive and back would churn a record that has not actually ended. */
        $deactivations = ['children' => [], 'families' => [], 'centres' => []];

        $kidQ2 = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where(function ($q) {
                $q->where('ch.enrollment_status', 'withdrawn')->orWhereNotNull('ch.deleted_at');
            });
        if ($sinceSql) {
            $kidQ2->where('ch.updated_at', '>=', $sinceSql);
        }
        $deactivations['children'] = $kidQ2->orderBy('ch.updated_at')->limit(500)
            // Same omission as families had: a child pushed to us is known over there
            // as 'child-<their id>', never by our kt_id.
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.enrollment_status',
                   'ch.external_id', 'ch.external_source', 'ch.deleted_at', 'ch.updated_at'])
            ->map(fn ($c) => [
                'kt_id' => (int) $c->id,
                'name' => trim(($c->first_name ?? '').' '.($c->last_name ?? '')),
                'external_id' => $c->external_id,
                'external_source' => $c->external_source,
                'reason' => $c->deleted_at ? 'deleted' : 'withdrawn',
                'at' => $c->updated_at,
            ])->values();

        $famQ2 = DB::table('families')->whereIn('centre_id', $centreIds)->whereNotNull('deleted_at');
        if ($sinceSql) {
            $famQ2->where('updated_at', '>=', $sinceSql);
        }
        /* external_id / external_source, the same as centres already carry.

           Without them this list was only usable for families that ORIGINATED here —
           the consumer matches those by our kt_id. A family the source system pushed
           to us has no id of ours on its side; it knows the family as its own record
           ('parent-<their id>'), which is exactly the external_id sitting in this row.
           Omitting it meant the commonest case — iLearn de-enrolling a family iLearn
           itself created — arrived as an announcement nobody could act on. */
        $deactivations['families'] = $famQ2->orderBy('updated_at')->limit(500)
            ->get(['id', 'family_name', 'external_id', 'external_source', 'updated_at'])
            ->map(fn ($f) => [
                'kt_id' => (int) $f->id,
                'name' => $f->family_name,
                'external_id' => $f->external_id,
                'external_source' => $f->external_source,
                'reason' => 'de_enrolled',
                'at' => $f->updated_at,
            ])->values();

        $cenQ = DB::table('centres')->where('agency_id', $agencyId)->whereNotNull('deleted_at');
        if ($sinceSql) {
            $cenQ->where('updated_at', '>=', $sinceSql);
        }
        $deactivations['centres'] = $cenQ->orderBy('updated_at')->limit(200)
            ->get(['id', 'name', 'external_id', 'external_source', 'updated_at'])
            ->map(fn ($c) => [
                'kt_id' => (int) $c->id,
                'name' => $c->name,
                // How the consumer knows this provider, when it created it.
                'external_id' => $c->external_id,
                'external_source' => $c->external_source,
                'reason' => 'archived',
                'at' => $c->updated_at,
            ])->values();

        return response()->json([
            'ok'       => true,
            'since'    => $sinceSql,
            'families' => $famOut,
            'children' => $orphanOut,
            'deactivations' => $deactivations,
        ]);
    }

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
