<?php

namespace App\Support;

/**
 * HTML WRITTEN IN THE EMAIL COMPOSER OR A SIGNATURE (2026-11-02). The composer is a rich-text box, so what reaches
 * the server is HTML typed (or pasted) by a person. It goes out under the mailbox's name, so it is cut down to an
 * allowlist: formatting, links, lists, tables and pictures - no scripts, frames, forms, event handlers, or links that
 * run code. Built on DOMDocument, never on regexes over tags.
 */
final class MailHtml
{
    private const TAGS = ['p', 'br', 'div', 'span', 'b', 'strong', 'i', 'em', 'u', 's', 'strike', 'sub', 'sup', 'a', 'ul', 'ol', 'li',
        'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'img', 'table', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th', 'hr', 'font',
        'pre', 'code', 'small', 'big', 'center', 'caption', 'col', 'colgroup'];
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'select', 'textarea',
        'link', 'meta', 'base', 'svg', 'math', 'frame', 'frameset', 'applet', 'noscript', 'template', 'head', 'title'];
    private const ATTRS = ['href', 'src', 'alt', 'title', 'width', 'height', 'style', 'align', 'valign', 'colspan', 'rowspan',
        'cellpadding', 'cellspacing', 'border', 'bgcolor', 'color', 'face', 'size', 'target', 'dir'];

    public static function clean(?string $html): string
    {
        $html = (string) $html;
        if (trim($html) === '') return '';
        // Plain text (an old signature typed in a textarea): keep its line breaks.
        if (! preg_match('/<[a-z][^>]*>/i', $html)) return nl2br(e($html));

        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="kt-mail-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $doc->getElementById('kt-mail-root');
        if (! $root) return nl2br(e(strip_tags($html)));
        self::walk($root);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
        return $out;
    }

    private static function walk(\DOMNode $node): void
    {
        for ($i = $node->childNodes->length - 1; $i >= 0; $i--) {
            $c = $node->childNodes->item($i);
            if ($c instanceof \DOMComment || $c instanceof \DOMProcessingInstruction) { $node->removeChild($c); continue; }
            if (! $c instanceof \DOMElement) continue;
            $tag = strtolower($c->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) { $node->removeChild($c); continue; }
            self::walk($c);
            if (! in_array($tag, self::TAGS, true)) {
                // Unknown wrapper (<o:p>, <section>...): keep what it holds.
                while ($c->firstChild) $node->insertBefore($c->firstChild, $c);
                $node->removeChild($c);
                continue;
            }
            foreach (iterator_to_array($c->attributes) as $at) {
                $name = strtolower($at->name);
                $val = trim((string) $at->value);
                if (! in_array($name, self::ATTRS, true)) { $c->removeAttribute($at->name); continue; }
                if ($name === 'href' && ! preg_match('#^(https?:|mailto:|tel:|\#)#i', $val)) { $c->removeAttribute($at->name); continue; }
                if ($name === 'src' && ! preg_match('#^(https:|data:image/(png|jpe?g|gif|webp);base64,)#i', $val)) { $c->removeAttribute($at->name); continue; }
                if ($name === 'style' && preg_match('/expression|javascript:|behaviou?r|url\s*\(|@import|position\s*:\s*fixed/i', $val)) { $c->removeAttribute($at->name); continue; }
            }
            if ($tag === 'a' && $c->hasAttribute('href')) { $c->setAttribute('target', '_blank'); $c->setAttribute('rel', 'noopener'); }
        }
    }

    /** Text of an HTML body, for "is there anything written?" checks. */
    public static function text(string $html): string
    {
        $t = preg_replace('#<(style|script)\b[^>]*>.*?</\1>#is', ' ', $html);
        $t = html_entity_decode(strip_tags(str_ireplace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", (string) $t)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $t));
    }
}
