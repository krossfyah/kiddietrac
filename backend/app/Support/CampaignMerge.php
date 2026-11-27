<?php

declare(strict_types=1);

namespace App\Support;

/**
 * MERGE FIELDS IN AGENCY CAMPAIGNS (2026-11-27, Anthony: "Hi {first_name},").
 *
 * An agency's family campaign (marketing_campaigns: the portal's Marketing > Campaigns) may write {first_name}
 * anywhere in its subject or body. It becomes the recipient's OWN first name, per email. Where there is no one
 * person (the News post every family reads, a recipient without a first name on file) it reads FALLBACK, so
 * "Hi {first_name}," becomes "Hi there,".
 *
 * Spelling is forgiving: {first_name}, {{first_name}}, { First_Name }, {firstname}. An editor may wrap part of the
 * token in a tag ("{<b>first_name</b>}"); that is not matched and goes out as written, so the editor inserts the
 * token as plain text.
 *
 * html() escapes the name (it lands in HTML); text() does not (subject lines, bell rows, push).
 * Cheap on purpose: the command wraps the email once and calls html() on the finished HTML per recipient.
 */
final class CampaignMerge
{
    public const TOKEN = '{first_name}';

    public const FALLBACK = 'there';

    private const PATTERN = '/\{\{?\s*first[\s_-]?name\s*\}?\}/i';

    /** True when the text uses the field at all (lets a caller skip per-recipient work). */
    public static function has(?string $s): bool
    {
        return $s !== null && $s !== '' && preg_match(self::PATTERN, $s) === 1;
    }

    /** The name to use: the person's first name, else FALLBACK. Never an email address, never a last name. */
    public static function firstName(?string $first): string
    {
        $n = trim((string) preg_replace('/\s+/u', ' ', (string) $first));
        if ($n === '' || str_contains($n, '@') || mb_strlen($n) > 40) {
            return self::FALLBACK;
        }

        return $n;
    }

    /** For HTML: the name escaped. */
    public static function html(?string $html, ?string $first): string
    {
        $html = (string) $html;
        if (! self::has($html)) {
            return $html;
        }
        $name = htmlspecialchars(self::firstName($first), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace_callback(self::PATTERN, fn () => $name, $html);
    }

    /**
     * A short plain-text excerpt (preheader, bell preview) that never ends inside a token: a cut "{first_na…" could
     * not be filled in later and would show as written. Tokens are normalised to TOKEN first.
     */
    public static function excerpt(?string $text, int $width): string
    {
        $text = (string) preg_replace(self::PATTERN, self::TOKEN, (string) $text);
        $cut = mb_strimwidth($text, 0, $width, '…');
        if ($cut !== $text) {
            $open = mb_strrpos($cut, '{');
            if ($open !== false && mb_strpos($cut, '}', $open) === false) {
                $cut = rtrim(mb_substr($cut, 0, $open)) . '…';
            }
        }

        return $cut;
    }

    /** For plain text (subject, bell row, push): the name as is. */
    public static function text(?string $text, ?string $first): string
    {
        $text = (string) $text;
        if (! self::has($text)) {
            return $text;
        }
        $name = self::firstName($first);

        return (string) preg_replace_callback(self::PATTERN, fn () => $name, $text);
    }
}
