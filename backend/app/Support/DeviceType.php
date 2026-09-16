<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What kind of thing was someone using (2026-09-02).
 *
 * audit_logs.user_agent has been filled on every sign-in all along -- the string was
 * simply never read, so the activity feed said "Anthony Hosein signed in" whether that
 * happened on a phone at the door or a desktop in the office. Those are different events
 * to anyone reviewing access, and the data to tell them apart was already on the row.
 *
 * The classifier is deliberately coarse: phone / tablet / desktop, plus whether it was the
 * native app rather than a browser. User-agent sniffing is guesswork past that point, and
 * a feed that confidently names the wrong phone model is worse than one that says "Mobile".
 *
 * Order matters. iPadOS 13+ reports itself as "Macintosh", so the iPad checks must run
 * before the desktop check or every iPad is filed as a laptop.
 */
final class DeviceType
{
    public const MOBILE = 'Mobile';
    public const TABLET = 'Tablet';
    public const DESKTOP = 'Desktop';
    public const SCRIPT = 'API / script';
    public const OTHER = 'Other';

    /**
     * @return array{kind:string,label:string,icon:string,detail:string,is_app:bool}
     */
    public static function classify(?string $ua, string $platform = ''): array
    {
        $ua = trim((string) $ua);
        $platform = strtolower(trim($platform));

        if ($ua === '' && $platform === '') {
            return self::make(self::OTHER, 'Unknown device', '💻', '', false);
        }

        /* The native apps are Capacitor WebViews. Android marks them "; wv)" outright.
           iOS gives WKWebView no marker at all, so the honest test is the absence of the
           "Safari/" token that mobile Safari always sends -- and even that is inference,
           which is why the explicit platform hint from the app's own login call wins. */
        $isApp = $platform === 'ios' || $platform === 'android'
            || (bool) preg_match('/;\s*wv\)/i', $ua)
            || (bool) preg_match('/KiddieTrac/i', $ua);

        // iPad first: iPadOS 13+ says "Macintosh" and would otherwise read as a desktop.
        $isIpad = (bool) preg_match('/iPad/i', $ua)
            || (preg_match('/Macintosh/i', $ua) && ! empty($_SERVER['HTTP_SEC_CH_UA_MOBILE']));

        if ($platform === 'ios') {
            return $isIpad
                ? self::make(self::TABLET, 'iPad', '📱', 'Apple iPad', true)
                : self::make(self::MOBILE, 'iPhone', '📱', 'Apple iPhone', true);
        }

        if ($isIpad) {
            return self::make(self::TABLET, 'iPad', '📱', 'Apple iPad', $isApp);
        }
        if (preg_match('/iPhone|iPod/i', $ua)) {
            return self::make(self::MOBILE, 'iPhone', '📱', 'Apple iPhone', $isApp);
        }

        if ($platform === 'android' || preg_match('/Android/i', $ua)) {
            /* An Android tablet is an Android UA WITHOUT the "Mobile" token -- that is the
               convention Google documents, and there is no better signal in the string. */
            $isTablet = preg_match('/Android/i', $ua) && ! preg_match('/Mobile/i', $ua);

            return $isTablet
                ? self::make(self::TABLET, 'Android tablet', '📱', 'Android tablet', $isApp)
                : self::make(self::MOBILE, 'Android', '📱', 'Android phone', $isApp);
        }

        if (preg_match('/Windows NT/i', $ua)) {
            return self::make(self::DESKTOP, 'Windows', '🖥️', 'Windows desktop', false);
        }
        if (preg_match('/Macintosh|Mac OS X/i', $ua)) {
            return self::make(self::DESKTOP, 'Mac', '🖥️', 'Mac desktop', false);
        }
        if (preg_match('/CrOS/i', $ua)) {
            return self::make(self::DESKTOP, 'Chromebook', '🖥️', 'Chromebook', false);
        }
        if (preg_match('/Linux/i', $ua)) {
            return self::make(self::DESKTOP, 'Linux', '🖥️', 'Linux desktop', false);
        }

        /* A non-browser client -- curl, a script, an integration. Named rather than
           filed as "unknown", because "someone signed in from a script" is exactly the
           line an access review needs to stop on, and silence would hide it. */
        if (preg_match('#^(curl|wget|python|php|go-http|java|okhttp|postman|axios|node)[/ -]#i', $ua)) {
            return self::make(self::SCRIPT, 'API / script', '⌨️', $ua, false);
        }

        return self::make(self::OTHER, 'Unknown device', '💻', '', $isApp);
    }

    /** The short phrase the activity feed appends, e.g. "Mobile · Android app". */
    public static function shortLabel(?string $ua, string $platform = ''): string
    {
        $d = self::classify($ua, $platform);
        if ($d['kind'] === self::OTHER) {
            return '';
        }
        if ($d['kind'] === self::SCRIPT) {
            return $d['label'];
        }

        // "Mobile" answers the question asked; the model and app-ness are the useful detail.
        $suffix = $d['is_app'] ? ' app' : '';

        return $d['kind'] . ' · ' . $d['label'] . $suffix;
    }

    /**
     * @return array{kind:string,label:string,icon:string,detail:string,is_app:bool}
     */
    private static function make(string $kind, string $label, string $icon, string $detail, bool $isApp): array
    {
        return [
            'kind' => $kind,
            'label' => $label,
            'icon' => $icon,
            'detail' => $detail,
            'is_app' => $isApp,
        ];
    }
}
