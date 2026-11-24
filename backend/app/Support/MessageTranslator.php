<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Outgoing messages in each person's own language (2026-10-22).
 *
 * The portal's screens are translated in the browser (kt-i18n.js), but everything the
 * server sends — emails, texts, push and bell notifications, PDFs — was English only.
 * Anthony chose "AI translation, cached" and "whole PDF".
 *
 *   text($s, $loc)  one string;  many($list, $loc)  several in one request;
 *   html($html, $loc)  only the words between the tags are translated, so layout,
 *   links and styling are untouched; each piece is cached on its own, so an email
 *   template's fixed wording is translated once and reused for everyone.
 *
 * The language is the recipient's users.locale (fr, es, hi — set by the language picker,
 * POST /locale). English, an unknown language, or anything going wrong returns the
 * English original: a translation must never stop or delay a message beyond its time
 * limit (urgent paths pass a short one). Translations are made by Claude, told to keep
 * names, numbers, amounts, dates, times, phone numbers, emails, links and emoji exactly,
 * and stored in outgoing_translations. Kill switch: platform setting i18n.messages = 0.
 */
final class MessageTranslator
{
    public const LANGS = ['fr' => 'Canadian French', 'es' => 'Spanish', 'hi' => 'Hindi'];
    private const API = 'https://api.anthropic.com/v1/messages';
    private const MODEL = 'claude-haiku-5-5';
    private const BATCH = 60;

    private static array $userLocale = [];
    private static ?string $forced = null;

    /* ── whose language ─────────────────────────────────────────────────── */

    public static function norm(?string $loc): ?string
    {
        $l = strtolower(substr((string) $loc, 0, 2));
        return isset(self::LANGS[$l]) ? $l : null;
    }

    public static function enabled(): bool
    {
        return (string) PlatformSettings::get('i18n.messages', '1') !== '0';
    }

