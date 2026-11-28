<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CustomRoles;
use App\Support\PermissionMap;
use App\Support\PermissionRoutes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

/**
 * EVERY ROUTE AND SCREEN HAS A PERMISSION (custom roles, 2026-11-28).
 *
 * Custom roles are enforced (App\Support\CustomRoles): a route no map knows is REFUSED to anyone holding a custom role,
 * so a route added without a line here works for built-in roles and fails closed for custom ones. This fails when:
 *   1. an authenticated api/v1 route is in none of PermissionMap::ROUTES, PermissionRoutes::EXTRA / OPEN / NONE;
 *   2. a map names a permission the catalog does not have, or GROUPS does not hold every catalog key exactly once;
 *   3. a staff menu screen (app-v2-shell.js navItemsForRole) is in neither PermissionRoutes::SCREENS nor OPEN_SCREENS;
 *   4. CUSTOM_OFFER names a key outside the catalog or a base that is not a custom-role base.
 * --suggest prints a line for each unmapped route (the key of the nearest mapped route of the same kind, to review).
 * Daily 05:50 Toronto, alerts the platform admins; --no-alert for trials.
 */
final class KtPermissionMapCheck extends Command
{
    protected $signature = 'kt:permission-map-check {--quiet-ok} {--no-alert : report only} {--suggest : print map lines for unmapped routes}';

    protected $description = 'Every API route and staff screen carries a permission, so custom roles fail closed only on purpose';

    public function handle(): int
    {
        $bad = [];
        $cat = PermissionMap::CATALOG;
        $known = [];
        $unmapped = [];
        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1')) {
                continue;
            }
            $authed = false;
            foreach ($route->gatherMiddleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'auth:')) { $authed = true; break; }
            }
            foreach ($route->methods() as $method) {
                if ($method === 'HEAD') continue;
                $k = $method . ' ' . $uri;
                $known[$k] = true;
                if ($authed && CustomRoles::keyFor($method, $uri) === null) {
                    $unmapped[] = $k;   // public doors are never refused (EnforceCustomRole), so only signed-in routes count
                }
            }
        }
        if ($unmapped) {
            sort($unmapped);
            $bad['Routes with no permission (refused to custom roles until mapped)'] = $unmapped;
        }

        $badKeys = [];
        foreach ([PermissionMap::ROUTES, PermissionRoutes::EXTRA, PermissionRoutes::SCREENS] as $map) {
            foreach ($map as $what => $key) {
                if (! isset($cat[$key])) $badKeys[] = $what . ' => ' . $key;
            }
        }
        foreach (PermissionMap::CUSTOM_OFFER as $base => $keys) {
            if (! in_array($base, CustomRoles::BASES, true)) $badKeys[] = 'CUSTOM_OFFER base ' . $base;
            foreach ($keys as $k) {
                if (! isset($cat[$k])) $badKeys[] = 'CUSTOM_OFFER ' . $base . ' => ' . $k;
            }
        }
        if ($badKeys) {
            $bad['Permissions not in the catalog'] = $badKeys;
        }

        $grouped = [];
        foreach (PermissionMap::GROUPS as $keys) {
            foreach ($keys as $k) $grouped[] = $k;
        }
        $dupes = array_keys(array_filter(array_count_values($grouped), fn ($n) => $n > 1));
        $missing = array_values(array_diff(array_keys($cat), $grouped));
        $extra = array_values(array_diff($grouped, array_keys($cat)));
        if ($dupes || $missing || $extra) {
            $bad['Roles screen groups'] = array_merge(
                array_map(fn ($k) => 'in two groups: ' . $k, $dupes),
                array_map(fn ($k) => 'in no group: ' . $k, $missing),
                array_map(fn ($k) => 'not in the catalog: ' . $k, $extra)
            );
        }

        $shell = base_path('../parent-portal/js/app-v2-shell.js');
        if (is_file($shell)) {
            $src = (string) file_get_contents($shell);
            $screens = [];
            // Staff menus only: from navItemsForRole's first branch up to the parent (default) menu.
            $a = strpos($src, 'function navItemsForRole(role)');
            $b = $a !== false ? strpos($src, "if (familyPending())", $a) : false;
            if ($a !== false && $b !== false) {
                $block = substr($src, $a, $b - $a);
                // sales_rep / reseller_admin menus are platform-side: never a custom role.
                $block = preg_replace("/if \\(role === '(sales_rep|reseller_admin)'\\) \\{.*?\\n    \\}\\n/s", '', $block);
                preg_match_all("/hash:\\s*'([a-z0-9-]+)'/", $block, $m);
                $open = array_flip(PermissionRoutes::OPEN_SCREENS);
                foreach (array_unique($m[1]) as $h) {
                    if (! isset(PermissionRoutes::SCREENS[$h]) && ! isset($open[$h])) $screens[] = $h;
                }
            } else {
                $screens[] = 'could not find navItemsForRole in app-v2-shell.js';
            }
            if ($screens) {
                sort($screens);
                $bad['Menu screens with no permission (shown to every custom role)'] = $screens;
            }
        }

        $stale = array_keys(array_diff_key(PermissionRoutes::EXTRA + PermissionRoutes::OPEN + PermissionMap::ROUTES, $known));
        if ($stale && ! $this->option('quiet-ok')) {
            $this->line(count($stale) . ' map line(s) name a route that no longer exists (harmless): ' . implode(', ', array_slice($stale, 0, 20)));
        }

        if ($this->option('suggest') && $unmapped) {
            $this->line('// Suggested lines for PermissionRoutes::EXTRA (review each - the nearest mapped route of the same method):');
            $all = PermissionMap::ROUTES + PermissionRoutes::EXTRA;
            foreach ($unmapped as $k) {
                [$method, $uri] = explode(' ', $k, 2);
                $best = null; $bestLen = -1;
                foreach ($all as $mk => $key) {
                    [$mm, $mu] = explode(' ', $mk, 2);
                    if (($mm === 'GET') !== ($method === 'GET')) continue;
                    $len = strlen($this->commonPrefix($uri, $mu));
                    if ($len > $bestLen) { $bestLen = $len; $best = $key; }
                }
                $this->line("        '" . $method . ' ' . $uri . "' => '" . ($best ?? '?') . "',");
            }
        }

        foreach ($bad as $what => $items) {
            $this->error($what . ' (' . count($items) . '): ' . implode(', ', array_slice($items, 0, 60)) . (count($items) > 60 ? ' ...' : ''));
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Permission map - ' . count($bad) . ' problem(s)',
                'Custom roles are enforced: anyone holding one is refused a route no permission map knows (fail closed). Add each new route to App\\Support\\PermissionRoutes (php8.3 artisan kt:permission-map-check --suggest) and each new menu screen to its SCREENS.',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $this->info('Every API route and staff menu screen has a permission (' . count($known) . ' routes).');
        }

        return self::SUCCESS;
    }

    private function commonPrefix(string $a, string $b): string
    {
        $n = min(strlen($a), strlen($b));
        $i = 0;
        while ($i < $n && $a[$i] === $b[$i]) $i++;
        return substr($a, 0, $i);
    }
}
