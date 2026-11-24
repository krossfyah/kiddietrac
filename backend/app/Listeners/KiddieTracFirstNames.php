<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;

/**
 * KIDDIETRAC PEOPLE ARE FIRST NAMES ONLY IN EMAIL (2026-11-07).
 *
 * Anthony: "with any emails pls do not have my staffs last name added - look at the NDA request email you added my
 * last name". It was already the rule (2026-10-26), kept by hand in each template, and the NDA request still went
 * out as "Anthony Hosein has sent you...". A rule kept per template is broken by the next template, so it is kept
 * here, at send time, for every message: wherever the full name of one of KiddieTrac's own people (an active
 * platform admin or sales rep) appears in the subject, the HTML, the text part or the From / Reply-To name, only the
 * first name is left. A From / Reply-To that was the full name becomes "First from KiddieTrac".
 *
 * Registered before ConfidentialityNotice and AddPlainTextPart. Recipients (To / Cc / Bcc) are left alone. Never
 * blocks or cancels a send.
 */
final class KiddieTracFirstNames
{
    public function handle(MessageSending $event): void
    {
        try {
            $people = self::people();
            if (! $people) return;
            $m = $event->message;

            $subject = $m->getSubject();
            if (is_string($subject) && $subject !== '') {
                $new = self::scrub($subject, $people, false);
                if ($new !== $subject) $m->subject($new);
            }
            $html = $m->getHtmlBody();
            if (is_string($html) && $html !== '') {
                $new = self::scrub($html, $people, true);
                if ($new !== $html) $m->html($new);
            }
            $text = $m->getTextBody();
            if (is_string($text) && $text !== '') {
                $new = self::scrub($text, $people, false);
                if ($new !== $text) $m->text($new, 'utf-8');
            }
            foreach (['From' => 'from', 'Reply-To' => 'replyTo'] as $header => $setter) {
                $list = $header === 'From' ? $m->getFrom() : $m->getReplyTo();
                $changed = false;
                $out = [];
                foreach ($list as $a) {
                    $name = $a->getName();
                    $new = self::senderName($name, $people);
                    if ($new !== $name) $changed = true;
                    $out[] = new Address($a->getAddress(), $new);
                }
                if ($changed) $m->$setter(...$out);
            }
        } catch (\Throwable $e) {
            // never in the way of a send
        }
    }

    /** [[first, last], ...] for KiddieTrac's own people. Cached for ten minutes. */
    public static function people(): array
    {
        return Cache::remember('kt:kiddietrac-people-names', 600, function () {
            return DB::table('users')->join('role_assignments as ra', 'ra.user_id', '=', 'users.id')
                ->whereIn('ra.role', ['platform_admin', 'sales_rep'])->where('ra.active', 1)->whereNull('users.deleted_at')
                ->distinct()->get(['users.first_name', 'users.last_name'])
                ->map(fn ($u) => [trim((string) $u->first_name), trim((string) $u->last_name)])
                ->filter(fn ($p) => $p[0] !== '' && mb_strlen($p[1]) >= 2)
                ->unique(fn ($p) => mb_strtolower($p[0] . ' ' . $p[1]))->values()->all();
        });
    }

    /** "First Last" -> "First". In HTML the escaped form (O&#039;Brien) and a &nbsp; between the names count too. */
    public static function scrub(string $s, array $people, bool $html): string
    {
        foreach ($people as [$first, $last]) {
            $lasts = [$last];
            if ($html) {
                $lasts[] = htmlspecialchars($last, ENT_QUOTES, 'UTF-8');
                $lasts[] = htmlspecialchars($last, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            $alt = implode('|', array_map(fn ($x) => preg_quote($x, '/'), array_unique($lasts)));
            $gap = $html ? '(?:\s|&nbsp;|&#160;|\x{00A0})+' : '[\s\x{00A0}]+';
            $s = preg_replace('/(?<![\p{L}\p{N}])(' . preg_quote($first, '/') . ')' . $gap . '(?:' . $alt . ')(?![\p{L}\p{N}])/iu', '$1', $s) ?? $s;
        }
        return $s;
    }

    /** A sender shown as a KiddieTrac person's full name becomes "First from KiddieTrac". */
    public static function senderName(string $name, array $people): string
    {
        foreach ($people as [$first, $last]) {
            if (mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name))) === mb_strtolower($first . ' ' . $last)) {
                return $first . ' from KiddieTrac';
            }
        }
        return self::scrub($name, $people, false);
    }
}
