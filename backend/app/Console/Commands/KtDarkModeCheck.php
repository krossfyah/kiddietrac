<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * THE PORTAL IS LIGHT ON EVERY DEVICE (2026-11-27, Anthony: "the ? pops open as a black/dark mode - force this to
 * light mode (full sweep)").
 *
 * The portal has no dark theme: dashboard.html declares color-scheme: light only, so native controls and scrollbars
 * stay light. But any style that follows the DEVICE's dark mode still switches on by itself on a phone or computer
 * set to dark - that is how the help reader ("?"), every quick popover and the home section heads came up black
 * (fixed in 03781f63). Dark looks for the portal belong under its own switch, html.kt-dark / [data-theme="dark"],
 * which nothing turns on today.
 *
 * Static check over parent-portal/js/*.js, parent-portal/css/*.css, dashboard.src.html and index.html. It fails on:
 *   1. an @media (prefers-color-scheme: dark) block whose first rule is NOT scoped to html.kt-dark /
 *      [data-theme="dark"] (a scoped one can only apply once the portal turns its own dark theme on);
 *   2. a matchMedia('(prefers-color-scheme: dark)') call - code reading the device's dark mode to style the portal.
 * Comments are ignored. Allowed on purpose (dark mode kept): emails (built server-side, never in these folders), the
 * in-portal email preview (screen-email.js, screen-email-template.js) and the public standalone pages, which are not
 * the portal and are not scanned: nps.html, reenrol-reply.html, attendance-reply.html, jobs.html, pickup-pass.html.
 * Nightly; exit 1 + email to the platform admins when anything is found.
 */
class KtDarkModeCheck extends Command
{
    protected $signature = 'kt:dark-mode-check {--root= : parent-portal folder (default: the live one)} {--no-mail : print only} {--quiet-ok}';

    protected $description = 'Fail when portal JS/CSS follows the device\'s dark mode (the portal is light on every device).';

    /** Files that may follow the device's dark mode: the email preview shows an email as the reader's mail app would. */
    public const ALLOW = ['screen-email.js', 'screen-email-template.js'];

    /** Public standalone pages with deliberate dark mode - listed for the record; only portal files are scanned. */
    public const PUBLIC_PAGES = ['nps.html', 'reenrol-reply.html', 'attendance-reply.html', 'jobs.html', 'pickup-pass.html'];

    public function handle(): int
    {
        $root = rtrim($this->option('root') ?: base_path('../parent-portal'), '/');
        $files = array_merge(glob($root . '/js/*.js') ?: [], glob($root . '/css/*.css') ?: [],
            array_filter([$root . '/dashboard.src.html', $root . '/index.html'], 'is_file'));
        $found = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, self::ALLOW, true)) {
                continue;
            }
            $src = self::stripComments((string) @file_get_contents($file));
            foreach (self::findings($src) as [$off, $what]) {
                $found[] = ['line' => $name . ':' . self::lineAt($src, $off) . '  ' . $what];
            }
        }

        if (! $found) {
            if (! $this->option('quiet-ok')) {
                $this->info('Dark-mode check: ' . count($files) . ' portal files, none follow the device\'s dark mode.');
            }
            return 0;
        }
        $this->error("Portal styles that follow the device's dark mode (the portal is light on every device):");
        foreach ($found as $f) {
            $this->line('  - ' . $f['line']);
        }
        $this->line('Scope a dark look to html.kt-dark / [data-theme="dark"] instead, or remove it.');
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins('[KiddieTrac] Dark-mode check - portal styles follow the device\'s dark mode',
                'On a phone or computer set to dark mode these parts of the portal will turn dark (the portal has no dark theme). '
                . 'Scope them to html.kt-dark or remove them.',
                ['Unscoped dark-mode rules' => $found]);
        }
        return 1;
    }

    /** [offset, description] for each unscoped device-dark rule or listener. */
    public static function findings(string $src): array
    {
        $out = [];
        if (preg_match_all('/@media[^{]*prefers-color-scheme\s*:\s*dark[^{]*\{/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $off]) {
                $after = substr($src, $off + strlen($hit), 400);
                $sel = strstr($after, '{', true);
                $sel = $sel === false ? $after : $sel;
                if (! self::scoped($sel)) {
                    $out[] = [$off, '@media (prefers-color-scheme: dark) { ' . trim(preg_replace('/\s+/', ' ', mb_substr($sel, 0, 90))) . ' }'];
                }
            }
        }
        if (preg_match_all('/matchMedia\s*\(\s*[\'"`]\s*\(?\s*prefers-color-scheme\s*:\s*dark/i', $src, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$hit, $off]) {
                $out[] = [$off, "matchMedia('(prefers-color-scheme: dark)')"];
            }
        }
        return $out;
    }

    /** The rule only applies under the portal's own dark switch. */
    private static function scoped(string $sel): bool
    {
        $parts = array_filter(array_map('trim', explode(',', $sel)), fn ($p) => $p !== '');
        if (! $parts) {
            return false;
        }
        foreach ($parts as $p) {
            if (! preg_match('/kt-dark|data-theme\s*=\s*\\\\?[\'"]?dark/i', $p)) {
                return false;
            }
        }
        return true;
    }

    /** Blank out block comments and // line comments, keeping offsets (and so line numbers) intact. */
    private static function stripComments(string $s): string
    {
        $s = preg_replace_callback('#/\*.*?\*/#s', fn ($m) => preg_replace('/[^\n]/', ' ', $m[0]), $s);
        return preg_replace_callback('#(^|[\s;{}(,])//[^\n]*#', fn ($m) => $m[1] . str_repeat(' ', strlen($m[0]) - strlen($m[1])), $s);
    }

    private static function lineAt(string $s, int $off): int
    {
        return substr_count(substr($s, 0, $off), "\n") + 1;
    }
}
