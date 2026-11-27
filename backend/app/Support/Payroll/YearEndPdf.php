<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Support\KtDompdf as Dompdf;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 3 (2026-11-27): the house PDFs for year end - a person's slip (T4 / T4A, and the RL-1
 * information sheet), the T4 / T4A / RL-1 summaries. CRA's box numbers and descriptions in KiddieTrac's own layout (never an
 * image of a CRA form). No signature line.
 *
 * SIN: $sin is the full 9 digits ONLY when the caller is the person or an agency admin (PayrollYearEndController decides);
 * everyone else gets the masked hint. RL-1: Revenu Quebec authorizes the software that prints RL-1 slips, which KiddieTrac
 * is not, so the Quebec copy is an information sheet with the RL-1 boxes, marked as such.
 */
final class YearEndPdf
{
    public const T4_BOXES = ['14' => 'Employment income', '16' => 'Employee\'s CPP contributions', '16A' => 'Employee\'s second CPP contributions',
        '17' => 'Employee\'s QPP contributions', '17A' => 'Employee\'s second QPP contributions', '18' => 'Employee\'s EI premiums',
        '20' => 'RPP contributions', '22' => 'Income tax deducted', '24' => 'EI insurable earnings', '26' => 'CPP/QPP pensionable earnings',
        '44' => 'Union dues', '46' => 'Charitable donations', '52' => 'Pension adjustment', '55' => 'Employee\'s PPIP premiums', '56' => 'PPIP insurable earnings'];

    public const T4A_BOXES = ['048' => 'Fees for services', '022' => 'Income tax deducted'];

    public const RL1_BOXES = ['A' => 'Employment income', 'B.A' => 'QPP contributions', 'B.B' => 'Second additional QPP contributions', 'C' => 'Employment insurance premiums',
        'E' => 'Québec income tax withheld', 'G' => 'Pensionable salary or wages under the QPP', 'H' => 'QPIP premiums', 'I' => 'Salary or wages eligible under the QPIP'];

    public const OTHER = ['40' => 'Other taxable allowances and benefits'];

