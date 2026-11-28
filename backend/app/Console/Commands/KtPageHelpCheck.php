<?php

namespace App\Console\Commands;

use App\Services\HelpService;
use Illuminate\Console\Command;

/**
 * Every screen's "?" opens a real help article (2026-11-27).
 *
 * Anthony: "help for this page should be added throughout the portal" and "make the help detailed with step by
 * step". kt-page-help.js draws ONE "?" on every screen's banner and opens what HelpService::pageMapForRole says,
 * which comes from each article's `screens:` front matter. This reads the SAME function and fails when:
 *
 *   1. a nav hash of any role (app-v2-shell.js navItemsForRole, every branch; the platform block in
 *      agency-switcher.js) or an HR tab (kt-hr-sections.js SECTIONS) has no article that role may read;
 *   2. an article a screen opens has no French, Spanish or Hindi translation (slug.fr/es/hi.md, non-empty).
 *
 * It WARNS (fails only with --strict) on screens that open a "~" fallback article (no guide of its own yet), on
 * thin guides (under 300 words, or fewer than 3 numbered steps), and on `screens:` keys no screen uses.
 * --list prints every role / screen / article as tab-separated rows (the writers' inventory).
 * Alerts the platform admins on a failure unless --no-alert.
 */
class KtPageHelpCheck extends Command
{
    protected $signature = 'kt:page-help-check {--root= : parent-portal folder (default: the live one)} {--quiet-ok} {--no-alert : report only} {--strict : also fail on fallback and thin guides} {--list : print role, screen and article rows}';

    protected $description = 'Fail when a screen in any role\'s menu has no help article for that role, or its article is missing a translation.';

    /** The role whose nav is read => the role whose help library is served (HelpController::resolveRole). */
    public const ROLES = [
        'agency_admin' => 'agency_admin',
        'centre_director' => 'centre_director',
        'educator' => 'educator',
        'home_visitor' => 'home_visitor',
        'guardian' => 'guardian',
        'sales_rep' => 'sales_rep',
        'reseller_admin' => 'reseller_admin',
        'auditor' => 'auditor',
        // Its own help role since 2026-11-27 (superset of agency_admin plus the platform-only guides).
        'platform_admin' => 'platform_admin',
    ];

    /** Screens that are not in a menu but are opened often enough to need their own "?" check. */
    public const EXTRA = [
        'agency_admin' => [],
        'centre_director' => [],
        // The phone bottom bar's Message Centre (kt-mobilenav.js) - not in the home visitor's sidebar menu, so the
        // banner on it had no "?" and nothing here noticed (2026-11-27).
        'home_visitor' => ['chat'],
    ];

    private const LOCALES = ['fr', 'es', 'hi'];

