<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which banner tops a KiddieTrac-branded email right now (2026-10-09).
 *
 * Anthony: "change all email templates using the existing header logo to use the new header
 * logo during seasonal changes that is configurable from a set time and will change
 * automatically ... I can add more later through the gui". Seasons live in
 * email_header_seasons; outside every active season the standard banner is used. Times are
 * Toronto wall-clock. A yearly season compares month-day-time, so "Dec 1 – Jan 2" works
 * every year and wraps the new year. On overlap, the season that started most recently wins.
 * White-label agencies keep their own logo — this only changes the KiddieTrac banner.
 */
final class EmailHeader
{
    public const DEFAULT_URL = 'https://app.kiddietrac.com/email-header-v2.jpg';
    public const TZ = 'America/Toronto';
    private static ?array $memo = null;     // [url, expiresAt] per process

    public static function url(?Carbon $at = null): string
    {
        if ($at === null && self::$memo && self::$memo[1] > time()) {
            return self::$memo[0];
        }
        $url = self::DEFAULT_URL;
        try {
            $s = self::current($at);
            if ($s) $url = (string) $s->image_url;
        } catch (\Throwable $e) {
            // A header must never stop an email.
        }
        if ($at === null) self::$memo = [$url, time() + 60];

        return $url;
    }

    /** The season in force at $at (now by default), or null. */
    public static function current(?Carbon $at = null): ?object
    {
        if (! Schema::hasTable('email_header_seasons')) return null;
        $now = ($at ?: now())->copy()->setTimezone(self::TZ);
        $best = null; $bestStart = null;
        foreach (DB::table('email_header_seasons')->where('active', 1)->get() as $s) {
            $start = Carbon::parse($s->starts_at, self::TZ);
            $end = Carbon::parse($s->ends_at, self::TZ);
            if ($s->repeat_yearly) {
                // Place the window in the year that makes it contain "now", if any.
                $start = $start->copy()->setYear($now->year);
                $end = $end->copy()->setYear($now->year);
                if ($end->lt($start)) {                 // wraps the new year
                    if ($now->lt($start)) $start->subYear(); else $end->addYear();
                }
            }
            if ($now->gte($start) && $now->lt($end) && ($bestStart === null || $start->gt($bestStart))) {
                $best = $s; $bestStart = $start;
            }
        }

        return $best;
    }

    /**
     * Resize an uploaded banner to a light 1240px-wide JPEG and save it where the standard
     * banner is served from (app.kiddietrac.com). Returns its public URL.
     */
    public static function storeImage(string $srcPath, string $name): string
    {
        $info = @getimagesize($srcPath);
        if (! $info) throw new \RuntimeException('That file is not an image.');
        $src = match ($info[2]) {
            IMAGETYPE_PNG => imagecreatefrompng($srcPath),
            IMAGETYPE_JPEG => imagecreatefromjpeg($srcPath),
            IMAGETYPE_WEBP => imagecreatefromwebp($srcPath),
            default => throw new \RuntimeException('Use a PNG, JPEG or WebP image.'),
        };
        $w = imagesx($src); $h = imagesy($src);
        $tw = min(1240, $w); $th = (int) round($h * $tw / $w);
        $dst = imagecreatetruecolor($tw, $th);
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        $dir = base_path('../parent-portal/email-headers');
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-') ?: 'season';
        $file = $slug . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.jpg';
        imagejpeg($dst, $dir . '/' . $file, 82);
        imagedestroy($src); imagedestroy($dst);

        return 'https://app.kiddietrac.com/email-headers/' . $file;
    }
}
