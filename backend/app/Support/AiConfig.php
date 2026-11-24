<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * The Anthropic (Claude) connection, set in the portal (2026-10-28, Anthony: "configure this in the portal where if i
 * need to change it its accessible" + "see my claude usage and credits available etc - with full stats").
 *
 * The key is stored ENCRYPTED in platform settings (PlatformSettings::setSecret, like the Cloudways token), never
 * sent back to a browser (only its last 4), and wins over ANTHROPIC_API_KEY in .env; the model likewise. apply()
 * runs at boot (AppServiceProvider) and puts both into config('services.anthropic.*'), which every AI feature reads -
 * the controllers that used to read env() directly were moved onto config() for this.
 *
 * Credits: Anthropic does not give the prepaid balance to an API key, so the balance shown is an ESTIMATE - the
 * top-ups recorded here minus what KiddieTrac has spent since (metered per call in ai_usage) - plus the live state
 * of the account: the last successful call and the last error ("credit balance is too low" = out of credit).
 */
final class AiConfig
{
    public const KEY = 'ai.anthropic_key';
    public const MODEL = 'ai.model';
    public const TOPUPS = 'ai.topups';
    /* AI SWITCHED OFF PER AGENCY (2026-10-28, Anthony: "turn off the AI for all test agency's so it doesnt eat up our
       credits"). Platform → AI usage keeps the list. An agency on it is treated as having no AI Bundle (AiBundle::on),
       and any Claude call still made for it - Help "Ask AI", the chatbot, card scanning, a staff member's request - is
       stopped before it leaves (registerHttp), so the feature falls back exactly as when the AI is unavailable. */
    public const OFF = 'ai.off_agencies';

    /** @return int[] */
    public static function offAgencies(): array
    {
        $v = json_decode((string) PlatformSettings::get(self::OFF, '[]'), true);

        return is_array($v) ? array_values(array_unique(array_map('intval', $v))) : [];
    }

    public static function offFor(?int $agencyId): bool
    {
        // The public website demo spends no AI credit (2026-10-31, App\Support\PublicDemo).
        if (\App\Support\PublicDemo::isAgency($agencyId) || \App\Support\PublicDemo::isSession()) return true;
        // Test agencies never use AI, listed or not (2026-11-24, App\Support\TestAgency).
        if (\App\Support\TestAgency::isAgency($agencyId) || \App\Support\TestAgency::isSession()) return true;
        return $agencyId !== null && $agencyId > 0 && in_array($agencyId, self::offAgencies(), true);
    }

    /** Whose call is this: the agency set for metering, else the signed-in user's agency. */
    /** The agency the current Claude call is for (AiFresh asks). */
    public static function agencyNow(): ?int
    {
        try { return self::callAgency(); } catch (\Throwable $e) { return null; }
    }

    private static function callAgency(): ?int
    {
        if (UsageMeter::$agencyId) {
            return (int) UsageMeter::$agencyId;
        }
        if (app()->runningInConsole()) {
            return null;
        }
        try {
            // Platform screens (the key test on AI usage, website translation) act for KiddieTrac, not an agency.
            if (request()->is('api/v1/platform/*')) {
                return null;
            }
            $uid = optional(request()->user())->id;

            return $uid ? AuditScope::resolve((int) $uid, request()) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    public const MODELS = [
        'claude-haiku-5-5' => 'Claude Haiku 5.5 — fastest, lowest cost (recommended)',
        'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — stronger writing, higher cost',
        'claude-opus-5-5' => 'Claude Opus 5.5 — most capable, highest cost',
        'claude-fable-5-1' => 'Claude Fable 5.1',
    ];

    public static function key(): ?string
    {
        $k = null;
        try { $k = PlatformSettings::getSecret(self::KEY); } catch (\Throwable $e) {}
        $k = $k !== null && trim($k) !== '' ? trim($k) : null;

        return $k ?: (env('ANTHROPIC_API_KEY') ?: null);
    }

    public static function source(): string
    {
        try { $k = PlatformSettings::getSecret(self::KEY); } catch (\Throwable $e) { $k = null; }

        return ($k !== null && trim($k) !== '') ? 'portal' : (env('ANTHROPIC_API_KEY') ? 'env' : 'none');
    }

    public static function model(): string
    {
        $m = (string) PlatformSettings::get(self::MODEL, '');

        return $m !== '' ? $m : (env('ANTHROPIC_MODEL') ?: 'claude-haiku-5-5');
    }

    /** Boot: every AI feature reads config('services.anthropic.*'). */
    public static function apply(): void
    {
        try {
            $key = self::key();
            $model = self::model();
            config([
                'services.anthropic.key' => $key, 'services.anthropic.model' => $model, 'services.anthropic.translate_model' => $model,
                'integrations.anthropic_api_key' => $key, 'integrations.anthropic_model' => $model,
            ]);
        } catch (\Throwable $e) {
        }
    }

    /** One tiny call with a given key/model: [ok, http status, message]. */
    public static function test(?string $key = null, ?string $model = null): array
    {
        $key = $key ?: self::key();
        if (! $key) {
            return [false, 0, 'No key is set.'];
        }
        try {
            $r = Http::timeout(30)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])
                ->post('https://api.anthropic.com/v1/messages', ['model' => $model ?: self::model(), 'max_tokens' => 5,
                    'messages' => [['role' => 'user', 'content' => 'Reply with OK']]]);
        } catch (\Throwable $e) {
            return [false, 0, 'Anthropic did not answer just now.'];
        }
        if ($r->successful()) {
            return [true, 200, 'Connected.'];
        }

        return [false, $r->status(), self::friendly($r->status(), (string) ($r->json('error.message') ?? ''))];
    }

    /** Anthropic's error, in plain words (raw text never reaches the screen). */
    public static function friendly(int $status, string $msg): string
    {
        if (stripos($msg, 'credit balance') !== false) return 'Out of credit — add credits in the Anthropic console (Plans & Billing).';
        if ($status === 401) return 'Anthropic did not accept this key.';
        if ($status === 403) return 'This key is not allowed to use that model.';
        if ($status === 404 || stripos($msg, 'model') !== false) return 'That model is not available to this key.';
        if ($status === 429) return 'Rate limited by Anthropic — too many requests just now.';
        if ($status >= 500) return 'Anthropic is having trouble right now.';

        return 'Anthropic refused the request (' . $status . ').';
    }

    /**
     * The text of a Claude reply (2026-10-28). The FIRST TEXT block, not content[0]: Haiku 5.5 can put a
     * `thinking` block first, and content[0].text was then empty although the answer was there. Every AI
     * feature reads its reply through this.
     */
    public static function text($reply): ?string
    {
        if (! is_array($reply)) {
            return null;
        }
        $parts = [];
        foreach ((array) ($reply['content'] ?? []) as $b) {
            if (is_array($b) && ($b['type'] ?? 'text') === 'text' && isset($b['text']) && is_string($b['text'])) {
                $parts[] = $b['text'];
            }
        }

        return $parts ? implode("\n", $parts) : null;
    }

    /**
     * How to keep a model from thinking on a short writing job (2026-10-28) - each model has its own way, tested
     * live: Haiku 5.5 thinks by default and the thinking counted against max_tokens (the weekly recap came back as
     * ONE thinking block and no text at 600 tokens; 466 thinking tokens for 4 sentences at 2000). null = leave the
     * request alone (older models).
     */
    public static function thinkingFor(string $model): ?array
    {
        if (str_starts_with($model, 'claude-haiku-5')) return ['thinking' => ['type' => 'disabled']];
        if (str_starts_with($model, 'claude-sonnet-5')) return ['thinking' => ['type' => 'between_tools']];   // "disabled" is a 400 here
        if (str_starts_with($model, 'claude-opus-5') || str_starts_with($model, 'claude-fable-5')) {
            return ['thinking' => ['type' => 'adaptive'], 'output_config' => ['effort' => 'low'], '_min_tokens' => 1500];   // cannot be switched off
        }

        return null;
    }

    /**
     * One place, for every Claude call in the app (AppServiceProvider): a request that does not say how to think
     * gets thinkingFor() its model. A feature that wants thinking sets `thinking` itself and is left alone.
     */
    public static function registerHttp(): void
    {
        // An agency with AI switched off: the call never leaves (see OFF).
        try {
            \Illuminate\Support\Facades\Http::globalMiddleware(function (callable $handler) {
                return function ($request, array $options) use ($handler) {
                    try {
                        if (strtolower($request->getUri()->getHost()) === 'api.anthropic.com' && self::offFor(self::callAgency())) {
                            return \GuzzleHttp\Promise\Create::rejectionFor(
                                new \GuzzleHttp\Exception\ConnectException('AI is switched off for this agency (Platform → AI usage).', $request));
                        }
                    } catch (\Throwable $e) {
                    }

                    return $handler($request, $options);
                };
            });
        } catch (\Throwable $e) {
        }
        try {
            \Illuminate\Support\Facades\Http::globalRequestMiddleware(function ($request) {
                try {
                    $uri = $request->getUri();
                    if (strtolower($uri->getHost()) !== 'api.anthropic.com' || ! str_ends_with($uri->getPath(), '/messages')
                        || strtoupper($request->getMethod()) !== 'POST') {
                        return $request;
                    }
                    $body = json_decode((string) $request->getBody(), true);
                    if (! is_array($body) || array_key_exists('thinking', $body)) {
                        return $request;
                    }
                    $t = self::thinkingFor((string) ($body['model'] ?? ''));
                    if (! $t) {
                        return $request;
                    }
                    if (isset($t['_min_tokens'])) {
                        $body['max_tokens'] = max((int) ($body['max_tokens'] ?? 0), (int) $t['_min_tokens']);
                        unset($t['_min_tokens']);
                    }
                    $body = $body + $t;

                    return $request->withoutHeader('Content-Length')
                        ->withBody(\GuzzleHttp\Psr7\Utils::streamFor(json_encode($body, JSON_UNESCAPED_UNICODE)));
                } catch (\Throwable $e) {
                    return $request;
                }
            });
        } catch (\Throwable $e) {
        }
        // Fresh writing for creative calls (AiFresh::as): never the same opening and stock phrases twice.
        AiFresh::register();
    }

    /** Called by UsageMeter on every Anthropic reply: the account's live state. */
    public static function noteReply(bool $ok, int $status, string $msg = ''): void
    {
        try {
            if ($ok) {
                Cache::put('ai:last_ok', now()->toIso8601String(), now()->addDays(60));
            } else {
                Cache::put('ai:last_error', ['at' => now()->toIso8601String(), 'status' => $status, 'message' => self::friendly($status, $msg)], now()->addDays(60));
            }
        } catch (\Throwable $e) {
        }
    }

    public static function topups(): array
    {
        $t = json_decode((string) PlatformSettings::get(self::TOPUPS, '[]'), true);

        return is_array($t) ? array_values($t) : [];
    }

    /** Everything the AI usage screen shows. */
    public static function overview(): array
    {
        $rates = UsageSpend::rates();
        $cost = fn ($i, $o) => round($i / 1e6 * (float) $rates['ai_input_mtok'] + $o / 1e6 * (float) $rates['ai_output_mtok'], 4);
        $tz = 'America/Toronto';
        $now = \Illuminate\Support\Carbon::now($tz);
        $windows = [
            'today' => $now->copy()->startOfDay(), '7d' => $now->copy()->subDays(6)->startOfDay(),
            '30d' => $now->copy()->subDays(29)->startOfDay(), 'month' => $now->copy()->startOfMonth(),
        ];
        $totals = [];
        foreach ($windows as $k => $from) {
            $r = DB::table('ai_usage')->where('created_at', '>=', $from->copy()->utc())
                ->selectRaw('COUNT(*) n, COALESCE(SUM(input_tokens),0) i, COALESCE(SUM(output_tokens),0) o')->first();
            $totals[$k] = ['requests' => (int) $r->n, 'input' => (int) $r->i, 'output' => (int) $r->o, 'cost' => $cost((int) $r->i, (int) $r->o)];
        }
        $from30 = $windows['30d']->copy()->utc();
        $daily = [];
        // Bucketed by the TORONTO date in PHP (a fixed SQL offset would be an hour off for half the year).
        $acc = [];
        foreach (DB::table('ai_usage')->where('created_at', '>=', $from30)->orderBy('id')->get(['created_at', 'input_tokens', 'output_tokens']) as $r) {
            $d = \Illuminate\Support\Carbon::parse((string) $r->created_at, 'UTC')->setTimezone($tz)->toDateString();
            $acc[$d] = $acc[$d] ?? [0, 0, 0];
            $acc[$d][0]++; $acc[$d][1] += (int) $r->input_tokens; $acc[$d][2] += (int) $r->output_tokens;
        }
        foreach ($acc as $d => $v) {
            $daily[$d] = ['requests' => $v[0], 'cost' => $cost($v[1], $v[2])];
        }
        $series = [];
        for ($d = $windows['30d']->copy(); $d->lte($now); $d->addDay()) {
            $k = $d->toDateString();
            $series[] = ['date' => $k] + ($daily[$k] ?? ['requests' => 0, 'cost' => 0]);
        }
        $names = DB::table('agencies')->pluck('name', 'id');
        $group = function (string $col) use ($from30, $cost, $names) {
            return DB::table('ai_usage')->where('created_at', '>=', $from30)
                ->selectRaw("$col k, COUNT(*) n, SUM(input_tokens) i, SUM(output_tokens) o")->groupBy('k')->orderByDesc('n')->limit(25)->get()
                ->map(function ($r) use ($col, $cost, $names) {
                    $label = $r->k;
                    if ($col === 'agency_id') $label = $r->k ? ($names[$r->k] ?? ('Agency #' . $r->k)) : 'Platform (no agency)';
                    return ['label' => (string) ($label ?? '—'), 'requests' => (int) $r->n, 'input' => (int) $r->i, 'output' => (int) $r->o, 'cost' => $cost((int) $r->i, (int) $r->o)];
                })->values()->all();
        };
        $topups = self::topups();
        $credit = null;
        if ($topups) {
            $added = array_sum(array_map(fn ($t) => (float) $t['amount'], $topups));
            $first = min(array_map(fn ($t) => (string) $t['date'], $topups));
            $r = DB::table('ai_usage')->where('created_at', '>=', \Illuminate\Support\Carbon::parse($first, $tz)->startOfDay()->utc())
                ->selectRaw('COALESCE(SUM(input_tokens),0) i, COALESCE(SUM(output_tokens),0) o')->first();
            $spent = $cost((int) $r->i, (int) $r->o);
            $burn = $totals['7d']['cost'] / 7;
            $left = round($added - $spent, 2);
            $credit = ['added' => round($added, 2), 'since' => $first, 'spent' => round($spent, 2), 'left' => $left,
                'per_day' => round($burn, 4), 'days_left' => $burn > 0 ? (int) floor(max(0, $left) / $burn) : null];
        }
        $key = self::key();

        $off = self::offAgencies();
        return [
            'agencies' => DB::table('agencies')->whereNull('deleted_at')->orderBy('name')->get(['id', 'name', 'billing_status'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'name' => $a->name, 'status' => (string) $a->billing_status, 'ai_off' => in_array((int) $a->id, $off, true)])->values()->all(),
            'connection' => [
                'source' => self::source(), 'key_last4' => $key ? substr($key, -4) : null, 'model' => self::model(),
                'last_ok' => Cache::get('ai:last_ok'), 'last_error' => Cache::get('ai:last_error'),
            ],
            'models' => self::MODELS,
            'rates' => ['input_mtok' => (float) $rates['ai_input_mtok'], 'output_mtok' => (float) $rates['ai_output_mtok']],
            'totals' => $totals, 'daily' => $series,
            'by_agency' => $group('agency_id'), 'by_feature' => $group('feature'), 'by_model' => $group('model'),
            'credit' => $credit, 'topups' => $topups,
        ];
    }
}
