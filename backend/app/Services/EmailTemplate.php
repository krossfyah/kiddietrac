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
        $brand = self::brand($agency);

        $eyebrow   = htmlspecialchars($opts['eyebrow']   ?? '');
        $title     = htmlspecialchars($opts['title']     ?? $brand['product_name']);
        $subtitle  = htmlspecialchars($opts['subtitle']  ?? '');
        $preheader = htmlspecialchars($opts['preheader'] ?? '');
        $footerNote = $opts['footer_note'] ?? '';

        $logoBlock = self::logoBlock($brand);
        $footer    = self::footer($agency, $brand, $footerNote);

        $css = "font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;";

        return ''
            . '<!DOCTYPE html><html lang="en"><head>'
            . '<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>@media (max-width:620px){.kt-card{padding:18px !important;}.kt-title{font-size:20px !important;}.kt-banner{padding:20px 22px !important;}}</style>'
            . '</head>'
            . '<body style="margin:0;padding:24px 12px;background:#F4F6F9;' . $css . '">'

            // Hidden preheader (inbox snippet)
            . '<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">' . $preheader . '</div>'

            . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" width="100%" style="max-width:620px;margin:0 auto;">'

            // Banner
            . '<tr><td class="kt-banner" style="background:linear-gradient(135deg,' . $brand['primary'] . ' 0%, ' . $brand['secondary'] . ' 100%); padding:26px 30px; border-radius:16px 16px 0 0; color:#FFFFFF;">'
            . '<table cellpadding="0" cellspacing="0" border="0" width="100%">'
            . '<tr><td align="left" valign="middle">'
            . $logoBlock
            . '</td></tr>'
            . '<tr><td align="left" style="padding-top:14px;">'
            . ($eyebrow ? '<div style="font-size:11px;font-weight:700;letter-spacing:2px;color:rgba(255,255,255,.85);">' . $eyebrow . '</div>' : '')
            . '<div class="kt-title" style="font-size:22px;font-weight:800;margin-top:4px;line-height:1.15;">' . $title . '</div>'
            . ($subtitle ? '<div style="font-size:13px;opacity:.85;margin-top:4px;">' . $subtitle . '</div>' : '')
            . '</td></tr></table>'
            . '</td></tr>'

            // Body
            . '<tr><td class="kt-card" style="background:#FFFFFF;padding:28px 32px;color:#0F172A;font-size:14px;line-height:1.55;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 0 0;">'
            . $bodyHtml
            . '</td></tr>'

            // Footer
            . '<tr><td style="background:#F8FAFC;padding:18px 28px;border:1px solid #E5E7EB;border-top:none;border-radius:0 0 16px 16px;font-size:12px;color:#64748B;line-height:1.5;">'
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
    private static function brand(?object $agency): array
    {
        $primary = $agency->brand_primary_color ?? '#1F6080';
        $secondary = self::secondaryFromPrimary($primary);
        return [
            'primary' => $primary,
            'secondary' => $secondary,
            'logo_url' => $agency->brand_logo_url ?? null,
            'product_name' => $agency->name ?? 'Kiddietrac',
            'support_email' => $agency->brand_support_email ?? 'noreply@kiddietrac.com',
            'powered_by_visible' => $agency ? (bool) ($agency->powered_by_visible ?? 1) : true,
            'is_white_label' => $agency && !($agency->powered_by_visible ?? 1),
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
    private static function logoBlock(array $brand): string
    {
        $name = $brand['product_name'];
        $initial = strtoupper(mb_substr($name, 0, 1));
        if (!empty($brand['logo_url'])) {
            $url = self::absoluteUrl($brand['logo_url']);
            return '<img src="' . htmlspecialchars($url) . '" alt="' . htmlspecialchars($name) . '" height="36" style="height:36px;max-height:36px;border:0;display:block;background:rgba(255,255,255,.12);padding:4px 10px;border-radius:8px;">';
        }
        return '<div style="display:inline-block;background:rgba(255,255,255,.16);color:#FFFFFF;font-weight:800;font-size:18px;width:36px;height:36px;line-height:36px;text-align:center;border-radius:10px;">' . htmlspecialchars($initial) . '</div>';
    }

    private static function footer(?object $agency, array $brand, string $note): string
    {
        $product = htmlspecialchars($brand['product_name']);
        $support = htmlspecialchars($brand['support_email']);

        $html = '';
        if ($note) {
            $html .= '<div style="margin-bottom:8px;color:#475569;">' . $note . '</div>';
        }
        $html .= 'You\'re receiving this from <strong style="color:#0F172A;">' . $product . '</strong>.';
        if ($support) {
            $html .= ' Reach out to <a href="mailto:' . $support . '" style="color:' . $brand['primary'] . ';text-decoration:none;">' . $support . '</a> if anything looks wrong.';
        }
        // 'Powered by Kiddietrac' only when NOT on the white-label plan
        if ($brand['powered_by_visible']) {
            $html .= '<div style="margin-top:10px;padding-top:10px;border-top:1px solid #E2E8F0;font-size:11px;color:#94A3B8;">Powered by <a href="https://kiddietrac.com" style="color:#94A3B8;text-decoration:none;">Kiddietrac</a> · the childcare management platform.</div>';
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