    public function handle(HelpService $help): int
    {
        $root = rtrim($this->option('root') ?: base_path('../parent-portal'), '/');
        $bad = [];
        $warn = [];

        $shell = (string) @file_get_contents($root . '/js/app-v2-shell.js');
        $nav = self::navHashes($shell);
        if (count($nav) < 6) {
            $bad['Could not read the menus'][] = 'navItemsForRole in js/app-v2-shell.js gave ' . count($nav) . ' role(s)';
        }
        $platform = self::platformHashes((string) @file_get_contents($root . '/js/agency-switcher.js'));
        [$hrAdmin, $hrDirector, $alt] = self::hrKeys((string) @file_get_contents($root . '/js/kt-hr-sections.js'));
        if (! $hrAdmin) {
            $bad['Could not read the HR tabs'][] = 'SECTIONS in js/kt-hr-sections.js';
        }

        $rows = [];
        $used = [];
        $named = [];
        foreach (self::ROLES as $navRole => $libRole) {
            // A platform admin's menu = the agency admin's + the blocks drawn only for them + the Platform section.
            $keys = $navRole === 'platform_admin'
                ? array_merge($nav['agency_admin'] ?? [], $nav['platform_admin'] ?? [])
                : ($nav[$navRole] ?? []);
            if ($navRole === 'platform_admin') {
                $keys = array_merge($keys, $platform);
            }
            if (in_array($navRole, ['agency_admin', 'platform_admin'], true)) {
                $keys = array_merge($keys, $hrAdmin);
            }
            if ($navRole === 'centre_director') {
                $keys = array_merge($keys, $hrDirector);
            }
            $keys = array_values(array_unique(array_merge($keys, self::EXTRA[$navRole] ?? [])));
            $map = $help->pageMapForRole($libRole);
            foreach ($map as $k => $_) {
                $named[$k] = true;
            }
            foreach ($keys as $key) {
                [$via, $list] = self::resolve($map, $key, $alt);
                if (! $list) {
                    $bad['Screen with no help article for this role (add `screens: ' . $key . '` to a guide this role reads)'][] = $navRole . ' #' . $key;
                    $rows[] = [$navRole, $key, '', '', 'NONE', 0, 0];
                    continue;
                }
                $used[$via] = true;
                foreach ($list as $i => $a) {
                    $base = resource_path('help/' . $a['audience'] . '/' . $a['slug']);
                    foreach (self::LOCALES as $l) {
                        $t = is_file($base . '.' . $l . '.md') ? (string) file_get_contents($base . '.' . $l . '.md') : '';
                        if (trim(preg_replace('/^---\n.*?\n---\n/s', '', str_replace("\r\n", "\n", $t))) === '') {
                            $bad['Help article without a translation'][$a['audience'] . '/' . $a['slug'] . '.' . $l . '.md'] = $a['audience'] . '/' . $a['slug'] . '.' . $l . '.md';
                        }
                    }
                }
                $first = $list[0];
                [$words, $steps] = self::size(resource_path('help/' . $first['audience'] . '/' . $first['slug'] . '.md'));
                $status = $first['fallback'] ? 'missing' : (($words < 300 || $steps < 3) ? 'thin' : 'adequate');
                if ($status === 'missing') {
                    $warn['Screen opens a fallback guide (write its own)'][] = $navRole . ' #' . $key . ' -> ' . $first['slug'];
                } elseif ($status === 'thin') {
                    $warn['Thin guide (under 300 words or fewer than 3 steps)'][$first['audience'] . '/' . $first['slug']] = $first['audience'] . '/' . $first['slug'];
                }
                $rows[] = [$navRole, $key, $via, $first['audience'] . '/' . $first['slug'], $status, $words, $steps];
            }
        }
        $stale = array_diff(array_keys($named), array_keys($used));
        if ($stale) {
            $warn['screens: key no menu screen uses (renamed screen?)'] = array_values($stale);
        }

        if ($this->option('list')) {
            foreach ($rows as $r) {
                $this->line(implode("\t", $r));
            }
        }
        foreach ($bad as $what => $items) {
            $this->error($what . ': ' . implode(', ', array_values($items)));
        }
        if ($this->option('strict')) {
            foreach ($warn as $what => $items) {
                $bad[$what] = $items;
            }
        }
        if (! $this->option('quiet-ok') || $bad) {
            foreach ($warn as $what => $items) {
                $this->warn($what . ' (' . count($items) . ')' . ($this->option('quiet-ok') ? '' : ': ' . implode(', ', array_slice(array_values($items), 0, 40)) . (count($items) > 40 ? ' ...' : '')));
            }
        }
        if ($bad && ! $this->option('no-alert')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Page help - ' . count($bad) . ' problem(s)',
                'Every screen\'s "?" must open a help guide that role can read, in English, French, Spanish and Hindi. Add `screens:` to the guide\'s front matter (backend/resources/help).',
                $bad
            );
        }
        if ($bad) {
            return self::FAILURE;
        }
        if (! $this->option('quiet-ok')) {
            $n = count($rows);
            $c = array_count_values(array_column($rows, 4));
            $this->info("Page help: {$n} role screens mapped - " . ($c['adequate'] ?? 0) . ' adequate, ' . ($c['thin'] ?? 0) . ' thin, ' . ($c['missing'] ?? 0) . ' on a fallback guide; translations complete.');
        }

