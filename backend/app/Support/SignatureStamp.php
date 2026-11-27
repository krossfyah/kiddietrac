<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * THE SIGNATURE STAMP (2026-11-27, Anthony: "when a form is signed add the ip address and location to the form
 * underneath the date signed including timestamp beside the date").
 *
 * Every signature block on a signed form or document shows, under the signer:
 *
 *     Signed: Nov 27, 2026 at 3:42 PM EST
 *     IP address: 203.0.113.24
 *     Location: Toronto, Ontario, Canada (approximate, from IP address)
 *
 * CAPTURE (at signing time, server side only): capture() reads $request->ip(). On Cloudways nginx honours
 * X-Forwarded-For only from the CDN ranges in set_real_ip_from, Varnish overwrites X-Forwarded-For with nginx's
 * X-Real-IP and Apache's mod_remoteip trusts only 127.0.0.1, so the address is the real client and a value the
 * client sends in a header is ignored. Never take an IP from the request BODY.
 *
 * LOCATION comes from the local DB-IP City Lite database (GeoIp::lookup, refreshed monthly by geoip:update). The
 * signer's address never leaves this server. It is resolved ONCE, when the signature is made, and stored beside
 * it: a later lookup would describe where that address is today, not where the signer was.
 *
 * STORED VALUES on each signature record (new columns, nothing overwritten):
 *   <slot>_ip        the address, or NULL when none was recorded
 *   <slot>_location  "Toronto, Ontario, Canada"; '' = recorded but could not be placed (private network, unknown
 *                    range, no database) -> "Location unavailable"; NULL = signed before this existed.
 *
 * WHO SEES IT: only the signed document itself (screen, PDF, print, emailed copy), and only to people who can
 * already open that document. Never add the stamp to lists, searches, digests or exports.
 */
final class SignatureStamp
{
    /** The day signing began recording the IP address and approximate location. */
    public const SINCE = '2026-11-27';

    /**
     * The signer's address and approximate place, for storing with a signature being made NOW.
     *
     * @return array{ip:?string,location:?string}
     */
    public static function capture(?Request $request = null): array
    {
        try {
            $request = $request ?: (app()->bound('request') ? request() : null);
        } catch (\Throwable $e) {
            $request = null;
        }
        // A queue job / cron has no signer and no address: record nothing rather than the server's own.
        if (! $request || (app()->runningInConsole() && ! $request->server('HTTP_HOST'))) {
            return ['ip' => null, 'location' => null];
        }
        $ip = null;
        try {
            $ip = $request->ip();
        } catch (\Throwable $e) {
            $ip = null;
        }
        $ip = $ip ? mb_substr(trim((string) $ip), 0, 45) : null;
        if ($ip === null || $ip === '') {
            return ['ip' => null, 'location' => ''];
        }

        return ['ip' => $ip, 'location' => self::locate($ip)];
    }

    /** Capture as column => value pairs for a given prefix, e.g. columns('countersigner') */
    public static function columns(string $ipColumn, string $locationColumn, ?Request $request = null): array
    {
        $c = self::capture($request);

        return [$ipColumn => $c['ip'], $locationColumn => $c['location']];
    }

    /* ── A stamp the BROWSER prints into a PDF it builds itself ──────────────────────────────────────────────
       The filled form PDF is made in the signer's browser (kt-form-filler.js) before it is uploaded, so the
       server can't write into it. issue() hands the browser the stamp to draw plus a token (HMAC over the
       subject, address, place and time); on submit redeem() checks the token against the address the SUBMIT
       came from. A match stores exactly what was printed; a mismatch (the network changed, a forged token)
       refuses the submit so the browser rebuilds the PDF - the printed stamp and the record never disagree. */
    public const TOKEN_TTL = 1800;   // seconds between drawing the stamp and submitting

    /** @return array{token:string,signed:string,ip:string,location:string,lines:string[]} */
    public static function issue(string $subject, ?int $agencyId = null, ?Request $request = null): array
    {
        $c = self::capture($request);
        $at = now()->utc()->format('Y-m-d H:i:s');
        $b = rtrim(strtr(base64_encode((string) json_encode(['s' => $subject, 'ip' => $c['ip'], 'loc' => $c['location'], 'at' => $at])), '+/', '-_'), '=');
        $l = self::lines($at, $c['ip'], $c['location'], $agencyId);

        return ['token' => $b . '.' . hash_hmac('sha256', $b, self::key())] + $l;
    }

    /**
     * null  = no token sent (an older cached page) - capture() as usual;
     * false = a token that is forged, stale, for another form, or from another address - refuse;
     * array = the stamp to store: ['ip' =>, 'location' =>, 'at' => 'Y-m-d H:i:s' UTC].
     */
    public static function redeem(?string $token, string $subject, ?Request $request = null)
    {
        $token = trim((string) $token);
        if ($token === '') {
            return null;
        }
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2 || ! hash_equals(hash_hmac('sha256', $parts[0], self::key()), $parts[1])) {
            return false;
        }
        $d = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
        if (! is_array($d) || ($d['s'] ?? null) !== $subject || empty($d['at'])) {
            return false;
        }
        try {
            $age = now()->utc()->getTimestamp() - Carbon::parse((string) $d['at'], 'UTC')->getTimestamp();
        } catch (\Throwable $e) {
            return false;
        }
        if ($age < -60 || $age > self::TOKEN_TTL) {
            return false;
        }
        $now = self::capture($request);
        if ((string) ($now['ip'] ?? '') !== (string) ($d['ip'] ?? '')) {
            return false;
        }

        return ['ip' => $d['ip'] ?? null, 'location' => $d['loc'] ?? '', 'at' => (string) $d['at']];
    }

    private static function key(): string
    {
        return hash('sha256', 'kt-signature-stamp|' . (string) config('app.key'), true);
    }

    /** "Toronto, Ontario, Canada" from the local database; '' when the address can't be placed. */
    public static function locate(?string $ip): string
    {
        try {
            $g = GeoIp::lookup($ip);
        } catch (\Throwable $e) {
            $g = null;
        }
        if (! $g) {
            return '';
        }
        // DB-IP names some cities "Toronto (Old Toronto)": the bracketed district is noise at this precision.
        $city = isset($g['city']) && is_string($g['city']) ? trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $g['city'])) : null;
        $parts = array_values(array_unique(array_filter([
            $city, $g['region'] ?? null, $g['country'] ?? null,
        ], fn ($v) => is_string($v) && trim($v) !== '')));

        return mb_substr(implode(', ', $parts), 0, 190);
    }

    /**
     * The three lines under a signature. Empty when there is no signing time.
     *
     * $hasTime=false for a column that only ever held a DATE (the time was never recorded): the date shows alone.
     *
     * @return array{signed:string,ip:string,location:string,lines:string[]}|array{}
     */
    public static function lines($signedAt, ?string $ip, ?string $location, ?int $agencyId = null, bool $hasTime = true, ?string $tz = null): array
    {
        if ($signedAt === null || $signedAt === '' || $signedAt === '0000-00-00 00:00:00') {
            return [];
        }
        $tz = $tz ?: AgencyTime::tz($agencyId);
        try {
            $at = $signedAt instanceof \DateTimeInterface
                ? Carbon::instance($signedAt)->setTimezone($tz)
                : Carbon::parse((string) $signedAt, 'UTC')->setTimezone($tz);
        } catch (\Throwable $e) {
            return [];
        }
        $signed = 'Signed: ' . ($hasTime ? $at->format('M j, Y \a\t g:i A T') : $at->format('M j, Y'));

        $ip = $ip !== null ? trim($ip) : null;
        $since = Carbon::parse(self::SINCE)->format('M j, Y');
        if ($location === null) {
            // Signed before the stamp existed (or with no request to read): say so, never guess.
            $ipLine = ($ip !== null && $ip !== '') ? 'IP address: ' . $ip : '';
            $locLine = $ipLine !== ''
                ? 'Location not recorded (signed before IP recording began on ' . $since . ')'
                : 'IP address and location not recorded (signed before IP recording began on ' . $since . ')';
        } else {
            $ipLine = ($ip !== null && $ip !== '') ? 'IP address: ' . $ip : 'IP address: not available';
            $locLine = trim($location) !== ''
                ? 'Location: ' . trim($location) . ' (approximate, from IP address)'
                : 'Location unavailable';
        }

        return [
            'signed' => $signed,
            'ip' => $ipLine,
            'location' => $locLine,
            'lines' => array_values(array_filter([$signed, $ipLine, $locLine], fn ($l) => $l !== '')),
        ];
    }

    /** The stamp as escaped HTML for a document, printout or email (works on light and dark backgrounds). */
    public static function html($signedAt, ?string $ip, ?string $location, ?int $agencyId = null, bool $hasTime = true, string $style = ''): string
    {
        $l = self::lines($signedAt, $ip, $location, $agencyId, $hasTime);
        if (! $l) {
            return '';
        }

        return self::htmlFromLines($l, $style);
    }

    public static function htmlFromLines(array $l, string $style = ''): string
    {
        if (empty($l['lines'])) {
            return '';
        }
        $css = $style !== '' ? $style : 'font-size:11px;line-height:1.5;color:#64748B;margin-top:4px';

        return '<div class="kt-sigstamp" style="' . e($css) . '">'
            . implode('<br>', array_map(fn ($x) => e($x), $l['lines']))
            . '</div>';
    }

    /**
     * For an email callout that already says when it was signed: "<br><strong>IP address:</strong> …" and
     * "<br><strong>Location:</strong> …" (the Signed line too with $withSigned).
     */
    public static function labelledHtml(array $l, bool $withSigned = false): string
    {
        if (empty($l['lines'])) {
            return '';
        }
        $out = '';
        foreach ($withSigned ? $l['lines'] : array_slice($l['lines'], 1) as $x) {
            $out .= preg_match('/^(Signed|IP address|Location): (.+)$/', $x, $m)
                ? '<br><strong>' . e($m[1]) . ':</strong> ' . e($m[2])
                : '<br>' . e($x);
        }

        return $out;
    }

    /** Plain text, one line each (FPDF, text email). */
    public static function text($signedAt, ?string $ip, ?string $location, ?int $agencyId = null, bool $hasTime = true): string
    {
        $l = self::lines($signedAt, $ip, $location, $agencyId, $hasTime);

        return $l ? implode("\n", $l['lines']) : '';
    }
}
