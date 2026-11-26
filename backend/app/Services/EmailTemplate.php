<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * v22p38 — Branded email template helper.
 *
 * Every outbound transactional email (digest, campaign, invoice, password
 * reset, welcome) should pass through this service so that the recipient
 * sees consistent branding for their agency. Agencies on the white-label
 * plan get their OWN logo, primary colour, and from name — Kiddietrac
 * fades to the background.
 *
 * Usage:
 *
 *   $html = EmailTemplate::wrap($agencyId, $bodyHtml, [
 *       'eyebrow' => 'DAILY DIGEST',
 *       'title'   => 'Sunshine Childcare',
 *       'subtitle' => 'Wednesday, May 20',
 *       'preheader' => '8 of 12 children signed in this morning',
 *   ]);
 *
 * The $bodyHtml argument is the page content that goes between the branded
 * header banner and the footer. Pass already-rendered HTML — the wrapper
 * does NOT escape it, so callers must escape user input themselves.
 *
 * Email-client safe: tables for layout, inline styles only, no flexbox/grid,
 * no external CSS, no JS. Works in Outlook 2016+, Gmail, Apple Mail, mobile
 * clients. Falls back gracefully when the logo URL is missing.
 */
final class EmailTemplate
{
    /**
     * Wrap content in a branded outer shell.
     *
     * @param  int|null  $agencyId   Which agency's branding to apply (null = Kiddietrac default)
     * @param  string    $bodyHtml   Content between the banner and the footer
     * @param  array     $opts       { eyebrow, title, subtitle, preheader, footer_note }
     */
    public static function wrap(?int $agencyId, string $bodyHtml, array $opts = []): string
    {
        /* The agency's own wording for this email, if it changed any (2026-10-06,
           App\Support\EmailOverrides). Nothing stored means nothing changes: the same body
           and options come back, so an unedited email is byte-for-byte what it was. */
        $ktEmailKey = null;
        // What the caller passed, kept only while a preview is recording (see built()).
        $ktIn = \App\Support\EmailOverrides::$seen !== null ? [$agencyId, $bodyHtml, $opts] : null;
        try {
            [$bodyHtml, $opts, $ktEmailKey] = \App\Support\EmailOverrides::apply($agencyId, $bodyHtml, $opts);
        } catch (\Throwable $e) {
            unset($opts['email']);
        }

        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        // force_brand ('kt' | 'agency') overrides white-label detection — used to
        // preview both looks. Omit for the normal per-agency behaviour.
        $brand = self::brand($agency, $opts['force_brand'] ?? null);
        // support_email: a mail people are ASKED to reply to (Reply-To a real inbox) must not say "don't reply,
        // noreply@ is not monitored" in its footer (no-show reschedule email, 2026-11-28).
        if (! empty($opts['support_email'])) {
            $brand['support_email'] = (string) $opts['support_email'];
        }
        // Tells App\Listeners\WhiteLabelMail whose brand the words belong to (2026-10-21).
        $__brandMarker = ($brand['is_white_label'] && $agency && ! empty($agency->id)) ? '<!--kt-brand-agency:' . (int) $agency->id . '-->' : '';

        $eyebrow   = htmlspecialchars($opts['eyebrow']   ?? '');
        /* A FEATURE'S OWN MARK beside its eyebrow (2026-10-28, Anthony: "safe arrival email sent out doesnt use the
           safe arrival icon we designed"). An absolute PNG URL - email clients will not draw SVG - shown at 20px
           before the label, in both the KiddieTrac and the white-label header. */
        if (! empty($opts['eyebrow_icon']) && $eyebrow !== '') {
            $eyebrow = '<img src="' . htmlspecialchars((string) $opts['eyebrow_icon'], ENT_QUOTES) . '" width="20" height="20" alt="" '
                . 'style="display:inline-block;width:20px;height:20px;border:0;vertical-align:-5px;margin-right:7px;">' . $eyebrow;
        }
        $title     = htmlspecialchars($opts['title']     ?? $brand['product_name']);
        $subtitle  = htmlspecialchars($opts['subtitle']  ?? '');
        $preheader = htmlspecialchars($opts['preheader'] ?? '');
        $footerNote = $opts['footer_note'] ?? '';

        $logoBlock = self::logoBlock($brand);
        $footer    = self::footer($agency, $brand, $footerNote);

        $css = "font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;";

        // Header: KiddieTrac-branded emails use the full banner image; white-label
        // agencies keep their own logo on the coloured banner.
        $ktDefaultLogo = 'https://app.kiddietrac.com/logo-wordmark.png';
        $useKtHeader = ($brand['logo_url'] === $ktDefaultLogo);
        $titleBlock = '';
        if ($useKtHeader) {
            /* THE HEADER MUST SURVIVE IMAGES BEING OFF.

               This banner used to be nothing but a remote PNG, and the eyebrow/title
               sit in the card BELOW it -- so in Gmail, which blocks remote images by
               default, the entire header vanished and the mail arrived as plain text
               on white. It looked right in Outlook only because Outlook had already
               downloaded the image. "Branded in Outlook, bare in Gmail" is that
               difference and nothing else.

               Two changes, neither of which alters how it looks once the image loads:
               a bgcolor sampled from the artwork's own left edge (#021B51), so a
               blocked image leaves a branded navy band rather than nothing, and alt
               text styled white so it is legible on that band instead of invisible.

               The source PNG was 1920x819 and 1042 KB for a 620pt-wide slot. It is
               now a 1240px JPEG at 58 KB -- the same picture, 94% lighter. */
            $headerRow = '<tr><td bgcolor="#021B51" style="padding:0;line-height:0;font-size:0;background-color:#021B51;border-radius:16px 16px 0 0;">'
                // Seasonal banner when one is in force (App\Support\EmailHeader), else the standard one.
                . '<img src="' . htmlspecialchars((string) (($opts['header_image'] ?? null) ?: \App\Support\EmailHeader::url()), ENT_QUOTES) . '" alt="KiddieTrac — Smart Childcare Management Platform" width="620" style="display:block;width:100%;height:auto;border:0;border-radius:16px 16px 0 0;color:#FFFFFF;font-size:15px;font-weight:700;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif;"></td></tr>';
            $titleBlock = ($eyebrow ? '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#1BA7AC;margin-bottom:4px;">' . $eyebrow . '</div>' : '')
                . '<div class="kt-h" style="font-size:22px;font-weight:800;color:#0B2545;line-height:1.2;">' . $title . '</div>'
                . ($subtitle ? '<div style="font-size:14px;color:#64748B;margin-top:4px;">' . $subtitle . '</div>' : '')
                . '<div style="height:18px;line-height:18px;">&nbsp;</div>';
        } else {
            /* A white-label brand's banner gradient (2026-10-21) colours the band, with dark
               text when its colours are too light for white. */
            $hero = $brand['hero'] ?? null;
            $bandBg = $hero ? $hero['from'] : $brand['primary'];
            $bandCss = $hero ? $hero['css'] : 'linear-gradient(135deg,' . $brand['primary'] . ' 0%, ' . $brand['secondary'] . ' 100%)';
            $darkBand = $hero && $hero['text'] === 'dark';
            [$tMain, $tEyebrow, $tSub] = $darkBand ? ['#0F172A', '#1E293B', '#334155'] : ['#FFFFFF', '#E6EEF3', '#EAF2F7'];
            $headerRow = '<tr><td class="kt-banner" bgcolor="' . $bandBg . '" style="background-color:' . $bandBg . ';background:' . $bandCss . '; padding:26px 30px; border-radius:16px 16px 0 0; color:' . $tMain . ';">'
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td align="left" valign="middle">' . $logoBlock . '</td></tr>'
                . '<tr><td align="left" style="padding-top:14px;">'
                . ($eyebrow ? '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:' . $tEyebrow . ';">' . $eyebrow . '</div>' : '')
                . '<div class="kt-title" style="font-size:22px;font-weight:800;margin-top:4px;line-height:1.15;color:' . $tMain . ';">' . $title . '</div>'
                . ($subtitle ? '<div style="font-size:13px;color:' . $tSub . ';margin-top:4px;">' . $subtitle . '</div>' : '')
                . '</td></tr></table></td></tr>';
        }

        /* THE INVOICE HEADER (2026-10-31, Anthony: "when invoices are sent out include agency logo and info in the email like
           how we have it for other emails ... use the invoice header that we created for kiddietrac and not our standard
           header"). KiddieTrac's billing hero (PlatformAccounts::heroHtml), carrying the SENDING agency's logo, name and
           centre contact; the card, footer, dark palette and wording overrides below are unchanged. App\Support\InvoiceMail. */
        if (! empty($opts['invoice_hero']) && is_array($opts['invoice_hero'])) {
            $headerRow = self::invoiceHeroRow($agency, $brand, $opts['invoice_hero']);
            $titleBlock = '';
        }

        $ktHtml = ''
            . '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $title . '</title>'
            . '<style>'
            . '@media (max-width:620px){.kt-card{padding:18px !important;}.kt-title{font-size:20px !important;}.kt-banner{padding:20px 22px !important;}'
            // the fixed 620 is for the desktop clients that need an attribute; a phone
            // takes the width it actually has
            . '.kt-shell{width:100% !important;}'
            . '.kh-pad{padding-left:20px !important;padding-right:20px !important;}.kh-title{font-size:22px !important;}.kh-amt{font-size:21px !important;}'
            . '.kh-logo img{height:38px !important;max-height:38px !important;}}'
            // Dark-mode: render a proper dark palette instead of a broken auto-invert.
            . '@media (prefers-color-scheme: dark){'
            . 'body,.kt-bg{background:#0E1726 !important;}'
            . '.kt-card{background:#152033 !important;color:#E5E7EB !important;border-color:#26344A !important;}'
            . '.kt-card a{color:#7CC3E8 !important;}'
            . '.kt-card strong,.kt-card .kt-h{color:#F1F5F9 !important;}'
            // Message bodies set their colours inline, which beats the .kt-card rule
            // above - that rule is not on those elements. Dark body text therefore
            // stayed dark on the dark card and became unreadable. !important in a media
            // query outranks an inline style, so the text is reclaimed explicitly.
            . '.kt-card p,.kt-card div,.kt-card span,.kt-card td,.kt-card th,.kt-card li{color:#D6DEE9 !important;}'
            /* CALLOUTS IN DARK MODE.
               calloutBox paints a LIGHT tint inline (#EFF6FF and friends) while the rule
               above forces near-white text onto every td in the card. The result was a
               box whose bold labels - "Form:", "Note from the reviewer" - were white on
               pale blue, i.e. gone. Every email that uses a callout was affected, not
               just one. The fill goes dark and the 4px tone border stays, so info /
               warning / danger / success still read apart. Specificity matters here:
               `.kt-card td` is (0,1,1), so this must be (0,2,1) to win. */
            . '.kt-card td.kt-callout,td.kt-callout{background:#1B2740 !important;color:#D6DEE9 !important;}'
            . '.kt-card td.kt-callout strong,td.kt-callout strong{color:#F1F5F9 !important;}'
            . '.kt-card td.kt-callout a,td.kt-callout a{color:#7CC3E8 !important;}'
            . '.kt-card .kt-muted,.kt-card small{color:#9AA8BC !important;}'
            // Buttons keep their own contrast - they sit on a solid brand colour, so the
            // reclaim above would make the label unreadable in the other direction.
            . '.kt-btn,.kt-btn *,.kt-card .kt-btn,.kt-card .kt-btn *{color:#FFFFFF !important;}'
            // Light info panels (#F8FAFC boxes and the like) opt in with .kt-panel.
            . '.kt-panel{background:#1B2740 !important;border-color:#2C3B54 !important;}'
            . '.kt-panel td,.kt-panel div,.kt-panel span,.kt-panel p{color:#D6DEE9 !important;}'
            // Row rules inside a panel (the parent summary's menu, 2026-11-02) were near-white lines on the dark fill.
            . '.kt-panel td{border-color:#2C3B54 !important;}'
            // Progress-bar tracks (the educator summary's score bars, 2026-11-24) were pale stripes on the dark panel.
            . '.kt-track{background:#2C3B54 !important;}'
            . '.kt-footer{background:#101A2B !important;color:#94A3B8 !important;border-color:#26344A !important;}'
            . '.kt-footer a{color:#7CC3E8 !important;}'
            . '.kt-footer strong{color:#CBD5E1 !important;}'
            // The agency block sets its colours inline (dark slate), which beat .kt-footer: the agency name
            // and address were near-invisible in dark mode (2026-10-27). Reclaimed like the card text.
            . '.kt-footer div,.kt-footer span{color:#9AA8BC !important;}.kt-footer .kt-fname{color:#E2E8F0 !important;}'
            // The footer's hairlines (border-top on its divs) stayed #E2E8F0 = bright white lines on navy (2026-11-02).
            . '.kt-footer div{border-color:#26344A !important;}'
            // KiddieTrac's LinkedIn / YouTube / Facebook row (App\Support\SocialLinks, 2026-11-02).
            . '.kt-footer .kt-social a,.kt-footer .kt-social span{color:#7CC3E8 !important;}'
            . '.kt-logobox{background:#FFFFFF !important;}'
            . '}'
            /* OUTLOOK.COM / OUTLOOK APPS IN DARK MODE (2026-10-31). They ignore prefers-color-scheme and instead mark
               what they recolour with [data-ogsc] (text) / [data-ogsb] (backgrounds). The same palette as above, so a
               reminder reads the same there as in Apple Mail (memory: emails must be dark-mode friendly). */
            . '[data-ogsb] .kt-bg,[data-ogsb] body{background:#0E1726 !important;}'
            . '[data-ogsb] .kt-card{background:#152033 !important;}'
            . '[data-ogsc] .kt-card,[data-ogsc] .kt-card p,[data-ogsc] .kt-card div,[data-ogsc] .kt-card span,[data-ogsc] .kt-card td{color:#D6DEE9 !important;}'
            . '[data-ogsc] .kt-card strong,[data-ogsc] .kt-card .kt-h{color:#F1F5F9 !important;}'
            . '[data-ogsc] .kt-card a{color:#7CC3E8 !important;}'
            . '[data-ogsb] .kt-panel,[data-ogsb] td.kt-callout{background:#1B2740 !important;}'
            . '[data-ogsb] .kt-panel td{border-color:#2C3B54 !important;}'
            . '[data-ogsb] .kt-track{background:#2C3B54 !important;}'
            . '[data-ogsc] .kt-muted{color:#9AA8BC !important;}'
            . '[data-ogsc] .kt-btn,[data-ogsc] .kt-btn *{color:#FFFFFF !important;}'
            . '[data-ogsb] .kt-footer{background:#101A2B !important;}[data-ogsb] .kt-footer div{border-color:#26344A !important;}'
            . '[data-ogsc] .kt-footer,[data-ogsc] .kt-footer div,[data-ogsc] .kt-footer span{color:#9AA8BC !important;}'
            . '[data-ogsc] .kt-footer .kt-social a,[data-ogsc] .kt-footer .kt-social span{color:#7CC3E8 !important;}'
            // Outlook dark (2026-11-02): bold text and links in the footer kept their inline dark colours (the product
            // name in "You're receiving this from", the agency phone) - same palette as the prefers-color-scheme rules.
            . '[data-ogsc] .kt-footer strong{color:#CBD5E1 !important;}[data-ogsc] .kt-footer a{color:#7CC3E8 !important;}'
            . '[data-ogsc] .kt-footer .kt-fname{color:#E2E8F0 !important;}'
            . '</style>'
            . '</head>'
            . '<body class="kt-bg" style="margin:0;padding:24px 12px;background:#F4F6F9;' . $css . '">'

            // Hidden preheader (inbox snippet)
            . '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . $preheader . '</div>'

            /* THE WIDTH CANNOT RIDE ON max-width (2026-09-24).

               Anthony: "the banner header has a space at the far right."

               It did, and only in some clients. This was ONE table at width="100%"
               with style="max-width:620px", holding a banner at width="620". Outlook
               renders through Word, which ignores max-width entirely - so the table
               took the full width of the reading pane while the image stayed at its
               620 attribute, leaving a white strip to the right of the banner and a
               card that ran past it. Reproduced exactly by neutralising max-width on
               the table alone: banner ended at 632, card at 708, a 76px gap.

               So the width is carried by the width ATTRIBUTE on a fixed inner table,
               which every client obeys including Word, and an outer full-width table
               centres it - the standard bulletproof shell. max-width stays as well for
               the clients that honour it, and .kt-shell drops to 100% on a phone.

               Nothing changes where it already looked right: in Chrome this measures
               620px centred, exactly as before. */
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;border-collapse:collapse;">'
            . '<tr><td align="center" style="padding:0;">'
            . '<table role="presentation" class="kt-shell" cellpadding="0" cellspacing="0" border="0" align="center" width="620" style="width:620px;max-width:620px;margin:0 auto;">'

            // Header (banner image for KiddieTrac, coloured logo banner for white-label)
            . $headerRow

            // Body
            . '<tr><td class="kt-card" style="background:#FFFFFF;padding:28px 32px;color:#0F172A;font-size:14px;line-height:1.55;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 0 0;">'
            . $titleBlock
            . $bodyHtml
            . '</td></tr>'

            // Footer
            . '<tr><td class="kt-footer" style="background:#F8FAFC;padding:18px 28px;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 16px 16px;font-size:12px;color:#64748B;line-height:1.5;">'
            . $footer
            . '</td></tr>'

            . '</table>'
            . '</td></tr></table>'

            . '</body></html>';

        // A preview in progress records what was built; a real send ignores this.
        \App\Support\EmailOverrides::built($ktEmailKey, $ktHtml, $ktIn);

        return $ktHtml . $__brandMarker;
    }

