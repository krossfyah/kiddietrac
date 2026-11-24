<?php

namespace App\Support;

/**
 * "The KiddieTrac app is on Google Play" for KiddieTrac's own marketing emails (2026-10-25,
 * Anthony: "add that the kiddietrac is available on google playstore now (make this look nice
 * but keep this simple) ... all marketing emails as well").
 *
 * Google's official badge, hosted on the API host (/email/google-play-badge.png), linked to
 * the listing. Email-safe: tables, inline styles, a fixed-size image with alt text, so it reads
 * properly with images off. Only for KiddieTrac's own mail (leads, subscribers, bookings,
 * proposals) -- never an agency's mail, which may carry a white-label app of its own.
 *
 * THE APP STORE BESIDE IT (2026-11-06, Anthony: "update all emails with the apple link to download to their iphones and
 * all communication that already has the google add the apple next to it"). The iPhone app is live at IOS_URL. Every
 * card and badge pair now shows Apple's official "Download on the App Store" badge (rendered from Apple's own SVG to
 * /email/app-store-badge.png, same height as the Play badge) next to Google's. Same KiddieTrac-brand-only rule.
 */
final class PlayBadge
{
    public const URL = 'https://play.google.com/store/apps/details?id=com.kiddietrac.app';
    public const IMG = 'https://api.kiddietrac.com/email/google-play-badge.png';
    public const IOS_URL = 'https://apps.apple.com/app/kiddietrac/id6827700968';
    public const IOS_IMG = 'https://api.kiddietrac.com/email/app-store-badge.png';

    /** Just the badge, linked. */
    public static function badge(int $height = 44): string
    {
        $w = (int) round($height * 564 / 168);

        return '<a href="' . self::URL . '" style="display:inline-block;text-decoration:none;border:0">'
            . '<img src="' . self::IMG . '" width="' . $w . '" height="' . $height . '" alt="Get it on Google Play"'
            . ' style="display:block;border:0;width:' . $w . 'px;height:' . $height . 'px"></a>';
    }

    /** Apple's "Download on the App Store" badge, linked (503x168 image, shown at $height). */
    public static function appStoreBadge(int $height = 44): string
    {
        $w = (int) round($height * 503 / 168);

        return '<a href="' . self::IOS_URL . '" style="display:inline-block;text-decoration:none;border:0">'
            . '<img src="' . self::IOS_IMG . '" width="' . $w . '" height="' . $height . '" alt="Download on the App Store"'
            . ' style="display:block;border:0;width:' . $w . 'px;height:' . $height . 'px"></a>';
    }

    /** Both store badges side by side (App Store first, then Google Play); they wrap onto two lines on a narrow phone. */
    public static function badges(int $height = 44): string
    {
        $cell = fn (string $b) => '<span style="display:inline-block;vertical-align:middle;margin:4px 8px 4px 0">' . $b . '</span>';

        return $cell(self::appStoreBadge($height)) . $cell(self::badge($height));
    }

    /**
     * A small navy card: eyebrow, one line, the badge. Navy with light text so it reads the
     * same in light and dark mail clients (a pale card went grey-on-white in dark mode).
     * $font is the email's own font stack; $ink/$text are kept for callers, unused.
     */
    public static function card(string $font = 'font-family:Arial,Helvetica,sans-serif;', string $ink = '', string $text = ''): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" bgcolor="#0B1F3A" style="background:#0B1F3A;border-radius:12px">'
            . '<tr><td align="center" bgcolor="#0B1F3A" style="background:#0B1F3A;border-radius:12px;padding:20px 18px 22px;text-align:center">'
            . '<div style="' . $font . 'font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#7FD3D5 !important">New &middot; Now on iPhone and Android</div>'
            . '<div style="' . $font . 'margin:8px 0 0;font-size:17px;line-height:1.35;font-weight:700;color:#FFFFFF !important">Get the KiddieTrac app on the App Store and Google Play</div>'
            . '<div style="' . $font . 'margin:6px 0 14px;font-size:14px;line-height:1.6;color:#C9D4E3 !important">For parents and staff, on iPhone and Android. On a computer, sign in at app.kiddietrac.com.</div>'
            . self::badges() . self::reviewLine($font) . '</td></tr></table>';
    }

    /**
     * The card for an AGENCY's email (2026-11-02, Anthony: "daily and weekly summary add the google play icon and
     * link to the emails going forward for parents, educators and internally to staff"). Only when the agency mails
     * under KiddieTrac's own brand: a white-label or reseller agency has its own app, so its people are never sent
     * ours (same rule as StaffWelcome and AccountNotice). '' when it does not apply or the brand cannot be resolved.
     */
    public static function forAgency(?int $agencyId, string $font = 'font-family:Arial,Helvetica,sans-serif;'): string
    {
        try {
            if ((Brand::forAgency($agencyId)['source'] ?? 'kiddietrac') !== 'kiddietrac') return '';
            return '<div style="margin:22px 0 4px;">' . self::card($font) . '</div>';
        } catch (\Throwable $e) {
            return '';
        }
    }

    /** The Google review link (PlatformSettings marketing.google_review_url), or '' while none is saved. */
    public static function reviewUrl(): string
    {
        try { $u = trim((string) PlatformSettings::get('marketing.google_review_url', '')); } catch (\Throwable $e) { $u = ''; }
        return preg_match('#^https://#i', $u) ? $u : '';
    }

    /** "Enjoying KiddieTrac? Review us on Google" under the badge - only when the link is set. */
    public static function reviewLine(string $font = 'font-family:Arial,Helvetica,sans-serif;'): string
    {
        $u = self::reviewUrl();
        if ($u === '') return '';
        return '<div style="' . $font . 'margin:18px 0 0;padding:14px 0 0;border-top:1px solid rgba(255,255,255,.16);font-size:14px;line-height:1.5;color:#C9D4E3 !important">'
            . '<span style="color:#FBBF24 !important;letter-spacing:2px;font-size:15px">&#9733;&#9733;&#9733;&#9733;&#9733;</span><br>'
            . 'Enjoying KiddieTrac? <a href="' . htmlspecialchars($u, ENT_QUOTES) . '" style="color:#FFFFFF !important;font-weight:700;text-decoration:underline">Review us on Google</a></div>';
    }
}
