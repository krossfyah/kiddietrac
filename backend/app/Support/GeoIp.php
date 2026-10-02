<?php

declare(strict_types=1);

namespace App\Support;

use MaxMind\Db\Reader;

/**
 * Approximate location of an IP address, from a database on this server (2026-10-01).
 *
 * The data is DB-IP's "IP to City Lite" (CC BY 4.0 — the credit goes in anything that
 * shows a location: see SignInAlert's email). It is downloaded monthly by `geoip:update`
 * into storage/app/geoip/. No lookup ever leaves the server: an IP address is personal
 * information, and a third-party API would receive one for every sign-in.
 *
 * Accuracy is city-level at best and often wrong for mobile data, which is why callers
 * should compare REGIONS (province / state), not cities.
 */
final class GeoIp
{
    public const FILE = 'geoip/dbip-city-lite.mmdb';

    private static ?Reader $reader = null;
    private static bool $tried = false;

    /** @return array{city:?string,region:?string,country:?string,country_code:?string}|null */
    public static function lookup(?string $ip): ?array
    {
        $ip = trim((string) $ip);
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null;                                   // private, loopback, reserved: nowhere
        }
        $r = self::reader();
        if (! $r) {
            return null;
        }
        try {
            $d = $r->get($ip);
        } catch (\Throwable $e) {
            return null;
        }
        if (! is_array($d)) {
            return null;
        }
        $en = fn ($node) => is_array($node) ? ($node['names']['en'] ?? null) : null;

        return [
            'city' => $en($d['city'] ?? null),
            'region' => $en(($d['subdivisions'] ?? [])[0] ?? null),
            'country' => $en($d['country'] ?? null),
            'country_code' => $d['country']['iso_code'] ?? null,
        ];
    }

    /** "Toronto, Ontario, Canada" — whatever is known, most specific first. */
    public static function label(?array $g): string
    {
        if (! $g) {
            return 'an unknown location';
        }
        $parts = array_values(array_unique(array_filter([$g['city'] ?? null, $g['region'] ?? null, $g['country'] ?? null])));

        return $parts ? implode(', ', $parts) : 'an unknown location';
    }

    private static function reader(): ?Reader
    {
        if (self::$tried) {
            return self::$reader;
        }
        self::$tried = true;
        $path = storage_path('app/' . self::FILE);
        if (! is_file($path)) {
            return null;
        }
        try {
            self::$reader = new Reader($path);
        } catch (\Throwable $e) {
            self::$reader = null;
        }

        return self::$reader;
    }
}
