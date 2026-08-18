<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\EmailTemplate;
use App\Support\Payroll;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The payroll documents that have been issued — payslips and payroll invoices — for
 * admins, and the same rows filtered to one person for staff.
 *
 * Every query here is scoped to the resolved active agency and nothing accepts an
 * agency from the caller. A staff member is additionally pinned to their own user_id,
 * so the self-view cannot be widened by a query parameter.
 */
class PayrollDocumentController extends Controller
{
    /** The active agency, resolved the same way as everywhere else. */
    private function agency(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        $uid = (int) $request->user()->id;

        // The header is a request, not a fact: it only counts if the caller actually
        // holds a role in that agency. Platform admins carry NULL agency_id rows, so
        // they are allowed through on the header alone.
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->get(['role', 'agency_id']);
        $isPlatform = $roles->contains(function ($r) { return $r->role === 'platform_admin'; });
        $owned = $roles->pluck('agency_id')->filter()->map(function ($v) { return (int) $v; })->all();

        if ($header && ($isPlatform || in_array($header, $owned, true))) {
            return $header;
        }
        if ($owned) {
            return (int) $owned[0];
        }
        abort(403, 'No agency access.');
    }

    /** Admin ledger, split into the two payrolls. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);

        $q = DB::table('payroll_documents as pd')
            ->leftJoin('centres as c', 'c.id', '=', 'pd.centre_id')
            ->where('pd.agency_id', $agencyId);

        if ($from = $request->query('from')) {
            $q->whereDate('pd.period_start', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $q->whereDate('pd.period_start', '<=', $to);
        }
        if (($g = $request->query('group')) && in_array($g, ['educators', 'other'], true)) {
            $q->where('pd.staff_group', $g);
        }
        if (($k = $request->query('kind')) && in_array($k, ['payslip', 'invoice'], true)) {
            $q->where('pd.kind', $k);
        }
        if (($s = $request->query('status')) && in_array($s, ['issued', 'paid', 'void'], true)) {
            $q->where('pd.status', $s);
        }
        if ($term = trim((string) $request->query('q', ''))) {
            $q->where(function ($w) use ($term) {
                $w->where('pd.payee_name', 'like', '%' . $term . '%')
                  ->orWhere('pd.reference', 'like', '%' . $term . '%');
            });
        }

        $rows = $q->orderByDesc('pd.period_start')->orderBy('pd.payee_name')
            ->limit(1000)
            ->get([
                'pd.id', 'pd.user_id', 'pd.staff_group', 'pd.role_label', 'pd.payee_name',
                'pd.kind', 'pd.reference', 'pd.period_start', 'pd.period_end',
                'pd.units', 'pd.unit_label', 'pd.rate', 'pd.gross', 'pd.currency',
                'pd.status', 'pd.source', 'pd.issued_at', 'pd.paid_at', 'c.name as centre_name',
            ]);

        $totals = [];
        foreach (['educators', 'other'] as $g) {
            $sub = $rows->where('staff_group', $g);
            $totals[$g] = [
                'count' => $sub->count(),
                'gross' => round((float) $sub->sum('gross'), 2),
                'people' => $sub->pluck('user_id')->unique()->count(),
                'unpaid' => round((float) $sub->where('status', '!=', 'paid')->where('status', '!=', 'void')->sum('gross'), 2),
            ];
        }

        return response()->json([
            'data' => $rows->values(),
            'totals' => $totals,
            'grand_gross' => round((float) $rows->sum('gross'), 2),
        ]);
    }

    /** The signed-in person's own payroll documents. Pinned to their user id. */
    public function mine(Request $request): JsonResponse
    {
        $uid = (int) $request->user()->id;
        $rows = DB::table('payroll_documents')
            ->where('user_id', $uid)
            ->orderByDesc('period_start')
            ->limit(200)
            ->get([
                'id', 'kind', 'reference', 'period_start', 'period_end', 'units', 'unit_label',
                'rate', 'gross', 'currency', 'status', 'source', 'issued_at', 'paid_at', 'role_label',
            ]);

        return response()->json([
            'data' => $rows,
            'total_gross' => round((float) $rows->sum('gross'), 2),
            'ytd_gross' => round((float) $rows->filter(function ($r) {
                return $r->period_start && substr((string) $r->period_start, 0, 4) === date('Y');
            })->sum('gross'), 2),
        ]);
    }

