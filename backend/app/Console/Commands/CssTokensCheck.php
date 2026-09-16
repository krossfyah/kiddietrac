<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keep the design tokens in one place, and keep them defined.
 *
 * Two failures kept recurring in the portal's CSS, and both are invisible while you are
 * writing the code that causes them:
 *
 *  1. NINE stylesheets each defined :root. Whichever loaded last won, so the value a
 *     token carried was decided by <link> order in dashboard.html rather than by anyone's
 *     decision — and seven tokens genuinely disagreed between files. Editing the copy that
 *     did not win changed nothing on screen, which is how a fix could look applied and do
 *     nothing. Consolidated into kt-tokens.css on 2026-09-01; this stops it coming back.
 *
 *  2. A token referenced but never defined. `color: var(--ink-500)` with no fallback is
 *     not an error — it resolves to `inherit`, so text goes the colour of its parent and
 *     an element can render white-on-white. The 2026-07-24 production audit found ~350
 *     such references. A fallback (`var(--ink-500, #64748b)`) is fine and is not flagged;
 *     what this catches is the bare reference to a name nothing defines.
 *
 * styles.css is allowed its own :root ON PURPOSE: index.html and enroll.html load it
 * WITHOUT kt-tokens.css, so it is the only tokens those two pages get.
 *
 * Run before a review, or in CI:  php artisan css:tokens --check
 */
final class CssTokensCheck extends Command
{
    protected $signature = 'css:tokens
        {--check : Exit non-zero if tokens are split, referenced undefined, or a switch is reachable}
        {--refs : Also list every undefined reference, not just a count}';

    protected $description = 'Verify the design tokens live in one file and every referenced token exists';

    /**
     * The only two stylesheets permitted a top-level :root.
     *
     * kt-tokens.css is the portal's single source of truth. styles.css is the base sheet
     * for the pages that do not load kt-tokens.css at all — remove its :root and the login
     * and enrolment pages lose every token they have.
     */
    private const TOKEN_OWNERS = [
        // The portal (dashboard.html) — the single source of truth.
        'kt-tokens.css',
        // The standalone pages. index.html and enroll.html do NOT load kt-tokens.css,
        // so these three carry the whole token set for the login and enrolment screens.
        // A genuinely separate page family, not a split of the portal's tokens.
        'styles.css',
        'kt-login-v22.css',
        'kt-login-redesign.css',
    ];

    /**
     * Names that look like tokens but are declared at runtime rather than in a stylesheet:
     * JS sets them on an element's own style, so no :root defines them and none should.
     * Listed here so every exclusion is visible, rather than being a silent hole in a
     * check whose whole job is to have no holes.
     */
    private const RUNTIME_DEFINED = [
        '--kt-progress',
        '--kt-vh',
        '--kt-kb',
        '--shift',
        '--i',
    ];