    /** The colours an agency's email uses: primary, its banner gradient, and whether it is white-label. */
    public static function brandColours(?int $agencyId): array
    {
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        $b = self::brand($agency);
        return ['primary' => $b['primary'], 'hero_css' => $b['hero']['css'] ?? null, 'white_label' => (bool) $b['is_white_label']];
    }

    /**
     * The invoice header (see wrap). $h: chip, chip_col, doc_title, number, amount, amount_sub, centre_id (optional).
     * KiddieTrac-branded agencies get KiddieTrac's billing gradient artwork; a white-label agency gets its own banner
     * gradient. The logo is the agency's own (the white-label brand's for a white label), on a white tile so it reads
     * in dark mode too; no logo -> its initial. Text stays inline-coloured on the band, which dark mode leaves alone.
     */
    private static function invoiceHeroRow(?object $agency, array $brand, array $h): string
    {
        $F = "font-family:'Segoe UI',-apple-system,Helvetica,Arial,sans-serif;";
        $x = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $wl = ! empty($brand['is_white_label']);

        // Who is billing: the agency's name and logo.
        $name = trim((string) ($agency->name ?? '')) ?: (string) $brand['product_name'];
        $logo = null;
        if ($wl) {
            $logo = $brand['logo_url'] ?? null;
            if (! $logo && $agency && ! empty($agency->id)) {
                try {
                    $t = \App\Support\Brand::forAgency((int) $agency->id);
                    $sq = (string) ($t['logo_square_url'] ?? '');
                    if ($sq !== '' && ! str_contains($sq, 'app.kiddietrac.com/icon-') && ! str_contains($sq, 'app.kiddietrac.com/logo-')) $logo = $sq;
                } catch (\Throwable $e) {
                }
            }
        } elseif ($agency) {
            $logo = ($agency->brand_logo_url ?? null) ?: ($agency->logo_url ?? null);
        }
        if ($logo) $logo = self::absoluteUrl((string) $logo);

        /* No centre/provider address in the header (2026-10-31, Anthony: "why are you adding the provider's address at
           the top"). For a home-childcare agency the "centre" is a provider's own home; the bill comes from the agency,
           whose details are in the footer. */
        $centreLine = '';
        $contact = [];
        if (false && ! empty($h['centre_id'])) {
            $c = DB::table('centres')->where('id', (int) $h['centre_id'])->first(['name', 'address_line1', 'city', 'province', 'postal_code', 'phone', 'email']);
            if ($c) {
                $cn = trim((string) $c->name);
                $addr = trim(implode(', ', array_filter([trim((string) $c->address_line1), trim(trim((string) $c->city) . ' ' . trim((string) $c->province)), trim((string) $c->postal_code)])), ', ');
                $centreLine = trim(($cn !== '' && strcasecmp($cn, $name) !== 0 ? $cn : '') . ($addr !== '' ? ($cn !== '' && strcasecmp($cn, $name) !== 0 ? ' · ' : '') . $addr : ''));
            }
        }
        // Questions about a bill go to the agency's billing contact - never a home provider's personal phone or email.
        if ($agency) {
            if (trim((string) ($agency->contact_phone ?? '')) !== '') $contact[] = trim((string) $agency->contact_phone);
            $am = trim((string) ($agency->contact_email ?? '')) ?: trim((string) ($agency->brand_support_email ?? ''));
            if ($am !== '') $contact[] = $am;
        }
        foreach ($contact as $i => $v) {
            $d = preg_replace('/\D/', '', $v);
            if (! str_contains($v, '@') && strlen($d) === 10) $contact[$i] = '(' . substr($d, 0, 3) . ') ' . substr($d, 3, 3) . '-' . substr($d, 6);
            elseif (! str_contains($v, '@') && strlen($d) === 11 && $d[0] === '1') $contact[$i] = '1-' . substr($d, 1, 3) . '-' . substr($d, 4, 3) . '-' . substr($d, 7);
        }

        // The band: KiddieTrac's billing artwork, or the white-label agency's own gradient.
        if ($wl) {
            $hero = $brand['hero'] ?? null;
            $bg = $hero['from'] ?? $brand['primary'];
            $bgCss = 'background:' . $bg . ';background-image:' . ($hero['css'] ?? ('linear-gradient(135deg,' . $brand['primary'] . ' 0%,' . $brand['secondary'] . ' 100%)')) . ';';
            $bgAttr = '';
            $tc = (($hero['text'] ?? 'light') === 'dark') ? '#0F172A' : '#FFFFFF';
            $line = '<tr><td bgcolor="' . $bg . '" style="font-size:0;line-height:0;height:5px;' . $bgCss . 'opacity:.85;">&nbsp;</td></tr>';
        } else {
            $bg = '#2563EB';
            $bgCss = 'background:#2563EB url(https://api.kiddietrac.com/email/billing-hero.png) center/cover no-repeat;';
            $bgAttr = ' background="https://api.kiddietrac.com/email/billing-hero.png"';
            $tc = '#FFFFFF';
            $line = '<tr><td style="font-size:0;line-height:0;padding:0;"><img src="https://api.kiddietrac.com/email/billing-line.png" width="620" height="5" alt="" style="display:block;width:100%;height:5px;border:0;"></td></tr>';
        }
        $soft = $tc === '#FFFFFF' ? 'rgba(255,255,255,.16)' : 'rgba(15,23,42,.08)';
        $edge = $tc === '#FFFFFF' ? 'rgba(255,255,255,.45)' : 'rgba(15,23,42,.25)';

        $tile = $logo
            ? '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="kt-logobox kh-logo" bgcolor="#FFFFFF" style="background:#FFFFFF;padding:8px 12px;border-radius:12px;">'
                . '<img src="' . $x($logo) . '" alt="' . $x($name) . '" height="44" style="display:block;height:44px;max-height:44px;width:auto;border:0;"></td></tr></table>'
            : '<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="kt-logobox" bgcolor="#FFFFFF" align="center" style="background:#FFFFFF;width:48px;height:48px;border-radius:12px;'
                . $F . 'font-size:22px;font-weight:800;color:' . $brand['primary'] . ';">' . $x(mb_strtoupper(mb_substr($name, 0, 1))) . '</td></tr></table>';

        $who = '<div style="' . $F . 'font-size:15.5px;font-weight:800;color:' . $tc . ';line-height:1.25;">' . $x($name) . '</div>'
            . ($centreLine !== '' ? '<div style="' . $F . 'font-size:12px;color:' . $tc . ';opacity:.9;margin-top:2px;line-height:1.4;">' . $x($centreLine) . '</div>' : '')
            . ($contact ? '<div style="' . $F . 'font-size:11.5px;color:' . $tc . ';opacity:.85;margin-top:2px;line-height:1.4;">' . $x(implode('  ·  ', $contact)) . '</div>' : '');

        $chipCol = preg_match('/^#[0-9A-Fa-f]{3,8}$/', (string) ($h['chip_col'] ?? '')) ? $h['chip_col'] : '#5EEAD4';
        $sub = trim((string) ($h['amount_sub'] ?? ''));

        return '<tr><td class="kh-pad" bgcolor="' . $bg . '"' . $bgAttr . ' style="' . $bgCss . 'padding:22px 30px 26px;border-radius:16px 16px 0 0;' . $F . '">'
            /* KIDDIETRAC KEEPS THE TOP (2026-10-31, Anthony: "the kiddietrac logo should stay at the top and ilearn is
               already at the bottom which is fine"). A KiddieTrac-branded agency's invoice carries KiddieTrac's own billing
               header - the white wordmark straight on the gradient, as on KiddieTrac's invoices (the band is dark in both
               light and dark mode, so the white logo needs no swap); the agency's logo and details stay in the footer.
               A white-label agency keeps its own logo, name and centre at the top, with no KiddieTrac mark. */
            . ($wl
                ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                    . '<td valign="middle" width="1" style="padding-right:14px;">' . $tile . '</td>'
                    . '<td valign="middle">' . $who . '</td>'
                    . '<td valign="top" align="right" style="' . $F . 'font-size:10.5px;font-weight:800;letter-spacing:2.5px;color:' . $tc . ';opacity:.9;white-space:nowrap;padding-left:8px;">' . $x($h['label'] ?? 'BILLING') . '</td>'
                    . '</tr></table>'
                : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>'
                    . '<td valign="middle"><img src="https://api.kiddietrac.com/email/logo-white.png" width="136" alt="KiddieTrac" style="display:block;width:136px;height:auto;border:0;color:#FFFFFF;font-size:18px;font-weight:800;">'
                    . '<div style="' . $F . 'font-size:11px;letter-spacing:.4px;color:#FFFFFF;opacity:.88;margin-top:4px;">Smart Childcare Management Platform</div></td>'
                    . '<td valign="middle" align="right" style="' . $F . 'font-size:11px;font-weight:700;letter-spacing:2.5px;color:#FFFFFF;opacity:.9;white-space:nowrap;">' . $x($h['label'] ?? 'BILLING') . '</td>'
                    . '</tr></table>')
            . '<div style="margin-top:20px;"><span style="display:inline-block;' . $F . 'font-size:11px;font-weight:800;letter-spacing:1.4px;color:' . $tc . ';background:' . $soft . ';border:1px solid ' . $edge . ';padding:4px 10px;border-radius:999px;">'
            . '<span style="color:' . $chipCol . ';">&#9679;</span>&nbsp;' . $x($h['chip'] ?? 'INVOICE') . '</span></div>'
            . '<div class="kh-title" style="' . $F . 'font-size:26px;line-height:1.2;font-weight:800;color:' . $tc . ';margin-top:10px;">' . $x($h['doc_title'] ?? 'Invoice')
            . (trim((string) ($h['number'] ?? '')) !== '' ? ' <span style="font-weight:600;opacity:.9;">' . $x($h['number']) . '</span>' : '') . '</div>'
            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin-top:16px;"><tr>'
            . '<td style="background:' . $soft . ';border:1px solid ' . $edge . ';border-radius:14px;padding:9px 18px;"><span class="kh-amt" style="' . $F . 'font-size:25px;font-weight:800;color:' . $tc . ';">' . $x($h['amount'] ?? '') . '</span></td>'
            . ($sub !== '' ? '<td style="' . $F . 'padding-left:14px;font-size:14px;color:' . $tc . ';font-weight:600;">' . $x($sub) . '</td>' : '')
            . '</tr></table>'
            . '</td></tr>'
            . $line;
    }

