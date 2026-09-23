<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EVERYTHING A PROVIDER LEFT BEHIND (2026-09-21).
 *
 * Anthony: "the view archived provider should show all the provider information and
 * history on what children were in their care and dates etc (all history for this
 * provider including payroll etc)."
 *
 * The point of keeping an archived provider at all is that the record outlives the
 * placement. A parent asks who looked after their child in 2024; a regulator asks who was
 * working the week of an incident; an accountant asks what was paid. None of those are
 * answerable from a name and an archive date, which is all the View dialog had.
 *
 * WHY IT READS ROOMS, NOT JUST THE CENTRE. A child's link to a provider is the ROOM they
 * were enrolled in, not their family's registered centre - children are placed with
 * whichever provider has space, and four such placements exist today. Keying this on
 * families.centre_id would have quietly omitted exactly the children whose history is
 * least obvious. Same lesson as the attendance card earlier today.
 *
 * WORKS FOR LIVE PROVIDERS TOO. Nothing here depends on being archived; an admin asking
 * "what has happened at this provider" wants the same answer either way, and a screen
 * that only works on dead records is a screen nobody trusts.
 */
final class ProviderHistoryController extends Controller
{
    /** GET /admin/centres/{centre}/history */
    public function show(Request $request, int $centre): JsonResponse
    {
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        abort_unless($agencyId > 0, 422, 'No active agency.');

        /* The centre must belong to THIS agency. withTrashed by hand, because the whole
           point is reading an archived one. */
        $c = DB::table('centres')->where('id', $centre)->where('agency_id', $agencyId)->first();
        abort_unless($c, 404, 'No such provider.');

        $this->assertAdmin($request, $agencyId);

        $roomIds = DB::table('rooms')->where('centre_id', $centre)->pluck('id')->all();

        return response()->json([
            'provider' => $this->provider($c),
            'children' => $this->children($roomIds),
            'staff' => $this->staff($centre),
            'payroll' => $this->payroll($centre),
            'billing' => $this->billing($centre),
            'documents' => $this->documents($centre),
        ]);
    }

    private function provider(object $c): array
    {
        /* THE PICTURE OF A PROVIDER IS USUALLY A PERSON (2026-09-21).

           Anthony: "where is the display pic for the archived educators/providers?"

           Because there wasn't one. I added centres.logo_url, and NEITHER archived
           provider has a logo - only 3 of 13 centres do, all demo records. But in a home
           childcare agency the provider IS somebody: "Priscilla Abankwa" and "Chearstine
           Fitzpatrick" are the centre names AND the educators, and Chearstine's user
           account has a photograph. Showing a blank tile next to her name while her photo
           sat one join away is the gap.

           Matched on BOTH the centre email and an actual role at this centre, not on
           email alone. Email alone is how the family wizard once renamed the super admin;
           requiring a role as well means a shared or recycled address cannot put a
           stranger's face on a provider record. Falls back to a role-holder whose name
           matches the centre's, which is the other way these records are linked. */
        $owner = null;
        try {
            $email = mb_strtolower(trim((string) ($c->email ?? '')));
            $q = DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.centre_id', $c->id)
                ->whereNotNull('u.photo_url')->where('u.photo_url', '!=', '');

            if ($email !== '') {
                $owner = (clone $q)->whereRaw('LOWER(u.email) = ?', [$email])
                    ->first(['u.id', 'u.photo_url', 'u.first_name', 'u.last_name']);
            }
            if (! $owner) {
                $name = mb_strtolower(trim((string) $c->name));
                $owner = $q->whereRaw("LOWER(TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')))) = ?", [$name])
                    ->first(['u.id', 'u.photo_url', 'u.first_name', 'u.last_name']);
            }
        } catch (\Throwable $e) {
        }

        $address = implode(', ', array_filter([
            $c->address_line1 ?? null, $c->address_line2 ?? null,
            $c->city ?? null, $c->province ?? null, $c->postal_code ?? null,
        ]));

        return [
            'id' => (int) $c->id,
            'name' => $c->name,
            /* The provider's picture. Passed through UNCHANGED: SignProtectedMedia signs
               protected paths on the way out, and rewriting the host here is exactly what
               once sent signed media to the portal domain and 404'd every one. */
            'logo_url' => $c->logo_url ?: null,
            /* The picture to actually show, and WHOSE it is - so the screen can caption a
               person's face as a person rather than implying it is a premises logo. */
            'photo_url' => $c->logo_url ?: ($owner->photo_url ?? null),
            'photo_is_person' => ! $c->logo_url && ! empty($owner->photo_url),
            'photo_person' => (! $c->logo_url && ! empty($owner->photo_url))
                ? trim((string) (($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''))) : null,
            'brand_color' => $c->brand_color ?: null,
            'supervisor' => trim(($c->supervisor_first_name ?? '') . ' ' . ($c->supervisor_last_name ?? '')) ?: null,
            'status' => $c->deleted_at ? 'archived' : ($c->status ?? 'active'),
            'archived_at' => $c->deleted_at,
            'opened_at' => $c->created_at,
            'licence_number' => $c->license_number ?? null,
            'licence_capacity' => $c->license_capacity !== null ? (int) $c->license_capacity : null,
            'address' => $address ?: null,
            'phone' => $c->phone ?? null,
            'email' => $c->email ?? null,
            'hours' => ($c->open_time && $c->close_time) ? ($c->open_time . ' – ' . $c->close_time) : null,
            'cwelcc' => (bool) ($c->cwelcc_enrolled ?? false),
            'bio' => $c->provider_bio ?? null,
        ];
    }

