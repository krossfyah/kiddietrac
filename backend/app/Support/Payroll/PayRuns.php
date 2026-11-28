<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\HrClaims;
use App\Support\HrLeave;
use App\Support\HrTime;
use App\Support\Payroll;
use App\Support\TestAgency;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): pay runs.
 *
 * A run moves draft -> review -> approved -> finalized (or is discarded before it is finalized):
 *  - DRAFT: built from the clock. Hours come ONLY from HrTime (closed punches, the agency's rounding / clip / break rules
 *    through HrTime::paidMinutes, whole minutes through HrTime::punchMinutes), never typed. The rate is the person's pay
 *    rate on file (users.pay_rate / pay_type, HrPay): hourly x hours, or salary / pay periods. Approved overtime-as-pay and
 *    approved claims join as lines. The preparer can leave someone out and add a bonus, a taxable benefit or an
 *    after-tax deduction; everything else is the calculator's.
 *  - REVIEW: submitted, locked. Send back to draft, or approve.
 *  - APPROVED: by a manager who is not paid in the run (nobody approves their own pay).
 *  - FINALIZED: one payslip per person (payroll_documents, source engine, payroll_run_id), overtime and claims marked
 *    paid. Never edited again: a correction is a later ADJUSTMENT run against it (retro pay from hours that changed since,
 *    a bonus, or an after-tax recovery).
 * Every line keeps the calculator input, its result, the table version and the year to date before the pay, so a past pay
 * can be explained without recalculating. Finalize refuses when the year to date moved after the run was calculated
 * (another run finalized in between), so the CPP / EI maximums are never applied twice.
 *
 * YEAR TO DATE (ytd()) = opening balances (payroll_ytd_openings) + finalized engine lines + payslips that arrived from an
 * integration (iLearn) WITH deductions on them. An integration payslip is counted only when it is not one of ours
 * (payroll_run_id null, source not engine) and is dated after the opening balance's as-of date, so nothing is counted
 * twice; gross-only payslips (no CPP / EI / tax recorded) are never counted.
 */
final class PayRuns
{
    public const STATUSES = ['draft', 'review', 'approved', 'finalized', 'discarded'];

    /** The Starlight demo year (PayrollDemo) leaves approved overtime / claims for the demo's own screens. */
    public static bool $skipExtras = false;

    /** Lines' editable inputs in a draft. */
    public const EDITABLE = ['included', 'bonus', 'benefits_cash', 'benefits_noncash', 'other_deduction', 'other_label', 'note'];

    // ---- Year to date ------------------------------------------------------------------------------------------------

    /** Opening balance row for a person + year, or null. */
    public static function opening(int $agencyId, int $userId, int $year): ?object
    {
        return DB::table('payroll_ytd_openings')->where('agency_id', $agencyId)->where('user_id', $userId)->where('tax_year', $year)->first();
    }

    /**
     * Everything paid to this person by this agency in the tax year before this run, in the calculator's YTD terms.
     * @return array{gross:float,pi:float,ie:float,cpp:float,cpp2:float,ei:float,qpip:float,qpip_employer:float,income_tax:float,
     *               bonus:float,net:float,employer_cpp:float,employer_cpp2:float,employer_ei:float,employer_qpip:float,sources:array}
     */
    public static function ytd(int $agencyId, int $userId, int $year, ?int $excludeRunId = null): array
    {
        $t = ['gross' => 0.0, 'pi' => 0.0, 'ie' => 0.0, 'cpp' => 0.0, 'cpp2' => 0.0, 'ei' => 0.0, 'qpip' => 0.0, 'qpip_employer' => 0.0,
            'income_tax' => 0.0, 'bonus' => 0.0, 'net' => 0.0, 'employer_cpp' => 0.0, 'employer_cpp2' => 0.0, 'employer_ei' => 0.0, 'employer_qpip' => 0.0];
        $src = ['opening' => false, 'engine' => 0, 'integration' => 0];
        $op = self::opening($agencyId, $userId, $year);
        if ($op) {
            $src['opening'] = true;
            $t['gross'] += (float) $op->gross;
            $t['pi'] += (float) $op->pensionable;
            $t['ie'] += (float) $op->insurable;
            $t['cpp'] += (float) $op->cpp;
            $t['cpp2'] += (float) $op->cpp2;
            $t['ei'] += (float) $op->ei;
            $t['qpip'] += (float) $op->qpip;
            $t['income_tax'] += (float) $op->income_tax;
            $t['bonus'] += (float) $op->bonus;
            /* NET (2026-11-27): the opening row has no net column, so its take-home pay was never counted and a payslip's
               year-to-date net came out lower than gross less deductions (Starlight demo: short by exactly the opening's
               $7,748.21). Net only feeds payslips and screens, never CPP/EI/tax or T4 boxes. 'opening_net' marks year-to-date
               figures that include it, so payslips frozen before this fix can be corrected when they are drawn. */
            $t['net'] += self::openingNet($op);
            $src['opening_net'] = true;
        }
        $lines = DB::table('payroll_run_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.agency_id', $agencyId)->where('l.user_id', $userId)->where('r.status', 'finalized')->where('l.included', 1)
            ->whereNotNull('l.payroll_document_id')->whereRaw('YEAR(r.pay_date) = ?', [$year])
            ->when($excludeRunId, fn ($q) => $q->where('r.id', '!=', $excludeRunId))
            ->get(['l.gross', 'l.cpp', 'l.cpp2', 'l.ei', 'l.qpip', 'l.income_tax', 'l.bonus', 'l.net', 'l.benefits_noncash',
                'l.employer_cpp', 'l.employer_cpp2', 'l.employer_ei', 'l.employer_qpip']);
        foreach ($lines as $l) {
            $src['engine']++;
            $t['gross'] += (float) $l->gross;
            $t['pi'] += (float) $l->gross;
            $t['ie'] += (float) $l->gross - (float) $l->benefits_noncash;   // non-cash benefits are not insurable
            foreach (['cpp', 'cpp2', 'ei', 'qpip', 'income_tax', 'bonus', 'net', 'employer_cpp', 'employer_cpp2', 'employer_ei', 'employer_qpip'] as $k) {
                $t[$k] += (float) $l->$k;
            }
            $t['qpip_employer'] += (float) $l->employer_qpip;
        }
        $docs = DB::table('payroll_documents')->where('agency_id', $agencyId)->where('user_id', $userId)->where('kind', 'payslip')
            ->whereNull('payroll_run_id')->where('source', '!=', 'engine')
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
            ->where(fn ($w) => $w->whereNotNull('cpp')->orWhereNotNull('ei')->orWhereNotNull('income_tax'))
            ->whereRaw('LEFT(COALESCE(period_end, period_start), 4) = ?', [(string) $year])
            ->when($op, fn ($q) => $q->whereRaw('COALESCE(period_end, period_start) > ?', [(string) $op->as_of]))
            ->get(['gross', 'vacation_pay', 'cpp', 'ei', 'income_tax', 'net']);
        foreach ($docs as $d) {
            $src['integration']++;
            $g = (float) $d->gross + (float) $d->vacation_pay;   // an iLearn payslip's gross leaves vacation pay out
            $t['gross'] += $g;
            $t['pi'] += $g;
            $t['ie'] += $g;
            $t['cpp'] += (float) $d->cpp;
            $t['ei'] += (float) $d->ei;
            $t['income_tax'] += (float) $d->income_tax;
            $t['net'] += (float) $d->net;
        }
        foreach ($t as $k => $v) {
            $t[$k] = round($v, 2);
        }
        $t['sources'] = $src;

        return $t;
    }

