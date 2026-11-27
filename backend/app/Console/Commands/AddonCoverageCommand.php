<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\FeatureFlagController as FF;
use App\Http\Middleware\EnforcePlanFeatures as EPF;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * NOTHING NEW SHIPS UN-GATED (2026-11-26, Anthony: "when agency is setup and addon's are added does this reflect the
 * addon's and functionality per agency setup correctly = full sweep here on current and future wiring with existing
 * and new addon's").
 *
 * One source of truth already decides what an agency has: FeatureFlagController::FEATURES (each feature's plan level
 * and screens) + the agency's licences (plan + add-ons, App\Support\Licences) + explicit On/Off. Three things read it:
 * the API (EnforcePlanFeatures::MAP), the menu (/auth/me hidden_screens, from FEATURES hashes - the portal keeps no
 * copy) and jobs (App\Support\Features / AiBundle). This check fails when one of them drifts:
 *
 *   1. ROUTES  - every api/v1 route group is owned by a feature (MAP) or is a known core group (the baseline file).
 *                A NEW group fails until someone decides: add it to a feature's MAP, or record it as core.
 *   2. SCREENS - every FEATURES hash is a real portal screen (a typo hides nothing), and every portal screen is owned
 *                by a feature or is a known core screen (baseline).
 *   3. JOBS    - every scheduled command is classified; one mapped to a feature (JOBS) must ask Features/AiBundle.
 *   4. ADD-ONS - every add-on product's feature keys exist (or are a known non-portal key), and every add-on-only
 *                feature can actually be bought.
 *   5. MAPS    - MAP and global search name only real feature keys.
 *   6. LICENCE - every agency runs on a licence (2026-10-25 rule); a demo agency without one is a warning.
 *
 * The baseline (resources/addon-coverage-baseline.txt) is the core list as it stood on 2026-11-26, reviewed then.
 * Regenerate it ONLY after classifying what is new: php artisan kt:addon-coverage --write-baseline.
 */
final class AddonCoverageCommand extends Command
{
    protected $signature = 'kt:addon-coverage {--quiet-ok : print nothing when everything is classified} {--write-baseline : record what is unclassified now as core}';

    protected $description = 'Fail when a route, screen, scheduled job or add-on is not wired to the plan/add-on map';

    /**
     * Scheduled jobs that do a sold feature's work for an agency => [feature, file that must hold the gate].
     * A job not listed here is core (in the baseline) or new (fails).
     */
    public const JOBS = [
        'immunization:reminders' => ['immunizations', 'app/Console/Commands/ImmunizationRemindersCommand.php'],
        'drip:dispatch' => ['marketing', 'app/Console/Commands/DripDispatchCommand.php'],
        'invoices:issue-scheduled' => ['billing', 'app/Console/Commands/IssueScheduledInvoices.php'],
        'billing:reminders' => ['billing', 'app/Console/Commands/BillingReminders.php'],
        'invoices:apply-late-fees' => ['billing', 'app/Console/Commands/ApplyLateFeesCommand.php'],
        'invoices:autopay-charge' => ['autopay', 'app/Console/Commands/AutopayChargeCommand.php'],
        'qbo:sync' => ['accounting_sync', 'app/Console/Commands/QboSyncCommand.php'],
        'sage:sync' => ['accounting_sync', 'app/Console/Commands/SageSyncCommand.php'],
        'xero:sync' => ['accounting_sync', 'app/Console/Commands/XeroSyncCommand.php'],
        'kiddietrac:send-scheduled-reports' => ['reports', 'app/Console/Commands/SendScheduledReports.php'],
        'schedule:autofill' => ['staff_scheduling', 'app/Console/Commands/ScheduleAutofillCommand.php'],
        'occms:roa-remind' => ['cwelcc_reporting', 'app/Console/Commands/OccmsRoaRemindCommand.php'],
        'portfolio:year-end' => ['observations', 'app/Console/Commands/YearEndPortfolioCommand.php'],
        'hr:leave-accrue' => ['hr', 'app/Console/Commands/HrLeaveAccrueCommand.php'],
        'hr:talent-remind' => ['hr', 'app/Console/Commands/HrTalentRemindCommand.php'],
        'hr:role-courses' => ['hr', 'app/Console/Commands/HrRoleCoursesCommand.php'],
        'hr:declined-purge' => ['hr', 'app/Console/Commands/HrDeclinedPurgeCommand.php'],
        'hr:overtime-warn' => ['hr', 'app/Console/Commands/HrOvertimeWarnCommand.php'],
        'hr:shift-notices' => ['hr', 'app/Console/Commands/HrShiftNoticesCommand.php'],
        'timeoff:remind-approvers' => ['timesheets', 'app/Console/Commands/TimeOffRemindApprovers.php'],
        'forms:remind-countersign' => ['forms', 'app/Console/Commands/CountersignRemindCommand.php'],
        'kt:payslip-notices' => ['payroll', 'app/Support/PayslipNotices.php'],
        'kiddietrac:safe-arrival' => ['sms', 'app/Console/Commands/SafeArrivalCommand.php'],
        'kiddietrac:safe-arrival-next' => ['sms', 'app/Console/Commands/SafeArrivalNextCallCommand.php'],
        'kiddietrac:report-card-reminders' => ['ai_tools', 'app/Console/Commands/ReportCardRemindersCommand.php'],
        // AI inside shared jobs: the job runs for everyone, its AI part asks AiBundle.
        'kiddietrac:parent-summary' => ['ai_tools', 'app/Console/Commands/ParentDailySummaryCommand.php'],
        'kiddietrac:educator-summary' => ['ai_tools', 'app/Support/AiSummaries.php'],
        'kiddietrac:admin-digest' => ['ai_tools', 'app/Support/AiSummaries.php'],
    ];

    /** Add-on feature keys that are not portal features (they switch a relationship, not a screen). */
    public const NON_FLAG_KEYS = [
        'reseller' => 'Reseller (Agency Bundle): the reseller relationship (resellers table, ResellerAccess), not a portal feature',
    ];

    private const BASELINE = 'resources/addon-coverage-baseline.txt';

    private array $fail = [];
    private array $warn = [];
    private array $unclassified = [];

    public function handle(): int
    {
        $baseline = $this->baseline();
        $features = FF::FEATURES;

        // 1. ROUTES -------------------------------------------------------------------------------------------
        $routeGroups = [];
        foreach (Route::getRoutes() as $rt) {
            $uri = $rt->uri();
            if (! str_starts_with($uri, 'api/v1/')) continue;
            $p = substr($uri, 7);
            foreach ($rt->methods() as $m) {
                if ($m === 'HEAD') continue;
                if (EPF::featureFor($p, $m) !== null) continue;
                $routeGroups[self::group($p)] = true;
            }
        }
        foreach (array_keys($routeGroups) as $g) {
            if (! isset($baseline['route'][$g])) $this->unclass('route', $g, 'API route group owned by no feature: add its prefix to EnforcePlanFeatures::MAP, or record it as core');
        }

        // 2. SCREENS ------------------------------------------------------------------------------------------
        $screens = $this->portalScreens();
        $owned = [];
        foreach ($features as $key => $meta) {
            foreach ($meta['hashes'] ?? [] as $h) {
                $owned[$h] = $key;
                if ($screens && ! isset($screens[$h])) $this->fail[] = "screen '$h' in feature '$key' is not a portal screen (typo or removed screen: switching the feature off hides nothing)";
            }
        }
        foreach (array_keys($screens) as $h) {
            if (isset($owned[$h])) continue;
            if (! isset($baseline['screen'][$h])) $this->unclass('screen', $h, 'portal screen owned by no feature: add it to a feature\'s hashes in FeatureFlagController::FEATURES, or record it as core');
        }

        // 3. JOBS ---------------------------------------------------------------------------------------------
        foreach ($this->scheduledCommands() as $cmd) {
            if (isset(self::JOBS[$cmd])) {
                [$fk, $file] = self::JOBS[$cmd];
                if (! isset($features[$fk])) { $this->fail[] = "job '$cmd' is mapped to unknown feature '$fk'"; continue; }
                $src = @file_get_contents(base_path($file)) ?: '';
                if (! preg_match('/Features::(skips|on|forCentre)\(|EnforcePlanFeatures::allows\(|AiBundle::(on|forChild|forCentre)\(/', $src)) {
                    $this->fail[] = "job '$cmd' does '$fk' work but $file never asks Features/AiBundle (it would run for agencies without it)";
                }
                continue;
            }
            if (! isset($baseline['job'][$cmd])) $this->unclass('job', $cmd, 'scheduled job not classified: map it in AddonCoverageCommand::JOBS (and gate it with App\\Support\\Features) or record it as core');
        }

        // 4. ADD-ONS ------------------------------------------------------------------------------------------
        $sold = [];
        foreach (DB::table('sales_products')->where('kind', 'addon')->get(['name', 'part_number', 'feature_keys', 'active']) as $p) {
            foreach ((array) (json_decode((string) $p->feature_keys, true) ?: []) as $k) {
                if (! isset($features[$k]) && ! isset(self::NON_FLAG_KEYS[$k])) $this->fail[] = "add-on '{$p->name}' ({$p->part_number}) switches on '$k', which is no feature: buying it changes nothing";
                if ($p->active) $sold[$k] = true;
            }
        }
        foreach ($features as $key => $meta) {
            if (($meta['plan_min'] ?? '') === 'addon' && ! isset($sold[$key])) $this->fail[] = "feature '$key' is add-on only but no active add-on product sells it";
        }

        // 5. MAPS ---------------------------------------------------------------------------------------------
        foreach (array_keys(EPF::MAP) as $k) {
            if (! isset($features[$k])) $this->fail[] = "EnforcePlanFeatures::MAP names unknown feature '$k'";
        }
        if (class_exists(\App\Support\SearchRegistry::class) && defined(\App\Support\SearchRegistry::class . '::SOURCES')) {
            foreach (\App\Support\SearchRegistry::SOURCES as $src => $def) {
                $k = $def['feature'] ?? null;
                if ($k && ! isset($features[$k])) $this->fail[] = "global search group '$src' names unknown feature '$k'";
            }
        }

        // 7. CENTRE CAP ---------------------------------------------------------------------------------------
        /* Every path that opens a centre asks App\Support\CentreCap (2026-11-26, Anthony: "Apply to every centre-create
           path ... reader and guard together"). The seeders that build a brand-new demo agency are exempt: a new agency
           holds no licence, so it has no cap. */
        $seedOk = ['SeedDemo.php', 'SeedOhioDemo.php', 'SeedBrightwoodDemo.php', 'SeedStarlightDemo.php'];
        foreach (\Illuminate\Support\Facades\File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php' || in_array($file->getFilename(), $seedOk, true)) continue;
            $src = (string) @file_get_contents($file->getPathname());
            if (preg_match('/table\([\'"]centres[\'"]\)->insert(GetId)?\(|Centre::(create|firstOrCreate|updateOrCreate)\(/', $src)
                && ! str_contains($src, 'CentreCap::')) {
                $this->fail[] = 'centre created in ' . str_replace(base_path() . '/', '', $file->getPathname()) . ' without asking App\\Support\\CentreCap (the licence centre cap)';
            }
        }

        // 6. LICENCE ------------------------------------------------------------------------------------------
        foreach (DB::table('agencies')->whereNull('deleted_at')->get(['id', 'name', 'settings']) as $a) {
            if (\App\Support\Licences::managed((int) $a->id)) continue;
            /* Test agencies are full showcases on purpose (2026-11-26, Anthony): exempt. */
            if (\App\Support\TestAgency::isAgency((int) $a->id) || ! empty((json_decode((string) $a->settings, true) ?: [])['demo'])) continue;
            $this->fail[] = "agency {$a->id} ({$a->name}) has no licence, so every feature and add-on is on";
        }

        if ($this->option('write-baseline')) {
            return $this->writeBaseline($baseline);
        }

        foreach ($this->unclassified as [$kind, $id, $why]) $this->fail[] = "new $kind '$id': $why";
        if (! $this->fail) {
            if (! $this->option('quiet-ok')) {
                foreach ($this->warn as $w) $this->line('  warn ' . $w);
                $this->info(sprintf('Add-on coverage OK: %d route groups, %d screens, %d scheduled jobs checked.', count($routeGroups), count($screens), count($this->scheduledCommands())));
            }
            return self::SUCCESS;
        }
        foreach ($this->fail as $f) $this->error('  ' . $f);
        foreach ($this->warn as $w) $this->line('  warn ' . $w);
        $this->line(count($this->fail) . ' failure(s).');

        return self::FAILURE;
    }

    /** The route group: first path segment, plus the second unless it is a parameter. */
    public static function group(string $path): string
    {
        $s = explode('/', $path);
        return $s[0] . (isset($s[1]) && $s[1] !== '' && ! str_starts_with($s[1], '{') ? '/' . $s[1] : '');
    }

    private function unclass(string $kind, string $id, string $why): void
    {
        $this->unclassified[] = [$kind, $id, $why];
    }

    /** @return array<string, array<string,bool>> kind => id => true */
    private function baseline(): array
    {
        $out = ['route' => [], 'screen' => [], 'job' => []];
        $f = base_path(self::BASELINE);
        if (! is_file($f)) return $out;
        foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') continue;
            [$kind, $id] = array_pad(preg_split('/\s+/', $line, 2), 2, '');
            if (isset($out[$kind]) && $id !== '') $out[$kind][$id] = true;
        }
        return $out;
    }

    private function writeBaseline(array $baseline): int
    {
        foreach ($this->unclassified as [$kind, $id]) $baseline[$kind][$id] = true;
        $lines = ["# kt:addon-coverage baseline: route groups, portal screens and scheduled jobs that are CORE (every plan, no add-on).",
            "# Reviewed 2026-11-26. Add a line only after deciding the thing is core; a sold feature goes in its map instead.", ''];
        foreach (['route', 'screen', 'job'] as $kind) {
            $ids = array_keys($baseline[$kind]);
            sort($ids);
            foreach ($ids as $id) $lines[] = $kind . ' ' . $id;
            $lines[] = '';
        }
        file_put_contents(base_path(self::BASELINE), implode("\n", $lines));
        $this->info('Baseline written: ' . count($this->unclassified) . ' new line(s).');
        foreach ($this->fail as $f) $this->error('  ' . $f);
        return $this->fail ? self::FAILURE : self::SUCCESS;
    }

    /** Every screen hash the portal can show: nav items, lazy screens and registered screens. */
    private function portalScreens(): array
    {
        $root = dirname(base_path()) . '/parent-portal';
        $out = [];
        foreach (['js/app-v2-shell.js', 'js/agency-switcher.js', 'js/kt-mobilenav.js', 'js/screen-role-home.js'] as $f) {
            $src = @file_get_contents($root . '/' . $f) ?: '';
            if (preg_match_all('/\bhash:\s*[\'"]([a-z0-9-]+)[\'"]/', $src, $m)) foreach ($m[1] as $h) $out[$h] = true;
        }
        $dash = @file_get_contents($root . '/dashboard.src.html') ?: '';
        if (preg_match('/window\.__KT_LAZY=(\{.*?\});/s', $dash, $m)) {
            foreach ((array) json_decode($m[1], true) as $e) {
                foreach ($e['k'] ?? [] as $rk) { $h = explode(':', $rk, 2)[1] ?? ''; if ($h !== '') $out[$h] = true; }
                foreach ($e['h'] ?? [] as $h) $out[$h] = true;
            }
        }
        foreach (glob($root . '/js/*.js') ?: [] as $f) {
            $src = @file_get_contents($f) ?: '';
            if (! str_contains($src, 'registerScreen(')) continue;
            if (preg_match_all('/registerScreen\(([^;]{0,160})/', $src, $m)) {
                foreach ($m[1] as $args) {
                    if (preg_match_all('/[\'"](?:[a-z_]+)?:([a-z0-9-]+)[\'"]/', $args, $mm)) foreach ($mm[1] as $h) $out[$h] = true;
                }
            }
        }
        return $out;
    }

    /** Scheduled command names (first word): console.php schedule lines and the kt:minute groups. */
    private function scheduledCommands(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;
        $out = [];
        $src = @file_get_contents(base_path('routes/console.php')) ?: '';
        if (preg_match_all('/Schedule::command\(\s*[\'"]([^\'" ]+)/', $src, $m)) foreach ($m[1] as $c) $out[$c] = true;
        if (class_exists(MinuteBatchCommand::class)) {
            foreach (MinuteBatchCommand::GROUPS as $jobs) foreach (array_keys($jobs) as $c) $out[explode(' ', $c)[0]] = true;
        }
        unset($out['kt:minute']);
        ksort($out);
        return $cache = array_keys($out);
    }
}
