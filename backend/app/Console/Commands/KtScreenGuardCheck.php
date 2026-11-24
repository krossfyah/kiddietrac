<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Each role sees its own screens and nothing else (2026-11-06 role sweep).
 *
 * Anthony: "add guards to ensure roles and responsibilities see their relevant settings, views etc - full sweep".
 * The sweep found GET /admin/biometric-report with no role check (any parent could read the agency's biometric
 * list), parents able to open the staff Time off form, educators able to open refunds / billing schedule / drip
 * campaigns (whose APIs refuse them), and four different home-tile badges. This fails, nightly, on:
 *
 *   1. an /admin/ or /platform/ API route with no role middleware that is not in ADMIN_ROUTES_CHECKED_INSIDE
 *      (each of those checks the caller itself - say how when adding one). The screens' own "am I a platform
 *      admin" flags live in browser storage, which the person can edit, so the server is the only real guard;
 *   2. a screen registered for a role in DENY (js registerScreen 'role:hash', literal or via a role-list forEach);
 *   3. a home-tile count badge drawn outside KT.tileBadge (kt-badges.js).
 *
 * An alert to the platform admins on a failure; --no-alert for trials, --root to scan another parent-portal copy.
 */
class KtScreenGuardCheck extends Command
{
    protected $signature = 'kt:screen-guard-check {--root= : parent-portal folder to scan (default: the live one)} {--quiet-ok} {--no-alert : report only}';

    protected $description = 'Fail on an unguarded /admin/ route, a staff screen registered for a parent (or an admin one for an educator), or a hand-rolled home-tile badge.';

    /** Controller@method behind an /admin/ route with no role middleware => how it checks the caller itself. */
    public const ADMIN_ROUTES_CHECKED_INSIDE = [
        'FeatureFlagController@show' => 'agency admin / platform check in controller',
        'FeatureFlagController@update' => 'agency admin / platform check in controller',
        'FeatureFlagController@invoiceNumberPreview' => 'agency admin / platform check in controller',
        'FeatureFlagController@catalog' => 'static catalogue, no agency data',
        'BiometricController@report' => 'AgencyRole::names must include agency_admin/platform_admin (2026-11-06)',
        'CareScheduleController@show' => 'assertChild',
        'CareScheduleController@update' => 'assertChild',
        'CareScheduleController@notice' => 'assertChild',
        'ContentReportController@adminIndex' => 'adminAgency',
        'ContentReportController@adminUpdate' => 'adminAgency',
        'CurrencyController@showCountry' => 'the active agency\'s own country',
        'CurrencyController@show' => 'the active agency\'s own currency',
        'CurrencyController@showJurisdiction' => 'the active agency\'s own jurisdiction',
        'FileRequestController@index' => 'staff check in controller',
        'FileRequestController@store' => 'staff check in controller',
        'FileRequestController@show' => 'staff check in controller',
        'FileRequestController@destroy' => 'staff check in controller',
        'FileRequestController@download' => 'staff check in controller',
        'FileRequestController@link' => 'staff check in controller',
        'FileRequestController@remind' => 'staff check in controller',
        'DashboardExtrasController@floorStaff' => 'staff check in controller',
        'DashboardExtrasController@quickNotify' => 'staff check in controller',
        'DashboardExtrasController@upcomingBirthdays' => 'staff check in controller',
        'EducatorSelfController@formsTodayRollup' => 'staff check in controller',
        'ManagedFormController@index' => 'manager check in controller',
        'ManagedFormController@store' => 'manager check in controller',
        'ManagedFormController@bulkAssign' => 'manager check in controller',
        'ManagedFormController@packages' => 'manager check in controller',
        'ManagedFormController@packageDetail' => 'manager check in controller',
        'ManagedFormController@packageDelete' => 'manager check in controller',
        'ManagedFormController@packageRedeliver' => 'manager check in controller',
        'ManagedFormController@packageResend' => 'manager check in controller',
        'ManagedFormController@packageResendOne' => 'manager check in controller',
        'ManagedFormController@shareRecipients' => 'manager check in controller',
        'ManagedFormController@signoffs' => 'manager check in controller',
        'ManagedFormController@syncDocuments' => 'manager check in controller',
        'ManagedFormController@deleteSignoff' => 'manager check in controller',
        'ManagedFormController@countersign' => 'manager check in controller',
        'ManagedFormController@emailSignoff' => 'manager check in controller',
        'ManagedFormController@returnToSigner' => 'manager check in controller',
        'ManagedFormController@share' => 'manager check in controller',
        'ManagedFormController@update' => 'manager check in controller',
        'ManagedFormController@destroy' => 'manager check in controller',
        'ManagedFormController@signoffDetail' => 'manager check in controller',
        'RefController@prefix' => 'agency admin check in controller',
        'RefController@setPrefix' => 'agency admin check in controller',
        'CareController@listTours' => 'staff check in controller',
        'CareController@updateTour' => 'staff check in controller',
        'UserDataMapController@show' => 'admin check in controller',
        'PhoneMenuController@webrtc' => 'answers only for a person a department rings (alert_user_ids)',
        'PhoneMenuController@lastCaller' => 'only the people the calling department rings, or a platform admin (2026-11-06)',
    ];