    public static function forUser(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }
        // No AI translation for a test-only person (2026-11-24, App\Support\TestAgency): their text stays English.
        if (\App\Support\TestAgency::isUser((int) $userId)) {
            return null;
        }
        if (! array_key_exists($userId, self::$userLocale)) {
            try {
                self::$userLocale[$userId] = self::norm(DB::table('users')->where('id', $userId)->value('locale'));
            } catch (\Throwable $e) {
                self::$userLocale[$userId] = null;
            }
        }
        return self::$userLocale[$userId];
    }

    /** The one language shared by everyone an email goes to, or null (mixed / English). */
    public static function forEmails(array $emails): ?string
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($e) => strtolower(trim((string) $e)), $emails))));
        if (! $emails) {
            return null;
        }
        try {
            $locs = DB::table('users')->whereIn(DB::raw('LOWER(email)'), $emails)->whereNull('deleted_at')->pluck('locale', 'email')->all();
        } catch (\Throwable $e) {
            return null;
        }
        $found = [];
        foreach ($emails as $e) {
            $match = null;
            foreach ($locs as $em => $l) {
                if (strtolower($em) === $e) { $match = self::norm($l); break; }
            }
            $found[] = $match;    // an address with no account counts as English
        }
        $u = array_unique($found);
        if (count($u) === 1 && $u[0] !== null) {
            foreach ($emails as $e) { if (\App\Support\TestAgency::isEmail($e)) return null; }   // test-only people: no AI (2026-11-24)
        }
        return count($u) === 1 ? $u[0] : null;
    }

    /** A family's guardians' shared language (for its invoices and receipts). */
    public static function forFamily(?int $familyId): ?string
    {
        if (! $familyId) {
            return null;
        }
        try {
            $locs = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $familyId)->whereNull('u.deleted_at')->pluck('u.locale')->map(fn ($l) => self::norm($l))->unique()->values()->all();
        } catch (\Throwable $e) {
            return null;
        }
        return count($locs) === 1 ? $locs[0] : null;
    }

    /** Run $fn with PDFs (and html()) rendered in $loc. */
    public static function withLocale(?string $loc, callable $fn)
    {
        $prev = self::$forced;
        self::$forced = self::norm($loc) ?? '';
        try {
            return $fn();
        } finally {
            self::$forced = $prev;
        }
    }

    /** A family's document: being sent → the family's language; being viewed → the viewer's. */
    public static function forDocument(int $familyId, callable $fn)
    {
        if (self::$forced !== null) {
            return $fn();
        }
        $sending = true;
        try {
            $sending = app()->runningInConsole() || ! request()->isMethod('GET');
        } catch (\Throwable $e) {
        }

        return $sending ? self::withLocale(self::forFamily($familyId ?: null), $fn) : $fn();
    }

    /** The language a PDF being made right now should be in. */
    public static function pdfLocale(): ?string
    {
        if (self::$forced !== null) {
            return self::$forced !== '' ? self::$forced : null;
        }
        try {
            if (app()->runningInConsole() || ! request()->isMethod('GET')) {
                return null;                  // sent, not viewed: the sender decides (withLocale)
            }
            $u = request()->user();
            return $u ? self::forUser((int) $u->id) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /* ── translating ────────────────────────────────────────────────────── */

    public static function text(string $s, ?string $loc, int $timeout = 8): string
    {
        $loc = self::norm($loc);
        if (! $loc || trim($s) === '' || ! self::enabled()) {
            return $s;
        }
        return self::many([$s], $loc, $timeout)[0] ?? $s;
    }

    /** @return string[] same order; untranslatable or failed items come back unchanged */
    public static function many(array $list, ?string $loc, int $timeout = 8): array
    {
        $loc = self::norm($loc);
        $list = array_values(array_map('strval', $list));
        if (! $loc || ! $list || ! self::enabled()) {
            return $list;
        }
        $out = $list;
        $need = [];
        $hashes = [];
        foreach ($list as $i => $s) {
            if (! preg_match('/\p{L}{2,}/u', $s)) {
                continue;                     // numbers, symbols, a name's initial: nothing to translate
            }
            $hashes[$i] = sha1($loc . "\0" . $s);
        }
        if (! $hashes) {
            return $out;
        }
        try {
            $have = DB::table('outgoing_translations')->whereIn('hash', array_values(array_unique($hashes)))->pluck('translated', 'hash')->all();
        } catch (\Throwable $e) {
            return $out;
        }
        foreach ($hashes as $i => $h) {
            if (isset($have[$h])) {
                $out[$i] = $have[$h];
            } else {
                $need[$h] = $list[$i];
            }
        }
        if ($need) {
            $deadline = microtime(true) + $timeout;
            foreach (array_chunk($need, self::BATCH, true) as $chunk) {
                $left = (int) floor($deadline - microtime(true));
                if ($left < 2) {
                    break;                    // out of time: the rest stays English
                }
                $done = self::ask(array_values($chunk), $loc, $left);
                if (! $done) {
                    break;
                }
                $rows = [];
                $k = 0;
                foreach ($chunk as $h => $src) {
                    $t = $done[$k++] ?? null;
                    if (! is_string($t) || trim($t) === '') {
                        continue;
                    }
                    $have[$h] = $t;
                    $rows[] = ['hash' => $h, 'locale' => $loc, 'source' => $src, 'translated' => $t, 'created_at' => now()];
                }
                if ($rows) {
                    try { DB::table('outgoing_translations')->insertOrIgnore($rows); } catch (\Throwable $e) {}
                }
            }
            foreach ($hashes as $i => $h) {
                if (isset($have[$h])) {
                    $out[$i] = $have[$h];
                }
            }
        }
        return $out;
    }

    /** An HTML document or fragment: the text between tags (and alt/title) is translated. */
    public static function html(string $html, ?string $loc, int $timeout = 20): string
    {
        $loc = self::norm($loc);
        if (! $loc || trim($html) === '' || ! self::enabled()) {
            return $html;
        }
        try {
            $doc = new \DOMDocument();
            $prev = libxml_use_internal_errors(true);
            $isDoc = (bool) preg_match('/<html[\s>]/i', $html);
            $doc->loadHTML('<?xml encoding="UTF-8">' . ($isDoc ? $html : '<div id="kt-tr-root">' . $html . '</div>'), LIBXML_NOERROR | LIBXML_NOWARNING | ($isDoc ? 0 : LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD));
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
            $xp = new \DOMXPath($doc);
            $nodes = [];
            $texts = [];
            foreach ($xp->query('//text()[not(ancestor::script) and not(ancestor::style) and not(ancestor::code) and not(ancestor::*[@data-kt-no-translate])]') as $n) {
                $v = $n->nodeValue;
                if (trim($v) === '' || ! preg_match('/\p{L}{2,}/u', $v)) {
                    continue;
                }
                $nodes[] = $n;
                $texts[] = trim($v);
            }
            $attrs = [];
            foreach ($xp->query('//@alt | //@title | //@placeholder') as $a) {
                if (preg_match('/\p{L}{2,}/u', $a->value)) {
                    $attrs[] = $a;
                    $texts[] = trim($a->value);
                }
            }
            $title = $xp->query('//title')->item(0);
            if (! $texts) {
                return $html;
            }
            $uniq = array_values(array_unique($texts));
            $tr = array_combine($uniq, self::many($uniq, $loc, $timeout));
            foreach ($nodes as $n) {
                $v = $n->nodeValue;
                $lead = preg_match('/^\s+/u', $v, $m) ? $m[0] : '';
                $tail = preg_match('/\s+$/u', $v, $m) ? $m[0] : '';
                $n->nodeValue = $lead . ($tr[trim($v)] ?? trim($v)) . $tail;
            }
            foreach ($attrs as $a) {
                $a->value = $tr[trim($a->value)] ?? $a->value;
            }
            if ($isDoc) {
                $root = $doc->documentElement;
                if ($root && strtolower($root->nodeName) === 'html') {
                    $root->setAttribute('lang', $loc);
                }
                $out = $doc->saveHTML();
            } else {
                $box = $doc->getElementById('kt-tr-root');
                $out = '';
                foreach ($box ? $box->childNodes : [] as $c) {
                    $out .= $doc->saveHTML($c);
                }
            }
            // The encoding hint added for the parser can come back anywhere (after a doctype).
            $out = str_replace('<?xml encoding="UTF-8">', '', (string) $out);
            return $out !== '' ? $out : $html;
        } catch (\Throwable $e) {
            Log::warning('Message translation (html) failed', ['locale' => $loc, 'error' => $e->getMessage()]);
            return $html;
        }
    }

    /** One request to Claude: a JSON array in, the same-length array back. Null on any problem. */
    private static function ask(array $items, string $loc, int $timeout): ?array
    {
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) {
            return null;
        }
        $lang = self::LANGS[$loc];
        $system = "You translate text from KiddieTrac, a childcare app, into {$lang} for parents and childcare staff.\n"
            . "You receive a JSON array of strings and reply with ONLY a JSON array of the same length, in the same order, each item translated.\n"
            . "Keep exactly as they are: people's and children's names, centre and agency names, the product name KiddieTrac, numbers, amounts and currency, "
            . "dates, times, phone numbers, email addresses, web links, reference codes, emoji, and any {placeholders}. "
            . "Keep leading and trailing punctuation. Do not add, explain or drop anything. "
            . 'Words in capitals that people must type or press (STOP, HELP, START, YES, NO, UNSTOP, any key or code) stay exactly as written. '
            . ($loc === 'fr' ? 'Use Canadian French and the polite "vous". ' : '')
            . ($loc === 'es' ? 'Use neutral Latin American Spanish and "usted". ' : '')
            . ($loc === 'hi' ? 'Use simple, everyday Hindi in Devanagari script. ' : '')
            . 'If an item is already in the target language or is only a name or code, return it unchanged.';
        $chars = array_sum(array_map('mb_strlen', $items));
        try {
            $r = Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->timeout(max(2, $timeout))->post(self::API, [
                    'model' => config('services.anthropic.translate_model') ?: self::MODEL,
                    'max_tokens' => min(16000, 400 + (int) ($chars * 3)),
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE)]],
                ]);
            if (! $r->successful()) {
                Log::warning('Message translation: API refused', ['status' => $r->status(), 'locale' => $loc]);
                return null;
            }
            $txt = (string) (\App\Support\AiConfig::text($r->json()) ?? '');
            if (preg_match('/\[[\s\S]*\]/u', $txt, $m)) {
                $txt = $m[0];
            }
            $arr = json_decode($txt, true);
            return (is_array($arr) && count($arr) === count($items)) ? array_values($arr) : null;
        } catch (\Throwable $e) {
            Log::warning('Message translation failed', ['locale' => $loc, 'error' => $e->getMessage()]);
            return null;
        }
    }
}
