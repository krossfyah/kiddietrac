<?php

declare(strict_types=1);

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * CHILDREN ARE "FIRST L." IN STAFF EMAIL (2026-11-24).
 *
 * Anthony's rule: in any email that is not to the child's own family, a child is named by first name and last
 * initial ("Liam T."). It is kept here, at send time, for every message, the way KiddieTracFirstNames keeps staff to
 * first names: a rule kept per template is broken by the next template. Educator / director / admin summaries and
 * digests, Safe Arrival staff mail, absence and late notices to staff, alerts, tickets, the phone-line approval mail,
 * temporary-pickup staff mail, observation and incident mail - all of it, now and later.
 *
 * Wherever a live child's full name ("First Last", or "Preferred Last") appears in the subject, the HTML or the text
 * part, it becomes "First L." - UNLESS one of the To / Cc recipients belongs to that child's family (a guardian's
 * account email or the family's primary email): a parent's own email about their own child keeps the full name.
 * Two different children who would both read "Liam T." in one email get their room ("Liam T. (Toddlers)"), or two
 * letters of the last name when the room does not tell them apart.
 *
 * Registered after KiddieTracFirstNames. Idempotent (a shortened name no longer matches). Never blocks a send.
 */
final class StaffChildNames
{
    public function handle(MessageSending $event): void
    {
        try {
            $m = $event->message;
            $subject = (string) ($m->getSubject() ?? '');
            $html = (string) ($m->getHtmlBody() ?? '');
            $text = (string) ($m->getTextBody() ?? '');
            $hay = mb_strtolower($subject . "\n" . $html . "\n" . $text);
            if (trim($hay) === '') return;

            // Only the children whose last name is in the message at all (cheap), then the exact full-name test.
            $found = [];
            foreach (self::children() as $c) {
                if (! str_contains($hay, mb_strtolower($c['last'])) && ! str_contains($hay, mb_strtolower(htmlspecialchars($c['last'], ENT_QUOTES, 'UTF-8')))) continue;
                foreach ($c['firsts'] as $f) {
                    if (preg_match(self::pattern($f, $c['last'], true), $subject . "\n" . $html . "\n" . $text)) { $found[$c['id']] = $c; break; }
                }
            }
            if (! $found) return;

            /* Matching is by the written name, so two records with the same name (a child in two agencies, a
               demo copy) are one name here: if ANY of them is the recipient's own child, that name is left alone. */
            $own = self::recipientFamilies($m);
            $keep = [];
            foreach ($found as $c) { if (in_array($c['family_id'], $own, true)) $keep[self::key($c)] = true; }
            $found = array_filter($found, fn ($c) => ! isset($keep[self::key($c)]));
            if (! $found) return;

            $short = self::shorts($found);
            $subject2 = self::apply($subject, $found, $short, false);
            if ($subject2 !== $subject) $m->subject($subject2);
            if ($html !== '') { $h2 = self::apply($html, $found, $short, true); if ($h2 !== $html) $m->html($h2); }
            if ($text !== '') { $t2 = self::apply($text, $found, $short, false); if ($t2 !== $text) $m->text($t2, 'utf-8'); }
        } catch (\Throwable $e) {
            // never in the way of a send
        }
    }