    /**
     * Every child ever enrolled in one of this provider's rooms, with the dates.
     *
     * Enrolments, not check-events: the question is "who was in their care and when",
     * which is the placement, not the individual days. A child who moved rooms inside the
     * same provider appears once per placement, which is the truth rather than a tidier
     * lie.
     */
    private function children(array $roomIds): array
    {
        if (! $roomIds) {
            return [];
        }

        $rows = DB::table('enrollments as e')
            ->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->leftJoin('families as f', 'f.id', '=', 'ch.family_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->whereIn('e.room_id', $roomIds)
            ->orderByDesc('e.start_date')
            ->get([
                'ch.id', 'ch.first_name', 'ch.last_name', 'ch.enrollment_status', 'ch.deleted_at',
                'f.family_name', 'r.name as room_name',
                'e.start_date', 'e.end_date', 'e.monthly_fee', 'e.cwelcc_eligible',
            ]);

        $today = now()->toDateString();

        return $rows->map(function ($r) use ($today) {
            $left = $r->end_date && $r->end_date < $today;

            return [
                'child_id' => (int) $r->id,
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                'family' => $r->family_name,
                'room' => $r->room_name,
                'start_date' => $r->start_date,
                'end_date' => $r->end_date,
                /* Said plainly, so a reader does not have to compare two dates against
                   today in their head for every row. */
                'state' => $left ? 'left' : ($r->deleted_at ? 'removed' : 'in care'),
                'monthly_fee' => $r->monthly_fee !== null ? (float) $r->monthly_fee : null,
                'cwelcc' => (bool) $r->cwelcc_eligible,
            ];
        })->all();
    }

    /** Who worked here, and whether they still do. */
    private function staff(int $centre): array
    {
        return DB::table('role_assignments as ra')
            ->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.centre_id', $centre)
            ->orderByDesc('ra.created_at')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.photo_url', 'ra.role', 'ra.active',
                   'ra.created_at', 'ra.closed_with_account_at'])
            ->map(fn ($r) => [
                'user_id' => (int) $r->id,
                'name' => trim(($r->first_name ?? '') . ' ' . ($r->last_name ?? '')),
                'email' => $r->email,
                /* Unchanged on the way out: SignProtectedMedia signs /storage paths as the
                   JSON leaves, and 69 of 116 people have a photo, so a staff list without
                   faces was throwing away something the record already held. */
                'photo_url' => $r->photo_url ?: null,
                'role' => str_replace('_', ' ', (string) $r->role),
                'active' => (bool) $r->active,
                'from' => $r->created_at,
                'until' => $r->closed_with_account_at,
            ])->all();
    }

    /**
     * What was paid out, and the hours behind it.
     *
     * Two different records and both matter: payroll_documents is what was ISSUED (a
     * payslip, with gross and net), payee_invoices is what was BILLED by the provider.
     * Time punches are the evidence under them. A guard on each, because an agency that
     * has never run payroll should get an empty list, not a 500.
     */
    private function payroll(int $centre): array
    {
        /* EVERY IMPORTED PAYSLIP WAS INVISIBLE (2026-09-21).

           Anthony: "all history on payroll even the ones that came across from ilearn".

           He was right to ask. Measured: 156 payroll documents, and 97 of them carry NO
           centre_id at all - including ALL 94 that came across from iLearn, which are
           linked only by user_id. Filtering on centre_id alone returned none of them, so
           a provider's entire pre-migration pay history was missing from their record
           while the screen quietly said "Payroll issued - 0".

           So it matches either way: the centre, OR the people who held a role at this
           centre. For a home-childcare provider that second path is the important one -
           the provider IS a person, and their payslips are attached to their account.

           Each row says HOW it was matched. A staff member may have worked at more than
           one provider, so a payslip reached through them is attributed by person rather
           than by record, and the screen should not pretend those are the same claim. */
        $staffIds = DB::table('role_assignments')->where('centre_id', $centre)
            ->pluck('user_id')->unique()->values()->all();

        $docs = [];
        if (Schema::hasTable('payroll_documents')) {
            $docs = DB::table('payroll_documents')
                ->where(function ($w) use ($centre, $staffIds) {
                    $w->where('centre_id', $centre);
                    if ($staffIds) {
                        $w->orWhere(function ($q) use ($staffIds) {
                            $q->whereNull('centre_id')->whereIn('user_id', $staffIds);
                        });
                    }
                })
                ->orderByDesc('period_start')->limit(300)
                ->get(['id', 'payee_name', 'kind', 'reference', 'period_start', 'period_end',
                       'gross', 'net', 'status', 'issued_at', 'paid_at', 'centre_id',
                       'source', 'external_source', 'pdf_url'])
                ->map(function ($r) use ($centre) {
                    $a = (array) $r;
                    $a['matched_by'] = ((int) ($r->centre_id ?? 0) === $centre) ? 'centre' : 'person';
                    /* Where it came from, in words. "ilearn" on a payslip is the answer to
                       "is this everything, including before we moved?" */
                    $a['origin'] = $r->external_source ?: ($r->source ?: null);
                    unset($a['centre_id'], $a['source'], $a['external_source']);

                    return $a;
                })->all();
        }

        $invoices = [];
        if (Schema::hasTable('payee_invoices')) {
            $invoices = DB::table('payee_invoices')
                ->where(function ($w) use ($centre, $staffIds) {
                    $w->where('centre_id', $centre);
                    if ($staffIds) {
                        $w->orWhere(function ($q) use ($staffIds) {
                            $q->whereNull('centre_id')->whereIn('payee_user_id', $staffIds);
                        });
                    }
                })
                ->orderByDesc('period_start')->limit(300)
                ->get(['id', 'payee_name', 'kind', 'reference', 'period_start', 'period_end',
                       'hours', 'rate', 'amount', 'status', 'paid_at'])
                ->map(fn ($r) => (array) $r)->all();
        }

        /* Hours worked, per person, from the punches. Summed in SQL - a provider with
           years of history would otherwise pull thousands of rows to add up two numbers. */
        $hours = DB::table('time_punches as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->where('tp.centre_id', $centre)
            ->whereNotNull('tp.punched_out_at')
            ->groupBy('u.id', 'u.first_name', 'u.last_name')
            ->selectRaw("u.id, TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) name,
                         COUNT(*) shifts,
                         ROUND(SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) / 60, 1) hours,
                         MIN(tp.punched_in_at) first_shift, MAX(tp.punched_in_at) last_shift")
            ->orderByDesc('hours')->get()->map(fn ($r) => (array) $r)->all();

        return ['documents' => $docs, 'invoices' => $invoices, 'hours' => $hours];
    }

    /** What families at this provider were billed. */
    private function billing(int $centre): array
    {
        $rows = DB::table('invoices as i')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->where('i.centre_id', $centre)
            ->orderByDesc('i.issued_at')->limit(200)
            ->get(['i.id', 'i.invoice_number', 'f.family_name', 'i.period_start', 'i.period_end',
                   'i.total', 'i.amount_paid', 'i.balance_due', 'i.status', 'i.issued_at']);

        $totals = DB::table('invoices')->where('centre_id', $centre)
            ->selectRaw('COUNT(*) n, COALESCE(SUM(total),0) billed, COALESCE(SUM(amount_paid),0) paid')
            ->first();

        return [
            'invoices' => $rows->map(fn ($r) => (array) $r)->all(),
            'summary' => [
                'count' => (int) ($totals->n ?? 0),
                'billed' => (float) ($totals->billed ?? 0),
                'paid' => (float) ($totals->paid ?? 0),
            ],
        ];
    }

    /** Anything filed against this provider. */
    private function documents(int $centre): array
    {
        if (! Schema::hasTable('documents')) {
            return [];
        }

        return DB::table('documents')
            ->where('scope_type', 'centre')->where('scope_id', $centre)
            ->orderByDesc('created_at')->limit(100)
            ->get(['id', 'title', 'category', 'file_url', 'file_type', 'signed_at', 'expires_at', 'created_at'])
            ->map(fn ($r) => (array) $r)->all();
    }

    /**
     * Admins only. Named explicitly rather than inferred from the /admin/ path — that
     * prefix says where a thing is EDITED, not who may read it, and this payload carries
     * children's names, staff pay and family billing.
     */
    private function assertAdmin(Request $request, int $agencyId): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where('role', 'platform_admin')
                    ->orWhere(function ($a) use ($agencyId) {
                        $a->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', $agencyId);
                    });
            })->exists();

        abort_unless($ok, 403, 'Administrators only.');
    }
}
