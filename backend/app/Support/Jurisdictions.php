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
 * The ratio tables are read by ratio engine v2 (App\Support\RatioRules; Ontario centres by
 * App\Support\OntarioRatios).
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
                /* O. Reg. 137/15 Schedule 1, read from the e-Laws consolidation (amended to O. Reg.
                   197/26) on 2026-10-13. staff:children is the ratio ("3 to 10" for infants); group
                   is Column 4; qualified is Column 5 (proportion of employees that must be
                   qualified, s.54); reduced is the s.8(4) minimum from Licensing Manual Part 3.4
                   (never below two-thirds of the ratio; none for infants or junior school age).
                   Months; ranges overlap in the regulation (kindergarten is "in kindergarten").
                   Read by App\Support\OntarioRatios for locations licensed 'on_centre' only. */
                'ratios' => [
                    'engine' => 'ontario',
                    'centre' => [
                        ['band' => 'infant', 'label' => 'Infant', 'min_months' => 0, 'max_months' => 18, 'staff' => 3, 'children' => 10, 'ratio' => 3, 'group' => 10, 'qualified' => [1, 3], 'reduced' => null],
                        ['band' => 'toddler', 'label' => 'Toddler', 'min_months' => 18, 'max_months' => 30, 'staff' => 1, 'children' => 5, 'ratio' => 5, 'group' => 15, 'qualified' => [1, 3], 'reduced' => 8],
                        ['band' => 'preschool', 'label' => 'Preschool', 'min_months' => 30, 'max_months' => 72, 'staff' => 1, 'children' => 8, 'ratio' => 8, 'group' => 24, 'qualified' => [2, 3], 'reduced' => 12],
                        ['band' => 'kindergarten', 'label' => 'Kindergarten', 'min_months' => 44, 'max_months' => 84, 'staff' => 1, 'children' => 13, 'ratio' => 13, 'group' => 26, 'qualified' => [1, 2], 'reduced' => 20],
                        ['band' => 'primary_junior', 'label' => 'Primary/junior school age', 'min_months' => 68, 'max_months' => 156, 'staff' => 1, 'children' => 15, 'ratio' => 15, 'group' => 30, 'qualified' => [1, 2], 'reduced' => 23],
                        ['band' => 'junior', 'label' => 'Junior school age', 'min_months' => 108, 'max_months' => 156, 'staff' => 1, 'children' => 20, 'ratio' => 20, 'group' => 20, 'qualified' => [1, 1], 'reduced' => null],
                    ],
                    // s.8(3): younger children allowed in a group, percent of licensed capacity (Manual 3.3).
                    'mixed_age' => ['toddler' => 20, 'preschool' => 20, 'kindergarten' => 25, 'primary_junior' => 25, 'junior' => 25],
                    // s.8(4) + Manual 3.4; rest period up to 2 h for toddler/preschool (s.47).
                    'reduced_ratio' => ['long_program_minutes' => 360, 'arrival_max' => [90, 30], 'departure_max' => [60, 30],
                        'rest_max_minutes' => 120, 'rest_groups' => ['toddler', 'preschool'], 'never' => ['infant', 'junior', 'family']],
                    'supervisor' => ['full_below' => 5, 'half_upto' => 6],   // s.8(5)
                    'min_adults' => ['infant_group_at' => 4, 'others_at' => 6],   // s.8(6)
                    // s.8.1 + Schedule 4: [from months, to months, children per employee].
                    'family_group' => ['max' => 15, 'max_under_24m' => 6, 'bands' => [[0, 12, 3], [12, 24, 4], [24, 156, 8]]],
                    'nap_relief' => null, 'home' => null,
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

            /* CALIFORNIA (2026-10-28, Anthony: "look for any US, california compliance related childcare items to be
               considered for this setup - full sweep"). Researched 2026-10-28 from the CCR Title 22 text (Cornell LII /
               Justia copies), the Health and Safety Code and CDSS / CDPH forms; sections cited per line. */
            'US-CA' => [
                'label' => 'California', 'country' => 'US', 'currency' => 'USD', 'timezone' => 'America/Los_Angeles',
                'authority' => 'Department of Social Services (CDSS), Community Care Licensing — CCR Title 22, Div. 12 (Ch. 1 centers, Ch. 3 family child care homes)',
                'license_types' => [
                    'ca_center' => 'Child care center (infant / preschool / school-age)',
                    'ca_small_fcch' => 'Small family child care home (up to 8)',
                    'ca_large_fcch' => 'Large family child care home (up to 14)',
                ],
                'ratios' => [
                    /* Teachers per child. Infant 1:4 (101416.5; 1 teacher may oversee 12 with aides; 12 sleeping infants
                       per teacher at nap); toddler program 18 months–3 years 1:6, group 12 (H&SC 1596.955 — optional
                       component; outside one, under-2s are infants and 2-year-olds preschool); preschool 1:12, or 1 teacher
                       + 1 aide per 15, or a qualified aide 1:18 (101216.3); school-age 1:14, 28 with an aide (101516.5).
                       Mixed ages: the youngest child sets the ratio (101516.5). The director counts only while teaching. */
                    'centre' => [
                        ['band' => 'infant', 'label' => 'Infant (under 18 months)', 'min_months' => 0, 'max_months' => 18, 'ratio' => 4, 'group' => 12],
                        ['band' => 'toddler', 'label' => 'Toddler program (18 months–3 years)', 'min_months' => 18, 'max_months' => 36, 'ratio' => 6, 'group' => 12],
                        ['band' => 'preschool', 'label' => 'Preschool (3 years to school age)', 'min_months' => 36, 'max_months' => null, 'ratio' => 12, 'group' => null],
                        ['band' => 'school_age', 'label' => 'School-age', 'min_months' => null, 'max_months' => null, 'ratio' => 14, 'group' => 28],
                    ],
                    'mixed_age' => 'youngest',
                    // 101230(c): one teacher (or an 18+ qualified aide) may supervise 24 napping children while the rest are on site.
                    'nap_relief' => ['max_hours' => null, 'factor' => 2, 'no_infants' => true],
                    'second_adult_at' => null,
                    /* Family child care homes (H&SC 1597.44 / 1597.465, 22 CCR 102416.5). Not a per-caregiver ratio: a
                       capacity table by infants (under 2). The provider's own children under 10 count. A large home needs
                       an assistant provider once it is past the small-home limits. */
                    'home' => ['engine' => 'ca_fcch', 'counts_own_under' => 10, 'infant_under_months' => 24,
                        'tiers' => [
                            'ca_small_fcch' => [
                                ['max' => 4, 'max_infants' => 4, 'staff' => 1],
                                ['max' => 6, 'max_infants' => 3, 'staff' => 1],
                                ['max' => 8, 'max_infants' => 2, 'staff' => 1, 'note' => '7–8 children only with a child in kindergarten or elementary school and another child at least 6, parents told in writing (and the owner\'s consent if rented)'],
                            ],
                            'ca_large_fcch' => [
                                ['max' => 4, 'max_infants' => 4, 'staff' => 1],
                                ['max' => 6, 'max_infants' => 3, 'staff' => 1],
                                ['max' => 8, 'max_infants' => 2, 'staff' => 1, 'note' => '7–8 children without an assistant only on the small-home conditions'],
                                ['max' => 12, 'max_infants' => 4, 'staff' => 2],
                                ['max' => 14, 'max_infants' => 3, 'staff' => 2, 'note' => '13–14 children only with a child in kindergarten or elementary school and another child at least 6, parents told in writing'],
                            ],
                        ]],
                ],
                // 101174(d) centers and 102417(g)(9)(A) homes: fire and disaster (earthquake, flood) drills at least every six months; keep the log 1 year.
                'drills' => [
                    ['type' => 'fire', 'every' => 'half_year', 'label' => 'Fire drill — at least every six months'],
                    ['type' => 'disaster', 'every' => 'half_year', 'label' => 'Disaster drill (earthquake / flood) — at least every six months'],
                ],
                'drill_note' => 'Your local fire authority (State Fire Marshal, Title 19) may ask for more. Keep the Emergency Disaster Plan (LIC 610) on site.',
                'background_checks' => [
                    // H&SC 1596.871: CDSS clearance (DOJ + FBI fingerprints by Live Scan) before first being present; DOJ sends subsequent arrest notices, so no renewal.
                    ['code' => 'livescan', 'label' => 'Criminal record clearance — DOJ + FBI fingerprints (Live Scan)', 'renew_years' => null],
                    // 22 CCR 101170.2 / 102370.2: Child Abuse Central Index check (LIC 198A) before working, living in or being present.
                    ['code' => 'caci', 'label' => 'Child Abuse Central Index (CACI, LIC 198A)', 'renew_years' => null],
                    ['code' => 'driver', 'label' => 'Driver record', 'renew_years' => null],
                    ['code' => 'reference', 'label' => 'Reference check', 'renew_years' => null],
                    ['code' => 'other', 'label' => 'Other', 'renew_years' => null],
                ],
                'background_check_note' => 'Clearance from CDSS before day one (Live Scan, plus the CACI form LIC 198A). In family child care homes every adult who lives there is cleared too. Arrests after clearance are reported to CDSS automatically.',
                'checks_required' => ['livescan', 'caci'],
                'household_checks' => true,
                'certifications' => [
                    // 101216(g): health screening incl. a TB test or risk assessment, no more than 1 year before to 7 days after hire (LIC 503).
                    ['code' => 'TB_Clearance', 'label' => 'Health screening + TB clearance (LIC 503)', 'within_days_of_hire' => 7],
                    // H&SC 1596.7995 (SB 792): influenza (yearly, or a signed declination), pertussis (Tdap) and measles (MMR), before starting.
                    ['code' => 'Staff_Immunizations', 'label' => 'Staff immunizations — flu, Tdap, MMR (SB 792)', 'within_days_of_hire' => 1],
                    // H&SC 1596.8662 (AB 1207): mandated reporter training within 90 days of hire, renewed every 2 years.
                    ['code' => 'Mandated_Reporter', 'label' => 'Mandated reporter training (renew every 2 years)', 'within_days_of_hire' => 90],
                    // H&SC 1596.866: at least one director or teacher per center (and each home licensee) holds the 15-hour EMSA course.
                    ['code' => 'Health_Safety_15', 'label' => '15-hour Preventive Health & Safety (EMSA) incl. nutrition + lead', 'within_days_of_hire' => null],
                    ['code' => 'First_Aid', 'label' => 'Pediatric first aid', 'within_days_of_hire' => null],
                    ['code' => 'CPR', 'label' => 'Pediatric CPR', 'within_days_of_hire' => null],
                    // 101216.1: teachers 12 ECE/CD units + experience; directors more (101215.1).
                    ['code' => 'ECE_Units', 'label' => 'ECE units (12 for a teacher)', 'within_days_of_hire' => null],
                    ['code' => 'CD_Permit', 'label' => 'Child Development Permit (CTC)', 'within_days_of_hire' => null],
                    ['code' => 'Food_Handler', 'label' => 'Food handler', 'within_days_of_hire' => null],
                    ['code' => 'Other', 'label' => 'Other', 'within_days_of_hire' => null],
                ],
                'pd_hours' => null,   // Title 22 sets no yearly hours (Child Development Permits renew on CTC hours instead)
                'immunization_schedule' => 'cdph_child_care',
                // H&SC 120325 + SB 277 (2016): no personal-belief exemptions for child care entry; medical exemptions only (CAIR-ME).
                'immunization_exemptions' => ['medical'],
                'immunization_note' => 'California: proof of every required dose for the child\'s age (CDPH IMM-230), within 30 days of each new age checkpoint. Only medical exemptions are accepted (SB 277). Report status to CDPH each fall.',
                // 101212(d): phone/fax licensing the next working day, written report (LIC 624) within 7 days; parent the same day.
                'incident' => ['serious_label' => 'Unusual incident / injury (LIC 624)', 'report_to' => 'Community Care Licensing (CDSS)', 'deadline' => 'next working day by phone, written LIC 624 within 7 days',
                    'same_day_parent_notice' => ['injury', 'medical_treatment', 'unusual_incident']],
                // 101221(g) children's records 3 years after leaving; 102416.1 personnel 3 years; 101174(d) drill log 1 year.
                'retention_floor_months' => ['children_records_after_leaving' => 36, 'staff_records_after_leaving' => 36, 'drills' => 12, 'cacfp' => self::US_CACFP_RETENTION_MONTHS],
                // 101229.1(a)(1): the adult signing a child in or out uses a full signature and the time.
                'sign_in' => ['full_signature' => true, 'note' => 'California: the adult dropping off or picking up signs in full, with the time (22 CCR 101229.1).'],
                // AB 2370 (2018): centers in buildings from before 2010 test drinking water for lead, then every 5 years.
                'facility_checks' => [
                    ['code' => 'lead_water', 'label' => 'Drinking water lead test (centers built before 2010, every 5 years — AB 2370)', 'every_years' => 5, 'only' => ['ca_center']],
                ],
                'terms' => ['registry' => null, 'registry_optional' => 'California ECE Workforce Registry', 'subsidy' => 'CDSS subsidized care (CalWORKs / Alternative Payment)', 'quality_rating' => 'Quality Counts California'],
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

    /**
     * THE NAME PEOPLE READ for a background-check or certificate code (2026-11-27, the Renewals calendar showed
     * "vulnerable_sector (background_check)"). The agency's own jurisdiction first, then any jurisdiction that uses
     * the code, then the code made readable. $kind = 'background_checks' | 'certifications'.
     */
    public static function label(?int $agencyId, string $kind, ?string $code): string
    {
        $code = trim((string) $code);
        if ($code === '') {
            return '';
        }
        foreach (array_merge([self::forAgency($agencyId)], array_values(self::all())) as $p) {
            foreach ((array) ($p[$kind] ?? []) as $row) {
                if (strcasecmp((string) ($row['code'] ?? ''), $code) === 0 && ! empty($row['label'])) {
                    return (string) $row['label'];
                }
            }
        }
        $known = ['vulnerable_sector' => 'Vulnerable sector check', 'Vulnerable_Sector_Check' => 'Vulnerable sector check',
            'VSC' => 'Vulnerable sector check', 'CPR' => 'CPR', 'RECE' => 'RECE', 'WHMIS' => 'WHMIS'];
        if (isset($known[$code])) {
            return $known[$code];
        }
        $s = str_replace('_', ' ', $code);

        return ctype_upper(str_replace(' ', '', $s)) ? $s : ucfirst(strtolower($s));
    }

    /** The minimum months a retention setting may be set to, or null when the jurisdiction sets none known. */
    public static function retentionFloor(?int $agencyId, string $key): ?int
    {
        $f = self::forAgency($agencyId)['retention_floor_months'] ?? null;

        return is_array($f) && isset($f[$key]) ? (int) $f[$key] : null;
    }
}
