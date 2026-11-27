<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\TestAgency;
use Carbon\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 3 (2026-11-27): Canadian year end - T4, T4A and RL-1 slips, their summaries and the
 * reconciliation that has to pass before they can be finalized.
 *
 * WHERE THE NUMBERS COME FROM (sources(), and nowhere else):
 *  1. finalized engine pay runs: every included line whose payslip was written, by PAY DATE in the tax year (CRA: income
 *     is reported in the year it is paid);
 *  2. the person's opening year-to-date balance for the year (payroll_ytd_openings: what a previous payroll paid them);
 *  3. payslips that arrived from an integration (iLearn) WITH deductions on them: not ours (payroll_run_id null, source not
 *     engine), dated after the opening balance's as-of date - the same rule PayRuns::ytd() uses, so a person paid both ways
 *     is never counted twice. Gross-only payslips (no CPP / EI / tax recorded) never reach a T4.
 *  T4A box 048 (fees for services): payments to people marked as contractors (users.is_contractor) that did not go through
 *  the engine (integration / manual payslips and invoices), when the year's total is over $500 or tax was withheld (CRA's
 *  threshold). A contractor's documents never also reach a T4.
 *
 * BOXES (CRA RC4120 / T4 XML 1-27-1; Revenu Quebec RL-1.G-V): T4 14 employment income (gross: wages, vacation pay,
 * bonuses, taxable benefits), 16 / 16A CPP + CPP2 (outside Quebec) or 17 / 17A QPP + QPP2 (Quebec), 18 EI, 22 income tax
 * (Quebec: federal tax only - the Quebec tax goes on the RL-1), 24 EI insurable earnings (to the yearly maximum),
 * 26 CPP / QPP pensionable earnings (to the second ceiling, YAMPE), 55 / 56 PPIP premiums and insurable earnings (Quebec),
 * 10 province of employment, 28 exemptions (1 = exempt the whole year), 45 dental (agency default, per slip override),
 * 44 / 46 / 52 / 20 / 50 typed by an admin when they apply; other information code 40 = taxable benefits (also in box 14).
 * One T4 per province of employment. RL-1 (Quebec employees): A, B.A (QPP), B.B (QPP2), C (EI), E (Quebec tax),
 * G (QPP pensionable salary), H (QPIP), I (QPIP insurable salary).
 *
 * RECONCILIATION (reconcile()): the slips must equal (a) the payslips themselves (payroll_documents, independently of the
 * run lines), (b) the PD7A remittance report (Remittance::report, CRA and Revenu Quebec totals) for the engine share, and
 * (c) the opening balances + integration payslips counted exactly once. Anything more than $0.01 apart, any unfinished
 * run with a pay date in the year, a tax year that has not ended, a missing CRA payroll account or dental code = finalize
 * is refused.
 *
 * SIN: never stored on a slip; read from hr_staff_sin (Crypt) only by sinFor(), which only the slip PDF for the person /
 * an agency admin and the XML builder call. Everywhere else shows hr_staff_sin.sin_hint.
 */
final class YearEnd
{
    public const KINDS = ['T4' => 'T4 Statement of Remuneration Paid', 'T4A' => 'T4A Statement of Pension, Retirement, Annuity, and Other Income',
        'RL1' => 'RL-1 Employment and Other Income (Revenu Québec)'];

    public const DENTAL = [1 => 'Not eligible to access any dental care insurance or coverage of dental services of any kind',
        2 => 'Payee only', 3 => 'Payee, spouse and dependent children', 4 => 'Payee and their spouse', 5 => 'Payee and their dependent children'];

    /** Boxes an admin may type on a draft slip (they have no source in KiddieTrac payroll). */
    public const MANUAL = ['20' => 'RPP contributions', '44' => 'Union dues', '46' => 'Charitable donations', '50' => 'RPP or DPSP registration number',
        '52' => 'Pension adjustment', '45' => 'Employer-offered dental benefits', '29' => 'Employment code', 'rcpnt_bn' => 'Recipient business number (T4A)'];

    public const T4A_THRESHOLD = 500.0;

    // ---- Dates, settings ----------------------------------------------------------------------------------------------

    /** The filing deadline for a tax year: the last day of February of the next year (Feb 28, or 29 in a leap year). */
    public static function deadline(int $year): string
    {
        return Carbon::create($year + 1, 3, 1)->subDay()->toDateString();
    }

    public static function settings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $y = is_array($s['payroll_engine']['year_end'] ?? null) ? $s['payroll_engine']['year_end'] : [];

