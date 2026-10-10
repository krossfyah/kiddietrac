<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * A brand gradient as an IMAGE, for email banners (2026-10-09).
 *
 * CSS gradients only render in some mail clients: Gmail and Apple Mail draw them, Outlook
 * does not (Anthony: "the gradient not showing in outlook"). A background image is shown by
 * all of them, including new Outlook and Outlook on the web, and classic Outlook for Windows
 * takes the same file through VML. Drawn once per colour pair with GD, stored under the
 * public disk (served as a plain static file: no API headers, cacheable) and reused.
 *
 * Matches the CSS it replaces: linear-gradient(135deg, A 0%, B 135%).
 */
final class EmailGradient
{
    public const W = 600;
    public const H = 340;

    private static function rgb(string $hex): ?array
    {
        $h = ltrim(trim($hex), '#');
        if (strlen($h) === 3) $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        if (! preg_match('/^[0-9a-fA-F]{6}$/', $h)) return null;
        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    /** Public URL of the banner image for these colours, or null if it cannot be made. */
    public static function url(string $from, string $to, float $endStop = 1.35): ?string
    {
        $a = self::rgb($from); $b = self::rgb($to);
        if (! $a || ! $b || ! function_exists('imagecreatetruecolor')) return null;
        $name = 'email-gradients/' . substr(md5(strtolower($from . '|' . $to . '|' . $endStop . '|' . self::W . 'x' . self::H . '|v1')), 0, 20) . '.png';
        $path = storage_path('app/public/' . $name);
        if (! is_file($path)) {
            try {
                if (! is_dir(dirname($path))) @mkdir(dirname($path), 0755, true);
                $w = self::W; $h = self::H;
                $im = imagecreatetruecolor($w, $h);
                // 135deg: towards the bottom-right; the gradient line spans w·sin45 + h·cos45.
                $len = ($w + $h) * M_SQRT1_2;
                $cx = $w / 2; $cy = $h / 2;
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $t = ((($x - $cx) + ($y - $cy)) * M_SQRT1_2) / $len + 0.5;   // 0 at top-left, 1 at bottom-right
                        $k = max(0.0, min(1.0, $t / $endStop));
                        imagesetpixel($im, $x, $y, (int) (
                            ((int) round($a[0] + ($b[0] - $a[0]) * $k) << 16) |
                            ((int) round($a[1] + ($b[1] - $a[1]) * $k) << 8) |
                            (int) round($a[2] + ($b[2] - $a[2]) * $k)));
                    }
                }
                $tmp = $path . '.tmp' . getmypid();
                imagepng($im, $tmp, 9);
                imagedestroy($im);
                rename($tmp, $path);
            } catch (\Throwable $e) {
                Log::warning('email gradient failed', ['error' => $e->getMessage()]);
                return null;
            }
        }

        return 'https://api.kiddietrac.com/storage/' . $name;
    }
}