    /**
     * A common "stat tile" pattern reused across digest emails — colour-banded
     * tile with label, big number, and a hint.
     */
    public static function statTile(string $label, string $value, string $hint = '', string $accent = '#1F6080'): string
    {
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:#F8FAFC;border-radius:12px;--kt-edge:' . $accent . ';margin-bottom:10px;">'
            . '<tr><td style="padding:14px 16px;">'
            . '<div style="font-size:11px;font-weight:700;color:#64748B;letter-spacing:1px;text-transform:uppercase;">' . htmlspecialchars($label) . '</div>'
            . '<div style="font-size:24px;font-weight:800;color:#0F172A;margin-top:4px;letter-spacing:-.3px;">' . htmlspecialchars($value) . '</div>'
            . ($hint ? '<div style="font-size:12px;color:#64748B;margin-top:2px;">' . htmlspecialchars($hint) . '</div>' : '')
            . '</td></tr></table>';
    }

    /**
     * Two-column row of stat tiles for desktop, single-column on mobile via
     * email-client friendly table layout (no flex/grid).
     */
    public static function statRow(string $leftTile, string $rightTile): string
    {
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="margin-bottom:6px;">'
            . '<tr>'
            . '<td valign="top" width="50%" style="padding-right:6px;">' . $leftTile . '</td>'
            . '<td valign="top" width="50%" style="padding-left:6px;">' . $rightTile . '</td>'
            . '</tr></table>';
    }

