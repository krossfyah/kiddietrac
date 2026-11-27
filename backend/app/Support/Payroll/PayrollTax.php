<?php

declare(strict_types=1);

namespace App\Support\Payroll;

use App\Http\Controllers\Api\PayrollSettingsController;
use App\Support\AgencyTime;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 1 (2026-11-27): an employee's tax setup (payroll_tax_profiles, set by an agency
 * admin) and tax forms (payroll_tax_forms: TD1 federal + provincial, TP-1015.3 Quebec; US W-4 in Phase 2), turned into
 * CaCalculator inputs.
 *
 * NOTHING HERE RUNS FOR AN AGENCY UNTIL IT SWITCHES PAYROLL ON: engineOn() reads agencies.settings.payroll_engine.enabled
 * (default off; Phase 2 adds the HR settings switch + the "check with your accountant" acknowledgement). Every route and
 * screen built on this class checks engineOn() first, so the existing gross-only payroll is untouched while it is off.
 *
 * Fill-in rule: no signed form for the year = the basic personal amounts, returned with a warning (never an error).
 */
final class PayrollTax
{
    public const CPP_EXEMPT_REASONS = ['cpt30' => 'Form CPT30 election (age 65 to 70, receiving a CPP pension)',
        'disability' => 'Receiving a CPP or QPP disability pension', 'other' => 'Other reason (CRA confirmed)'];

    public const FREQUENCIES = [52 => 'Weekly (52)', 26 => 'Every two weeks (26)', 24 => 'Twice a month (24)', 12 => 'Monthly (12)'];

    private const NAME_TO_CODE = ['alberta' => 'AB', 'british columbia' => 'BC', 'manitoba' => 'MB', 'new brunswick' => 'NB',
        'newfoundland and labrador' => 'NL', 'newfoundland' => 'NL', 'nova scotia' => 'NS', 'northwest territories' => 'NT',
        'nunavut' => 'NU', 'ontario' => 'ON', 'prince edward island' => 'PE', 'quebec' => 'QC', 'québec' => 'QC',
        'saskatchewan' => 'SK', 'yukon' => 'YT'];