    public function setStatus(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $status = (string) $request->input('status');
        abort_unless(in_array($status, ['issued', 'paid', 'void'], true), 422, 'Unknown status.');

        $doc = DB::table('payroll_documents')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($doc, 404, 'Not found.');

        DB::table('payroll_documents')->where('id', $id)->update([
            'status' => $status,
            'paid_at' => $status === 'paid' ? ($doc->paid_at ?: now()) : null,
            'updated_at' => now(),
        ]);

        return response()->json(['ok' => true, 'status' => $status]);
    }

    /** Re-run the backfill for this agency. Idempotent, so it is safe to press twice. */
    public function backfill(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $weeks = max(1, min(260, (int) $request->input('weeks', 104)));
        $work = Payroll::backfillFromWork($agencyId, $weeks);
        $inv = Payroll::syncPayeeInvoices($agencyId);

        return response()->json(['ok' => true, 'payslips' => $work, 'invoices' => $inv]);
    }

    /**
     * The document itself. An admin may open anybody's in their agency; everybody else
     * may open only their own.
     */
    public function pdf(Request $request, int $id)
    {
        $uid = (int) $request->user()->id;
        $doc = DB::table('payroll_documents')->where('id', $id)->first();
        abort_unless($doc, 404, 'Not found.');

        if ((int) $doc->user_id !== $uid) {
            $agencyId = $this->agency($request);
            abort_unless((int) $doc->agency_id === $agencyId, 404, 'Not found.');
            $isAdmin = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
                ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])->exists();
            abort_unless($isAdmin, 403, 'Not permitted.');
        }

        $agency = DB::table('agencies')->where('id', $doc->agency_id)->first(['name', 'logo_url', 'settings']);
        $settings = $agency && $agency->settings ? (json_decode($agency->settings, true) ?: []) : [];
        $note = trim((string) ($settings['payslip_note'] ?? ''));

        $fmt = function ($v) { return '$' . number_format((float) $v, 2); };
        $period = $doc->period_start
            ? Carbon::parse($doc->period_start)->format('j M Y') . ' – ' . Carbon::parse($doc->period_end ?: $doc->period_start)->format('j M Y')
            : '—';

        $html = '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:12px;}'
            . 'h1{font-size:20px;margin:0 0 2px;color:#1F6080;}'
            . '.muted{color:#64748B;font-size:11px;}'
            . 'table{width:100%;border-collapse:collapse;margin-top:16px;}'
            . 'th{text-align:left;font-size:10px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #CBD5E1;padding:6px 4px;}'
            . 'td{padding:8px 4px;border-bottom:1px solid #F1F5F9;}'
            . '.r{text-align:right;} .tot{font-weight:bold;font-size:15px;color:#1F6080;}'
            . '</style></head><body>'
            . '<h1>' . e($agency->name ?? 'Payroll') . '</h1>'
            . '<div class="muted">' . ($doc->kind === 'invoice' ? 'Payroll invoice' : 'Payslip')
            . ' · ' . e((string) $doc->reference) . '</div>'
            . '<table><tr><td style="border:0;padding-top:14px;"><strong>' . e((string) $doc->payee_name) . '</strong>'
            . '<div class="muted">' . e((string) $doc->role_label) . '</div></td>'
            . '<td style="border:0;padding-top:14px;" class="r"><div class="muted">Pay period</div>' . e($period) . '</td></tr></table>'
            . '<table><thead><tr><th>Description</th><th class="r">' . e(ucfirst((string) $doc->unit_label)) . '</th>'
            . '<th class="r">Rate</th><th class="r">Amount</th></tr></thead><tbody>'
            . '<tr><td>' . ($doc->unit_label === 'visits' ? 'Home visits completed' : ($doc->unit_label === 'hours' ? 'Hours worked' : 'Agreed amount'))
            . '</td><td class="r">' . e((string) (float) $doc->units) . '</td><td class="r">' . $fmt($doc->rate)
            . '</td><td class="r">' . $fmt($doc->gross) . '</td></tr>'
            . '<tr><td colspan="3" class="r tot">Gross pay</td><td class="r tot">' . $fmt($doc->gross) . '</td></tr>'
            . '</tbody></table>'
            . '<p class="muted" style="margin-top:18px;">Gross pay only — deductions are not calculated here.'
            . ($doc->status === 'paid' && $doc->paid_at ? ' Marked paid ' . Carbon::parse($doc->paid_at)->format('j M Y') . '.' : '')
            . '</p>'
            . ($note !== '' ? '<p class="muted">' . e($note) . '</p>' : '')
            . '</body></html>';

        $pdf = new Dompdf();
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();

        $name = ($doc->kind === 'invoice' ? 'payroll-invoice-' : 'payslip-') . ($doc->period_start ?: $doc->id) . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $name . '"',
        ]);
    }
}
