<?php

namespace App\Support;

/**
 * KIDDIETRAC'S SOCIAL PAGES IN EVERY OUTBOUND EMAIL (2026-11-02, Anthony: "add our linkedin page to our
 * marketing site and all outbound emails including the youtube, facebook").
 *
 * The same three pages the marketing site footer links to. Small email-safe PNG icons hosted on the API host
 * (/email/social-*.png, brand-coloured squares with a white mark, so they read on a light AND a dark
 * background), each followed by a text label, so the row still reads with images off. No SVG, no webfonts.
 *
 * WHITE-LABEL: only mail that goes out under KiddieTrac's own brand carries them - the same rule as
 * PlayBadge::forAgency (Brand::forAgency(..)['source'] === 'kiddietrac'). A reseller or white-label agency's
 * people never see KiddieTrac's pages. App\Listeners\SocialLinksMail makes the final call at send time.
 *
 * The block is wrapped in <!--kt-social--> ... <!--/kt-social--> so the listener can find (and, for a
 * white-label recipient, remove) it.
 */
final class SocialLinks
{
    public const LINKEDIN = 'https://www.linkedin.com/company/kiddietrac';
    public const YOUTUBE = 'https://www.youtube.com/@KiddieTrac';
    public const FACEBOOK = 'https://www.facebook.com/people/KiddieTrac/61594034668577/';

    public const START = '<!--kt-social-->';
    public const END = '<!--/kt-social-->';

    private const IMG = 'https://api.kiddietrac.com/email/social-';

    /** [label, url, icon] for each page, in the marketing site's order. */
    public static function all(): array
    {
        return [
            ['LinkedIn', self::LINKEDIN, self::IMG . 'linkedin-grey-96.png'],
            ['YouTube', self::YOUTUBE, self::IMG . 'youtube-grey-96.png'],
            ['Facebook', self::FACEBOOK, self::IMG . 'facebook-grey-96.png'],
        ];
    }

    /** May this agency's mail carry KiddieTrac's pages? null/0 = KiddieTrac's own mail = yes. Fails closed. */
    public static function allowedFor(?int $agencyId): bool
    {
        if (! $agencyId) return true;
        try {
            return (Brand::forAgency($agencyId)['source'] ?? '') === 'kiddietrac';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * THE ROW (quietened 2026-11-03, Anthony: "the follow kiddietrac looks terrible, embed this into the email frame
     * in the footer and make these look better and discrete in the sense that it shouldn't stand out"). One small
     * line: a light-grey "Follow us" (13px) and three 24px grey round icons (drawn at 96px, /email/social-*-grey-96.png: grey
     * circle, white mark, so they read on light AND dark backgrounds). The alt text names the network, so the line
     * still reads with images off. $label colours that alt text; the caption is left out when $lead is ''.
     */
    public static function row(string $label = '#94A3B8', string $font = 'font-family:Arial,Helvetica,sans-serif;', string $lead = 'Follow us', string $leadColour = '#94A3B8', string $align = 'center'): string
    {
        $left = $align === 'left';
        $cells = $lead === '' ? '' : '<td class="kt-social-lead" style="padding:0 10px 0 0;vertical-align:middle;' . $font . 'font-size:13px;line-height:24px;color:' . $leadColour . ';white-space:nowrap;">'
            . htmlspecialchars($lead) . '</td>';
        foreach (self::all() as $i => [$name, $url, $icon]) {
            $u = htmlspecialchars($url, ENT_QUOTES);
            $cells .= '<td style="padding:0 ' . ($i === 2 ? '0' : '8px') . ' 0 0;vertical-align:middle;">'
                . '<a href="' . $u . '" target="_blank" title="KiddieTrac on ' . $name . '" style="display:inline-block;text-decoration:none;border:0;">'
                . '<img src="' . $icon . '" width="24" height="24" alt="' . $name . '" style="display:block;width:24px;height:24px;border:0;'
                . 'border-radius:12px;' . $font . 'font-size:10px;line-height:24px;text-align:center;color:' . $label . ';"></a></td>';
        }

        return '<table role="presentation" class="kt-social"' . ($left ? '' : ' align="center"') . ' cellpadding="0" cellspacing="0" border="0" style="margin:'
            . ($left ? '0' : '0 auto') . ';border-collapse:collapse;"><tr>' . $cells . '</tr></table>';
    }

    /** row() inside the markers the send-time listener looks for. */
    public static function block(string $inner): string
    {
        return self::START . $inner . self::END;
    }

    /**
     * For mail whose renderer has no social row of its own (added by the listener just before </body>): the same
     * quiet line, no box (2026-11-03 - it was a navy box under the email and stood out). Templates should carry
     * the row in their own footer instead; this is only the safety net.
     */
    public static function strip(): string
    {
        return self::block(
            '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;border-collapse:collapse;"><tr><td align="center" class="kt-social-n" style="padding:4px 10px 20px;">'
            . self::row()
            . '</td></tr></table>'
        );
    }

    /** Kept for the listener: the quiet line needs no dark-mode overrides (grey reads on both). */
    public static function stripCss(): string
    {
        return '';
    }

    /** The plain-text line, for a text part the sender wrote itself. */
    public static function text(): string
    {
        return 'Follow KiddieTrac: LinkedIn ' . self::LINKEDIN . ' | YouTube ' . self::YOUTUBE . ' | Facebook ' . self::FACEBOOK;
    }
}
