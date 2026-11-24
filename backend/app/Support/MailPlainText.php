<?php

namespace App\Support;

/**
 * An HTML email as readable plain text (the text/plain alternative every email now carries - see
 * App\Listeners\AddPlainTextPart). Links keep their address ("Book a demo: https://..."), the hidden preheader,
 * styles, scripts, tracking pixels and layout-only images go, paragraphs and rows become lines.
 */
final class MailPlainText
{
    public static function fromHtml(string $html): string
    {
        $t = preg_replace('#<!--.*?-->#s', '', $html);
        $t = preg_replace('#<(head|style|script|title)\b[^>]*>.*?</\1>#is', '', $t);
        $t = preg_replace('#<(div|span)[^>]*display:\s*none[^>]*>.*?</\1>#is', '', $t);   // preheader / dark-mode twins
        $t = preg_replace('#<img\b[^>]*>#i', '', $t);
        $t = preg_replace_callback('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (stripos($url, 'mailto:') === 0) return $label !== '' ? $label : substr($url, 7);
            return ($label !== '' && $label !== $url) ? $label . ' (' . $url . ')' : $url;
        }, $t);
        $t = preg_replace('#<(br|/p|/h[1-6]|/tr|/div|/li|/table|hr)\b[^>]*>#i', "\n", $t);
        $t = preg_replace('#<li\b[^>]*>#i', '- ', $t);
        $t = preg_replace('#</td>#i', '  ', $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = str_replace(["\u{2007}", "\u{034F}", "\u{200C}", "\u{00A0}"], ' ', $t);
        $t = preg_replace("/[ \t]+/u", ' ', $t);
        $t = preg_replace("/ *\n */", "\n", $t);
        $t = preg_replace("/\n{3,}/", "\n\n", $t);
        return trim($t) . "\n";
    }
}
