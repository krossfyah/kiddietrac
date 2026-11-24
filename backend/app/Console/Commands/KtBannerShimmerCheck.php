<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * EVERY BANNER SHIMMERS (2026-11-24, Anthony: "playspark hero banner has no shimmer effect? - full sweep to ensure
 * that all hero banners have the shimmer and is not missed (add to memory and guard)").
 *
 * The shimmer is kt-hero-breathe: background-size 200% and background-position sliding across a gradient. It
 * can be "on" and still invisible: the Play Spark brand gradient (#0B1026 -> #1B2350 -> #2A3570) had a colour
 * spread of 30 (CIE Lab delta-E) against 48-162 for every other banner, so the moving gradient looked static.
 *
 * Static checks over parent-portal/{js,css} (no browser needed):
 *   1. every gradient a banner is actually painted with spans at least MIN_SPREAD (delta-E, lightest to
 *      darkest stop): kt-hero-emoji.js BRANDS `bg:`, the --kt-hero-grad* palette tokens, and each
 *      white-label agency's Brand::hero. (A screen's inline banner colours are overridden by the palette,
 *      !important, kt-design-v22p64.css - so they are not checked: 13 false alarms on the first run.)
 *   2. branding-loader keeps background-size 200% on white-label banners (it was `auto`: static for every
 *      white-label customer until 2026-11-24);
 *   3. no stylesheet sets `animation: none` on a banner class outside @media (prefers-reduced-motion);
 *   4. each banner class (.kt-hero, .kt-page-hero, .page-header-v17, .kt-banner-fx) still has a
 *      kt-hero-breathe rule.
 * Runtime confirmation (every screen, every role): tools/banner-sweep.mjs (headless Chrome, own profile).
 * Nightly; exit 1 + email to the platform admins when anything is found.
 */
class KtBannerShimmerCheck extends Command
{
    protected $signature = 'kt:banner-shimmer-check {--no-mail : print only} {--quiet-ok}';
    protected $description = 'Every section banner has a visible shimmer (gradient range, animation not switched off).';

    public const MIN_SPREAD = 35;   // the standard palette (-b -c -g) sits at 38-39 and visibly shimmers; Play Spark was 30
    private const BANNER = '(?:kt-hero|kt-page-hero|page-header-v17|kt-banner-fx)';

    public function handle(): int
    {
        $root = base_path('../parent-portal');
        $found = [];

        // 1 + 2: gradients on banners in JS.
        foreach (glob($root . '/js/*.js') as $file) {
            $name = basename($file);
            $src = (string) @file_get_contents($file);
            if ($name === 'kt-hero-emoji.js') {
                if (preg_match_all('/\bbg:\s*[\'"]([^\'"]*gradient[^\'"]*)[\'"]/', $src, $m, PREG_OFFSET_CAPTURE)) {
                    foreach ($m[1] as [$g, $off]) {
                        $s = self::spread($g);
                        if ($s < self::MIN_SPREAD) {
                            $found['Brand banner gradients too flat to shimmer (min ' . self::MIN_SPREAD . ')'][] =
                                $name . ':' . self::lineAt($src, $off) . '  spread ' . $s . '  ' . $g;
                        }
                    }
                }
                continue;
            }
            // White-label banners: branding-loader must keep the shimmer's 200% size (it set `auto` until 2026-11-24).
            if ($name === 'branding-loader.js' && preg_match('/HERO_SEL\s*\+\s*.\{[^\n]*?background-size:\s*auto/', $src)) {
                $found['White-label banners pinned to background-size:auto (no room to slide)'][] = $name;
            }
        }

        // 3 + 4: stylesheets.
        $breathe = [];
        foreach (glob($root . '/css/*.css') as $file) {
            $name = basename($file);
            $css = (string) @file_get_contents($file);
            $flat = self::stripReducedMotion(preg_replace('#/\*.*?\*/#s', '', $css));
            if (preg_match_all('/([^{}]+)\{([^{}]*)\}/', $flat, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
                foreach ($m as $rule) {
                    $sel = $rule[1][0];
                    $decl = $rule[2][0];
                    if (! preg_match('/\.' . self::BANNER . '(?![\w-])(?![^,]*\s[.#\w])/', $sel)) continue;   // the banner itself, not its children
                    if (preg_match('/(^|;)\s*animation(-name)?\s*:\s*none/i', $decl)) {
                        $found['Banner animation switched off (outside reduced-motion)'][] = $name . '  ' . trim(preg_replace('/\s+/', ' ', $sel));
                    }
                    if (preg_match('/kt-hero-breathe/', $decl)) {
                        foreach (['kt-hero', 'kt-page-hero', 'page-header-v17', 'kt-banner-fx'] as $c) {
                            if (preg_match('/\.' . $c . '(?![\w-])/', $sel)) $breathe[$c] = true;
                        }
                    }
                }
            }
        }
        // The palette every banner draws from (inline banner colours are overridden by these, !important).
        foreach (glob($root . '/css/*.css') as $file) {
            $css = (string) @file_get_contents($file);
            if (preg_match_all('/(--kt-hero-grad[\w-]*)\s*:\s*([^;]*gradient[^;]*);/', $css, $m, PREG_SET_ORDER)) {
                foreach ($m as [, $tok, $g]) {
                    $sp = self::spread($g);
                    if ($sp >= 0 && $sp < self::MIN_SPREAD) $found['Banner palette tokens too flat to shimmer (min ' . self::MIN_SPREAD . ')'][] = basename($file) . '  ' . $tok . '  spread ' . $sp;
                }
            }
        }
        // White-label brands: each agency's own banner gradient (Brand::hero).
        foreach (\Illuminate\Support\Facades\DB::table('agencies')->whereNull('deleted_at')->pluck('id') as $aid) {
            try {
                if (! \App\Support\Brand::whiteLabel((int) $aid)) continue;
                $h = \App\Support\Brand::hero(\App\Support\Brand::forAgency((int) $aid));
                $sp = self::spread($h['css']);
                if ($sp >= 0 && $sp < self::MIN_SPREAD) $found['White-label banner gradients too flat to shimmer (Branding -> banner colours)'][] = 'agency #' . $aid . '  spread ' . $sp . '  ' . $h['css'];
            } catch (\Throwable $e) {}
        }
        foreach (['kt-hero', 'kt-page-hero', 'page-header-v17', 'kt-banner-fx'] as $c) {
            if (empty($breathe[$c])) $found['Banner classes with no kt-hero-breathe rule'][] = '.' . $c;
        }

        if (! $found) {
            if (! $this->option('quiet-ok')) $this->info('Banner shimmer check: every banner shimmers.');
            return 0;
        }
        $lines = [];
        foreach ($found as $head => $items) {
            $lines[] = $head . ':';
            foreach ($items as $i) $lines[] = '  - ' . $i;
        }
        $this->error(implode("\n", $lines));
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins('[KiddieTrac] Banner shimmer check - banners that will not shimmer',
                "These section banners will look static.\n\n" . implode("\n", $lines));
        }
        return 1;
    }

    /** Drop every @media (prefers-reduced-motion ...) { ... } block (nested braces). */
    private static function stripReducedMotion(string $css): string
    {
        while (($p = stripos($css, '@media')) !== false && preg_match('/@media[^{]*prefers-reduced-motion[^{]*\{/i', $css, $mm, PREG_OFFSET_CAPTURE)) {
            $start = $mm[0][1];
            $i = $start + strlen($mm[0][0]);
            $depth = 1;
            $n = strlen($css);
            while ($i < $n && $depth > 0) {
                if ($css[$i] === '{') $depth++;
                elseif ($css[$i] === '}') $depth--;
                $i++;
            }
            $css = substr($css, 0, $start) . substr($css, $i);
        }
        return $css;
    }

    private static function lineAt(string $src, int $off): int
    {
        return substr_count(substr($src, 0, $off), "\n") + 1;
    }

    /** Largest CIE76 delta-E between any two opaque colour stops; -1 when fewer than two colours. */
    public static function spread(string $g): int
    {
        preg_match_all('/#([0-9a-f]{6}|[0-9a-f]{3})\b|rgba?\(\s*([\d.]+)[\s,]+([\d.]+)[\s,]+([\d.]+)(?:[\s,\/]+([\d.]+%?))?\s*\)/i', $g, $m, PREG_SET_ORDER);
        $labs = [];
        foreach ($m as $c) {
            if (! empty($c[1])) {
                $h = strlen($c[1]) === 3 ? preg_replace('/(.)/', '$1$1', $c[1]) : $c[1];
                $rgb = [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
            } else {
                $a = isset($c[5]) && $c[5] !== '' ? (float) $c[5] / (str_ends_with($c[5], '%') ? 100 : 1) : 1;
                if ($a < 0.5) continue;   // a translucent sheen, not a stop
                $rgb = [(float) $c[2], (float) $c[3], (float) $c[4]];
            }
            $labs[] = self::lab($rgb);
        }
        if (count($labs) < 2) return -1;
        $max = 0;
        foreach ($labs as $a) foreach ($labs as $b) $max = max($max, sqrt(($a[0] - $b[0]) ** 2 + ($a[1] - $b[1]) ** 2 + ($a[2] - $b[2]) ** 2));
        return (int) round($max);
    }

    private static function lab(array $rgb): array
    {
        $f = function ($v) { $v /= 255; return $v <= 0.04045 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4; };
        [$r, $g, $b] = array_map($f, $rgb);
        $x = ($r * 0.4124 + $g * 0.3576 + $b * 0.1805) / 0.95047;
        $y = $r * 0.2126 + $g * 0.7152 + $b * 0.0722;
        $z = ($r * 0.0193 + $g * 0.1192 + $b * 0.9505) / 1.08883;
        $t = fn ($v) => $v > 0.008856 ? $v ** (1 / 3) : 7.787 * $v + 16 / 116;
        return [116 * $t($y) - 16, 500 * ($t($x) - $t($y)), 200 * ($t($y) - $t($z))];
    }
}
