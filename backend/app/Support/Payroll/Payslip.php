<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use Dompdf\Dompdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): an engine payslip (payroll_documents.source = engine), drawn ONLY from
 * what finalize froze in payroll_documents.breakdown + lines: earnings, every deduction, net, the year to date and the
 * employer's contributions. Nothing is recalculated. Used by the screen (view()), the PDF (pdf(), called from
 * PayrollDocumentController::pdf after its own access check) and My HR. Every other payslip keeps its own layout.
 */
final class Payslip
{
    public static function isEngine(?object $doc): bool
    {
        return $doc && ($doc->source ?? '') === 'engine' && ! empty($doc->breakdown);
    }

    /** The payslip as data for the screen. */
    public static function view(object $doc): array
    {
        $b = json_decode((string) $doc->breakdown, true) ?: [];
        $t = $b['this_pay'] ?? [];
        $y = $b['ytd'] ?? [];
        $qc = ($b['jurisdiction'] ?? '') === 'QC';
        $plan = $qc ? 'QPP' : 'CPP';
        $ded = [];
        $add = function ($label, $k, $neg = true) use (&$ded, $t, $y) {
            $v = (float) ($t[$k] ?? 0);
            $yy = isset($y[$k]) ? (float) $y[$k] : null;
            if (abs($v) < 0.005 && ($yy === null || abs($yy) < 0.005)) {
                return;
            }
            $ded[] = ['label' => $label, 'amount' => $v, 'ytd' => $yy];
        };
        $add($plan, 'cpp');
        $add($plan . '2 (second additional)', 'cpp2');
        $add('EI', 'ei');
        if ($qc) {
            $add('QPIP', 'qpip');
        }
        $add('Federal income tax', 'federal_tax');
        $add($qc ? 'Quebec income tax' : ((CaCalculator::NAMES[$b['jurisdiction'] ?? ''] ?? 'Provincial') . ' income tax'), 'provincial_tax');
        if ((float) ($t['other_deduction'] ?? 0) > 0) {
            $ded[] = ['label' => $b['other_label'] ?: 'Other deduction', 'amount' => (float) $t['other_deduction'], 'ytd' => null];
        }
        $er = [];
        foreach ([[$plan, 'employer_cpp'], [$plan . '2', 'employer_cpp2'], ['EI (1.4 × employee)', 'employer_ei'], ['QPIP', 'employer_qpip']] as [$lab, $k]) {
            $v = (float) ($t[$k] ?? 0);
            if (abs($v) >= 0.005 || abs((float) ($y[$k] ?? 0)) >= 0.005) {
                $er[] = ['label' => $lab, 'amount' => $v, 'ytd' => (float) ($y[$k] ?? 0)];
            }
        }
        $earn = json_decode((string) $doc->lines, true) ?: [];
        $agency = DB::table('agencies')->where('id', $doc->agency_id)->value('name');
        $centre = $doc->centre_id ? DB::table('centres')->where('id', $doc->centre_id)->value('name') : null;

        return ['id' => (int) $doc->id, 'reference' => $doc->reference, 'payee' => $doc->payee_name, 'role' => $doc->role_label, 'agency' => $agency, 'centre' => $centre,
            'period_start' => (string) $doc->period_start, 'period_end' => (string) $doc->period_end, 'pay_date' => $b['run']['pay_date'] ?? null,
            'run_reference' => $b['run']['reference'] ?? null, 'adjustment' => ($b['run']['kind'] ?? '') === 'adjustment',
            'frequency' => $doc->pay_frequency, 'jurisdiction' => $b['jurisdiction'] ?? null, 'province' => CaCalculator::NAMES[$b['jurisdiction'] ?? ''] ?? null,
            'earnings' => $earn, 'gross' => (float) ($t['gross'] ?? $doc->gross), 'deductions' => $ded,
            'total_deductions' => round(array_sum(array_column($ded, 'amount')), 2), 'reimbursements' => (float) ($t['reimbursements'] ?? 0),
            'benefits_noncash' => (float) ($t['benefits_noncash'] ?? 0), 'net' => (float) $doc->net,
            'ytd' => ['gross' => (float) ($y['gross'] ?? 0), 'net' => PayRuns::ytdNetOf($doc, $b), 'vacation_pay' => (float) ($y['vacation_pay'] ?? 0),
                'income_tax' => (float) ($y['income_tax'] ?? 0)],
            'ytd_sources' => $b['ytd_sources'] ?? null, 'employer' => $er, 'employer_total' => round(array_sum(array_column($er, 'amount')), 2),
            'table_version' => $b['table_version'] ?? null, 'status' => $doc->status];
    }

