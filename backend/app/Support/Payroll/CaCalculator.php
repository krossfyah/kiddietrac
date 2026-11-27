<?php

declare(strict_types=1);

namespace App\Support\Payroll;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): the ONE Canadian payroll deductions calculator.
 *
 * CRA T4127 "Option 1" for periodic pay (federal, every province and territory except Quebec income tax), CPP / CPP2,
 * QPP / QPP2, EI, QPIP, and Revenu Quebec TP-1015.F-V section 2.1 for Quebec income tax. Rates come only from the
 * edition files read by TaxTables (never typed here). Non-periodic pay (bonus, retro, vacation paid out instead of
 * taken) uses the T4127 regular bonus method (tax with the payment minus tax without it) and TP-1015.F Method 2.
 * Proven against CRA PDOC by `php artisan payroll:calc-check` (fixture resources/payroll/ca/fixtures).
 *
 * Pure: no database, no clock. calculate() takes every input and returns every factor it used plus the table
 * version, so a past pay can always be explained.
 *
 * Inputs (amounts per pay unless named annual / ytd):
 *   jurisdiction  AB BC MB NB NL NS NT NU ON PE QC SK YT (province of employment)
 *   pay_date      Y-m-d (picks the table edition)       periods  P (52, 26, 24, 12 or any other)
 *   salary        regular salary / wages               vacation_pay  vacation pay paid with the pay (periodic)
 *   benefits_cash taxable cash benefits (insurable)    benefits_noncash  taxable non-cash benefits (not insurable)
 *   bonus         non-periodic payment in this pay (B) bonus_ytd  earlier non-periodic payments this year (B1)
 *   rpp_rrsp      F (registered plan deductions)       union_dues  U1      alimony  F2
 *   annual_f1     F1 annual authorised deductions      annual_hd   HD prescribed-zone deduction
 *   federal_claim TC (null = BPAF formula)             provincial_claim  TCP (null = the province's basic amount)
 *   additional_tax L (per pay, from TD1)              k3 / k3p  other authorised annual credits
 *   on_dependants Ontario Y: count of dependants under 19 + disabled dependants
 *   tax_exempt    claim code E (no income tax; Ontario Health Premium still applies)
 *   cpp_exempt / ei_exempt / qpip_exempt               pm  pensionable months (12 unless proration applies)
 *   ytd           [pi, cpp, cpp2, ie, ei, qpip, qpip_employer, f5b]  with THIS employer, before this pay
 *   qc_claim      Quebec TP-1015.3 E (null = basic amount)   qc_deductions  J (line 19)   qc_additional_tax  L
 */
final class CaCalculator
{
    public const JURISDICTIONS = ['AB', 'BC', 'MB', 'NB', 'NL', 'NS', 'NT', 'NU', 'ON', 'PE', 'QC', 'SK', 'YT'];

    public const NAMES = ['AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
        'ON' => 'Ontario', 'PE' => 'Prince Edward Island', 'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon'];

    /** @throws \InvalidArgumentException on a bad input; \RuntimeException when no table covers the pay date. */
    public static function calculate(array $in): array
    {
        $j = strtoupper((string) ($in['jurisdiction'] ?? ''));
        if (! in_array($j, self::JURISDICTIONS, true)) {
            throw new \InvalidArgumentException('Unknown province or territory of employment: ' . $j);
        }
        $date = (string) ($in['pay_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            throw new \InvalidArgumentException('pay_date must be Y-m-d.');
        }
        $P = (int) ($in['periods'] ?? 0);
        if ($P < 1 || $P > 366) {
            throw new \InvalidArgumentException('periods must be the number of pay periods in the year.');
        }
        $t = TaxTables::forDate('CA', $date);
        if ($t === null) {
            throw new \RuntimeException('No CRA payroll tables are loaded for ' . substr($date, 0, 4) . '. Nothing was calculated.');
        }
        $qc = $j === 'QC';
        if ($qc && empty($t['quebec']['brackets'])) {
            throw new \RuntimeException('No Revenu Quebec (TP-1015.F) tables are loaded for ' . substr($date, 0, 4) . '. Nothing was calculated.');
        }
        $n = fn ($k) => max(0.0, (float) ($in[$k] ?? 0));
        $ytd = (array) ($in['ytd'] ?? []);
        $y = fn ($k) => max(0.0, (float) ($ytd[$k] ?? 0));
        $pm = max(0, min(12, (int) ($in['pm'] ?? 12)));

        $salary = $n('salary');
        $vac = $n('vacation_pay');
        $bCash = $n('benefits_cash');
        $bNon = $n('benefits_noncash');
        $B = $n('bonus');
        $B1 = $n('bonus_ytd');
        $I = $salary + $vac + $bCash + $bNon;          // periodic gross (T4127 factor I)
        $PI = $I + $B;                                  // pensionable earnings this pay
        $IEreg = $salary + $vac + $bCash;               // insurable earnings (non-cash benefits are not insurable)
        $IE = $IEreg + $B;

        $f = [];
        $warn = [];

        // ---- CPP / QPP (T4127 chapter 6; TP-1015.F section 3) -------------------------------------------------------
        $plan = $qc ? $t['qpp'] : $t['cpp'];
        $cppMax = $plan['max'] * $pm / 12;
        $exemptP = floor($plan['exemption'] / $P * 100) / 100;      // truncated, never rounded (Table 6.1)
        $hasRegular = $I > 0;
        $C = $C2 = $Creg = 0.0;
        if (empty($in['cpp_exempt']) && $pm > 0) {
            $left = max(0.0, $cppMax - $y('cpp'));
            $C = self::r(max(0.0, min($left, $plan['rate'] * ($PI - ($hasRegular ? $exemptP : 0)))));
            $Creg = self::r(max(0.0, min($left, $plan['rate'] * ($I - $exemptP))));
            if ($Creg > $C) {
                $Creg = $C;
            }
            $W = max($y('pi'), $plan['ympe'] * $pm / 12);
            $C2 = self::r(max(0.0, min($plan['second_max'] * $pm / 12 - $y('cpp2'), ($y('pi') + $PI - $W) * $plan['second_rate'])));
        }
        $Cbon = $C - $Creg;
        $f += ['P' => $P, 'I' => $I, 'PI' => $PI, 'B' => $B, 'B1' => $B1, 'PM' => $pm, 'cpp_exemption_per_pay' => $exemptP, 'C' => $C, 'C2' => $C2];

        // ---- EI (chapter 7) -------------------------------------------------------------------------------------------
        $ei = $t['ei'];
        $eiRate = $qc ? $ei['qc_rate'] : $ei['rate'];
        $eiMax = $qc ? $ei['qc_max_premium'] : $ei['max_premium'];
        $EI = $EIreg = 0.0;
        if (empty($in['ei_exempt'])) {
            $ieLeft = max(0.0, $ei['max_insurable'] - $y('ie'));
            $eiLeft = max(0.0, $eiMax - $y('ei'));
            $EI = self::r(min($eiLeft, $eiRate * min($IE, $ieLeft)));
            $EIreg = min($EI, self::r(min($eiLeft, $eiRate * min($IEreg, $ieLeft))));
        }
        $EIbon = $EI - $EIreg;
        $f['EI'] = $EI;

        // ---- QPIP (Quebec only, TP-1015.F section 4) -----------------------------------------------------------------
        $QPIP = $QPIPer = 0.0;
        if ($qc && empty($in['qpip_exempt'])) {
            $q = $t['qpip'];
            $QPIP = self::r(max(0.0, min($q['employee_max'] - $y('qpip'), $q['employee_rate'] * $IE)));
            $erYtd = isset($ytd['qpip_employer']) ? $y('qpip_employer') : $y('qpip') * $q['employer_rate'] / $q['employee_rate'];
            $QPIPer = self::r(max(0.0, min($q['employer_max'] - $erYtd, $q['employer_rate'] * $IE)));
        }

        // ---- Income tax -----------------------------------------------------------------------------------------------
        $F = $n('rpp_rrsp');
        $F2 = $n('alimony');
        $U1 = $n('union_dues');
        $HD = $n('annual_hd');
        $F1 = $n('annual_f1');
        $L = $n('additional_tax');
        $F5 = self::r($C * ($plan['first_additional_rate'] / $plan['rate']) + $C2);
        $F5A = $PI > 0 ? self::r($F5 * (($PI - $B) / $PI)) : 0.0;
        $F5B = $F5 - $F5A;
        $f += ['F5' => $F5, 'F5A' => $F5A, 'F5B' => $F5B];

        $regA = $P * ($I - $F - $F2 - $F5A - $U1) - $HD - $F1;
        // K2 credits (T4127 step 2 note): annualise this pay's contribution, but never below what is already
        // deducted this year (D / D1), and use the full annual maximum from the pay in which the maximum is reached.
        $cppAnnual = max($P * $Creg, $y('cpp'));
        if ($pm > 0 && empty($in['cpp_exempt']) && $y('cpp') + $C >= $cppMax - 0.005) {
            $cppAnnual = $cppMax;
        }
        $eiAnnual = max($P * $EIreg, $y('ei'));
        if (empty($in['ei_exempt']) && $y('ei') + $EI >= $eiMax - 0.005) {
            $eiAnnual = $eiMax;
        }
        $qpipRate = (float) ($t['qpip']['employee_rate'] ?? 0);
        $qpipAnnual = max($P * $IEreg * $qpipRate, $y('qpip'));
        if ($qc && $y('qpip') + $QPIP >= $t['qpip']['employee_max'] - 0.005) {
            $qpipAnnual = (float) $t['qpip']['employee_max'];
        }
        $ctx = ['t' => $t, 'j' => $j, 'P' => $P, 'pm' => $pm, 'I' => $I, 'HD' => $HD, 'in' => $in,
            'cppCredit' => $cppAnnual, 'eiCredit' => $eiAnnual, 'qpipCredit' => $qc ? $qpipAnnual : 0.0];
        $f += ['cpp_credit_base' => $cppAnnual, 'ei_credit_base' => $eiAnnual];

        $fed = $prov = 0.0;
        $bFed = $bProv = 0.0;
        if (! empty($in['tax_exempt'])) {
            // Claim code E: no income tax; the Ontario Health Premium is still payable on income over $20,000.
            if ($j === 'ON') {
                $prov = self::r(self::onHealthPremium($t['provinces']['ON'], max(0.0, $regA)) / $P);
            }
            $f['tax_exempt'] = true;
        } elseif ($regA < 0) {
            $fed = $L;
            $f['A'] = 0.0;
        } else {
            $reg = self::annualTax($ctx, $regA);
            $f += self::prefix('', $reg['factors']);
            $fed = self::r($reg['T1'] / $P + $L);
            $prov = $qc ? 0.0 : self::r($reg['T2'] / $P);
            if ($B > 0) {
                // T4127 regular bonus method: annual tax with the payment minus annual tax without it.
                $F3 = $n('bonus_rpp_rrsp');
                $F4 = $n('bonus_ytd_rpp_rrsp');
                $prevB = max(0.0, $B1 - $F4 - $y('f5b'));
                $A1 = max(0.0, $regA) + max(0.0, $B - $F3 - $F5B) + $prevB;
                $A2 = max(0.0, $regA) + $prevB;
                $f += ['bonus_A_with' => $A1, 'bonus_A_without' => $A2];
                if ($A1 <= $t['bonus']['flat_threshold']) {
                    $bFed = self::r($B * ($qc ? $t['bonus']['flat_rate_qc'] : $t['bonus']['flat_rate']));
                } else {
                    $ctx1 = $ctx;
                    $ctx1['cppCredit'] += $Cbon + (float) ($ytd['cpp_bonus'] ?? 0);
                    $ctx1['eiCredit'] += $EIbon + (float) ($ytd['ei_bonus'] ?? 0);
                    $ctx1['qpipCredit'] += $qc ? $B * $qpipRate : 0.0;
                    $ctx2 = $ctx;
                    $ctx2['cppCredit'] += (float) ($ytd['cpp_bonus'] ?? 0);
                    $ctx2['eiCredit'] += (float) ($ytd['ei_bonus'] ?? 0);
                    $w = self::annualTax($ctx1, $A1);
                    $wo = self::annualTax($ctx2, $A2);
                    $bFed = self::r($w['T1'] - $wo['T1']);
                    $bProv = $qc ? 0.0 : self::r($w['T2'] - $wo['T2']);
                }
            }
        }

        // ---- Quebec income tax (TP-1015.F-V 2.1.1 regular payments; 2.1.2 Method 2 for lump sums) ---------------------
        $qcTax = $qcBonusTax = 0.0;
        if ($qc && empty($in['tax_exempt'])) {
            $qr = self::quebecTax($t['quebec'], [
                'P' => $P, 'G' => $I, 'F' => $F, 'C' => $C, 'C2' => $C2, 'S3' => $PI, 'B2' => $B, 'B1' => $B1,
                'E' => $in['qc_claim'] ?? null, 'J' => $n('qc_deductions'), 'L' => $n('qc_additional_tax'),
                'Q' => $n('qc_ftq'), 'Q1' => $n('qc_fondaction'), 'CSB1' => $y('qc_csb'),
            ]);
            $qcTax = $qr['A'];
            $qcBonusTax = $qr['A_bonus'];
            $f += self::prefix('qc_', $qr['factors']);
            $prov = $qcTax;
            $bProv = $qcBonusTax;
        }

        $tax = self::r($fed + $prov + $bFed + $bProv);
        $gross = self::r($PI);
        $deductions = self::r($tax + $C + $C2 + $EI + $QPIP);
        if (($in['federal_claim'] ?? null) === null) {
            $warn[] = 'No federal TD1 on file: the basic personal amount was used.';
        }
        if (! $qc && ($in['provincial_claim'] ?? null) === null) {
            $warn[] = 'No ' . self::NAMES[$j] . ' TD1 on file: the provincial basic amount was used.';
        }
        if ($qc && ($in['qc_claim'] ?? null) === null) {
            $warn[] = 'No Quebec TP-1015.3 on file: the Quebec basic personal amount was used.';
        }

        return [
            'country' => 'CA', 'jurisdiction' => $j, 'table_version' => (string) $t['version'], 'edition' => (string) $t['edition'],
            'quebec_edition' => $qc ? (string) ($t['quebec']['edition'] ?? '') : null,
            'employee' => [
                'gross' => $gross,
                'cpp' => $C, 'cpp2' => $C2, 'ei' => $EI, 'qpip' => $QPIP,
                'federal_tax' => self::r($fed), 'provincial_tax' => self::r($prov),
                'bonus_federal_tax' => $bFed, 'bonus_provincial_tax' => $bProv,
                'income_tax' => $tax, 'total_deductions' => $deductions, 'net' => self::r($gross - $bNon - $deductions),
            ],
            'employer' => ['cpp' => $C, 'cpp2' => $C2, 'ei' => self::r($EI * $ei['employer_factor']), 'qpip' => $QPIPer],
            'plan' => $qc ? 'QPP' : 'CPP',
            'factors' => $f,
            'warnings' => $warn,
            'inputs' => $in,
        ];
    }

    /** CPP pensionable months for a year from the birth date: starts the month after turning 18, ends the month turning 70. */
    public static function pensionableMonths(int $year, ?string $dob): int
    {
        if (! $dob || ! preg_match('/^(\d{4})-(\d{2})-\d{2}/', $dob, $m)) {
            return 12;
        }
        $by = (int) $m[1];
        $bm = (int) $m[2];
        $start = 1;
        $end = 12;
        if ($year < $by + 18 || $year > $by + 70) {
            return 0;
        }
        if ($year === $by + 18) {
            $start = $bm + 1;
        }
        if ($year === $by + 70) {
            $end = $bm;
        }

        return max(0, $end - $start + 1);
    }

    /** Annual T1 (federal) and T2 (provincial) for an annual taxable income A, T4127 steps 2-5. */
    private static function annualTax(array $c, float $A): array
    {
        $t = $c['t'];
        $j = $c['j'];
        $in = $c['in'];
        $P = $c['P'];
        $qc = $j === 'QC';
        $fd = $t['federal'];
        $low = (float) $fd['lowest_rate'];
        $NI = $A + $c['HD'];
        [$R, $K] = self::bracket($fd['brackets'], $A);
        $TC = array_key_exists('federal_claim', $in) && $in['federal_claim'] !== null ? (float) $in['federal_claim'] : self::bpaf($fd['bpaf'], $NI);
        $K1 = $low * $TC;
        $plan = $qc ? $t['qpp'] : $t['cpp'];
        $eiMax = $qc ? $t['ei']['qc_max_premium'] : $t['ei']['max_premium'];
        $cppBase = min($plan['base_max'] * $c['pm'] / 12, $c['cppCredit'] * ($plan['base_rate'] / $plan['rate']));
        $eiCr = min($eiMax, $c['eiCredit']);
        $K2 = $low * $cppBase + $low * $eiCr;
        if ($qc) {
            $K2 += $low * min($t['qpip']['employee_max'], $c['qpipCredit']);
        }
        $K3 = max(0.0, (float) ($in['k3'] ?? 0));
        $annualGross = $P * $c['I'];
        $K4 = min($low * $annualGross, $low * $fd['cea']);
        $T3 = max(0.0, $R * $A - $K - $K1 - $K2 - $K3 - $K4);
        $T1 = $qc ? max(0.0, $T3 - $fd['quebec_abatement'] * $T3) : $T3;
        $fx = ['A' => $A, 'R' => $R, 'K' => $K, 'TC' => $TC, 'K1' => $K1, 'K2' => $K2, 'K3' => $K3, 'K4' => $K4, 'T3' => $T3, 'T1' => $T1];
        if ($qc) {
            return ['T1' => $T1, 'T2' => 0.0, 'factors' => $fx];
        }

        $p = $t['provinces'][$j];
        [$V, $KP] = self::bracket($p['brackets'], $A);
        $plow = (float) $p['brackets'][0]['rate'];
        if (array_key_exists('provincial_claim', $in) && $in['provincial_claim'] !== null) {
            $TCP = (float) $in['provincial_claim'];
        } else {
            $bf = $p['basic_formula'] ?? null;
            if (($bf['kind'] ?? '') === 'federal') {
                $TCP = self::bpaf($fd['bpaf'], $NI);
            } elseif (($bf['kind'] ?? '') === 'phase_out') {
                $TCP = self::phaseOut($bf, $NI);
            } else {
                $TCP = (float) $p['basic'];
            }
        }
        $K1P = $plow * $TCP;
        $K2P = $plow * $cppBase + $plow * $eiCr;
        $K3P = max(0.0, (float) ($in['k3p'] ?? 0));
        $K4P = isset($p['cea']) ? min($plow * $annualGross, $plow * $p['cea']) : 0.0;
        $K5P = isset($p['k5p']) ? max(0.0, ($K1P + $K2P - $p['k5p']['threshold']) * $p['k5p']['rate']) : 0.0;
        $T4 = max(0.0, $V * $A - $KP - $K1P - $K2P - $K3P - $K4P - $K5P);
        $V1 = 0.0;
        foreach ($p['surtax'] ?? [] as $s) {
            if ($T4 > $s['over']) {
                $V1 += $s['rate'] * ($T4 - $s['over']);
            }
        }
        $V2 = isset($p['health_premium']) ? self::onHealthPremium($p, $A) : 0.0;
        $S = 0.0;
        $red = $p['reduction'] ?? null;
        if (($red['kind'] ?? '') === 'on') {
            $Y = max(0, (int) ($in['on_dependants'] ?? 0)) * $red['y_per_dependant'];
            $S = max(0.0, min($T4 + $V1, 2 * ($red['s2'] + $Y) - ($T4 + $V1)));
        } elseif (($red['kind'] ?? '') === 'bc') {
            if ($A <= $red['from']) {
                $S = min($T4, $red['s2']);
            } elseif ($A <= $red['to']) {
                $S = max(0.0, min($T4, $red['s2'] - ($A - $red['from']) * $red['rate']));
            }
        }
        $T2 = max(0.0, $T4 + $V1 + $V2 - $S);
        $fx += ['V' => $V, 'KP' => $KP, 'TCP' => $TCP, 'K1P' => $K1P, 'K2P' => $K2P, 'K3P' => $K3P, 'K4P' => $K4P, 'K5P' => $K5P,
            'T4' => $T4, 'V1' => $V1, 'V2' => $V2, 'S' => $S, 'T2' => $T2];

        return ['T1' => $T1, 'T2' => $T2, 'factors' => $fx];
    }

    /** Quebec income tax per pay (TP-1015.F-V 2.1.1), plus Method 2 tax on a lump sum paid in the same pay. */
    public static function quebecTax(array $q, array $v): array
    {
        $P = (int) $v['P'];
        $G = (float) $v['G'];
        $S3 = (float) $v['S3'];
        $B2 = (float) ($v['B2'] ?? 0);
        $H = self::r(min($q['workers_deduction']['rate'] * $G, $q['workers_deduction']['max'] / $P));
        $CS = (float) $v['C'] * (0.01 / 0.063) + (float) $v['C2'];
        $CSA = $S3 > 0 ? self::r($CS * (($S3 - $B2) / $S3)) : 0.0;
        $CSB = $S3 > 0 ? self::r($CS * ($B2 / $S3)) : 0.0;
        $J = (float) ($v['J'] ?? 0);
        $I = max(0.0, $P * ($G - (float) $v['F'] - $H - $CSA) - $J);
        $E = $v['E'] === null ? (float) $q['basic'] : (float) $v['E'];
        $E = floor($E + 0.5);
        [$T, $K] = self::bracket($q['brackets'], $I);
        $cr = (float) $q['credit_rate'];
        $lf = (float) $q['labour_fund_credit_rate'];
        $Y = max(0.0, $T * $I - $K - $cr * $E - $lf * $P * (float) ($v['Q'] ?? 0) - $lf * $P * (float) ($v['Q1'] ?? 0));
        $A = self::r(max(0.0, $Y / $P + (float) ($v['L'] ?? 0)));
        $Ab = 0.0;
        $Ib = null;
        if ($B2 > 0) {
            if ($P * $G + (float) ($v['B1'] ?? 0) + $B2 <= $q['lump_sum']['threshold']) {
                $Ab = self::r($B2 * $q['lump_sum']['flat_rate']);
            } else {
                $Ib = max(0.0, $P * ($G - (float) $v['F'] - $H - $CSA) + (float) ($v['B1'] ?? 0) + $B2 - (float) ($v['CSB1'] ?? 0) - $CSB - $J);
                [$Tb] = self::bracket($q['brackets'], $Ib);
                $Ab = self::r(max(0.0, $Tb * ($B2 - $CSB)));
            }
        }

        return ['A' => $A, 'A_bonus' => $Ab, 'factors' => ['H' => $H, 'CSA' => $CSA, 'CSB' => $CSB, 'I' => $I, 'E' => $E, 'T' => $T, 'K' => $K, 'Y' => $Y, 'I_bonus' => $Ib]];
    }

    /** QPP employee contributions (TP-1015.F-V 3.1): C (base + first additional) and C2 (second additional). */
    public static function qpp(array $plan, int $P, float $S3, int $pm, float $A5, float $A8, float $S5): array
    {
        $exemptP = floor($plan['exemption'] / $P * 100) / 100;
        $C = self::r(max(0.0, min($plan['max'] * $pm / 12 - $A5, $plan['rate'] * ($S3 - $exemptP))));
        $W = max($S5, $plan['ympe'] * $pm / 12);
        $C2 = self::r(max(0.0, min($plan['second_max'] * $pm / 12 - $A8, $plan['second_rate'] * ($S5 + $S3 - $W))));

        return ['C' => $C, 'C2' => $C2];
    }

    private static function onHealthPremium(array $p, float $A): float
    {
        $v = 0.0;
        foreach ($p['health_premium'] ?? [] as $b) {
            if ($A > $b['over']) {
                $v = min($b['cap'], $b['base'] + $b['rate'] * ($A - $b['over']));
            }
        }

        return $v;
    }

    /** Federal basic personal amount (BPAF), reduced between the two thresholds; rounded to the cent (T4127 chapter 2). */
    private static function bpaf(array $b, float $NI): float
    {
        if ($NI <= $b['from']) {
            return (float) $b['max'];
        }
        if ($NI >= $b['to']) {
            return (float) $b['min'];
        }

        return self::r($b['max'] - ($NI - $b['from']) * (($b['max'] - $b['min']) / ($b['to'] - $b['from'])));
    }

    private static function phaseOut(array $b, float $NI): float
    {
        if ($NI <= $b['from']) {
            return (float) $b['max'];
        }
        if ($NI >= $b['to']) {
            return (float) $b['min'];
        }

        return self::r($b['max'] - ($NI - $b['from']) * (($b['max'] - $b['min']) / ($b['to'] - $b['from'])));
    }

    /** Rate and constant for an income from a bracket list [{over, rate, k}] (thresholds are "over"). */
    private static function bracket(array $brackets, float $A): array
    {
        $r = $brackets[0];
        foreach ($brackets as $b) {
            if ($A >= $b['over']) {
                $r = $b;
            }
        }

        return [(float) $r['rate'], (float) $r['k']];
    }

    private static function prefix(string $p, array $a): array
    {
        $o = [];
        foreach ($a as $k => $v) {
            $o[$p . $k] = is_float($v) ? round($v, 4) : $v;
        }

        return $o;
    }

    /** Cents, half up (T4127 chapter 1 rounding). */
    public static function r(float $x): float
    {
        return round($x + ($x >= 0 ? 1e-9 : -1e-9), 2);
    }
}