    /**
     * A muted callout box for hints / warnings inside an email body.
     */
    public static function calloutBox(string $message, string $tone = 'info'): string
    {
        $palette = [
            'info'    => ['bg' => '#EFF6FF', 'border' => '#3B82F6', 'fg' => '#1E40AF'],
            'warning' => ['bg' => '#FEF3C7', 'border' => '#F59E0B', 'fg' => '#78350F'],
            'danger'  => ['bg' => '#FEE2E2', 'border' => '#DC2626', 'fg' => '#991B1B'],
            'success' => ['bg' => '#DCFCE7', 'border' => '#16A34A', 'fg' => '#166534'],
        ];
        $p = $palette[$tone] ?? $palette['info'];
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="margin:18px 0;">'
            . '<tr><td class="kt-callout" style="background:' . $p['bg'] . ';--kt-edge:' . $p['border'] . ';border-radius:8px;padding:12px 14px;font-size:13px;color:' . $p['fg'] . ';line-height:1.4;">'
            . $message
            . '</td></tr></table>';
    }

    /**
     * A primary CTA button — supports email-client variations (Outlook needs
     * VML for rounded corners). For simplicity we go with a flat solid button
     * which renders sensibly everywhere.
     */
    public static function button(string $label, string $href, string $colour = '#1F6080'): string
    {
        return '<div style="margin:20px 0;text-align:center;">'
            . '<a href="' . htmlspecialchars($href) . '" style="background:' . $colour . ';color:#FFFFFF;padding:12px 26px;border-radius:8px;font-weight:700;font-size:14px;text-decoration:none;display:inline-block;">' . htmlspecialchars($label) . '</a>'
            . '</div>';
    }

