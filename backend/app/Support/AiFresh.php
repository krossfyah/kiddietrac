<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * NOTHING THE AI WRITES SHOULD READ LIKE THE LAST ONE (2026-11-09, Anthony: "with AI pulling images, writing emails etc
 * etc never repeat it self and use unique content - wire this up properly").
 *
 * Daily digests, parent summaries, weekly recaps, report cards, thank-you notes, lesson plans and drafted emails all
 * came out of fixed prompts, so day after day and child after child they opened the same way and leaned on the same
 * stock phrases ("had a wonderful day", "explored the"). One layer for all of them, on the HTTP client every Claude
 * call already goes through (AiConfig::registerHttp):
 *
 *   AiFresh::as('daily_digest');      // just before a creative call; the agency comes from UsageMeter
 *
 *   1. Before it goes: the system prompt gets a freshness rule and the agency's OVERUSED PHRASES for that kind - word
 *      runs that turned up in several of its recent pieces. Never the recent pieces themselves: those are about other
 *      children and families, and the model must not see one family's details while writing to another. A phrase used
 *      across several pieces is formulaic by definition; runs holding a name (a capitalised word mid-sentence) or a
 *      number are dropped as well.
 *   2. When it comes back: compared with the agency's recent pieces of the same kind (word 3-grams, Jaccard). Too close
 *      -> asked once more, told to change its opening and structure, and the fresher of the two is used.
 *   3. The result is remembered (ai_content_log: agency, kind, text; 60 days, 60 per kind) for the next one.
 *
 * Calls not marked with as() - translation, extraction, tagging, answers, "improve my text" - pass straight through:
 * those must stay faithful to what they were given, not different. Without a known agency nothing is read or kept
 * (one agency's writing never shapes another's); the freshness rule alone still goes in.
 */
final class AiFresh
{
    private static ?string $kind = null;
    private static ?int $agency = null;
    private static float $at = 0.0;

    private const KEEP_DAYS = 60;
    private const KEEP_PER_KIND = 60;
    private const TOO_CLOSE = 0.45;

    /** Mark the NEXT Claude call as creative writing of this kind. */
    public static function as(string $kind, ?int $agencyId = null): void
    {
        $kind = mb_substr(preg_replace('/[^a-z0-9_.-]+/i', '_', $kind), 0, 40);
        // A service marking its own call keeps the agency its caller already named (console jobs have no signed-in user).
        if ($agencyId === null && self::$kind === $kind && self::$at && microtime(true) - self::$at < 90) {
            $agencyId = self::$agency;
        }
        self::$kind = $kind;
        self::$agency = $agencyId;
        self::$at = microtime(true);
    }

    public static function register(): void
    {
        try {
            \Illuminate\Support\Facades\Http::globalMiddleware(function (callable $handler) {
                return function ($request, array $options) use ($handler) {
                    $kind = self::$kind;
                    if ($kind === null) return $handler($request, $options);
                    // A mark whose call never came (it failed first) does not carry over to some later, unrelated call.
                    if (microtime(true) - self::$at > 90) { self::$kind = null; return $handler($request, $options); }
                    try {
                        $uri = $request->getUri();
                        if (strtolower($uri->getHost()) !== 'api.anthropic.com' || ! str_ends_with($uri->getPath(), '/messages')
                            || strtoupper($request->getMethod()) !== 'POST') {
                            return $handler($request, $options);
                        }
                    } catch (\Throwable $e) {
                        return $handler($request, $options);
                    }
                    // One call per as(): the next call is unmarked unless it says so itself.
                    $agency = self::$agency ?: AiConfig::agencyNow();   // the caller's, else the one the call is metered to
                    self::$kind = null; self::$agency = null;

                    $recent = $agency ? self::recent($agency, $kind) : [];
                    $first = self::rewrite($request, self::rule($recent, false));
                    return $handler($first, $options)->then(function ($response) use ($handler, $request, $options, $recent, $agency, $kind) {
                        $text = self::textOf($response);
                        if ($text === null) return $response;
                        $close = self::closest($text, $recent);
                        if ($close < self::TOO_CLOSE) { self::remember($agency, $kind, $text); return $response; }
                        // Too much like one it already wrote: once more, told so.
                        return $handler(self::rewrite($request, self::rule($recent, true)), $options)->then(
                            function ($second) use ($response, $text, $close, $recent, $agency, $kind) {
                                $t2 = self::textOf($second);
                                if ($t2 !== null && self::closest($t2, $recent) < $close) { self::remember($agency, $kind, $t2); return $second; }
                                self::remember($agency, $kind, $text);
                                return $response;
                            },
                            function () use ($response, $text, $agency, $kind) { self::remember($agency, $kind, $text); return $response; }
                        );
                    });
                };
            });
        } catch (\Throwable $e) {
        }
    }

    /** The agency's recent pieces of this kind, newest first. */
    private static function recent(int $agency, string $kind, int $n = 20): array
    {
        try {
            return DB::table('ai_content_log')->where('agency_id', $agency)->where('kind', $kind)
                ->where('created_at', '>=', now()->subDays(self::KEEP_DAYS))
                ->orderByDesc('id')->limit($n)->pluck('text')->map(fn ($t) => (string) $t)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function remember(?int $agency, string $kind, string $text): void
    {
        if (! $agency || trim($text) === '') return;
        try {
            DB::table('ai_content_log')->insert(['agency_id' => $agency, 'kind' => $kind, 'text' => mb_substr($text, 0, 6000), 'created_at' => now()]);
            // Keep it small: the newest KEEP_PER_KIND, nothing older than KEEP_DAYS.
            $cut = DB::table('ai_content_log')->where('agency_id', $agency)->where('kind', $kind)->orderByDesc('id')->skip(self::KEEP_PER_KIND)->value('id');
            if ($cut) DB::table('ai_content_log')->where('agency_id', $agency)->where('kind', $kind)->where('id', '<=', $cut)->delete();
            if (random_int(1, 50) === 1) DB::table('ai_content_log')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        } catch (\Throwable $e) {
        }
    }

    /** The freshness rule for the system prompt. */
    private static function rule(array $recent, bool $retry): string
    {
        $r = "\n\nFRESH WRITING: this is one of many pieces of this kind people here receive, so it must not read like a template. "
            . 'Vary how it opens, how sentences start, the order of points and the words used; prefer the specific detail in the facts '
            . 'over stock phrases. Never trade accuracy for variety: every fact must still come only from what you were given.';
        $over = self::overused($recent);
        if ($over['phrases']) {
            $r .= "\nThese phrases have been used too often lately - do not use them or close variants: \"" . implode('", "', $over['phrases']) . '".';
        }
        if ($over['openings']) {
            $r .= "\nDo not open the way recent pieces did (\"" . implode('...", "', $over['openings']) . '...").';
        }
        if ($retry) {
            $r .= "\nYour last draft was too close to an earlier piece. Write it again with a different opening, a different structure and different wording.";
        }
        return $r;
    }

    /**
     * Word runs (3-5 words) found in several recent pieces, and the most repeated openings. Runs holding a capitalised
     * word mid-sentence (a name) or a digit are left out, so no family's details travel into another's prompt.
     */
    private static function overused(array $recent): array
    {
        if (count($recent) < 3) return ['phrases' => [], 'openings' => []];
        $min = count($recent) >= 8 ? 3 : 2;
        $seen = []; $opens = [];
        // Words written in lower case somewhere: everyday words. A capitalised word never seen in lower case (a name,
        // a street, a centre) is private wherever it stands, the first word of a sentence included.
        $lower = [];
        foreach ($recent as $t) {
            foreach (preg_split('/[^\p{L}\']+/u', strip_tags($t), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $w) {
                if (! preg_match('/\p{Lu}/u', $w)) $lower[$w] = true;
            }
        }
        foreach ($recent as $t) {
            $sentences = preg_split('/(?<=[.!?])\s+|\n+/u', strip_tags($t)) ?: [];
            $mine = [];
            foreach ($sentences as $si => $s) {
                $words = preg_split('/\s+/u', trim($s)) ?: [];
                $clean = [];
                foreach ($words as $wi => $w) {
                    $bare = trim($w, " \t\"'“”‘’()[]{},;:.!?-–—•*");
                    if ($bare === '') continue;
                    $private = preg_match('/\d/u', $bare) || (preg_match('/\p{Lu}/u', $bare) && ($wi > 0 || ! isset($lower[mb_strtolower($bare)])));
                    $clean[] = $private ? null : mb_strtolower($bare);
                }
                if ($si === 0 && count($clean) >= 3 && ! in_array(null, array_slice($clean, 0, 3), true)) {
                    $opens[implode(' ', array_slice($clean, 0, 3))] = ($opens[implode(' ', array_slice($clean, 0, 3))] ?? 0) + 1;
                }
                for ($n = 5; $n >= 3; $n--) {
                    for ($i = 0; $i + $n <= count($clean); $i++) {
                        $g = array_slice($clean, $i, $n);
                        if (in_array(null, $g, true)) continue;
                        $mine[implode(' ', $g)] = true;
                    }
                }
            }
            foreach ($mine as $g => $_) $seen[$g] = ($seen[$g] ?? 0) + 1;
        }
        $stop = '/^(the|a|an|and|to|of|in|on|for|with|at|is|was|it|this|that|we|our|your|you|they|their|he|she|his|her)( |$)/';
        $phr = array_filter($seen, fn ($c, $g) => $c >= $min && ! preg_match($stop . 'u', $g) && mb_strlen($g) >= 12, ARRAY_FILTER_USE_BOTH);
        uksort($phr, fn ($a, $b) => ($phr[$b] <=> $phr[$a]) ?: (mb_strlen($b) <=> mb_strlen($a)));
        // A run inside a longer chosen run says nothing new.
        $out = [];
        foreach (array_keys($phr) as $g) {
            foreach ($out as $o) if (str_contains($o, $g) || str_contains($g, $o)) continue 2;
            $out[] = $g;
            if (count($out) >= 20) break;
        }
        arsort($opens);
        $op = array_keys(array_filter($opens, fn ($c) => $c >= 2));
        return ['phrases' => $out, 'openings' => array_slice($op, 0, 6)];
    }

    /** How close $text is to the closest recent piece: Jaccard of word 3-grams, 0..1. */
    public static function closest(string $text, array $recent): float
    {
        $a = self::grams($text);
        if (! $a) return 0.0;
        $best = 0.0;
        foreach ($recent as $r) {
            $b = self::grams($r);
            if (! $b) continue;
            $inter = count(array_intersect_key($a, $b));
            $j = $inter / max(1, count($a) + count($b) - $inter);
            if ($j > $best) $best = $j;
        }
        return $best;
    }

    private static function grams(string $t): array
    {
        $w = preg_split('/[^\p{L}\p{N}\']+/u', mb_strtolower(strip_tags($t)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $g = [];
        for ($i = 0; $i + 3 <= count($w); $i++) $g[$w[$i] . ' ' . $w[$i + 1] . ' ' . $w[$i + 2]] = true;
        return $g;
    }

    /** The request with the rule added to its system prompt (a string, or a list of blocks). */
    private static function rewrite($request, string $rule)
    {
        try {
            $body = json_decode((string) $request->getBody(), true);
            if (! is_array($body)) return $request;
            $sys = $body['system'] ?? '';
            if (is_array($sys)) $sys[] = ['type' => 'text', 'text' => ltrim($rule)];
            else $sys = trim((string) $sys) . $rule;
            $body['system'] = $sys;
            return $request->withoutHeader('Content-Length')
                ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($body, JSON_UNESCAPED_UNICODE)));
        } catch (\Throwable $e) {
            return $request;
        }
    }

    private static function textOf($response): ?string
    {
        try {
            if ($response->getStatusCode() !== 200) return null;
            $body = $response->getBody();
            $raw = (string) $body;
            if ($body->isSeekable()) $body->rewind();   // the caller still reads it
            $j = json_decode($raw, true);
            if (! is_array($j)) return null;
            $out = '';
            foreach ((array) ($j['content'] ?? []) as $c) if (($c['type'] ?? '') === 'text') $out .= (string) ($c['text'] ?? '');
            return trim($out) !== '' ? $out : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The agency a piece is for, from whatever ids the caller has: agency, centre, room or child. */
    public static function agencyFrom(array $ids): ?int
    {
        try {
            if (! empty($ids['agency_id'])) return (int) $ids['agency_id'];
            $centre = ! empty($ids['centre_id']) ? (int) $ids['centre_id'] : null;
            if (! $centre && ! empty($ids['room_id'])) $centre = (int) DB::table('rooms')->where('id', (int) $ids['room_id'])->value('centre_id');
            if (! $centre && ! empty($ids['child_id'])) {
                $centre = (int) DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->where('c.id', (int) $ids['child_id'])->value('f.centre_id');
            }
            $ag = $centre ? DB::table('centres')->where('id', $centre)->value('agency_id') : null;
            return $ag ? (int) $ag : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function ready(): bool
    {
        try { return Schema::hasTable('ai_content_log'); } catch (\Throwable $e) { return false; }
    }
}