    private static function m($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    private static function css(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;color:#0F172A;font-size:11px;}h1{font-size:18px;margin:0;color:#1F6080;}'
            . '.muted{color:#64748B;font-size:10px;}table{width:100%;border-collapse:collapse;margin-top:10px;}'
            . 'th{text-align:left;font-size:9px;color:#64748B;text-transform:uppercase;border-bottom:1px solid #CBD5E1;padding:5px 4px;}'
            . 'td{padding:5px 4px;border-bottom:1px solid #F1F5F9;vertical-align:top;}.r{text-align:right;}.bx{font-weight:bold;color:#1F6080;width:46px;}'
            . '.id td{border:1px solid #E2E8F0;padding:6px 8px;}.id .k{font-size:9px;color:#64748B;text-transform:uppercase;display:block;}'
            . '.flag{display:inline-block;padding:3px 8px;border-radius:4px;font-weight:bold;font-size:10px;margin-top:6px;}'
            . '.amend{background:#FEF3C7;color:#92400E;}.cancel{background:#FEE2E2;color:#991B1B;}.draft{background:#E2E8F0;color:#334155;}'
            . '.note{border:1px solid #E2E8F0;border-radius:6px;padding:8px 10px;margin-top:12px;font-size:10px;color:#334155;}';
    }

    private static function agency(int $agencyId): object
    {
        return DB::table('agencies')->where('id', $agencyId)->first(['name', 'legal_name', 'address_line1', 'city', 'province', 'postal_code']);
    }

    private static function flag(object $s): string
    {
        if ($s->status === 'draft' && $s->report_type === 'O') {
            return '<span class="flag draft">DRAFT · not final, not for filing</span>';
        }
        $h = '';
        if ($s->report_type === 'A') {
            $h .= '<span class="flag amend">AMENDED' . ($s->reason ? ' · ' . e($s->reason) : '') . '</span> ';
        }
        if ($s->report_type === 'C') {
            $h .= '<span class="flag cancel">CANCELLED · this slip replaces an earlier one and reports nothing' . ($s->reason ? ' · ' . e($s->reason) : '') . '</span> ';
        }
        if ($s->status === 'draft') {
            $h .= '<span class="flag draft">Waiting to be finalized</span>';
        }
        if (in_array($s->status, ['replaced', 'cancelled'], true)) {
            $h .= '<span class="flag cancel">No longer in force: a later slip replaces it</span>';
        }

        return $h;
    }

    /** A person's slip. $sin = full digits (person / admin) or the masked hint. */
    public static function slipHtml(object $s, ?string $sin): string
    {
        $ag = self::agency((int) $s->agency_id);
        $emp = PayrollEmployer::settings((int) $s->agency_id);
        $b = json_decode((string) $s->boxes, true) ?: [];
        $c = json_decode((string) $s->codes, true) ?: [];
        $oi = json_decode((string) $s->other_info, true) ?: [];
        $sinTxt = $sin ? (preg_match('/^\d{9}$/', $sin) ? substr($sin, 0, 3) . ' ' . substr($sin, 3, 3) . ' ' . substr($sin, 6) : $sin) : 'Not on file';
        $who = trim($s->given_name . ' ' . $s->surname);
        $title = $s->kind === 'T4' ? 'T4 · Statement of Remuneration Paid' : ($s->kind === 'T4A' ? 'T4A · Statement of Pension, Retirement, Annuity, and Other Income' : 'RL-1 information · Employment and Other Income');
        $map = $s->kind === 'T4' ? self::T4_BOXES : ($s->kind === 'T4A' ? self::T4A_BOXES : self::RL1_BOXES);
        $rows = '';
        foreach ($map as $k => $label) {
            if (! array_key_exists($k, $b)) {
                continue;
            }
            if ((float) $b[$k] == 0.0 && ! in_array($k, ['14', '24', '26', '048', 'A'], true)) {
                continue;
            }
            $rows .= '<tr><td class="bx">' . e($k) . '</td><td>' . e($label) . '</td><td class="r">' . self::m($b[$k]) . '</td></tr>';
        }
        $ids = '<table class="id"><tr>'
            . '<td><span class="k">' . ($s->kind === 'T4A' ? 'Payer' : 'Employer') . '</span><b>' . e((string) ($ag->legal_name ?: $ag->name)) . '</b>'
            . ($ag->address_line1 ? '<div class="muted">' . e($ag->address_line1 . ', ' . $ag->city . ' ' . $ag->province . ' ' . $ag->postal_code) . '</div>' : '') . '</td>'
            . '<td><span class="k">' . ($s->kind === 'RL1' ? 'Employer' : 'Box 54 · Payroll account number') . '</span>' . e((string) ($s->kind === 'RL1' ? ($emp['rq_number'] ?: 'Not set') : ($emp['rp_number'] ?: 'Not set'))) . '</td>'
            . '<td><span class="k">Year</span><b>' . (int) $s->tax_year . '</b></td></tr><tr>'
            . '<td><span class="k">' . ($s->kind === 'T4A' ? 'Recipient' : 'Employee') . '</span><b>' . e($who) . '</b></td>'
            . '<td><span class="k">' . ($s->kind === 'T4A' ? 'Box 12 · Social insurance number' : ($s->kind === 'T4' ? 'Box 12 · Social insurance number' : 'Social insurance number')) . '</span>' . e($sinTxt) . '</td>'
            . '<td>' . ($s->kind === 'T4' ? '<span class="k">Box 10 · Province of employment</span>' . e((string) $s->jurisdiction) : ($s->kind === 'RL1' ? '<span class="k">Province</span>QC' : '<span class="k">Recipient number</span>' . (int) $s->user_id)) . '</td></tr>';
        if ($s->kind === 'T4') {
            $x = $c['28'] ?? [];
            $ex = [];
            foreach (['cpp' => $s->jurisdiction === 'QC' ? 'QPP' : 'CPP', 'ei' => 'EI', 'ppip' => 'PPIP'] as $k => $l) {
                if (isset($x[$k])) {
                    $ex[] = $l . ($x[$k] ? ': exempt' : ': not exempt');
                }
            }
            $ids .= '<tr><td><span class="k">Box 28 · Exempt</span>' . e(implode(' · ', $ex)) . '</td>'
                . '<td><span class="k">Box 29 · Employment code</span>' . e((string) ($c['29'] ?? '—')) . '</td>'
                . '<td><span class="k">Box 45 · Employer-offered dental benefits</span>' . e(isset($c['45']) ? $c['45'] . ' · ' . (YearEnd::DENTAL[(int) $c['45']] ?? '') : 'Not set') . '</td></tr>'
                . (! empty($c['50']) ? '<tr><td colspan="3"><span class="k">Box 50 · RPP or DPSP registration number</span>' . e((string) $c['50']) . '</td></tr>' : '');
        }
        $ids .= '</table>';
        $other = '';
        foreach ($oi as $o) {
            $other .= '<tr><td class="bx">' . e((string) $o['code']) . '</td><td>' . e(self::OTHER[(string) $o['code']] ?? 'Other information') . '</td><td class="r">' . self::m($o['amount']) . '</td></tr>';
        }
        $note = $s->kind === 'RL1'
            ? 'This is an information sheet with the RL-1 box numbers, not the official RL-1 slip. Revenu Québec authorizes the software that prints RL-1 slips; your employer issues your official RL-1 through Revenu Québec.'
            : 'Keep this slip for your income tax return. It shows the CRA box numbers and descriptions in your employer\'s own layout. Your employer also files it with the CRA.';

        return '<html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>'
            . '<h1>' . e($title) . '</h1><div class="muted">' . (int) $s->tax_year . ' · ' . ($s->kind === 'RL1' ? 'Employee information sheet' : 'Copy for the ' . ($s->kind === 'T4A' ? 'recipient' : 'employee')) . ' · version ' . (int) $s->version . '</div>'
            . self::flag($s) . $ids
            . '<table><thead><tr><th>Box</th><th>Description</th><th class="r">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . ($other ? '<table><thead><tr><th>Code</th><th>Other information</th><th class="r">Amount</th></tr></thead><tbody>' . $other . '</tbody></table>'
                . '<div class="muted" style="margin-top:4px;">Code 40 is already included in box 14.</div>' : '')
            . '<div class="note">' . e($note) . ' Questions about these amounts? Contact the office.</div>'
            . '</body></html>';
    }

