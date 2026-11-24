<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * THE PLAN IS ENFORCED BY THE API, NOT ONLY THE MENU (2026-10-24).
 *
 * Anthony: "are all the features in the sales sheet wired up correctly based on the licensing -
 * full sweep". They were not: the portal hid a feature's screens (hidden_screens), but only one
 * route in the whole API (expenses) checked the plan, so a Starter agency could still call the
 * payroll, AI or marketing APIs directly. This applies FeatureFlagController::effectiveFor to the
 * API paths each sold feature owns, in one place, so a new route under a feature's path is
 * covered without anyone remembering to add `feature:` to it.
 *
 * Only paths a feature OWNS are listed. Shared infrastructure (auth, parents paying, the photo
 * feed, clock in/out, webhooks with no signed-in user, platform/sales screens) is never gated.
 * Agencies with no licence and nothing switched off skip the check entirely (everything is on).
 */
final class EnforcePlanFeatures
{
    /** feature key => [path prefixes after api/v1/, optional HTTP methods (empty = all)] */
    public const MAP = [
        'lesson_plans'     => [['provider/lesson-plans', 'provider/lesson-plan-templates', 'parent/lesson-plan', 'curriculum', 'analytics/hdlh-gaps'], []],
        'observations'     => [['provider/observations'], []],
        'field_trips'      => [['operations/field-trips', 'field-trips/', 'operations/bus-routes', 'zones'], []],
        'conferences'      => [['conferences'], []],
        'immunizations'    => [['immunization/', 'director/immunizations', 'admin/immunization-records', 'admin/immunization-reminders'], []],
        'medications'      => [['provider/medications', 'director/medications'], []],
        'wellness'         => [['wellness/'], []],
        'awards'           => [['provider/awards', 'parent/awards'], []],
        'parent_feedback'  => [['engagement/nps', 'feedback/day', 'feedback/mine-educator'], []],
        'sms'              => [['admin/sms', 'admin/sms-settings', 'sms-replies'], []],
        'staff_scheduling' => [['director/schedule', 'operations/substitutes', 'operations/sub-requests', 'operations/room-rotations', 'provider/educator-rooms'], []],
        'timesheets'       => [['director/timesheets', 'time-off', 'admin/time-off'], []],
        'payroll'          => [['admin/payroll', 'admin/payroll-settings', 'exports/payroll', 'provider/payroll-documents', 'payroll-documents/', 'me/payslips', 'agency/payslip-settings'], []],
        'certifications'   => [['director/certifications', 'admin/background-checks', 'staff-certifications', 'admin/staff-compliance'], []],
        'home_visits'      => [['home-visits', 'inspection-forms', 'operations/inspection'], []],
        'billing'          => [['billing/schedule', 'billing/etransfer-requests', 'billing/etransfer-reports', 'admin/billing-settings', 'payment-plans', 'billing/tuition-increases', 'billing/vacation-holds', 'refunds', 'admin/account-ledgers', 'admin/fee-plans'], []],
        'autopay'          => [['admin/payment-providers', 'admin/payment-runs'], []],
        'expenses'         => [['admin/expenses', 'admin/expense-invoices', 'admin/suppliers', 'admin/purchase-orders'], []],
        'accounting_sync'  => [['qbo/', 'xero/', 'sage/', 'sage50/', 'admin/qbo-config', 'admin/xero-config', 'admin/sage-config'], []],
        'white_label'      => [['admin/branding'], ['POST', 'PUT', 'PATCH', 'DELETE']],   // reading the brand is for everyone
        'forms'            => [['admin/forms', 'admin/managed-forms', 'doc-workflows', 'provider/document-templates'], []],
        'compliance'       => [['compliance/expiry-calendar', 'director/compliance', 'admin/compliance'], []],
        'cwelcc_reporting' => [['compliance/cwelcc', 'occms/', 'cacfp/', 'exports/cwelcc-monthly', 'workforce-funding/', 'compliance/subsidies'], []],
        'reports'          => [['reports', 'admin/report-schedules', 'director/reports'], []],
        'audit_log'        => [['admin/audit-logs', 'director/audit-log', 'exports/audit-logs'], []],
        'data_retention'   => [['admin/compliance-settings', 'compliance/retention'], []],
        'waitlist'         => [['agency/waitlist', 'director/waitlist'], []],
        'sales_crm'        => [['engagement/reenrollment'], []],
        'tours'            => [['admin/tours'], []],
        'marketing'        => [['marketing/campaigns', 'marketing/drip', 'marketing/assets', 'marketing/images', 'marketing/senders'], []],
        'analytics'        => [['analytics/trends', 'ai/enrollment-forecast', 'ai/attendance-anomalies', 'engagement/scores'], []],
        // + observation write-up help and AI drafting (2026-10-28, the AI Bundle).
        // Not 'ai/' as a whole: ai/enrollment-forecast and ai/attendance-anomalies are Analytics, and not AI.
        'ai_tools'         => [['ai/churn-risk', 'ai/doc-extract', 'ai/weekly-recap', 'ai/tag-observation', 'ai/translate-message', 'ai/assist', 'report-cards', 'parent/report-cards', 'director/digest-status', 'director/lesson-plans-ai', 'provider/observations/structure'], []],
        'mrr_dashboard'    => [['admin/mrr'], []],
    ];

