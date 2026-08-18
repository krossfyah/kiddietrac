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
        $agency = $agencyId ? DB::table('agencies')->where('id', $agencyId)->first() : null;
        // force_brand ('kt' | 'agency') overrides white-label detection — used to
        // preview both looks. Omit for the normal per-agency behaviour.
        $brand = self::brand($agency, $opts['force_brand'] ?? null);

        $eyebrow   = htmlspecialchars($opts['eyebrow']   ?? '');
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
            $headerRow = '<tr><td style="padding:0;line-height:0;font-size:0;">'
                . '<img src="https://app.kiddietrac.com/email-header.png" alt="KiddieTrac — Smart Childcare Management Platform" width="620" style="display:block;width:100%;max-width:620px;height:auto;border:0;border-radius:16px 16px 0 0;"></td></tr>';
            $titleBlock = ($eyebrow ? '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#1BA7AC;margin-bottom:4px;">' . $eyebrow . '</div>' : '')
                . '<div class="kt-h" style="font-size:22px;font-weight:800;color:#0B2545;line-height:1.2;">' . $title . '</div>'
                . ($subtitle ? '<div style="font-size:14px;color:#64748B;margin-top:4px;">' . $subtitle . '</div>' : '')
                . '<div style="height:18px;line-height:18px;">&nbsp;</div>';
        } else {
            $headerRow = '<tr><td class="kt-banner" bgcolor="' . $brand['primary'] . '" style="background-color:' . $brand['primary'] . ';background:linear-gradient(135deg,' . $brand['primary'] . ' 0%, ' . $brand['secondary'] . ' 100%); padding:26px 30px; border-radius:16px 16px 0 0; color:#FFFFFF;">'
                . '<table cellpadding="0" cellspacing="0" border="0" width="100%"><tr><td align="left" valign="middle">' . $logoBlock . '</td></tr>'
                . '<tr><td align="left" style="padding-top:14px;">'
                . ($eyebrow ? '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:#E6EEF3;">' . $eyebrow . '</div>' : '')
                . '<div class="kt-title" style="font-size:22px;font-weight:800;margin-top:4px;line-height:1.15;color:#FFFFFF;">' . $title . '</div>'
                . ($subtitle ? '<div style="font-size:13px;color:#EAF2F7;margin-top:4px;">' . $subtitle . '</div>' : '')
                . '</td></tr></table></td></tr>';
        }

        return ''
            . '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<title>' . $title . '</title>'
            . '<style>'
            . '@media (max-width:620px){.kt-card{padding:18px !important;}.kt-title{font-size:20px !important;}.kt-banner{padding:20px 22px !important;}}'
            // Dark-mode: render a proper dark palette instead of a broken auto-invert.
            . '@media (prefers-color-scheme: dark){'
            . 'body,.kt-bg{background:#0E1726 !important;}'
            . '.kt-card{background:#152033 !important;color:#E5E7EB !important;border-color:#26344A !important;}'
            . '.kt-card a{color:#7CC3E8 !important;}'
            . '.kt-card strong,.kt-card .kt-h{color:#F1F5F9 !important;}'
            . '.kt-footer{background:#101A2B !important;color:#94A3B8 !important;border-color:#26344A !important;}'
            . '.kt-footer a{color:#7CC3E8 !important;}'
            . '.kt-footer strong{color:#CBD5E1 !important;}'
            . '.kt-logobox{background:#FFFFFF !important;}'
            . '}'
            . '</style>'
            . '</head>'
            . '<body class="kt-bg" style="margin:0;padding:24px 12px;background:#F4F6F9;' . $css . '">'

            // Hidden preheader (inbox snippet)
            . '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . $preheader . '</div>'

            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" width="100%" style="max-width:620px;margin:0 auto;">'

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

            . '</body></html>';
    }

    /**
     * A common "stat tile" pattern reused across digest emails — colour-banded
     * tile with label, big number, and a hint.
     */
    public static function statTile(string $label, string $value, string $hint = '', string $accent = '#1F6080'): string
    {
        return '<table cellpadding="0" cellspacing="0" border="0" width="100%" role="presentation" style="background:#F8FAFC;border-radius:12px;border-left:4px solid ' . $accent . ';margin-bottom:10px;">'
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
            . '<tr><td style="background:' . $p['bg'] . ';border-left:4px solid ' . $p['border'] . ';border-radius:8px;padding:12px 14px;font-size:13px;color:' . $p['fg'] . ';line-height:1.4;">'
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

        if ($whiteLabel) {
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
            . '<tr><td style="background:linear-gradient(135deg,#F0F7FB 0%,#F6F1FB 100%);border-left:4px solid #7C3AED;border-radius:12px;padding:16px 20px;">'
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
                . '</div>';
        }
        return $html;
    }

    private static function absoluteUrl(string $maybeRelative): string
    {
        if (preg_match('#^https?://#i', $maybeRelative)) return $maybeRelative;
        // Relative — assume it's served from the api host's /storage path
        $base = rtrim((string) config('app.url', 'https://api.kiddietrac.com'), '/');
        // Strip trailing /api/v1 if present so /storage/... resolves directly
        $base = preg_replace('#/api/v1/?$#', '', $base);
        return $base . '/' . ltrim($maybeRelative, '/');
    }
}