    /** Screens a role must never have: their APIs refuse that role, or they are the other side's job. */
    public const DENY = [
        'guardian' => ['time-off', 'payroll', 'refunds', 'billing-schedule', 'drip-campaigns', 'background-checks', 'sms', 'agency-billing', 'headcounts', 'time-clock'],
        'educator' => ['refunds', 'billing-schedule', 'drip-campaigns', 'payroll', 'background-checks', 'agency-billing', 'payment-plans'],
        'home_visitor' => ['refunds', 'billing-schedule', 'drip-campaigns', 'payroll', 'background-checks', 'agency-billing'],
    ];

    public function handle(): int
    {
        $bad = [];

        // 1. /admin/ routes
        try {
            $n = 0;
            foreach (app('router')->getRoutes() as $route) {
                $uri = $route->uri();
                if (strpos('/' . $uri . '/', '/admin/') === false && strpos('/' . $uri . '/', '/platform/') === false) {
                    continue;
                }
                $n++;
                $guarded = false;
                foreach ($route->gatherMiddleware() as $m) {
                    $m = is_string($m) ? $m : '';
                    if (str_contains($m, 'EnsureRole') || str_starts_with($m, 'role:')) {
                        $guarded = true;
                        break;
                    }
                }
                $full = (string) $route->getActionName();
                $action = ($p = strrpos($full, '\\')) !== false ? substr($full, $p + 1) : $full;
                if (! $guarded && ! isset(self::ADMIN_ROUTES_CHECKED_INSIDE[$action])) {
                    $bad['/admin/ or /platform/ route with no role middleware (add one, or check inside and list it in ADMIN_ROUTES_CHECKED_INSIDE)'][] = implode('|', $route->methods()) . ' ' . $uri . ' (' . $action . ')';
                }
            }
            if (! $n) {
                $bad['Could not list routes'][] = 'no /admin/ or /platform/ routes found';
            }
        } catch (\Throwable $e) {
            $bad['Could not list routes'][] = $e->getMessage();
        }

        // 2 + 3. the front end
        $root = rtrim($this->option('root') ?: base_path('../parent-portal'), '/');
        foreach (glob($root . '/js/*.js') ?: [] as $f) {
            $rel = 'js/' . basename($f);
            if (preg_match('/\.(bak|orig)|\.min\.js$/', $rel)) {
                continue;
            }
            $src = (string) @file_get_contents($f);
            foreach ($this->registrations($src) as [$role, $hash]) {
                if (in_array($hash, self::DENY[$role] ?? [], true)) {
                    $bad['Screen registered for a role that must not have it'][] = $role . ':' . $hash . ' in ' . $rel;
                }
            }
            if ($rel !== 'js/kt-badges.js') {
                $lines = explode("\n", $src);
                foreach ($lines as $i => $line) {
                    /* A count painted on links found by their address reaches the home tiles too (2026-11-06: News got
                       a pill after its label this way): it must hand tiles to KT.tileBadge, i.e. mention kt-tile. */
                    if (preg_match('/a\[(?:data-route|href)=\\\\?["\']#?[\'"]?\s*\+/', $line)) {
                        $win = implode("\n", array_slice($lines, $i, 20));
                        if (preg_match('/createElement\([\'"]span/', $win) && preg_match('/#DC2626|#EF4444|background:\s*red/i', $win) && ! preg_match('/kt-tile|tileBadge/', $win)) {
                            $bad['Count painted on links by address without KT.tileBadge for home tiles'][] = $rel . ':' . ($i + 1);
                            break;
                        }
                    }
                    if (! preg_match('/kt-tile\b/', $line)) {
                        continue;
                    }
                    $win = implode("\n", array_slice($lines, $i, 16));
                    if (preg_match('/createElement\([\'"]span/', $win) && preg_match('/position:\s*absolute/', $win) && preg_match('/#DC2626|#EF4444|background:\s*red/i', $win)) {
                        $bad['Home-tile badge drawn outside KT.tileBadge (kt-badges.js)'][] = $rel . ':' . ($i + 1);
                        break;
                    }
                }
            }
        }

        foreach ($bad as $what => $items) {
            $this->error($what . ': ' . implode(', ', $items));
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Role screens - ' . count($bad) . ' problem(s)',
                'Each role must see only its own screens: every /admin/ API checks the caller, no staff screen is registered for a parent (or an admin screen for an educator), and every home-tile count is KT.tileBadge.',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Admin routes guarded, role screens clean, one tile badge.');
        }

        return self::SUCCESS;
    }

    /** [role, hash] for every registerScreen in a file: 'role:hash' literals and ['a','b'].forEach(function (r) { ... r + ':hash' ... }). */
    private function registrations(string $src): array
    {
        $out = [];
        if (preg_match_all("/registerScreen\\(\\s*'([a-z_]+):([\\w-]+)'/", $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $out[] = [$x[1], $x[2]];
            }
        }
        if (preg_match_all("/\\[((?:\\s*'[a-z_]+'\\s*,?)+)\\]\\.forEach\\(function\\s*\\((\\w+)\\)\\s*\\{(.*?)\\n\\s*\\}\\);/s", $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                preg_match_all("/'([a-z_]+)'/", $x[1], $roles);
                preg_match_all('/registerScreen\(\s*' . preg_quote($x[2], '/') . "\\s*\\+\\s*':([\\w-]+)'/", $x[3], $hashes);
                foreach ($roles[1] as $role) {
                    foreach ($hashes[1] as $h) {
                        $out[] = [$role, $h];
                    }
                }
            }
        }

        return $out;
    }
}
