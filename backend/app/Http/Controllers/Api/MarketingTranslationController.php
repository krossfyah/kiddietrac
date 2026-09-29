<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Marketing-site auto-translation (2026-09-28).
 *
 * The page (www.kiddietrac.com) looks every piece of text up in a dictionary. What is
 * missing — new content added after the dictionaries were made — it reports here. We
 * translate it once with Claude, store it (status 'auto', live straight away, as Anthony
 * chose) and serve it to every later visitor. People correct translations in
 * Website → Translations; a corrected one ('edited') is never overwritten.
 *
 * Guard rails, because this endpoint is public:
 *   - Only text that actually appears on the published site is translated (checked against
 *     public_html/index.html and the Website settings) — it is not a free translation API.
 *   - Throttled, capped per request, de-duplicated, each key translated at most once.
 *   - The translation runs after the response, so reporting never slows the page.
 */
class MarketingTranslationController extends Controller
{
    private const LANGS = ['fr' => 'Canadian French (fr-CA)', 'es' => 'neutral Latin-American Spanish', 'hi' => 'Hindi (Devanagari)'];
    private const MAX_ITEMS = 40;
    private const MAX_LEN = 2000;          // a sentence or paragraph
    private const MAX_POST_LEN = 16000;    // a whole blog article body (keys "post|…")

    // ───────────────────────── public ─────────────────────────

    /** GET /marketing-site/i18n/{lang}/extra — every auto/edited translation for a language. */
    public function extra(string $lang): JsonResponse
    {
        if (! isset(self::LANGS[$lang])) {
            return $this->cors(response()->json([], 404));
        }
        $map = Cache::remember('mkt-i18n-extra-' . $lang, 600, function () use ($lang) {
            return DB::table('marketing_translations')->where('lang', $lang)
                ->pluck('text', 'k')->all();
        });

        return $this->cors(response()->json((object) $map)->header('Cache-Control', 'public, max-age=120'));
    }

    /**
     * POST /marketing-site/i18n/missing {lang, items: [{k, en}]}
     * The page reporting text it has no translation for.
     */
    public function missing(Request $request): JsonResponse
    {
        $lang = (string) $request->input('lang');
        $items = $request->input('items');
        if (! isset(self::LANGS[$lang]) || ! is_array($items)) {
            return $this->cors(response()->json(['ok' => false], 422));
        }
        $corpus = $this->corpus();
        $known = DB::table('marketing_translations')->where('lang', $lang)->pluck('k_hash')->flip();
        $todo = [];
        foreach (array_slice($items, 0, self::MAX_ITEMS) as $it) {
            $k = is_array($it) ? (string) ($it['k'] ?? '') : '';
            $en = is_array($it) ? (string) ($it['en'] ?? '') : '';
            if ($k === '' || $en === '') {
                continue;
            }
            $isPost = str_starts_with($k, 'post|');
            if (mb_strlen($en) > ($isPost ? self::MAX_POST_LEN : self::MAX_LEN) || mb_strlen($k) > self::MAX_POST_LEN + 200) {
                continue;
            }
            $h = sha1($k);
            if (isset($known[$h])) {
                continue;
            }
            if (! $this->onSite($en, $corpus)) {
                continue;
            }
            // one translation per key at a time, however many visitors report it
            if (! Cache::add('mkt-i18n-lock-' . $lang . '-' . $h, 1, 900)) {
                continue;
            }
            $todo[] = ['k' => $k, 'en' => $en, 'h' => $h];
        }
        if ($todo) {
            $run = function () use ($lang, $todo) {
                @set_time_limit(300);
                $this->translateAndStore($lang, $todo);
            };
            if (app()->runningInConsole()) {
                $run();
            } else {
                dispatch($run)->afterResponse();
            }
        }

        return $this->cors(response()->json(['ok' => true, 'queued' => count($todo)]));
    }

    // ───────────────────────── staff (Website → Translations) ─────────────────────────