    /** Strip block comments — a token NAMED in a comment is not a reference to it. */
    private function uncomment(string $src): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $src);
    }

    public function handle(): int
    {
        $root = realpath(base_path('../parent-portal'));
        if ($root === false) {
            $this->warn('parent-portal not found next to the backend — nothing to check.');

            return self::SUCCESS;
        }

        $css = $this->files($root, 'css');
        $html = $this->files($root, 'html');
        $js = $this->files($root, 'js');
        $offenders = [];

        /* WHERE A TOKEN COUNTS AS DEFINED — deliberately wider than :root.
           The first version of this check only collected :root in .css and reported four
           tokens as undefined that were all defined perfectly well: kiosk.html declares
           its own :root inline (it loads no stylesheet at all), and --tx/--ty are set on
           individual elements rather than the document. Both are legitimate. A checker
           whose failures are usually its own fault gets ignored, which would leave the
           real problem unguarded. */
        $defined = [];
        foreach (array_merge($css, $html) as $path) {
            $src = $this->uncomment((string) file_get_contents($path));
            foreach ($this->tokensIn($src) as $tok) {
                $defined[$tok] = basename($path);
            }
        }
        foreach ($js as $path) {
            // JS assigns tokens at runtime: el.style.setProperty('--kt-safe-top', ...)
            preg_match_all('/setProperty\(\s*[\x27"](--[\w-]+)/', (string) file_get_contents($path), $m);
            foreach ($m[1] as $tok) {
                $defined[$tok] = basename($path);
            }
        }

        // ── ownership is still about :root in stylesheets only ────────────────────
        foreach ($css as $path) {
            $name = basename($path);
            $blocks = $this->rootBlocks((string) file_get_contents($path));
            if ($blocks !== [] && ! in_array($name, self::TOKEN_OWNERS, true)) {
                $offenders[$name] = count($blocks);
            }
        }

        $this->line(sprintf('%d tokens defined; %d stylesheets, %d pages, %d scripts scanned.',
            count($defined), count($css), count($html), count($js)));

        // ── 1. is anything but the two owners defining tokens? ────────────────────
        $ok = true;
        if ($offenders !== []) {
            $ok = false;
            $this->newLine();
            $this->error('Tokens defined outside kt-tokens.css:');
            foreach ($offenders as $name => $n) {
                $this->line(sprintf('   %-38s %d :root block(s)', $name, $n));
            }
            $this->line('   Move these into kt-tokens.css. A second :root means the winner is');
            $this->line('   decided by <link> order, and editing the loser changes nothing.');
        } else {
            $this->info('Every token lives in an owning stylesheet.');
        }

        // ── 2. referenced but never defined ───────────────────────────────────────
        $missing = [];
        foreach (array_merge($css, $js, $html) as $path) {
            $src = $this->uncomment((string) file_get_contents($path));
            // var(--name) with NO comma before the closing paren = no fallback.
            preg_match_all('/var\(\s*(--[\w-]+)\s*\)/', $src, $m);
            foreach ($m[1] as $tok) {
                if (isset($defined[$tok]) || in_array($tok, self::RUNTIME_DEFINED, true)) {
                    continue;
                }
                $missing[$tok][basename($path)] = true;
            }
        }

        $this->newLine();
        if ($missing !== []) {
            $ok = false;
            $this->error(sprintf('%d token(s) referenced with no definition and no fallback:', count($missing)));
            $show = $this->option('refs') ? $missing : array_slice($missing, 0, 12, true);
            foreach ($show as $tok => $files) {
                $this->line(sprintf('   %-28s used in %s', $tok, implode(', ', array_slice(array_keys($files), 0, 3))));
            }
            if (! $this->option('refs') && count($missing) > 12) {
                $this->line(sprintf('   ... and %d more (--refs to list)', count($missing) - 12));
            }
            $this->line('   These resolve to `inherit`, so the element renders the parent colour —');
            $this->line('   usually invisible rather than obviously wrong. Define them, or give');
            $this->line('   each reference a fallback.');
        } else {
            $this->info('Every referenced token is defined.');
        }

        // ── 3. can a generic !important rule reach a switch? ──────────────────────
        $reach = [];
        foreach ($css as $path) {
            $src = $this->uncomment((string) file_get_contents($path));
            if (! preg_match_all('/([^{}]+)\{([^{}]*)\}/', $src, $rules, PREG_SET_ORDER)) {
                continue;
            }
            foreach ($rules as $rule) {
                if (! str_contains($rule[2], '!important')) {
                    continue;
                }
                foreach (explode(',', $rule[1]) as $sel) {
                    if ($this->reachesSwitch(trim($sel))) {
                        $reach[] = [basename($path), trim($sel)];
                    }
                }
            }
        }

        $this->newLine();
        if ($reach !== []) {
            $ok = false;
            $this->error(sprintf('%d unscoped !important rule(s) can restyle a switch:', count($reach)));
            foreach (array_slice($reach, 0, 12) as [$f, $sel]) {
                $this->line(sprintf('   %-30s %s', $f, substr($sel, 0, 62)));
            }
            $this->line('   A switch is a <button role="switch"> or a checkbox styled as one, so a bare');
            $this->line('   `button {...!important}` silently squares it off or stretches it — the');
            $this->line('   cosmetic bug that has come back repeatedly. Exclude switches from the');
            $this->line('   selector: button:not([role="switch"]):not([aria-pressed]) { ... }');
        } else {
            $this->info('No generic !important rule can reach a switch.');
        }

        if ($this->option('check') && ! $ok) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Would this selector restyle a switch?
     *
     * Only bare element selectors are a problem: `button { ... !important }` beats the
     * inline styling that makes a switch look like a switch, because !important outranks
     * inline. Anything anchored to a class or id is aimed at something specific and is
     * its author's business.
     *
     * A selector is fine if it excludes switches explicitly, targets a typed input that
     * cannot be one, or styles a pseudo-element a checkbox does not have.
     */
    private function reachesSwitch(string $sel): bool
    {
        if ($sel === '' || ! preg_match('/^(button|input)\b/', $sel)) {
            return false;
        }
        // Explicitly scoped away from switches — the correct fix, already applied in six places.
        if (preg_match('/:not\(\s*\[(role="switch"|aria-pressed)/', $sel)
            || preg_match('/:not\(\s*\[type=["\x27]?checkbox/', $sel)
            || str_contains($sel, 'kt-sw')
            || str_contains($sel, 'switchified')
            || str_contains($sel, '[role="switch"]')) {
            return false;
        }
        // A typed input that is not a checkbox can never be a switch.
        if (preg_match('/\[type=["\x27]?(\w+)/', $sel, $m) && $m[1] !== 'checkbox') {
            return false;
        }
        // ::placeholder, ::-webkit-*, etc. do not exist on a checkbox or button-switch.
        if (str_contains($sel, '::')) {
            return false;
        }

        return true;
    }

    /** Top-level :root bodies. A :root nested in @media is a legitimate override, not a split. */
    private function rootBlocks(string $src): array
    {
        $out = [];
        if (! preg_match_all('/:root\s*\{/', $src, $m, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($m[0] as [$match, $offset]) {
            $before = (string) preg_replace('#/\*.*?\*/#s', '', substr($src, 0, $offset));
            if (substr_count($before, '{') - substr_count($before, '}') !== 0) {
                continue;
            }
            $start = $offset + strlen($match);
            $end = strpos($src, '}', $start);
            if ($end !== false) {
                $out[] = substr($src, $start, $end - $start);
            }
        }

        return $out;
    }

    /** Token declarations: `--name:` inside a rule, anywhere. */
    private function tokensIn(string $body): array
    {
        preg_match_all('/(--[\w-]+)\s*:/', $this->uncomment($body), $m);

        return $m[1];
    }

    private function files(string $root, string $ext): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            $p = $f->getPathname();
            if (str_contains($p, DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if (strtolower($f->getExtension()) === $ext) {
                $out[] = $p;
            }
        }
        sort($out);

        return $out;
    }
}
