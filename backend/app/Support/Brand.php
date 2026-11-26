<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * THE BRAND A PERSON SEES (2026-10-21).
 *
 * One answer for every surface (portal, app, emails, PDFs, texts, pushes). Resolution:
 *
 *   1. KiddieTrac's own brand, always the base.
 *   2. The agency's RESELLER, when it has one: its logos, colours, app name and support
 *      email. `hide_kiddietrac` makes every agency under it white-labelled.
 *   3. The AGENCY's own logo/colour/email on top - only when the reseller allows
 *      overrides, or when the agency has no reseller and holds the white-label licence
 *      (powered_by_visible = 0, the licence as it existed before resellers).
 *
 * `white_label` is the one flag the rest of the platform asks: true means the name
 * "KiddieTrac" must not be shown to this agency's people.
 *
 * Never reveals another tenant: forAgency() only reads the agency asked for and its own
 * reseller, and the public /branding endpoint never falls back to "the first agency".
 */
final class Brand
{
    public const KT = [
        'name' => 'KiddieTrac',
        'app_name' => 'KiddieTrac',
        'logo_square_url' => 'https://app.kiddietrac.com/icon-512.png',
        'logo_wordmark_url' => 'https://app.kiddietrac.com/logo-wordmark@2x.png',
        'splash_logo_url' => 'https://app.kiddietrac.com/logo-wordmark@2x.png',
        'favicon_url' => 'https://app.kiddietrac.com/favicon-32.png',
        'primary_color' => '#1F6080',
        'accent_color' => '#13B7CC',
        'splash_bg' => '#E7F3FB',
        'support_email' => 'info@kiddietrac.com',
    ];

    /** Keys a reseller (or an allowed agency) may set. */
    public const FIELDS = ['app_name', 'logo_square_url', 'logo_wordmark_url', 'splash_logo_url', 'favicon_url',
        'primary_color', 'accent_color', 'splash_bg', 'support_email',
        // The banner gradient (2026-10-21): start, optional middle, end, angle in degrees.
        'hero_from', 'hero_via', 'hero_to', 'hero_angle',
        // The soft tint (2026-11-26): the light wash behind the sidebar, tabs and highlighted rows. Empty = derived
        // from the brand colour in branding-loader.js (KT.brandTint).
        'soft_tint'];

    private static array $memo = [];

    /** The resolved brand for an agency (null/0 = KiddieTrac's own). */
    public static function forAgency(?int $agencyId): array
    {
        $agencyId = (int) $agencyId;
        if (isset(self::$memo['a' . $agencyId])) {
            return self::$memo['a' . $agencyId];
        }
        $b = self::KT + ['play_url' => PlayBadge::URL, 'ios_url' => PlayBadge::IOS_URL, 'source' => 'kiddietrac', 'white_label' => false, 'agency_id' => null,
            'agency_name' => null, 'reseller_id' => null, 'reseller_name' => null];
        if ($agencyId <= 0) {
            return self::$memo['a0'] = $b;
        }
        $agency = DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->first();
        if (! $agency) {
            return self::$memo['a' . $agencyId] = $b;
        }
        $b['agency_id'] = (int) $agency->id;
        $b['agency_name'] = $agency->name;

        $reseller = ! empty($agency->reseller_id)
            ? DB::table('resellers')->where('id', $agency->reseller_id)->where('status', 'active')->first()
            : null;
        $agencyWl = (int) ($agency->powered_by_visible ?? 1) === 0;

        if ($reseller) {
            $rb = self::decode($reseller->brand);
            foreach (self::FIELDS as $k) {
                if (! empty($rb[$k])) { $b[$k] = $rb[$k]; }
            }
            $b['name'] = $b['app_name'] = $rb['app_name'] ?? $reseller->name;
            $b['source'] = 'reseller';
            $b['reseller_id'] = (int) $reseller->id;
            $b['reseller_name'] = $reseller->name;
            $b['white_label'] = (bool) $reseller->hide_kiddietrac || $agencyWl;
            $agencyMay = (bool) $reseller->allow_agency_override;
        } else {
            $b['white_label'] = $agencyWl;
            $agencyMay = $agencyWl;
        }

        // The agency's own identity, where it is allowed to show.
        if ($agencyMay) {
            $settings = self::decode($agency->settings ?? null);
            $ab = is_array($settings['branding'] ?? null) ? $settings['branding'] : [];
            $logo = $agency->brand_logo_url ?: ($ab['logo_url'] ?? null);
            if ($logo) {
                $b['logo_wordmark_url'] = $logo;
                $b['splash_logo_url'] = $ab['splash_logo_url'] ?? $logo;
                if (empty($ab['logo_square_url']) && $b['source'] !== 'reseller') { $b['logo_square_url'] = $logo; }
            }
            foreach (['logo_square_url', 'favicon_url', 'accent_color', 'splash_bg', 'hero_from', 'hero_via', 'hero_to', 'hero_angle', 'soft_tint'] as $k) {
                if (! empty($ab[$k])) { $b[$k] = $ab[$k]; }
            }
            if (! empty($agency->brand_primary_color)) { $b['primary_color'] = $agency->brand_primary_color; }
            if (! empty($agency->brand_support_email)) { $b['support_email'] = $agency->brand_support_email; }
            if ($b['source'] === 'kiddietrac') {
                $b['name'] = $b['app_name'] = $agency->name;
                $b['source'] = 'agency';
            } elseif ($logo || ! empty($agency->brand_primary_color)) {
                $b['source'] = 'agency';
            }
        }
        // Only a white-labelled agency may lose the KiddieTrac name; otherwise it stays.
        if (! $b['white_label']) {
            $b['name'] = $b['app_name'] = self::KT['name'];
        }

        return self::$memo['a' . $agencyId] = self::absolute($b);
    }

