<?php

namespace App\Support;

/**
 * RICH TEXT IN SALES CAMPAIGN EMAILS (2026-11-04, Anthony: "I don't see a rich editor to edit the campaign
 * template/email"). The Introduction (and the whole body of the "Your own email" layout) is written in a rich-text
 * box: headings, bold/italic, colour, lists, links, pictures, the film, a Book-a-demo button and {first_name}.
 *
 *  - Saved through MailHtml::clean (the email composer's allowlist), minus inline data: pictures (inboxes block them).
 *  - Old plain-text introductions keep working: no tags = the paragraphs they always were.
 *  - Drawn with the layout's own type styles: a <p> gets the layout's paragraph style, a heading its heading style,
 *    a link its colour - anything the writer set (a colour, centring) comes after, so it wins.
 *  - Every web link is tracked like the fixed ones: the nth distinct link is key "xa", "xb"... (/c/l/{token}/xa), and
 *    the click resolves it from the campaign's own saved body, so it is never an open redirect.
 *
 * THE CANVAS (2026-11-05, Anthony: "have it where it doesn't use the top header and footer as optional where I can
 * create my own campaign from scratch with uploading images, content and headers/footers etc - totally customizable").
 * "Your own email" carries a canvas (sales_campaigns.canvas / sales_campaign_templates.canvas, JSON): the KiddieTrac
 * header, none or your own; the KiddieTrac footer, your own or the shortest one; the signature on or off; page,
 * email, text, heading and link colours; font; width; corners; spacing. Your own header and footer are rich boxes like
 * the body (pictures, links - tracked too: their links follow the body's in the xa..xz order).
 * CASL: every footer choice keeps one line with who sent it, the mailing address and a working unsubscribe link -
 * the law asks for it in every commercial email, so it is the one part that cannot be switched off.
 * Dark mode: the chosen colours are the light look; a reader in dark mode gets the dark palette, as every layout does.
 */
final class CampaignRich
{
    public const FIRST_NAME = '{first_name}';
    private const API = 'https://api.kiddietrac.com/api/v1';

    public static function isRich(?string $s): bool
    {
        return $s !== null && (bool) preg_match('/<[a-z][^>]*>/i', $s);
    }

    /** What is stored: plain text as it is, rich text through the allowlist. Empty (only blank lines) = null. */
    public static function clean(?string $s): ?string
    {
        if ($s === null) return null;
        if (! self::isRich($s)) return trim($s) === '' ? null : $s;
        $html = MailHtml::clean($s);
        $html = preg_replace('#<img\b[^>]*\bsrc="data:[^"]*"[^>]*>#i', '', $html);
        $html = preg_replace('#<img\b(?![^>]*\bsrc=)[^>]*>#i', '', $html);   // a picture whose address was refused
        if (trim(MailHtml::text($html)) === '' && ! preg_match('#<img\b#i', $html)) return null;
        return $html;
    }

    /** Distinct web links in the body, in order (at most 26 are tracked). */
    public static function links(string $html): array
    {
        $out = [];
        if (! self::isRich($html)) return $out;
        $doc = self::load($html);
        foreach ($doc->getElementsByTagName('a') as $a) {
            $h = trim((string) $a->getAttribute('href'));
            if (preg_match('#^https?://#i', $h) && ! in_array($h, $out, true)) $out[] = $h;
            if (count($out) === 26) break;
        }
        return $out;
    }

    /** The destination of tracked key "xa".."xz" for this campaign, or null. */
    public static function target(object $c, string $key): ?string
    {
        if (! preg_match('/^x([a-z])$/', $key, $m)) return null;
        return self::links(self::linkSource($c))[ord($m[1]) - 97] ?? null;
    }

    /** Every rich part whose links are tracked, in key order: the body first (its keys never move), then header, footer. */
    public static function linkSource(object $c): string
    {
        $cv = self::canvas($c->canvas ?? null);
        return (string) ($c->intro ?? '') . ($cv['header'] === 'own' ? (string) $cv['header_html'] : '') . ($cv['footer'] === 'own' ? (string) $cv['footer_html'] : '');
    }

    public const FONTS = [
        'sans' => ['Clean (Segoe UI, Helvetica)', 'Segoe UI,Helvetica,Arial,sans-serif'],
        'modern' => ['Modern (Verdana)', 'Verdana,Geneva,Tahoma,sans-serif'],
        'serif' => ['Classic (Georgia)', 'Georgia,Times New Roman,serif'],
        'mono' => ['Typewriter (Courier)', 'Courier New,Courier,monospace'],
    ];

    /** The canvas with every key present (defaults = the look "Your own email" always had). */
    public static function canvas($raw): array
    {
        $v = is_array($raw) ? $raw : (is_string($raw) && $raw !== '' ? (json_decode($raw, true) ?: []) : []);
        $hex = fn ($x, $d) => is_string($x) && preg_match('/^#[0-9a-fA-F]{6}$/', $x) ? strtoupper($x) : $d;
        $in = fn ($x, array $ok, $d) => in_array($x, $ok, true) ? $x : $d;
        return [
            'header' => $in($v['header'] ?? null, ['kt', 'none', 'own'], 'kt'),
            'header_html' => isset($v['header_html']) && is_string($v['header_html']) ? $v['header_html'] : null,
            'footer' => $in($v['footer'] ?? null, ['kt', 'own', 'min'], 'kt'),
            'footer_html' => isset($v['footer_html']) && is_string($v['footer_html']) ? $v['footer_html'] : null,
            'signature' => array_key_exists('signature', $v) ? (bool) $v['signature'] : true,
            'page_bg' => $hex($v['page_bg'] ?? null, '#EEF2F7'),
            'sheet_bg' => $hex($v['sheet_bg'] ?? null, '#FFFFFF'),
            'text' => $hex($v['text'] ?? null, '#1E293B'),
            'heading' => $hex($v['heading'] ?? null, '#0B1F4D'),
            'link' => $hex($v['link'] ?? null, '#0E8F99'),
            'font' => $in($v['font'] ?? null, array_keys(self::FONTS), 'sans'),
            'width' => $in((int) ($v['width'] ?? 600), [560, 600, 680], 600),
            'corners' => array_key_exists('corners', $v) ? (bool) $v['corners'] : true,
            'pad' => $in($v['pad'] ?? null, ['normal', 'tight'], 'normal'),
        ];
    }

