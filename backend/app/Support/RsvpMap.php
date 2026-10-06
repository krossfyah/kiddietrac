<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A map of where an RSVP event is, for the confirmation email (2026-10-06).
 *
 * Anthony: "add a map to the email outlining the exact location". Email clients run no
 * scripts and load no frames, so an embedded map is impossible; what every client shows
 * is an image. So the address is looked up once (OpenStreetMap's Nominatim), a picture of
 * the streets around it is drawn from OpenStreetMap tiles with a pin on the spot, and the
 * PNG is saved beside the event's other images. The email shows the picture, and tapping
 * it — or "Get directions" — opens Google Maps at the same coordinates.
 *
 * Cached by the address text: the same address never asks OpenStreetMap twice (their
 * usage policy asks for exactly that, plus a real User-Agent and the attribution printed
 * on the image). A sidecar JSON keeps the coordinates for the directions link. If the
 * lookup or drawing fails, the email simply has no map — never a broken image.
 */
final class RsvpMap
{
    private const UA = 'KiddieTrac-RSVP/1.0 (+https://www.kiddietrac.com; noreply@kiddietrac.com)';
    private const W = 640;
    private const H = 320;
    private const ZOOM = 15;

    /**
     * The map for an event's location, made if needed.
     *
     * @return array{img:string,lat:float,lng:float,label:string}|null
     */
    public static function forEvent(object $event): ?array
    {
        $loc = trim((string) ($event->location ?? ''));
        $pin = self::parsePin((string) ($event->map_pin ?? ''));
        if ($loc === '' && ! $pin) return null;
        $agencyId = (int) ($event->agency_id ?? 0);
        $key = $pin ? md5('pin:' . $pin[0] . ',' . $pin[1]) : md5(mb_strtolower(preg_replace('/\s+/u', ' ', self::clean($loc))));
        $dir = storage_path('app/public/rsvp/' . $agencyId);
        $png = $dir . '/map-' . $key . '.png';
        $meta = $dir . '/map-' . $key . '.json';
        $url = 'https://api.kiddietrac.com/storage/rsvp/' . $agencyId . '/map-' . $key . '.png';

        if (is_file($png) && is_file($meta)) {
            $m = json_decode((string) file_get_contents($meta), true);
            if (is_array($m) && isset($m['lat'], $m['lng'])) {
                return ['img' => $url, 'lat' => (float) $m['lat'], 'lng' => (float) $m['lng'], 'label' => (string) ($m['label'] ?? '')];
            }
        }
        // A failed lookup is remembered too, for a day, so every confirmation does not retry it.
        $fail = $dir . '/map-' . $key . '.fail';
        if (is_file($fail) && filemtime($fail) > time() - 86400) return null;

        try {
            $hit = $pin ? ['lat' => $pin[0], 'lng' => $pin[1], 'label' => self::clean($loc), 'query' => 'pin'] : self::geocode($loc);
            if (! $hit) { @mkdir($dir, 0775, true); @file_put_contents($fail, 'not found'); return null; }
            $img = self::render($hit['lat'], $hit['lng']);
            if (! $img) { @mkdir($dir, 0775, true); @file_put_contents($fail, 'render failed'); return null; }
            @mkdir($dir, 0775, true);
            file_put_contents($png, $img);
            file_put_contents($meta, json_encode(['lat' => $hit['lat'], 'lng' => $hit['lng'], 'label' => $hit['label'], 'query' => $hit['query'], 'at' => date('c')]));
            return ['img' => $url, 'lat' => $hit['lat'], 'lng' => $hit['lng'], 'label' => $hit['label']];
        } catch (\Throwable $e) {
            Log::warning('rsvp map failed', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * An exact spot from what an organiser pastes: a Google Maps link ("…/@44.01,-80.07,17z",
     * "…!3d44.01!4d-80.07", "?q=44.01,-80.07") or plain "44.01, -80.07". Null if none.
     *
     * @return array{0:float,1:float}|null
     */
    public static function parsePin(string $s): ?array
    {
        $s = trim($s);
        if ($s === '') return null;
        $pats = ['/!3d(-?\d+(?:\.\d+)?)!4d(-?\d+(?:\.\d+)?)/', '/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/',
                 '/[?&](?:q|query|ll|destination)=(-?\d+(?:\.\d+)?)(?:,|%2C)\s*(-?\d+(?:\.\d+)?)/i', '/^\s*(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)\s*$/'];
        foreach ($pats as $re) {
            if (preg_match($re, $s, $m)) {
                $lat = (float) $m[1]; $lng = (float) $m[2];
                if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180 && ($lat != 0.0 || $lng != 0.0)) return [$lat, $lng];
            }
        }

        return null;
    }

    /** Google Maps directions to the pin (or to the address text when there is no pin). */
    public static function directionsUrl(object $event, ?array $map): string
    {
        if ($map) return 'https://www.google.com/maps/dir/?api=1&destination=' . $map['lat'] . ',' . $map['lng'];

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(self::clean((string) $event->location));
    }

    /** The location text without emoji/pictographs, lines joined with commas. */
    public static function clean(string $loc): string
    {
        $loc = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $loc);
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $loc)), fn ($l) => $l !== ''));

        return implode(', ', $lines);
    }

    /**
     * Try the most precise reading of the address first: a line with a street number, then
     * the whole text, then the street line without the number. "388113 Mono Centre Rd, Mono,
     * ON L9W 6V8" — rural addresses are often missing their house number in OpenStreetMap.
     *
     * @return array{lat:float,lng:float,label:string,query:string}|null
     */
    private static function geocode(string $loc): ?array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $loc))), fn ($l) => $l !== ''));
        $tries = [];
        foreach ($lines as $l) if (preg_match('/\d/', $l)) $tries[] = $l;
        $tries[] = implode(', ', $lines);
        if (count($lines) > 1) $tries[] = $lines[0] . ', ' . preg_replace('/^\s*\d+\s+/', '', end($lines));
        foreach ($lines as $l) if (preg_match('/^\d+\s+/', $l)) $tries[] = preg_replace('/^\s*\d+\s+/', '', $l);
        $tries = array_values(array_unique(array_filter($tries)));

        foreach ($tries as $q) {
            $r = Http::withHeaders(['User-Agent' => self::UA, 'Accept-Language' => 'en'])->timeout(12)
                ->get('https://nominatim.openstreetmap.org/search', ['format' => 'jsonv2', 'limit' => 1, 'countrycodes' => 'ca,us', 'q' => $q]);
            $j = $r->ok() ? $r->json() : null;
            if (is_array($j) && ! empty($j[0]['lat'])) {
                return ['lat' => (float) $j[0]['lat'], 'lng' => (float) $j[0]['lon'], 'label' => (string) ($j[0]['display_name'] ?? $q), 'query' => $q];
            }
            usleep(1100000);   // Nominatim: at most one request a second
        }

        return null;
    }

    /** Draw W×H around the point from OpenStreetMap tiles, with a pin and the attribution. */
    private static function render(float $lat, float $lng): ?string
    {
        if (! function_exists('imagecreatetruecolor')) return null;
        $z = self::ZOOM;
        $n = 2 ** $z;
        $px = ($lng + 180) / 360 * $n * 256;
        $rad = deg2rad($lat);
        $py = (1 - log(tan($rad) + 1 / cos($rad)) / M_PI) / 2 * $n * 256;
        $x0 = $px - self::W / 2;
        $y0 = $py - self::H / 2;

        $im = imagecreatetruecolor(self::W, self::H);
        imagefill($im, 0, 0, imagecolorallocate($im, 236, 240, 244));
        $got = 0;
        for ($tx = (int) floor($x0 / 256); $tx <= (int) floor(($x0 + self::W - 1) / 256); $tx++) {
            for ($ty = (int) floor($y0 / 256); $ty <= (int) floor(($y0 + self::H - 1) / 256); $ty++) {
                $r = Http::withHeaders(['User-Agent' => self::UA])->timeout(12)->get('https://tile.openstreetmap.org/' . $z . '/' . (($tx % $n + $n) % $n) . '/' . $ty . '.png');
                if (! $r->ok()) continue;
                $tile = @imagecreatefromstring($r->body());
                if (! $tile) continue;
                imagecopy($im, $tile, (int) round($tx * 256 - $x0), (int) round($ty * 256 - $y0), 0, 0, 256, 256);
                imagedestroy($tile);
                $got++;
            }
        }
        if (! $got) { imagedestroy($im); return null; }

        // The pin: a red drop with a white ring, its point on the spot.
        $cx = (int) round(self::W / 2);
        $cy = (int) round(self::H / 2);
        $shadow = imagecolorallocatealpha($im, 0, 0, 0, 90);
        $red = imagecolorallocate($im, 220, 38, 38);
        $dark = imagecolorallocate($im, 153, 27, 27);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefilledellipse($im, $cx, $cy + 2, 16, 6, $shadow);
        imagefilledpolygon($im, [$cx - 11, $cy - 22, $cx + 11, $cy - 22, $cx, $cy], $dark);
        imagefilledellipse($im, $cx, $cy - 26, 30, 30, $dark);
        imagefilledellipse($im, $cx, $cy - 26, 26, 26, $red);
        imagefilledpolygon($im, [$cx - 9, $cy - 21, $cx + 9, $cy - 21, $cx, $cy - 3], $red);
        imagefilledellipse($im, $cx, $cy - 26, 10, 10, $white);

        // Attribution, as the tile licence requires.
        $txt = '(c) OpenStreetMap contributors';
        $fw = imagefontwidth(2) * strlen($txt);
        imagefilledrectangle($im, self::W - $fw - 10, self::H - 18, self::W, self::H, imagecolorallocatealpha($im, 255, 255, 255, 30));
        imagestring($im, 2, self::W - $fw - 5, self::H - 16, $txt, imagecolorallocate($im, 51, 65, 85));

        ob_start();
        imagepng($im, null, 6);
        imagedestroy($im);

        return (string) ob_get_clean();
    }
}
