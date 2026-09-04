<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Where an action came from (2026-07-14).
 *
 * The audit log shows an IP address, which tells an auditor nothing on its own.
 * What they actually need is "was this action taken from Canada, or from a
 * country nobody at this agency has ever worked from?".
 *
 * Resolved from the IP at read time and cached for a week (an IP's country does
 * not move). Never throws and never blocks the page: if the lookup is unavailable
 * we fall back to the raw IP.
 */
final class GeoIp
{
    private const TTL = 604800;   // 7 days

    /** "Toronto, Canada 🇨🇦" — or the IP itself if we can't place it. */
    public static function locate(?string $ip): string
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return '—';
        }

        // Written by a cron, the scheduler or a queue worker -- there is no address
        // to place, and it is not an unknown either. See App\Support\Audit.
        if ($ip === 'system') {
            return 'Scheduled task';
        }

        if (self::isPrivate($ip)) {
            return 'Internal network';
        }

        $hit = Cache::remember('geoip:' . $ip, self::TTL, function () use ($ip) {
            try {
                // ip-api.com: free, no key, 45 req/min — and we only ever ask once
                // per IP per week.
                $res = Http::timeout(3)->get("http://ip-api.com/json/{$ip}", [
                    'fields' => 'status,country,countryCode,city',
                ]);

                if (! $res->ok()) {
                    return null;
                }

                $d = $res->json();
                if (($d['status'] ?? '') !== 'success') {
                    return null;
                }

                return [
                    'city' => $d['city'] ?? null,
                    'country' => $d['country'] ?? null,
                    'code' => $d['countryCode'] ?? null,
                ];
            } catch (\Throwable $e) {
                return null;   // cached as null → we won't hammer a dead service
            }
        });

        if (! $hit || empty($hit['country'])) {
            return $ip;
        }

        $flag = self::flag($hit['code'] ?? '');
        $place = $hit['city'] ? ($hit['city'] . ', ' . $hit['country']) : $hit['country'];

        return trim($place . ' ' . $flag);
    }

    /**
     * Resolve many addresses in ONE request, before anything asks for them.
     *
     * locate() makes a blocking HTTP call per uncached address. That is fine for a
     * single row and ruinous for a page of them: the audit log resolved its IPs
     * inside a map() over 50 rows and took 10.7 seconds doing it, against SQL that
     * runs in under 4 ms.
     *
     * ip-api.com takes up to 100 addresses per POST. Everything here writes the same
     * cache keys locate() reads, so callers do not change -- they just stop waiting.
     */
    public static function warm(array $ips): void
    {
        $want = [];
        foreach ($ips as $ip) {
            $ip = trim((string) $ip);
            if ($ip === '' || $ip === 'system' || self::isPrivate($ip)) {
                continue;
            }
            if (Cache::has('geoip:' . $ip)) {
                continue;
            }
            $want[$ip] = true;
        }

        $want = array_keys($want);
        if ($want === []) {
            return;
        }

        foreach (array_chunk($want, 100) as $chunk) {
            try {
                $res = Http::timeout(5)->post(
                    'http://ip-api.com/batch?fields=status,country,countryCode,city,query',
                    array_values($chunk)
                );

                if (! $res->ok()) {
                    continue;
                }

                $seen = [];
                foreach ((array) $res->json() as $d) {
                    $q = $d['query'] ?? null;
                    if (! $q) {
                        continue;
                    }
                    $seen[$q] = true;
                    Cache::put('geoip:' . $q, ($d['status'] ?? '') === 'success' ? [
                        'city' => $d['city'] ?? null,
                        'country' => $d['country'] ?? null,
                        'code' => $d['countryCode'] ?? null,
                    ] : null, self::TTL);
                }

                // Anything the service skipped is cached as a miss too, so the page
                // does not fall back to a per-row lookup for it a moment later.
                foreach ($chunk as $ip) {
                    if (! isset($seen[$ip])) {
                        Cache::put('geoip:' . $ip, null, self::TTL);
                    }
                }
            } catch (\Throwable $e) {
                // Never let a location lookup break the page it decorates.
            }
        }
    }

    private static function isPrivate(string $ip): bool
    {
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }

    /** ISO country code → flag emoji (regional indicator letters). */
    private static function flag(string $code): string
    {
        $code = strtoupper(trim($code));
        if (strlen($code) !== 2) {
            return '';
        }

        $out = '';
        foreach (str_split($code) as $ch) {
            $out .= mb_chr(0x1F1E6 + (ord($ch) - ord('A')), 'UTF-8');
        }

        return $out;
    }
}
