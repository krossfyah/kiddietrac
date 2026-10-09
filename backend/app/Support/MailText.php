<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Mime\Email;

/**
 * NO RAW ESCAPE CODES IN AN EMAIL (2026-10-09).
 *
 * Anthony's Thanksgiving notice read "the year\u{2019}s clearest invitation": a PHP
 * string wrote the escape in single quotes, where PHP does not translate it, and 59
 * emails since August carried the code instead of an apostrophe. The source is fixed;
 * this is the net under every email so a reader never sees one again:
 *
 *   - a literal \u{2019} or ’ becomes the character;
 *   - a double-encoded entity in the HTML (&amp;rsquo; shows as "&rsquo;") is un-doubled;
 *   - an entity in the SUBJECT (plain text, so "&#039;" shows as typed) is decoded.
 *
 * Each repair is logged and audited as email.text_repaired, and kt:text-check reports
 * them nightly so the sender gets fixed at source rather than relying on the net.
 * Never throws: a message that cannot be inspected is sent as it is.
 */
final class MailText
{
    private const U_BRACE = '/\\\\u\{([0-9A-Fa-f]{1,6})\}/';
    private const U_FOUR = '/\\\\u([0-9A-Fa-f]{4})/';
    private const DOUBLE_ENTITY = '/&amp;(#[0-9]{1,7}|#x[0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/';
    private const ENTITY = '/&(#[0-9]{1,7}|#x[0-9A-Fa-f]{1,6}|[A-Za-z][A-Za-z0-9]{1,31});/';

    /** Repair plain text (a subject or a text part). @return array{0:string,1:array<string>} */
    public static function repairText(string $s, bool $decodeEntities = false): array
    {
        $kinds = [];
        $s = self::unescape($s, $kinds);
        if ($decodeEntities && preg_match(self::ENTITY, $s)) {
            $d = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($d !== $s) { $s = $d; $kinds[] = 'entity in plain text'; }
        }
        return [$s, $kinds];
    }

    /** Repair HTML. @return array{0:string,1:array<string>} */
    public static function repairHtml(string $html): array
    {
        $kinds = [];
        $html = self::unescape($html, $kinds, true);
        $n = 0;
        $html = preg_replace(self::DOUBLE_ENTITY, '&$1;', $html, -1, $n) ?? $html;
        if ($n) $kinds[] = 'double-encoded entity';
        return [$html, $kinds];
    }

    /** Repair a message in place. Returns what was repaired (empty = nothing). */
    public static function repair(Email $m): array
    {
        $kinds = [];
        try {
            $subj = (string) $m->getSubject();
            [$s2, $k] = self::repairText($subj, true);
            if ($k) { $m->subject($s2); $kinds = array_merge($kinds, array_map(fn ($x) => 'subject: ' . $x, $k)); }

            $html = $m->getHtmlBody();
            if (is_string($html) && $html !== '') {
                [$h2, $k] = self::repairHtml($html);
                if ($k) { $m->html($h2, $m->getHtmlCharset() ?: 'utf-8'); $kinds = array_merge($kinds, array_map(fn ($x) => 'html: ' . $x, $k)); }
            }
            $text = $m->getTextBody();
            if (is_string($text) && $text !== '') {
                [$t2, $k] = self::repairText($text);
                if ($k) { $m->text($t2, $m->getTextCharset() ?: 'utf-8'); $kinds = array_merge($kinds, array_map(fn ($x) => 'text: ' . $x, $k)); }
            }
        } catch (\Throwable $e) {
            return [];
        }

        if ($kinds) {
            $kinds = array_values(array_unique($kinds));
            $subject = (string) $m->getSubject();
            Log::warning('[MAIL TEXT] repaired before sending', ['subject' => $subject, 'kinds' => $kinds]);
            try {
                $agency = $m->getHeaders()->has('X-KT-Agency-Id') ? (int) $m->getHeaders()->get('X-KT-Agency-Id')->getBodyAsString() : null;
                Audit::write(['user_id' => null, 'agency_id' => $agency ?: null, 'action' => 'email.text_repaired',
                    'entity_type' => 'email', 'entity_id' => null,
                    'payload' => json_encode(['summary' => 'Fixed before sending: ' . implode('; ', $kinds), 'subject' => mb_substr($subject, 0, 200)]),
                    'created_at' => now()]);
            } catch (\Throwable $e) {
            }
        }

        return $kinds;
    }

    /** \u{2019} and ’ written out literally -> the character. */
    private static function unescape(string $s, array &$kinds, bool $html = false): string
    {
        if (strpos($s, '\\u') === false) return $s;
        $chr = function ($hex) use ($html) {
            $cp = hexdec($hex);
            if ($cp < 0x20 || ($cp >= 0xD800 && $cp <= 0xDFFF) || $cp > 0x10FFFF) return null;
            $c = mb_chr($cp, 'UTF-8');
            if ($c === false) return null;
            return $html ? htmlspecialchars($c, ENT_QUOTES | ENT_HTML5, 'UTF-8', false) : $c;
        };
        $n = 0;
        $s = preg_replace_callback(self::U_BRACE, function ($mm) use ($chr, &$n) { $c = $chr($mm[1]); if ($c === null) return $mm[0]; $n++; return $c; }, $s) ?? $s;
        $s = preg_replace_callback(self::U_FOUR, function ($mm) use ($chr, &$n) { $c = $chr($mm[1]); if ($c === null) return $mm[0]; $n++; return $c; }, $s) ?? $s;
        if ($n) $kinds[] = 'raw \\u escape';
        return $s;
    }
}