    /** Take-home pay inside an opening balance: gross less the deductions it records (it records no other deductions). */
    public static function openingNet(object $op): float
    {
        return round((float) $op->gross - (float) $op->cpp - (float) $op->cpp2 - (float) $op->ei - (float) $op->qpip - (float) $op->income_tax, 2);
    }

    /**
     * A finalized payslip's year-to-date net. Breakdowns frozen before 2026-11-27 left the opening balance's net out
     * (ytd_sources.opening without opening_net); add it back when drawing. Nothing stored is edited.
     */
    public static function ytdNetOf(object $doc, array $b): float
    {
        $net = (float) ($b['ytd']['net'] ?? 0);
        $src = $b['ytd_sources'] ?? [];
        if (! empty($src['opening']) && empty($src['opening_net'])) {
            $year = (int) substr((string) ($b['run']['pay_date'] ?? $doc->period_end), 0, 4);
            $op = self::opening((int) $doc->agency_id, (int) $doc->user_id, $year);
            if ($op) {
                $net += self::openingNet($op);
            }
        }

        return round($net, 2);
    }

    private static function ytdHash(array $y): string
    {
        unset($y['sources']);

        return sha1(json_encode($y));
    }

    // ---- Reading the clock -------------------------------------------------------------------------------------------

    /**
     * Paid minutes per person in the period from HrTime, at the given centres (null = every centre of the agency).
     * @return array<int, array{minutes:int, shifts:int, open:int, detail:array}>
     */
    public static function hoursFor(int $agencyId, ?array $centreIds, string $from, string $to, array $userIds): array
    {
        if (! $userIds) {
            return [];
        }
        $centres = $centreIds ?? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        [$a, $b] = AgencyTime::spanRange($agencyId, $from, $to);
        $punches = DB::table('time_punches')->whereIn('user_id', $userIds)->whereIn('centre_id', $centres ?: [0])
            ->where('punched_in_at', '>=', $a)->where('punched_in_at', '<', $b)->orderBy('punched_in_at')
            ->get(['id', 'user_id', 'centre_id', 'punched_in_at', 'punched_out_at']);
        $paid = [];
        if (HrTime::on($agencyId) && HrTime::rulesOn(HrTime::settings($agencyId))) {
            $paid = HrTime::paidMinutes($agencyId, $punches, $from, $to);
        }
        $out = [];
        foreach ($punches as $p) {
            $uid = (int) $p->user_id;
            $out[$uid] ??= ['minutes' => 0, 'shifts' => 0, 'open' => 0, 'detail' => []];
            if (! $p->punched_out_at) {
                $out[$uid]['open']++;
                continue;
            }
            $m = isset($paid[(int) $p->id]) ? (int) $paid[(int) $p->id] : HrTime::punchMinutes($p->punched_in_at, $p->punched_out_at);
            $out[$uid]['minutes'] += $m;
            $out[$uid]['shifts']++;
            $out[$uid]['detail'][] = ['punch_id' => (int) $p->id, 'centre_id' => (int) $p->centre_id, 'in' => (string) $p->punched_in_at, 'out' => (string) $p->punched_out_at, 'minutes' => $m];
        }

        return $out;
    }

