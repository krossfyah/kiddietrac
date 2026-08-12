<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Home-visitor inspection forms — canonical, exact-reproduction schemas.
 *
 * Two Ontario home-child-care monitoring documents digitised field-for-field:
 *   - monthly_monitoring   : iLearn "Monthly Home Visitor Monitoring & Inspection Report" (4 pages, 11 sections)
 *   - quarterly_checklist  : Ministry of Education "Standard Home Visitor Checklist" (O. Reg. 137/15, 68 questions)
 *
 * This is the SINGLE source of truth. The API serves it to the browser to build
 * the fillable form, and the server renders the same schema to HTML/PDF for the
 * emailed copy and downloads — so the on-screen form, the email and the PDF all
 * match the original layout exactly.
 *
 * Block types (understood identically by screen-hcc-forms.js and the PDF renderer):
 *   head       {fields:[{id,label,type,w}]}            top detail fields
 *   subhead    {text}                                  grey sub-group bar
 *   intro      {text}                                  descriptive line above a block
 *   checklist  {ministry:bool, items:[item]}           Yes/No/N/A + Comments rows
 *   checkgroup {intro?, cols, boxes:[label], other?}   checkbox grid (check all that apply)
 *   textareas  {items:[{id,label,rows}]}               free-text blocks
 *   table      {intro?,note?,columns:[{id,label,w}],rows,comments?}  repeating grid
 *   yn         {items:[{id,label}]}                    single Yes/No pairs
 *   sign       {statements?:[l,r], columns:[{id,label,type}]}  signature block
 *   static     {html}                                  static reference text
 *
 * checklist item: {n?,ref?,risk?,desc,subs?[],bullets?[],note?,prompts?[],comment?}
 *   subs    -> each string gets its OWN Yes/No/N/A row (matches multi-checkbox questions)
 *   bullets -> descriptive bullet list inside the description (single Yes/No/N/A row)
 *   prompts -> labelled text inputs rendered inside the Comments cell
 *   note    -> small italic note under the reference (e.g. "*Does not apply to in-home services")
 */
final class HccFormSchemas
{
    public static function keys(): array
    {
        return ['monthly_monitoring', 'quarterly_checklist'];
    }

    public static function label(string $key): string
    {
        return [
            'monthly_monitoring'  => 'Monthly Home Visitor Monitoring & Inspection Report',
            'quarterly_checklist' => 'Standard Home Visitor Checklist (Quarterly Inspection)',
        ][$key] ?? $key;
    }

    public static function all(): array
    {
        return [
            'monthly_monitoring'  => self::monthly(),
            'quarterly_checklist' => self::quarterly(),
        ];
    }

    public static function get(string $key): ?array
    {
        $s = self::all()[$key] ?? null;
        return $s ? self::assignIds($s) : null;
    }

    /**
     * Inject explicit, stable field ids into every block/item so the browser
     * form, the PDF renderer and the stored answers all key IDENTICALLY. This
     * is the ONE place ids are minted — no side recomputes them.
     */
    public static function assignIds(array $schema): array
    {
        foreach ($schema['sections'] as &$sec) {
            $sk = $sec['key'];
            foreach ($sec['blocks'] as $bi => &$b) {
                $b['_id'] = $sk . '_b' . $bi;
                switch ($b['type']) {
                    case 'checklist':
                        foreach ($b['items'] as $ii => &$it) {
                            $base = $b['_id'] . '_i' . $ii;
                            if (!empty($it['subs'])) {
                                $it['_sids'] = [];
                                foreach ($it['subs'] as $si => $_) {
                                    $it['_sids'][] = $base . '_s' . $si;
                                }
                            } else {
                                $it['_vid'] = $base . '_v';
                            }
                            $it['_cid'] = $base . '_c';
                            if (!empty($it['prompts'])) {
                                $it['_pids'] = [];
                                foreach ($it['prompts'] as $pi => $_) {
                                    $it['_pids'][] = $base . '_p' . $pi;
                                }
                            }
                        }
                        unset($it);
                        break;
                    case 'checkgroup':
                        $b['_bids'] = [];
                        foreach ($b['boxes'] as $ci => $_) {
                            $b['_bids'][] = $b['_id'] . '_c' . $ci;
                        }
                        if (!empty($b['other'])) {
                            $b['_otherId'] = $b['_id'] . '_other';
                        }
                        break;
                    // head / textareas / yn / sign / table already carry explicit field ids.
                }
            }
            unset($b);
        }
        unset($sec);
        return $schema;
    }

    /**
     * Map every answer field id -> a human label, for the edit audit trail.
     * Mirrors the id scheme minted by assignIds().
     */
    public static function labelMap(string $key): array
    {
        $s = self::get($key);
        if (! $s) {
            return [];
        }
        $m = [];
        $short = function (string $t, int $n = 60) {
            $t = trim(preg_replace('/\s+/', ' ', $t));
            return mb_strlen($t) > $n ? mb_substr($t, 0, $n - 1) . '…' : $t;
        };
        foreach ($s['sections'] as $sec) {
            foreach ($sec['blocks'] as $b) {
                switch ($b['type']) {
                    case 'head':
                        foreach ($b['fields'] as $f) $m[$f['id']] = $f['label'];
                        break;
                    case 'checklist':
                        foreach ($b['items'] as $it) {
                            $n = isset($it['n']) ? ('Q' . $it['n'] . '. ') : '';
                            $d = $short($it['desc']);
                            if (! empty($it['_sids'])) {
                                foreach ($it['_sids'] as $si => $sid) $m[$sid] = $n . $d . ' — ' . $short($it['subs'][$si], 45);
                            } elseif (! empty($it['_vid'])) {
                                $m[$it['_vid']] = $n . $d;
                            }
                            if (! empty($it['_cid'])) $m[$it['_cid']] = $n . $d . ' (comment)';
                            if (! empty($it['_pids'])) foreach ($it['_pids'] as $pi => $pid) $m[$pid] = $n . $short((string) ($it['prompts'][$pi] ?? 'note'), 45);
                        }
                        break;
                    case 'checkgroup':
                        foreach (($b['_bids'] ?? []) as $i => $bid) $m[$bid] = ($b['intro'] ?? 'Option') . ': ' . $short($b['boxes'][$i], 40);
                        if (! empty($b['_otherId'])) $m[$b['_otherId']] = ($b['intro'] ?? '') . ': Other';
                        break;
                    case 'textareas':
                        foreach ($b['items'] as $it) $m[$it['id']] = $short($it['label']);
                        break;
                    case 'yn':
                        foreach ($b['items'] as $it) $m[$it['id']] = $short($it['label']);
                        break;
                    case 'sign':
                        foreach ($b['columns'] as $c) $m[$c['id']] = $short($c['label']);
                        break;
                    case 'table':
                        // Row cells are compared as a whole via the block id.
                        $m[$b['_id']] = ($sec['title'] ?? 'Table');
                        if (! empty($b['comments'])) $m[$b['comments']['id']] = $short($b['comments']['label']);
                        break;
                }
            }
        }
        return $m;
    }

