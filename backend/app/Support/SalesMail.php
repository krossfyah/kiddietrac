<?php

namespace App\Support;

/**
 * KiddieTrac-branded layout for the SALES TEAM's own emails (2026-10-16, Anthony: "make the
 * emails for sales lead updates etc to look better with kiddietrac branding").
 *
 * The same frame as the proposal email customers receive (SalesController::proposalEmailHtml):
 * the navy band with the light wordmark + tagline, the brand gradient strip, a white card on a
 * soft grey page. Email-client safe: tables, inline styles, hosted https images, no background
 * images; the band stays navy in Outlook / Gmail dark mode (the dark-ink logo went speckled on a
 * darkened card before).
 *
 * Everything passed in is TEXT and is escaped here, except 'html' blocks (already-built markup,
 * e.g. a transcript from bubbles()).
 *
 *   SalesMail::render([
 *     'preheader' => 'inbox preview line',
 *     'eyebrow'   => 'New sales lead',            // small label above the title
 *     'icon'      => '🎯',
 *     'title'     => 'Sunrise Montessori',
 *     'subtitle'  => 'Jane Doe · Website contact form',
 *     'meta'      => 'Fri 16 Oct 2026, 9:14 PM EDT · Anthony Hosein',
 *     'message'   => "the note / message body",  'message_label' => 'Their message',
 *     'sections'  => [['heading' => 'Contact', 'rows' => ['Email' => 'x@y', ...]], ...],
 *     'html'      => '<div>…</div>',
 *     'button'    => ['label' => 'Open lead', 'url' => 'https://…'],
 *     'alert'     => 'Due Fri 23 Oct',            // amber line, e.g. a follow-up date
 *     'note'      => 'small print under the card',
 *   ]);
 */
final class SalesMail
{
    public const NAVY = '#1E2A5E';
    public const TEAL = '#1F6080';
    public const MUTED = '#5B6B7C';
    public const BAND = '#081C41';

    private static function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    /** A label/value table. Empty values are skipped; values may be multi-line. */
    public static function rows(array $rows): string
    {
        $out = '';
        foreach ($rows as $k => $v) {
            if ($v === null || trim((string) $v) === '') {
                continue;
            }
            $val = self::e($v);
            if (filter_var((string) $v, FILTER_VALIDATE_EMAIL)) {
                $val = '<a href="mailto:' . $val . '" style="color:' . self::TEAL . ';text-decoration:none">' . $val . '</a>';
            } elseif (preg_match('/^\+?[0-9 ().-]{7,}$/', (string) $v)) {
                $val = '<a href="tel:' . self::e(preg_replace('/[^0-9+]/', '', (string) $v)) . '" style="color:' . self::TEAL . ';text-decoration:none">' . $val . '</a>';
            } else {
                $val = nl2br($val);
            }
            $out .= '<tr><td valign="top" style="padding:7px 14px 7px 0;font-size:13px;color:' . self::MUTED . ';white-space:nowrap;width:1%">' . self::e($k) . '</td>'
                . '<td valign="top" style="padding:7px 0;font-size:14px;color:#0D1B2A;font-weight:bold;line-height:1.45">' . $val . '</td></tr>';
        }
        return $out === '' ? '' : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse">' . $out . '</table>';
    }

    /** A chat transcript: [['who' => .., 'at' => .., 'text' => .., 'mine' => bool], ...] */
    public static function bubbles(array $lines): string
    {
        $out = '';
        foreach ($lines as $l) {
            $mine = ! empty($l['mine']);
            $out .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 10px"><tr><td>'
                . '<div style="font-size:11.5px;font-weight:bold;color:' . ($mine ? '#0E7490' : '#64748B') . ';margin:0 0 3px">' . self::e($l['who'] ?? '')
                . (! empty($l['at']) ? ' <span style="font-weight:normal;color:#94A3B8">' . self::e($l['at']) . '</span>' : '') . '</div>'
                . '<div style="background:' . ($mine ? '#ECFEFF' : '#F4F7FA') . ';border:1px solid ' . ($mine ? '#A5F3FC' : '#E2E8F0') . ';border-radius:10px;padding:10px 12px;font-size:14px;line-height:1.55;color:#1E293B">'
                . nl2br(self::e($l['text'] ?? '')) . '</div></td></tr></table>';
        }
        return $out;
    }

