<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Support\Brand;
use App\Support\KtDompdf as Dompdf;
use App\Support\MessageTranslator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * THE KIDDIETRAC PAYSLIP (2026-11-27, Anthony: "mimic this for the standard payslip/paystub ... agency logo and full
 * information including staff full info ... with the terms and conditions and non-negotiable water mark").
 *
 * ONE layout for every payslip PDF KiddieTrac draws itself:
 *  - payroll engine payslips (Payslip::pdf, source 'engine', figures frozen by PayRuns::finalize - nothing recalculated);
 *  - manual / gross-only / iLearn-figures payslips (PayrollDocumentController::pdf built-in layout, and the office view);
 *  - the weekly gross statement staff download from My pay (PayController::payslipPdf).
 * A PDF made by the source system (iLearn, password protected) is still served as-is for downloads and emails; an agency's
 * own document template still wins where one is active.
 *
 * Layout: a band in the agency's brand colour (white-label tint, KiddieTrac blue otherwise) with the employer block and a
 * round logo badge; a white card over the band with three columns (employee | pay details | year to date + this pay);
 * Earnings (rate, hours, this pay, YTD; Gross), Deductions (this pay, YTD; Total) or a clear "not calculated" note,
 * employer contributions (information only), leave balances, remarks, terms and conditions (agency setting
 * payroll.payslip_terms, default below in 4 languages), a diagonal NON-NEGOTIABLE watermark and a footer on every page.
 *
 * PRIVACY: SIN is only ever the last 3 digits (XXX-XXX-123) from hr_staff_sin.sin_hint, never decrypted here. Bank details
 * are only the stored hint (last 4). Staff references are RefCode, never raw ids.
 */
final class PayslipPdf
{
    /** Default terms, one statement per line. {days} = the agency's report window. */
    public const TERMS = [
        'en' => "This statement is confidential and intended only for the employee named on it.\n"
            . "Please check it and report any error or missing pay to your employer within {days} days of the pay date.\n"
            . "Keep this statement for your records and for filing your income tax return.\n"
            . "All amounts are in Canadian dollars (CAD).\n"
            . "Where deductions are shown, they are calculated using the Canada Revenue Agency (CRA) and Revenu Québec formulas published for the pay date.\n"
            . 'This is a statement of earnings, not a cheque. It cannot be cashed or negotiated.',
        'fr' => "Ce relevé est confidentiel et destiné uniquement à l'employé qui y est nommé.\n"
            . "Veuillez le vérifier et signaler toute erreur ou tout montant manquant à votre employeur dans les {days} jours suivant la date de paie.\n"
            . "Conservez ce relevé pour vos dossiers et pour votre déclaration de revenus.\n"
            . "Tous les montants sont en dollars canadiens (CAD).\n"
            . "Les retenues indiquées sont calculées selon les formules publiées par l'Agence du revenu du Canada (ARC) et Revenu Québec en vigueur à la date de paie.\n"
            . "Ceci est un relevé de paie, pas un chèque. Il ne peut être ni encaissé ni négocié.",
        'es' => "Este comprobante es confidencial y está dirigido únicamente al empleado que figura en él.\n"
            . "Revíselo e informe a su empleador de cualquier error o pago faltante dentro de los {days} días posteriores a la fecha de pago.\n"
            . "Conserve este comprobante para sus registros y para su declaración de impuestos.\n"
            . "Todos los importes están en dólares canadienses (CAD).\n"
            . "Las deducciones indicadas se calculan con las fórmulas publicadas por la Agencia de Ingresos de Canadá (CRA) y Revenu Québec vigentes en la fecha de pago.\n"
            . 'Esto es un comprobante de pago, no un cheque. No se puede cobrar ni negociar.',
        'hi' => "यह विवरण गोपनीय है और केवल इसमें नामित कर्मचारी के लिए है।\n"
            . "कृपया इसे जाँचें और वेतन तिथि से {days} दिनों के भीतर किसी भी त्रुटि या छूटे भुगतान की सूचना अपने नियोक्ता को दें।\n"
            . "इस विवरण को अपने रिकॉर्ड और आयकर रिटर्न के लिए सँभालकर रखें।\n"
            . "सभी राशियाँ कनाडाई डॉलर (CAD) में हैं।\n"
            . "जहाँ कटौतियाँ दिखाई गई हैं, वे वेतन तिथि पर लागू कनाडा राजस्व एजेंसी (CRA) और Revenu Québec के प्रकाशित सूत्रों से गणना की गई हैं।\n"
            . 'यह आय का विवरण है, चेक नहीं। इसे भुनाया या हस्तांतरित नहीं किया जा सकता।',
    ];

    /** Words drawn by hand (never sent to the translator): watermark, the not-a-cheque line, page numbers. */
    private const WORDS = [
        'en' => ['wm' => 'NON-NEGOTIABLE', 'cheque' => 'NON-NEGOTIABLE — THIS IS NOT A CHEQUE', 'page' => 'Page {PAGE_NUM} of {PAGE_COUNT}', 'terms' => 'Terms and conditions'],
        'fr' => ['wm' => 'NON NÉGOCIABLE', 'cheque' => 'NON NÉGOCIABLE — CECI N\'EST PAS UN CHÈQUE', 'page' => 'Page {PAGE_NUM} de {PAGE_COUNT}', 'terms' => 'Conditions'],
        'es' => ['wm' => 'NO NEGOCIABLE', 'cheque' => 'NO NEGOCIABLE — ESTO NO ES UN CHEQUE', 'page' => 'Página {PAGE_NUM} de {PAGE_COUNT}', 'terms' => 'Términos y condiciones'],
        'hi' => ['wm' => 'अहस्तांतरणीय', 'cheque' => 'अहस्तांतरणीय — यह चेक नहीं है', 'page' => 'पृष्ठ {PAGE_NUM} / {PAGE_COUNT}', 'terms' => 'नियम और शर्तें'],
    ];

    public const DEFAULT_DAYS = 30;

    /* ── settings ─────────────────────────────────────────────────────────── */

    /** The agency's terms as lines, in $loc when they are the default. */
    public static function terms(int $agencyId, ?string $loc = null): array
    {
        $p = self::payrollSettings($agencyId);
        $days = max(1, min(365, (int) ($p['payslip_terms_days'] ?? self::DEFAULT_DAYS)));
        $custom = trim((string) ($p['payslip_terms'] ?? ''));
        $text = $custom !== '' ? $custom : (self::TERMS[self::lang($loc)] ?? self::TERMS['en']);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', str_replace('{days}', (string) $days, $text))), fn ($l) => $l !== ''));

        return ['lines' => $lines, 'custom' => $custom !== ''];
    }

    private static function payrollSettings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return is_array($s['payroll'] ?? null) ? $s['payroll'] : [];
    }

    private static function lang(?string $loc): string
    {
        $l = strtolower(substr((string) $loc, 0, 2));

        return isset(self::WORDS[$l]) ? $l : 'en';
    }

    /** Leave units, singular or plural, in the reader's language: 1 day, 0.5 day, 2 days. */
    private const UNITS = [
        'en' => ['days' => ['day', 'days'], 'hours' => ['hour', 'hours']],
        'fr' => ['days' => ['jour', 'jours'], 'hours' => ['heure', 'heures']],
        'es' => ['days' => ['día', 'días'], 'hours' => ['hora', 'horas']],
        'hi' => ['days' => ['दिन', 'दिन'], 'hours' => ['घंटा', 'घंटे']],
    ];

    public static function unit(string $unit, float $n, string $lang = 'en'): string
    {
        $u = self::UNITS[$lang][$unit === 'hours' ? 'hours' : 'days'] ?? self::UNITS['en'][$unit === 'hours' ? 'hours' : 'days'];
        // French uses the singular below 2 (0.5 jour, 1 jour); the others only for exactly 1.
        $one = $lang === 'fr' ? abs($n) < 2 : abs(abs($n) - 1.0) < 0.0001;

        return $one ? $u[0] : $u[1];
    }

    /** Hindi needs a Devanagari font Dompdf can use: Noto Sans Devanagari (SIL OFL 1.1, notofonts/devanagari), in
     *  storage/fonts on the server, or the copy kept in the repo at resources/fonts (storage is a shared symlink outside
     *  git). Without either, its words would print as boxes, so English is used. */
    private static function devanagariFont(string $weight = 'Regular'): ?string
    {
        foreach ([storage_path('fonts'), resource_path('fonts')] as $dir) {
            if (is_file($f = $dir . '/NotoSansDevanagari-' . $weight . '.ttf')) {
                return $f;
            }
        }

        return null;
    }

    /* ── shared blocks ────────────────────────────────────────────────────── */

    /** Employer block + brand colour + logo. $plain = draw as plain KiddieTrac even for a white-label agency (preview only). */
    public static function employer(int $agencyId, ?int $centreId = null, bool $plain = false): array
    {
        $a = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = $a && $a->settings ? (json_decode((string) $a->settings, true) ?: []) : [];
        $brand = Brand::forAgency($agencyId);
        $wl = ! $plain && ! empty($brand['white_label']);
        $colour = $wl && preg_match('/^#[0-9A-Fa-f]{6}$/', (string) ($brand['primary_color'] ?? '')) ? strtoupper($brand['primary_color']) : Brand::KT['primary_color'];
        $logoUrl = $wl ? ($brand['logo_square_url'] ?? null) : (($a->logo_url ?? null) ?: Brand::KT['logo_square_url']);
        $addr = trim(implode(', ', array_filter([
            trim(implode(' ', array_filter([$a->address_line1 ?? null, $a->address_line2 ?? null]))),
            $a->city ?? null,
            trim(($a->province ?? '') . ' ' . ($a->postal_code ?? '')),
        ])));
        if ($addr === '') {
            $addr = trim(preg_replace('/\s*\n\s*/', ', ', (string) ($settings['brand_address'] ?? '')));
        }
        $emp = class_exists(PayrollEmployer::class) ? PayrollEmployer::settings($agencyId) : [];
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first(['name']) : null;
        $phone = (string) ($a->contact_phone ?? '');

        return [
            'agency_id' => $agencyId,
            'name' => (string) ($a->name ?? ''),
            'legal_name' => trim((string) ($a->legal_name ?? '')) ?: (string) ($a->name ?? ''),
            'address' => $addr,
            'phone' => self::phone($phone),
            'email' => (string) (($wl ? ($a->brand_support_email ?? null) : null) ?: ($a->contact_email ?? '')),
            'rp' => (string) ($emp['rp_number'] ?? ''),
            'rq' => (string) ($emp['rq_number'] ?? ''),
            'centre' => $centre->name ?? null,
            'colour' => $colour,
            'ink' => Brand::heroText($colour, null, $colour) === 'light' ? '#FFFFFF' : '#0F172A',
            'logo' => self::logo($logoUrl),
            'initials' => self::initials((string) ($a->name ?? '')),
            'white_label' => $wl,
        ];
    }

    /** The employee block. Never the full SIN, never raw ids, never bank numbers. */
    public static function employee(int $agencyId, int $userId, ?int $centreId = null, ?string $fallbackName = null, ?string $fallbackRole = null): array
    {
        $u = DB::table('users')->where('id', $userId)->first();
        $file = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->first();
        $prof = DB::table('user_profiles')->where('user_id', $userId)->first(['address']);
        $extras = $u && $u->profile_extras ? (json_decode((string) $u->profile_extras, true) ?: []) : [];
        $addr = trim((string) ($prof->address ?? ''));
        if ($addr === '') {
            $addr = trim(implode(', ', array_filter([
                trim((string) ($extras['address_line1'] ?? ($extras['address'] ?? ''))),
                trim(implode(' ', array_filter([$extras['city'] ?? '', $extras['province'] ?? ($extras['state'] ?? '')]))),
                trim((string) ($extras['postal_code'] ?? ($extras['postal'] ?? ''))),
            ])));
        }
        $hint = (string) DB::table('hr_staff_sin')->where('agency_id', $agencyId)->where('user_id', $userId)->value('sin_hint');
        $last3 = preg_match('/(\d{3})\s*$/', $hint, $m) ? $m[1] : null;
        $ref = '';
        try {
            $ref = (string) (\App\Support\RefCode::users([$userId], $agencyId, 'S')[$userId] ?? '');
        } catch (\Throwable $e) {
        }
        $centreRow = ($c = $file->primary_centre_id ?? null ?: $centreId) ? DB::table('centres')->where('id', $c)->first(['name', 'province', 'country']) : null;
        $pm = DB::table('staff_payout_methods')->where('user_id', $userId)
            ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))->orderByRaw('agency_id IS NULL')->first(['method', 'account_hint', 'interac_email_hint']);
        $payment = 'Not recorded';
        if ($pm && $pm->method === 'direct_deposit') {
            $payment = 'Direct deposit' . (preg_match('/(\d{3,4})\s*$/', (string) $pm->account_hint, $mm) ? ' · account ending ' . $mm[1] : '');
        } elseif ($pm && $pm->method === 'interac') {
            $payment = 'Interac e-Transfer' . ($pm->interac_email_hint ? ' · ' . $pm->interac_email_hint : '');
        } elseif ($pm && $pm->method === 'cheque') {
            $payment = 'Cheque';
        }
        $types = ['full_time' => 'Full time', 'part_time' => 'Part time', 'casual' => 'Casual', 'contract' => 'Contract', 'contractor' => 'Contractor',
            'temporary' => 'Temporary', 'seasonal' => 'Seasonal', 'student' => 'Student', 'supply' => 'Supply'];
        $et = (string) ($file->employment_type ?? '');

        return [
            'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: (string) $fallbackName,
            'ref' => $ref,
            'position' => trim((string) ($file->job_title ?? '')) ?: (string) $fallbackRole,
            'centre' => $centreRow->name ?? null,
            'centre_province' => ($centreRow && strtoupper((string) $centreRow->country) === 'CA' && isset(CaCalculator::NAMES[strtoupper((string) $centreRow->province)]))
                ? CaCalculator::NAMES[strtoupper((string) $centreRow->province)] : null,
            'employment_type' => $et !== '' ? ($types[$et] ?? ucfirst(str_replace('_', ' ', $et))) : null,
            'address' => $addr,
            'phone' => self::phone((string) ($u->phone ?? '')),
            'email' => (string) ($u->email ?? ''),
            'start' => ! empty($u->hire_date) ? self::day((string) $u->hire_date) : null,
            'pay_rate' => isset($u->pay_rate) && (float) $u->pay_rate > 0 ? (float) $u->pay_rate : null,
            'pay_type' => (string) ($u->pay_type ?? ''),
            'sin' => $last3 ? 'XXX-XXX-' . $last3 : 'Not on file',
            'payment' => $payment,
        ];
    }

    /** Leave balances (HR add-on), only the types staff can see and that are tracked. */
    public static function leave(int $agencyId, int $userId): array
    {
        try {
            if (! class_exists(\App\Support\HrLeave::class) || ! \App\Support\HrLeave::on($agencyId)) {
                return [];
            }
            $out = [];
            foreach (\App\Support\HrLeave::summary($agencyId, $userId, true) as $t) {
                if (empty($t['tracked'])) {
                    continue;
                }
                $out[] = ['name' => $t['name'], 'balance' => (float) $t['balance'], 'unit' => $t['unit'] === 'hours' ? 'hours' : 'days'];
            }

            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ── builders ─────────────────────────────────────────────────────────── */

    /** A payroll engine payslip: every figure from what finalize froze on the document. */
    public static function fromEngine(object $doc, bool $plain = false): array
    {
        $v = Payslip::view($doc);
        $b = json_decode((string) $doc->breakdown, true) ?: [];
        $t = $b['this_pay'] ?? [];
        $y = $b['ytd'] ?? [];
        $aid = (int) $doc->agency_id;
        $uid = (int) $doc->user_id;
        $payDate = (string) ($b['run']['pay_date'] ?? $doc->period_end);
        $year = (int) substr($payDate, 0, 4);

        // The engine payslips this year up to this one (finalized runs, by pay date) - for each line's year to date.
        $docs = DB::table('payroll_documents as d')->join('payroll_runs as r', 'r.id', '=', 'd.payroll_run_id')
            ->where('d.agency_id', $aid)->where('d.user_id', $uid)->where('d.source', 'engine')->where('r.status', 'finalized')
            ->whereYear('r.pay_date', $year)
            ->where(fn ($w) => $w->where('r.pay_date', '<', $payDate)->orWhere(fn ($x) => $x->where('r.pay_date', $payDate)->where('d.id', '<=', $doc->id)))
            ->get(['d.id', 'd.lines']);
        $ytdByLabel = [];
        foreach ($docs as $d) {
            foreach (json_decode((string) $d->lines, true) ?: [] as $l) {
                if (! empty($l['not_taxable'])) {
                    continue;
                }
                $k = (string) ($l['label'] ?? '');
                $ytdByLabel[$k] = ($ytdByLabel[$k] ?? 0) + (float) ($l['amount'] ?? 0);
            }
        }
        $earn = [];
        $rate = (float) ($b['rate'] ?? $doc->rate ?? 0);
        foreach ($v['earnings'] as $l) {
            if (! empty($l['not_taxable'])) {
                continue;   // reimbursements: not earnings, added after deductions
            }
            $lr = $l['rate'] ?? (isset($l['multiplier']) && $rate > 0 ? round($rate * (float) $l['multiplier'], 4) : null);
            $earn[] = ['label' => (string) $l['label'] . (isset($l['multiplier']) ? ' (' . rtrim(rtrim(number_format((float) $l['multiplier'], 2), '0'), '.') . '×)' : '')
                    . (! empty($l['non_cash']) ? ' (not paid in cash)' : ''),
                'rate' => $lr !== null ? (float) $lr : null, 'hours' => isset($l['hours']) && $l['hours'] !== null ? (float) $l['hours'] : null,
                'current' => (float) $l['amount'], 'ytd' => round($ytdByLabel[(string) $l['label']] ?? (float) $l['amount'], 2)];
            unset($ytdByLabel[(string) $l['label']]);
        }
        foreach ($ytdByLabel as $label => $amt) {   // paid earlier this year, not on this payslip
            if (abs($amt) >= 0.005) {
                $earn[] = ['label' => $label, 'rate' => null, 'hours' => null, 'current' => 0.0, 'ytd' => round($amt, 2)];
            }
        }
        $ytdGross = (float) ($y['gross'] ?? 0);
        $rest = round($ytdGross - array_sum(array_column($earn, 'ytd')), 2);
        if (abs($rest) >= 0.01) {
            $earn[] = ['label' => 'Earlier pay this year (before this payroll)', 'rate' => null, 'hours' => null, 'current' => 0.0, 'ytd' => $rest];
        }

        // Deductions: CPP/EI/QPIP year to date come frozen on the payslip; the tax split from the engine lines.
        $lineIds = DB::table('payroll_run_lines')->whereIn('payroll_document_id', $docs->pluck('id')->all() ?: [0]);
        $sum = (clone $lineIds)->selectRaw('SUM(federal_tax) f, SUM(provincial_tax) p, SUM(other_deduction) o, SUM(benefits_noncash) nc, SUM(reimbursements) rb')->first();
        $qc = ($b['jurisdiction'] ?? '') === 'QC';
        $plan = $qc ? 'QPP' : 'CPP';
        $ded = [];
        $row = function (string $label, float $cur, ?float $ytd) use (&$ded) {
            if (abs($cur) < 0.005 && ($ytd === null || abs($ytd) < 0.005)) {
                return;
            }
            $ded[] = ['label' => $label, 'current' => $cur, 'ytd' => $ytd];
        };
        $row($plan, (float) ($t['cpp'] ?? 0), (float) ($y['cpp'] ?? 0));
        $row($plan . '2 (second additional)', (float) ($t['cpp2'] ?? 0), (float) ($y['cpp2'] ?? 0));
        $row('EI', (float) ($t['ei'] ?? 0), (float) ($y['ei'] ?? 0));
        $row('QPIP', (float) ($t['qpip'] ?? 0), (float) ($y['qpip'] ?? 0));
        $fy = round((float) ($sum->f ?? 0), 2);
        $py = round((float) ($sum->p ?? 0), 2);
        $row('Federal income tax', (float) ($t['federal_tax'] ?? 0), $fy);
        $row($qc ? 'Quebec income tax' : ((CaCalculator::NAMES[$b['jurisdiction'] ?? ''] ?? 'Provincial') . ' income tax'), (float) ($t['provincial_tax'] ?? 0), $py);
        $taxRest = round((float) ($y['income_tax'] ?? 0) - $fy - $py, 2);
        if (abs($taxRest) >= 0.01) {
            $row('Income tax before this payroll', 0.0, $taxRest);
        }
        $row(($b['other_label'] ?? null) ?: 'Other deduction', (float) ($t['other_deduction'] ?? 0), round((float) ($sum->o ?? 0), 2));

        $run = $doc->payroll_run_id ? DB::table('payroll_runs')->where('id', $doc->payroll_run_id)->first(['notes', 'reference', 'kind']) : null;
        $lineNote = $doc->payroll_run_id ? DB::table('payroll_run_lines')->where('payroll_document_id', $doc->id)->value('note') : null;
        $remarks = array_values(array_filter([
            ($run->kind ?? '') === 'adjustment' ? 'Adjustment payslip: corrects an earlier pay run.' : null,
            trim((string) ($run->notes ?? '')), trim((string) $lineNote), trim((string) ($doc->notes ?? '')),
            self::agencyNote($aid),
            ! empty($b['ytd_sources']['opening']) || ! empty($b['ytd_sources']['integration']) ? 'Year to date includes pay recorded before this payroll (opening balance or another payroll).' : null,
        ]));
        $emp = self::employee($aid, $uid, $doc->centre_id ? (int) $doc->centre_id : null, $doc->payee_name, $doc->role_label);
        $totalDedCur = round(array_sum(array_column($ded, 'current')), 2);
        $totalDedYtd = round(array_sum(array_map(fn ($d) => (float) $d['ytd'], $ded)), 2);
        $after = [];
        $ncY = round((float) ($sum->nc ?? 0), 2);
        $rbY = round((float) ($sum->rb ?? 0), 2);
        if ($v['benefits_noncash'] > 0 || $ncY > 0) {
            $after[] = ['label' => 'Less non-cash benefit (taxed, not paid in cash)', 'amount' => -$v['benefits_noncash'], 'ytd' => -$ncY];
        }
        if ($v['reimbursements'] > 0 || $rbY > 0) {
            $after[] = ['label' => 'Plus reimbursements (not taxable)', 'amount' => $v['reimbursements'], 'ytd' => $rbY];
        }
        /* YEAR-TO-DATE NET = the year-to-date column above it (gross - total deductions -/+ the lines after), so the payslip
           always adds up. Checked against the stored figure (PayRuns::ytdNetOf: frozen breakdowns from before 2026-11-27
           left the opening balance's net out); a difference is logged, never hidden. */
        $ytdNet = round($ytdGross - $totalDedYtd - $ncY + $rbY, 2);
        $stored = PayRuns::ytdNetOf($doc, $b);
        if (abs($stored - $ytdNet) >= 0.02) {
            \Illuminate\Support\Facades\Log::warning('[payslip] year-to-date net does not match the stored figure', ['doc' => $doc->id, 'drawn' => $ytdNet, 'stored' => $stored]);
        }

        return self::base($doc, $plain) + [
            'employee' => $emp,
            'province' => $v['province'] ?: $emp['centre_province'],
            'pay_date' => $payDate,
            'run_reference' => $b['run']['reference'] ?? ($run->reference ?? null),
            'rate_line' => self::rateLine($b['pay_type'] ?? $doc->unit_label, $rate, $doc->pay_frequency),
            'earnings' => $earn,
            'gross' => ['current' => (float) $v['gross'], 'ytd' => $ytdGross],
            'deductions' => $ded,
            'deductions_total' => ['current' => $totalDedCur, 'ytd' => $totalDedYtd],
            'after' => $after,
            'net' => ['current' => (float) $v['net'], 'ytd' => $ytdNet],
            'employer_contrib' => array_map(fn ($e) => ['label' => $e['label'], 'current' => $e['amount'], 'ytd' => $e['ytd']], $v['employer']),
            'remarks' => $remarks,
            'basis' => 'Deductions worked out with the CRA payroll deductions formulas' . ($qc ? ' and Revenu Québec TP-1015.F' : '') . ' (tables ' . ($v['table_version'] ?? '') . ').',
        ];
    }

    /** Any other payslip KiddieTrac holds (manual, Generate payroll, iLearn figures). Shows only what is recorded. */
    public static function fromDocument(object $doc, bool $plain = false): array
    {
        $aid = (int) $doc->agency_id;
        $uid = (int) $doc->user_id;
        $lines = [];
        foreach ((json_decode((string) ($doc->lines ?? ''), true) ?: []) as $l) {
            if (is_array($l) && trim((string) ($l['label'] ?? '')) !== '') {
                $lines[] = $l;
            }
        }
        $linesSum = round(array_sum(array_map(fn ($l) => (float) ($l['amount'] ?? 0), $lines)), 2);
        $ot = (float) ($doc->ot_amount ?? 0);
        $gross = (float) $doc->gross;
        $first = round($gross - $linesSum - $ot, 2);
        $hasFunding = (bool) array_filter($lines, fn ($l) => ! empty($l['component']));
        $firstLabel = $hasFunding ? 'Base wage' : ($doc->unit_label === 'visits' ? 'Home visits completed' : ($doc->unit_label === 'hours' ? 'Hours worked' : 'Agreed amount'));
        $earn = [];
        if (! ($lines && abs($first) < 0.005)) {
            $earn[] = ['label' => $firstLabel, 'rate' => (float) $doc->rate > 0 ? (float) $doc->rate : null,
                'hours' => in_array($doc->unit_label, ['hours', 'visits'], true) ? (float) $doc->units : null, 'current' => $first, 'ytd' => null];
        }
        if ($ot > 0) {
            $earn[] = ['label' => 'Overtime' . ($doc->ot_mult ? ' (' . rtrim(rtrim(number_format((float) $doc->ot_mult, 2), '0'), '.') . '×)' : ''),
                'rate' => (float) $doc->rate > 0 && $doc->ot_mult ? round((float) $doc->rate * (float) $doc->ot_mult, 2) : null,
                'hours' => $doc->ot_hours !== null ? (float) $doc->ot_hours : null, 'current' => $ot, 'ytd' => null];
        }
        foreach ($lines as $l) {
            $earn[] = ['label' => (string) $l['label'], 'rate' => isset($l['rate']) && $l['rate'] !== '' && $l['rate'] !== null ? (float) $l['rate'] : null,
                'hours' => isset($l['hours']) && $l['hours'] !== '' && $l['hours'] !== null ? (float) $l['hours'] : null, 'current' => (float) ($l['amount'] ?? 0), 'ytd' => null];
        }
        $vac = (float) ($doc->vacation_pay ?? 0);
        if (abs($vac) >= 0.005) {
            $earn[] = ['label' => 'Vacation pay', 'rate' => null, 'hours' => null, 'current' => $vac, 'ytd' => null];
        }

        // Year to date from this person's other payslips this year (void ones left out).
        $yr = substr((string) ($doc->period_end ?: $doc->period_start), 0, 4);
        $ytd = DB::table('payroll_documents')->where('agency_id', $aid)->where('user_id', $uid)->where('kind', 'payslip')->where('status', '!=', 'void')
            ->whereYear('period_end', (int) $yr)->where(fn ($w) => $w->where('period_end', '<', $doc->period_end)->orWhere(fn ($x) => $x->where('period_end', $doc->period_end)->where('id', '<=', $doc->id)))
            ->selectRaw('SUM(gross + COALESCE(vacation_pay,0)) g, SUM(cpp) cpp, SUM(ei) ei, SUM(income_tax) tax, SUM(other_deductions) oth, SUM(COALESCE(net, gross + COALESCE(vacation_pay,0))) net')->first();

        $recorded = ['CPP' => [$doc->cpp, 'cpp'], 'EI' => [$doc->ei, 'ei'], 'Income tax' => [$doc->income_tax, 'tax'], 'Other deductions' => [$doc->other_deductions, 'oth']];
        $ded = [];
        foreach ($recorded as $label => [$cur, $k]) {
            if ($cur !== null && abs((float) $cur) >= 0.005) {
                $ded[] = ['label' => $label, 'current' => (float) $cur, 'ytd' => round((float) ($ytd->$k ?? 0), 2)];
            }
        }
        $grossAll = round($gross + $vac, 2);
        $net = $doc->net !== null ? (float) $doc->net : $grossAll;
        $grossOnly = ! $ded;

        $remarks = array_values(array_filter([trim((string) ($doc->notes ?? '')), self::agencyNote($aid),
            $hasFunding ? 'Wage Enhancement Grant (WEG) and workforce compensation (CWELCC) are provincial funding paid to you on every pay cheque for each hour worked, shown here as separate lines.' : null,
            ! empty($doc->pdf_url) ? 'Drawn by KiddieTrac from the figures your payroll provider sent. Your provider\'s own copy is the official payslip.' : null,
            $doc->status === 'paid' && $doc->paid_at ? 'Marked paid ' . self::day((string) $doc->paid_at) . '.' : null]));
        $emp = self::employee($aid, $uid, $doc->centre_id ? (int) $doc->centre_id : null, $doc->payee_name, $doc->role_label);

        return self::base($doc, $plain) + [
            'employee' => $emp,
            'province' => $emp['centre_province'],
            'pay_date' => $doc->paid_at ? substr((string) $doc->paid_at, 0, 10) : null,
            'run_reference' => null,
            'rate_line' => self::rateLine($doc->unit_label === 'visits' ? 'per_visit' : ($doc->unit_label === 'hours' ? 'hourly' : 'flat'), (float) $doc->rate, $doc->pay_frequency),
            'earnings' => $earn,
            'gross' => ['current' => $grossAll, 'ytd' => round((float) ($ytd->g ?? $grossAll), 2)],
            'deductions' => $grossOnly ? null : $ded,
            'deductions_total' => $grossOnly ? null : ['current' => round(array_sum(array_column($ded, 'current')), 2), 'ytd' => round(array_sum(array_column($ded, 'ytd')), 2)],
            'after' => [],
            // Gross-only: no net year to date (it would only repeat gross, or mix in other payrolls' net).
            'net' => ['current' => $net, 'ytd' => $grossOnly ? null : round((float) ($ytd->net ?? $net), 2)],
            'employer_contrib' => [],
            'remarks' => $remarks,
            'basis' => $grossOnly ? null : 'Deductions as recorded by payroll.',
        ];
    }

    /** The weekly gross statement from My pay (no stored document, no deductions). */
    public static function fromWeekly(int $agencyId, int $userId, array $profile, string $start, string $end, float $units, float $gross): array
    {
        $type = (string) ($profile['type'] ?? 'hourly');
        $emp = self::employee($agencyId, $userId, null, null, null);
        $label = $type === 'per_visit' ? 'Home visits' : ($type === 'salary' ? 'Salary (this period)' : 'Hours worked');

        return [
            'agency' => self::employer($agencyId),
            'title' => 'Pay statement',
            'reference' => null,
            'period_start' => $start, 'period_end' => $end,
            'status' => 'Estimate from hours recorded',
            'currency' => 'CAD',
            'employee' => $emp,
            'province' => $emp['centre_province'],
            'pay_date' => null,
            'run_reference' => null,
            'rate_line' => self::rateLine($type, (float) ($profile['rate'] ?? 0), 'Weekly statement'),
            'earnings' => [['label' => $label, 'rate' => (float) ($profile['rate'] ?? 0) ?: null, 'hours' => $type === 'salary' ? null : $units, 'current' => $gross, 'ytd' => null]],
            'gross' => ['current' => $gross, 'ytd' => null],
            'deductions' => null, 'deductions_total' => null, 'after' => [],
            'net' => ['current' => $gross, 'ytd' => null],
            'employer_contrib' => [],
            'leave' => self::leave($agencyId, $userId),
            'remarks' => array_values(array_filter([self::agencyNote($agencyId), 'Worked out from the hours recorded in KiddieTrac for this week. Your official payslip comes from payroll.'])),
            'basis' => null,
        ];
    }

    private static function base(object $doc, bool $plain): array
    {
        $aid = (int) $doc->agency_id;

        return [
            'agency' => self::employer($aid, $doc->centre_id ? (int) $doc->centre_id : null, $plain),
            'title' => 'Payslip',
            'reference' => (string) $doc->reference,
            'period_start' => (string) $doc->period_start, 'period_end' => (string) ($doc->period_end ?: $doc->period_start),
            'status' => ucfirst((string) $doc->status),
            'currency' => (string) ($doc->currency ?: 'CAD'),
            'leave' => self::leave($aid, (int) $doc->user_id),
        ];
    }

    private static function agencyNote(int $agencyId): ?string
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $n = trim((string) ($s['payslip_note'] ?? ''));

        return $n !== '' ? $n : null;
    }

    private static function rateLine(?string $type, float $rate, ?string $freq): string
    {
        if ($rate <= 0) {
            return trim((string) $freq) ?: '—';
        }
        $r = '$' . number_format($rate, 2) . ($type === 'salary' ? ' per period' : ($type === 'per_visit' ? ' per visit' : ($type === 'hourly' || $type === 'hours' ? ' per hour' : '')));

        return $r . ($freq ? ' · ' . $freq : '');
    }

    /* ── drawing ──────────────────────────────────────────────────────────── */

    public static function html(array $v, ?string $loc = null): string
    {
        $lang = self::lang($loc);
        if ($lang === 'hi' && ! self::devanagariFont()) {
            $lang = 'en';
        }
        $w = self::WORDS[$lang];
        $a = $v['agency'];
        $e = $v['employee'];
        $c = $a['colour'];
        $ink = $a['ink'];
        $sub = $ink === '#FFFFFF' ? 'rgba(255,255,255,.86)' : '#334155';
        $m = fn ($n) => $n === null ? '—' : (($n < 0 ? '-' : '') . '$' . number_format(abs((float) $n), 2));
        $h = fn ($n) => $n === null ? '' : rtrim(rtrim(number_format((float) $n, 2), '0'), '.');
        $nt = ' data-kt-no-translate="1"';
        $kv = function (string $k, ?string $val, bool $raw = false) use ($nt) {
            if ($val === null || trim($val) === '') {
                return '';
            }

            return '<tr><td class="k">' . e($k) . '</td><td class="v"' . ($raw ? '' : $nt) . '>' . e($val) . '</td></tr>';
        };
        $period = self::day($v['period_start']) . ' – ' . self::day($v['period_end']);
        $terms = self::terms((int) $a['agency_id'], $lang);

        // Header band: employer at left, round logo badge at right.
        $band = '<div class="band" style="background:' . $c . ';color:' . $ink . ';"><table class="bt"><tr><td>'
            . '<div class="an"' . $nt . '>' . e($a['name']) . '</div>'
            . ($a['legal_name'] !== $a['name'] ? '<div class="al"' . $nt . ' style="color:' . $sub . ';">' . e($a['legal_name']) . '</div>' : '')
            . ($a['address'] ? '<div class="al"' . $nt . ' style="color:' . $sub . ';">' . e($a['address']) . '</div>' : '')
            . '<div class="al"' . $nt . ' style="color:' . $sub . ';">' . e(implode('  ·  ', array_filter([$a['phone'], $a['email']]))) . '</div>'
            . ($a['rp'] ? '<div class="al" style="color:' . $sub . ';">CRA business number <span' . $nt . '>' . e($a['rp']) . '</span></div>' : '')
            . ($a['centre'] ? '<div class="al" style="color:' . $sub . ';">Centre: <span' . $nt . '>' . e($a['centre']) . '</span></div>' : '')
            . '</td>' . self::logoPanel($a) . '</tr></table></div>';

        // The card: three columns.
        $col1 = '<div class="ch">Employee</div><table class="kv">'
            . $kv('Name', $e['name']) . $kv('Employee ID', $e['ref'] ?: null) . $kv('Position', $e['position'] ?: null)
            . $kv('Centre', $e['centre']) . $kv('Employment type', $e['employment_type'], true) . $kv('Start date', $e['start'], true)
            . $kv('Home address', $e['address'] ?: 'Not on file') . $kv('Phone', $e['phone'] ?: null) . $kv('Email', $e['email'] ?: null) . '</table>';
        $col2 = '<div class="ch">Pay details</div><table class="kv">'
            . $kv('SIN', $e['sin']) . $kv('Province of employment', $v['province'] ?: null, true)
            . $kv('Pay period', $period, true) . $kv('Pay date', $v['pay_date'] ? self::day($v['pay_date']) : null, true)
            . $kv('Payslip number', $v['reference'] ?: null) . $kv('Pay run', $v['run_reference'] ?: null)
            . $kv('Pay rate', $v['rate_line'], true) . $kv('Payment method', $e['payment'], true) . $kv('Status', $v['status'], true) . '</table>';
        $money = function (string $k, $n, bool $strong = false) use ($m) {
            if ($n === null) {
                return '';
            }

            return '<tr' . ($strong ? ' class="s"' : '') . '><td>' . e($k) . '</td><td class="r">' . $m($n) . '</td></tr>';
        };
        $grossOnly = $v['deductions'] === null;
        $col3 = ($v['gross']['ytd'] !== null ? '<div class="ch">Year to date</div><table class="mini">'
            . $money('Gross', $v['gross']['ytd']) . ($grossOnly ? '' : $money('Deductions', $v['deductions_total']['ytd'])) . $money('Net', $v['net']['ytd'], true) . '</table>' : '')
            . '<div class="ch"' . ($v['gross']['ytd'] !== null ? ' style="margin-top:8px;"' : '') . '>This pay</div><table class="mini">'
            . $money('Gross', $v['gross']['current']) . ($grossOnly ? '' : $money('Deductions', $v['deductions_total']['current'])) . '</table>'
            . '<div class="netbox" style="border-color:' . $c . ';"><div class="nk">' . ($grossOnly ? 'Gross pay' : 'Net pay') . '</div>'
            . '<div class="nv" style="color:' . self::darken($c) . ';">' . $m($v['net']['current']) . '</div></div>';

        // Earnings.
        $er = '';
        foreach ($v['earnings'] as $r) {
            $er .= '<tr><td>' . e($r['label']) . '</td><td class="r">' . ($r['rate'] !== null ? $m($r['rate']) : '') . '</td><td class="r">' . $h($r['hours']) . '</td>'
                . '<td class="r">' . $m($r['current']) . '</td><td class="r">' . ($r['ytd'] !== null ? $m($r['ytd']) : '') . '</td></tr>';
        }
        $er .= '<tr class="tot"><td>Gross pay</td><td></td><td></td><td class="r">' . $m($v['gross']['current']) . '</td><td class="r">' . ($v['gross']['ytd'] !== null ? $m($v['gross']['ytd']) : '') . '</td></tr>';
        $earnings = '<div class="sec" style="color:' . self::darken($c) . ';">Earnings</div><table class="grid" style="border-top-color:' . $c . ';"><thead><tr><th>Description</th><th class="r">Rate</th><th class="r">Hours</th><th class="r">This pay</th><th class="r">Year to date</th></tr></thead><tbody>' . $er . '</tbody></table>';

        // Deductions, or a clear note when payroll did not work them out.
        if ($grossOnly) {
            $deductions = '<div class="sec" style="color:' . self::darken($c) . ';">Deductions</div><div class="note">Deductions not calculated ' . ($a['white_label'] ? 'here' : 'in KiddieTrac') . '. This payslip shows gross pay before income tax, CPP, EI and any other deductions your payroll makes.</div>';
        } else {
            $dr = '';
            foreach ($v['deductions'] as $r) {
                $dr .= '<tr><td>' . e($r['label']) . '</td><td class="r">' . $m($r['current']) . '</td><td class="r">' . ($r['ytd'] !== null ? $m($r['ytd']) : '') . '</td></tr>';
            }
            $dr .= '<tr class="tot"><td>Total deductions</td><td class="r">' . $m($v['deductions_total']['current']) . '</td><td class="r">' . $m($v['deductions_total']['ytd']) . '</td></tr>';
            foreach ($v['after'] as $x) {
                $dr .= '<tr><td>' . e($x['label']) . '</td><td class="r">' . $m($x['amount']) . '</td><td class="r">' . (isset($x['ytd']) ? $m($x['ytd']) : '') . '</td></tr>';
            }
            $dr .= '<tr class="net"><td>Net pay</td><td class="r" style="color:' . self::darken($c) . ';">' . $m($v['net']['current']) . '</td><td class="r">' . $m($v['net']['ytd']) . '</td></tr>';
            $deductions = '<div class="sec" style="color:' . self::darken($c) . ';">Deductions</div><table class="grid" style="border-top-color:' . $c . ';"><thead><tr><th>Description</th><th class="r" style="width:22%;">This pay</th><th class="r" style="width:22%;">Year to date</th></tr></thead><tbody>' . $dr . '</tbody></table>';
        }

        // Employer contributions + leave balances side by side.
        $side = '';
        if ($v['employer_contrib']) {
            $x = '';
            foreach ($v['employer_contrib'] as $r) {
                $x .= '<tr><td>' . e($r['label']) . '</td><td class="r">' . $m($r['current']) . '</td><td class="r">' . $m($r['ytd']) . '</td></tr>';
            }
            $side .= '<td class="half"><div class="sec2">Employer contributions <span class="muted">(paid by your employer, not taken from your pay)</span></div>'
                . '<table class="grid sm"><thead><tr><th></th><th class="r">This pay</th><th class="r">Year to date</th></tr></thead><tbody>' . $x . '</tbody></table></td>';
        }
        if ($v['leave']) {
            $x = '';
            foreach ($v['leave'] as $r) {
                $x .= '<tr><td' . $nt . '>' . e($r['name']) . '</td><td class="r"' . $nt . '>' . $h($r['balance']) . ' ' . e(self::unit($r['unit'], (float) $r['balance'], $lang)) . '</td></tr>';
            }
            $side .= '<td class="half"><div class="sec2">Leave balances <span class="muted">(as of ' . e(self::day(\App\Support\AgencyTime::today((int) $a['agency_id']))) . ')</span></div>'
                . '<table class="grid sm"><thead><tr><th>Type</th><th class="r">Balance</th></tr></thead><tbody>' . $x . '</tbody></table></td>';
        }
        $side = $side ? '<table class="two"><tr>' . $side . '</tr></table>' : '';

        $remarks = '<div class="sec" style="color:' . self::darken($c) . ';">Remarks</div><div class="remarks">'
            // A white-label agency's people never see the KiddieTrac name (Brand::whiteLabel).
            . ($v['remarks'] ? implode('', array_map(fn ($r) => '<div class="rl">' . nl2br(e($a['white_label'] ? str_replace(['recorded in KiddieTrac', 'Drawn by KiddieTrac'], ['recorded', 'Drawn'], $r) : $r)) . '</div>', $v['remarks'])) : '<div class="rl muted">None</div>')
            . ($v['basis'] ? '<div class="rl muted">' . e($v['basis']) . '</div>' : '') . '</div>';

        $termsHtml = '<div class="terms"><div class="th"' . ($terms['custom'] ? '' : $nt) . '>' . e($w['terms']) . '</div><ol>'
            . implode('', array_map(fn ($l) => '<li' . ($terms['custom'] ? '' : $nt) . '>' . e($l) . '</li>', $terms['lines'])) . '</ol>'
            . '<div class="nn"' . $nt . '>' . e($w['cheque']) . '</div></div>';

        $tz = \App\Support\AgencyTime::tz((int) $a['agency_id']);
        $gen = Carbon::now($tz)->format('M j, Y g:i A T');
        $foot = '<div class="foot"><table><tr><td style="width:50%;">' . e($v['title']) . ($v['reference'] ? ' <span' . $nt . '>' . e($v['reference']) . '</span>' : '') . ' · Generated ' . e($gen)
            . ($a['white_label'] ? '' : ' · <span' . $nt . '>Prepared with KiddieTrac</span>') . '</td><td style="width:34%;text-align:center;font-weight:bold;color:#991B1B;"' . $nt . '>' . e($w['cheque']) . '</td><td></td></tr></table></div>';
        $wm = '<div class="wm"' . $nt . '>' . e($w['wm']) . '</div>';

        $font = $lang === 'hi' ? "'Noto Sans Devanagari', 'DejaVu Sans'" : "'DejaVu Sans'";
        $fontFace = $lang === 'hi' && ($f = self::devanagariFont())
            ? "@font-face{font-family:'Noto Sans Devanagari';font-weight:normal;src:url('" . $f . "') format('truetype');}"
                . "@font-face{font-family:'Noto Sans Devanagari';font-weight:bold;src:url('" . (self::devanagariFont('Bold') ?: $f) . "') format('truetype');}"
            : '';

        return '<html><head><meta charset="utf-8"><style>' . $fontFace
            . '@page{margin:34px 36px 58px 36px;}'
            . 'body{font-family:' . $font . ',sans-serif;color:#0F172A;font-size:8.5px;line-height:1.25;}'
            . '.wm{position:fixed;top:330px;left:-60px;width:680px;text-align:center;white-space:nowrap;font-size:68px;font-weight:bold;color:#64748B;opacity:.10;transform:rotate(-38deg);letter-spacing:3px;}'
            . '.band{margin:-34px -36px 0 -36px;padding:20px 40px 54px 40px;}'
            . '.bt{width:100%;border-collapse:collapse;}.bt td{vertical-align:top;padding:0;}'
            . '.an{font-size:21px;font-weight:bold;line-height:1.15;margin-bottom:5px;}.al{font-size:8.8px;line-height:1.4;}'
            . '.logo-cell{text-align:right;vertical-align:top;padding-left:14px !important;}'
            . '.logo-panel{background:#FFFFFF;border-radius:10px;margin-left:auto;}'
            . '.logo-panel img{display:block;}'
            . '.logo-init{font-weight:bold;text-align:center;letter-spacing:1px;}'
            . '.card{background:#FFFFFF;border:1px solid #E2E8F0;border-radius:12px;margin:-46px 4px 0 4px;padding:14px 16px 12px 16px;}'
            . '.cols{width:100%;border-collapse:collapse;}.cols td.c{vertical-align:top;padding:0 10px 0 0;}'
            . '.ch{font-size:8px;font-weight:bold;text-transform:uppercase;letter-spacing:.8px;color:#64748B;border-bottom:1px solid #E2E8F0;padding-bottom:3px;margin-bottom:4px;}'
            . 'table.kv{width:100%;border-collapse:collapse;}table.kv td{padding:1.6px 0;vertical-align:top;}table.kv td.k{font-size:7.4px;color:#64748B;width:38%;padding-right:5px;padding-top:2.4px;}table.kv td.v{font-size:8.4px;font-weight:bold;color:#0F172A;line-height:1.25;}'
            . '.mini{width:100%;border-collapse:collapse;}.mini td{padding:1.5px 0;font-size:9px;}.mini tr.s td{font-weight:bold;}'
            . '.netbox{margin-top:8px;border:2px solid;border-radius:9px;padding:6px 9px;text-align:center;}.netbox .nk{font-size:8px;text-transform:uppercase;letter-spacing:.8px;color:#64748B;font-weight:bold;}'
            . '.netbox .nv{font-size:19px;font-weight:bold;}'
            . '.r{text-align:right;}.muted{color:#64748B;font-weight:normal;}'
            . '.sec{font-size:10.5px;font-weight:bold;margin:9px 0 3px;}.sec2{font-size:9px;font-weight:bold;margin:0 0 3px;color:#334155;}'
            . 'table.grid{width:100%;border-collapse:collapse;border-top:2px solid;}'
            . 'table.grid th{font-size:7.4px;color:#64748B;text-transform:uppercase;letter-spacing:.5px;text-align:left;padding:3px 6px;background:#F8FAFC;border-bottom:1px solid #E2E8F0;}'
            . 'table.grid th.r{text-align:right;}table.grid td{padding:2px 6px;border-bottom:1px dotted #CBD5E1;}'
            . 'table.grid tr.tot td{font-weight:bold;border-top:1px solid #94A3B8;border-bottom:1px solid #E2E8F0;background:#F8FAFC;}'
            . 'table.grid tr.net td{font-weight:bold;font-size:11px;border-bottom:0;}'
            . 'table.sm td{padding:2px 6px;}'
            . '.two{width:100%;border-collapse:collapse;margin-top:9px;}.two td.half{vertical-align:top;width:50%;padding:0 6px 0 0;}'
            . '.note{border:1px solid #FDE68A;background:#FFFBEB;color:#92400E;border-radius:7px;padding:7px 9px;font-size:9px;}'
            . '.remarks{border-top:1px solid #CBD5E1;}.rl{padding:2.5px 0;border-bottom:1px solid #E2E8F0;}'
            . '.terms{page-break-inside:avoid;margin-top:9px;border:1px solid #E2E8F0;border-radius:8px;padding:7px 10px;background:#F8FAFC;font-size:7.6px;color:#334155;}'
            . '.terms .th{font-weight:bold;font-size:8px;text-transform:uppercase;letter-spacing:.6px;color:#64748B;margin-bottom:2px;}'
            . '.terms ol{margin:0 0 0 14px;padding:0;}.terms li{margin:0 0 1px;}'
            . '.nn{margin-top:5px;text-align:center;font-weight:bold;letter-spacing:1.2px;color:#991B1B;font-size:8.6px;}'
            . '.foot{position:fixed;bottom:-40px;left:0;right:0;height:22px;font-size:7.2px;color:#64748B;border-top:1px solid #E2E8F0;padding-top:4px;}'
            . '.foot table{width:100%;border-collapse:collapse;}.foot td{padding:0;}'
            . '</style></head><body>' . $foot . $band
            . '<div class="card"><table class="cols"><tr><td class="c" style="width:36%;">' . $col1 . '</td><td class="c" style="width:34%;">' . $col2 . '</td>'
            . '<td class="c" style="width:30%;padding-right:0;">' . $col3 . '</td></tr></table></div>'
            . $earnings . $deductions . $side . $remarks . $termsHtml . $wm
            // Devanagari: letter spacing pulls vowel signs off their letters.
            . ($lang === 'hi' ? '<style>.wm,.nn,.ch,.th,.netbox .nk,table.grid th{letter-spacing:0 !important;}body{line-height:1.1;}table.grid td,table.kv td,.mini td{padding-top:1px;padding-bottom:1px;}.band{padding-top:16px;padding-bottom:50px;}</style>' : '')
            . '</body></html>';
    }

    /** PDF bytes (page x of y comes from the footer's CSS counters). */
    /**
     * Dompdf lays glyphs out in typing order and does no Indic shaping, so the short-i sign (U+093F), typed after its
     * consonant cluster but written before it, would print after the letter. Moving it in front of the cluster gives the
     * written order. Half forms still show as letter + virama; the text reads correctly.
     */
    public static function devanagariOrder(string $s): string
    {
        $c = '[\x{0915}-\x{0939}\x{0958}-\x{095F}]\x{093C}?';

        return preg_replace('/((?:' . $c . '\x{094D})*' . $c . ')\x{093F}/u', "\u{093F}$1", $s) ?? $s;
    }

    public static function render(array $v, ?string $loc = null): string
    {
        $loc = $loc ?? MessageTranslator::pdfLocale();
        $lang = self::lang($loc);
        if ($lang === 'hi' && ! self::devanagariFont()) {
            $lang = 'en';
        }
        $pdf = new Dompdf(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans', 'chroot' => [storage_path('fonts'), resource_path('fonts'), base_path('vendor/dompdf/dompdf')]]);
        $html = self::html($v, $loc);
        $pdf->loadHtml($lang === 'hi' ? self::devanagariOrder($html) : $html);
        $pdf->setPaper('letter');
        $pdf->render();
        // Page x of y on every page. Drawn last, so the watermark's transparency is reset first.
        $label = $lang === 'hi' ? self::devanagariOrder(self::WORDS[$lang]['page']) : self::WORDS[$lang]['page'];
        $family = $lang === 'hi' ? 'Noto Sans Devanagari' : 'DejaVu Sans';
        $pdf->getCanvas()->page_script(function ($num, $count, $canvas, $fm) use ($label, $family) {
            $canvas->set_opacity(1.0);
            $t = str_replace(['{PAGE_NUM}', '{PAGE_COUNT}'], [(string) $num, (string) $count], $label);
            $font = $fm->getFont($family) ?: $fm->getFont('DejaVu Sans');
            $canvas->text($canvas->get_width() - 36 - $fm->getTextWidth($t, $font, 7.2), $canvas->get_height() - 29, $t, $font, 7.2, [0.39, 0.45, 0.55]);
        });

        return (string) $pdf->output();
    }

    public static function response(array $v, string $filename, string $disposition = 'inline')
    {
        return response(self::render($v), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition . '; filename="' . $filename . '"']);
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    private static function day(?string $d): string
    {
        $p = explode('-', substr((string) $d, 0, 10));

        return count($p) === 3 && checkdate((int) $p[1], (int) $p[2], (int) $p[0])
            ? Carbon::createFromDate((int) $p[0], (int) $p[1], (int) $p[2])->format('M j, Y') : (string) $d;
    }

    private static function phone(string $p): string
    {
        $d = preg_replace('/\D/', '', $p);
        if (strlen($d) === 11 && $d[0] === '1') {
            $d = substr($d, 1);
        }

        return strlen($d) === 10 ? '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6) : $p;
    }

    /** A readable shade of the brand colour for text on white (light brand colours like #44DFF3 are unreadable as text). */
    private static function darken(string $hex): string
    {
        $rgb = array_map(fn ($i) => hexdec(substr($hex, $i, 2)), [1, 3, 5]);
        $lum = (0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2]) / 255;
        if ($lum < 0.45) {
            return $hex;
        }
        $f = 0.45 / $lum;

        return sprintf('#%02X%02X%02X', ...array_map(fn ($v) => (int) round($v * $f * 0.8), $rgb));
    }

    /** LOGO PANEL (2026-11-27, Anthony: "make the logo area for the payslip better for logos such as iLearn's").
     *  A white rounded rectangle that takes the logo's own shape: a wide wordmark gets a wide panel (up to 220 x 80), a
     *  square or round mark a square one. The logo is contained, never cropped or stretched, with the same padding on
     *  every side. No logo -> the agency's initials in its colour. */
    private static function logoPanel(array $a): string
    {
        $pad = 10;
        $maxW = 220 - 2 * $pad;
        $maxH = 80 - 2 * $pad;
        $l = $a['logo'] ?? null;
        if (is_array($l) && ! empty($l['src']) && $l['w'] > 0 && $l['h'] > 0) {
            $r = $l['w'] / $l['h'];
            // Square-ish marks are drawn a little smaller than the full height so they do not dominate the band.
            [$w, $h] = $r >= $maxW / $maxH ? [$maxW, $maxW / $r] : [$maxH * $r, $maxH];
            if ($r < 1.25) {
                [$w, $h] = [$w * 0.92, $h * 0.92];
            }
            $w = round($w, 1);
            $h = round($h, 1);

            return '<td class="logo-cell" style="width:' . round($w + 2 * $pad + 14) . 'px;"><div class="logo-panel" style="width:' . $w . 'px;height:' . $h
                . 'px;padding:' . $pad . 'px;"><img src="' . $l['src'] . '" style="width:' . $w . 'px;height:' . $h . 'px;"></div></td>';
        }
        $ini = (string) ($a['initials'] ?? '');
        if ($ini === '') {
            return '<td></td>';
        }

        return '<td class="logo-cell" style="width:84px;"><div class="logo-panel" style="width:56px;height:56px;padding:7px;">'
            . '<div class="logo-init" data-kt-no-translate style="color:' . e($a['colour']) . ';font-size:' . ($fs = mb_strlen($ini) > 2 ? 18 : 22) . 'px;line-height:1;padding-top:' . round((56 - $fs) / 2 - 1) . 'px;">'
            . e($ini) . '</div></div></td>';
    }

    /** Up to three initials from the agency name ("iLearn Home Childcare Inc" -> "IHC"); legal words are skipped. */
    private static function initials(string $name): string
    {
        $out = '';
        foreach (preg_split('/[\s\-&]+/u', trim($name)) ?: [] as $w) {
            if ($w === '' || preg_match('/^(inc|ltd|llc|corp|co|the|of|and)\.?$/i', $w)) {
                continue;
            }
            $out .= mb_strtoupper(mb_substr($w, 0, 1));
            if (mb_strlen($out) >= 3) {
                break;
            }
        }

        return $out;
    }

    /** The logo, ready for print: ['src' => data: URI, 'w' => px, 'h' => px] or null. Dompdf never fetches.
     *  Our own /storage files are read from disk (the ORIGINAL upload, not a thumbnail). The picture is flattened onto
     *  white (so transparent PNGs print cleanly), the empty margin around the mark is trimmed (so a wordmark saved on a
     *  big canvas still fills its panel), SVG is rasterised when Imagick can, and anything over 1200 px is scaled down. */
    private static function logo(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        try {
            $path = null;
            if (preg_match('#/storage/(.+)$#', (string) parse_url($url, PHP_URL_PATH), $mm) && ! str_contains($mm[1], '..')) {
                foreach ([storage_path('app/public/' . $mm[1]), public_path('storage/' . $mm[1])] as $p) {
                    if (is_file($p)) {
                        $path = $p;
                        break;
                    }
                }
            }
            if (! $path && preg_match('#^https://app\.kiddietrac\.com/([A-Za-z0-9_\-./]+)$#', $url, $mm) && ! str_contains($mm[1], '..')) {
                $p = base_path('../parent-portal/' . $mm[1]);
                if (is_file($p)) {
                    $path = $p;
                }
            }
            if ($path) {
                $data = (string) file_get_contents($path);
            } elseif (preg_match('#^https://#i', $url)) {
                $res = \Illuminate\Support\Facades\Http::timeout(5)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->get($url);
                $data = $res->successful() ? (string) $res->body() : '';
            } else {
                return null;
            }
            if ($data === '' || strlen($data) > 6000000) {
                return null;
            }
            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
            $svg = in_array($type, ['image/svg+xml', 'image/svg'], true) || ($type === 'text/xml' && str_contains($data, '<svg'));
            if (! $svg && ! in_array($type, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
                return null;
            }
            if (class_exists(\Imagick::class)) {
                try {
                    $im = new \Imagick();
                    if ($svg) {
                        $im->setResolution(300, 300);
                    }
                    $im->readImageBlob($data);
                    $im->setIteratorIndex(0);
                    $im = $im->getImage();
                    // Trim only what is truly empty: clear pixels first, then near-pure white (2%), never a light colour
                    // that belongs to the mark (a pale gradient at the top of an icon is part of it).
                    $trimmed = function (\Imagick $x, float $fuzz): \Imagick {
                        $t = clone $x;
                        $t->trimImage($fuzz * \Imagick::getQuantum());
                        $t->setImagePage(0, 0, 0, 0);

                        return $t->getImageWidth() >= 8 && $t->getImageHeight() >= 8 ? $t : $x;
                    };
                    if ($im->getImageAlphaChannel()) {
                        $im = $trimmed($im, 0.0);
                    }
                    $im->setImageBackgroundColor('white');
                    $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
                    $im->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
                    $im = $trimmed($im, 0.02);
                    if ($im->getImageWidth() > 1200 || $im->getImageHeight() > 1200) {
                        $im->thumbnailImage(1200, 1200, true);
                    }
                    $im->setImageFormat('png');
                    $out = $im->getImageBlob();

                    return ['src' => 'data:image/png;base64,' . base64_encode($out), 'w' => $im->getImageWidth(), 'h' => $im->getImageHeight()];
                } catch (\Throwable $e) {
                    if ($svg) {
                        return null;
                    }
                }
            }
            if ($svg || $type === 'image/webp') {
                return null;
            }
            $sz = @getimagesizefromstring($data);
            if (! $sz || $sz[0] < 1 || $sz[1] < 1) {
                return null;
            }

            return ['src' => 'data:' . $type . ';base64,' . base64_encode($data), 'w' => (int) $sz[0], 'h' => (int) $sz[1]];
        } catch (\Throwable $e) {
            return null;
        }
    }
}
