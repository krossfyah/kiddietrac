<?php

namespace App\Support;

/**
 * IS THIS PROFILE PHOTO ACTUALLY A PICTURE? (2026-10-30, Anthony: "avatar or display pics for some users like
 * saurav still not showing - full sweep").
 *
 * Every stored avatar was served correctly. The ones that "did not show" were blank uploads:
 *  - Umera: a solid black square. The in-app camera's Capture button worked before the camera had a picture, and
 *    drawing an empty <video> gives a black frame (fixed in screen-onboarding.js too).
 *  - Lisa: one flat colour.
 *  - Saurav: a grey "image placeholder" graphic, picked to get past the required photo step.
 * In a 26px circle all three read as a missing or broken picture.
 *
 * A photo of a person has dozens of distinct colours; these had 1-11. We sample a 24x24 grid, bucket each pixel to
 * 16 levels per channel and refuse the upload when there are 12 buckets or fewer. The person is told why and can
 * pick another picture.
 */
final class AvatarCheck
{
    public const MIN_COLOURS = 13;

    /** Distinct colour buckets in the image, or null when it cannot be read (let the normal validation decide). */
    public static function colours(string $path): ?int
    {
        if (! function_exists('imagecreatefromstring') || ! is_file($path)) return null;
        $im = @imagecreatefromstring((string) file_get_contents($path));
        if (! $im) return null;
        $w = imagesx($im); $h = imagesy($im);
        $sx = max(1, intdiv($w, 24)); $sy = max(1, intdiv($h, 24));
        $seen = [];
        for ($x = 0; $x < $w; $x += $sx) {
            for ($y = 0; $y < $h; $y += $sy) {
                $c = imagecolorat($im, $x, $y);
                if (! imageistruecolor($im)) { $c = imagecolorsforindex($im, $c); $c = ($c['red'] << 16) | ($c['green'] << 8) | $c['blue']; }
                $seen[(($c >> 20) & 15) . '-' . (($c >> 12) & 15) . '-' . (($c >> 4) & 15)] = true;
            }
        }
        imagedestroy($im);
        return count($seen);
    }

    /** True when the picture is blank, one flat colour, or a simple placeholder graphic. */
    public static function looksBlank(string $path): bool
    {
        $n = self::colours($path);
        return $n !== null && $n < self::MIN_COLOURS;
    }

    public const MESSAGE = 'That picture looks blank. Please choose a clear photo of your face, or take one with the camera.';
}