    public static function render(array $o): string
    {
        $e = fn ($v) => self::e($v);
        $navy = self::NAVY;
        $teal = self::TEAL;
        $muted = self::MUTED;

        $head = '<tr><td class="kt-pad" style="padding:26px 34px 6px">'
            . (! empty($o['eyebrow']) ? '<div style="display:inline-block;font-size:11.5px;letter-spacing:1.2px;text-transform:uppercase;font-weight:bold;color:' . $teal . ';background:#E8F3F7;border-radius:999px;padding:5px 11px;margin:0 0 12px">'
                . (! empty($o['icon']) ? $o['icon'] . '&nbsp; ' : '') . $e($o['eyebrow']) . '</div>' : '')
            . '<div class="kt-h" style="font-size:22px;line-height:1.3;font-weight:bold;color:' . $navy . ';margin:0 0 4px">' . $e($o['title'] ?? '') . '</div>'
            . (! empty($o['subtitle']) ? '<div style="font-size:14.5px;color:' . $muted . ';margin:0 0 4px">' . $e($o['subtitle']) . '</div>' : '')
            . (! empty($o['meta']) ? '<div style="font-size:12.5px;color:#94A3B8;margin:6px 0 0">' . $e($o['meta']) . '</div>' : '')
            . '</td></tr>';

        $body = '';
        if (! empty($o['intro'])) {
            $body .= '<tr><td class="kt-pad" style="padding:10px 34px 0;font-size:14.5px;line-height:1.6;color:#334155">' . $e($o['intro']) . '</td></tr>';
        }
        if (isset($o['message']) && trim((string) $o['message']) !== '') {
            $body .= '<tr><td class="kt-pad" style="padding:16px 34px 0">'
                . (! empty($o['message_label']) ? '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;font-weight:bold;color:' . $muted . ';margin:0 0 7px">' . $e($o['message_label']) . '</div>' : '')
                . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-left:4px solid #2C8AAC;background:#F4F9FB;border-radius:0 10px 10px 0"><tr><td style="padding:14px 16px;font-size:14.5px;line-height:1.6;color:#0D1B2A">'
                . nl2br($e($o['message'])) . '</td></tr></table></td></tr>';
        }
        if (! empty($o['alert'])) {
            $body .= '<tr><td class="kt-pad" style="padding:14px 34px 0"><table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FFF7ED;border:1px solid #FED7AA;border-radius:10px"><tr><td style="padding:11px 14px;font-size:14px;font-weight:bold;color:#C2410C">'
                . $e($o['alert']) . '</td></tr></table></td></tr>';
        }
        foreach ((array) ($o['sections'] ?? []) as $s) {
            $rows = self::rows((array) ($s['rows'] ?? []));
            if ($rows === '') {
                continue;
            }
            $body .= '<tr><td class="kt-pad" style="padding:20px 34px 0">'
                . (! empty($s['heading']) ? '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;font-weight:bold;color:' . $muted . ';border-bottom:1px solid #E5ECF1;padding:0 0 7px;margin:0 0 4px">' . $e($s['heading']) . '</div>' : '')
                . $rows . '</td></tr>';
        }
        if (! empty($o['html'])) {
            $body .= '<tr><td class="kt-pad" style="padding:20px 34px 0">'
                . (! empty($o['html_heading']) ? '<div style="font-size:12px;letter-spacing:1px;text-transform:uppercase;font-weight:bold;color:' . $muted . ';border-bottom:1px solid #E5ECF1;padding:0 0 7px;margin:0 0 12px">' . $e($o['html_heading']) . '</div>' : '')
                . $o['html'] . '</td></tr>';
        }
        if (! empty($o['button']['url'])) {
            $body .= '<tr><td class="kt-pad" style="padding:24px 34px 0"><table role="presentation" cellpadding="0" cellspacing="0"><tr><td bgcolor="' . $teal . '" style="border-radius:10px;background:' . $teal . '">'
                . '<a href="' . $e($o['button']['url']) . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:10px">'
                . $e($o['button']['label'] ?? 'Open in KiddieTrac') . ' &rarr;</a></td></tr></table></td></tr>';
        }
        if (! empty($o['note'])) {
            $body .= '<tr><td class="kt-pad" style="padding:18px 34px 0;font-size:12.5px;line-height:1.55;color:' . $muted . '">' . $e($o['note']) . '</td></tr>';
        }

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            . '<style>@media (prefers-color-scheme: dark){.kt-card{background:#ffffff !important}.kt-band{background:' . self::BAND . ' !important}.kt-h{color:' . $navy . ' !important}}'
            . '[data-ogsc] .kt-band,[data-ogsb] .kt-band{background:' . self::BAND . ' !important}[data-ogsc] .kt-card,[data-ogsb] .kt-card{background:#ffffff !important}[data-ogsc] .kt-h{color:' . $navy . ' !important}'
            . '@media (max-width:600px){.kt-pad{padding-left:20px !important;padding-right:20px !important}}</style></head>'
            . '<body style="margin:0;padding:0;background:#EEF3F6">'
            . (! empty($o['preheader']) ? '<div style="display:none;max-height:0;overflow:hidden">' . $e($o['preheader']) . '</div>' : '')
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#EEF3F6"><tr><td align="center" style="padding:24px 10px">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" class="kt-card" style="width:100%;max-width:600px;background:#ffffff;border-radius:16px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#0D1B2A">'
            . '<tr><td bgcolor="' . self::BAND . '" class="kt-band" style="background:' . self::BAND . ';padding:20px 24px;text-align:center"><img src="https://api.kiddietrac.com/email/wordmark-tagline-navy.png" width="220" alt="KiddieTrac - Smart Childcare Management Platform" style="display:block;margin:0 auto;border:0;width:220px;max-width:70%;height:auto"></td></tr>'
            . '<tr><td style="font-size:0;line-height:0"><img src="https://api.kiddietrac.com/email/brand-gradient.png" width="600" height="6" alt="" style="display:block;border:0;width:100%;max-width:600px;height:6px"></td></tr>'
            . $head . $body
            . '<tr><td style="padding:28px 0 0"></td></tr>'
            . '<tr><td class="kt-pad" style="background:#F6F9FB;border-top:1px solid #E5ECF1;padding:16px 34px;font-size:12px;line-height:1.55;color:#7A8B9A;text-align:center">'
            . '<strong style="color:' . $navy . '">KiddieTrac</strong> &middot; Smart Childcare Management Platform<br>'
            . 'Sales team notification &middot; <a href="https://app.kiddietrac.com/dashboard.html#sales" style="color:' . $teal . ';text-decoration:none">Open the pipeline</a>'
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
