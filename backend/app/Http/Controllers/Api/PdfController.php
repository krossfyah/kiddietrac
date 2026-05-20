<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * v22p51 — PDF generation (T4A annual receipts + child portfolio export).
 * Uses dompdf so no external service or fonts are required.
 */
final class PdfController extends Controller
{
    /**
     * GET /api/v1/families/{family}/t4a/{year}
     * Returns a downloadable PDF tax receipt for the calendar year.
     */
    public function t4a(Request $request, int $familyId, int $year): Response
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        abort_unless($family, 404);
        $this->authorizeAgency($request, (int) $family->agency_id);

        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        $agency = DB::table('agencies')->where('id', $family->agency_id)->first();
        $payments = DB::table('payments')
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->where('invoices.family_id', $familyId)
            ->whereBetween('payments.paid_at', [$start, $end])
            // no deleted_at on payments
            ->select('payments.paid_at', 'payments.amount', 'invoices.invoice_number')
            ->orderBy('payments.paid_at')
            ->get();

        $total = (float) $payments->sum('amount');
        $children = DB::table('children')
            ->where('family_id', $familyId)
            ->whereNull('deleted_at')
            ->pluck('first_name')
            ->all();

        $html = view('pdf.t4a', [
            'family' => $family,
            'agency' => $agency,
            'year' => $year,
            'payments' => $payments,
            'total' => $total,
            'children' => $children,
        ])->render();

        return $this->renderPdf($html, sprintf('T4A-%s-%d.pdf', $this->slug((string) $family->family_name), $year));
    }

    /**
     * GET /api/v1/care/portfolio/{child}/pdf
     * Renders the child portfolio (observations + milestones + care logs) to PDF.
     */
    public function portfolio(Request $request, int $childId): Response
    {
        $child = DB::table('children')->where('id', $childId)->whereNull('deleted_at')->first();
        abort_unless($child, 404);
        $family = DB::table('families')->where('id', $child->family_id)->first();
        $this->authorizeAgency($request, (int) $family->agency_id);

        $agency = DB::table('agencies')->where('id', $family->agency_id)->first();
        $observations = DB::table('observations as o')
            ->leftJoin('users as u', 'u.id', '=', 'o.recorded_by_id')
            ->where('o.child_id', $childId)
            ->orderByDesc('o.observed_at')
            ->select('o.observed_at', 'o.framework', 'o.domain', 'o.body as notes', DB::raw("CONCAT(u.first_name,' ',u.last_name) as recorder"))
            ->limit(120)
            ->get();
        $milestones = DB::table('milestone_records')
            ->where('child_id', $childId)
            ->orderBy('observed_at')
            ->get();
        $logs = DB::table('daily_care_logs')
            ->where('child_id', $childId)
            ->orderByDesc('occurred_at')
            ->limit(60)
            ->get();

        $html = view('pdf.portfolio', [
            'child' => $child,
            'family' => $family,
            'agency' => $agency,
            'observations' => $observations,
            'milestones' => $milestones,
            'logs' => $logs,
        ])->render();

        return $this->renderPdf($html, sprintf('Portfolio-%s.pdf', $this->slug((string) $child->first_name . '-' . $child->last_name)));
    }

    private function renderPdf(string $html, string $filename): Response
    {
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($opts);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        $body = $dompdf->output();
        return new Response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, must-revalidate',
        ]);
    }

    private function slug(string $s): string
    {
        $s = preg_replace('/[^A-Za-z0-9 ]/', '', $s) ?? '';
        return strtolower(str_replace(' ', '-', trim($s))) ?: 'doc';
    }

    private function authorizeAgency(Request $request, int $agencyId): void
    {
        $user = $request->user();
        abort_unless($user, 401);

        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        if ($isPlatformAdmin) return;

        $hasRole = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('agency_id', $agencyId)
            ->where('active', true)
            ->exists();
        abort_unless($hasRole, 403, 'Outside your agency');
    }
}