    /** Has this agency switched the payroll engine on? (Off for every agency until Phase 2's switch.) */
    public static function engineOn(int $agencyId): bool
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return ($s['payroll_engine']['enabled'] ?? false) === true;
    }

    /** Pay periods a year from the agency's payroll cadence (Payroll settings), or null when none is set. */
    public static function agencyPeriods(int $agencyId): ?int
    {
        $c = (string) (PayrollSettingsController::read($agencyId)['cadence'] ?? '');

        return ['weekly' => 52, 'biweekly' => 26, 'semimonthly' => 24, 'monthly' => 12][$c] ?? null;
    }

    /** Normalise a centre's province ("Ontario", "ON") to the CRA code, or null outside Canada. */
    public static function provinceCode(?string $province, ?string $country): ?string
    {
        $c = strtoupper(trim((string) $country));
        if ($c !== '' && ! in_array($c, ['CA', 'CANADA'], true)) {
            return null;
        }
        $p = trim((string) $province);
        $u = strtoupper($p);
        if (in_array($u, CaCalculator::JURISDICTIONS, true)) {
            return $u;
        }

        return self::NAME_TO_CODE[mb_strtolower($p)] ?? null;
    }

    /** The province where the person works by default: their HR file's main centre, else their first live centre. */
    public static function defaultJurisdiction(int $agencyId, int $userId): array
    {
        $cid = (int) DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->value('primary_centre_id');
        if (! $cid) {
            $cid = (int) DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $userId)->where('ra.active', 1)->where('c.agency_id', $agencyId)->orderBy('ra.id')->value('ra.centre_id');
        }
        $c = $cid ? DB::table('centres')->where('id', $cid)->where('agency_id', $agencyId)->first(['name', 'province', 'country']) : null;
        if (! $c) {
            return ['country' => null, 'jurisdiction' => null, 'centre' => null];
        }
        $code = self::provinceCode($c->province, $c->country);
        $country = $code ? 'CA' : (in_array(strtoupper((string) $c->country), ['US', 'USA', 'UNITED STATES'], true) ? 'US' : null);

        return ['country' => $country, 'jurisdiction' => $code ?: ($country === 'US' ? strtoupper((string) $c->province) : null), 'centre' => $c->name];
    }

    /** The person's tax setup with defaults filled in, plus what is missing. */
    public static function profile(int $agencyId, int $userId): array
    {
        $row = DB::table('payroll_tax_profiles')->where('agency_id', $agencyId)->where('user_id', $userId)->first();
        $def = self::defaultJurisdiction($agencyId, $userId);
        $country = $row->country ?? ($def['country'] ?: 'CA');
        $j = $row->jurisdiction ?? $def['jurisdiction'];
        $periods = $row->pay_frequency ?? null;
        $dob = DB::table('users')->where('id', $userId)->value('date_of_birth');
        $year = (int) AgencyTime::now($agencyId)->format('Y');
        $warn = [];
        if ($country !== 'CA') {
            $warn[] = 'US payroll is coming in a later phase. Canadian deductions only for now.';
        }
        if (! $j) {
            $warn[] = 'No province of employment: set it here or give the person a main centre with a province.';
        }
        if (! ($periods ?: self::agencyPeriods($agencyId))) {
            $warn[] = 'No pay frequency: set the agency\'s pay schedule in Payroll settings or choose one here.';
        }
        if (! $dob) {
            $warn[] = 'No date of birth on the account: CPP starts after 18 and stops at 70, so it cannot be checked.';
        }

        return [
            'saved' => (bool) $row,
            'country' => $country,
            'jurisdiction' => $j,
            'jurisdiction_from_centre' => $row === null || $row->jurisdiction === null,
            'default_jurisdiction' => $def['jurisdiction'],
            'default_centre' => $def['centre'],
            'pay_frequency' => $periods ? (int) $periods : null,
            'agency_pay_frequency' => self::agencyPeriods($agencyId),
            'cpp_exempt_reason' => $row->cpp_exempt_reason ?? null,
            'ei_exempt' => (bool) ($row->ei_exempt ?? false),
            'qpip_exempt' => (bool) ($row->qpip_exempt ?? false),
            'vacation_pay_pct' => isset($row->vacation_pay_pct) ? (float) $row->vacation_pay_pct : null,
            'annual_zone_deduction' => isset($row->annual_zone_deduction) ? (float) $row->annual_zone_deduction : null,
            'notes' => $row->notes ?? null,
            'pensionable_months' => CaCalculator::pensionableMonths($year, $dob ? (string) $dob : null),
            'has_birth_date' => (bool) $dob,
            'updated_at' => $row->updated_at ?? null,
            'warnings' => $warn,
        ];
    }

    /** Every tax form for a person (newest first), with its signed state from Managed Forms. */
    public static function forms(int $agencyId, int $userId, ?int $year = null): array
    {
        $q = DB::table('payroll_tax_forms as f')
            ->leftJoin('managed_form_signoffs as s', function ($j) {
                $j->on('s.managed_form_id', '=', 'f.managed_form_id')->on('s.user_id', '=', 'f.user_id');
            })
            ->where('f.agency_id', $agencyId)->where('f.user_id', $userId);
        if ($year) {
            $q->where('f.tax_year', $year);
        }

        return $q->orderByDesc('f.id')->get(['f.id', 'f.form', 'f.tax_year', 'f.jurisdiction', 'f.claims', 'f.source', 'f.status', 'f.managed_form_id',
            'f.created_at', 'f.signed_at as paper_signed_at', 's.signed_at as esigned_at'])
            ->map(function ($r) {
                $signed = $r->source === 'paper' ? $r->paper_signed_at : $r->esigned_at;
                $state = $r->status === 'withdrawn' ? 'withdrawn' : ($signed ? 'signed' : 'waiting');

                return ['id' => (int) $r->id, 'form' => $r->form, 'tax_year' => (int) $r->tax_year, 'jurisdiction' => $r->jurisdiction,
                    'claims' => json_decode((string) $r->claims, true) ?: [], 'source' => $r->source, 'state' => $state,
                    'signed_at' => $signed ? (string) $signed : null, 'managed_form_id' => $r->managed_form_id ? (int) $r->managed_form_id : null,
                    'created_at' => (string) $r->created_at];
            })->all();
    }

    /** The claims in force for a tax year: the newest SIGNED form of that year, else basic amounts + a warning. */
    public static function claimsFor(int $agencyId, int $userId, int $year): array
    {
        foreach (self::forms($agencyId, $userId, $year) as $f) {
            if ($f['state'] === 'signed') {
                return ['form' => $f, 'claims' => $f['claims'], 'warnings' => []];
            }
        }

        return ['form' => null, 'claims' => [], 'warnings' => ['No signed TD1 for ' . $year . ': the basic personal amounts are used until one is signed.']];
    }

    /**
     * CaCalculator inputs for one pay: the employer's setup + the employee's signed claims. The caller adds the
     * amounts (salary, vacation pay, benefits, bonus) and the year-to-date figures.
     */
    public static function inputs(int $agencyId, int $userId, string $payDate): array
    {
        $p = self::profile($agencyId, $userId);
        $year = (int) substr($payDate, 0, 4);
        $c = self::claimsFor($agencyId, $userId, $year);
        $cl = $c['claims'];
        $dob = DB::table('users')->where('id', $userId)->value('date_of_birth');
        $pm = CaCalculator::pensionableMonths($year, $dob ? (string) $dob : null);
        $in = [
            'jurisdiction' => $p['jurisdiction'],
            'pay_date' => $payDate,
            'periods' => $p['pay_frequency'] ?: $p['agency_pay_frequency'],
            'pm' => $pm,
            'cpp_exempt' => $p['cpp_exempt_reason'] !== null || $pm === 0,
            'ei_exempt' => $p['ei_exempt'],
            'qpip_exempt' => $p['qpip_exempt'],
            'annual_hd' => (float) ($cl['federal']['zone_deduction'] ?? $p['annual_zone_deduction'] ?? 0),
            'additional_tax' => (float) ($cl['federal']['additional_tax'] ?? 0),
            'tax_exempt' => ! empty($cl['federal']['exempt']),
        ];
        if (isset($cl['federal']['total_claim'])) {
            $in['federal_claim'] = (float) $cl['federal']['total_claim'];
        }
        if (isset($cl['provincial']['total_claim'])) {
            $in['provincial_claim'] = (float) $cl['provincial']['total_claim'];
        }
        if (isset($cl['quebec']['line7'])) {
            $in['qc_claim'] = (float) $cl['quebec']['line7'] + (float) ($cl['quebec']['line9'] ?? 0);
            $in['qc_deductions'] = (float) ($cl['quebec']['line19'] ?? 0);
            $in['qc_additional_tax'] = (float) ($cl['quebec']['additional_tax'] ?? 0);
        }

        return ['inputs' => $in, 'warnings' => array_merge($p['warnings'], $c['warnings']), 'form_id' => $c['form']['id'] ?? null];
    }

    /** The basic amounts a blank TD1 starts from, for the person's province and the year (from the tables). */
    public static function basics(string $jurisdiction, int $year, string $asOf): array
    {
        $t = TaxTables::forDate('CA', $year === (int) substr($asOf, 0, 4) ? $asOf : $year . '-01-01');
        if (! $t) {
            return ['available' => false, 'year' => $year];
        }
        $prov = $jurisdiction === 'QC' ? null : ($t['provinces'][$jurisdiction] ?? null);

        return ['available' => true, 'year' => $year, 'table_version' => $t['version'],
            'federal_basic' => (float) $t['federal']['bpaf']['max'],
            'federal_basic_min' => (float) $t['federal']['bpaf']['min'],
            'provincial_basic' => $prov ? (float) $prov['basic'] : null,
            'quebec_basic' => $jurisdiction === 'QC' ? (float) ($t['quebec']['basic'] ?? 0) : null,
            'quebec_available' => ! empty($t['quebec']['brackets'])];
    }
}