    /** Resolve the brand bundle to use for this agency (handles white-label fallbacks). */
    private static function brand(?object $agency, ?string $force = null): array
    {
        // A white-label agency (powered_by_visible = 0) is shown with ONLY its own
        // branding; everyone else is KiddieTrac-branded throughout. `$force` lets a
        // caller preview either look.
        $whiteLabel = $agency ? !($agency->powered_by_visible ?? 1) : false;
        if ($force === 'kt') $whiteLabel = false;
        elseif ($force === 'agency') $whiteLabel = true;

        $ktLogo = 'https://app.kiddietrac.com/logo-wordmark.png';
        $primary = ($whiteLabel ? ($agency->brand_primary_color ?? null) : null) ?: '#1F6080';

        /* RESELLERS (2026-10-21): an agency under a reseller that hides KiddieTrac is
           white-label too, and shows the RESELLER's name, logo and colour unless the
           reseller lets the agency use its own (App\Support\Brand resolves which). */
        $theme = ($agency && ! empty($agency->id)) ? \App\Support\Brand::forAgency((int) $agency->id) : null;
        if ($theme && $theme['white_label'] && $force !== 'kt') {
            $whiteLabel = true;
        }
        if ($whiteLabel && $theme && $theme['white_label']) {
            $wm = (string) $theme['logo_wordmark_url'];
            // KiddieTrac's own wordmark is never a white-label logo: no logo -> initial avatar.
            $logo = $wm !== '' && ! str_contains($wm, 'app.kiddietrac.com/logo-wordmark') ? $wm : null;
            $name = $theme['name'];
            $support = $theme['support_email'] ?: 'noreply@kiddietrac.com';
            $primary = $theme['primary_color'] ?: $primary;
            $hero = $theme['hero'] ?? null;
        } elseif ($whiteLabel) {
            // White-label with no logo on file → the agency's initial avatar, never
            // the KiddieTrac mark (that would break the white-label illusion).
            $logo = $agency->brand_logo_url ?? null;
            $name = $agency->name ?? 'Kiddietrac';
            $support = $agency->brand_support_email ?? 'noreply@kiddietrac.com';
        } else {
            // Not white-label → KiddieTrac branding throughout, regardless of any
            // agency logo on file.
            $logo = $ktLogo;
            $name = 'KiddieTrac';
            $support = 'noreply@kiddietrac.com';
        }

        return [
            'primary' => $primary,
            'secondary' => self::secondaryFromPrimary($primary),
            'logo_url' => $logo,
            'product_name' => $name,
            'support_email' => $support,
            'powered_by_visible' => !$whiteLabel,
            'is_white_label' => $whiteLabel,
            'hero' => $hero ?? null,   // the white-label banner gradient (Brand::hero), else null
        ];
    }

