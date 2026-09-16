<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Make an uploaded picture safe to put in a text message.
 *
 * A phone photo is 3–8 MB of 4000px JPEG. Carriers do not forward that: the practical
 * ceiling for MMS is well under a megabyte on most North American networks, and Twilio's
 * own guidance is to stay near 500 KB for anything you actually want delivered. Sending
 * the original means the message silently vanishes somewhere between Twilio and the
 * handset, which is worse than refusing it.
 *
 * So the file is re-encoded rather than merely checked: capped at 1200px on its long
 * edge, then re-compressed downward until it fits the target. GIFs are passed through
 * untouched — re-encoding one loses the animation, which is usually the entire point.
 *
 * The result goes in the API's public web root under a 40-character random name. It has
 * to be fetchable without credentials because that is how carriers collect MMS media, so
 * the filename IS the access control — the same reasoning as the walk maps.
 */
class AnnouncementImage
{
    /** What carriers actually accept. Not a preference — anything else is dropped. */
    public const MIMES = ['image/jpeg', 'image/png', 'image/gif'];

    /** Aim here. Above roughly this, delivery gets unreliable rather than slow. */
    private const TARGET_BYTES = 480 * 1024;

    /** Hard ceiling. Twilio rejects beyond 5 MB outright. */
    public const MAX_UPLOAD_BYTES = 5 * 1024 * 1024;

    private const MAX_EDGE = 1200;

    /**
     * @return array{path:string,url:string,bytes:int,mms_ok:bool}|null
     */
    public static function store(UploadedFile $file, string $folder = 'announcement-images'): ?array
    {
        $mime = strtolower((string) $file->getClientMimeType());
        if (! in_array($mime, self::MIMES, true)) {
            return null;
        }

        // Campaign banners live elsewhere but need identical treatment — re-encoded,
        // size-capped and given an unguessable name. Same rules, different folder,
        // rather than a second copy of this logic that drifts from it.
        $dir = public_path($folder);
        if (! is_dir($dir) && ! @mkdir($dir, 0755, true) && ! is_dir($dir)) {
            Log::warning('Announcement image dir not writable', ['dir' => $dir]);

            return null;
        }

        $token = Str::random(40);

        // An animated GIF cannot survive a re-encode through GD's single-frame API, and
        // an announcement GIF is nearly always animated on purpose. Take it as-is if it
        // is already small enough; refuse it if not, rather than send a still frame.
        if ($mime === 'image/gif') {
            if ($file->getSize() > self::TARGET_BYTES * 2) {
                return null;
            }
            $name = $token.'.gif';
            $file->move($dir, $name);

            return self::result($dir, $name, true, $folder);
        }

        $src = self::readImage($file->getPathname(), $mime);
        if (! $src) {
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, self::MAX_EDGE / max($w, $h));
        if ($scale < 1.0) {
            $nw = max(1, (int) round($w * $scale));
            $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            // A PNG screenshot is a common case and usually has transparency; flatten it
            // onto white rather than letting it come out with black behind the text.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($src);
            $src = $dst;
        }

        // Step the quality down until it fits. Everything becomes a JPEG at this point —
        // it is the only one of the three formats that compresses to a size a carrier
        // will carry, and a photo or screenshot loses nothing that matters.
        $name = $token.'.jpg';
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        $ok = false;
        foreach ([82, 70, 58, 45, 34] as $q) {
            if (! imagejpeg($src, $path, $q)) {
                break;
            }
            $ok = true;
            clearstatcache(true, $path);
            if (filesize($path) <= self::TARGET_BYTES) {
                break;
            }
        }
        imagedestroy($src);

        if (! $ok || ! is_file($path)) {
            return null;
        }

        clearstatcache(true, $path);

        // Still too big after the lowest quality step — say so plainly rather than send
        // something that will be dropped in transit.
        return self::result($dir, $name, filesize($path) <= self::TARGET_BYTES * 1.25, $folder);
    }

    private static function result(string $dir, string $name, bool $mmsOk, string $folder = 'announcement-images'): array
    {
        $path = $dir.DIRECTORY_SEPARATOR.$name;
        clearstatcache(true, $path);

        return [
            'path'   => $folder.'/'.$name,
            'url'    => rtrim((string) config('app.url'), '/').'/'.$folder.'/'.$name,
            'bytes'  => (int) @filesize($path),
            'mms_ok' => $mmsOk,
        ];
    }

    private static function readImage(string $path, string $mime)
    {
        try {
            if ($mime === 'image/jpeg') {
                return @imagecreatefromjpeg($path);
            }
            if ($mime === 'image/png') {
                return @imagecreatefrompng($path);
            }
        } catch (\Throwable $e) {
            Log::warning('Announcement image decode failed', ['error' => $e->getMessage()]);
        }

        return null;
    }
}
