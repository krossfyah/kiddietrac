<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Payroll\CaCalculator;
use App\Support\Payroll\TaxTables;
use Illuminate\Console\Command;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): proves CaCalculator against CRA's Payroll Deductions Online
 * Calculator (PDOC) and Revenu Quebec's published worked examples. Every fixture case is recomputed; any amount more
 * than the fixture's tolerance ($0.05) away from PDOC fails the command (exit 1). Read-only: no database.
 */
class PayrollCalcCheckCommand extends Command
{
    protected $signature = 'payroll:calc-check {--fixture= : Fixture JSON (default resources/payroll/ca/fixtures/pdoc-2026.json)} {--case= : Only this case id} {--verbose-factors : Print every factor for each case}';

    protected $description = 'Recompute the PDOC-verified payroll cases with CaCalculator; fail on any variance over $0.05.';

    private const FREQ = ['WEEKLY_52PP' => 52, 'BI_WEEKLY' => 26, 'SEMI_MONTHLY' => 24, 'MONTHLY_12PP' => 12];

    public function handle(): int
    {
        $path = $this->option('fixture') ?: resource_path('payroll/ca/fixtures/pdoc-2026.json');
        $fx = json_decode((string) @file_get_contents($path), true);
        if (! is_array($fx) || empty($fx['cases'])) {
            $this->error('Fixture missing or unreadable: ' . $path);

            return 1;
        }
        $tol = (float) ($fx['tolerance'] ?? 0.05);
        $only = $this->option('case');
        $rows = [];
        $fails = 0;
        $checked = 0;
        $byJ = [];
        foreach ($fx['cases'] as $c) {
            $i = $c['input'];
            if ($only && $i['id'] !== $only) {
                continue;
            }
            $in = ['jurisdiction' => $i['j'], 'pay_date' => $i['date'], 'periods' => self::FREQ[$i['freq']],
                'salary' => $i['salary'], 'vacation_pay' => $i['vac'] ?? 0, 'bonus' => $i['bonus'] ?? 0, 'pm' => $i['pm'] ?? 12,
                'cpp_exempt' => ($i['cpp'] ?? '') === 'exempt', 'ei_exempt' => ($i['ei'] ?? '') === 'exempt',
                'ytd' => ['pi' => $i['piYtd'] ?? 0, 'cpp' => $i['cppYtd'] ?? 0, 'cpp2' => $i['cpp2Ytd'] ?? 0, 'ie' => $i['ieYtd'] ?? 0, 'ei' => $i['eiYtd'] ?? 0]];
            $dc = $fx['pdoc_default_claims'] ?? [];
            $in['federal_claim'] = $i['fed'] ?? ($dc['federal'] ?? null);
            if ($i['j'] !== 'QC') {
                $in['provincial_claim'] = $i['pv'] ?? (($i['date'] < '2026-07-01' ? ($dc['provincial_before_july'][$i['j']] ?? null) : null) ?? ($dc['provincial'][$i['j']] ?? null));
            }
            $r = CaCalculator::calculate($in);
            $e = $r['employee'];
            $map = ['fed_tax' => $e['federal_tax'], 'cpp' => $e['cpp'], 'cpp2' => $e['cpp2'], 'ei' => $e['ei'],
                'bonus_fed_tax' => $e['bonus_federal_tax']];
            if ($i['j'] !== 'QC') {
                $map += ['prov_tax' => $e['provincial_tax'], 'bonus_prov_tax' => $e['bonus_provincial_tax'], 'net' => $e['net']];
            }
            $worst = 0.0;
            $bad = [];
            foreach ($map as $k => $mine) {
                if (! array_key_exists($k, $c['pdoc']) || $c['pdoc'][$k] === null) {
                    continue;
                }
                $d = round($mine - (float) $c['pdoc'][$k], 2);
                $checked++;
                $worst = max($worst, abs($d));
                if (abs($d) > $tol + 1e-9) {
                    $bad[] = $k . ' ' . number_format($mine, 2) . ' vs ' . number_format((float) $c['pdoc'][$k], 2);
                }
            }
            $fails += $bad ? 1 : 0;
            $byJ[$i['j']] = ($byJ[$i['j']] ?? []) + ['cases' => 0, 'worst' => 0.0, 'fail' => 0];
            $byJ[$i['j']]['cases']++;
            $byJ[$i['j']]['worst'] = max($byJ[$i['j']]['worst'], $worst);
            $byJ[$i['j']]['fail'] += $bad ? 1 : 0;
            $rows[] = [$i['id'], $r['table_version'], number_format($e['federal_tax'], 2), $i['j'] === 'QC' ? '(RQ)' : number_format($e['provincial_tax'], 2),
                number_format($e['cpp'], 2), number_format($e['cpp2'], 2), number_format($e['ei'], 2), number_format($worst, 2), $bad ? 'FAIL ' . implode('; ', $bad) : 'ok'];
            if ($this->option('verbose-factors')) {
                $this->line($i['id'] . ' ' . json_encode($r['factors']));
            }
        }
        $this->table(['case', 'tables', 'federal', 'provincial', 'CPP/QPP', 'CPP2', 'EI', 'max diff', 'result'], $rows);

        // Revenu Quebec's own worked examples (PDOC does not calculate Quebec income tax).
        $q = $fx['quebec_tax_cases'] ?? null;
        $qRows = [];
        if ($q && ! $only) {
            $t = TaxTables::forDate('CA', '2026-07-01');
            foreach ($q['cases'] as $c) {
                $v = $c['input'];
                $res = CaCalculator::quebecTax($t['quebec'], ['P' => $v['P'], 'G' => $v['G'], 'F' => $v['F'], 'C' => $v['C'], 'C2' => $v['C2'],
                    'S3' => $v['G'], 'B2' => 0, 'E' => $v['E'], 'J' => 0, 'L' => 0, 'Q' => $v['Q'], 'Q1' => $v['Q1']]);
                $d = round($res['A'] - $c['expected']['A'], 2);
                $checked++;
                $ok = abs($d) <= $tol + 1e-9;
                $fails += $ok ? 0 : 1;
                $byJ['QC']['rq'] = ($byJ['QC']['rq'] ?? 0) + 1;
                $byJ['QC']['worst'] = max($byJ['QC']['worst'] ?? 0, abs($d));
                $qRows[] = [$c['id'], $c['ref'], number_format($res['A'], 2), number_format($c['expected']['A'], 2), number_format($d, 2), $ok ? 'ok' : 'FAIL'];
            }
            foreach ($q['qpp_cases'] ?? [] as $c) {
                $v = $c['input'];
                $res = CaCalculator::qpp($t['qpp'], $v['P'], (float) $v['S3'], $v['pm'], (float) $v['A5'], (float) $v['A8'], (float) $v['S5']);
                $d = max(abs($res['C'] - $c['expected']['C']), abs($res['C2'] - $c['expected']['C2']));
                $checked += 2;
                $ok = $d <= $tol + 1e-9;
                $fails += $ok ? 0 : 1;
                $qRows[] = [$c['id'], $c['ref'], 'C ' . number_format($res['C'], 2) . ' / C2 ' . number_format($res['C2'], 2),
                    'C ' . number_format($c['expected']['C'], 2) . ' / C2 ' . number_format($c['expected']['C2'], 2), number_format($d, 2), $ok ? 'ok' : 'FAIL'];
            }
            $this->table(['Revenu Quebec example', 'reference', 'KiddieTrac', 'published', 'diff', 'result'], $qRows);
        }

        foreach ($fx['known_differences'] ?? [] as $k) {
            $this->warn('Known PDOC difference (not pass/fail): ' . ($k['case']['input']['id'] ?? '?') . ' - ' . $k['explanation']);
        }
        ksort($byJ);
        $this->table(['province', 'PDOC cases', 'RQ examples', 'worst diff', 'failed'],
            array_map(fn ($k, $v) => [$k, $v['cases'] ?? 0, $v['rq'] ?? 0, number_format($v['worst'] ?? 0, 2), $v['fail'] ?? 0], array_keys($byJ), $byJ));
        $this->line(sprintf('%d amounts checked against %s (PDOC run %s), tolerance $%.2f: %s.', $checked, basename($path), $fx['pdoc_run_date'] ?? '?', $tol,
            $fails ? $fails . ' case(s) FAILED' : 'all within tolerance'));

        return $fails ? 1 : 0;
    }
}
