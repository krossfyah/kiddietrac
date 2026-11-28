<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A small map of where a walk went, as a PNG a mail client will actually show.
 *
 * 2026-11-28: maps drawn from now on are PRIVATE (see WHERE THE MAPS LIVE below). The next paragraph describes
 * the LEGACY public maps, which stay public by decision.
 *
 * Email cannot authenticate, so the image has to be fetchable without a session. That
 * makes the FILENAME the access control: a 40-character random token per walk, which is
 * not enumerable and is not derived from any id. This is the same reasoning as an
 * unsubscribe link. It is deliberately not the /help-img treatment, where predictable
 * names under a public directory published one customer's children to everybody.
 *
 * Tiles come from OpenStreetMap, composited locally with GD rather than handed to a
 * third-party static-map service, so no route ever leaves this host to somebody else's
 * API. OSM's licence requires the attribution drawn in the corner; do not remove it.
 *
 * Raster, not SVG: SVG in email renders as a broken image in several clients.
 */
final class WalkMap
{
    private const W = 600;
    private const H = 300;
    private const TILE = 256;
    private const UA = 'KiddieTrac/1.0 (+https://www.kiddietrac.com; info@kiddietrac.com)';

    /** An email is read next week or next month: its map link lives this long (Anthony, 2026-11-28). */
    public const EMAIL_TTL_DAYS = 30;

    /** The portal asks again on every load, so its link only has to outlive a working day. */
    public const PORTAL_TTL_HOURS = 12;

    /** Expiries round up to this, so one walk's link is identical for a while (cacheable, like ProtectedMedia). */
    private const STABLE_WINDOW = 21600;

    /*
     * WHERE THE MAPS LIVE (2026-11-28, Anthony: "Move new ones only").
     *   - LEGACY: the 32 maps drawn before this date stay in public/walk-maps/<token>.png, served by URL as before, so the
     *     emails already sent keep showing them. Decided, not an oversight: do not move them.
     *   - NEW: walk-maps/<agency>/<token>.png on the PRIVATE disk (outside the web root), handed out only as a signed,
     *     agency-tied link /api/v1/media/w/<token>: EMAIL_TTL_DAYS for emails (urlFor), PORTAL_TTL_HOURS for the portal
     *     (portalUrl, read through the signed-in, agency-scoped walk record). WalkMapFileController re-checks that the
     *     walk still belongs to the agency the link names.
     */

    /** The map link for an EMAIL (30 days), or null when there is nothing to draw. */
    public static function urlFor(int $tripId): ?string
    {
        return self::link($tripId, self::EMAIL_TTL_DAYS * 86400);
    }

    /** The map link for the PORTAL (12 hours). Callers have already scoped the walk to the signed-in person's agency. */
    public static function portalUrl(int $tripId): ?string
    {
        return self::link($tripId, self::PORTAL_TTL_HOURS * 3600);
    }

    /** The private-disk path of a new-style map. */
    public static function privatePath(int $agencyId, string $token): string
    {
        return 'walk-maps/' . $agencyId . '/' . $token . '.png';
    }

