<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Turn a GPS fix into a street address.
 *
 * The tracker stores lat/lon and nothing else, so "where are they now?" could only ever
 * be answered with a pair of decimals. A director wants a street name.
 *
 * OpenStreetMap's Nominatim does this for free, and the same project already supplies the
 * map tiles in WalkMap. Its usage policy asks for at most one request a second and a
 * User-Agent that identifies the application — both honoured here. The real protection is
 * the cache: a walk pings every few seconds from roughly the same place, and rounding to
 * four decimals (about 11 metres) means a whole outing usually costs a handful of lookups
 * rather than one per ping.
 *
 * Never throws and never blocks for long. An address is a nicety; the coordinates are the
 * fact, and they are what comes back when the lookup fails.
 */
class Geocode
{
    /** ~11 m. Finer than this just multiplies calls for no readable difference. */
    private const PRECISION = 4;

    private const TTL = 60 * 60 * 24 * 30;   // a street does not move

    /**
     * The other direction: an address to a point.
     *
     * Needed because a geofence round a home childcare property is impossible without
     * one, and the addresses were typed by people rather than picked off a map.
     *
     * A caveat worth stating plainly, since these are providers' HOME addresses: this
     * sends the address to OpenStreetMap. That is what geocoding is — there is no way
     * to turn an address into a point without asking something that has a map. It is
     * done once per property and the answer is stored, so it is not a repeated
     * disclosure, and nothing about the children or the family goes with it.
     *
     * Returns ['lat' => float, 'lon' => float] or null. Never throws.
     *
     * @return array{lat: float, lon: float}|null
     */
    public static function forward(string $address, ?string $city = null, ?string $postal = null, string $country = 'Canada'): ?array
    {
        $parts = array_values(array_filter([
            trim($address), trim((string) $city), trim((string) $postal), $country,
        ], fn ($p) => $p !== ''));
        if (count($parts) < 2) {
            return null;                 // an address alone is not enough to place
        }
        $query = implode(', ', $parts);

        return Cache::remember('geo:fwd:'.md5(mb_strtolower($query)), self::TTL, function () use ($query) {
            try {
                $url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=1'
                    .'&countrycodes=ca&q='.rawurlencode($query);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 8,
                    CURLOPT_CONNECTTIMEOUT => 4,
                    // Same as reverse(): this host has no IPv6 route and hangs without it.
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    CURLOPT_USERAGENT => 'KiddieTrac/1.0 (childcare platform; info@kiddietrac.com)',
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code !== 200 || ! $body) {
                    return null;
                }
                $j = json_decode((string) $body, true);
                if (! is_array($j) || ! isset($j[0]['lat'], $j[0]['lon'])) {
                    return null;
                }

                /* PRECISE ENOUGH TO FENCE, or nothing.
                   Nominatim answers a query it cannot place by widening until it can:
                   asked for a Canadian postal code alone it returned a point in
                   ALBERTA for an Ontario address — 2,000 km out, and confidently
                   formatted. A geofence built on that is worse than no geofence, so a
                   match that is not at least street level is refused. */
                $type = (string) ($j[0]['addresstype'] ?? $j[0]['type'] ?? '');
                $precise = ['building', 'house', 'residential', 'road', 'street',
                            'place', 'address', 'yes', 'apartments', 'commercial'];
                if ($type !== '' && ! in_array($type, $precise, true)) {
                    Log::info('Geocode::forward refused an imprecise match', [
                        'query' => $query, 'addresstype' => $type,
                    ]);

                    return null;
                }

                $lat = (float) $j[0]['lat'];
                $lon = (float) $j[0]['lon'];
                /* A point at nowhere, or outside Canada, is a bad match rather than an
                   answer — better no fence than one round the wrong building. */
                if (($lat === 0.0 && $lon === 0.0) || $lat < 41 || $lat > 84 || $lon < -142 || $lon > -52) {
                    return null;
                }

                return ['lat' => $lat, 'lon' => $lon];
            } catch (\Throwable $e) {
                Log::info('Geocode::forward failed', ['query' => $query, 'error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * A short, human address for a fix — "142 Hockley Rd, Mono" — or null.
     */
    public static function reverse($lat, $lon): ?string
    {
        if ($lat === null || $lon === null) {
            return null;
        }
        $lat = round((float) $lat, self::PRECISION);
        $lon = round((float) $lon, self::PRECISION);
        if ($lat === 0.0 && $lon === 0.0) {
            return null;
        }

        return Cache::remember('geo:'.$lat.','.$lon, self::TTL, function () use ($lat, $lon) {
            try {
                $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2'
                    .'&lat='.$lat.'&lon='.$lon.'&zoom=18&addressdetails=1';

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    // This host has no working IPv6 route; without forcing v4 the call
                    // hangs until it times out.
                    CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    // Nominatim's policy requires an identifying agent. An anonymous
                    // caller gets blocked, and rightly.
                    CURLOPT_USERAGENT => 'KiddieTrac/1.0 (childcare platform; info@kiddietrac.com)',
                ]);
                $body = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code !== 200 || ! $body) {
                    return null;
                }
                $j = json_decode((string) $body, true);
                if (! is_array($j)) {
                    return null;
                }

                return self::shorten($j);
            } catch (\Throwable $e) {
                Log::info('Reverse geocode failed', ['error' => $e->getMessage()]);

                return null;
            }
        });
    }

    /**
     * Nominatim returns the whole hierarchy down to the country. Nobody reading a walk
     * tracker needs "Ontario, Canada, L9W 6K4" on every row — the street and the town
     * are the useful part.
     */
    private static function shorten(array $j): ?string
    {
        $a = $j['address'] ?? [];

        $street = trim(implode(' ', array_filter([
            $a['house_number'] ?? null,
            $a['road'] ?? ($a['pedestrian'] ?? ($a['footway'] ?? null)),
        ])));

        $place = $a['city'] ?? ($a['town'] ?? ($a['village'] ?? ($a['municipality'] ?? ($a['hamlet'] ?? null))));

        // A park or a school is a better answer than the road running past it.
        $named = $a['leisure'] ?? ($a['amenity'] ?? ($a['building'] ?? null));
        if ($named && ! $street) {
            $street = $named;
        }

        $out = trim(implode(', ', array_filter([$street ?: null, $place ?: null])));
        if ($out !== '') {
            return $out;
        }

        // Fall back to whatever it did give us, trimmed to something readable.
        $display = (string) ($j['display_name'] ?? '');

        return $display !== '' ? implode(', ', array_slice(explode(', ', $display), 0, 3)) : null;
    }
}
