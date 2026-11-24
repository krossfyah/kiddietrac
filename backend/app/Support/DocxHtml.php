<?php

namespace App\Support;

/**
 * A Word document (.docx) as plain, safe HTML for reading in the portal (2026-11-04, Anthony: "have the ability to view
 * resumes with a view function"). A browser cannot draw a .docx, so the viewer could only offer a download.
 *
 * Reads word/document.xml straight from the zip: headings, paragraphs, bold / italic / underline, bullet and numbered
 * lists, tables, line breaks and tabs. Pictures, headers/footers and exact layout are left out - it is for reading
 * what the document says, not for printing it. Every piece of text is escaped; nothing from the file is passed through
 * as markup, so the result is safe to show.
 */
final class DocxHtml
{
    private const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** HTML for the document at a local path, or null when it is not a readable .docx. */
    public static function fromFile(string $absPath): ?string
    {
        if (! class_exists(\ZipArchive::class)) return null;
        $zip = new \ZipArchive();
        if ($zip->open($absPath) !== true) return null;
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || strlen($xml) > 30 * 1024 * 1024) return null;

        $dom = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        if (! $ok) return null;
        $x = new \DOMXPath($dom);
        $x->registerNamespace('w', self::W);
        $body = $x->query('/w:document/w:body')->item(0);
        if (! $body) return null;
        return self::blocks($x, $body);
    }

    private static function blocks(\DOMXPath $x, \DOMNode $parent): string
    {
        $out = ''; $list = null;   // 'ul' | 'ol' while inside a run of list paragraphs
        foreach ($parent->childNodes as $n) {
            if (! $n instanceof \DOMElement || $n->namespaceURI !== self::W) continue;
            if ($n->localName === 'p') {
                $style = strtolower((string) ($x->evaluate('string(w:pPr/w:pStyle/@w:val)', $n)));
                $isList = $x->query('w:pPr/w:numPr', $n)->length > 0 || str_contains($style, 'list');
                $text = self::runs($x, $n);
                if ($isList) {
                    $want = preg_match('/number|decimal/', $style) ? 'ol' : 'ul';
                    if ($list !== $want) { if ($list) $out .= '</' . $list . '>'; $out .= '<' . $want . '>'; $list = $want; }
                    if ($text !== '') $out .= '<li>' . $text . '</li>';
                    continue;
                }
                if ($list) { $out .= '</' . $list . '>'; $list = null; }
                if (trim(strip_tags($text)) === '') { $out .= '<p class="sp">&nbsp;</p>'; continue; }
                $tag = 'p';
                if ($style === 'title') $tag = 'h1';
                elseif (preg_match('/^heading\s*([1-6])$/', $style, $m) || preg_match('/^heading([1-6])/', $style, $m)) $tag = 'h' . min(6, (int) $m[1] + 1);
                elseif ($style === 'subtitle') $tag = 'h3';
                $align = (string) $x->evaluate('string(w:pPr/w:jc/@w:val)', $n);
                $css = in_array($align, ['center', 'right', 'both'], true) ? ' style="text-align:' . ($align === 'both' ? 'justify' : $align) . '"' : '';
                $out .= '<' . $tag . $css . '>' . $text . '</' . $tag . '>';
            } elseif ($n->localName === 'tbl') {
                if ($list) { $out .= '</' . $list . '>'; $list = null; }
                $out .= '<table>';
                foreach ($x->query('w:tr', $n) as $tr) {
                    $out .= '<tr>';
                    foreach ($x->query('w:tc', $tr) as $tc) $out .= '<td>' . self::blocks($x, $tc) . '</td>';
                    $out .= '</tr>';
                }
                $out .= '</table>';
            } elseif ($n->localName === 'sdt') {
                $c = $x->query('w:sdtContent', $n)->item(0);
                if ($c) $out .= self::blocks($x, $c);
            }
        }
        if ($list) $out .= '</' . $list . '>';
        return $out;
    }

    /** The text runs of one paragraph, with their emphasis. */
    private static function runs(\DOMXPath $x, \DOMElement $p): string
    {
        $out = '';
        foreach ($x->query('.//w:r', $p) as $r) {
            $t = '';
            foreach ($r->childNodes as $c) {
                if (! $c instanceof \DOMElement) continue;
                if ($c->localName === 't') $t .= htmlspecialchars($c->textContent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                elseif ($c->localName === 'tab') $t .= '&emsp;';
                elseif ($c->localName === 'br' || $c->localName === 'cr') $t .= '<br>';
            }
            if ($t === '') continue;
            $b = $x->query('w:rPr/w:b[not(@w:val="0") and not(@w:val="false")]', $r)->length > 0;
            $i = $x->query('w:rPr/w:i[not(@w:val="0") and not(@w:val="false")]', $r)->length > 0;
            $u = $x->query('w:rPr/w:u[not(@w:val="none")]', $r)->length > 0;
            if ($u) $t = '<u>' . $t . '</u>';
            if ($i) $t = '<em>' . $t . '</em>';
            if ($b) $t = '<strong>' . $t . '</strong>';
            $out .= $t;
        }
        return $out;
    }
}
