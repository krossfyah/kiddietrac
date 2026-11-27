<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): the source-deductions remittance report (CRA PD7A amounts).
 *
 * From FINALIZED runs only (what this payroll withheld; payslips that arrived from iLearn were remitted by that payroll).
 * Grouped into the remitting periods of the agency's remitter type by PAY DATE (CRA: deductions are due by when the pay
 * was made, not the period worked):
 *   regular        each calendar month, due the 15th of the next month
 *   quarterly      each quarter, due the 15th of the month after it (Apr 15, Jul 15, Oct 15, Jan 15)
 *   accelerated_1  pays on the 1st-15th due the 25th of that month; 16th-end due the 10th of the next month
 *   accelerated_2  pays on the 1st-7th, 8th-14th, 15th-21st, 22nd-end, each due 3 working days after it ends (weekends skipped)
 * A due date that lands on a weekend is shown as is with the note that CRA accepts it on the next business day; public
 * holidays are not moved (paying earlier is never late).
 *
 * CRA (PD7A): employee + employer CPP / CPP2, employee + employer EI, and income tax, for everyone outside Quebec; for Quebec
 * employees only federal tax and EI go to CRA. Revenu Quebec: QPP / QPP2 (both sides), QPIP (both sides) and Quebec income
 * tax (the employer's Health Services Fund, CNESST and labour-standards contributions are not worked out here).
 * Workers' compensation (WSIB) is an estimate from the rate in the employer settings, report only.
 * KiddieTrac never pays or files these: the agency remits them through its bank or CRA My Business Account.
 */
final class Remittance
{
    /** [period key, start, end, due date] for a pay date under a remitter type. */
    public static function periodFor(string $payDate, string $type): array
    {
        $d = Carbon::parse($payDate);
        $y = $d->year;
        $m = $d->month;
        switch ($type) {
            case 'quarterly':
                $q = intdiv($m - 1, 3);
                $s = Carbon::create($y, $q * 3 + 1, 1);
                $e = $s->copy()->addMonths(2)->endOfMonth();

                return [$y . '-Q' . ($q + 1), $s->toDateString(), $e->toDateString(), $e->copy()->addDay()->day(15)->toDateString()];
            case 'accelerated_1':
                if ($d->day <= 15) {
                    return [$d->format('Y-m') . 'a', $d->copy()->day(1)->toDateString(), $d->copy()->day(15)->toDateString(), $d->copy()->day(25)->toDateString()];
                }

                return [$d->format('Y-m') . 'b', $d->copy()->day(16)->toDateString(), $d->copy()->endOfMonth()->toDateString(),
                    $d->copy()->startOfMonth()->addMonth()->day(10)->toDateString()];
            case 'accelerated_2':
                $bands = [[1, 7], [8, 14], [15, 21], [22, (int) $d->copy()->endOfMonth()->day]];
                foreach ($bands as $i => [$a, $b]) {
                    if ($d->day >= $a && $d->day <= $b) {
                        $end = $d->copy()->day($b);

                        return [$d->format('Y-m') . '-' . ($i + 1), $d->copy()->day($a)->toDateString(), $end->toDateString(), self::workingDaysAfter($end, 3)->toDateString()];
                    }
                }
                // no break: unreachable
            default:
                return [$d->format('Y-m'), $d->copy()->startOfMonth()->toDateString(), $d->copy()->endOfMonth()->toDateString(),
                    $d->copy()->startOfMonth()->addMonth()->day(15)->toDateString()];
        }
    }

    private static function workingDaysAfter(Carbon $d, int $n): Carbon
    {
        $x = $d->copy();
        while ($n > 0) {
            $x->addDay();
            if (! $x->isWeekend()) {
                $n--;
            }
        }

        return $x;
    }

    /** The report for a tax year (by pay date). $centres limits to runs of those centres (directors); null = agency. */
    public static function report(int $agencyId, int $year, ?string $type = null, ?array $centres = null): array
    {
        $emp = PayrollEmployer::settings($agencyId);
        $type = $type && isset(PayrollEmployer::REMITTERS[$type]) ? $type : $emp['remitter_type'];
        $rows = DB::table('payroll_run_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.run_id')
            ->where('r.agency_id', $agencyId)->where('r.status', 'finalized')->where('l.included', 1)->whereNotNull('l.payroll_document_id')
            ->whereRaw('YEAR(r.pay_date) = ?', [$year])
            ->when($centres !== null, fn ($q) => $q->whereIn('r.centre_id', $centres ?: [0]))
            ->orderBy('r.pay_date')
            ->get(['r.id as run_id', 'r.reference', 'r.pay_date', 'l.user_id', 'l.jurisdiction', 'l.gross', 'l.cpp', 'l.cpp2', 'l.ei', 'l.qpip', 'l.federal_tax',
                'l.provincial_tax', 'l.income_tax', 'l.employer_cpp', 'l.employer_cpp2', 'l.employer_ei', 'l.employer_qpip', 'l.benefits_noncash']);
        $per = [];
        foreach ($rows as $l) {
            [$key, $s, $e, $due] = self::periodFor((string) $l->pay_date, $type);
            $p = &$per[$key];
            $p ??= ['key' => $key, 'start' => $s, 'end' => $e, 'due' => $due, 'runs' => [], 'pay_dates' => [],
                'gross' => 0.0, 'cpp_employee' => 0.0, 'cpp_employer' => 0.0, 'ei_employee' => 0.0, 'ei_employer' => 0.0, 'tax' => 0.0, 'cra_total' => 0.0,
                'rq_qpp_employee' => 0.0, 'rq_qpp_employer' => 0.0, 'rq_qpip_employee' => 0.0, 'rq_qpip_employer' => 0.0, 'rq_tax' => 0.0, 'rq_total' => 0.0,
                'insurable' => 0.0, 'last_pay_date' => null, 'employees_last_pay' => []];
            $p['runs'][$l->run_id] = $l->reference;
            $p['pay_dates'][(string) $l->pay_date] = true;
            $qc = $l->jurisdiction === 'QC';
            $p['gross'] += (float) $l->gross;
            $p['insurable'] += (float) $l->gross - (float) $l->benefits_noncash;
            $p['ei_employee'] += (float) $l->ei;
            $p['ei_employer'] += (float) $l->employer_ei;
            if ($qc) {
                $p['tax'] += (float) $l->federal_tax;
                $p['rq_qpp_employee'] += (float) $l->cpp + (float) $l->cpp2;
                $p['rq_qpp_employer'] += (float) $l->employer_cpp + (float) $l->employer_cpp2;
                $p['rq_qpip_employee'] += (float) $l->qpip;
                $p['rq_qpip_employer'] += (float) $l->employer_qpip;
                $p['rq_tax'] += (float) $l->provincial_tax;
            } else {
                $p['cpp_employee'] += (float) $l->cpp + (float) $l->cpp2;
                $p['cpp_employer'] += (float) $l->employer_cpp + (float) $l->employer_cpp2;
                $p['tax'] += (float) $l->income_tax;
            }
            if ($p['last_pay_date'] === null || (string) $l->pay_date > $p['last_pay_date']) {
                $p['last_pay_date'] = (string) $l->pay_date;
                $p['employees_last_pay'] = [];
            }
            if ((string) $l->pay_date === $p['last_pay_date']) {
                $p['employees_last_pay'][(int) $l->user_id] = true;
            }
            unset($p);
        }
        $out = [];
        $sum = ['gross' => 0.0, 'cra_total' => 0.0, 'rq_total' => 0.0, 'wsib' => 0.0];
        foreach ($per as $p) {
            foreach ($p as $k => $v) {
                if (is_float($v)) {
                    $p[$k] = round($v, 2);
                }
            }
            $p['cra_total'] = round($p['cpp_employee'] + $p['cpp_employer'] + $p['ei_employee'] + $p['ei_employer'] + $p['tax'], 2);
            $p['rq_total'] = round($p['rq_qpp_employee'] + $p['rq_qpp_employer'] + $p['rq_qpip_employee'] + $p['rq_qpip_employer'] + $p['rq_tax'], 2);
            $p['employees_last_pay'] = count($p['employees_last_pay']);
            $p['runs'] = array_values($p['runs']);
            $p['pay_dates'] = array_keys($p['pay_dates']);
            $p['wsib'] = $emp['wsib_rate'] !== null ? round($p['insurable'] * $emp['wsib_rate'] / 100, 2) : null;
            $dow = Carbon::parse($p['due'])->dayOfWeekIso;
            $p['due_note'] = $dow >= 6 ? 'Lands on a ' . Carbon::parse($p['due'])->format('l') . ': CRA accepts it on the next business day.' : null;
            $sum['gross'] += $p['gross'];
            $sum['cra_total'] += $p['cra_total'];
            $sum['rq_total'] += $p['rq_total'];
            $sum['wsib'] += (float) ($p['wsib'] ?? 0);
            $out[] = $p;
        }

        return ['year' => $year, 'remitter_type' => $type, 'remitter_label' => PayrollEmployer::REMITTERS[$type], 'rp_number' => $emp['rp_number'],
            'rq_number' => $emp['rq_number'], 'wsib_rate' => $emp['wsib_rate'], 'periods' => $out,
            'totals' => array_map(fn ($v) => round($v, 2), $sum), 'has_quebec' => $sum['rq_total'] > 0];
    }
}
