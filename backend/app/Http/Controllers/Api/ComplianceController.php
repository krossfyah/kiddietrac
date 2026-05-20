<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dompdf\Dompdf;
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
            ->where('f.cwelcc_enrolled', 1)
            ->whereNull('f.deleted_at')
            ->select('f.id', 'f.family_name', 'f.cwelcc_enrolled_at', 'f.cwelcc_subsidy_rate', 'c.name as centre_name', 'f.centre_id')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function setCwelccEnrolment(Request $request, int $familyId): JsonResponse
    {
        $data = $request->validate([
            'enrolled' => 'required|boolean',
            'enrolled_at' => 'nullable|date',
            'subsidy_rate' => 'nullable|numeric|min:0|max:100',
        ]);
        DB::table('families')->where('id', $familyId)->update([
            'cwelcc_enrolled' => $data['enrolled'] ? 1 : 0,
            'cwelcc_enrolled_at' => $data['enrolled_at'] ?? null,
            'cwelcc_subsidy_rate' => $data['subsidy_rate'] ?? null,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'updated']);
    }

    public function cwelccMonthlyReport(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = (string) $request->query('month', Carbon::now()->format('Y-m'));
        // Compute on the fly from payments + invoices for enrolled families
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $rows = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where('f.cwelcc_enrolled', 1)
            ->whereNull('ch.deleted_at')
            ->whereNull('f.deleted_at')
            ->select(
                'ch.id as child_id',
                DB::raw("CONCAT(ch.first_name,' ',ch.last_name) as child_name"),
                'f.id as family_id', 'f.family_name', 'f.cwelcc_subsidy_rate',
                'c.id as centre_id', 'c.name as centre_name'
            )
            ->get();

        $report = $rows->map(function ($r) use ($start, $end, $month) {
            $invoiced = (float) DB::table('invoices')
                ->where('family_id', $r->family_id)
                ->whereBetween('issued_at', [$start, $end])
                ->sum('total');
            $rate = (float) ($r->cwelcc_subsidy_rate ?? 50);
            $subsidy = round($invoiced * ($rate / 100), 2);
            $parent = round($invoiced - $subsidy, 2);
            return [
                'child_id' => $r->child_id,
                'child_name' => $r->child_name,
                'family_id' => $r->family_id,
                'family_name' => $r->family_name,
                'centre_id' => $r->centre_id,
                'centre_name' => $r->centre_name,
                'gross_fee' => $invoiced,
                'subsidy_rate' => $rate,
                'subsidy_amount' => $subsidy,
                'parent_portion' => $parent,
                'month' => $month,
            ];
        });

        $totals = [
            'gross' => $report->sum('gross_fee'),
            'subsidy' => $report->sum('subsidy_amount'),
            'parent' => $report->sum('parent_portion'),
            'child_count' => $report->count(),
        ];
        return response()->json(['data' => $report, 'totals' => $totals, 'month' => $month]);
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
        return response()->streamDownload(function () use ($body) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Child', 'Family', 'Centre', 'Gross fee', 'Subsidy rate %', 'Subsidy $', 'Parent portion $']);
            foreach ($body['data'] as $r) {
                fputcsv($h, [$r['child_name'], $r['family_name'], $r['centre_name'], $r['gross_fee'], $r['subsidy_rate'], $r['subsidy_amount'], $r['parent_portion']]);
            }
            fputcsv($h, ['TOTAL', '', '', $body['totals']['gross'], '', $body['totals']['subsidy'], $body['totals']['parent']]);
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
    public function expiryCalendar(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $days = (int) $request->query('within_days', 180);
        $cutoff = Carbon::now()->addDays($days);
        // pull from certifications + background_checks unified
        $certs = DB::table('certifications as ct')
            ->join('users as u', 'u.id', '=', 'ct.user_id')
            ->leftJoin('role_assignments as ra', function ($j) use ($agencyId) {
                $j->on('ra.user_id', '=', 'ct.user_id')->where('ra.agency_id', $agencyId)->where('ra.active', 1);
            })
            ->whereNotNull('ra.id')
            ->where('ct.expires_at', '<=', $cutoff)
            ->select('ct.id', DB::raw("'certification' as source"), 'ct.cert_type as type', 'ct.expires_at',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"), 'u.email as user_email')
            ->get();
        $bgcs = DB::table('background_checks as bc')
            ->join('users as u', 'u.id', '=', 'bc.user_id')
            ->where('bc.agency_id', $agencyId)
            ->where('bc.expires_at', '<=', $cutoff)
            ->select('bc.id', DB::raw("'background_check' as source"), 'bc.check_type as type', 'bc.expires_at',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"), 'u.email as user_email')
            ->get();
        $all = $certs->merge($bgcs)->sortBy('expires_at')->values();
        $all->transform(function ($r) {
            $exp = Carbon::parse($r->expires_at);
            $r->days_until = (int) $exp->diffInDays(Carbon::now(), false) * ($exp->isPast() ? -1 : 1);
            $r->bucket = $exp->isPast() ? 'expired' : ($r->days_until <= 30 ? 'soon' : ($r->days_until <= 90 ? 'upcoming' : 'future'));
            return $r;
        });
        return response()->json(['data' => $all, 'within_days' => $days]);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