    /** "Liam", "Thompson" -> "Liam T." The one place the short form is made (also for code that builds names itself). */
    public static function short(?string $first, ?string $last): string
    {
        $first = trim((string) $first);
        $last = trim((string) $last);
        if ($last === '') return $first;
        return trim($first . ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.');
    }

    /** Live children: id, family, the names they are written by, last name, room. Cached ten minutes. */
    public static function children(): array
    {
        return Cache::remember('kt:staff-child-names', 600, function () {
            return DB::table('children as c')->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
                ->whereNull('c.deleted_at')
                ->get(['c.id', 'c.family_id', 'c.first_name', 'c.preferred_name', 'c.last_name', 'r.name as room'])
                ->map(function ($c) {
                    $last = trim((string) $c->last_name);
                    $firsts = array_values(array_unique(array_filter([trim((string) $c->first_name), trim((string) $c->preferred_name)], fn ($x) => $x !== '')));
                    return ['id' => (int) $c->id, 'family_id' => (int) $c->family_id, 'firsts' => $firsts, 'last' => $last, 'room' => trim((string) $c->room)];
                })
                ->filter(fn ($c) => $c['firsts'] && mb_strlen($c['last']) >= 2)
                ->values()->all();
        });
    }

    /** Families any To / Cc recipient belongs to (a guardian's account email, or the family's primary email). */
    private static function recipientFamilies($m): array
    {
        $emails = [];
        foreach (array_merge($m->getTo(), $m->getCc()) as $a) {
            $e = mb_strtolower(trim($a->getAddress()));
            if ($e !== '') $emails[] = $e;
        }
        if (! $emails) return [];
        $ph = implode(',', array_fill(0, count($emails), '?'));
        $a = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereRaw("LOWER(TRIM(u.email)) IN ($ph)", $emails)->pluck('g.family_id')->all();
        $b = DB::table('families')->whereRaw("LOWER(TRIM(primary_email)) IN ($ph)", $emails)->pluck('id')->all();
        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }

    /** id => short name; children who would collide in this email are told apart by room, else by the shortest start of the last name that does. */
    private static function shorts(array $found): array
    {
        $out = [];
        $by = [];
        foreach ($found as $c) {
            $s = self::short($c['firsts'][0], $c['last']);
            $out[$c['id']] = $s;
            $by[mb_strtolower($s)][] = $c;
        }
        foreach ($by as $group) {
            // Only DIFFERENT written names that would read the same need telling apart.
            $group = array_values(array_reduce($group, function ($acc, $c) { $acc[self::key($c)] ??= $c; return $acc; }, []));
            if (count($group) < 2) continue;
            $rooms = array_map(fn ($c) => mb_strtolower($c['room']), $group);
            $roomsDiffer = count(array_unique($rooms)) === count($group) && ! in_array('', $rooms, true);
            // No room to tell them apart: the shortest start of the last name that does (Bat. / Batt.).
            $k = 2;
            $max = max(array_map(fn ($c) => mb_strlen($c['last']), $group));
            while ($k < $max && count(array_unique(array_map(fn ($c) => mb_strtolower(mb_substr($c['last'], 0, $k)), $group))) < count($group)) $k++;
            foreach ($group as $c) {
                $pre = mb_substr($c['last'], 0, $k);
                $out[$c['id']] = $roomsDiffer
                    ? self::short($c['firsts'][0], $c['last']) . ' (' . $c['room'] . ')'
                    : trim($c['firsts'][0] . ' ' . mb_convert_case($pre, MB_CASE_TITLE) . ($pre === $c['last'] ? '' : '.'));
            }
        }
        // Every record with the same written name takes the short form chosen for that name.
        $byKey = [];
        foreach ($found as $c) { $byKey[self::key($c)] ??= $out[$c['id']]; }
        foreach ($found as $c) { $out[$c['id']] = $byKey[self::key($c)]; }
        return $out;
    }

    /** The written name, the same for two records that read the same. */
    private static function key(array $c): string
    {
        return mb_strtolower($c['firsts'][0] . ' ' . $c['last']);
    }

    private static function apply(string $s, array $found, array $short, bool $html): string
    {
        foreach ($found as $c) {
            foreach ($c['firsts'] as $i => $f) {
                // The preferred name stays as written; only the last name is cut to its initial.
                $repl = $i === 0 ? $short[$c['id']] : preg_replace('/^' . preg_quote($c['firsts'][0], '/') . '/u', $f, $short[$c['id']]);
                if ($html) $repl = htmlspecialchars($repl, ENT_QUOTES, 'UTF-8');
                $s = preg_replace(self::pattern($f, $c['last'], $html), addcslashes($repl, '\\$'), $s) ?? $s;
            }
        }
        return $s;
    }

    /** "First Last" with any spacing, the HTML-escaped last name too (O&#039;Brien), case-insensitive, whole words. */
    private static function pattern(string $first, string $last, bool $html): string
    {
        $lasts = [$last];
        if ($html) {
            $lasts[] = htmlspecialchars($last, ENT_QUOTES, 'UTF-8');
            $lasts[] = htmlspecialchars($last, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $alt = implode('|', array_map(fn ($x) => preg_quote($x, '/'), array_unique($lasts)));
        $gap = $html ? '(?:\s|&nbsp;|&#160;|\x{00A0})+' : '[\s\x{00A0}]+';
        return '/(?<![\p{L}\p{N}])' . preg_quote($first, '/') . $gap . '(?:' . $alt . ')(?![\p{L}\p{N}])/iu';
    }
}