    /** A reseller's brand on its own (for its panel preview and pre-login app builds). */
    public static function forReseller(int $resellerId): array
    {
        $r = DB::table('resellers')->where('id', $resellerId)->first();
        $b = self::KT + ['play_url' => PlayBadge::URL, 'ios_url' => PlayBadge::IOS_URL, 'source' => 'kiddietrac', 'white_label' => false, 'agency_id' => null, 'agency_name' => null,
            'reseller_id' => null, 'reseller_name' => null];
        if (! $r) {
            return $b;
        }
        $rb = self::decode($r->brand);
        foreach (self::FIELDS as $k) {
            if (! empty($rb[$k])) { $b[$k] = $rb[$k]; }
        }
        $b['source'] = 'reseller';
        $b['reseller_id'] = (int) $r->id;
        $b['reseller_name'] = $r->name;
        $b['white_label'] = (bool) $r->hide_kiddietrac;
        $b['name'] = $b['app_name'] = $b['white_label'] ? ($rb['app_name'] ?? $r->name) : self::KT['name'];

        return self::absolute($b);
    }

    /** May this agency show its own logo/colours/banner (the same rule forAgency applies)? */
    public static function agencyMayOverride(int $agencyId): bool
    {
        $agency = DB::table('agencies')->where('id', $agencyId)->first(['reseller_id', 'powered_by_visible']);
        if (! $agency) {
            return false;
        }
        $reseller = ! empty($agency->reseller_id)
            ? DB::table('resellers')->where('id', $agency->reseller_id)->where('status', 'active')->first(['allow_agency_override'])
            : null;
        return $reseller ? (bool) $reseller->allow_agency_override : (int) ($agency->powered_by_visible ?? 1) === 0;
    }

    /** The product name to print for this agency's people. */
    public static function name(?int $agencyId): string
    {
        return (string) self::forAgency($agencyId)['name'];
    }

    public static function whiteLabel(?int $agencyId): bool
    {
        return (bool) self::forAgency($agencyId)['white_label'];
    }

    public static function forget(): void
    {
        self::$memo = [];
    }

    /* ── Words, for everything the server writes (2026-10-21, phase 3) ─────────────
       Emails, texts, push notifications and PDFs say "KiddieTrac" in hundreds of places.
       Rather than thread a name through each, the choke points (mail listener, SMS
       gateway, push sender, PDF views) pass their text through rename(). Same rule as
       the portal: never inside an address or a link (kiddietrac.com, @kiddietrac). */
    private const NAME_RE = '/(?<![\w@.\/-])Kiddie ?[Tt]rac(?![\w-]*\.(?:com|ca|app|net)\b)(?![\w])/u';
    private const KT_MAIL_RE = '/\b(?:info|support|help|hello)@kiddietrac\.com\b/i';

    /** "KiddieTrac" -> this agency's brand name, when it is white-label; otherwise unchanged. */
    public static function rename(?string $text, ?int $agencyId): string
    {
        $text = (string) $text;
        if ($text === '' || ! $agencyId || ! self::whiteLabel($agencyId)) {
            return $text;
        }
        $b = self::forAgency($agencyId);
        $out = preg_replace(self::NAME_RE, $b['name'], $text) ?? $text;
        if (! empty($b['support_email']) && ! preg_match('/@kiddietrac\.com$/i', (string) $b['support_email'])) {
            $out = preg_replace(self::KT_MAIL_RE, $b['support_email'], $out) ?? $out;
        }
        return $out;
    }

    /* The agency a piece of output belongs to when the caller did not say: a background
       job sets it with useAgency(); a web request falls back to whoever is asking. */
    private static ?int $context = null;

    public static function useAgency(?int $agencyId): void
    {
        self::$context = $agencyId ?: null;
    }