    public static function html(object $doc): string
    {
        $v = self::view($doc);
        $m = fn ($n) => '$' . number_format((float) $n, 2);
        $d = fn ($s) => $s ? Carbon::parse($s)->format('M j, Y') : '';
        $rows = '';
        foreach ($v['earnings'] as $l) {
            $det = isset($l['hours']) && $l['hours'] !== null && isset($l['rate']) ? rtrim(rtrim(number_format((float) $l['hours'], 2), '0'), '.') . ' h × ' . $m($l['rate'])
                : (isset($l['hours'], $l['multiplier']) ? rtrim(rtrim(number_format((float) $l['hours'], 2), '0'), '.') . ' h × ' . rtrim(rtrim(number_format((float) $l['multiplier'], 2), '0'), '.') : '');
            $rows .= '<tr><td>' . e($l['label']) . (! empty($l['not_taxable']) ? ' <span class="muted">(not taxable)</span>' : '') . (! empty($l['non_cash']) ? ' <span class="muted">(not paid in cash)</span>' : '')
                . '</td><td class="r muted">' . e($det) . '</td><td class="r">' . $m($l['amount']) . '</td></tr>';
        }
        $ded = '';
        foreach ($v['deductions'] as $x) {
            $ded .= '<tr><td>' . e($x['label']) . '</td><td class="r">' . $m($x['amount']) . '</td><td class="r muted">' . ($x['ytd'] !== null ? $m($x['ytd']) : '') . '</td></tr>';
        }
        $er = '';
        foreach ($v['employer'] as $x) {
            $er .= '<tr><td>' . e($x['label']) . '</td><td class="r">' . $m($x['amount']) . '</td><td class="r muted">' . $m($x['ytd']) . '</td></tr>';
        }

        return '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:11.5px;}h1{font-size:19px;margin:0 0 2px;color:#1F6080;}'
            . '.muted{color:#64748B;font-size:10.5px;}table{width:100%;border-collapse:collapse;margin-top:12px;}'
            . 'th{text-align:left;font-size:9.5px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #CBD5E1;padding:5px 4px;}'
            . 'td{padding:6px 4px;border-bottom:1px solid #F1F5F9;}.r{text-align:right;}.tot td{font-weight:bold;}.net td{font-weight:bold;font-size:14px;color:#1F6080;border-top:1px solid #CBD5E1;}'
            . '.box{border:1px solid #E2E8F0;border-radius:6px;padding:8px 10px;margin-top:10px;}'
            . '</style></head><body>'
            . '<h1>' . e((string) $v['agency']) . '</h1><div class="muted">Payslip · ' . e((string) $v['reference']) . ($v['adjustment'] ? ' · Adjustment' : '') . '</div>'
            . '<table><tr><td style="border:0;"><strong>' . e((string) $v['payee']) . '</strong><div class="muted">' . e((string) $v['role']) . ($v['centre'] ? ' · ' . e($v['centre']) : '')
            . ($v['province'] ? ' · Works in ' . e($v['province']) : '') . '</div></td>'
            . '<td style="border:0;" class="r"><div class="muted">Pay period</div>' . e($d($v['period_start']) . ' – ' . $d($v['period_end']))
            . '<div class="muted" style="margin-top:3px;">Pay date ' . e($d($v['pay_date'])) . ' · ' . e((string) $v['frequency']) . '</div></td></tr></table>'
            . '<table><thead><tr><th>Earnings</th><th class="r"></th><th class="r">This pay</th></tr></thead><tbody>' . $rows
            . '<tr class="tot"><td colspan="2" class="r">Gross pay</td><td class="r">' . $m($v['gross']) . '</td></tr></tbody></table>'
            . '<table><thead><tr><th>Deductions</th><th class="r">This pay</th><th class="r">Year to date</th></tr></thead><tbody>' . $ded
            . '<tr class="tot"><td>Total deductions</td><td class="r">' . $m($v['total_deductions']) . '</td><td></td></tr>'
            . ($v['benefits_noncash'] > 0 ? '<tr><td>Less non-cash benefit (taxed, not paid in cash)</td><td class="r">' . $m($v['benefits_noncash']) . '</td><td></td></tr>' : '')
            . ($v['reimbursements'] > 0 ? '<tr><td>Plus reimbursements (not taxable)</td><td class="r">' . $m($v['reimbursements']) . '</td><td></td></tr>' : '')
            . '<tr class="net"><td>Net pay</td><td class="r">' . $m($v['net']) . '</td><td class="r muted">' . $m($v['ytd']['net']) . '</td></tr></tbody></table>'
            . '<div class="box"><strong>Year to date</strong> · Gross ' . $m($v['ytd']['gross']) . ' · Income tax ' . $m($v['ytd']['income_tax'])
            . ($v['ytd']['vacation_pay'] > 0 ? ' · Vacation pay ' . $m($v['ytd']['vacation_pay']) : '')
            . (! empty($v['ytd_sources']['opening']) || ! empty($v['ytd_sources']['integration']) ? '<div class="muted">Includes pay recorded before this payroll (opening balance or another payroll).</div>' : '') . '</div>'
            . ($er ? '<table><thead><tr><th>Employer contributions (paid by your employer, not taken from your pay)</th><th class="r">This pay</th><th class="r">Year to date</th></tr></thead><tbody>' . $er . '</tbody></table>' : '')
            . '<p class="muted" style="margin-top:14px;">Deductions worked out with the CRA payroll deductions formulas' . ($v['jurisdiction'] === 'QC' ? ' and Revenu Québec TP-1015.F' : '')
            . ' (tables ' . e((string) $v['table_version']) . '). Questions about your pay? Contact the office.</p>'
            . '</body></html>';
    }

    /* THE HOUSE PAYSLIP (2026-11-27): the PDF is drawn by PayslipPdf (agency band + logo, full employee block, earnings and
       deductions with year to date, employer contributions, leave, remarks, terms, NON-NEGOTIABLE watermark). Same frozen
       figures as view(); html() stays for anything that wants the plain version. */
    public static function pdf(object $doc)
    {
        return PayslipPdf::response(PayslipPdf::fromEngine($doc), 'payslip-' . ($doc->period_start ?: $doc->id) . '.pdf');
    }
}
