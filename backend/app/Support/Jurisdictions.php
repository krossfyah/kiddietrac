<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHERE AN AGENCY OPERATES, AND WHAT THAT MEANS (2026-10-09).
 *
 * Anthony: "start adding the gaps you found and reported for each province and state".
 * Phase 0 of the compliance gap analysis ("KiddieTrac compliance gap analysis — Ohio, US
 * states and Canadian provinces"): one registry, in the style of LearningFrameworks, that
 * says for each jurisdiction what the rest of the product needs to know — currency, default
 * timezone, licence types, ratio table, drill rules, certification and background-check
 * catalogues, immunization schedule, incident wording and deadline, retention floors and
 * terminology. The agency stores its code in agencies.jurisdiction (ISO 3166-2, e.g. CA-ON,
 * US-OH); centres.license_type picks one of the profile's licence types.
 *
 * ONLY VERIFIED VALUES. Every number here was confirmed in the 2026-10-09 research pass
 * (sources in the gap-analysis doc). Anything not confirmed is null with a note, so code that
 * reads it must treat null as "unknown — do not enforce", never as zero.
 *
 * The ratio tables are stored for ratio engine v2; RatioEngine does not read them yet.
 */
final class Jurisdictions
{
    public const DEFAULT = 'CA-ON';

    /** Every jurisdiction requires CACFP-style 3 years + current for food-program records in the US. */
    private const US_CACFP_RETENTION_MONTHS = 48;

    public static function all(): array
    {
        return [
            'CA-ON' => [
                'label' => 'Ontario', 'country' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Toronto',
                'authority' => 'Ministry of Education — CCEYA, O. Reg. 137/15',
                'license_types' => [
                    'on_centre' => 'Licensed child care centre',
                    'on_hcc_agency' => 'Home child care agency',
                    'on_hcc_home' => 'Home child care (agency-affiliated home)',
                ],
                // Report verified infant and preschool only; other bands not checked.
                'ratios' => [
                    'centre' => [
                        ['band' => 'infant', 'label' => 'Infant', 'ratio' => 3, 'group' => null],
                        ['band' => 'preschool', 'label' => 'Preschool', 'ratio' => 8, 'group' => null],
                    ],
                    'mixed_age' => null, 'nap_relief' => null, 'home' => null,
                ],
                'drills' => [
                    ['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly'],
                ],
                'background_checks' => [
                    ['code' => 'vss', 'label' => 'Vulnerable sector check', 'renew_years' => 5],
                    ['code' => 'criminal', 'label' => 'Criminal record check', 'renew_years' => null],
                    ['code' => 'offence_declaration', 'label' => 'Offence declaration (yearly)', 'renew_years' => 1],
                    ['code' => 'driver', 'label' => 'Driver abstract', 'renew_years' => null],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => [
                    ['code' => 'RECE', 'label' => 'RECE (Registered Early Childhood Educator)', 'within_days_of_hire' => null],
                    ['code' => 'ECE_Assistant', 'label' => 'ECE Assistant', 'within_days_of_hire' => null],
                    ['code' => 'First_Aid', 'label' => 'Standard First Aid', 'within_days_of_hire' => null],
                    ['code' => 'CPR', 'label' => 'CPR', 'within_days_of_hire' => null],
                    ['code' => 'Food_Handler', 'label' => 'Food Handler', 'within_days_of_hire' => null],
                    ['code' => 'VSC', 'label' => 'Vulnerable Sector Check', 'within_days_of_hire' => null],
                    ['code' => 'WHMIS', 'label' => 'WHMIS', 'within_days_of_hire' => null],
                    ['code' => 'Health_Card', 'label' => 'Health assessment / health card', 'within_days_of_hire' => null],
                    ['code' => 'Other', 'label' => 'Other', 'within_days_of_hire' => null],
                ],
                'pd_hours' => null,
                'immunization_schedule' => 'naci',
                'incident' => ['serious_label' => 'Serious occurrence', 'report_to' => 'the Ministry', 'deadline' => null],
                'retention_floor_months' => null,   // not covered by the research pass
                'terms' => ['registry' => null, 'subsidy' => 'CWELCC', 'quality_rating' => null],
            ],

            'US-OH' => [
                'label' => 'Ohio', 'country' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',
                'authority' => 'Department of Children and Youth (DCY) — OAC 5180:2-12 (centers), 5180:2-13 (family homes)',
                'license_types' => [
                    'oh_center' => 'Child care center',
                    'oh_type_a' => 'Type A family child care home',
                    'oh_type_b' => 'Type B family child care home',
                ],
                'ratios' => [
                    // 5180:2-12-18 Appendix A (eff. 7/1/2026). Months; null max = open-ended.
                    'centre' => [
                        ['band' => 'infant_young', 'label' => 'Under 12 months', 'min_months' => 0, 'max_months' => 12, 'ratio' => 5, 'group' => 12],
                        ['band' => 'infant_older', 'label' => '12–18 months', 'min_months' => 12, 'max_months' => 18, 'ratio' => 6, 'group' => 12],
                        ['band' => 'toddler', 'label' => '18 months–2½ years', 'min_months' => 18, 'max_months' => 30, 'ratio' => 7, 'group' => 14],
                        ['band' => 'toddler_older', 'label' => '2½–3 years', 'min_months' => 30, 'max_months' => 36, 'ratio' => 8, 'group' => 16],
                        ['band' => 'preschool_3', 'label' => '3 years', 'min_months' => 36, 'max_months' => 48, 'ratio' => 12, 'group' => 24],
                        ['band' => 'preschool_4', 'label' => '4 years to school age', 'min_months' => 48, 'max_months' => null, 'ratio' => 14, 'group' => 28],
                        ['band' => 'school_age', 'label' => 'School-age', 'min_months' => null, 'max_months' => null, 'ratio' => 18, 'group' => 36],
                        ['band' => 'school_age_older', 'label' => '11–15 years', 'min_months' => 132, 'max_months' => 192, 'ratio' => 20, 'group' => 40],
                    ],
                    'mixed_age' => 'youngest',     // the youngest child present sets the ratio
                    'nap_relief' => ['max_hours' => 2, 'factor' => 2, 'no_infants' => true],   // 12-20(A)(7)
                    'second_adult_at' => 7,
                    // 5180:2-13-18 (eff. 7/1/2026), DCY Memo 25-069.
                    'home' => ['per_staff' => 7, 'max_under_2_per_staff' => 3, 'counts_own_under' => 6,
                        'type_b_max' => 7, 'type_a_max' => 14, 'type_a_second_staff' => true],
                ],
                'drills' => [
                    ['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly, at varied times'],
                    ['type' => 'weather', 'every' => 'month', 'months' => [3, 4, 5, 6, 7, 8, 9], 'label' => 'Severe weather drill — monthly, March to September'],
                    ['type' => 'lockdown', 'every' => 'quarter', 'label' => 'Lockdown drill — quarterly'],
                    ['type' => 'vehicle_exit', 'every' => 'month', 'only_if' => 'transport', 'label' => 'Vehicle evacuation drill — monthly (programs that transport)'],
                ],
                'background_checks' => [
                    ['code' => 'bci', 'label' => 'BCI fingerprint check (Ohio)', 'renew_years' => 5],
                    ['code' => 'fbi', 'label' => 'FBI fingerprint check', 'renew_years' => 5],
                    ['code' => 'sex_offender_state', 'label' => 'Ohio sex offender registry', 'renew_years' => 5],
                    ['code' => 'sex_offender_national', 'label' => 'National sex offender registry', 'renew_years' => 5],
                    ['code' => 'sacwis', 'label' => 'Child welfare system check (SACWIS)', 'renew_years' => 5],
                    ['code' => 'driver', 'label' => 'Driver record', 'renew_years' => null],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'background_check_note' => 'Before day one, then every 5 years. Keep the DCY 01176 result notice. In homes, every adult resident is checked.',
                // Required of every staff member before day one (StaffCompliance). Household residents: same types.
                'checks_required' => ['bci', 'fbi', 'sex_offender_state', 'sex_offender_national', 'sacwis'],
                'household_checks' => true,
                'certifications' => [
                    ['code' => 'First_Aid', 'label' => 'First aid', 'within_days_of_hire' => 90],
                    ['code' => 'CPR', 'label' => 'CPR (with an in-person part)', 'within_days_of_hire' => 90],
                    ['code' => 'Child_Abuse_Training', 'label' => 'Child abuse prevention training', 'within_days_of_hire' => 60],
                    ['code' => 'Communicable_Disease', 'label' => 'Communicable disease training', 'within_days_of_hire' => null],
                    ['code' => 'Orientation', 'label' => 'Orientation', 'within_days_of_hire' => 30],
                    ['code' => 'OPIN', 'label' => 'Ohio Professional Registry (OPIN)', 'within_days_of_hire' => null],
                    ['code' => 'Food_Handler', 'label' => 'Food handler', 'within_days_of_hire' => null],
                    ['code' => 'Health_Card', 'label' => 'Staff health records (MMR, Tdap)', 'within_days_of_hire' => 30],
                    ['code' => 'Other', 'label' => 'Other', 'within_days_of_hire' => null],
                ],
                'pd_hours' => ['hours' => 6, 'year_starts_month' => 7],   // 6 hours per July–June year
                'immunization_schedule' => 'acip_ohio',
                'incident' => ['serious_label' => 'Serious incident', 'report_to' => 'DCY (Ohio licensing system)', 'deadline' => 'next business day',
                    'same_day_parent_notice' => ['first_aid', 'emergency_transport', 'head_injury', 'unusual_incident']],
                // Months. Minimums from the rules; KiddieTrac's defaults already exceed them.
                'retention_floor_months' => ['attendance' => 12, 'incidents' => 12, 'medication_permissions' => 12, 'trip_permissions' => 12,
                    'staff_records_after_leaving' => 36, 'substitute_days' => 18, 'cacfp' => self::US_CACFP_RETENTION_MONTHS],
                'terms' => ['registry' => 'Ohio Professional Registry (OPIN)', 'subsidy' => 'Publicly funded child care (PFCC)', 'quality_rating' => 'Step Up To Quality'],
            ],

            'CA-AB' => [
                'label' => 'Alberta', 'country' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Edmonton',
                'authority' => 'Early Learning and Child Care Act and Regulation',
                'license_types' => ['ab_centre' => 'Licensed facility-based program', 'ab_fdh_agency' => 'Family day home agency'],
                'ratios' => ['centre' => [
                    ['band' => 'infant', 'label' => 'Infant', 'ratio' => 3, 'group' => null],
                    ['band' => 'preschool', 'label' => 'Preschool', 'ratio' => 8, 'group' => null],
                ], 'mixed_age' => null, 'nap_relief' => null, 'home' => null],
                'drills' => [['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly']],
                'background_checks' => [
                    ['code' => 'criminal', 'label' => 'Criminal record check', 'renew_years' => 3],
                    ['code' => 'vss', 'label' => 'Vulnerable sector check', 'renew_years' => 3],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => null,   // not covered by the research pass
                'pd_hours' => null,
                'immunization_schedule' => null,   // status recorded only
                'incident' => ['serious_label' => 'Serious incident', 'report_to' => null, 'deadline' => null],
                'retention_floor_months' => null,
                'privacy_note' => 'Alberta PIPA s.13.1: tell families about service providers outside Canada.',
                'terms' => ['registry' => null, 'subsidy' => 'Affordability grant', 'quality_rating' => null],
            ],

            'CA-BC' => [
                'label' => 'British Columbia', 'country' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Vancouver',
                'authority' => 'Community Care and Assisted Living Act — Child Care Licensing Regulation',
                'license_types' => ['bc_centre' => 'Licensed group child care', 'bc_family' => 'Licensed family child care'],
                'ratios' => ['centre' => [
                    ['band' => 'infant', 'label' => 'Infant/toddler', 'ratio' => 4, 'group' => null],
                    ['band' => 'preschool', 'label' => 'Preschool', 'ratio' => 8, 'group' => null],
                ], 'mixed_age' => null, 'nap_relief' => null, 'home' => null],
                'drills' => [['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly']],
                'background_checks' => [
                    ['code' => 'criminal', 'label' => 'Criminal Records Review Program', 'renew_years' => 5],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => null,
                'pd_hours' => null,
                'immunization_schedule' => null,   // status recorded only
                'incident' => ['serious_label' => 'Reportable incident', 'report_to' => null, 'deadline' => null],
                'retention_floor_months' => null,
                'terms' => ['registry' => null, 'subsidy' => 'ChildCareBC / ACCB', 'quality_rating' => null],
            ],

            'CA-NS' => [
                'label' => 'Nova Scotia', 'country' => 'CA', 'currency' => 'CAD', 'timezone' => 'America/Halifax',
                'authority' => 'Early Learning and Child Care Act and Regulations',
                'license_types' => ['ns_centre' => 'Licensed child care centre', 'ns_fh_agency' => 'Family home day care agency'],
                'ratios' => ['centre' => [
                    ['band' => 'infant', 'label' => 'Infant', 'ratio' => 4, 'group' => null],
                    ['band' => 'preschool', 'label' => 'Preschool', 'ratio' => 8, 'group' => null],
                ], 'mixed_age' => null, 'nap_relief' => null, 'home' => null],
                'drills' => [['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly']],
                'background_checks' => [
                    ['code' => 'vss', 'label' => 'Vulnerable sector check', 'renew_years' => 5],
                    ['code' => 'abuse_register', 'label' => 'Child Abuse Register check', 'renew_years' => 3],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => null,
                'pd_hours' => null,
                'immunization_schedule' => null,
                'incident' => ['serious_label' => 'Serious incident', 'report_to' => null, 'deadline' => null],
                'retention_floor_months' => null,
                'terms' => ['registry' => null, 'subsidy' => 'Fee reduction', 'quality_rating' => null],
            ],

            'US-TX' => [
                'label' => 'Texas', 'country' => 'US', 'currency' => 'USD', 'timezone' => 'America/Chicago',
                'authority' => 'Health and Human Services Commission — 26 TAC 746/747',
                'license_types' => ['tx_center' => 'Licensed child care center', 'tx_home' => 'Licensed / registered child care home'],
                'ratios' => ['centre' => [
                    ['band' => 'infant', 'label' => 'Infant', 'ratio' => 4, 'group' => null],
                    ['band' => 'preschool_4', 'label' => '4 years', 'ratio' => 18, 'group' => null],
                ], 'mixed_age' => null, 'nap_relief' => null, 'home' => null],
                'drills' => [
                    ['type' => 'fire', 'every' => 'month', 'label' => 'Fire drill — monthly'],
                    ['type' => 'weather', 'every' => 'quarter', 'label' => 'Severe weather drill — 4 a year'],
                    ['type' => 'lockdown', 'every' => 'quarter', 'label' => 'Lockdown drill — 4 a year'],
                ],
                'background_checks' => [
                    ['code' => 'fingerprint', 'label' => 'FBI fingerprints + Rap Back', 'renew_years' => 5],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => null,
                'pd_hours' => null,
                'immunization_schedule' => 'acip',
                'incident' => ['serious_label' => 'Serious incident', 'report_to' => 'HHSC', 'deadline' => null],
                'retention_floor_months' => ['cacfp' => self::US_CACFP_RETENTION_MONTHS],
                'terms' => ['registry' => null, 'subsidy' => 'TWC child care services (TX3C)', 'quality_rating' => 'Texas Rising Star'],
            ],

            'US-NY' => [
                'label' => 'New York', 'country' => 'US', 'currency' => 'USD', 'timezone' => 'America/New_York',
                'authority' => 'Office of Children and Family Services — 18 NYCRR 416–418',
                'license_types' => ['ny_center' => 'Day care center', 'ny_group_family' => 'Group family day care', 'ny_family' => 'Family day care'],
                'ratios' => ['centre' => [
                    ['band' => 'infant', 'label' => 'Infant', 'ratio' => 4, 'group' => null],
                    ['band' => 'preschool_4', 'label' => '4 years', 'ratio' => 8, 'group' => null],
                ], 'mixed_age' => null, 'nap_relief' => null, 'home' => null],
                'drills' => [
                    ['type' => 'fire', 'every' => 'month', 'label' => 'Evacuation drill — monthly'],
                    ['type' => 'shelter', 'every' => 'half_year', 'label' => 'Shelter-in-place drill — twice a year'],
                ],
                'background_checks' => [
                    ['code' => 'fingerprint', 'label' => 'Criminal history (fingerprints)', 'renew_years' => 5],
                    ['code' => 'scr', 'label' => 'Statewide Central Register check', 'renew_years' => 5],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'certifications' => null,
                'pd_hours' => null,
                'immunization_schedule' => 'acip',
                'incident' => ['serious_label' => 'Serious incident', 'report_to' => 'OCFS', 'deadline' => null],
                'retention_floor_months' => ['cacfp' => self::US_CACFP_RETENTION_MONTHS],
                'terms' => ['registry' => null, 'subsidy' => 'Child Care Assistance (CCTA)', 'quality_rating' => 'QUALITYstarsNY'],
            ],
        ];
    }

    public static function exists(?string $code): bool
    {
        return $code !== null && isset(self::all()[$code]);
    }

    public static function profile(?string $code): array
    {
        $all = self::all();
        $code = ($code && isset($all[$code])) ? $code : self::DEFAULT;

        return ['code' => $code] + $all[$code];
    }

    /** The agency's jurisdiction code; CA-ON when unset (every agency before 2026-10-09 was Ontario). */
    public static function codeFor(?int $agencyId): string
    {
        if (! $agencyId) return self::DEFAULT;
        static $cache = [];
        if (! isset($cache[$agencyId])) {
            $c = null;
            try { $c = DB::table('agencies')->where('id', $agencyId)->value('jurisdiction'); } catch (\Throwable $e) {}
            $cache[$agencyId] = self::exists($c) ? $c : self::DEFAULT;
        }

        return $cache[$agencyId];
    }

    public static function forAgency(?int $agencyId): array
    {
        return self::profile(self::codeFor($agencyId));
    }

    /** The list for pickers: code, label, country. */
    public static function options(): array
    {
        $out = [];
        foreach (self::all() as $code => $p) $out[] = ['code' => $code, 'label' => $p['label'], 'country' => $p['country']];

        return $out;
    }

    /** Certification codes offered in this jurisdiction (null catalogue → the Ontario set, the product's original list). */
    public static function certificationCodes(?int $agencyId): array
    {
        $p = self::forAgency($agencyId);
        $list = $p['certifications'] ?? null ?: self::profile('CA-ON')['certifications'];

        return array_column($list, 'code');
    }

    /** Background-check codes offered (null catalogue → Ontario's). */
    public static function backgroundCheckCodes(?int $agencyId): array
    {
        $p = self::forAgency($agencyId);
        $list = $p['background_checks'] ?? null ?: self::profile('CA-ON')['background_checks'];

        return array_column($list, 'code');
    }

    /** Every code any jurisdiction uses — what the API accepts, so a record made under one profile stays valid. */
    public static function allBackgroundCheckCodes(): array
    {
        $c = [];
        foreach (self::all() as $p) foreach ((array) ($p['background_checks'] ?? []) as $b) $c[$b['code']] = true;

        return array_keys($c);
    }

    public static function allCertificationCodes(): array
    {
        $c = [];
        foreach (self::all() as $p) foreach ((array) ($p['certifications'] ?? []) as $b) $c[$b['code']] = true;

        return array_keys($c);
    }

    /** The minimum months a retention setting may be set to, or null when the jurisdiction sets none known. */
    public static function retentionFloor(?int $agencyId, string $key): ?int
    {
        $f = self::forAgency($agencyId)['retention_floor_months'] ?? null;

        return is_array($f) && isset($f[$key]) ? (int) $f[$key] : null;
    }
}
