<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Makes a small still for a gallery tile.
 *
 * Until now `photos.thumbnail_url` was set to the FULL-SIZE image path, so a gallery
 * of twelve tiles downloaded twelve full photos — a single 4000x3000 phone shot is
 * ~5 MB, drawn into a 220px box. That is why shared photos crawled on mobile data.
 * A 480px JPEG of the same picture is ~40 KB: roughly a hundredth of the bytes, and
 * still sharper than the box it is drawn into on a 2x screen.
 *
 * Imagick is preferred (it can read EXIF orientation and strip metadata); GD is the
 * fallback. Both are present on the current host, but never assume: every failure
 * path returns null and the caller keeps using the original, because a slow tile is
 * better than a missing one.
 */
final class MediaThumb
{
    /** Long edge of the generated thumbnail, in pixels. */
    public const EDGE = 480;

    /**
     * @param  string  $absPath  full path to the source image on disk
     * @return string|null       absolute path of the thumbnail, or null if not made
     */
    public static function make(string $absPath, int $edge = self::EDGE): ?string
    {
        if (! is_file($absPath)) return null;

        $info = @getimagesize($absPath);
        if (! $info) return null;                       // not an image (or unreadable)
        [$w, $h] = $info;
        if (! $w || ! $h) return null;

        // Already small enough that a second file would just cost a request.
        if (max($w, $h) <= $edge && filesize($absPath) < 200 * 1024) return null;

        $out = preg_replace('/\.[^.]+$/', '', $absPath).'-thumb.jpg';
        if (is_file($out) && filemtime($out) >= filemtime($absPath)) return $out;   // reuse

        $scale = $edge / max($w, $h);
        $tw = max(1, (int) round($w * $scale));
        $th = max(1, (int) round($h * $scale));

        try {
            if (extension_loaded('imagick')) {
                $im = new \Imagick($absPath);
                $im->setIteratorIndex(0);               // first frame of an animation
                if (method_exists($im, 'autoOrient')) $im->autoOrient();
                $im->thumbnailImage($tw, $th, true);
                $im->setImageFormat('jpeg');
                $im->setImageCompressionQuality(78);
                $im->stripImage();                     // drop EXIF/GPS from the copy
                $ok = $im->writeImage($out);
                $im->clear();
                if ($ok && is_file($out)) return $out;
                return null;
            }

            if (extension_loaded('gd')) {
                $src = match ($info[2]) {
                    IMAGETYPE_JPEG => @imagecreatefromjpeg($absPath),
                    IMAGETYPE_PNG  => @imagecreatefrompng($absPath),
                    IMAGETYPE_GIF  => @imagecreatefromgif($absPath),
                    IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($absPath) : false,
                    default        => false,
                };
                if (! $src) return null;
                $dst = imagecreatetruecolor($tw, $th);
                // Flatten transparency onto white — the output is JPEG.
                $white = imagecolorallocate($dst, 255, 255, 255);
                imagefilledrectangle($dst, 0, 0, $tw, $th, $white);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
                $ok = imagejpeg($dst, $out, 78);
                imagedestroy($src);
                imagedestroy($dst);
                if ($ok && is_file($out)) return $out;
            }
        } catch (\Throwable $e) {
            // fall through
        }
        return null;
    }

    /** Convert an absolute path under public/ back into a web path. */
    public static function webPath(string $absPath): ?string
    {
        $pub = rtrim(base_path('public'), '/\\');
        $abs = str_replace('\\', '/', $absPath);
        $pub = str_replace('\\', '/', $pub);
        if (str_starts_with($abs, $pub)) return substr($abs, strlen($pub));

        // Storage is symlinked in as public/storage, so a storage/app/public path maps too.
        $stor = str_replace('\\', '/', rtrim(storage_path('app/public'), '/\\'));
        if (str_starts_with($abs, $stor)) return '/storage'.substr($abs, strlen($stor));

        return null;
    }
}