    /** @var array<int, array<string,bool>> per-request cache */
    private static array $flags = [];

    /** What still answers for an agency with no licence: signing in, the licence itself, help. */
    private const LAPSED_OK = ['auth/', 'admin/licence', 'tickets', 'support', 'notifications', 'diag/', 'help', 'logout', 'me', 'push/', 'i18n'];
    private static array $lapsed = [];

    public function handle(Request $request, Closure $next): Response
    {
        $path = preg_replace('#^api/v1/#', '', ltrim($request->path(), '/'));

        /* NO LICENCE, NO OPERATION (2026-10-25, Anthony: "no agency should have no license to
           operate with kiddietrac" — plan required, lapsed past grace = renewal screen only). */
        $lapsedOk = false;
        foreach (self::LAPSED_OK as $p) { if (str_starts_with($path, $p)) { $lapsedOk = true; break; } }
        if (! $lapsedOk && ($u = Auth::guard('sanctum')->user())) {
            $aid = $this->agencyFor($request, $u);
            if ($aid) {
                if (! isset(self::$lapsed[$aid])) self::$lapsed[$aid] = \App\Support\Licences::lapsed($aid);
                if (self::$lapsed[$aid] && ! DB::table('role_assignments')->where('user_id', $u->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
                    return response()->json(['message' => 'This KiddieTrac account is paused because its licence has ended. An administrator can renew it.', 'licence_lapsed' => true], 402);
                }
            }
        }

        $feature = self::featureFor($path, $request->method());
        if ($feature === null) {
            return $next($request);
        }
        // Sanctum reads the bearer token itself; global middleware runs before the route's auth.
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return $next($request);   // the route's own auth answers 401
        }
        $agencyId = $this->agencyFor($request, $user);
        if (! $agencyId) {
            return $next($request);
        }
        if (! isset(self::$flags[$agencyId])) {
            self::$flags[$agencyId] = self::gated($agencyId)
                ? \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($agencyId)
                : [];
        }
        $flags = self::$flags[$agencyId];
        if (! array_key_exists($feature, $flags) || $flags[$feature]) {
            return $next($request);
        }
        // KiddieTrac's own tools (MRR) are never held back from a superadmin by the agency in view (2026-10-25).
        if (in_array($feature, \App\Http\Controllers\Api\FeatureFlagController::PLATFORM_WIDE, true)
            && DB::table('role_assignments')->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            return $next($request);
        }
        return response()->json([
            'message' => 'This feature is not included in your agency\'s plan.',
            'feature' => $feature,
            'contact' => 'Contact KiddieTrac to add it.',
        ], 403);
    }

    /** Would the API let this agency use this feature? (the same gate handle() applies; for counts shown outside the route) */
    public static function allows(int $agencyId, string $feature): bool
    {
        if (! self::gated($agencyId)) return true;
        $flags = \App\Http\Controllers\Api\FeatureFlagController::effectiveFor($agencyId);
        return ! array_key_exists($feature, $flags) || (bool) $flags[$feature];
    }

    /** Which sold feature owns this path, if any. */
    public static function featureFor(string $path, string $method = 'GET'): ?string
    {
        foreach (self::MAP as $key => [$prefixes, $methods]) {
            if ($methods && ! in_array(strtoupper($method), $methods, true)) continue;
            foreach ($prefixes as $p) {
                if ($path === rtrim($p, '/') || str_starts_with($path, rtrim($p, '/') . '/') || (str_ends_with($p, '/') && str_starts_with($path, $p))) {
                    return $key;
                }
            }
        }
        return null;
    }

    /** Only licence-managed agencies, or ones with something switched off, can have a feature off. */
    private static function gated(int $agencyId): bool
    {
        if (\App\Support\Licences::managed($agencyId)) return true;
        $raw = DB::table('agencies')->where('id', $agencyId)->value('feature_flags');
        $stored = json_decode((string) $raw, true) ?: [];
        foreach ($stored as $v) { if (! $v) return true; }
        return false;
    }

    private function agencyFor(Request $request, $user): ?int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header) {
            $ok = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                ->where(function ($q) use ($header) { $q->where('agency_id', $header)->orWhere('role', 'platform_admin'); })->exists();
            if ($ok) return $header;
        }
        $direct = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->whereNotNull('agency_id')->value('agency_id');
        if ($direct) return (int) $direct;
        $viaCentre = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $user->id)->where('ra.active', true)->value('c.agency_id');
        return $viaCentre ? (int) $viaCentre : null;
    }
}
