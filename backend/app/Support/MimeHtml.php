<?php

namespace App\Support;

/**
 * THE EMAIL AS IT WAS SENT (2026-11-05, Anthony: crash email header white-on-white in the in-app email client).
 *
 * Graph's message `body` is Microsoft's sanitized copy: it drops CSS it does not like - linear-gradient backgrounds
 * among them - so a navy header band arrived blank and its white text vanished. The original is still in the mailbox
 * (GET /messages/{id}/$value, the raw MIME). This pulls the HTML part out of that MIME so the reading pane shows the
 * email as its sender wrote it, the same as Gmail accounts already get. The frame it is drawn in is sandboxed with
 * scripts off, so nothing in it can run.
 *
 * html() returns '' when there is no HTML part (or anything is malformed); the caller keeps Graph's body then.
 */
class MimeHtml
{
    public static function html(string $mime, int $depth = 0): string
    {
        if ($depth > 6 || $mime === '') return '';
        [$head, $body] = self::split($mime);
        $h = self::headers($head);
        $type = strtolower(trim(explode(';', $h['content-type'] ?? 'text/plain')[0]));
        if (str_starts_with($type, 'multipart/')) {
            if (! preg_match('/boundary="?([^";]+)"?/i', $h['content-type'] ?? '', $m)) return '';
            $parts = preg_split('/\r?\n--' . preg_quote(trim($m[1]), '/') . '(?:--)?[ \t]*(?:\r?\n|$)/', "\n" . $body);
            array_shift($parts);   // the preamble before the first boundary
            foreach ($parts as $p) {
                if (trim($p) === '') continue;
                $html = self::html($p, $depth + 1);
                if ($html !== '') return $html;
            }
            return '';
        }
        if ($type !== 'text/html') return '';
        if (preg_match('/^\s*attachment/i', $h['content-disposition'] ?? '')) return '';   // an attached .html file
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        if ($enc === 'base64') $body = (string) base64_decode(preg_replace('/\s+/', '', $body));
        elseif ($enc === 'quoted-printable') $body = quoted_printable_decode($body);
        $cs = preg_match('/charset="?([^";\s]+)"?/i', $h['content-type'] ?? '', $c) ? strtoupper($c[1]) : 'UTF-8';
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') {
            try { $body = mb_convert_encoding($body, 'UTF-8', $cs); } catch (\Throwable $e) {}
        }
        return mb_check_encoding($body, 'UTF-8') ? $body : '';
    }

    private static function split(string $s): array
    {
        $p = preg_split('/\r?\n\r?\n/', $s, 2);
        return [$p[0] ?? '', $p[1] ?? ''];
    }

    private static function headers(string $head): array
    {
        $out = [];
        $head = preg_replace('/\r?\n[ \t]+/', ' ', $head);   // unfold continuation lines
        foreach (preg_split('/\r?\n/', $head) as $line) {
            $i = strpos($line, ':');
            if ($i) $out[strtolower(trim(substr($line, 0, $i)))] = trim(substr($line, $i + 1));
        }
        return $out;
    }
}