    /** GET /marketing-site/translations?lang=&status=&q= */
    public function index(Request $request): JsonResponse
    {
        $q = DB::table('marketing_translations');
        if (isset(self::LANGS[(string) $request->query('lang')])) {
            $q->where('lang', $request->query('lang'));
        }
        if (in_array($request->query('status'), ['auto', 'edited'], true)) {
            $q->where('status', $request->query('status'));
        }
        if ($s = trim((string) $request->query('q'))) {
            $q->where(function ($w) use ($s) {
                $w->where('en', 'like', '%' . $s . '%')->orWhere('text', 'like', '%' . $s . '%');
            });
        }
        $rows = $q->orderByDesc('updated_at')->limit(500)->get(['id', 'lang', 'k', 'en', 'text', 'status', 'updated_at']);

        return response()->json([
            'translations' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'lang' => $r->lang, 'status' => $r->status,
                'kind' => str_starts_with($r->k, 'post|') ? 'Blog article' : (str_starts_with($r->k, 'A|') ? 'Label' : 'Page text'),
                'en' => $r->en, 'text' => $r->text,
                'updated_at' => $r->updated_at ? \Carbon\Carbon::parse($r->updated_at, config('app.timezone'))->utc()->toIso8601ZuluString() : null,
            ])->values(),
            'last_error' => Cache::get('mkt-i18n-last-error') ?: $this->probe(),
            'configured' => (bool) (config('services.anthropic.key') ?: config('services.anthropic.api_key')),
            'counts' => DB::table('marketing_translations')->select('lang', 'status', DB::raw('count(*) c'))->groupBy('lang', 'status')->get(),
        ]);
    }

    /**
     * Is the translation API actually usable right now? The last-error marker only knows
     * about a translation that was attempted; with nothing attempted yet it would call an
     * out-of-credit account healthy. A 1-token request answers for real; cached 10 minutes.
     */
    private function probe(): ?array
    {
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) {
            return null;
        }
        $r = Cache::remember('mkt-i18n-probe', 600, function () use ($key) {
            try {
                $res = Http::timeout(15)->withHeaders([
                    'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
                ])->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-sonnet-4-6',
                    'max_tokens' => 1,
                    'messages' => [['role' => 'user', 'content' => 'ok']],
                ]);
                if ($res->successful()) {
                    return ['ok' => true];
                }

                return ['at' => now()->toIso8601String(), 'status' => $res->status(),
                    'message' => mb_substr((string) ($res->json('error.message') ?? $res->body()), 0, 300)];
            } catch (\Throwable $e) {
                return ['at' => now()->toIso8601String(), 'status' => 0, 'message' => mb_substr($e->getMessage(), 0, 300)];
            }
        });

        return empty($r['ok']) ? $r : null;
    }

    /** PUT /marketing-site/translations/{id} {text} — a person's correction; sticks. */
    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['text' => 'required|string|max:' . (self::MAX_POST_LEN + 4000)]);
        $row = DB::table('marketing_translations')->where('id', $id)->first();
        abort_unless($row, 404);
        if (! $this->markersMatch($row->en, $data['text'], $row->k)) {
            return response()->json(['message' => 'The translation must keep the same {0}…{/0} markers (and HTML tags) as the English — they are the links, bold words and icons.'], 422);
        }
        DB::table('marketing_translations')->where('id', $id)->update([
            'text' => $data['text'], 'status' => 'edited', 'updated_by' => auth()->id(), 'updated_at' => now(),
        ]);
        Cache::forget('mkt-i18n-extra-' . $row->lang);

        return response()->json(['ok' => true]);
    }

    /** DELETE /marketing-site/translations/{id} — drop it; the page will ask for a fresh one. */
    public function destroy(int $id): JsonResponse
    {
        $row = DB::table('marketing_translations')->where('id', $id)->first();
        abort_unless($row, 404);
        DB::table('marketing_translations')->where('id', $id)->delete();
        Cache::forget('mkt-i18n-extra-' . $row->lang);
        Cache::forget('mkt-i18n-lock-' . $row->lang . '-' . $row->k_hash);

        return response()->json(['ok' => true]);
    }

    // ───────────────────────── the work ─────────────────────────

    /** The published site's text, to check a reported string really is on it. */
    private function corpus(): string
    {
        $path = dirname(base_path(), 2) . '/public_html/index.html';
        $mtime = @filemtime($path) ?: 0;
        $cfg = '';
        try {
            if (Storage::disk('local')->exists('marketing-site.json')) {
                $cfg = (string) Storage::disk('local')->get('marketing-site.json');
            }
        } catch (\Throwable $e) {
        }

        return Cache::remember('mkt-i18n-corpus-' . $mtime . '-' . md5($cfg), 3600, function () use ($path, $cfg) {
            $raw = (string) @file_get_contents($path) . ' ' . $cfg;
            // text that lives inside script data (blog posts, shop items) is escaped there
            $raw = str_replace(['<\\/', '\\"', "\\'", '\\n', '\\u2060', '\\u2014', '\\u2019'], ['</', '"', "'", ' ', '', '—', '’'], $raw);
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return $this->squash($raw);
        });
    }

    private function squash(string $s): string
    {
        $s = str_replace("\u{2060}", '', $s);
        $s = preg_replace('/\{\/?\d+\/?\}/', ' ', $s);
        $s = html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return mb_strtolower(preg_replace('/\s+/u', '', $s));
    }

    private function onSite(string $en, string $corpus): bool
    {
        $needle = $this->squash($en);
        if ($needle === '') {
            return false;
        }
        if (mb_strlen($needle) > 400) {
            // a long body: its opening and closing must both be there
            return str_contains($corpus, mb_substr($needle, 0, 160)) && str_contains($corpus, mb_substr($needle, -160));
        }

        return str_contains($corpus, $needle);
    }

    private function translateAndStore(string $lang, array $todo): void
    {
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) {
            Log::warning('marketing i18n: no Anthropic key; ' . count($todo) . ' strings left untranslated');
            return;
        }
        // articles one at a time (long), everything else in batches
        $batches = [];
        $small = [];
        foreach ($todo as $t) {
            if (str_starts_with($t['k'], 'post|') && mb_strlen($t['en']) > 1500) {
                $batches[] = [$t];
            } else {
                $small[] = $t;
            }
        }
        foreach (array_chunk($small, 20) as $c) {
            $batches[] = $c;
        }
        foreach ($batches as $batch) {
            try {
                $out = $this->callClaude($key, $lang, $batch);
            } catch (\Throwable $e) {
                Log::warning('marketing i18n: translation failed: ' . $e->getMessage());
                continue;
            }
            foreach ($batch as $i => $t) {
                $tr = $out[(string) $i] ?? null;
                if (! is_string($tr) || trim($tr) === '' || ! $this->markersMatch($t['en'], $tr, $t['k'])) {
                    Log::info('marketing i18n: rejected a translation for ' . mb_substr($t['k'], 0, 80));
                    continue;
                }
                DB::table('marketing_translations')->insertOrIgnore([
                    'lang' => $lang, 'k_hash' => $t['h'], 'k' => $t['k'], 'en' => $t['en'], 'text' => $tr,
                    'status' => 'auto', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            Cache::forget('mkt-i18n-extra-' . $lang);
        }
    }

    private function callClaude(string $key, string $lang, array $batch): array
    {
        $items = [];
        foreach ($batch as $i => $t) {
            $items[(string) $i] = $t['en'];
        }
        $prompt = "Translate website text for KiddieTrac, a Canadian childcare management platform, into "
            . self::LANGS[$lang] . ".\n\nRules:\n"
            . "- Keep every marker exactly: {0}…{/0} wrap words (links, bold); {3/} is a line break or icon. Same markers, properly nested; you may move them with the words they belong to.\n"
            . "- If a value contains HTML tags, keep the SAME tags in the same order and nesting; translate only the text.\n"
            . "- Keep unchanged: KiddieTrac, CWELCC, QR, Maya, brand names, plan names (Starter, Professional, Enterprise), emails, URLs, prices, emoji.\n"
            . ($lang === 'fr'
                ? "- Canadian French childcare vocabulary: service de garde, garderie en milieu familial, éducatrice/éducateur, facturation, inscription.\n"
                : "- Neutral Spanish, formal 'usted': guardería, centro de cuidado infantil, educadora/educador, facturación.\n")
            . "- Warm, plain marketing tone; similar length to the English. Add nothing that is not in the English.\n\n"
            . "Input JSON (id → English):\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            . "\n\nReply with ONLY a JSON object mapping the same ids to the translations.";

        $res = Http::timeout(120)->withHeaders([
            'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json',
        ])->post('https://api.anthropic.com/v1/messages', [
            'model' => config('services.anthropic.model') ?: 'claude-sonnet-4-6',
            'max_tokens' => 8000,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]);
        if (! $res->successful()) {
            // Said in words where the portal can show it: "credit balance is too low" looks
            // exactly like "broken" from the outside otherwise.
            $msg = (string) ($res->json('error.message') ?? $res->body());
            Cache::put('mkt-i18n-last-error', ['at' => now()->toIso8601String(), 'status' => $res->status(), 'message' => mb_substr($msg, 0, 300)], 86400 * 7);
            throw new \RuntimeException('Anthropic ' . $res->status() . ': ' . mb_substr($msg, 0, 200));
        }
        Cache::forget('mkt-i18n-last-error');
        Cache::forget('mkt-i18n-probe');
        $text = (string) ($res->json('content.0.text') ?? '');
        $a = strpos($text, '{');
        $b = strrpos($text, '}');
        $json = ($a !== false && $b !== false) ? json_decode(substr($text, $a, $b - $a + 1), true) : null;
        if (! is_array($json)) {
            throw new \RuntimeException('no JSON in the reply');
        }

        return $json;
    }

    /** Same {n}/{/n}/{n/} markers (and, for article bodies, the same HTML tags) as the English. */
    private function markersMatch(string $en, string $tr, string $k): bool
    {
        $marks = function ($s) {
            preg_match_all('/\{(\d+)(\/?)\}|\{\/(\d+)\}/', $s, $m, PREG_SET_ORDER);
            $out = [];
            foreach ($m as $x) {
                $out[] = isset($x[3]) && $x[3] !== '' ? '/' . $x[3] : $x[1] . $x[2];
            }
            sort($out);

            return $out;
        };
        if ($marks($en) !== $marks($tr)) {
            return false;
        }
        if (str_starts_with($k, 'post|')) {
            preg_match_all('/<\/?([a-z0-9]+)/i', $en, $a);
            preg_match_all('/<\/?([a-z0-9]+)/i', $tr, $b);

            return $a[0] === $b[0];
        }

        return true;
    }

    private function cors(JsonResponse $r): JsonResponse
    {
        return $r->header('Access-Control-Allow-Origin', '*');
    }
}