        return self::SUCCESS;
    }

    /** [key used, articles] — the tab key, then the bare hash, then the part before "/"; an HR-only twin reads as its entry. Mirrors kt-page-help.js keysFor(). */
    public static function resolve(array $map, string $key, array $alt): array
    {
        $base = explode('?', $key)[0];
        $tab = preg_match('/(?:^|&)tab=([\w-]+)/', explode('?', $key . '?')[1], $m) ? $m[1] : '';
        if (isset($alt[$base])) {
            [$base, $first] = $alt[$base];
            $tab = $tab ?: $first;
        }
        $try = [];
        if ($tab !== '') {
            $try[] = $base . '?tab=' . $tab;
        }
        $try[] = $base;
        if (str_contains($base, '/')) {
            $try[] = explode('/', $base)[0];
        }
        foreach ($try as $k) {
            if (! empty($map[$k])) {
                return [$k, $map[$k]];
            }
        }

        return [$key, null];
    }

    /** role => nav hashes (and HR-only twins), one entry per `if (role === '...')` branch of navItemsForRole; the familyPending and default branches are the guardian's. */
    public static function navHashes(string $shell): array
    {
        $a = strpos($shell, 'function navItemsForRole(role)');
        if ($a === false) {
            return [];
        }
        $b = strpos($shell, 'function footIsSidebarColumn', $a);
        $body = substr($shell, $a, $b === false ? 200000 : $b - $a);
        if (! preg_match_all("/\n    if \\((?:role === '([a-z_]+)'|familyPending\\(\\))\\) \\{/", $body, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
            return [];
        }
        $out = [];
        foreach ($m as $i => $x) {
            $role = ($x[1][0] ?? '') !== '' && ($x[1][1] ?? -1) >= 0 ? $x[1][0] : 'guardian';
            $start = $x[0][1] + strlen($x[0][0]);
            $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($body);
            $branch = substr($body, $start, $end - $start);
            if ($role === 'agency_admin') {
                /* The agency admin branch also draws blocks for OTHER people (2026-11-27): `...(isPlatformAdmin_v22p34 ?
                   [..] : [..])` only for a platform admin and `...(isReseller_v ? [..] : [])` only for a reseller. Their
                   screens belong to those roles, so a platform-only guide (`roles: platform_admin`) is not reported
                   missing for agency admins. The ": [..]" side stays the agency admin's. */
                foreach (['isPlatformAdmin_v22p34' => 'platform_admin', 'isReseller_v' => 'reseller_admin'] as $flag => $who) {
                    while (($p = strpos($branch, '...(' . $flag . ' ? [')) !== false) {
                        $open = $p + strlen('...(' . $flag . ' ? ');
                        $close = self::matchBracket($branch, $open);
                        if ($close === null) {
                            break;
                        }
                        preg_match_all("/\\b(?:hash|alt):\\s*'([^']+)'/", substr($branch, $open, $close - $open + 1), $h);
                        $out[$who] = array_values(array_unique(array_merge($out[$who] ?? [], $h[1])));
                        $branch = substr($branch, 0, $p) . substr($branch, $close + 1);
                    }
                }
            }
            preg_match_all("/\\b(?:hash|alt):\\s*'([^']+)'/", $branch, $h);
            $out[$role] = array_values(array_unique(array_merge($out[$role] ?? [], $h[1])));
        }

        return $out;
    }

    /** Offset of the "]" that closes the "[" at $open (strings and comments are not special here: menus have none). */
    private static function matchBracket(string $s, int $open): ?int
    {
        $depth = 0;
        for ($i = $open, $n = strlen($s); $i < $n; $i++) {
            if ($s[$i] === '[') {
                $depth++;
            } elseif ($s[$i] === ']') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    public static function platformHashes(string $src): array
    {
        preg_match_all('/data-hash="([^"]+)"/', $src, $m);

        return array_values(array_unique($m[1]));
    }

    /** [admin keys, director keys, alt => [hash, first HR tab]] from kt-hr-sections.js SECTIONS, keyed the way its hrefOf() links. */
    public static function hrKeys(string $src): array
    {
        $a = strpos($src, 'var SECTIONS = [');
        $b = $a === false ? false : strpos($src, "];\n  var BY_ID", $a);
        if ($a === false || $b === false) {
            return [[], [], []];
        }
        $admin = [];
        $director = [];
        $alt = [];
        foreach (array_slice(explode("{ id: '", substr($src, $a, $b - $a)), 1) as $sec) {
            if (! preg_match("/hash:\\s*'([^']+)'/", $sec, $hm)) {
                continue;
            }
            $hash = $hm[1];
            $head = explode('tabs:', $sec)[0];
            $secAdmin = (bool) preg_match('/admin:\s*true/', $head);
            preg_match_all("/\\{\\s*k:\\s*'([a-z]+)'([^}]*)\\}/", $sec, $tm, PREG_SET_ORDER);
            $pre = false;
            $first = null;
            foreach ($tm as $t) {
                if (preg_match("/screen:\\s*'/", $t[2])) {
                    $pre = true;
                } elseif ($first === null) {
                    $first = $t[1];
                }
            }
            if (preg_match("/alt:\\s*'([^']+)'/", $head, $am) && $first) {
                $alt[$am[1]] = [$hash, $first];
            }
            foreach ($tm as $t) {
                $key = preg_match("/screen:\\s*'([^']+)'/", $t[2], $sm) ? $sm[1]
                    : ((! $pre && $t[1] === $first) ? $hash : $hash . '?tab=' . $t[1]);
                $admin[] = $key;
                if (! $secAdmin && ! preg_match('/admin:\s*true/', $t[2])) {
                    $director[] = $key;
                }
            }
        }

        return [array_values(array_unique($admin)), array_values(array_unique($director)), $alt];
    }

    /** [words, numbered steps] of an English article body. */
    public static function size(string $file): array
    {
        $raw = str_replace("\r\n", "\n", (string) @file_get_contents($file));
        $body = preg_replace('/^---\n.*?\n---\n/s', '', $raw);

        return [str_word_count(strip_tags((string) $body)), preg_match_all('/^\s*\d+\.\s/m', (string) $body)];
    }
}
