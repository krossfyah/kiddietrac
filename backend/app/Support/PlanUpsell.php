<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\FeatureFlagController;
use Illuminate\Support\Facades\DB;

/**
 * LOCKED FEATURES SHOWN TO AGENCY ADMINS AS AN UPGRADE (2026-11-26).
 *
 * Anthony's choice: a feature the agency's plan does not include stays out of sight for staff and parents (and the
 * API still refuses it), but the AGENCY ADMIN sees it greyed out with an "Upgrade" badge; tapping it explains what
 * it does and which plan or add-on brings it, with a way to ask for it. A platform admin keeps the agency's view as
 * it is (they can switch the feature on themselves).
 *
 * Only what the PLAN leaves out is offered: a feature a platform admin switched off on purpose (an explicit Off in
 * feature_flags) is not "for sale" to that agency, so it stays hidden like before. hidden_screens keeps listing the
 * locked screens - every other reader (search, the route guard, the API) treats them as off; only the menu and the
 * tiles draw them, as locked, from this list.
 */
final class PlanUpsell
{
    /** What it does, in one line, for the features a plan can leave out. Anything else uses its catalogue label. */
    public const BLURBS = [
        'sms'              => 'Text and call families: announcements by text and voice, Safe Arrival check-ins when a child has not arrived, and the parent phone line for calling in absent or late.',
        'ai_tools'         => 'AI help across the portal: daily summaries for families, writing help, report-card drafts, play ideas and document reading.',
        'white_label'      => 'Your own brand on the portal, the apps, emails and your public site, with no KiddieTrac branding.',
        'hr'               => 'KiddieTrac HR: leave balances on every time-off request, your own leave types and rules, a full employee file, an absence report, hiring with your own jobs page, onboarding checklists, performance reviews and goals, course assignments with certificates, rotas and timesheet approval, health and safety records, and Canadian payroll with T4, T4A and RL-1 slips.',
        'payroll'          => 'Payroll documents and password-protected payslips for your team, with notices when a new payslip is ready.',
        'autopay'          => 'Families pay by card or bank automatically: saved payment methods and nightly autopay runs.',
        'expenses'         => 'Track expenses, suppliers and purchase orders by centre.',
        'accounting_sync'  => 'Send invoices and payments to QuickBooks, Xero or Sage automatically.',
        'data_retention'   => 'Archive and clear old records on a schedule that follows your retention policy.',
        'marketing'        => 'Email campaigns and automatic drip sequences for enquiries and families.',
        'sales_crm'        => 'An enquiry-to-enrolment pipeline, and re-enrolment that families answer for each child.',
        'analytics'        => 'Enrolment forecasts, engagement and retention analytics, anomaly alerts and each child\'s trends.',
        'multi_centre'     => 'Run several centres under one agency.',
        'field_trips'      => 'Walk Watch, field trips with live GPS, bus routes and safe zones.',
        'staff_scheduling' => 'Staff schedules, a substitute list with cover shifts, and room rotations.',
        'timesheets'       => 'Time clock, timesheets and time-off requests.',
        'billing'          => 'Invoices, billing schedules that run themselves, tuition increases with notice, vacation hold credits, reminders and late fees for families.',
        'immunizations'    => 'Immunization records and reminders, against your province\'s schedule.',
        'observations'     => 'Observations, photos, videos, portfolios and milestones.',
    ];

    /**
     * hash => {feature, label, blurb, via} for an agency admin of a licensed agency; [] for everyone else.
     *
     * @param string[] $roles
     */
    public static function lockedFor(?int $agencyId, array $roles): array
    {
        if (! $agencyId || ! in_array('agency_admin', $roles, true) || in_array('platform_admin', $roles, true)) {
            return [];
        }
        if (! Licences::managed($agencyId) || Licences::lapsed($agencyId)) {
            return [];
        }
        $raw = DB::table('agencies')->where('id', $agencyId)->value('feature_flags');
        $stored = json_decode((string) $raw, true) ?: [];
        $eff = FeatureFlagController::effectiveFor($agencyId);
        $hidden = array_flip(FeatureFlagController::hiddenHashesFor($agencyId, false, $roles));

        $out = [];
        foreach (FeatureFlagController::FEATURES as $key => $meta) {
            if (! empty($eff[$key]) || array_key_exists($key, $stored) || in_array($key, FeatureFlagController::PLATFORM_WIDE, true)) {
                continue;
            }
            $info = ['feature' => $key, 'label' => $meta['label'], 'blurb' => self::BLURBS[$key] ?? $meta['label'] . '.', 'via' => self::via($key, $meta)];
            foreach ($meta['hashes'] ?? [] as $h) {
                if (isset($hidden[$h]) && ! isset($out[$h])) {
                    $out[$h] = $info;
                }
            }
        }

        return $out;
    }

    /** "the SMS Bundle add-on" / "the Growth plan". */
    public static function via(string $key, array $meta): string
    {
        $min = (string) ($meta['plan_min'] ?? 'free');
        if ($min === 'addon') {
            $name = DB::table('sales_products')->where('kind', 'addon')->where('active', 1)
                ->whereRaw('JSON_CONTAINS(COALESCE(feature_keys, JSON_ARRAY()), JSON_QUOTE(?))', [$key])->value('name');

            return $name ? 'the ' . $name . ' add-on' : 'an add-on';
        }

        return 'the ' . (Licences::PLAN_NAMES[$min] ?? ucfirst($min)) . ' plan';
    }
}
