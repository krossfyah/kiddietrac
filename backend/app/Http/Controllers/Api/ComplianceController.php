<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\KtDompdf as Dompdf;   // translating Dompdf (2026-10-22)
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p53 — Compliance & finance reports:
 *   - CWELCC monthly subsidy tracking + PDF
 *   - Cohort retention reports
 *   - Multi-cert renewal calendars (extends background_checks for
 *     anaphylaxis / first-aid / food-handler)
 */
final class ComplianceController extends Controller
{
    // =========================================================
    // CWELCC subsidy tracking
    // =========================================================
    public function cwelccFamilies(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            // The caller's centres, as the monthly report beside it (2026-11-05 leak sweep): a
            // director was listed every centre's CWELCC families.
            ->whereIn('c.id', \App\Support\Visibility::centreIds((int) $agencyId, $request->user()) ?: [0])
            ->join('cwelcc_enrolments as e', function ($j) { $j->on('e.family_id', '=', 'f.id')->whereNull('e.enrolled_to'); })
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.family_name', 'e.enrolled_from as cwelcc_enrolled_at', 'e.subsidy_rate as cwelcc_subsidy_rate', 'c.name as centre_name', 'f.centre_id')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function setCwelccEnrolment(Request $request, int $familyId): JsonResponse
    {
        $data = $request->validate([
            'enrolled' => 'required|boolean',
            'enrolled_at' => 'nullable|date',          // start day, or the last day when ending
            'subsidy_rate' => 'nullable|numeric|min:0|max:100',
        ]);
        // SECURITY: the family MUST belong to the caller's active agency. This took
        // $familyId straight from the URL with no ownership check (cross-tenant IDOR).
        $agencyId = $this->resolveAgencyId($request);
        $famAgency = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->value('c.agency_id');
        abort_unless($famAgency && (int) $famAgency === (int) $agencyId, 403, 'That family is not in your agency.');
        /* ...and at a centre you run (2026-11-05 leak sweep): any director of the agency could
           enrol or end another centre's family in CWELCC, which changes that centre's subsidy
           claim. An admin of this agency, or a director of the family's centre. */
        $famCentre = DB::table('families')->where('id', $familyId)->value('centre_id');
        abort_unless(array_intersect(\App\Support\AgencyRole::names($request), ['platform_admin', 'agency_admin', 'centre_director'])
            && \App\Support\CentreScope::canWrite(\App\Support\CentreScope::ids($request, (int) $agencyId), $famCentre),
            403, 'That family is not at a centre you manage.');

        /* Through CwelccHistory (2026-09-29): this overwrote the family's flag, date and
           rate in place, which erased the family from past reports on un-enrolment and
           re-priced past months on a rate change. */
        $day = ! empty($data['enrolled_at']) ? Carbon::parse($data['enrolled_at'])->toDateString()
            : \App\Support\AgencyTime::today($agencyId);
        $rate = isset($data['subsidy_rate']) ? (float) $data['subsidy_rate'] : null;
        $by = (int) $request->user()->id;
        $open = \App\Support\CwelccHistory::open($familyId);
        if ($data['enrolled']) {
            $err = $open
                ? \App\Support\CwelccHistory::changeRate($familyId, $day, $rate, $by)
                : \App\Support\CwelccHistory::enrol($familyId, $day, $rate, $by);
        } else {
            $err = \App\Support\CwelccHistory::end($familyId, $day, $by);
        }
        if ($err) {
            return response()->json(['message' => $err], 422);
        }

        return response()->json(['status' => 'updated']);
    }

    public function cwelccMonthlyReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        /* HISTORICAL (2026-09-29). This read families.cwelcc_enrolled as it is TODAY, for
           any month: a family that left CWELCC vanished from every past report, a family
           that joined in July appeared in March, and a rate change re-priced past months.
           It now reads the enrolment periods in force during the month, at their own
           rate, and keeps archived families and children.

           It was also per CHILD while summing the FAMILY's invoices on every child's row,
           so a family with two children was claimed twice. One row per family period; an
           invoice counts toward the period covering its issue date. */
        $periods = DB::table('cwelcc_enrolments as e')
            ->join('families as f', 'f.id', '=', 'e.family_id')
            ->join('centres as c', 'c.id', '=', DB::raw('COALESCE(e.centre_id, f.centre_id)'))
            ->where('c.agency_id', $agencyId)
            // The caller's centres (also feeds the PDF and CSV): a director's own (2026-10-05).
            ->whereIn('c.id', \App\Support\Visibility::centreIds((int) $agencyId, $request->user()) ?: [0])
            ->where('e.enrolled_from', '<=', $end->toDateString())
            ->where(function ($w) use ($start) {
                $w->whereNull('e.enrolled_to')->orWhere('e.enrolled_to', '>=', $start->toDateString());
            })
            ->orderBy('f.family_name')->orderBy('e.enrolled_from')
            ->get(['e.id as period_id', 'e.family_id', 'e.enrolled_from', 'e.enrolled_to', 'e.subsidy_rate',
                'f.family_name', 'f.deleted_at as family_archived', 'c.id as centre_id', 'c.name as centre_name']);

        /* ONTARIO IS NOT A PERCENTAGE ANY MORE (2026-11-13, OCCMS sweep). Since January 1, 2025 an Ontario CWELCC
           family pays the BASE FEE (App\Support\CwelccFees) and the centre is funded by its cost-based allocation, so
           "gross x rate%" claimed a reduction that no longer exists. For an Ontario agency the report is the split the
           Standardized Financial Report asks for: base fee revenue billed, the part the fee subsidy (CMSM) paid, and
           the part parents paid. The rate model stays for the other provinces. */
        $onBase = \App\Support\CwelccFees::applies((int) $agencyId);

        $report = $periods->map(function ($p) use ($start, $end, $month, $onBase) {
            $from = max($start->toDateString(), $p->enrolled_from);
            $to = min($end->toDateString(), $p->enrolled_to ?: $end->toDateString());
            $invQ = DB::table('invoices')
                ->where('family_id', $p->family_id)
                ->whereBetween('issued_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                // A voided invoice was never owed and a draft was never sent -- neither is
                // a fee the family was charged, so neither belongs in a subsidy claim.
                ->whereNotIn('status', ['void', 'draft']);
            $invoiced = (float) (clone $invQ)->sum('total');
            $feeSubsidy = $onBase ? (float) (clone $invQ)->sum('subsidy_amount') : 0.0;
            // Children of the family during the month, archived ones included.
            $kids = DB::table('children')->where('family_id', $p->family_id)
                ->where(function ($w) use ($start) { $w->whereNull('deleted_at')->orWhere('deleted_at', '>=', $start); })
                ->where(function ($w) use ($start) { $w->whereNull('withdrawn_at')->orWhere('withdrawn_at', '>=', $start); })
                ->orderBy('first_name')->get(['first_name', 'last_name']);
            $rate = $onBase ? null : (float) ($p->subsidy_rate ?? 50);
            $subsidy = $onBase ? round($feeSubsidy, 2) : round($invoiced * ($rate / 100), 2);
            $gross = $onBase ? round($invoiced + $feeSubsidy, 2) : $invoiced;

            return [
                'family_id' => $p->family_id,
                'family_name' => $p->family_name . ($p->family_archived ? ' (archived)' : ''),
                'child_name' => $kids->map(fn ($k) => trim($k->first_name . ' ' . $k->last_name))->implode(', '),
                'child_count' => $kids->count(),
                'centre_id' => $p->centre_id,
                'centre_name' => $p->centre_name,
                'period_from' => $p->enrolled_from,
                'period_to' => $p->enrolled_to,
                'gross_fee' => $gross,
                'subsidy_rate' => $rate,
                'subsidy_amount' => $subsidy,
                'parent_portion' => round($gross - $subsidy, 2),
                'month' => $month,
            ];
        });

        $totals = [
            'gross' => round($report->sum('gross_fee'), 2),
            'subsidy' => round($report->sum('subsidy_amount'), 2),
            'parent' => round($report->sum('parent_portion'), 2),
            'child_count' => $report->sum('child_count'),
            'family_count' => $report->pluck('family_id')->unique()->count(),
        ];
        return response()->json(['data' => $report, 'totals' => $totals, 'month' => $month, 'model' => $onBase ? 'on_base_fee' : 'rate']);
    }

    public function cwelccMonthlyPdf(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));
        $reportJson = $this->cwelccMonthlyReport($request);
        $body = json_decode($reportJson->getContent(), true);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $html = view('pdf.cwelcc', [
            'agency' => $agency,
            'month' => $month,
            'rows' => $body['data'],
            'totals' => $body['totals'],
            'model' => $body['model'] ?? 'rate',
        ])->render();
        $dompdf = new Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return new Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="CWELCC-' . $month . '.pdf"',
        ]);
    }

    public function cwelccMonthlyCsv(Request $request): StreamedResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));
        $reportJson = $this->cwelccMonthlyReport($request);
        $body = json_decode($reportJson->getContent(), true);
        $fref = \App\Support\RefCode::families(array_column($body['data'] ?? [], 'family_id'));   // 2026-10-14
        return response()->streamDownload(function () use ($body, $fref) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            if (($body['model'] ?? 'rate') === 'on_base_fee') {
                // Ontario since 2025: base fee revenue, split between the fee subsidy and parents (no rate).
                fputcsv($h, ['Children', 'Family', 'Family ID', 'Centre', 'Base fee revenue $', 'Fee subsidy paid $', 'Parents paid $']);
                foreach ($body['data'] as $r) {
                    fputcsv($h, [$r['child_name'], $r['family_name'], $fref[(int) ($r['family_id'] ?? 0)] ?? '', $r['centre_name'], $r['gross_fee'], $r['subsidy_amount'], $r['parent_portion']]);
                }
                fputcsv($h, ['TOTAL', '', '', '', $body['totals']['gross'], $body['totals']['subsidy'], $body['totals']['parent']]);
            } else {
                fputcsv($h, ['Children', 'Family', 'Family ID', 'Centre', 'Gross fee', 'Subsidy rate %', 'Subsidy $', 'Parent portion $']);
                foreach ($body['data'] as $r) {
                    fputcsv($h, [$r['child_name'], $r['family_name'], $fref[(int) ($r['family_id'] ?? 0)] ?? '', $r['centre_name'], $r['gross_fee'], $r['subsidy_rate'], $r['subsidy_amount'], $r['parent_portion']]);
                }
                fputcsv($h, ['TOTAL', '', '', '', $body['totals']['gross'], '', $body['totals']['subsidy'], $body['totals']['parent']]);
            }
            fclose($h);
        }, "CWELCC-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // =========================================================
    // Cohort retention
    // =========================================================
    public function retentionReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $cohorts = [];
        for ($i = 0; $i < 12; $i++) {
            $cohortStart = Carbon::now()->subMonths($i)->startOfMonth();
            $cohortEnd = $cohortStart->copy()->endOfMonth();
            $cohortKey = $cohortStart->format('Y-m');
            $enrolled = DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)
                ->whereNull('ch.deleted_at')
                ->whereBetween('ch.enrolled_at', [$cohortStart, $cohortEnd])
                ->count();
            $stillEnrolled = DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)
                ->whereNull('ch.deleted_at')
                ->whereBetween('ch.enrolled_at', [$cohortStart, $cohortEnd])
                ->whereNull('ch.withdrawn_at')
                ->count();
            $cohorts[] = [
                'cohort' => $cohortKey,
                'enrolled' => $enrolled,
                'still_enrolled' => $stillEnrolled,
                'retention_pct' => $enrolled > 0 ? round(($stillEnrolled / $enrolled) * 100, 1) : null,
                'months_since' => $i,
            ];
        }
        return response()->json(['data' => $cohorts]);
    }

    // =========================================================
    // Cert renewals (extends background_checks for cert types)
    // =========================================================
    /**
     * GET /compliance/expiry-calendar - the Renewals calendar (2026-11-27 rewrite).
     *
     * Background checks AND staff certificates (CPR, first aid...) that expire within ?within_days (default 180), or
     * already have. Only the newest record of each type per person counts: a renewed certificate retires the old one.
     *
     * Scope (P0, kiddietrac-data-leakage-rule): the active agency; a centre director only the staff placed at their
     * own centres (CentreScope); everyone else 403. Only staff still with us (Live::staffIds): a deactivated,
     * suspended or departed person, or one whose only centre closed, is not on a current renewals list.
     */
    public function expiryCalendar(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! array_intersect(\App\Support\AgencyRole::names($request), ['platform_admin', 'agency_admin', 'centre_director'])) {
            return response()->json(['message' => 'Only directors and agency admins can see the renewals calendar.'], 403);
        }
        $people = \App\Support\Live::staffIds($agencyId, \App\Support\CentreScope::ids($request, $agencyId));
        $days = max(1, min(730, (int) $request->query('within_days', 180)));
        $today = \App\Support\AgencyTime::todayDate($agencyId)->startOfDay();
        $cutoff = $today->copy()->addDays($days)->toDateString();
        if (! $people) {
            return response()->json(['data' => [], 'within_days' => $days]);
        }

        $rows = [];
        // Newest record per (person, type) only - an older one that a renewal replaced is not "due".
        $latest = function ($list, string $source) use (&$rows, $agencyId) {
            $best = [];
            foreach ($list as $r) {
                $k = $r->user_id . '|' . strtolower((string) $r->type);
                $exp = $r->expires_at ? substr((string) $r->expires_at, 0, 10) : '9999-12-31';
                if (! isset($best[$k]) || $exp > $best[$k][0] || ($exp === $best[$k][0] && $r->id > $best[$k][1]->id)) {
                    $best[$k] = [$exp, $r];
                }
            }
            foreach ($best as [$exp, $r]) {
                $r->source = $source;
                $r->type_label = \App\Support\Jurisdictions::label($agencyId, $source === 'certificate' ? 'certifications' : 'background_checks', (string) $r->type);
                $rows[] = $r;
            }
        };
        $latest(DB::table('background_checks as bc')->join('users as u', 'u.id', '=', 'bc.user_id')
            ->where('bc.agency_id', $agencyId)->whereIn('bc.user_id', $people)
            ->get(['bc.id', 'bc.user_id', 'bc.check_type as type', 'bc.expires_at',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name")]), 'background_check');
        $latest(DB::table('staff_certifications as sc')->join('users as u', 'u.id', '=', 'sc.user_id')
            ->where('sc.active', 1)->whereIn('sc.user_id', $people)
            ->where(fn ($w) => $w->whereNull('sc.agency_id')->orWhere('sc.agency_id', $agencyId))
            ->get(['sc.id', 'sc.user_id', 'sc.cert_type as type', 'sc.expires_at',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name")]), 'certificate');

        $out = [];
        foreach ($rows as $r) {
            if (! $r->expires_at || substr((string) $r->expires_at, 0, 10) > $cutoff) {
                continue;   // never expires, or not yet inside the window
            }
            $d = (int) $today->diffInDays(Carbon::parse(substr((string) $r->expires_at, 0, 10))->startOfDay(), false);
            $out[] = [
                'id' => (int) $r->id, 'source' => $r->source, 'type' => (string) $r->type, 'type_label' => $r->type_label,
                'user_id' => (int) $r->user_id, 'user_name' => trim((string) $r->user_name),
                'expires_at' => substr((string) $r->expires_at, 0, 10), 'days_until' => $d,
                'bucket' => $d < 0 ? 'expired' : ($d <= 30 ? 'soon' : ($d <= 90 ? 'upcoming' : 'future')),
            ];
        }
        usort($out, fn ($a, $b) => [$a['expires_at'], $a['user_name']] <=> [$b['expires_at'], $b['user_name']]);

        return response()->json(['data' => $out, 'within_days' => $days]);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($activeId) { $q->where('role', 'platform_admin')->orWhere('agency_id', $activeId); })->exists()) {
            return $activeId;
        }
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