        return ['dental_code' => isset($y['dental_code']) && isset(self::DENTAL[(int) $y['dental_code']]) ? (int) $y['dental_code'] : null,
            'contact_name' => $y['contact_name'] ?? null, 'contact_phone' => $y['contact_phone'] ?? null, 'contact_email' => $y['contact_email'] ?? null];
    }

    public static function saveSettings(int $agencyId, array $in): array
    {
        $cur = self::settings($agencyId);
        PayrollEmployer::save($agencyId, ['year_end' => array_replace($cur, array_intersect_key($in, $cur))]);

        return self::settings($agencyId);
    }

    /** Has the tax year ended in the agency's own time zone? */
    public static function yearEnded(int $agencyId, int $year): bool
    {
        return AgencyTime::today($agencyId) > $year . '-12-31';
    }

    public static function caps(int $year): array
    {
        $t = TaxTables::forDate('CA', $year . '-12-31') ?: [];

        return ['ei' => (float) ($t['ei']['max_insurable'] ?? 0), 'cpp' => (float) ($t['cpp']['yampe'] ?? 0), 'qpp' => (float) ($t['qpp']['yampe'] ?? 0),
            'qpip' => (float) ($t['qpip']['max_insurable'] ?? 0), 'version' => $t['version'] ?? null];
    }

    // ---- Sources ------------------------------------------------------------------------------------------------------

    /**
     * Every dollar for the year, per person and province, from the three allowed places (+ contractor fees for T4As).
     * @return array{t4: array<string, array>, t4a: array<int, array>, docs: array, openings: array, integration: array}
     */
    public static function sources(int $agencyId, int $year): array
    {
        $r2 = fn ($v) => round((float) $v, 2);
        $contractors = DB::table('users')->where('is_contractor', 1)
            ->whereIn('id', DB::table('role_assignments')->where('agency_id', $agencyId)->pluck('user_id')->all() ?: [0])
            ->pluck('id')->map(fn ($v) => (int) $v)->flip()->all();
        $t4 = [];
        $slot = function (int $uid, string $j) use (&$t4) {
            $k = $uid . ':' . $j;
            $t4[$k] ??= ['user_id' => $uid, 'jurisdiction' => $j, 'gross' => 0.0, 'pensionable' => 0.0, 'insurable' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0,
                'qpip' => 0.0, 'federal_tax' => 0.0, 'provincial_tax' => 0.0, 'income_tax' => 0.0, 'benefits' => 0.0,
                'employer' => ['cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'qpip' => 0.0], 'cpp_exempt_all' => true, 'ei_exempt_all' => true, 'qpip_exempt_all' => true,
                'engine' => ['lines' => 0, 'gross' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'income_tax' => 0.0, 'federal_tax' => 0.0, 'provincial_tax' => 0.0, 'qpip' => 0.0,
                    'employer_cpp' => 0.0, 'employer_ei' => 0.0, 'employer_qpip' => 0.0],
                'opening' => null, 'integration' => ['docs' => [], 'gross' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'income_tax' => 0.0], 'centres' => []];

            return $k;
        };

        // 1. Finalized engine lines (pay date in the year).
        $lines = DB::table('payroll_run_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.agency_id', $agencyId)->where('r.status', 'finalized')->where('l.included', 1)->whereNotNull('l.payroll_document_id')
            ->whereRaw('YEAR(r.pay_date) = ?', [$year])->orderBy('r.pay_date')
            ->get(['l.id', 'l.user_id', 'l.centre_id', 'l.jurisdiction', 'l.gross', 'l.cpp', 'l.cpp2', 'l.ei', 'l.qpip', 'l.federal_tax', 'l.provincial_tax', 'l.income_tax',
                'l.benefits_cash', 'l.benefits_noncash', 'l.employer_cpp', 'l.employer_cpp2', 'l.employer_ei', 'l.employer_qpip', 'l.inputs']);
        foreach ($lines as $l) {
            $j = (string) ($l->jurisdiction ?: 'ON');
            $k = $slot((int) $l->user_id, $j);
            $s = &$t4[$k];
            $in = json_decode((string) $l->inputs, true) ?: [];
            $g = (float) $l->gross;
            $s['gross'] += $g;
            if (empty($in['cpp_exempt'])) {
                $s['pensionable'] += $g;
                $s['cpp_exempt_all'] = false;
            }
            if (empty($in['ei_exempt'])) {
                $s['insurable'] += $g - (float) $l->benefits_noncash;   // non-cash benefits are not insurable
                $s['ei_exempt_all'] = false;
            }
            if (empty($in['qpip_exempt'])) {
                $s['qpip_exempt_all'] = false;
            }
            foreach (['cpp', 'cpp2', 'ei', 'qpip', 'federal_tax', 'provincial_tax', 'income_tax'] as $f) {
                $s[$f] += (float) $l->$f;
            }
            $s['benefits'] += (float) $l->benefits_cash + (float) $l->benefits_noncash;
            foreach (['cpp', 'cpp2', 'ei', 'qpip'] as $f) {
                $s['employer'][$f] += (float) $l->{'employer_' . $f};
            }
            $e = &$s['engine'];
            $e['lines']++;
            $e['gross'] += $g;
            $e['cpp'] += (float) $l->cpp + (float) $l->cpp2;
            $e['ei'] += (float) $l->ei;
            $e['qpip'] += (float) $l->qpip;
            $e['income_tax'] += (float) $l->income_tax;
            $e['federal_tax'] += (float) $l->federal_tax;
            $e['provincial_tax'] += (float) $l->provincial_tax;
            $e['employer_cpp'] += (float) $l->employer_cpp + (float) $l->employer_cpp2;
            $e['employer_ei'] += (float) $l->employer_ei;
            $e['employer_qpip'] += (float) $l->employer_qpip;
            if ($l->centre_id) {
                $s['centres'][(int) $l->centre_id] = ($s['centres'][(int) $l->centre_id] ?? 0) + $g;
            }
            unset($s, $e);
        }

        // 2. Opening balances. 3. Integration payslips with deductions (after the opening's as-of date).
        $openings = DB::table('payroll_ytd_openings')->where('agency_id', $agencyId)->where('tax_year', $year)->get()->keyBy('user_id');
        $profiles = [];
        $prof = function (int $uid) use (&$profiles, $agencyId) {
            return $profiles[$uid] ??= PayrollTax::profile($agencyId, $uid);
        };
        $usedOpenings = [];
        foreach ($openings as $uid => $o) {
            $uid = (int) $uid;
            if (isset($contractors[$uid])) {
                continue;   // an opening balance is employment income: contractors never have one (shown as a warning)
            }
            $p = $prof($uid);
            $k = $slot($uid, (string) ($p['jurisdiction'] ?: 'ON'));
            $s = &$t4[$k];
            $s['gross'] += (float) $o->gross;
            $s['pensionable'] += (float) $o->pensionable;
            $s['insurable'] += (float) $o->insurable;
            $s['cpp'] += (float) $o->cpp;
            $s['cpp2'] += (float) $o->cpp2;
            $s['ei'] += (float) $o->ei;
            $s['qpip'] += (float) $o->qpip;
            $s['income_tax'] += (float) $o->income_tax;
            // An opening balance has no federal / provincial split: outside Quebec it is all box 22; in Quebec the whole
            // amount cannot be split, so it is reported as federal and flagged (see warnings()).
            $s['federal_tax'] += (float) $o->income_tax;
            if ((float) $o->cpp > 0 || (float) $o->pensionable > 0) {
                $s['cpp_exempt_all'] = false;
            }
            if ((float) $o->ei > 0 || (float) $o->insurable > 0) {
                $s['ei_exempt_all'] = false;
            }
            $s['opening'] = ['id' => (int) $o->id, 'as_of' => (string) $o->as_of, 'gross' => (float) $o->gross, 'cpp' => round((float) $o->cpp + (float) $o->cpp2, 2),
                'ei' => (float) $o->ei, 'income_tax' => (float) $o->income_tax, 'qpip' => (float) $o->qpip];
            $usedOpenings[] = (int) $o->id;
            unset($s);
        }
        $docs = DB::table('payroll_documents')->where('agency_id', $agencyId)->where('kind', 'payslip')
            ->whereNull('payroll_run_id')->where('source', '!=', 'engine')
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
            ->where(fn ($w) => $w->whereNotNull('cpp')->orWhereNotNull('ei')->orWhereNotNull('income_tax'))
            ->whereRaw('LEFT(COALESCE(period_end, period_start), 4) = ?', [(string) $year])
            ->get(['id', 'user_id', 'centre_id', 'period_start', 'period_end', 'gross', 'vacation_pay', 'cpp', 'ei', 'income_tax']);
        $integration = [];
        foreach ($docs as $d) {
            $uid = (int) $d->user_id;
            if (! $uid || isset($contractors[$uid])) {
                continue;   // contractors: T4A below
            }
            $o = $openings[$uid] ?? null;
            if ($o && (string) ($d->period_end ?: $d->period_start) <= (string) $o->as_of) {
                continue;   // already inside the opening balance
            }
            $p = $prof($uid);
            $k = $slot($uid, (string) ($p['jurisdiction'] ?: 'ON'));
            $s = &$t4[$k];
            $g = (float) $d->gross + (float) $d->vacation_pay;   // an iLearn payslip's gross leaves vacation pay out
            $s['gross'] += $g;
            if (! $p['cpp_exempt_reason']) {
                $s['pensionable'] += $g;
                $s['cpp_exempt_all'] = false;
            }
            if (! $p['ei_exempt']) {
                $s['insurable'] += $g;
                $s['ei_exempt_all'] = false;
            }
            $s['cpp'] += (float) $d->cpp;
            $s['ei'] += (float) $d->ei;
            $s['income_tax'] += (float) $d->income_tax;
            $s['federal_tax'] += (float) $d->income_tax;
            $s['integration']['docs'][] = (int) $d->id;
            $s['integration']['gross'] += $g;
            $s['integration']['cpp'] += (float) $d->cpp;
            $s['integration']['ei'] += (float) $d->ei;
            $s['integration']['income_tax'] += (float) $d->income_tax;
            if ($d->centre_id) {
                $s['centres'][(int) $d->centre_id] = ($s['centres'][(int) $d->centre_id] ?? 0) + $g;
            }
            $integration[] = (int) $d->id;
            unset($s);
        }

        // T4A: contractor payments that did not go through the engine.
        $t4a = [];
        if ($contractors) {
            $cd = DB::table('payroll_documents')->where('agency_id', $agencyId)->whereIn('user_id', array_keys($contractors))
                ->whereIn('kind', ['payslip', 'invoice'])->whereNull('payroll_run_id')->where('source', '!=', 'engine')
                ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
                ->whereRaw('LEFT(COALESCE(period_end, period_start), 4) = ?', [(string) $year])
                ->get(['id', 'user_id', 'centre_id', 'gross', 'vacation_pay', 'income_tax']);
            foreach ($cd as $d) {
                $uid = (int) $d->user_id;
                $t4a[$uid] ??= ['user_id' => $uid, 'fees' => 0.0, 'income_tax' => 0.0, 'docs' => [], 'centres' => []];
                $g = (float) $d->gross + (float) $d->vacation_pay;
                $t4a[$uid]['fees'] += $g;
                $t4a[$uid]['income_tax'] += (float) $d->income_tax;
                $t4a[$uid]['docs'][] = (int) $d->id;
                if ($d->centre_id) {
                    $t4a[$uid]['centres'][(int) $d->centre_id] = ($t4a[$uid]['centres'][(int) $d->centre_id] ?? 0) + $g;
                }
            }
        }
        foreach ($t4 as &$s) {
            foreach ($s as $f => $v) {
                if (is_float($v)) {
                    $s[$f] = $r2($v);
                }
            }
            foreach (['employer', 'engine', 'integration'] as $g) {
                foreach ($s[$g] as $f => $v) {
                    if (is_float($v)) {
                        $s[$g][$f] = $r2($v);
                    }
                }
            }
        }
        unset($s);
        foreach ($t4a as &$a) {
            $a['fees'] = $r2($a['fees']);
            $a['income_tax'] = $r2($a['income_tax']);
        }
        unset($a);

        return ['t4' => $t4, 't4a' => $t4a, 'openings' => $usedOpenings, 'integration' => $integration,
            'contractor_openings' => array_values(array_intersect(array_map('intval', $openings->keys()->all()), array_keys($contractors)))];
    }

    // ---- Building slips -------------------------------------------------------------------------------------------------

    private static function topCentre(array $by): ?int
    {
        if (! $by) {
            return null;
        }
        arsort($by);

        return (int) array_key_first($by);
    }

    /** The boxes of a T4 (and RL-1 for Quebec) from one person's source bucket. */
    public static function t4Boxes(array $s, array $caps, ?int $dental): array
    {
        $qc = $s['jurisdiction'] === 'QC';
        $b = ['14' => $s['gross'], '18' => $s['ei'], '22' => $qc ? $s['federal_tax'] : $s['income_tax'],
            '24' => $s['ei_exempt_all'] ? 0.0 : round(min($s['insurable'], $caps['ei']), 2),
            '26' => $s['cpp_exempt_all'] ? 0.0 : round(min($s['pensionable'], $qc ? $caps['qpp'] : $caps['cpp']), 2)];
        if ($qc) {
            $b['17'] = $s['cpp'];
            $b['17A'] = $s['cpp2'];
            $b['55'] = $s['qpip'];
            $b['56'] = $s['qpip_exempt_all'] ? 0.0 : round(min($s['insurable'], $caps['qpip']), 2);
        } else {
            $b['16'] = $s['cpp'];
            $b['16A'] = $s['cpp2'];
        }
        $codes = ['10' => $s['jurisdiction'], '28' => ['cpp' => $s['cpp_exempt_all'] ? 1 : 0, 'ei' => $s['ei_exempt_all'] ? 1 : 0] + ($qc ? ['ppip' => $s['qpip_exempt_all'] ? 1 : 0] : []),
            '45' => $dental];
        $other = $s['benefits'] > 0 ? [['code' => '40', 'amount' => $s['benefits']]] : [];
        $rl1 = null;
        if ($qc) {
            $rl1 = ['A' => $s['gross'], 'B.A' => $s['cpp'], 'B.B' => $s['cpp2'], 'C' => $s['ei'], 'E' => $s['provincial_tax'],
                'G' => $b['26'], 'H' => $s['qpip'], 'I' => $b['56']];
        }

        return ['boxes' => $b, 'codes' => $codes, 'other' => $other, 'rl1' => $rl1];
    }

    private static function names(array $ids): array
    {
        return DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])->keyBy('id')->all();
    }

    /** The source part kept on a slip (what the reconciliation re-checks). */
    private static function slipSources(array $s): array
    {
        return ['engine' => $s['engine'], 'opening' => $s['opening'], 'integration' => $s['integration'],
            'tax' => ['federal' => $s['federal_tax'], 'provincial' => $s['provincial_tax'], 'total' => $s['income_tax']]];
    }

    /**
     * (Re)generate the year's DRAFT original slips from the sources. Refused once the year has final slips (amend those
     * instead). Typed boxes (union dues, donations, dental overrides, recipient BN) on earlier drafts are kept.
     */
    public static function generate(int $agencyId, int $year, int $actorId): array
    {
        abort_if(DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->whereIn('status', ['final', 'replaced', 'cancelled'])->exists(), 422,
            'The ' . $year . ' slips are already finalized. Amend or cancel a slip instead.');
        $src = self::sources($agencyId, $year);
        $caps = self::caps($year);
        $set = self::settings($agencyId);
        $made = ['T4' => 0, 'T4A' => 0, 'RL1' => 0];
        DB::transaction(function () use ($agencyId, $year, $actorId, $src, $caps, $set, &$made) {
            $old = DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->where('status', 'draft')->lockForUpdate()->get();
            $manual = [];
            foreach ($old as $o) {
                $manual[$o->kind . ':' . $o->user_id . ':' . $o->jurisdiction] = json_decode((string) $o->manual, true) ?: [];
            }
            DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->where('status', 'draft')->delete();
            $ids = array_merge(array_column($src['t4'], 'user_id'), array_keys($src['t4a']));
            $names = self::names($ids);
            $now = now();
            foreach ($src['t4'] as $s) {
                if ($s['gross'] <= 0 && $s['income_tax'] <= 0) {
                    continue;
                }
                $u = $names[$s['user_id']] ?? null;
                $x = self::t4Boxes($s, $caps, $set['dental_code']);
                $row = ['agency_id' => $agencyId, 'tax_year' => $year, 'country' => 'CA', 'user_id' => $s['user_id'], 'centre_id' => self::topCentre($s['centres']),
                    'status' => 'draft', 'report_type' => 'O', 'version' => 1, 'surname' => $u->last_name ?? null, 'given_name' => $u->first_name ?? null,
                    'employer' => json_encode(array_map(fn ($v) => round((float) $v, 2), $s['employer'])), 'sources' => json_encode(self::slipSources($s)),
                    'generated_by_id' => $actorId, 'created_at' => $now, 'updated_at' => $now];
                $m = $manual['T4:' . $s['user_id'] . ':' . $s['jurisdiction']] ?? [];
                [$boxes, $codes] = self::applyManual($x['boxes'], $x['codes'], $m);
                DB::table('payroll_slips')->insert($row + ['kind' => 'T4', 'jurisdiction' => $s['jurisdiction'], 'boxes' => json_encode($boxes), 'codes' => json_encode($codes),
                    'other_info' => json_encode($x['other']), 'manual' => json_encode($m ?: (object) []), 'warnings' => json_encode(self::warnings($agencyId, 'T4', $s, $boxes, $codes))]);
                $made['T4']++;
                if ($x['rl1']) {
                    $m2 = $manual['RL1:' . $s['user_id'] . ':QC'] ?? [];
                    DB::table('payroll_slips')->insert($row + ['kind' => 'RL1', 'jurisdiction' => 'QC', 'boxes' => json_encode($x['rl1']), 'codes' => json_encode(['45' => $codes['45']]),
                        'other_info' => json_encode([]), 'manual' => json_encode($m2 ?: (object) []), 'warnings' => json_encode(self::warnings($agencyId, 'RL1', $s, $x['rl1'], []))]);
                    $made['RL1']++;
                }
            }
            foreach ($src['t4a'] as $a) {
                if ($a['fees'] <= self::T4A_THRESHOLD && $a['income_tax'] <= 0) {
                    continue;   // CRA: a T4A for fees is needed over $500 (or when tax was deducted)
                }
                $u = $names[$a['user_id']] ?? null;
                $m = $manual['T4A:' . $a['user_id'] . ':'] ?? [];
                $boxes = ['048' => $a['fees'], '022' => $a['income_tax']];
                DB::table('payroll_slips')->insert(['agency_id' => $agencyId, 'tax_year' => $year, 'country' => 'CA', 'kind' => 'T4A', 'user_id' => $a['user_id'], 'jurisdiction' => null,
                    'centre_id' => self::topCentre($a['centres']), 'status' => 'draft', 'report_type' => 'O', 'version' => 1, 'surname' => $u->last_name ?? null, 'given_name' => $u->first_name ?? null,
                    'boxes' => json_encode($boxes), 'codes' => json_encode([]), 'other_info' => json_encode([]), 'manual' => json_encode($m ?: (object) []), 'employer' => json_encode([]),
                    'sources' => json_encode(['contractor_docs' => $a['docs'], 'fees' => $a['fees'], 'income_tax' => $a['income_tax']]),
                    'warnings' => json_encode(self::warnings($agencyId, 'T4A', ['user_id' => $a['user_id']], $boxes, [])),
                    'generated_by_id' => $actorId, 'created_at' => $now, 'updated_at' => $now]);
                $made['T4A']++;
            }
            DB::table('payroll_year_ends')->updateOrInsert(['agency_id' => $agencyId, 'tax_year' => $year],
                ['status' => 'draft', 'generated_by_id' => $actorId, 'generated_at' => $now, 'updated_at' => $now]);
            DB::table('payroll_year_ends')->where('agency_id', $agencyId)->where('tax_year', $year)->whereNull('created_at')->update(['created_at' => $now]);
        });
        $rec = self::reconcile($agencyId, $year);
        DB::table('payroll_year_ends')->where('agency_id', $agencyId)->where('tax_year', $year)->update(['reconciliation' => json_encode($rec)]);

        return ['made' => $made, 'reconciliation' => $rec];
    }

    /** Typed boxes over the generated ones (union dues 44, donations 46, PA 52, RPP 20 / 50, dental 45, employment code 29). */
    public static function applyManual(array $boxes, array $codes, array $m): array
    {
        foreach (['20', '44', '46', '52'] as $k) {
            if (isset($m[$k]) && $m[$k] !== null && $m[$k] !== '') {
                $boxes[$k] = round((float) $m[$k], 2);
            }
        }
        if (! empty($m['45'])) {
            $codes['45'] = (int) $m['45'];
        }
        if (! empty($m['29'])) {
            $codes['29'] = (int) $m['29'];
        }
        if (! empty($m['50'])) {
            $codes['50'] = (string) $m['50'];
        }

        return [$boxes, $codes];
    }

    private static function warnings(int $agencyId, string $kind, array $s, array $boxes, array $codes): array
    {
        $w = [];
        $uid = (int) $s['user_id'];
        if (! DB::table('hr_staff_sin')->where('agency_id', $agencyId)->where('user_id', $uid)->exists()) {
            $w[] = 'No SIN on file: the slip and the CRA file carry 000000000 until it is added in the HR file (CRA can charge a penalty for a missing SIN).';
        }
        if ($kind === 'T4' && ($s['jurisdiction'] ?? '') === 'QC' && ! empty($s['opening']) && (float) $s['opening']['income_tax'] > 0) {
            $w[] = 'The opening balance\'s income tax has no federal / Quebec split: it is all reported in box 22. Check it against the previous payroll\'s records.';
        }
        if ($kind === 'T4' && ($codes['45'] ?? null) === null) {
            $w[] = 'Box 45 (dental benefits) is required: set the agency default in the year-end settings or on this slip.';
        }
        if ($kind === 'T4' && ($boxes['14'] ?? 0) > 0 && ($boxes['16'] ?? $boxes['17'] ?? 0) <= 0 && ($codes['28']['cpp'] ?? 0) === 0 && ($s['pensionable'] ?? 0) > 3500) {
            $w[] = 'No CPP / QPP was deducted on pensionable earnings: check the tax setup (date of birth, exemptions).';
        }

        return $w;
    }

    // ---- The set in force -----------------------------------------------------------------------------------------------

    /**
     * The slips in force for each person / kind / province: the newest final slip of each chain (cancelled = none), plus
     * pending drafts when $withDrafts (what finalizing would leave).
     */
    public static function effective(int $agencyId, int $year, bool $withDrafts = true): array
    {
        $rows = DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->whereIn('status', $withDrafts ? ['draft', 'final'] : ['final'])
            ->orderBy('version')->get();
        $out = [];
        foreach ($rows as $r) {
            $out[$r->kind . ':' . $r->user_id . ':' . $r->jurisdiction] = $r;   // the higher version wins
        }

        return array_values(array_filter($out, fn ($r) => $r->report_type !== 'C'));
    }

    // ---- Reconciliation -----------------------------------------------------------------------------------------------

    /**
     * Slips vs payslips vs PD7A remittances vs openings / integration. ok = every line within $0.01 and no blocker.
     * Lines: [{key, label, slips, check, against, diff, ok}].
     */
    public static function reconcile(int $agencyId, int $year): array
    {
        $r2 = fn ($v) => round((float) $v, 2);
        $slips = self::effective($agencyId, $year, true);
        $src = self::sources($agencyId, $year);
        $sum = ['gross' => 0.0, 'cpp' => 0.0, 'ei' => 0.0, 'tax' => 0.0, 'qpip' => 0.0, 'e_gross' => 0.0, 'e_cpp' => 0.0, 'e_ei' => 0.0, 'e_tax' => 0.0, 'e_qpip' => 0.0,
            'cra' => 0.0, 'rq' => 0.0, 'o_gross' => 0.0, 'i_gross' => 0.0, 'fees' => 0.0];
        $seenDocs = [];
        $dupes = [];
        $slipTotals = ['T4' => 0, 'T4A' => 0, 'RL1' => 0];
        foreach ($slips as $s) {
            $b = json_decode((string) $s->boxes, true) ?: [];
            $so = json_decode((string) $s->sources, true) ?: [];
            $slipTotals[$s->kind]++;
            if ($s->kind === 'T4A') {
                $sum['fees'] += (float) ($b['048'] ?? 0);
                foreach ($so['contractor_docs'] ?? [] as $d) {
                    isset($seenDocs[$d]) ? $dupes[] = $d : $seenDocs[$d] = true;
                }
                continue;
            }
            if ($s->kind === 'RL1') {
                $sum['tax'] += (float) ($b['E'] ?? 0);   // the Quebec tax; its T4 carries the federal part in box 22
                continue;
            }
            $qc = $s->jurisdiction === 'QC';
            $sum['gross'] += (float) ($b['14'] ?? 0);
            $sum['cpp'] += $qc ? (float) ($b['17'] ?? 0) + (float) ($b['17A'] ?? 0) : (float) ($b['16'] ?? 0) + (float) ($b['16A'] ?? 0);
            $sum['ei'] += (float) ($b['18'] ?? 0);
            $sum['tax'] += (float) ($b['22'] ?? 0);
            $sum['qpip'] += (float) ($b['55'] ?? 0);
            $e = $so['engine'] ?? [];
            $sum['e_gross'] += (float) ($e['gross'] ?? 0);
            $sum['e_cpp'] += (float) ($e['cpp'] ?? 0);
            $sum['e_ei'] += (float) ($e['ei'] ?? 0);
            $sum['e_tax'] += (float) ($e['income_tax'] ?? 0);
            $sum['e_qpip'] += (float) ($e['qpip'] ?? 0);
            // What this payroll remitted for this person (PD7A): CRA = CPP both sides (outside Quebec) + EI both sides + tax
            // (Quebec: federal only); Revenu Quebec = QPP both sides + QPIP both sides + Quebec tax.
            if ($qc) {
                $sum['cra'] += (float) ($e['ei'] ?? 0) + (float) ($e['employer_ei'] ?? 0) + (float) ($e['federal_tax'] ?? 0);
                $sum['rq'] += (float) ($e['cpp'] ?? 0) + (float) ($e['employer_cpp'] ?? 0) + (float) ($e['qpip'] ?? 0) + (float) ($e['employer_qpip'] ?? 0) + (float) ($e['provincial_tax'] ?? 0);
            } else {
                $sum['cra'] += (float) ($e['cpp'] ?? 0) + (float) ($e['employer_cpp'] ?? 0) + (float) ($e['ei'] ?? 0) + (float) ($e['employer_ei'] ?? 0) + (float) ($e['income_tax'] ?? 0);
            }
            $sum['o_gross'] += (float) ($so['opening']['gross'] ?? 0);
            $sum['i_gross'] += (float) ($so['integration']['gross'] ?? 0);
            foreach ($so['integration']['docs'] ?? [] as $d) {
                isset($seenDocs[$d]) ? $dupes[] = $d : $seenDocs[$d] = true;
            }
        }
        foreach ($sum as $k => $v) {
            $sum[$k] = $r2($v);
        }

        // (a) The payslips themselves (payroll_documents, written at finalize) - not the run lines.
        $pd = DB::table('payroll_documents as d')->join('payroll_runs as r', 'r.id', '=', 'd.payroll_run_id')
            ->where('d.agency_id', $agencyId)->where('d.source', 'engine')->where('r.status', 'finalized')
            ->where(fn ($w) => $w->whereNull('d.status')->orWhere('d.status', '!=', 'void'))
            ->whereRaw('YEAR(r.pay_date) = ?', [$year])
            ->selectRaw('COUNT(*) n, COALESCE(SUM(d.gross),0) g, COALESCE(SUM(d.cpp),0) c, COALESCE(SUM(d.ei),0) e, COALESCE(SUM(d.income_tax),0) t')->first();
        // (b) The PD7A remittance report.
        $rem = Remittance::report($agencyId, $year);
        // (c) Openings + integration, straight from their tables.
        $og = $r2(DB::table('payroll_ytd_openings')->whereIn('id', $src['openings'] ?: [0])->sum('gross'));
        $ig = $r2(collect($src['t4'])->sum(fn ($s) => $s['integration']['gross']));
        $fees = $r2(collect($src['t4a'])->filter(fn ($a) => $a['fees'] > self::T4A_THRESHOLD || $a['income_tax'] > 0)->sum('fees'));
        $srcGross = $r2(collect($src['t4'])->filter(fn ($s) => $s['gross'] > 0 || $s['income_tax'] > 0)->sum('gross'));

        $lines = [];
        $line = function (string $key, string $label, float $slips, float $against, string $what) use (&$lines, $r2) {
            $d = $r2($slips - $against);
            $lines[] = ['key' => $key, 'label' => $label, 'slips' => $r2($slips), 'against' => $r2($against), 'against_label' => $what, 'diff' => $d, 'ok' => abs($d) <= 0.01];
        };
        $line('payslip_gross', 'Gross pay on KiddieTrac payslips', $sum['e_gross'], (float) $pd->g, 'Sum of ' . (int) $pd->n . ' finalized payslips');
        $line('payslip_cpp', 'CPP / QPP on KiddieTrac payslips', $sum['e_cpp'], (float) $pd->c, 'Sum of the payslips');
        $line('payslip_ei', 'EI on KiddieTrac payslips', $sum['e_ei'], (float) $pd->e, 'Sum of the payslips');
        $line('payslip_tax', 'Income tax on KiddieTrac payslips', $sum['e_tax'], (float) $pd->t, 'Sum of the payslips');
        $line('pd7a_cra', 'Owed to the CRA (PD7A, both shares)', $sum['cra'], (float) ($rem['totals']['cra_total'] ?? 0), 'Remittance report ' . $year);
        if ($sum['rq'] > 0 || (float) ($rem['totals']['rq_total'] ?? 0) > 0) {
            $line('pd7a_rq', 'Owed to Revenu Québec (both shares)', $sum['rq'], (float) ($rem['totals']['rq_total'] ?? 0), 'Remittance report ' . $year);
        }
        $line('openings', 'Opening balances (paid by a previous payroll)', $sum['o_gross'], $og, count($src['openings']) . ' opening balance(s)');
        $line('integration', 'Payslips from another payroll with deductions', $sum['i_gross'], $ig, count($src['integration']) . ' payslip(s)');
        $line('box14', 'T4 box 14 employment income, all slips', $sum['gross'], $srcGross, 'KiddieTrac + opening + other payroll, now');
        if ($fees > 0 || $sum['fees'] > 0) {
            $line('t4a', 'T4A box 048 fees for services', $sum['fees'], $fees, 'Contractor payments over $' . (int) self::T4A_THRESHOLD);
        }

        $block = [];
        $warn = [];
        if (! self::yearEnded($agencyId, $year)) {
            $block[] = 'The ' . $year . ' tax year has not ended: slips can be finalized from January 1, ' . ($year + 1) . '.';
        }
        $open = DB::table('payroll_runs')->where('agency_id', $agencyId)->whereIn('status', ['draft', 'review', 'approved'])->whereRaw('YEAR(pay_date) = ?', [$year])->pluck('reference')->all();
        if ($open) {
            $block[] = 'Pay runs with a ' . $year . ' pay date are not finalized yet: ' . implode(', ', $open) . '. Finalize or discard them first.';
        }
        if (! PayrollEmployer::settings($agencyId)['rp_number']) {
            $block[] = 'Add the CRA payroll account number (RP) in HR settings › Payroll engine.';
        }
        if (self::settings($agencyId)['dental_code'] === null && collect($slips)->contains(fn ($s) => $s->kind === 'T4' && ! (json_decode((string) $s->codes, true)['45'] ?? null))) {
            $block[] = 'Choose the dental benefits code (box 45) in the year-end settings.';
        }
        if ($dupes) {
            $block[] = 'A payslip is counted on more than one slip (' . implode(', ', array_unique($dupes)) . '). Regenerate the slips.';
        }
        if (! $slips) {
            $block[] = 'There are no slips for ' . $year . '. Generate them first.';
        }
        if ($src['contractor_openings']) {
            $warn[] = count($src['contractor_openings']) . ' contractor(s) have an opening balance: it is left off the slips (opening balances are employment income).';
        }
        $stale = collect($slips)->contains(fn ($s) => $s->status === 'draft' && $s->report_type === 'O') && ! collect($lines)->every(fn ($l) => $l['ok']);
        if ($stale) {
            $warn[] = 'The payroll changed since the slips were generated. Generate them again.';
        }
        $ok = ! $block && collect($lines)->every(fn ($l) => $l['ok']);

        return ['ok' => $ok, 'lines' => $lines, 'blockers' => $block, 'warnings' => $warn, 'slips' => $slipTotals, 'checked_at' => now()->toIso8601String(),
            'totals' => ['box14' => $sum['gross'], 'cpp' => $sum['cpp'], 'ei' => $sum['ei'], 'tax' => $sum['tax'], 'qpip' => $sum['qpip'], 'fees' => $sum['fees']]];
    }

    // ---- Finalize, amend, cancel -----------------------------------------------------------------------------------------

    /**
     * Finalize every pending slip of the year (originals, amendments, cancellations) - only when the reconciliation passes.
     * Replaced / cancelled originals change status in the same transaction. Returns the counts.
     */
    public static function finalize(int $agencyId, int $year, int $actorId): array
    {
        $rec = self::reconcile($agencyId, $year);
        if (! $rec['ok']) {
            DB::table('payroll_year_ends')->where('agency_id', $agencyId)->where('tax_year', $year)->update(['reconciliation' => json_encode($rec), 'updated_at' => now()]);
            abort(422, 'The slips do not reconcile, so they cannot be finalized: ' . implode(' ', $rec['blockers'] ?: ['the totals differ (see the reconciliation).']));
        }
        $n = ['final' => 0, 'replaced' => 0, 'cancelled' => 0, 'people' => []];
        DB::transaction(function () use ($agencyId, $year, $actorId, &$n, $rec) {
            $pending = DB::table('payroll_slips')->where('agency_id', $agencyId)->where('tax_year', $year)->where('status', 'draft')->lockForUpdate()->get();
            foreach ($pending as $p) {
                DB::table('payroll_slips')->where('id', $p->id)->update(['status' => 'final', 'finalized_by_id' => $actorId, 'finalized_at' => now(), 'updated_at' => now()]);
                $n['final']++;
                $n['people'][(int) $p->user_id] = true;
                if ($p->replaces_slip_id) {
                    $st = $p->report_type === 'C' ? 'cancelled' : 'replaced';
                    DB::table('payroll_slips')->where('id', $p->replaces_slip_id)->where('status', 'final')->update(['status' => $st, 'updated_at' => now()]);
                    $n[$st]++;
                }
            }
            DB::table('payroll_year_ends')->updateOrInsert(['agency_id' => $agencyId, 'tax_year' => $year], ['status' => 'final', 'finalized_by_id' => $actorId,
                'finalized_at' => now(), 'reconciliation' => json_encode($rec), 'updated_at' => now()]);
        });
        // Who-not-what: a bell that their slips are in My HR (no amounts). Test agencies send nothing.
        if (! TestAgency::isAgency($agencyId) && $n['people']) {
            try {
                foreach (array_keys($n['people']) as $uid) {
                    Notify::write(['user_id' => $uid, 'agency_id' => $agencyId, 'type' => 'hr', 'title' => 'Your ' . $year . ' tax slips are ready',
                        'body' => 'Open My HR › My tax slips to see them.', 'data' => json_encode(['link' => '#my-hr', 'kind' => 'tax_slips']), 'created_at' => now()]);
                }
            } catch (Throwable $e) {
                report($e);
            }
        }
        $n['people'] = count($n['people']);

        return $n;
    }

    /** Start an amendment of a final slip: a new draft (report type A) worked out from the payroll as it is now. */
    public static function amend(int $agencyId, int $slipId, int $actorId, string $reason): int
    {
        $s = DB::table('payroll_slips')->where('id', $slipId)->where('agency_id', $agencyId)->first();
        abort_unless($s, 404, 'Not found.');
        abort_unless($s->status === 'final', 422, 'Only a finalized slip can be amended. A draft can simply be changed or generated again.');
        abort_if(DB::table('payroll_slips')->where('replaces_slip_id', $s->id)->where('status', 'draft')->exists(), 422, 'This slip already has an amendment waiting to be finalized.');
        $year = (int) $s->tax_year;
        $row = ['agency_id' => $agencyId, 'tax_year' => $year, 'country' => 'CA', 'kind' => $s->kind, 'user_id' => $s->user_id, 'jurisdiction' => $s->jurisdiction,
            'centre_id' => $s->centre_id, 'status' => 'draft', 'report_type' => 'A', 'version' => (int) $s->version + 1, 'replaces_slip_id' => $s->id,
            'surname' => $s->surname, 'given_name' => $s->given_name, 'manual' => $s->manual, 'reason' => mb_substr($reason, 0, 500), 'generated_by_id' => $actorId,
            'created_at' => now(), 'updated_at' => now()];
        $src = self::sources($agencyId, $year);
        $m = json_decode((string) $s->manual, true) ?: [];
        if ($s->kind === 'T4A') {
            $a = $src['t4a'][(int) $s->user_id] ?? ['fees' => 0.0, 'income_tax' => 0.0, 'docs' => []];
            $row += ['boxes' => json_encode(['048' => $a['fees'], '022' => $a['income_tax']]), 'codes' => json_encode([]), 'other_info' => json_encode([]), 'employer' => json_encode([]),
                'sources' => json_encode(['contractor_docs' => $a['docs'], 'fees' => $a['fees'], 'income_tax' => $a['income_tax']]), 'warnings' => json_encode([])];
        } else {
            $b = $src['t4'][$s->user_id . ':' . $s->jurisdiction] ?? null;
            abort_unless($b, 422, 'Nothing is paid to this person in ' . $year . ' any more. Cancel the slip instead.');
            $x = self::t4Boxes($b, self::caps($year), self::settings($agencyId)['dental_code']);
            [$boxes, $codes] = self::applyManual($x['boxes'], $x['codes'], $m);
            $row += $s->kind === 'RL1'
                ? ['boxes' => json_encode($x['rl1'] ?? []), 'codes' => json_encode(['45' => $codes['45']]), 'other_info' => json_encode([])]
                : ['boxes' => json_encode($boxes), 'codes' => json_encode($codes), 'other_info' => json_encode($x['other'])];
            $row += ['employer' => json_encode(array_map(fn ($v) => round((float) $v, 2), $b['employer'])), 'sources' => json_encode(self::slipSources($b)),
                'warnings' => json_encode(self::warnings($agencyId, $s->kind, $b, $boxes, $codes))];
        }

        return (int) DB::table('payroll_slips')->insertGetId($row);
    }

    /** Cancel a final slip (issued to the wrong person, or not needed): a draft report type C with the original's amounts. */
    public static function cancel(int $agencyId, int $slipId, int $actorId, string $reason): int
    {
        $s = DB::table('payroll_slips')->where('id', $slipId)->where('agency_id', $agencyId)->first();
        abort_unless($s, 404, 'Not found.');
        abort_unless($s->status === 'final', 422, 'Only a finalized slip can be cancelled. A draft is removed by generating the slips again.');
        abort_if(DB::table('payroll_slips')->where('replaces_slip_id', $s->id)->where('status', 'draft')->exists(), 422, 'This slip already has an amendment waiting to be finalized.');
        $row = (array) $s;
        unset($row['id']);

        return (int) DB::table('payroll_slips')->insertGetId(array_replace($row, ['status' => 'draft', 'report_type' => 'C', 'version' => (int) $s->version + 1,
            'replaces_slip_id' => $s->id, 'reason' => mb_substr($reason, 0, 500), 'generated_by_id' => $actorId, 'finalized_by_id' => null, 'finalized_at' => null,
            'created_at' => now(), 'updated_at' => now()]));
    }

    /** Throw away a pending amendment / cancellation (the final slip it was for stays as it is). */
    public static function discardPending(int $agencyId, int $slipId): void
    {
        $s = DB::table('payroll_slips')->where('id', $slipId)->where('agency_id', $agencyId)->first();
        abort_unless($s && $s->status === 'draft' && $s->replaces_slip_id, 422, 'Only a pending amendment or cancellation can be withdrawn.');
        DB::table('payroll_slips')->where('id', $slipId)->update(['status' => 'discarded', 'updated_at' => now()]);
    }

    // ---- SIN ------------------------------------------------------------------------------------------------------------

    /** The 9 digits, or null. ONLY for the person's slip PDF (the person / an agency admin) and the CRA XML. */
    public static function sinFor(int $agencyId, int $userId): ?string
    {
        $enc = DB::table('hr_staff_sin')->where('agency_id', $agencyId)->where('user_id', $userId)->value('sin_enc');
        if (! $enc) {
            return null;
        }
        $v = rescue(fn () => Crypt::decryptString((string) $enc), null, false);
        $v = preg_replace('/\D/', '', (string) $v);

        return strlen($v) === 9 ? $v : null;
    }

    public static function sinHint(int $agencyId, int $userId): ?string
    {
        $h = DB::table('hr_staff_sin')->where('agency_id', $agencyId)->where('user_id', $userId)->value('sin_hint');

        return $h ? (string) $h : null;
    }

    // ---- Shapes -------------------------------------------------------------------------------------------------------

    public static function shape(object $s, array $names = []): array
    {
        return ['id' => (int) $s->id, 'tax_year' => (int) $s->tax_year, 'kind' => $s->kind, 'user_id' => (int) $s->user_id,
            'name' => $names[(int) $s->user_id] ?? trim($s->given_name . ' ' . $s->surname), 'jurisdiction' => $s->jurisdiction, 'centre_id' => $s->centre_id ? (int) $s->centre_id : null,
            'status' => $s->status, 'report_type' => $s->report_type, 'version' => (int) $s->version, 'replaces_slip_id' => $s->replaces_slip_id ? (int) $s->replaces_slip_id : null,
            'boxes' => json_decode((string) $s->boxes, true) ?: [], 'codes' => json_decode((string) $s->codes, true) ?: [], 'other_info' => json_decode((string) $s->other_info, true) ?: [],
            'manual' => json_decode((string) $s->manual, true) ?: [], 'employer' => json_decode((string) $s->employer, true) ?: [],
            'warnings' => json_decode((string) $s->warnings, true) ?: [], 'reason' => $s->reason,
            'finalized_at' => $s->finalized_at ? (string) $s->finalized_at : null, 'updated_at' => (string) $s->updated_at];
    }

    /** Summary totals for a kind over the slips in force (T4 Summary / T4A Summary / RL-1 Summary). */
    public static function summary(int $agencyId, int $year, string $kind, bool $withDrafts = true): array
    {
        $t = [];
        $n = 0;
        $er = ['cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'qpip' => 0.0];
        foreach (self::effective($agencyId, $year, $withDrafts) as $s) {
            if ($s->kind !== $kind) {
                continue;
            }
            $n++;
            foreach (json_decode((string) $s->boxes, true) ?: [] as $k => $v) {
                $t[$k] = round(($t[$k] ?? 0) + (float) $v, 2);
            }
            $e = json_decode((string) $s->employer, true) ?: [];
            if ($kind === 'T4' && $s->jurisdiction !== 'QC') {
                $er['cpp'] += (float) ($e['cpp'] ?? 0);
                $er['cpp2'] += (float) ($e['cpp2'] ?? 0);
            }
            if ($kind === 'T4') {
                $er['ei'] += (float) ($e['ei'] ?? 0);
            }
            if ($kind === 'RL1') {
                $er['cpp'] += (float) ($e['cpp'] ?? 0);
                $er['cpp2'] += (float) ($e['cpp2'] ?? 0);
                $er['qpip'] += (float) ($e['qpip'] ?? 0);
            }
        }
        $er = array_map(fn ($v) => round($v, 2), $er);
        $out = ['kind' => $kind, 'year' => $year, 'slips' => $n, 'boxes' => $t, 'employer' => $er];
        if ($kind === 'T4') {
            // T4 Summary lines 14, 16, 16A, 18, 19, 20, 22, 27, 27A, 52, 80 = 16 + 16A + 18 + 19 + 22 + 27 + 27A, 82 = remitted.
            $line80 = round(($t['16'] ?? 0) + ($t['16A'] ?? 0) + ($t['18'] ?? 0) + $er['ei'] + ($t['22'] ?? 0) + $er['cpp'] + $er['cpp2'], 2);
            $rem = (float) (Remittance::report($agencyId, $year)['totals']['cra_total'] ?? 0);
            // Deductions inside opening balances / other-payroll payslips were remitted by that payroll, not KiddieTrac's.
            $prior = 0.0;
            foreach (self::effective($agencyId, $year, $withDrafts) as $s2) {
                if ($s2->kind === 'T4' && $s2->jurisdiction !== 'QC') {
                    $so = json_decode((string) $s2->sources, true) ?: [];
                    $prior += (float) ($so['opening']['cpp'] ?? 0) + (float) ($so['opening']['ei'] ?? 0) + (float) ($so['opening']['income_tax'] ?? 0)
                        + (float) ($so['integration']['cpp'] ?? 0) + (float) ($so['integration']['ei'] ?? 0) + (float) ($so['integration']['income_tax'] ?? 0);
                }
            }
            $out['prior_payroll_deductions'] = round($prior, 2);
            $out['lines'] = ['14' => $t['14'] ?? 0, '16' => $t['16'] ?? 0, '16A' => $t['16A'] ?? 0, '18' => $t['18'] ?? 0, '19' => $er['ei'], '20' => $t['20'] ?? 0,
                '22' => $t['22'] ?? 0, '27' => $er['cpp'], '27A' => $er['cpp2'], '52' => $t['52'] ?? 0, '80' => $line80, '82' => round($rem, 2),
                'difference' => round($line80 - $rem, 2)];
        }

        return $out;
    }

    public static function note(int $agencyId, int $actorId, string $action, string $type, int $id, string $summary, array $extra = []): void
    {
        try {
            Audit::write(['agency_id' => $agencyId, 'user_id' => $actorId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary] + $extra), 'created_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