    /** What is stored: the canvas with its own header / footer through the allowlist, as JSON (null = the defaults). */
    public static function cleanCanvas($raw): ?string
    {
        if ($raw === null) return null;
        $cv = self::canvas($raw);
        $cv['header_html'] = self::clean($cv['header_html']);
        $cv['footer_html'] = self::clean($cv['footer_html']);
        return json_encode($cv, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Validation rules for a canvas in a request (prefix 'canvas'). */
    public static function canvasRules(): array
    {
        $hex = ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'];
        return [
            'canvas' => ['nullable', 'array'],
            'canvas.header' => ['nullable', 'in:kt,none,own'], 'canvas.header_html' => ['nullable', 'string', 'max:30000'],
            'canvas.footer' => ['nullable', 'in:kt,own,min'], 'canvas.footer_html' => ['nullable', 'string', 'max:30000'],
            'canvas.signature' => ['nullable', 'boolean'], 'canvas.corners' => ['nullable', 'boolean'],
            'canvas.page_bg' => $hex, 'canvas.sheet_bg' => $hex, 'canvas.text' => $hex, 'canvas.heading' => $hex, 'canvas.link' => $hex,
            'canvas.font' => ['nullable', 'in:' . implode(',', array_keys(self::FONTS))], 'canvas.width' => ['nullable', 'in:560,600,680'],
            'canvas.pad' => ['nullable', 'in:normal,tight'],
        ];
    }

    /**
     * The body as email HTML. $st: p, h1, h2, h3, a, li, quote styles (inline CSS). $tok = the recipient's token
     * (links tracked) or null (a preview or test: links go straight to their page).
     */
    public static function html(string $html, array $st, ?string $tok, string $first, ?string $linkSource = null): string
    {
        $doc = self::load(self::clean($html) ?? '');
        $root = $doc->getElementById('kt-rich-root');
        if (! $root) return '';
        // $linkSource: the whole campaign's tracked links (linkSource()) when this is one part of it - the keys must agree.
        $links = $tok ? array_flip(self::links($linkSource ?? $html)) : [];
        $xp = new \DOMXPath($doc);
        foreach ($xp->query('.//*', $root) as $n) {
            $tag = strtolower($n->tagName);
            $base = match ($tag) {
                'p', 'div' => $st['p'] ?? '',
                'h1' => $st['h1'] ?? '', 'h2' => $st['h2'] ?? '', 'h3', 'h4' => $st['h3'] ?? '',
                // Lists carry no colour of their own: they take it from the wrapper <div>, which every layout's dark palette recolours.
                'ul', 'ol' => 'margin:0 0 14px;padding-left:22px;' . self::noColour(preg_replace('/margin:[^;]*;?/', '', $st['p'] ?? '')),
                'li' => self::noColour($st['li'] ?? 'margin:0 0 6px;'),
                'a' => $st['a'] ?? '',
                'blockquote' => $st['quote'] ?? 'margin:0 0 14px;padding:4px 0 4px 14px;border-left:3px solid #CBD5E1;',
                'img' => 'display:block;max-width:100%;height:auto;border:0;',
                'hr' => 'border:0;border-top:1px solid #E2E8F0;margin:18px 0;',
                default => '',
            };
            if ($base !== '') $n->setAttribute('style', $base . (string) $n->getAttribute('style'));
            if ($tag === 'img' && ! $n->hasAttribute('alt')) $n->setAttribute('alt', '');
            // Dark mode: headings take the layout's heading colour (.hd), links its accent (.acc), a button stays a button.
            if (in_array($tag, ['h1', 'h2', 'h3', 'h4'], true)) $n->setAttribute('class', 'hd');
            if ($tag === 'a') $n->setAttribute('class', preg_match('/background/i', (string) $n->getAttribute('style')) ? 'btn' : 'acc');
            if ($tag === 'a' && $tok) {
                $h = trim((string) $n->getAttribute('href'));
                if (isset($links[$h])) $n->setAttribute('href', self::API . '/c/l/' . $tok . '/x' . chr(97 + $links[$h]));
            }
        }
        $out = '';
        foreach ($root->childNodes as $ch) $out .= $doc->saveHTML($ch);
        // Bare text the editor left outside a paragraph still reads like one.
        if ($out !== '' && ! preg_match('#^\s*<(p|div|h[1-6]|ul|ol|table|blockquote|img|hr|a)\b#i', $out)) $out = '<p style="' . ($st['p'] ?? '') . '">' . $out . '</p>';
        preg_match('/color:([^;]+);/', $st['p'] ?? '', $m);
        $out = '<div style="color:' . ($m[1] ?? '#1E293B') . ';">' . $out . '</div>';
        return str_replace([self::FIRST_NAME, '{first}', '{{first_name}}'], htmlspecialchars($first, ENT_QUOTES, 'UTF-8'), $out);
    }

    private static function noColour(string $style): string
    {
        return preg_replace('/(^|;)\s*color:[^;]*;?/', '$1', $style);
    }

    private static function load(string $html): \DOMDocument
    {
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="kt-rich-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $doc;
    }
}
