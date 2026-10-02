<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * "New sign-in to your account" — the bank-style alert (2026-10-01).
 *
 * Anthony asked for a flag when an account is used from two places; of the forms of it
 * (impossible travel, concurrent sessions, new device/location) he chose the last, sent
 * to the user only. So: when a sign-in comes from a DEVICE (browser + system) or an AREA
 * (province/state + country) this account has not used before, the owner gets an email
 * saying when, on what, and roughly where, and what to do if it was not them.
 *
 * Deliberately coarse, because the inputs are:
 *   · device = browser family + OS family. A Chrome update is not a new device.
 *   · area = REGION, not city. Mobile data moves a phone between cities all day; a
 *     city-level rule would email somebody on most visits.
 *   · location comes from App\Support\GeoIp, on this server — no lookup service sees
 *     anybody's IP.
 *
 * Never on an account's first sign-in (nothing to compare with — an invite being
 * accepted is not news), and at most one email per account per hour. The first time an
 * account is checked, its known places are seeded from the login rows already in the
 * audit log, so switching this on did not email everybody at once.
 *
 * Never throws: a sign-in must not fail because its alert could not be worked out.
 */
final class SignInAlert
{
    /** Record a sign-in; email the owner if it is from somewhere new. */
    public static function check(int $userId, Request $request, string $method = 'password'): void
    {
        try {
            self::run($userId, (string) $request->userAgent(), (string) $request->ip(), $method, true);
        } catch (\Throwable $e) {
            Log::warning('SignInAlert failed', ['user' => $userId, 'error' => $e->getMessage()]);
        }
    }

    /** Record only — for a first sign-in (invite accepted, sign-up), which is never news. */
    public static function remember(int $userId, Request $request): void
    {
        try {
            self::run($userId, (string) $request->userAgent(), (string) $request->ip(), 'first', false);
        } catch (\Throwable $e) {
        }
    }

    private static function run(int $userId, string $ua, string $ip, string $method, bool $mayAlert): void
    {
        [$dKey, $dLabel] = self::device($ua);
        $geo = GeoIp::lookup($ip);
        $rKey = self::regionKey($geo);

        self::seedFromHistory($userId);
        $known = DB::table('user_signin_places')->where('user_id', $userId)->get(['kind', 'place_key']);
        $hadHistory = $known->isNotEmpty();
        $newDevice = ! $known->contains(fn ($r) => $r->kind === 'device' && $r->place_key === $dKey);
        // An area we could not work out is not "new" — that would alert on every VPN.
        $newRegion = $rKey !== null && ! $known->contains(fn ($r) => $r->kind === 'region' && $r->place_key === $rKey);

        self::touch($userId, 'device', $dKey, $dLabel);
        if ($rKey !== null) {
            self::touch($userId, 'region', $rKey, GeoIp::label(['region' => $geo['region'] ?? null, 'country' => $geo['country'] ?? null]));
        }

        if (! $mayAlert || ! $hadHistory || (! $newDevice && ! $newRegion)) {
            return;
        }
        if (! Cache::add('signin-alert:' . $userId, 1, 3600)) {
            return;                                                  // one an hour, at most
        }

        $user = DB::table('users')->where('id', $userId)->first(['id', 'first_name', 'last_name', 'email']);
        $agencyId = AuditScope::ownAgency($userId);
        $what = $newDevice && $newRegion ? 'new device and location' : ($newDevice ? 'new device' : 'new location');

        Audit::write([
            'user_id' => $userId,
            'agency_id' => $agencyId,
            'action' => 'signin.new_' . ($newDevice && $newRegion ? 'device_and_location' : ($newDevice ? 'device' : 'location')),
            'payload' => json_encode(['device' => $dLabel, 'location' => GeoIp::label($geo), 'method' => $method,
                'emailed' => (bool) ($user && $user->email)]),
            'ip_address' => $ip,
            'user_agent' => mb_substr($ua, 0, 255),
        ]);

        if (! $user || ! $user->email) {
            return;                                                  // nobody to tell; the audit row stands
        }
        self::email($agencyId, $user, $dLabel, GeoIp::label($geo), $ip, $what);
    }

    private static function email(?int $agencyId, object $user, string $device, string $where, string $ip, string $what): void
    {
        $html = self::renderEmail($agencyId, $user, $device, $where, $ip, $what);
        $to = (string) $user->email;
        $name = trim($user->first_name . ' ' . $user->last_name);
        dispatch(function () use ($agencyId, $to, $name, $html) {
            AgencyMailer::forAgency($agencyId)->html($html, function ($m) use ($to, $name) {
                $m->to($to, $name ?: null)->from('noreply@kiddietrac.com', 'KiddieTrac')->subject('New sign-in to your KiddieTrac account');
            });
        })->onQueue('mail');
    }