    /** Y/N/A checklist item helper. */
    private static function ck(string $desc, array $extra = []): array
    {
        return array_merge(['desc' => $desc], $extra);
    }

    // ─────────────────────────────────────────────────────────────────────
    //  FORM 1 — Monthly Home Visitor Monitoring & Inspection Report (iLearn)
    // ─────────────────────────────────────────────────────────────────────
    private static function monthly(): array
    {
        return [
            'key'       => 'monthly_monitoring',
            'brand'     => 'ilearn',
            'title'     => 'Monthly Home Visitor',
            'title2'    => 'Monitoring & Inspection Report',
            'confidential' => 'Confidential Agency Monitoring Document – iLearn Home Child Care Agency',
            'accent'    => '#159FB4',
            'sections'  => [
                // Top detail fields
                ['key' => 'details', 'bar' => 'none', 'blocks' => [
                    ['type' => 'head', 'fields' => [
                        ['id' => 'provider_name', 'label' => 'Provider Name', 'type' => 'text', 'w' => 'half'],
                        ['id' => 'date', 'label' => 'Date', 'type' => 'date', 'w' => 'half'],
                        ['id' => 'home_visitor', 'label' => 'Home Visitor', 'type' => 'text', 'w' => 'half'],
                        ['id' => 'time_in', 'label' => 'Time In', 'type' => 'time', 'w' => 'quarter'],
                        ['id' => 'time_out', 'label' => 'Time Out', 'type' => 'time', 'w' => 'quarter'],
                        ['id' => 'children_present', 'label' => 'Children Present', 'type' => 'text', 'w' => 'full'],
                    ]],
                ]],

                ['key' => 's1', 'bar' => 'dark', 'title' => 'SECTION 1 — CHILD INFORMATION & ATTENDANCE', 'blocks' => [
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Attendance records up to date'),
                        self::ck('Child emergency cards current'),
                        self::ck('Allergies posted and visible'),
                        self::ck('Children supervised at all times'),
                        self::ck('Ratios maintained'),
                    ]],
                    ['type' => 'subhead', 'text' => 'Child Records & Plans on File'],
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Child registration form on file'),
                        self::ck('Outdoor plan on file'),
                        self::ck('Consent for cream (sunscreen / diaper cream) on file'),
                        self::ck('Immunization record on file'),
                        self::ck('Allergy / individual plan on file'),
                    ]],
                ]],

                ['key' => 's2', 'bar' => 'dark', 'title' => 'SECTION 2 — DAILY & MONTHLY SAFETY CHECKS', 'blocks' => [
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Daily sleep checks completed'),
                        self::ck('Daily supervision checks completed'),
                        self::ck('Monthly fire drill completed'),
                    ]],
                ]],

                ['key' => 's3', 'bar' => 'dark', 'title' => 'SECTION 3 — DAILY PARENT COMMUNICATION MONITORING', 'blocks' => [
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Daily communication completed for all children'),
                        self::ck('Daily log book recorded daily'),
                        self::ck('Meals documented'),
                        self::ck('Naps documented'),
                        self::ck('Diapering / toileting documented'),
                        self::ck('Activities documented'),
                        self::ck('Photos shared appropriately'),
                        self::ck('Incident reports completed when required'),
                        self::ck('Parent concerns responded to professionally'),
                        self::ck('Parent messages responded to within agency expectations'),
                        self::ck('Communication is professional and respectful'),
                    ]],
                    ['type' => 'checkgroup', 'intro' => 'How is daily communication given?  (check all that apply – review proof)', 'cols' => 2, 'other' => true, 'boxes' => [
                        'Childcare app (e.g. HiMama / Lillio)', 'Daily log book',
                        'Printed daily sheet', 'Email',
                        'Text message', 'Phone call',
                        'In-person / verbal', 'Photos shared',
                    ]],
                    ['type' => 'textareas', 'items' => [
                        ['id' => 'proof_reviewed', 'label' => 'Proof reviewed — describe evidence / sample seen:', 'rows' => 3],
                    ]],
                ]],

                ['key' => 's4', 'bar' => 'dark', 'title' => 'SECTION 4 — PARENT BOARD – POSTED INFORMATION', 'blocks' => [
                    ['type' => 'intro', 'text' => 'Posted on the parent board for parents to see:'],
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Licence posted'),
                        self::ck('Menu posted'),
                        self::ck('Program plan posted'),
                        self::ck('Emergency phone numbers posted'),
                        self::ck('Fire evacuation plan posted'),
                    ]],
                ]],

                ['key' => 's5', 'bar' => 'dark', 'title' => 'SECTION 5 — PROGRAM QUALITY REVIEW', 'blocks' => [
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Daily routine followed'),
                        self::ck('Program plan available'),
                        self::ck('Children engaged in activities'),
                        self::ck('Indoor play materials accessible'),
                        self::ck('Outdoor play provided daily'),
                    ]],
                    ['type' => 'subhead', 'text' => "Today's Program — Home Visitor to record"],
                    ['type' => 'textareas', 'items' => [
                        ['id' => 'today_activities', 'label' => "Today's planned activities:", 'rows' => 3],
                        ['id' => 'today_lunch', 'label' => "Today's lunch menu:", 'rows' => 2],
                        ['id' => 'hv_observations', 'label' => 'Home Visitor Observations:', 'rows' => 3],
                    ]],
                ]],

                ['key' => 's6', 'bar' => 'dark', 'title' => 'SECTION 6 — HEALTH & SAFETY INSPECTION', 'blocks' => [
                    ['type' => 'subhead', 'text' => 'Indoor Environment'],
                    ['type' => 'checkgroup', 'cols' => 2, 'boxes' => [
                        'Stairs safe', 'Gates secure',
                        'Electrical outlets protected', 'Cords inaccessible',
                        'Medicines locked', 'Cleaning supplies locked',
                        'Plastic bags inaccessible', 'Hazardous materials inaccessible',
                        'Windows / screens secure', 'Rooms restricted when necessary',
                    ]],
                    ['type' => 'subhead', 'text' => 'Kitchen Safety'],
                    ['type' => 'checkgroup', 'cols' => 2, 'boxes' => [
                        'Pot handles turned inward', 'Knives inaccessible',
                        'Appliances safe',
                    ]],
                    ['type' => 'subhead', 'text' => 'Fire Safety'],
                    ['type' => 'checkgroup', 'cols' => 2, 'boxes' => [
                        'Smoke alarms operational', 'Carbon monoxide alarms operational',
                        'Fire extinguisher present', 'Fire extinguisher inspected',
                    ]],
                    ['type' => 'subhead', 'text' => 'First Aid'],
                    ['type' => 'checkgroup', 'cols' => 2, 'boxes' => [
                        'First aid kit complete', 'Emergency numbers available',
                    ]],
                    ['type' => 'subhead', 'text' => 'Outdoor Safety'],
                    ['type' => 'checkgroup', 'cols' => 2, 'boxes' => [
                        'Yard safe', 'Outdoor equipment safe',
                        'Fence secure', 'Toxic plants removed',
                        'Tools inaccessible',
                    ]],
                    ['type' => 'textareas', 'items' => [
                        ['id' => 's6_comments', 'label' => 'Comments:', 'rows' => 3],
                    ]],
                ]],

                ['key' => 's7', 'bar' => 'dark', 'title' => 'SECTION 7 — PROFESSIONAL PRACTICE MONITORING', 'blocks' => [
                    ['type' => 'checklist', 'ministry' => false, 'items' => [
                        self::ck('Provider follows agency policies'),
                        self::ck('Behaviour guidance appropriate'),
                        self::ck('Serious occurrence procedure understood'),
                        self::ck('Emergency procedures understood'),
                        self::ck('Professional learning completed this month'),
                        self::ck('Professional learning log maintained'),
                    ]],
                ]],

                ['key' => 's8', 'bar' => 'dark', 'title' => 'SECTION 8 — FIRE DRILL & EMERGENCY PREPAREDNESS', 'blocks' => [
                    ['type' => 'head', 'fields' => [
                        ['id' => 'last_fire_drill', 'label' => 'Date of Last Fire Drill', 'type' => 'date', 'w' => 'half'],
                        ['id' => 'time_to_evacuate', 'label' => 'Time to Evacuate', 'type' => 'text', 'w' => 'half'],
                    ]],
                    ['type' => 'yn', 'items' => [
                        ['id' => 'evac_reviewed', 'label' => 'Evacuation Procedure Reviewed?'],
                        ['id' => 'exit_routes_clear', 'label' => 'Emergency Exit Routes Clear?'],
                    ]],
                    ['type' => 'textareas', 'items' => [
                        ['id' => 's8_comments', 'label' => 'Comments:', 'rows' => 3],
                    ]],
                ]],

                ['key' => 's9', 'bar' => 'dark', 'title' => 'SECTION 9 — HOME VISITOR COACHING & OBSERVATIONS', 'blocks' => [
                    ['type' => 'textareas', 'items' => [
                        ['id' => 'provider_strengths', 'label' => 'Provider Strengths:', 'rows' => 4],
                        ['id' => 'coaching_provided', 'label' => 'Coaching Provided:', 'rows' => 4],
                    ]],
                ]],

                ['key' => 's10', 'bar' => 'dark', 'title' => 'SECTION 10 — CORRECTIVE ACTION PLAN', 'blocks' => [
                    ['type' => 'table', 'columns' => [
                        ['id' => 'requirement', 'label' => 'Requirement / Corrective Action', 'w' => 'wide'],
                        ['id' => 'due_date', 'label' => 'Due Date'],
                        ['id' => 'completed_date', 'label' => 'Completed Date'],
                    ], 'rows' => 4],
                ]],

                ['key' => 's11', 'bar' => 'dark', 'title' => 'SECTION 11 — SIGNATURES', 'blocks' => [
                    ['type' => 'sign', 'columns' => [
                        ['id' => 'provider_sig', 'label' => 'Provider Signature', 'type' => 'text'],
                        ['id' => 'provider_sig_date', 'label' => 'Date', 'type' => 'date'],
                        ['id' => 'hv_sig', 'label' => 'Home Visitor Signature', 'type' => 'text'],
                        ['id' => 'hv_sig_date', 'label' => 'Date', 'type' => 'date'],
                    ]],
                ]],
            ],
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  FORM 2 — Standard Home Visitor Checklist (Ministry of Education)
    // ─────────────────────────────────────────────────────────────────────
    private static function quarterly(): array
    {
        return [
            'key'       => 'quarterly_checklist',
            'brand'     => 'ministry',
            'title'     => 'Standard Home Visitor Checklist',
            'subtitle'  => "Ministry of Education\nChild Care Quality Assurance and Licensing Branch",
            'confidential' => 'Standard Home Visitor Checklist — Ministry of Education',
            'accent'    => '#111111',
            'sections'  => [
                ['key' => 'instr', 'bar' => 'none', 'title' => 'INSTRUCTIONS', 'blocks' => [
                    ['type' => 'static', 'html' =>
                        '<p>Ontario Regulation 137/15 made under the <em>Child Care and Early Years Act, 2014</em> (CCEYA) requires that:</p>'
                        . '<p style="margin-left:18px;">26. (1) Every licensee of a home child care agency shall ensure that before a premises is used as a premises where the licensee is to oversee the provision of home child care, the premises, including the outdoor play space, is inspected by a home child care visitor employed by the licensee to ensure compliance with the Act and this Regulation and, where the premises is so used, that further inspections are carried out without prior notice to the home child care provider, at least once in every quarter of each calendar year, and at such other times as the director may require.<br>(2) The home child care visitor shall use any checklist provided by the director in performing an inspection of a home child care premises.</p>'
                        . '<p>The following checklist must be completed in its entirety for all home child care premises inspections conducted by home visitors of a licensed home child care agency, before a premises is used to provide home child care or in-home services and, at a minimum, on a quarterly basis thereafter. The checklist must be fully completed by the end of each quarter. Home child care visitors may wish to conduct multiple inspections to a premises during a quarter and complete sections of the checklist at that time.</p>'
                        . '<p>The checklist questions that appear in the standard home visitor checklist reflect requirements under the CCEYA and O. Reg. 137/15 only. Each checklist question has a corresponding risk level. Licensing requirements have four risk levels:</p>'
                        . '<ul>'
                        . '<li><strong>Critical:</strong> Non-compliance poses a direct threat to a child which could result in / has resulted in death</li>'
                        . '<li><strong>High:</strong> Non-compliance poses a direct threat to a child which could result in / has resulted in serious harm to their health, safety and well-being</li>'
                        . '<li><strong>Moderate:</strong> Non-compliance poses an indirect threat to a child which could result in / has resulted in harm to the health, safety and well-being of a child</li>'
                        . '<li><strong>Low:</strong> Non-compliance is not as likely to pose a threat to the health, safety and well-being of children, but the possibility exists.</li>'
                        . '</ul>'
                    ],
                ]],

                ['key' => 'details', 'bar' => 'catplain', 'title' => 'INSPECTION DETAILS', 'blocks' => [
                    ['type' => 'head', 'fields' => [
                        ['id' => 'agency_name_licence', 'label' => 'Home Child Care Agency Name and Licence Number', 'type' => 'text', 'w' => 'full'],
                        ['id' => 'premises_address', 'label' => 'Address of Home Child Care Premises', 'type' => 'text', 'w' => 'full'],
                        ['id' => 'provider_name', 'label' => 'Name of Home Child Care Provider', 'type' => 'text', 'w' => 'full'],
                        ['id' => 'inspection_dates', 'label' => 'Date of Inspection(s)', 'type' => 'text', 'w' => 'full'],
                        ['id' => 'start_time_1', 'label' => 'Start Time of Inspection(s)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'end_time_1', 'label' => 'End Time of Inspection(s)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'start_time_2', 'label' => 'Start Time (2)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'end_time_2', 'label' => 'End Time (2)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'start_time_3', 'label' => 'Start Time (3)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'end_time_3', 'label' => 'End Time (3)', 'type' => 'time', 'w' => 'half'],
                        ['id' => 'quarter', 'label' => 'Quarter', 'type' => 'select', 'options' => ['1', '2', '3', '4'], 'w' => 'half'],
                        ['id' => 'home_visitor', 'label' => 'Name of Home Visitor', 'type' => 'text', 'w' => 'half'],
                    ]],
                    ['type' => 'intro', 'text' => 'Other Authorities / Representatives, if present at the time of inspection (e.g., other agency home visitors, Children’s Aid Society representatives, Ministry of Education program advisor, etc.)'],
                    ['type' => 'table', 'columns' => [
                        ['id' => 'name', 'label' => 'Name', 'w' => 'wide'],
                        ['id' => 'organization', 'label' => 'Organization', 'w' => 'wide'],
                    ], 'rows' => 3],
                ]],

                ['key' => 'children', 'bar' => 'catplain', 'title' => 'CHILDREN RECEIVING CHILD CARE AT THIS PREMISES', 'blocks' => [
                    ['type' => 'intro', 'text' => 'Include information about every child receiving care at the premises, including privately-placed children and children who are not present on the date of the inspection.'],
                    ['type' => 'table', 'columns' => [
                        ['id' => 'child_name', 'label' => 'Child’s First Name and Last Initial', 'w' => 'wide'],
                        ['id' => 'dob_age', 'label' => 'Date of Birth and Age'],
                        ['id' => 'days_hours', 'label' => 'Days and Hours of Care', 'w' => 'wide'],
                        ['id' => 'present', 'label' => 'Present? (Y/N)', 'w' => 'narrow'],
                    ], 'rows' => 14, 'comments' => ['id' => 'children_comments', 'label' => 'Comments regarding children receiving care']],
                ]],

                ['key' => 'own_children', 'bar' => 'catplain', 'title' => "PROVIDER’S OWN CHILDREN", 'blocks' => [
                    ['type' => 'intro', 'text' => "Include information about the provider’s own children who may be counted in the total number of children receiving care. For more information, please refer to the Ministry infographic called “Home Child Care and Unlicensed Child Care: How Many Children Are Allowed?”"],
                    ['type' => 'table', 'note' => 'Note: If the child care is provided on or after September 1 in a calendar year, a child who will attain the age of four in that year shall not be counted on any day.', 'columns' => [
                        ['id' => 'child_name', 'label' => 'Child’s First Name and Last Initial', 'w' => 'wide'],
                        ['id' => 'dob', 'label' => 'Date of Birth'],
                        ['id' => 'age', 'label' => 'Age'],
                        ['id' => 'counted', 'label' => 'Counted in the Total Number of Children During the Inspection? (Yes/No)', 'w' => 'wide'],
                    ], 'rows' => 6, 'comments' => ['id' => 'own_children_comments', 'label' => "Comments regarding provider’s own children:"]],
                ]],

                ['key' => 'residents', 'bar' => 'catplain', 'title' => 'OTHER PERSONS ORDINARILY RESIDENT OF THE PREMISES', 'blocks' => [
                    ['type' => 'intro', 'text' => "Individuals who may have access to children in care (including supervised access) because they use the premises as a primary residence for at least some period during the year (e.g., the provider’s spouse, adult children, adult dependents, etc.). Upon return to the agency, confirm that the individual’s vulnerable sector check is on file at the agency."],
                    ['type' => 'table', 'columns' => [
                        ['id' => 'name', 'label' => 'Name of Individual', 'w' => 'wide'],
                        ['id' => 'relationship', 'label' => 'Role/Relationship to Provider'],
                        ['id' => 'present', 'label' => 'Present? (Y/N)', 'w' => 'narrow'],
                        ['id' => 'comments', 'label' => 'Comments', 'w' => 'wide'],
                        ['id' => 'vsc', 'label' => 'VSC on File?', 'w' => 'narrow'],
                    ], 'rows' => 5],
                ]],

                ['key' => 'regulars', 'bar' => 'catplain', 'title' => 'OTHER PERSONS REGULARLY AT THE PREMISES', 'blocks' => [
                    ['type' => 'intro', 'text' => "An individual who is present at the premises during hours in which care is provided often enough that children in care are able to recognize the individual. This would include persons who are present frequently during a short period of time (e.g., visiting family members) or repeatedly (e.g., the provider’s friend who visits the premises once a week, or a neighbour who visits the premises every other month to provide tutoring to the providers own child). Upon return to the agency, confirm that the individual’s vulnerable sector check is on file at the agency."],
                    ['type' => 'table', 'columns' => [
                        ['id' => 'name', 'label' => 'Name of Individual', 'w' => 'wide'],
                        ['id' => 'relationship', 'label' => 'Role/Relationship to Provider'],
                        ['id' => 'present', 'label' => 'Present? (Y/N)', 'w' => 'narrow'],
                        ['id' => 'comments', 'label' => 'Comments', 'w' => 'wide'],
                        ['id' => 'vsc', 'label' => 'VSC on File?', 'w' => 'narrow'],
                    ], 'rows' => 6],
                ]],

                // ── The regulatory checklist, grouped by category band ──
                ['key' => 'cat_children', 'bar' => 'cat', 'title' => 'NUMBER OF CHILDREN', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '1', 'ref' => 'ss. 6(3)1.i.A. CCEYA', 'risk' => 'High', 'desc' => "One child care provider provides care for no more than six children which include the provider’s own children who are under four years of age at the premises, where applicable.", 'note2' => 'Note: If the child care is provided on or after September 1 in a calendar year, a child who will attain the age of four in that year shall not be counted on any day.'],
                        ['n' => '2', 'ref' => 'ss. 6(3)1.iv.A. CCEYA', 'risk' => 'High', 'desc' => 'The provider does not care for more than three children who are younger than two years old, unless otherwise approved by a director.'],
                        ['n' => '3', 'ref' => 'ss.9(2)', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'The provider complies with the maximum capacity as set out in the agreement established between the agency and provider.'],
                        ['n' => '4', 'ref' => 's.11', 'risk' => 'Critical', 'desc' => 'Every child is supervised by an adult at all times whether on or off the premises.'],
                        ['n' => '5', 'ref' => 'ss. 11.1 (1)', 'risk' => 'High', 'desc' => 'Every volunteer or student is supervised by the provider at all times and is not permitted to be alone with any child.'],
                        ['n' => '6', 'ref' => 'ss.6.1 (1) for ss. 11.1 (2)', 'risk' => 'Moderate', 'desc' => 'The agency’s policies and procedures regarding volunteers and students are being implemented at the premises.'],
                    ]],
                ]],

                ['key' => 'cat_building', 'bar' => 'cat', 'title' => 'BUILDING, EQUIPMENT AND PLAYGROUND – HOME CHILD CARE', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '7', 'ref' => 'ss. 27(2)', 'risk' => 'Moderate', 'desc' => 'Play materials:', 'subs' => [
                            'are provided in numbers adequate to serve the number of children receiving child care;',
                            'are of sufficient variety to allow for rotation of play materials in active use;',
                            'are available and accessible to the children throughout the day;',
                            'allow the children to make choices and to encourage exploration, play and inquiry;',
                            'are appropriate to support the learning and development of each child.',
                        ]],
                        ['n' => '8', 'ref' => 'ss. 27(3)1.', 'risk' => 'Critical', 'desc' => 'Each infant (i.e. a child who is younger than 18 months of age) who receives home child care at the premises is provided with a cradle, crib or playpen and bedding for rest.'],
                        ['n' => '9', 'ref' => 'ss. 27(3)2.', 'risk' => 'Moderate', 'desc' => 'Each child who receives home child care at the premises for six hours or more, who is 18 months old up to and including five years old, is provided a cot or bed and bedding for rest, unless otherwise approved by a director.'],
                        ['n' => '10', 'ref' => 'ss. 27(4)', 'risk' => 'High', 'desc' => 'The play materials, equipment and furnishings are maintained in a safe condition and kept in a good state of repair.'],
                        ['n' => '11', 'ref' => 's. 28', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'The temperature in the premises is maintained at a minimum of 20 degrees Celsius.'],
                        ['n' => '12', 'ref' => 's. 29', 'risk' => 'Critical', 'desc' => 'Children are only permitted to be on a balcony with an adult present on the balcony.'],
                        ['n' => '13', 'ref' => 's. 30', 'risk' => 'Critical', 'desc' => 'Outdoor play is supervised in accordance with the plans agreed upon by the provider, the parent of a child receiving care and a home visitor.'],
                        ['n' => '14', 'ref' => 'ss. 30.1(1)', 'risk' => 'Critical', 'desc' => 'Children under six years old are not permitted to use or have access to any standing or recreational body of water on the premises.'],
                        ['n' => '15', 'ref' => 'ss. 30.1(2)(a)', 'risk' => 'Critical', 'desc' => 'If children who are six years old or older are permitted to use or have access to a standing or recreational body of water at the premises, a qualified lifeguard is present.'],
                        ['n' => '16', 'ref' => 'ss. 6.1(1) for 30.1(2)(b)', 'risk' => 'Critical', 'desc' => 'If children who are six years old or older are permitted to use or have access to a standing or recreational body of water, the agency’s written policies and procedures regarding children’s use of and access to the body of water are being implemented at the premises.'],
                        ['n' => '17', 'ref' => 'ss. 31(a)', 'risk' => 'Critical', 'desc' => 'All items that could cause harm to a child such as poisonous and hazardous substances are inaccessible to children.'],
                        ['n' => '18', 'ref' => 'ss. 31(b)', 'risk' => 'Critical', 'desc' => 'All firearms and ammunition are locked up and the key, if any is inaccessible to children.'],
                    ]],
                ]],

                ['key' => 'cat_health', 'bar' => 'cat', 'title' => 'HEALTH AND MEDICAL SUPERVISION', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '19', 'ref' => 'ss. 33.1(1)', 'risk' => 'Critical', 'desc' => 'A child who is younger than 12 months is placed for sleep in the required position, unless the child’s physician has recommended otherwise in writing.'],
                        ['n' => '20', 'ref' => 'ss. 33(2)(a),(b)', 'risk' => 'High', 'desc' => 'If care is provided for a child who regularly sleeps at the premises:', 'subs' => [
                            'the provider periodically performs a direct visual check of each sleeping child who is under 24 months of age, by being physically present beside the child and looking for indicators of distress or unusual behaviours;',
                            'there is sufficient light in the sleeping area or room to conduct direct visual checks.',
                        ]],
                        ['n' => '21', 'ref' => 'ss. 6.1(1) for 33.1(2)(c)', 'risk' => 'High', 'desc' => 'The agency’s policies and procedures with respect to sleep are being implemented at the premises.'],
                        ['n' => '22', 'ref' => 'ss. 33.1(5)', 'risk' => 'Moderate', 'desc' => 'If electronic sleep monitoring devices are used:', 'subs' => [
                            'each device is able to detect and monitor the sounds and, if applicable, video images of every sleeping child;',
                            'the receiving unit of the device is actively monitored by the provider at all times;',
                            'each device is checked daily to ensure it is functioning properly;',
                            'the devices are not used as a replacement for the required direct visual checks.',
                        ]],
                        ['n' => '23', 'ref' => 's. 34', 'risk' => 'Moderate', 'desc' => 'There is a first-aid kit and first-aid manual readily available for first-aid treatment in the premises.'],
                        ['n' => '24', 'ref' => 'ss. 36(1)', 'risk' => 'High', 'note' => '*Does not apply to in-home services', 'desc' => 'A daily observation is made of each child to detect possible symptoms of ill health before the child begins to associate with other children.'],
                        ['n' => '25', 'ref' => 'ss. 36(2)', 'risk' => 'High', 'note' => '*Does not apply to in-home services', 'desc' => 'A child who appears to be ill is separated from other children and the symptoms of the child’s illness are noted in the child’s records.'],
                        ['n' => '26', 'ref' => 'ss. 36(3)', 'risk' => 'High', 'note' => '*Does not apply to in-home services', 'desc' => 'A child who is ill is taken home by a parent or examined by a medical practitioner or nurse as required.'],
                        ['n' => '27', 'ref' => 'ss. 36(4)', 'risk' => 'Moderate', 'desc' => 'When a child receiving care is injured, an accident report is made describing the circumstances of the injury and any first aid administered and a copy of the report is provided to a parent of the child.', 'prompts' => ['Number of accidents since last inspection:', 'Were measures taken to minimize recurrence? (please specify):']],
                        ['n' => '28', 'ref' => 'ss. 37(1)(c),(d)', 'risk' => 'Moderate', 'desc' => 'A daily written record is maintained including a summary of incidents affecting the health, safety or well-being of any child receiving care and the provider providing care.'],
                        ['n' => '29', 'ref' => 'ss. 37(2)', 'risk' => 'Moderate', 'desc' => 'A parent is notified when an incident affecting the health, safety or well-being of a child occurs.'],
                        ['n' => '30', 'ref' => 'ss. 6.1(1) for ss. 38(1)(a)', 'risk' => 'Moderate', 'desc' => 'The agency’s serious occurrence policies and procedures are being implemented at the premises.', 'prompts' => ['Number of serious occurrences since last inspection:', 'Were measures taken to minimize recurrence? (please specify):']],
                        ['n' => '31', 'ref' => 'ss. 38(1)(c)', 'risk' => 'Moderate', 'desc' => 'A summary of the serious occurrence report and any action taken as a result of the serious occurrence is posted for at least 10 business days at the premises.'],
                        ['n' => '32', 'ref' => 'ss. 6.1(1) for ss. 39(1)', 'risk' => 'Critical', 'desc' => 'The agency’s anaphylactic policy is being implemented at the premises.'],
                        ['n' => '33', 'ref' => 'ss. 39(2)', 'risk' => 'Critical', 'desc' => 'There is an individualized plan for each child with an anaphylactic allergy who is enrolled with the home child care agency that has been developed in consultation with a parent of the child and with any regulated health professional who is involved in the child’s health care and who, in the parent’s opinion, should be included in the consultation that describes the procedures to be followed in the event of an allergic reaction or other medical emergency.', 'prompts' => ['Names of children with anaphylactic allergies:']],
                        ['n' => '34', 'ref' => 'ss. 6.1(1) for 39(2)', 'risk' => 'Critical', 'desc' => 'Each individualized plan for a child an anaphylactic allergy is being implemented at the premises.'],
                        ['n' => '35', 'ref' => 'ss. 39.1(2), (3)', 'risk' => 'High', 'desc' => 'There is an individualized plan for each child with medical needs who is enrolled with the home child care agency that has been developed in consultation with a parent of the child and with any regulated health professional who is involved in the child’s health care and who, in the parent’s opinion, should be included in the consultation and describes:', 'subs' => [
                            'the steps to reduce the risk of the medical condition(s) worsening;',
                            'a description of any medical devices used by the child and instructions on how to use them;',
                            'a description of the procedures to be followed if the child has an allergic reaction or other medical emergency;',
                            'a description of the supports made available to the child, if any;',
                            'any additional procedures to be followed during an evacuation or off-site field trip.',
                        ], 'note2' => 'NOTE: An additional individualized plan is not required for a child with an anaphylactic allergy, if the child does not otherwise have a medical need, as these children must already have an individualized plan under the anaphylactic policy.'],
                        ['n' => '36', 'ref' => 'ss. 6.1(1) for 39(1)', 'risk' => 'Critical', 'desc' => 'Each individualized plan for a child with medical needs is being implemented at the premises.'],
                        ['n' => '37', 'ref' => 'ss. 6.1(1) for ss. 40(1)', 'risk' => 'Critical', 'desc' => 'The agency’s written procedure for the administration of any drug or medication to a child is being implemented at the premises.', 'prompts' => ['Medications on site at the time of inspection:']],
                        ['n' => '38', 'ref' => 'ss. 40(2)', 'risk' => 'High', 'desc' => 'A child is permitted to carry his or her own asthma medication or emergency allergy medication in accordance with established written procedures.'],
                        ['n' => '39', 'ref' => 's. 41', 'risk' => 'High', 'desc' => 'Every dog and/or cat and/or ferret that is kept on the premises is inoculated against rabies.'],
                    ]],
                ]],

                ['key' => 'cat_nutrition', 'bar' => 'cat', 'title' => 'NUTRITION', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '40', 'ref' => 'ss. 42(1)(a)', 'risk' => 'High', 'note' => '*Does not apply to in-home services', 'desc' => 'Each child under one year old is fed in accordance with written instructions from a parent.'],
                        ['n' => '41', 'ref' => 'ss. 42(1)(b)', 'risk' => 'High', 'note' => '*Does not apply to in-home services', 'desc' => 'Food or drink supplied by a parent is labelled with the child’s name.'],
                        ['n' => '42', 'ref' => '*ss. 42(2) 1,2, 3 and 5', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'Each child one year of age or older but younger than 44 months of age is supplied and provided all required meals and snacks by the provider. Meals, snacks or beverages that meet the recommendations set out in the most recent and relevant food guide published by Health Canada.'],
                        ['n' => '43', 'ref' => 'ss. 42(2)4', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'Drinking water is available at all times.'],
                        ['n' => '44', 'ref' => 'ss. 42(3)', 'risk' => 'Critical', 'note' => '* This provision only applies to in-home services', 'desc' => 'Every licensee shall ensure that a child who receives in-home services at a premises overseen by the licensee shall be fed in accordance with written instructions from the child’s parent'],
                        ['n' => '45', 'ref' => 'ss. 43(4)', 'risk' => 'Low', 'desc' => 'Menus are planned in consultation with a parent and a home child care visitor and that the menu, and the meals and snacks it provides, meet the requirements set out in the most recent and relevant food guide published by Health Canada.', 'prompts' => ['Discussions with provider regarding menus and meal/snack planning:']],
                        ['n' => '46', 'ref' => 'ss. 44', 'risk' => 'Critical', 'note' => '* Does not apply to in-home services', 'desc' => 'Special dietary and feeding arrangements are carried out in accordance with the written instructions of a parent.'],
                    ]],
                ]],

                ['key' => 'cat_program', 'bar' => 'cat', 'title' => 'PROGRAM FOR CHILDREN', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '47', 'ref' => 'ss. 46(5)', 'risk' => 'Moderate', 'desc' => 'The approaches set out in the program statement are being implemented at the premises.', 'prompts' => ['What approaches in the program statement were observed?']],
                        ['n' => '48', 'ref' => '*ss. 47(3)(a)', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'Each child who is 18 months or older but younger than 6 years old and who receives child care for six or more hours has a rest period not exceeding two hours in length.'],
                        ['n' => '49', 'ref' => '*ss. 47(3)(b)', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'A child who is 18 months or older but younger than 7 years old is permitted to sleep, rest or engage in quiet activities based on their needs.'],
                        ['n' => '50', 'ref' => 'ss. 47(4)', 'risk' => 'Moderate', 'note' => '*Does not apply to in-home services', 'desc' => 'Each child who receives child care for six or more hours spends time outdoors for at least two hours each day, weather permitting, unless a physician or parent of the child has advised otherwise in writing.'],
                        ['n' => '51', 'ref' => 'ss. 48 (2)', 'risk' => 'Critical', 'desc' => 'No person who provides home child care, volunteer of the licensee, or student on educational placement with the licensee or the provider has engaged or engages in any prohibited practices prescribed in the regulation.'],
                        ['n' => '52', 'ref' => 'ss. 47(5)', 'risk' => 'Moderate', 'note' => '* This provision only applies to in-home services.', 'desc' => 'Every licensee shall ensure that the program in each premises where it oversees the provision of in-home services is arranged to include sleep, rest or quiet time and outdoor activities in accordance with written instructions from a child’s parent.'],
                        ['n' => '53', 'ref' => 'ss. 52(1), (2)', 'risk' => 'High', 'desc' => 'There is an up-to-date individualized support plan for each child with special needs who receives child care at the premises that has been developed in consultation with a parent, the child (if appropriate), and any regulated health professional or other person who works with the child in a capacity that would allow the person to help inform the plan and describes:', 'subs' => [
                            'how the child will be supported to function and participate in a meaningful and purposeful manner;',
                            'any supports or aids, or adaptations or other environmental modifications;',
                            'the child’s use of supports or aids, or the child’s use of or interaction with the adapted or modified environment.',
                        ]],
                        ['n' => '54', 'ref' => 'ss. 6.1(1) for ss. 52(1)', 'risk' => 'High', 'desc' => 'Each individualized plan support plan for each child with special needs is being implemented at the premises.'],
                    ]],
                ]],

                ['key' => 'cat_emergency', 'bar' => 'cat', 'title' => 'EMERGENCY PREPAREDNESS', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '55', 'ref' => 'ss.10(1) CCEYA', 'risk' => 'Low', 'desc' => 'A parent is not prevented from having access to his or her child except if there are reasonable grounds to believe that the parent does not have a legal right of access to the child.'],
                        ['n' => '56', 'ref' => 'ss.10(2) CCEYA', 'risk' => 'Low', 'desc' => 'A parent is not prevented from entering the premises while child care is provided there for his or her child without reasonable grounds to do so.'],
                        ['n' => '57', 'ref' => 's. 67', 'risk' => 'Critical', 'desc' => 'There is telephone service that can be used to obtain emergency services and is accessible at all times.'],
                        ['n' => '58', 'ref' => 'ss. 68(3)', 'risk' => 'High', 'desc' => 'A written fire evacuation procedure is established for the premises in the event of a fire.'],
                        ['n' => '59', 'ref' => 'ss. 69', 'risk' => 'Moderate', 'desc' => 'Premises with no access to 9-1-1 call centre the emergency contact list includes:', 'bullets' => ['Emergency services', 'Poison control']],
                        ['n' => '60', 'ref' => 's. 70', 'risk' => 'High', 'desc' => 'The following information about each child is up to date and readily accessible to each provider:', 'subs' => [
                            'Telephone numbers for a parent and a telephone number for an emergency contact and an alternate contact if parent cannot be reached.',
                            'Any special medical or additional information about each child that could be helpful in an emergency.',
                        ]],
                    ]],
                ]],

                ['key' => 'cat_admin', 'bar' => 'cat', 'title' => 'ADMINISTRATIVE MATTERS & MISCELLANEOUS', 'checklistHeader' => true, 'blocks' => [
                    ['type' => 'checklist', 'ministry' => true, 'items' => [
                        ['n' => '61', 'ref' => 'ss. 6(3)1.iii. CCEYA', 'risk' => 'Moderate', 'desc' => 'The provider has advised the agency of all of the children at the premises.'],
                        ['n' => '62', 'ref' => 's.15 CCEYA', 'risk' => 'Low', 'desc' => 'A receipt for payment is provided upon request to a person who pays for child care, free of charge, where applicable.'],
                        ['n' => '63', 'ref' => 'ss. 72(1) & 72(2)(b)', 'risk' => 'High', 'desc' => 'Records for each child are kept at the premises, up-to-date and available for inspection, and include:', 'subs' => [
                            'an application for enrolment signed by the parent that includes the information required under s. 72(1)1;',
                            'previous history of communicable diseases, conditions requiring medical attention and immunization or objection, as required;',
                            'any ill health symptoms;',
                            'a copy of any individualized plan;',
                            'signed, written parental instructions for medical treatment, drug or medication administration;',
                            'signed, written parental instructions for requirements for diet, rest or physical activity;',
                            'a copy of any written recommendation from a child’s physician regarding the child’s placement for sleep.',
                        ]],
                        ['n' => '64', 'ref' => 'ss. 72(3)', 'risk' => 'High', 'desc' => 'Daily attendance records are kept showing the arrival and departure times of each child or their absence.'],
                        ['n' => '65', 'ref' => 'ss. 14(2), (5) CCEYA, s.84(1) O.Reg 137/15', 'risk' => 'Low', 'desc' => 'The licensed child care decal is posted in a visible/obvious place at this premises, and no unauthorized copies are made.'],
                        ['n' => '66', 'ref' => 'ss. 32(1)', 'risk' => 'High', 'desc' => 'The provider is observed carried out any direction of a medical officer of health regarding matters that may affect the health or well-being of a child.'],
                        ['n' => '67', 'ref' => 'ss. 32(2)', 'risk' => 'Low', 'desc' => 'Where a report has been made by the local medical officer of health, any person designated by the MOH or the local fire department, a copy of the report is observed to be saved on the premises.'],
                        ['n' => '68', 'ref' => 'ss. 40(4)(a)(b)(c)', 'risk' => 'Moderate', 'desc' => 'Where the provider has administered items under ss.40(3) the following have met:', 'subs' => [
                            'Parental authorization for administration',
                            'Items have been stored in accordance with the instruction on the label',
                            'The container or package is clearly labelled with the child’s name and the name of the item',
                            'Items have only been administered from the original container or package',
                            'Items administered in accordance with written instructions on the label and any instructions provided by ta parent.',
                        ]],
                    ]],
                ]],

                ['key' => 'noncompliance', 'bar' => 'catplain', 'title' => 'SUMMARY & ACTION PLAN', 'blocks' => [
                    ['type' => 'textareas', 'items' => [
                        ['id' => 'noncompliances', 'label' => 'Summary of Observed Non-Compliances:', 'rows' => 6],
                        ['id' => 'action_plan', 'label' => 'Action Plan for Compliance:', 'rows' => 5],
                        ['id' => 'required_completion_date', 'label' => 'Required Completion Date:', 'rows' => 2],
                        ['id' => 'additional_comments', 'label' => 'Additional Comments, Observations, and/or Recommendations:', 'rows' => 6],
                    ]],
                ]],

                ['key' => 'signatures', 'bar' => 'catplain', 'title' => 'SIGNATURES', 'blocks' => [
                    ['type' => 'sign', 'statements' => [
                        'I confirm that this inspection was conducted without prior notice to the home child care provider who is the subject of the inspection, and that the results of this inspection and any action plans have been reviewed with the home child care provider.',
                        'I confirm that the home visitor has reviewed the results of the inspection with me and that, where non-compliances have been identified, I clearly understand the requirements and action plan that must be followed.',
                    ], 'columns' => [
                        ['id' => 'hv_name', 'label' => 'Name of Home Visitor (Please Print)', 'type' => 'text'],
                        ['id' => 'hv_sig', 'label' => 'Signature', 'type' => 'text'],
                        ['id' => 'hv_sig_date', 'label' => 'Date', 'type' => 'date'],
                        ['id' => 'provider_print', 'label' => 'Name of Home Child Care Provider (Please Print)', 'type' => 'text'],
                        ['id' => 'provider_sig', 'label' => 'Signature', 'type' => 'text'],
                        ['id' => 'provider_sig_date', 'label' => 'Date', 'type' => 'date'],
                    ]],
                    ['type' => 'textareas', 'items' => [
                        ['id' => 'post_inspection', 'label' => 'Post-Inspection Confirmation of Compliance and Follow-Up Comments:', 'rows' => 8],
                    ]],
                ]],
            ],
        ];
    }
}