    /** Synthesise a darker shade of the primary for the gradient. */
    private static function secondaryFromPrimary(string $hex): string
    {
        if (!preg_match('/^#([0-9a-fA-F]{6})$/', $hex)) return '#16637A';
        $r = max(0, hexdec(substr($hex, 1, 2)) - 36);
        $g = max(0, hexdec(substr($hex, 3, 2)) - 36);
        $b = max(0, hexdec(substr($hex, 5, 2)) - 36);
        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /** Render the logo (or first-letter avatar) at the top of the banner. */
    /**
     * A warm daily inspirational quote block. Chosen deterministically by the day
     * (default seed = YYYYMMDD) so everyone gets the same quote each day and it
     * rotates daily. Pass a $seed to pin a specific day.
     */
    public static function dailyQuote(?int $seed = null): string
    {
        $quotes = [
            ['Children are not things to be molded, but people to be unfolded.', 'Jess Lair'],
            ['Play is the highest form of research.', 'Albert Einstein'],
            ['There is no such thing as other people’s children.', 'Hillary Clinton'],
            ['A child who is allowed to be disrespectful will become an adult who has no respect for anyone. Kindness, taught early, lasts a lifetime.', 'Anonymous'],
            ['If we want children to flourish, to truly educate them, we need to build them a childhood of experiences.', 'Anonymous'],
            ['To the world you may be one person, but to one child you may be the world.', 'Anonymous'],
            ['Every child deserves a champion — an adult who will never give up on them.', 'Rita Pierson'],
            ['The way we talk to our children becomes their inner voice.', 'Peggy O’Mara'],
            ['Children learn more from what you are than what you teach.', 'W.E.B. Du Bois'],
            ['It is easier to build strong children than to repair broken adults.', 'Frederick Douglass'],
            ['A hundred years from now it will not matter what my bank account was… but the world may be different because I was important in the life of a child.', 'Forest Witcraft'],
            ['Kindness is a language which the deaf can hear and the blind can see.', 'Mark Twain'],
            ['Tell me and I forget. Teach me and I remember. Involve me and I learn.', 'Benjamin Franklin'],
            ['The best way to make children good is to make them happy.', 'Oscar Wilde'],
            ['Childhood is not a race to see how quickly a child can read, write and count. It is a small window of time to learn and develop at the pace that is right for each child.', 'Magda Gerson'],
            ['Behind every young child who believes in themselves is a caring adult who believed first.', 'Anonymous'],
            ['Teaching is the greatest act of optimism.', 'Colleen Wilcox'],
            ['You are not just caring for children; you are shaping the future.', 'Anonymous'],
            ['The days are long, but the years are short.', 'Gretchen Rubin'],
            ['Love the children first, teach them second.', 'Anonymous'],
            ['A little progress each day adds up to big results.', 'Anonymous'],
            ['The heart of teaching is the teaching of heart.', 'Anonymous'],
            ['Patience and love can turn any ordinary day into a memory a child keeps forever.', 'Anonymous'],
            ['Children see magic because they look for it.', 'Christopher Moore'],
            ['Wherever you find children, you find the future being built one small moment at a time.', 'Anonymous'],
            ['What we love to do, we find time to do — and loving these children is time well spent.', 'John Lancaster Spalding'],
            ['Great things are done by a series of small things brought together.', 'Vincent van Gogh'],
            ['Be the reason a child smiles today.', 'Anonymous'],
        ];
        $seed = $seed ?? (int) date('Ymd');
        $q = $quotes[abs($seed) % count($quotes)];
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="margin:22px 0 6px;">'
            . '<tr><td class="kt-panel" bgcolor="#F3F4FB" style="background-color:#F3F4FB;background-image:linear-gradient(135deg,#F0F7FB 0%,#F6F1FB 100%);--kt-edge:#7C3AED;border-radius:12px;padding:16px 20px;">'
            . '<div style="font-size:11px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:#8B5CF6;margin-bottom:6px;">✨ A thought for today</div>'
            . '<div style="font-size:15.5px;font-style:italic;color:#334155;line-height:1.55;">“' . htmlspecialchars($q[0]) . '”</div>'
            . '<div style="font-size:12.5px;color:#7A8AA3;margin-top:8px;font-weight:700;">— ' . htmlspecialchars($q[1]) . '</div>'
            . '</td></tr></table>';
    }

    private static function logoBlock(array $brand): string
    {
        $name = $brand['product_name'];
        $initial = strtoupper(mb_substr($name, 0, 1));
        if (!empty($brand['logo_url'])) {
            $url = self::absoluteUrl($brand['logo_url']);
            return '<table cellpadding="0" cellspacing="0" border="0" role="presentation"><tr><td class="kt-logobox" style="background:#FFFFFF;padding:13px 22px;border-radius:12px;box-shadow:0 3px 10px rgba(15,23,42,.14);"><img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '" height="60" style="height:60px;max-height:60px;border:0;display:block;"></td></tr></table>';
        }
        return '<div style="display:inline-block;background:#FFFFFF;color:' . $brand['primary'] . ';font-weight:800;font-size:20px;width:44px;height:44px;line-height:44px;text-align:center;border-radius:12px;box-shadow:0 2px 6px rgba(15,23,42,.10);">' . htmlspecialchars($initial) . '</div>';
    }

    private static function footer(?object $agency, array $brand, string $note): string
    {
        $product = htmlspecialchars($brand['product_name']);
        $support = htmlspecialchars($brand['support_email']);

        $supportRaw = $brand['support_email'];
        $isNoReply = empty($supportRaw) || strtolower($supportRaw) === 'noreply@kiddietrac.com';

        $html = '';

        // The AGENCY first. Their logo is stored (brand_logo_url) but was only ever
        // used for white-labelled agencies, so an email from iLearn arrived carrying
        // nothing but KiddieTrac's mark. A parent should see who is writing to them.
        if ($agency) {
            $agencyName = htmlspecialchars((string) ($agency->name ?? ''));
            $agencyLogo = $agency->brand_logo_url ?? ($agency->logo_url ?? null);
            if ($agencyLogo) {
                // Stored paths are relative to the app host; emails need absolute URLs.
                if (! preg_match('~^https?://~i', (string) $agencyLogo)) {
                    $agencyLogo = 'https://app.kiddietrac.com/' . ltrim((string) $agencyLogo, '/');
                }
            }
            // Everything the agency has published about itself. All of this was stored
            // and none of it was shown, so an email from a childcare provider carried
            // less identifying detail than a shop receipt.
            $settings = [];
            try { $settings = json_decode((string) ($agency->settings ?? ''), true) ?: []; } catch (\Throwable $e) {}

            $lines = [];
            // Address is stored as a multi-line string. Escape FIRST, then turn the
            // newlines into <br> — the other order would let stored text inject markup.
            $addr = trim((string) ($settings['brand_address'] ?? ''));
            if ($addr !== '') {
                $lines[] = nl2br(htmlspecialchars($addr), false);
            }
            $phone = trim((string) ($agency->contact_phone ?? ''));
            if ($phone !== '') {
                // tel: needs the digits; the label keeps whatever the agency typed.
                $telHref = preg_replace('/[^0-9+]/', '', $phone);
                $lines[] = '<a href="tel:' . htmlspecialchars($telHref) . '" style="color:#475569;text-decoration:none;">'
                    . htmlspecialchars($phone) . '</a>';
            }
            $mail = trim((string) ($agency->contact_email ?? $agency->brand_support_email ?? ''));
            if ($mail !== '') {
                $lines[] = '<a href="mailto:' . htmlspecialchars($mail) . '" style="color:#1F6FB2;text-decoration:none;">'
                    . htmlspecialchars($mail) . '</a>';
            }
            foreach ([['brand_privacy_url', 'Privacy policy'], ['brand_terms_url', 'Terms']] as [$key, $label]) {
                $url = trim((string) ($settings[$key] ?? ''));
                if ($url !== '') {
                    $lines[] = '<a href="' . htmlspecialchars($url) . '" style="color:#1F6FB2;text-decoration:none;">'
                        . $label . '</a>';
                }
            }

            if ($agencyName !== '' || $agencyLogo || $lines) {
                // text-align:left explicitly — the footer container centres its
                // contents, and this is contact information now, not a decorative mark.
                $html .= '<div style="text-align:left;margin-bottom:14px;padding-bottom:12px;border-bottom:1px solid #E7EBF0;">';
                if ($agencyLogo) {
                    $html .= '<img src="' . htmlspecialchars($agencyLogo) . '" alt="' . $agencyName
                        . '" height="34" style="max-height:34px;width:auto;display:block;margin:0 0 8px;border:0;">';
                }
                if ($agencyName !== '') {
                    $html .= '<div class="kt-fname" style="font-weight:800;color:#0F172A;font-size:13.5px;margin-bottom:3px;">' . $agencyName . '</div>';
                }
                if ($lines) {
                    $html .= '<div style="color:#475569;font-size:12px;line-height:1.65;">' . implode('<br>', $lines) . '</div>';
                }
                $html .= '</div>';
            }
        }

        if ($note) {
            $html .= '<div style="margin-bottom:8px;color:#475569;">' . $note . '</div>';
        }
        $html .= 'You\'re receiving this from <strong style="color:#0F172A;">' . $product . '</strong>.';
        if ($isNoReply) {
            // noreply@ is unmonitored — point people to a real contact.
            $html .= ' <span style="color:#64748B;">Please don\'t reply — <strong>noreply@kiddietrac.com</strong> is not a monitored inbox. '
                . 'For help, contact your site administrator or our sales &amp; support team at '
                . '<a href="mailto:info@kiddietrac.com" style="color:' . $brand['primary'] . ';text-decoration:none;">info@kiddietrac.com</a>.</span>';
        } else {
            $s = htmlspecialchars($supportRaw);
            $html .= ' Questions? Contact <a href="mailto:' . $s . '" style="color:' . $brand['primary'] . ';text-decoration:none;">' . $s . '</a>.';
        }
        // Legal links — every email carries Privacy & Terms.
        $primary = $brand['primary'];
        $legalStyle = 'color:' . $primary . ';text-decoration:none;';
        $html .= '<div style="margin-top:10px;font-size:11.5px;color:#94A3B8;">'
            . '<a href="https://www.kiddietrac.com/privacy" style="' . $legalStyle . '">Privacy Policy</a>'
            . ' &nbsp;·&nbsp; <a href="https://www.kiddietrac.com/terms" style="' . $legalStyle . '">Terms of Use</a>'
            . '</div>';

        // KiddieTrac platform footer — only when NOT on the white-label plan.
        if ($brand['powered_by_visible']) {
            $html .= '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #E2E8F0;font-size:11px;color:#94A3B8;line-height:1.7;">'
                . 'Powered by <a href="https://www.kiddietrac.com" style="color:#94A3B8;text-decoration:none;font-weight:700;">KiddieTrac</a> — The Smart Childcare Management Platform.<br>'
                . '🌐 <a href="https://www.kiddietrac.com" style="color:#94A3B8;text-decoration:none;">www.kiddietrac.com</a>'
                . ' &nbsp;·&nbsp; ✉️ <a href="https://www.kiddietrac.com/#subscribe" style="color:#94A3B8;text-decoration:none;">Subscribe for updates</a>'
                /* KiddieTrac's LinkedIn, YouTube and Facebook (2026-11-02, App\Support\SocialLinks). Only when this
                   agency mails under KiddieTrac's own brand (same rule as PlayBadge::forAgency); App\Listeners\
                   SocialLinksMail re-checks at send time and removes it for a white-label recipient. */
                . (\App\Support\SocialLinks::allowedFor($agency && ! empty($agency->id) ? (int) $agency->id : null)
                    ? '<div style="margin:10px 0 8px;text-align:center;">'
                        . \App\Support\SocialLinks::block(\App\Support\SocialLinks::row('#64748B', 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif;', 'Follow us', '#94A3B8', 'center')) . '</div>'
                    : '<br>')
                /* Inside the powered_by block on purpose: a white-label agency's mail
                   carries their branding, not ours, so it must not assert our copyright
                   either. The year is the sending year, not a constant to go stale. */
                . e(\App\Support\Company::copyright())
                . '</div>';
        }
        return $html;
    }

    /**
     * THE SAME FOOTER, FOR A PAGE RATHER THAN AN EMAIL (2026-09-18).
     *
     * Anthony, on the text-alert consent page: "at the bottom it shows KiddieTrac change
     * this to show the typical KiddieTrac footer".
     *
     * It said the word and nothing else. The signed pages people reach from an email -
     * consent, a form to sign, a file to upload - are the ones seen by somebody who is not
     * signed in and may not recognise us, so they are exactly where the contact route and
     * the legal links matter. Wording is kept identical to footer() above so the page and
     * the email that led to it do not say different things; it is separate because that
     * one is built around an agency's own branding block and an email's table layout.
     */
    public static function pageFooterHtml(): string
    {
        $muted = 'color:#94A3B8;text-decoration:none;';

        return '<div style="margin-top:18px;font-size:11.5px;color:#94A3B8;line-height:1.7;text-align:center;">'
            . '<div style="color:#64748B;">You\'re receiving this from <strong style="color:#475569;">KiddieTrac</strong>. '
            . 'Please don\'t reply — <strong>noreply@kiddietrac.com</strong> is not a monitored inbox. '
            . 'For help, contact your site administrator or our sales &amp; support team at '
            . '<a href="mailto:info@kiddietrac.com" style="color:#1F6080;text-decoration:none;">info@kiddietrac.com</a>.</div>'
            . '<div style="margin-top:10px;">'
            . '<a href="https://www.kiddietrac.com/privacy" style="color:#1F6080;text-decoration:none;">Privacy Policy</a>'
            . ' &nbsp;·&nbsp; <a href="https://www.kiddietrac.com/terms" style="color:#1F6080;text-decoration:none;">Terms of Use</a>'
            . '</div>'
            . '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #E2E8F0;font-size:11px;">'
            . 'Powered by <a href="https://www.kiddietrac.com" style="' . $muted . 'font-weight:700;">KiddieTrac</a> — The Smart Childcare Management Platform.<br>'
            . '🌐 <a href="https://www.kiddietrac.com" style="' . $muted . '">www.kiddietrac.com</a>'
            . ' &nbsp;·&nbsp; ✉️ <a href="https://www.kiddietrac.com/#subscribe" style="' . $muted . '">Subscribe for updates</a>'
            . '<br>' . e(\App\Support\Company::copyright())
            . '</div></div>';
    }

    private static function absoluteUrl(string $maybeRelative): string
    {
        /* A protected upload needs a signature to be fetchable at all — the raw
           /storage path is refused now. Signed for 30 days so the image still
           renders when the email is opened next week. */
        if (\App\Support\ProtectedMedia::isProtected($maybeRelative)) {
            return (string) \App\Support\ProtectedMedia::signForEmail($maybeRelative);
        }
        if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
        // Relative — assume it's served from the api host's /storage path
        $base = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/');
        // Strip trailing /api/v1 if present so /storage/... resolves directly
        $base = preg_replace('#/api/v1/?$#', '', $base);
        return $base . '/' . ltrim($maybeRelative, '/');
    }
}