    private static function link(int $tripId, int $ttl): ?string
    {
        try {
            $trip = DB::table('field_trips')->where('id', $tripId)->first();
            if (! $trip || ! $trip->agency_id) {
                return null;
            }
            $token = $trip->map_token ?: null;
            // Legacy map: stays public by decision (2026-11-28) so old emails keep working.
            if ($token && is_file(public_path('walk-maps') . '/' . $token . '.png')) {
                return rtrim(config('app.url'), '/') . '/walk-maps/' . $token . '.png';
            }
            if (! $token || ! PrivateFiles::disk()->exists(self::privatePath((int) $trip->agency_id, $token))) {
                if (self::draw($trip, $token) === null) {
                    return null;
                }
            }

            return rtrim(config('app.url'), '/') . '/api/v1/media/w/' . self::token((int) $trip->id, (int) $trip->agency_id, $ttl);
        } catch (\Throwable $e) {
            Log::warning('walk map link failed', ['trip_id' => $tripId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /** Draw the route once, onto the private disk. Returns the walk's map token, or null when there is nothing to draw. */
    private static function draw(object $trip, ?string $token): ?string
    {
        $points = DB::table('field_trip_pings')->where('field_trip_id', $trip->id)
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['lat', 'lon', 'accuracy_m'])
            ->filter(fn ($p) => (float) $p->lat !== 0.0 || (float) $p->lon !== 0.0)
            ->filter(fn ($p) => $p->accuracy_m === null || (float) $p->accuracy_m <= 100)
            ->map(fn ($p) => [(float) $p->lat, (float) $p->lon])
            ->values()->all();

        // One fix is a dot, not a route. Two is the minimum worth drawing.
        if (count($points) < 2) {
            return null;
        }
        $png = self::render($points);
        if ($png === null) {
            return null;
        }
        $token = $token ?: Str::random(40);
        PrivateFiles::disk()->put(self::privatePath((int) $trip->agency_id, $token), $png);
        DB::table('field_trips')->where('id', $trip->id)->update(['map_token' => $token]);

        return $token;
    }

    /** payload.signature, both base64url: one path segment, nothing an HTML escaper can break (as ProtectedMedia::emailToken). */
    public static function token(int $tripId, int $agencyId, int $ttl): string
    {
        $exp = (int) (ceil((time() + $ttl) / self::STABLE_WINDOW) * self::STABLE_WINDOW);
        $payload = rtrim(strtr(base64_encode((string) json_encode(['t' => $tripId, 'a' => $agencyId, 'e' => $exp])), '+/', '-_'), '=');

        return $payload . '.' . self::signature($payload);
    }

    /** [trip id, agency id] for a good, unexpired token; null when forged, altered or expired. */
    public static function verify(?string $token): ?array
    {
        $token = (string) $token;
        if (substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        if ($payload === '' || ! hash_equals(self::signature($payload), $sig)) {
            return null;
        }
        $d = json_decode((string) base64_decode(strtr($payload, '-_', '+/'), true), true);
        if (! is_array($d) || empty($d['t']) || empty($d['a']) || empty($d['e']) || time() > (int) $d['e']) {
            return null;
        }

        return [(int) $d['t'], (int) $d['a']];
    }

    private static function signature(string $payload): string
    {
        return rtrim(strtr(base64_encode(hash_hmac('sha256', 'kt-walk-map|' . $payload, (string) config('app.key'), true)), '+/', '-_'), '=');
    }

    /** @param array<int,array{0:float,1:float}> $points */
    private static function render(array $points): ?string
    {
        $lats = array_column($points, 0);
        $lons = array_column($points, 1);

        // Largest zoom at which the whole route still fits, with a margin so the line
        // never touches the edge.
        $zoom = 10;
        for ($z = 18; $z >= 10; $z--) {
            $xs = array_map(fn ($lon) => self::xPx($lon, $z), $lons);
            $ys = array_map(fn ($lat) => self::yPx($lat, $z), $lats);
            if ((max($xs) - min($xs)) <= self::W - 60 && (max($ys) - min($ys)) <= self::H - 60) {
                $zoom = $z;
                break;
            }
        }

        $xs = array_map(fn ($lon) => self::xPx($lon, $zoom), $lons);
        $ys = array_map(fn ($lat) => self::yPx($lat, $zoom), $lats);
        $centreX = (min($xs) + max($xs)) / 2;
        $centreY = (min($ys) + max($ys)) / 2;
        $originX = $centreX - self::W / 2;
        $originY = $centreY - self::H / 2;

        $canvas = imagecreatetruecolor(self::W, self::H);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 233, 235, 238));

        $tileX0 = (int) floor($originX / self::TILE);
        $tileY0 = (int) floor($originY / self::TILE);
        $tileX1 = (int) floor(($originX + self::W) / self::TILE);
        $tileY1 = (int) floor(($originY + self::H) / self::TILE);
        $max = (1 << $zoom) - 1;

        for ($tx = $tileX0; $tx <= $tileX1; $tx++) {
            for ($ty = $tileY0; $ty <= $tileY1; $ty++) {
                if ($tx < 0 || $ty < 0 || $tx > $max || $ty > $max) {
                    continue;
                }
                $raw = self::tile($zoom, $tx, $ty);
                if ($raw === null) {
                    continue;
                }
                $img = @imagecreatefromstring($raw);
                if (! $img) {
                    continue;
                }
                imagecopy(
                    $canvas, $img,
                    (int) round($tx * self::TILE - $originX), (int) round($ty * self::TILE - $originY),
                    0, 0, self::TILE, self::TILE
                );
                imagedestroy($img);
            }
        }

        // The route: a white casing under a brand-coloured line, so it reads on any map.
        imagesetthickness($canvas, 7);
        $casing = imagecolorallocate($canvas, 255, 255, 255);
        $line = imagecolorallocate($canvas, 31, 96, 128);
        for ($pass = 0; $pass < 2; $pass++) {
            imagesetthickness($canvas, $pass === 0 ? 8 : 4);
            $colour = $pass === 0 ? $casing : $line;
            for ($i = 1; $i < count($xs); $i++) {
                imageline(
                    $canvas,
                    (int) round($xs[$i - 1] - $originX), (int) round($ys[$i - 1] - $originY),
                    (int) round($xs[$i] - $originX), (int) round($ys[$i] - $originY),
                    $colour
                );
            }
        }

        // Start and finish.
        self::dot($canvas, (int) round($xs[0] - $originX), (int) round($ys[0] - $originY), imagecolorallocate($canvas, 30, 142, 96));
        $last = count($xs) - 1;
        self::dot($canvas, (int) round($xs[$last] - $originX), (int) round($ys[$last] - $originY), imagecolorallocate($canvas, 190, 64, 56));

        // OSM requires attribution. This is a licence term, not decoration.
        $bg = imagecolorallocatealpha($canvas, 255, 255, 255, 40);
        imagefilledrectangle($canvas, self::W - 196, self::H - 16, self::W, self::H, $bg);
        imagestring($canvas, 2, self::W - 192, self::H - 15, '(c) OpenStreetMap contributors', imagecolorallocate($canvas, 60, 60, 60));

        ob_start();
        imagepng($canvas, null, 6);
        $out = ob_get_clean();
        imagedestroy($canvas);

        return $out ?: null;
    }

    private static function dot($img, int $x, int $y, int $colour): void
    {
        imagefilledellipse($img, $x, $y, 15, 15, imagecolorallocate($img, 255, 255, 255));
        imagefilledellipse($img, $x, $y, 10, 10, $colour);
    }

    /** Tiles are cached on disk: the same walk re-rendered must not re-hit OSM. */
    private static function tile(int $z, int $x, int $y): ?string
    {
        $dir = storage_path('app/osm-tiles/'.$z.'/'.$x);
        $file = $dir.'/'.$y.'.png';
        if (is_file($file) && filemtime($file) > time() - 60 * 86400) {
            return file_get_contents($file) ?: null;
        }

        $ch = curl_init("https://tile.openstreetmap.org/{$z}/{$x}/{$y}.png");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => self::UA,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200 || ! $body) {
            return null;
        }
        if (is_dir($dir) || @mkdir($dir, 0755, true)) {
            @file_put_contents($file, $body);
        }

        return $body;
    }

    private static function xPx(float $lon, int $z): float
    {
        return (($lon + 180) / 360) * (1 << $z) * self::TILE;
    }

    private static function yPx(float $lat, int $z): float
    {
        $r = deg2rad($lat);

        return (1 - log(tan($r) + 1 / cos($r)) / M_PI) / 2 * (1 << $z) * self::TILE;
    }
}