    // ---- Building and calculating ------------------------------------------------------------------------------------

    public static function reference(int $agencyId, string $payDate): string
    {
        $y = substr($payDate, 0, 4);
        $n = DB::table('payroll_runs')->where('agency_id', $agencyId)->whereRaw('YEAR(pay_date) = ?', [(int) $y])->count() + 1;

        return 'PR-' . $y . '-' . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
    }

    /** The centres a run covers (null = all of the agency's). */
    public static function runCentres(object $run): ?array
    {
        return $run->centre_id ? [(int) $run->centre_id] : null;
    }

    /** People in another live regular run whose period overlaps this one. [user id => run reference] */
    public static function inOtherRuns(object $run): array
    {
        return DB::table('payroll_run_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.agency_id', $run->agency_id)->where('r.id', '!=', $run->id)->where('r.kind', 'regular')
            ->where('r.status', '!=', 'discarded')->where('l.included', 1)
            ->where('r.period_start', '<=', $run->period_end)->where('r.period_end', '>=', $run->period_start)
            ->pluck('r.reference', 'l.user_id')->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])->all();
    }

    /**
     * (Re)build a draft's lines from the clock and recalculate every line. Inputs typed on a line (left out, bonus,
     * benefits, after-tax deduction, note) are kept. Regular runs: everyone in the run's centres who has paid hours or a
     * salary. Adjustment runs: only the people already added; their retro hours = hours HrTime gives for the original
     * period now, less what the original run and earlier adjustments paid.
     */
    public static function build(object $run): array
    {
        abort_unless($run->status === 'draft', 422, 'Only a draft run can be recalculated.');
        $aid = (int) $run->agency_id;
        $centres = self::runCentres($run);
        $existing = DB::table('payroll_run_lines')->where('run_id', $run->id)->get()->keyBy('user_id');
        $skipped = [];
        if ($run->kind === 'regular') {
            $people = HrLeave::staffIds($aid, $centres);
            $busy = self::inOtherRuns($run);
            foreach ($people as $i => $uid) {
                if (isset($busy[$uid])) {
                    $skipped[] = ['user_id' => $uid, 'why' => 'Already in ' . $busy[$uid] . ' for this period'];
                    unset($people[$i]);
                }
            }
            $people = array_values($people);
        } else {
            $people = $existing->keys()->map(fn ($v) => (int) $v)->all();
        }
        $hours = self::hoursFor($aid, $centres, (string) $run->period_start, (string) $run->period_end, $people);
        $users = DB::table('users')->whereIn('id', $people ?: [0])->get(['id', 'first_name', 'last_name', 'pay_rate', 'pay_type'])->keyBy('id');
        $ot = $run->kind === 'regular' && ! self::$skipExtras && HrTime::on($aid) ? HrTime::forPayroll($aid, $people) : [];
        $claims = [];
        if ($run->kind === 'regular' && ! self::$skipExtras) {
            try {
                $claims = HrClaims::forPayroll($aid, $people);
            } catch (Throwable $e) {
                report($e);
            }
        }
        $paidBefore = $run->kind === 'adjustment' ? self::minutesPaid($run) : [];
        $keep = [];
        foreach ($people as $uid) {
            $u = $users[$uid] ?? null;
            if (! $u) {
                continue;
            }
            $h = $hours[$uid] ?? ['minutes' => 0, 'shifts' => 0, 'open' => 0, 'detail' => []];
            $rate = $u->pay_rate !== null ? (float) $u->pay_rate : null;
            $type = $u->pay_type ?: ($rate !== null ? 'hourly' : null);
            $old = $existing[$uid] ?? null;
            if ($run->kind === 'regular' && ! $old && $type !== 'salary' && $h['minutes'] === 0 && empty($ot[$uid]) && empty($claims[$uid])) {
                continue;   // nothing worked and nothing owed: not in this run
            }
            $extras = ['overtime' => [], 'claims' => [], 'hours_detail' => $h['detail'], 'open_punches' => $h['open'], 'shifts' => $h['shifts']];
            $minutes = $h['minutes'];
            if ($run->kind === 'adjustment') {
                $extras['paid_minutes_before'] = $paidBefore[$uid] ?? 0;
                $extras['minutes_now'] = $minutes;
                $minutes = $minutes - ($paidBefore[$uid] ?? 0);
                $extras['hours_detail'] = [];
            }
            $hourly = $type === 'salary' ? ($rate !== null ? $rate / (52 * self::weeklyHours($aid, $uid)) : null) : $rate;
            foreach ($ot[$uid] ?? [] as $o) {
                $extras['overtime'][] = $o + ['amount' => $hourly !== null ? round($o['hours'] * $o['multiplier'] * $hourly, 2) : 0.0];
            }
            foreach ($claims[$uid] ?? [] as $c) {
                $extras['claims'][] = $c;
            }
            $row = ['run_id' => $run->id, 'agency_id' => $aid, 'user_id' => $uid, 'pay_type' => $type, 'rate' => $rate,
                'minutes' => max(0, $minutes), 'hours' => HrTime::hours(max(0, $minutes)), 'extras' => json_encode($extras),
                'centre_id' => self::homeCentre($aid, $uid, $h['detail'], $centres), 'updated_at' => now()];
            if ($run->kind === 'adjustment' && $minutes < 0) {
                $row['minutes'] = 0;
                $row['hours'] = 0;
                $extras['overpaid_minutes'] = -$minutes;
                $row['extras'] = json_encode($extras);
            }
            if ($old) {
                DB::table('payroll_run_lines')->where('id', $old->id)->update($row);
                $id = (int) $old->id;
            } else {
                $id = (int) DB::table('payroll_run_lines')->insertGetId($row + ['created_at' => now()]);
            }
            $keep[] = $id;
            self::calcLine((int) $id, $run);
        }
        // People no longer in the run (left, moved centre, now in another run) drop out of the draft.
        DB::table('payroll_run_lines')->where('run_id', $run->id)->whereNotIn('id', $keep ?: [0])->delete();
        $totals = self::totals((int) $run->id);
        $totals['skipped'] = $skipped;
        DB::table('payroll_runs')->where('id', $run->id)->update(['totals' => json_encode($totals), 'updated_at' => now()]);

        return $totals;
    }

    /** Minutes already paid per person for an adjustment run's original period (original + earlier finalized adjustments). */
    private static function minutesPaid(object $run): array
    {
        $ids = DB::table('payroll_runs')->where('agency_id', $run->agency_id)->where('status', 'finalized')
            ->where(fn ($w) => $w->where('id', $run->adjusts_run_id)->orWhere('adjusts_run_id', $run->adjusts_run_id))
            ->where('id', '!=', $run->id)->pluck('id')->all();

        return DB::table('payroll_run_lines')->whereIn('run_id', $ids ?: [0])->where('included', 1)
            ->groupBy('user_id')->selectRaw('user_id, SUM(minutes) m')->pluck('m', 'user_id')
            ->mapWithKeys(fn ($v, $k) => [(int) $k => (int) $v])->all();
    }

    private static function weeklyHours(int $agencyId, int $userId): float
    {
        $h = (float) DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->value('hours_per_week');

        return $h > 0 ? $h : 40.0;
    }

    /** The centre a payslip is filed to: the centre most of the hours were at, else the HR file's main centre, else the run's. */
    private static function homeCentre(int $agencyId, int $userId, array $detail, ?array $runCentres): ?int
    {
        $by = [];
        foreach ($detail as $d) {
            $by[$d['centre_id']] = ($by[$d['centre_id']] ?? 0) + $d['minutes'];
        }
        if ($by) {
            arsort($by);

            return (int) array_key_first($by);
        }
        $c = (int) DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->value('primary_centre_id');
        if ($c && ($runCentres === null || in_array($c, $runCentres, true))) {
            return $c;
        }

        return $runCentres[0] ?? null;
    }

    /** Recalculate one line from its stored inputs (draft only). */
    public static function calcLine(int $lineId, ?object $run = null): void
    {
        $l = DB::table('payroll_run_lines')->where('id', $lineId)->first();
        if (! $l) {
            return;
        }
        $run = $run ?: DB::table('payroll_runs')->where('id', $l->run_id)->first();
        $aid = (int) $run->agency_id;
        $uid = (int) $l->user_id;
        $extras = json_decode((string) $l->extras, true) ?: [];
        $warn = [];
        $upd = ['error' => null, 'updated_at' => now()];
        $hours = (float) $l->hours;
        $rate = $l->rate !== null ? (float) $l->rate : null;
        $regular = 0.0;
        if ($run->kind === 'regular') {
            if ($l->pay_type === 'salary') {
                $regular = $rate !== null ? round($rate / (int) $run->periods, 2) : 0.0;
            } elseif ($rate !== null) {
                $regular = round($hours * $rate, 2);
            }
        }
        $retro = 0.0;
        if ($run->kind === 'adjustment' && $l->pay_type !== 'salary' && $rate !== null) {
            $retro = round($hours * $rate, 2);   // hours HrTime gives now that were never paid
        }
        $otAmt = round(array_sum(array_column($extras['overtime'] ?? [], 'amount')), 2);
        $reimb = round(array_sum(array_column($extras['claims'] ?? [], 'amount')), 2);
        $tax = PayrollTax::inputs($aid, $uid, (string) $run->pay_date);
        $prof = PayrollTax::profile($aid, $uid);
        $vacPct = $prof['vacation_pay_pct'];
        $vac = $vacPct ? round(($regular + $otAmt + $retro) * $vacPct / 100, 2) : 0.0;
        $warn = array_merge($warn, $tax['warnings']);
        if ($rate === null && ($hours > 0 || $l->pay_type === 'salary')) {
            $upd['error'] = 'No pay rate on file. Add it in the HR profile (People), then recalculate.';
        }
        if (($extras['open_punches'] ?? 0) > 0) {
            $warn[] = $extras['open_punches'] . ' clock-in(s) in this period are still open and are not paid until they are closed.';
        }
        if (! empty($extras['overpaid_minutes'])) {
            $warn[] = HrTime::hours((int) $extras['overpaid_minutes']) . ' fewer hours than were paid: recover the difference with an after-tax deduction if it was overpaid.';
        }
        if ($run->kind === 'regular') {
            $also = DB::table('payroll_documents')->where('agency_id', $aid)->where('user_id', $uid)->where('kind', 'payslip')->whereNull('payroll_run_id')
                ->where('status', '!=', 'void')->where('period_start', '<=', $run->period_end)->where('period_end', '>=', $run->period_start)
                ->whereIn('source', ['manual', 'ilearn'])->pluck('source')->unique()->all();
            foreach ($also as $s) {
                $warn[] = $s === 'manual' ? 'Also paid by a manual payroll run for this period. Leave them out here if that payslip already paid these hours.'
                    : 'Also has an iLearn payslip for this period. Its deductions already count in the year to date.';
            }
        }
        $in = $tax['inputs'];
        $year = (int) substr((string) $run->pay_date, 0, 4);
        $ytd = self::ytd($aid, $uid, $year, (int) $run->id);
        $in['salary'] = $regular + $otAmt;
        $in['vacation_pay'] = $vac;
        $in['bonus'] = round((float) $l->bonus + $retro, 2);
        $in['bonus_ytd'] = $ytd['bonus'];
        $in['benefits_cash'] = (float) $l->benefits_cash;
        $in['benefits_noncash'] = (float) $l->benefits_noncash;
        $in['periods'] = (int) $run->periods;
        $in['ytd'] = ['pi' => $ytd['pi'], 'cpp' => $ytd['cpp'], 'cpp2' => $ytd['cpp2'], 'ie' => $ytd['ie'], 'ei' => $ytd['ei'],
            'qpip' => $ytd['qpip'], 'qpip_employer' => $ytd['qpip_employer']];
        if (($prof['pay_frequency'] ?? null) && (int) $prof['pay_frequency'] !== (int) $run->periods) {
            $warn[] = 'Their tax setup says ' . $prof['pay_frequency'] . ' pays a year; this run uses ' . $run->periods . '.';
        }
        $upd += ['regular' => $regular, 'overtime' => $otAmt, 'vacation_pay' => $vac, 'reimbursements' => $reimb, 'jurisdiction' => $in['jurisdiction'] ?? null,
            'inputs' => json_encode($in), 'ytd_before' => json_encode($ytd), 'ytd_hash' => self::ytdHash($ytd)];
        $zero = ['gross' => 0, 'cpp' => 0, 'cpp2' => 0, 'ei' => 0, 'qpip' => 0, 'federal_tax' => 0, 'provincial_tax' => 0, 'income_tax' => 0,
            'net' => 0, 'employer_cpp' => 0, 'employer_cpp2' => 0, 'employer_ei' => 0, 'employer_qpip' => 0, 'table_version' => null, 'result' => null];
        if (! $upd['error']) {
            try {
                if (($in['salary'] + $in['vacation_pay'] + $in['bonus'] + $in['benefits_cash'] + $in['benefits_noncash']) <= 0) {
                    $upd += $zero + ['net' => round($reimb - (float) $l->other_deduction, 2)];
                } else {
                    $r = CaCalculator::calculate($in);
                    $e = $r['employee'];
                    $er = $r['employer'];
                    // The calculator's "no TD1" notes repeat PayrollTax's "No signed TD1" one.
                    $cw = array_values(array_filter($r['warnings'], fn ($w) => ! ($tax['form_id'] === null && str_contains($w, 'TD1')) && ! str_contains($w, 'TP-1015.3 on file')));
                    $warn = array_merge($warn, $cw);
                    $upd += ['gross' => $e['gross'], 'cpp' => $e['cpp'], 'cpp2' => $e['cpp2'], 'ei' => $e['ei'], 'qpip' => $e['qpip'],
                        'federal_tax' => round($e['federal_tax'] + $e['bonus_federal_tax'], 2), 'provincial_tax' => round($e['provincial_tax'] + $e['bonus_provincial_tax'], 2),
                        'income_tax' => $e['income_tax'], 'net' => round($e['net'] - (float) $l->other_deduction + $reimb, 2),
                        'employer_cpp' => $er['cpp'], 'employer_cpp2' => $er['cpp2'], 'employer_ei' => $er['ei'], 'employer_qpip' => $er['qpip'],
                        'table_version' => $r['table_version'],
                        'result' => json_encode(['edition' => $r['edition'], 'quebec_edition' => $r['quebec_edition'], 'plan' => $r['plan'],
                            'factors' => $r['factors'], 'tax_form_id' => $tax['form_id']])];
                    if ($upd['net'] < 0) {
                        $upd['error'] = 'The after-tax deduction is more than the net pay.';
                    }
                }
            } catch (Throwable $e) {
                $upd += $zero;
                $upd['error'] = $e instanceof \InvalidArgumentException || $e instanceof \RuntimeException ? $e->getMessage() : 'This pay could not be worked out.';
                if (! ($e instanceof \InvalidArgumentException || $e instanceof \RuntimeException)) {
                    report($e);
                }
            }
        } else {
            $upd += $zero;
        }
        $warn = array_values(array_unique($warn));
        $upd['warnings'] = json_encode($warn);
        $upd['state'] = $upd['error'] ? 'error' : ($warn ? 'warning' : 'ok');
        DB::table('payroll_run_lines')->where('id', $lineId)->update($upd);
    }

    public static function totals(int $runId): array
    {
        $ls = DB::table('payroll_run_lines')->where('run_id', $runId)->get();
        $in = $ls->where('included', 1);
        $sum = fn ($k) => round((float) $in->sum($k), 2);
        $qc = $in->where('jurisdiction', 'QC');
        $nq = $in->where('jurisdiction', '!=', 'QC');
        $cra = round((float) $nq->sum(fn ($l) => (float) $l->cpp + (float) $l->cpp2 + (float) $l->employer_cpp + (float) $l->employer_cpp2 + (float) $l->income_tax)
            + (float) $in->sum(fn ($l) => (float) $l->ei + (float) $l->employer_ei) + (float) $qc->sum('federal_tax'), 2);
        $rq = round((float) $qc->sum(fn ($l) => (float) $l->cpp + (float) $l->cpp2 + (float) $l->employer_cpp + (float) $l->employer_cpp2
            + (float) $l->qpip + (float) $l->employer_qpip + (float) $l->provincial_tax), 2);

        return ['people' => $in->count(), 'left_out' => $ls->where('included', 0)->count(), 'gross' => $sum('gross'),
            'cpp' => round($sum('cpp') + $sum('cpp2'), 2), 'ei' => $sum('ei'), 'qpip' => $sum('qpip'), 'tax' => $sum('income_tax'), 'net' => $sum('net'),
            'reimbursements' => $sum('reimbursements'),
            'employer' => round($sum('employer_cpp') + $sum('employer_cpp2') + $sum('employer_ei') + $sum('employer_qpip'), 2),
            'remit_cra' => $cra, 'remit_rq' => $rq,
            'errors' => $in->where('state', 'error')->count(), 'warnings' => $in->where('state', 'warning')->count()];
    }

    // ---- Moving a run along ------------------------------------------------------------------------------------------

    /** The person ids paid in a run (included lines). */
    public static function paidIds(int $runId): array
    {
        return DB::table('payroll_run_lines')->where('run_id', $runId)->where('included', 1)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * Write the payslips. Approved only; inside one transaction with the run locked; refuses when any line's year to
     * date has moved since it was calculated. Returns the number of payslips.
     */
    public static function finalize(int $runId, int $actorId): int
    {
        $made = 0;
        DB::transaction(function () use ($runId, $actorId, &$made) {
            $run = DB::table('payroll_runs')->where('id', $runId)->lockForUpdate()->first();
            abort_unless($run && $run->status === 'approved', 422, 'Only an approved run can be finalized.');
            $aid = (int) $run->agency_id;
            $year = (int) substr((string) $run->pay_date, 0, 4);
            $lines = DB::table('payroll_run_lines')->where('run_id', $runId)->where('included', 1)->get();
            abort_if($lines->where('state', 'error')->count() > 0, 422, 'A line still has a problem. Send the run back to draft and fix it.');
            foreach ($lines as $l) {
                if (self::ytdHash(self::ytd($aid, (int) $l->user_id, $year, $runId)) !== (string) $l->ytd_hash) {
                    abort(409, 'Another pay was finalized for someone in this run after it was calculated, so the year to date moved. Send it back to draft, recalculate and approve it again.');
                }
            }
            $users = DB::table('users')->whereIn('id', $lines->pluck('user_id')->all() ?: [0])->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');
            $freq = PayrollTax::FREQUENCIES[(int) $run->periods] ?? ($run->periods . ' pays a year');
            $test = TestAgency::isAgency($aid);
            foreach ($lines as $l) {
                if ((float) $l->gross <= 0 && (float) $l->net <= 0) {
                    continue;
                }
                $uid = (int) $l->user_id;
                $u = $users[$uid] ?? null;
                $role = Payroll::primaryRole($uid);
                $ex = json_decode((string) $l->extras, true) ?: [];
                $ytd = json_decode((string) $l->ytd_before, true) ?: [];
                $payLines = self::payLines($l, $ex);
                $breakdown = self::breakdown($run, $l, $ex, $ytd);
                $docId = (int) DB::table('payroll_documents')->insertGetId([
                    'external_key' => 'engine:' . $runId . ':u:' . $uid,
                    'agency_id' => $aid, 'centre_id' => $l->centre_id, 'user_id' => $uid,
                    'staff_group' => Payroll::groupFor($role), 'role_label' => $role ? ucfirst(str_replace('_', ' ', $role)) : null,
                    'payee_name' => $u ? trim($u->first_name . ' ' . $u->last_name) : null, 'payee_email' => $u->email ?? null,
                    'kind' => 'payslip', 'reference' => $run->reference . '-' . str_pad((string) $uid, 4, '0', STR_PAD_LEFT),
                    'period_start' => $run->period_start, 'period_end' => $run->period_end,
                    'units' => $l->pay_type === 'salary' ? 0 : (float) $l->hours, 'unit_label' => $l->pay_type === 'salary' ? 'flat' : 'hours',
                    'rate' => $l->pay_type === 'salary' ? 0 : (float) $l->rate, 'pay_frequency' => $freq,
                    'gross' => (float) $l->gross, 'cpp' => round((float) $l->cpp + (float) $l->cpp2, 2), 'ei' => (float) $l->ei,
                    'income_tax' => (float) $l->income_tax, 'other_deductions' => round((float) $l->qpip + (float) $l->other_deduction, 2) ?: null,
                    'vacation_pay' => null, 'net' => (float) $l->net, 'currency' => 'CAD', 'status' => 'issued',
                    'source' => 'engine', 'payroll_run_id' => $runId,
                    'lines' => json_encode($payLines), 'breakdown' => json_encode($breakdown),
                    'hours_detail' => ! empty($ex['hours_detail']) ? json_encode($ex['hours_detail']) : null,
                    'notes' => $l->note, 'issued_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('payroll_run_lines')->where('id', $l->id)->update(['payroll_document_id' => $docId, 'updated_at' => now()]);
                $otIds = array_values(array_filter(array_map(fn ($o) => (int) ($o['overtime_id'] ?? 0), $ex['overtime'] ?? [])));
                if ($otIds) {
                    HrTime::markPaid($aid, $uid, $otIds, $docId);
                }
                $clIds = array_values(array_filter(array_map(fn ($c) => (int) ($c['claim_id'] ?? 0), $ex['claims'] ?? [])));
                if ($clIds) {
                    HrClaims::markPaid($aid, $uid, $clIds, $docId);
                }
                if ($test) {
                    // Test agencies send nothing: the payslip notice sweep finds it already settled.
                    DB::table('payslip_notices')->insertOrIgnore(['payroll_document_id' => $docId, 'user_id' => $uid, 'agency_id' => $aid, 'kind' => 'new',
                        'dedupe_key' => 'new', 'net' => (float) $l->net, 'to_email' => null, 'status' => 'skipped', 'reason' => 'test agency: nothing is sent', 'created_at' => now()]);
                }
                $made++;
            }
            DB::table('payroll_runs')->where('id', $runId)->update(['status' => 'finalized', 'finalized_by_id' => $actorId, 'finalized_at' => now(),
                'totals' => json_encode(self::totals($runId) + ['payslips' => $made]), 'updated_at' => now()]);
        });

        return $made;
    }

    /** The payslip's earnings lines (payroll_documents.lines): what the pay is made of. */
    public static function payLines(object $l, array $ex): array
    {
        $out = [];
        if ((float) $l->regular > 0) {
            $out[] = $l->pay_type === 'salary' ? ['label' => 'Salary', 'amount' => (float) $l->regular]
                : ['label' => 'Regular hours', 'hours' => (float) $l->hours, 'rate' => (float) $l->rate, 'amount' => (float) $l->regular];
        }
        foreach ($ex['overtime'] ?? [] as $o) {
            $out[] = ['label' => $o['label'], 'hours' => $o['hours'], 'multiplier' => $o['multiplier'], 'amount' => $o['amount'], 'overtime_id' => $o['overtime_id']];
        }
        $inputs = json_decode((string) $l->inputs, true) ?: [];
        $retro = round((float) ($inputs['bonus'] ?? 0) - (float) $l->bonus, 2);
        if ($retro > 0) {
            $out[] = ['label' => 'Retro pay (hours not paid before)', 'hours' => (float) $l->hours, 'rate' => (float) $l->rate, 'amount' => $retro];
        }
        if ((float) $l->vacation_pay > 0) {
            $out[] = ['label' => 'Vacation pay', 'amount' => (float) $l->vacation_pay];
        }
        if ((float) $l->bonus > 0) {
            $out[] = ['label' => 'Bonus', 'amount' => (float) $l->bonus];
        }
        if ((float) $l->benefits_cash > 0) {
            $out[] = ['label' => 'Taxable benefit (cash)', 'amount' => (float) $l->benefits_cash];
        }
        if ((float) $l->benefits_noncash > 0) {
            $out[] = ['label' => 'Taxable benefit (non-cash)', 'amount' => (float) $l->benefits_noncash, 'non_cash' => true];
        }
        foreach ($ex['claims'] ?? [] as $c) {
            $out[] = ['label' => $c['label'], 'amount' => $c['amount'], 'claim_id' => $c['claim_id'], 'not_taxable' => true];
        }

        return $out;
    }

    /** Everything the payslip shows, frozen at finalize (payroll_documents.breakdown). */
    public static function breakdown(object $run, object $l, array $ex, array $ytd): array
    {
        $qc = $l->jurisdiction === 'QC';
        $this_ = ['gross' => (float) $l->gross, 'cpp' => (float) $l->cpp, 'cpp2' => (float) $l->cpp2, 'ei' => (float) $l->ei, 'qpip' => (float) $l->qpip,
            'federal_tax' => (float) $l->federal_tax, 'provincial_tax' => (float) $l->provincial_tax, 'income_tax' => (float) $l->income_tax,
            'other_deduction' => (float) $l->other_deduction, 'reimbursements' => (float) $l->reimbursements, 'benefits_noncash' => (float) $l->benefits_noncash,
            'net' => (float) $l->net, 'vacation_pay' => (float) $l->vacation_pay, 'bonus' => (float) $l->bonus,
            'employer_cpp' => (float) $l->employer_cpp, 'employer_cpp2' => (float) $l->employer_cpp2, 'employer_ei' => (float) $l->employer_ei, 'employer_qpip' => (float) $l->employer_qpip];
        $after = [];
        foreach (['gross', 'cpp', 'cpp2', 'ei', 'qpip', 'income_tax', 'net', 'employer_cpp', 'employer_cpp2', 'employer_ei', 'employer_qpip'] as $k) {
            $after[$k] = round((float) ($ytd[$k] ?? 0) + $this_[$k], 2);
        }
        $after['vacation_pay'] = round((float) DB::table('payroll_run_lines as x')->join('payroll_runs as r', 'r.id', '=', 'x.run_id')
            ->where('r.agency_id', $run->agency_id)->where('x.user_id', $l->user_id)->where('r.status', 'finalized')->where('x.included', 1)
            ->whereRaw('YEAR(r.pay_date) = ?', [(int) substr((string) $run->pay_date, 0, 4)])->sum('x.vacation_pay') + (float) $l->vacation_pay, 2);

        return ['version' => 1, 'country' => 'CA', 'jurisdiction' => $l->jurisdiction, 'plan' => $qc ? 'QPP' : 'CPP',
            'run' => ['id' => (int) $run->id, 'reference' => $run->reference, 'kind' => $run->kind, 'pay_date' => (string) $run->pay_date,
                'period_start' => (string) $run->period_start, 'period_end' => (string) $run->period_end, 'periods' => (int) $run->periods],
            'pay_type' => $l->pay_type, 'rate' => $l->rate !== null ? (float) $l->rate : null, 'hours' => (float) $l->hours,
            'this_pay' => $this_, 'ytd' => $after, 'ytd_sources' => $ytd['sources'] ?? null,
            'other_label' => $l->other_label, 'table_version' => $l->table_version, 'warnings' => json_decode((string) $l->warnings, true) ?: []];
    }

    // ---- Shapes ------------------------------------------------------------------------------------------------------

    public static function shapeRun(object $r, array $names = [], array $centres = []): array
    {
        return ['id' => (int) $r->id, 'reference' => $r->reference, 'kind' => $r->kind, 'adjusts_run_id' => $r->adjusts_run_id ? (int) $r->adjusts_run_id : null,
            'centre_id' => $r->centre_id ? (int) $r->centre_id : null, 'centre' => $r->centre_id ? ($centres[(int) $r->centre_id] ?? null) : null,
            'period_start' => (string) $r->period_start, 'period_end' => (string) $r->period_end, 'pay_date' => (string) $r->pay_date,
            'periods' => (int) $r->periods, 'status' => $r->status, 'totals' => json_decode((string) $r->totals, true) ?: null,
            'notes' => $r->notes, 'return_reason' => $r->return_reason,
            'created_by' => $names[(int) $r->created_by_id] ?? null, 'submitted_by' => $names[(int) $r->submitted_by_id] ?? null,
            'submitted_at' => $r->submitted_at ? (string) $r->submitted_at : null,
            'approved_by' => $names[(int) $r->approved_by_id] ?? null, 'approved_at' => $r->approved_at ? (string) $r->approved_at : null,
            'finalized_by' => $names[(int) $r->finalized_by_id] ?? null, 'finalized_at' => $r->finalized_at ? (string) $r->finalized_at : null,
            'created_at' => (string) $r->created_at];
    }

    public static function shapeLine(object $l, array $names = []): array
    {
        $ex = json_decode((string) $l->extras, true) ?: [];
        $out = ['id' => (int) $l->id, 'user_id' => (int) $l->user_id, 'name' => $names[(int) $l->user_id] ?? ('#' . $l->user_id),
            'included' => (bool) $l->included, 'state' => $l->state, 'error' => $l->error, 'warnings' => json_decode((string) $l->warnings, true) ?: [],
            'pay_type' => $l->pay_type, 'rate' => $l->rate !== null ? (float) $l->rate : null, 'hours' => (float) $l->hours, 'shifts' => (int) ($ex['shifts'] ?? 0),
            'jurisdiction' => $l->jurisdiction, 'table_version' => $l->table_version, 'note' => $l->note, 'other_label' => $l->other_label,
            'payroll_document_id' => $l->payroll_document_id ? (int) $l->payroll_document_id : null,
            'overtime' => $ex['overtime'] ?? [], 'claims' => $ex['claims'] ?? [],
            'paid_hours_before' => isset($ex['paid_minutes_before']) ? HrTime::hours((int) $ex['paid_minutes_before']) : null,
            'hours_now' => isset($ex['minutes_now']) ? HrTime::hours((int) $ex['minutes_now']) : null,
            'ytd_before' => json_decode((string) $l->ytd_before, true) ?: null];
        foreach (['regular', 'overtime', 'vacation_pay', 'bonus', 'benefits_cash', 'benefits_noncash', 'reimbursements', 'other_deduction', 'gross', 'cpp', 'cpp2', 'ei', 'qpip',
            'federal_tax', 'provincial_tax', 'income_tax', 'net', 'employer_cpp', 'employer_cpp2', 'employer_ei', 'employer_qpip'] as $k) {
            $out[$k === 'overtime' ? 'overtime_pay' : $k] = (float) $l->$k;
        }

        return $out;
    }

    public static function note(int $agencyId, int $actorId, string $action, int $runId, string $summary, array $extra = []): void
    {
        try {
            Audit::write(['agency_id' => $agencyId, 'user_id' => $actorId, 'action' => $action, 'entity_type' => 'payroll_run', 'entity_id' => $runId,
                'payload' => json_encode(['summary' => $summary] + $extra), 'created_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** "Nov 7 – Nov 20, 2026" */
    public static function periodText(string $a, string $b): string
    {
        $s = Carbon::parse($a);
        $e = Carbon::parse($b);

        return $s->year === $e->year ? $s->format('M j') . ' – ' . $e->format('M j, Y') : $s->format('M j, Y') . ' – ' . $e->format('M j, Y');
    }
}