    /** The email itself — public so a sample can be sent exactly as users receive it. */
    public static function renderEmail(?int $agencyId, object $user, string $device, string $where, string $ip, string $what): string
    {
        $tz = AgencyTime::tz($agencyId);
        $when = Carbon::now()->timezone($tz)->format('l, F j \a\t g:i A') . ' (' . Carbon::now()->timezone($tz)->format('T') . ')';
        $first = trim((string) $user->first_name) ?: 'there';
        $portal = 'https://app.kiddietrac.com';
        $row = fn ($k, $v) => '<tr><td style="padding:6px 14px 6px 0;color:#64748B;font-size:13.5px;white-space:nowrap;vertical-align:top;">' . e($k)
            . '</td><td style="padding:6px 0;color:#0F172A;font-size:14px;font-weight:600;">' . e($v) . '</td></tr>';

        $html = EmailTemplate::wrap($agencyId,
            '<p style="margin:0 0 14px;font-size:15px;line-height:1.6;">Hi ' . e($first) . ', your KiddieTrac account was just signed in to from a '
            . e($what) . '.</p>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;">'
            . $row('When', $when) . $row('Device', $device) . $row('Near', $where) . $row('IP address', $ip) . '</table>'
            . '<p style="margin:0 0 10px;font-size:14.5px;line-height:1.6;"><strong>If this was you</strong>, there is nothing to do.</p>'
            . '<p style="margin:0 0 14px;font-size:14.5px;line-height:1.6;"><strong>If it was not you</strong>, change your password now — '
            . 'that signs out every other device. Open KiddieTrac, then Settings → Security. If you cannot sign in, use '
            . '<a href="' . $portal . '/index.html#forgot" style="color:#1F6080;">Forgot password</a>.</p>'
            . '<p style="margin:14px 0 0;font-size:12px;color:#94A3B8;line-height:1.5;">Location is approximate and based on the network, '
            . 'so it can show a nearby town. IP geolocation by <a href="https://db-ip.com" style="color:#94A3B8;">DB-IP</a>.</p>',
            ['eyebrow' => 'ACCOUNT SECURITY', 'title' => 'New sign-in to your account', 'subtitle' => 'Security',
             'preheader' => 'Signed in from a ' . $what . ': ' . $device . ', near ' . $where]);

        return $html;
    }

    /** ["chrome|android", "Chrome on Android"] */
    public static function device(string $ua): array
    {
        $os = 'Unknown system';
        if (preg_match('/iPad/i', $ua)) { $os = 'iPad'; }
        elseif (preg_match('/iPhone|iPod/i', $ua)) { $os = 'iPhone'; }
        elseif (preg_match('/Android/i', $ua)) { $os = 'Android'; }
        elseif (preg_match('/CrOS/i', $ua)) { $os = 'ChromeOS'; }
        elseif (preg_match('/Windows/i', $ua)) { $os = 'Windows'; }
        elseif (preg_match('/Macintosh|Mac OS X/i', $ua)) { $os = 'Mac'; }
        elseif (preg_match('/Linux/i', $ua)) { $os = 'Linux'; }

        $b = 'Browser';
        if (preg_match('/; wv\)/', $ua)) { $b = 'KiddieTrac app'; }
        elseif (preg_match('/Edg\//', $ua)) { $b = 'Edge'; }
        elseif (preg_match('/SamsungBrowser/i', $ua)) { $b = 'Samsung Internet'; }
        elseif (preg_match('/OPR\/|Opera/i', $ua)) { $b = 'Opera'; }
        elseif (preg_match('/Firefox|FxiOS/i', $ua)) { $b = 'Firefox'; }
        elseif (preg_match('/CriOS|Chrome\//i', $ua)) { $b = 'Chrome'; }
        elseif (preg_match('/Safari/i', $ua)) { $b = 'Safari'; }
        elseif ($ua === '') { $b = 'Unknown browser'; }

        return [strtolower($b . '|' . $os), $b . ' on ' . $os];
    }

    private static function regionKey(?array $g): ?string
    {
        if (! $g || empty($g['country_code'])) {
            return null;
        }

        return mb_substr($g['country_code'] . '-' . ($g['region'] ?? ''), 0, 120);
    }

    private static function touch(int $userId, string $kind, string $key, string $label): void
    {
        $now = now();
        $hit = DB::table('user_signin_places')->where(['user_id' => $userId, 'kind' => $kind, 'place_key' => $key])->update(['last_seen_at' => $now]);
        if (! $hit) {
            DB::table('user_signin_places')->insertOrIgnore([
                'user_id' => $userId, 'kind' => $kind, 'place_key' => $key, 'label' => mb_substr($label, 0, 190),
                'first_seen_at' => $now, 'last_seen_at' => $now,
            ]);
        }
    }

    /** Once per account: what it has signed in from before, from the audit log's login rows. */
    private static function seedFromHistory(int $userId): void
    {
        if (DB::table('user_signin_places')->where('user_id', $userId)->exists()) {
            return;
        }
        $rows = DB::table('audit_logs')->where('user_id', $userId)->where('action', 'login')
            ->where('created_at', '>=', now()->subDays(180))->orderByDesc('id')->limit(200)
            ->get(['user_agent', 'ip_address', 'created_at']);
        foreach ($rows as $r) {
            [$k, $l] = self::device((string) $r->user_agent);
            self::touchAt($userId, 'device', $k, $l, $r->created_at);
            $g = GeoIp::lookup((string) $r->ip_address);
            $rk = self::regionKey($g);
            if ($rk !== null) {
                self::touchAt($userId, 'region', $rk, GeoIp::label(['region' => $g['region'] ?? null, 'country' => $g['country'] ?? null]), $r->created_at);
            }
        }
    }

    private static function touchAt(int $userId, string $kind, string $key, string $label, $at): void
    {
        DB::table('user_signin_places')->insertOrIgnore([
            'user_id' => $userId, 'kind' => $kind, 'place_key' => $key, 'label' => mb_substr($label, 0, 190),
            'first_seen_at' => $at, 'last_seen_at' => $at,
        ]);
    }
}
