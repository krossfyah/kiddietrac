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
                'pd.units', 'pd.unit_label', 'pd.rate', 'pd.gross', 'pd.net', 'pd.currency',
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
                'rate', 'gross', 'net', 'currency', 'status', 'source', 'issued_at', 'paid_at', 'role_label',
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
     * The values a template can refer to, named as the payslip kind advertises them.
     *
     * Formatted here rather than in the template, because the renderer deliberately does no
     * arithmetic and no formatting — that is what keeps it unable to execute anything.
     */
    private static function templateData(object $doc, ?object $agency, array $settings): array
    {
        $money = fn ($v) => number_format((float) $v, 2);
        $day = function ($d) {
            // Date-only: formatted from its parts, never through a timezone conversion.
            $p = explode('-', substr((string) $d, 0, 10));

            return count($p) === 3
                ? \Illuminate\Support\Carbon::createFromDate((int) $p[0], (int) $p[1], (int) $p[2])->format('M j, Y')
                : (string) $d;
        };

        $hours = (float) ($doc->units ?? 0);
        $rate = (float) ($doc->rate ?? 0);
        $gross = (float) ($doc->gross ?? 0);
        $vacation = (float) ($doc->vacation_pay ?? 0);

        return [
            'agency_name' => $agency->name ?? '',
            'agency_logo' => $agency->logo_url ?? '',
            'doc_title' => $doc->kind === 'invoice' ? 'Payroll invoice' : 'Payslip',
            'doc_number' => $doc->reference ?? '',
            'payee_name' => $doc->payee_name ?? '',
            'payee_role' => $doc->role_label ?? '',
            'recipient_type' => $doc->role_label ?? '',
            'status' => ucfirst((string) ($doc->status ?? '')),
            'period' => $doc->period_start
                ? $day($doc->period_start) . ' – ' . $day($doc->period_end ?: $doc->period_start)
                : '',
            'period_start' => $doc->period_start ? $day($doc->period_start) : '',
            'period_end' => $doc->period_end ? $day($doc->period_end) : '',
            'hours' => $money($hours),
            'rate' => $money($rate),
            'regular_amount' => $money($hours > 0 ? $hours * $rate : $gross),
            // Blank rather than zero when nothing was recorded, so a template using
            // {{#if ot_hours}} hides the row instead of printing "0.00 hours at x0".
            'ot_hours' => $doc->ot_hours ? $money($doc->ot_hours) : '',
            'ot_mult' => $doc->ot_mult ? rtrim(rtrim(number_format((float) $doc->ot_mult, 2), '0'), '.') : '',
            'ot_amount' => $doc->ot_amount ? $money($doc->ot_amount) : '',
            'vacation' => $vacation ? $money($vacation) : '',
            'gross' => $money($gross),
            'gross_with_vacation' => $money($gross + $vacation),
            'cpp' => $doc->cpp !== null ? $money($doc->cpp) : '',
            'ei' => $doc->ei !== null ? $money($doc->ei) : '',
            'income_tax' => $doc->income_tax !== null ? $money($doc->income_tax) : '',
            'other_deductions' => $doc->other_deductions !== null ? $money($doc->other_deductions) : '',
            'net' => $doc->net !== null ? $money($doc->net) : $money($gross),
            'notes' => $doc->notes ?? ($settings['payslip_note'] ?? ''),
            'generated_at' => now()->format('M j, Y g:i A'),
        ];
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

        // An agency template wins, when one is active. The built-in layout below stays as
        // the fallback — an agency that has never opened the template screen must still get
        // a working payslip, and a template that throws must not take the document with it.
        $tpl = \App\Support\DocumentTemplate::active(
            (int) $doc->agency_id,
            $doc->kind === 'invoice' ? 'invoice' : 'payslip'
        );
        if ($tpl) {
            try {
                $rendered = \App\Support\DocumentTemplate::render(
                    (string) $tpl->body,
                    self::templateData($doc, $agency, $settings)
                );
                $html = '<html><head><meta charset="utf-8"><style>'
                    . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:12px;}'
                    . 'h1{font-size:20px;margin:0 0 2px;color:#1F6080;}'
                    . '.muted{color:#64748B;font-size:11px;} .meta div{margin:2px 0;} .k{color:#64748B;}'
                    . 'table{width:100%;border-collapse:collapse;margin-top:14px;}'
                    . 'th{text-align:left;font-size:10px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #CBD5E1;padding:6px 4px;}'
                    . 'td{padding:7px 4px;border-bottom:1px solid #F1F5F9;} .num,.r{text-align:right;}'
                    . '.totals .row{display:flex;justify-content:space-between;padding:3px 0;}'
                    . '.totals .grand{font-weight:bold;font-size:15px;color:#1F6080;border-top:1px solid #CBD5E1;margin-top:4px;padding-top:6px;}'
                    . ((string) ($tpl->styles ?? ''))
                    . '</style></head><body>'
                    . '<h1>' . e($agency->name ?? 'Payroll') . '</h1>'
                    . $rendered . '</body></html>';

                $pdf = new Dompdf();
                $pdf->loadHtml($html);
                $pdf->setPaper('letter');
                $pdf->render();
                $name = ($doc->kind === 'invoice' ? 'payroll-invoice-' : 'payslip-')
                    . ($doc->period_start ?: $doc->id) . '.pdf';

                return response($pdf->output(), 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="' . $name . '"',
                ]);
            } catch (\Throwable $e) {
                // Fall through to the built-in layout rather than handing back an error
                // page: somebody wanting their payslip should still get one.
                \Illuminate\Support\Facades\Log::warning('Document template render failed', [
                    'template' => $tpl->id, 'document' => $doc->id, 'error' => $e->getMessage(),
                ]);
            }
        }
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
            . '<tr><td colspan="3" class="r"><strong>Gross pay</strong></td><td class="r"><strong>' . $fmt($doc->gross) . '</strong></td></tr>'
            . (function () use ($doc, $fmt) {
                // Only where a payroll was actually run. Reconstructed payslips carry no
                // deduction data, and a column of zeros would imply one that never happened.
                $lines = [
                    'CPP' => $doc->cpp, 'EI' => $doc->ei, 'Income tax' => $doc->income_tax,
                    'Other deductions' => $doc->other_deductions,
                ];
                $any = false;
                foreach ($lines as $v) {
                    if ($v !== null && (float) $v != 0.0) { $any = true; break; }
                }
                if (! $any) { return ''; }
                $html = '';
                foreach ($lines as $label => $v) {
                    if ($v === null || (float) $v == 0.0) { continue; }
                    $html .= '<tr><td colspan="3" class="r">' . e($label) . '</td><td class="r">-' . $fmt($v) . '</td></tr>';
                }
                if ($doc->vacation_pay !== null && (float) $doc->vacation_pay != 0.0) {
                    $html .= '<tr><td colspan="3" class="r">Vacation pay</td><td class="r">' . $fmt($doc->vacation_pay) . '</td></tr>';
                }
                if ($doc->net !== null) {
                    $html .= '<tr><td colspan="3" class="r tot">Net pay</td><td class="r tot">' . $fmt($doc->net) . '</td></tr>';
                }
                return $html;
            })()
            . '</tbody></table>'
            . '<p class="muted" style="margin-top:18px;">'
            . ($doc->net !== null && (float) $doc->net != (float) $doc->gross
                ? 'Deductions as recorded by payroll.'
                : 'Gross pay only — deductions are not calculated here.')
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