    /** T4 / T4A / RL-1 Summary (no SIN on it). */
    public static function summaryHtml(int $agencyId, int $year, string $kind): string
    {
        $ag = self::agency($agencyId);
        $emp = PayrollEmployer::settings($agencyId);
        $sum = YearEnd::summary($agencyId, $year, $kind, false);
        $map = $kind === 'T4' ? self::T4_BOXES : ($kind === 'T4A' ? self::T4A_BOXES : self::RL1_BOXES);
        $rows = '';
        $prior = null;
        if ($kind === 'T4') {
            $L = $sum['lines'];
            $lab = ['14' => 'Employment income', '16' => 'Employees\' CPP contributions', '16A' => 'Employees\' second CPP contributions', '18' => 'Employees\' EI premiums',
                '19' => 'Employer\'s EI premiums', '20' => 'RPP contributions', '22' => 'Income tax deducted', '27' => 'Employer\'s CPP contributions',
                '27A' => 'Employer\'s second CPP contributions', '52' => 'Pension adjustment', '80' => 'Total deductions reported (16 + 16A + 18 + 19 + 22 + 27 + 27A)',
                '82' => 'Remittances for the year (from the KiddieTrac remittance report: check against your CRA statement)'];
            foreach ($lab as $k => $l) {
                $rows .= '<tr><td class="bx">' . e($k) . '</td><td>' . e($l) . '</td><td class="r">' . self::m($L[$k] ?? 0) . '</td></tr>';
            }
            $d = (float) $L['difference'];
            if (! empty($sum['prior_payroll_deductions'])) {
                $prior = '<div class="note">Employee deductions of ' . self::m($sum['prior_payroll_deductions']) . ' in the slips came from opening balances or another payroll: that payroll remitted them (plus the employer share), so line 82 above leaves them out. Use the remittances on your CRA statement for line 82.</div>';
            }
            $rows .= '<tr><td class="bx">' . ($d >= 0 ? '86' : '84') . '</td><td>' . ($d >= 0 ? 'Balance due' : 'Overpayment') . ' (line 80 minus line 82)</td><td class="r">' . self::m(abs($d)) . '</td></tr>';
        } else {
            foreach ($map as $k => $l) {
                if (isset($sum['boxes'][$k])) {
                    $rows .= '<tr><td class="bx">' . e($k) . '</td><td>' . e($l) . ' (total)</td><td class="r">' . self::m($sum['boxes'][$k]) . '</td></tr>';
                }
            }
            if ($kind === 'RL1') {
                $rows .= '<tr><td class="bx"></td><td>Employer\'s QPP contributions</td><td class="r">' . self::m($sum['employer']['cpp'] + $sum['employer']['cpp2']) . '</td></tr>'
                    . '<tr><td class="bx"></td><td>Employer\'s QPIP premiums</td><td class="r">' . self::m($sum['employer']['qpip']) . '</td></tr>';
            }
        }
        $title = $kind === 'T4' ? 'T4 Summary · Summary of Remuneration Paid' : ($kind === 'T4A' ? 'T4A Summary · Summary of Pension, Retirement, Annuity, and Other Income' : 'RL-1 Summary information');

        return '<html><head><meta charset="utf-8"><style>' . self::css() . '</style></head><body>'
            . '<h1>' . e($title) . '</h1><div class="muted">' . $year . ' · ' . e((string) ($ag->legal_name ?: $ag->name)) . '</div>'
            . '<table class="id"><tr><td><span class="k">' . ($kind === 'RL1' ? 'Revenu Québec number' : 'Payroll account number') . '</span>' . e((string) ($kind === 'RL1' ? ($emp['rq_number'] ?: 'Not set') : ($emp['rp_number'] ?: 'Not set'))) . '</td>'
            . '<td><span class="k">Slips</span><b>' . (int) $sum['slips'] . '</b></td><td><span class="k">Due</span>' . e(\Carbon\Carbon::parse(YearEnd::deadline($year))->format('F j, Y')) . '</td></tr></table>'
            . '<table><thead><tr><th>Line</th><th>Description</th><th class="r">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table>'
            . ($prior ?? '') . '<div class="note">Finalized slips only. ' . ($kind === 'RL1' ? 'File the RL-1 slips and summary with Revenu Québec (My Account for businesses or authorized software): KiddieTrac is not Revenu Québec-authorized software, so it does not make the RL-1 XML file.'
                : 'File it with the slips: upload the CRA XML file from KiddieTrac to CRA Internet File Transfer, or enter it in My Business Account. KiddieTrac never files for you.') . '</div>'
            . '</body></html>';
    }

    public static function pdf(string $html, string $name)
    {
        $pdf = new Dompdf();
        $pdf->loadHtml($html);
        $pdf->setPaper('letter');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="' . $name . '.pdf"']);
    }
}
