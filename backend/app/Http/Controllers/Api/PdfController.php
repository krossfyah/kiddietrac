<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
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
    use ResolvesCentreContext;

    /**
     * GET /api/v1/families/{family}/t4a/{year}
     * Returns a downloadable PDF tax receipt for the calendar year.
     */
    public function t4a(Request $request, int $familyId, int $year): Response
    {
        $family = DB::table('families')->where('id', $familyId)->whereNull('deleted_at')->first();
        abort_unless($family, 404);
        // SECURITY (v22p96): a T4A tax receipt is family-private — this family's
        // guardians/centre staff, or a platform_admin scoped to the agency they've
        // switched into (was a global platform bypass across all tenants).
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);

        $start = Carbon::create($year, 1, 1)->startOfDay();
        $end = Carbon::create($year, 12, 31)->endOfDay();

        // v22p98: families have no agency_id — resolve the agency via the centre.
        $agency = DB::table('agencies')->where('id', DB::table('centres')->where('id', $family->centre_id)->value('agency_id'))->first();
        /* SPLIT BILLING (2026-09-29): each payer claims what THEY paid, so a split family
           gets one receipt per payer. A parent always gets their own; staff may ask for a
           payer with ?guardian_id=, or the whole family without it. Failed and refunded
           payments were counted before -- a receipt must only show money that stayed paid. */
        $guardianId = (int) $request->query('guardian_id', 0);
        $asGuardian = \App\Support\BillingSplit::guardianFor((int) $request->user()->id, $familyId);
        $isStaff = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['platform_admin', 'agency_admin', 'centre_director', 'educator'])->exists();
        if ($asGuardian && ! $isStaff) { $guardianId = $asGuardian; }
        $payer = null;
        if ($guardianId && \App\Support\BillingSplit::isSplit($familyId)) {
            abort_unless(DB::table('guardians')->where('id', $guardianId)->where('family_id', $familyId)->exists(), 404);
            $payer = collect(\App\Support\BillingSplit::payers($familyId))->firstWhere('guardian_id', $guardianId)
                ?? ['name' => (string) DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->where('g.id', $guardianId)->value(DB::raw("TRIM(CONCAT(u.first_name,' ',u.last_name))"))];
            $payments = collect(\App\Support\BillingSplit::paidByGuardian($familyId, $guardianId, $start->toDateTimeString(), $end->toDateTimeString()))
                ->map(fn ($r) => (object) $r);
        } else {
            $payments = DB::table('payments')
                ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
                ->where('invoices.family_id', $familyId)
                ->whereBetween('payments.paid_at', [$start, $end])
                ->whereNotIn('payments.status', ['failed', 'refunded', 'void'])
                ->select('payments.paid_at', 'payments.amount', 'invoices.invoice_number')
                ->orderBy('payments.paid_at')
                ->get();
        }

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
            'payerName' => $payer['name'] ?? null,
        ])->render();

        return $this->renderPdf($html, sprintf('Childcare-Receipt-%s%s-%d.pdf', $this->slug((string) $family->family_name),
            $payer ? '-' . $this->slug((string) $payer['name']) : '', $year));
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
        // SECURITY (v22p96): a child's portfolio is child-private — guardians of
        // this child or its centre staff, or a platform_admin scoped to the agency
        // they've switched into (was a global platform bypass across all tenants).
        abort_unless($this->canAccessChildScoped($request, $childId), 403);

        // v22p98: families have no agency_id — resolve the agency via the centre.
        $agency = DB::table('agencies')->where('id', DB::table('centres')->where('id', $family->centre_id)->value('agency_id'))->first();
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