    public static function contextAgency(): ?int
    {
        if (self::$context) {
            return self::$context;
        }
        try {
            $req = request();
            $user = $req ? $req->user() : null;
            if (! $user) return null;
            $hdr = (int) $req->header('X-Active-Agency-Id');
            if ($hdr && DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)
                ->where(function ($q) use ($hdr) {
                    $q->where('agency_id', $hdr)->orWhere('role', 'platform_admin')
                      ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $hdr)->pluck('id'));
                })->exists()) {
                return $hdr;
            }
            return AuditScope::ownAgency((int) $user->id);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The product name for a PDF or page: from an agency row/id when given, else the context. */
    public static function nameFor($agency = null): string
    {
        $id = is_object($agency) ? (int) ($agency->id ?? 0) : (int) $agency;
        return self::name($id ?: self::contextAgency());
    }

    /** The agency whose people a user belongs to (for pushes and texts addressed to a person). */
    public static function agencyOfUser(int $userId): ?int
    {
        try { return AuditScope::ownAgency($userId); } catch (\Throwable $e) { return null; }
    }

    private static function decode($json): array
    {
        $d = is_string($json) ? json_decode($json, true) : (is_array($json) ? $json : null);
        return is_array($d) ? $d : [];
    }

    /* ── The banner gradient (2026-10-21) ─────────────────────────────────────────
       Every page banner of a white-label session draws hero.css. Unset colours come from
       the brand's own primary -> accent at 135deg. `text` is 'light' (white titles) unless
       the colours are too pale for white to read, then 'dark'. */
    public static function hero(array $b): array
    {
        $hex = fn ($v) => is_string($v) && preg_match('/^#[0-9A-Fa-f]{6}$/', $v) ? strtoupper($v) : null;
        $from = $hex($b['hero_from'] ?? null) ?? $hex($b['primary_color'] ?? null) ?? self::KT['primary_color'];
        $to = $hex($b['hero_to'] ?? null) ?? $hex($b['accent_color'] ?? null) ?? self::KT['accent_color'];
        $via = $hex($b['hero_via'] ?? null);
        $angle = isset($b['hero_angle']) && is_numeric($b['hero_angle']) ? max(0, min(360, (int) $b['hero_angle'])) : 135;
        $css = 'linear-gradient(' . $angle . 'deg, ' . $from . ' 0%, ' . ($via ? $via . ' 50%, ' : '') . $to . ' 100%)';
        return ['from' => $from, 'via' => $via, 'to' => $to, 'angle' => $angle, 'css' => $css,
            'text' => self::heroText($from, $via, $to),
            'custom' => ! empty($b['hero_from']) || ! empty($b['hero_to']) || ! empty($b['hero_via']) || ! empty($b['hero_angle'])];
    }

    /** 'light' when white text reads on the banner (WCAG 3:1 for large text), else 'dark'. */
    public static function heroText(string $from, ?string $via, string $to): string
    {
        $lum = function (string $h): float {
            $c = array_map(fn ($i) => hexdec(substr($h, $i, 2)) / 255, [1, 3, 5]);
            $c = array_map(fn ($v) => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
            return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        };
        $mid = $via ?: sprintf('#%02X%02X%02X', ...array_map(fn ($i) => intdiv(hexdec(substr($from, $i, 2)) + hexdec(substr($to, $i, 2)), 2), [1, 3, 5]));
        // Titles sit on the start and middle of the banner.
        $worst = max($lum($from), $lum($mid));
        $white = 1.05 / ($worst + 0.05);
        $dark = ($worst + 0.05) / (0.0137 + 0.05);   // #0F172A
        return ($white >= 3 || $white >= $dark) ? 'light' : 'dark';
    }

    /** Uploaded files are stored as /storage/…, served from the API host. */
    /* ── The app listing (2026-11-01, Anthony: "get the app" under the web sign-in + a one-time
       popup after a browser sign-in). KiddieTrac's own brand -> KiddieTrac's Google Play listing.
       A white-label brand -> its reseller's own listing (Platform -> Resellers -> App), or null:
       never a KiddieTrac badge on a white-label page. */
    public static function playUrl(array $b): ?string
    {
        if (empty($b['white_label'])) {
            return PlayBadge::URL;
        }
        if (empty($b['reseller_id'])) {
            return null;
        }
        $app = self::decode(DB::table('resellers')->where('id', (int) $b['reseller_id'])->value('app_build'));
        $url = $app['play_listing_url'] ?? null;
        return is_string($url) && preg_match('#^https://#i', $url) ? $url : null;
    }

    /* The iPhone listing (2026-11-06: KiddieTrac is on the App Store). KiddieTrac's own brand -> KiddieTrac's App Store
       page; a white-label brand -> null (no reseller iPhone listing is recorded yet), so no KiddieTrac badge shows. */
    public static function iosUrl(array $b): ?string
    {
        return empty($b['white_label']) ? PlayBadge::IOS_URL : null;
    }

    private static function absolute(array $b): array
    {
        $b['hero'] = self::hero($b);
        $b['play_url'] = self::playUrl($b);
        $b['ios_url'] = self::iosUrl($b);
        foreach (['logo_square_url', 'logo_wordmark_url', 'splash_logo_url', 'favicon_url'] as $k) {
            if (! empty($b[$k]) && str_starts_with((string) $b[$k], '/storage/')) {
                $b[$k] = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/') . $b[$k];
            }
        }
        return $b;
    }
}
