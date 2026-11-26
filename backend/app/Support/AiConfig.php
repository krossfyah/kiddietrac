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

    /** Everything the AI usage screen shows. Costs are priced per model (AiPricing, 2026-11-26). The tables by
     *  agency / feature / model / user are breakdown(), for the Breakdown tab's own date range. */
    public static function overview(): array
    {
        $rates = UsageSpend::rates();
        // Sum of per-model costs over a query that selects AiPricing::sumsSql() grouped by model.
        $priced = function ($q) {
            $n = 0; $i = 0; $o = 0; $c = 0.0;
            foreach ($q->selectRaw('model, ' . AiPricing::sumsSql())->groupBy('model')->get() as $r) {
                $n += (int) $r->n; $i += (int) $r->i; $o += (int) $r->o; $c += AiPricing::rowCost($r);
            }
            return ['requests' => $n, 'input' => $i, 'output' => $o, 'cost' => round($c, 4)];
        };
        $tz = 'America/Toronto';
        $now = \Illuminate\Support\Carbon::now($tz);
        $windows = [
            'today' => $now->copy()->startOfDay(), '7d' => $now->copy()->subDays(6)->startOfDay(),
            '30d' => $now->copy()->subDays(29)->startOfDay(), 'month' => $now->copy()->startOfMonth(),
        ];
        $totals = [];
        foreach ($windows as $k => $from) {
            $totals[$k] = $priced(DB::table('ai_usage')->where('created_at', '>=', $from->copy()->utc()));
        }
        $from30 = $windows['30d']->copy()->utc();
        $daily = [];
        // Bucketed by the TORONTO date in PHP (a fixed SQL offset would be an hour off for half the year).
        $acc = [];
        foreach (DB::table('ai_usage')->where('created_at', '>=', $from30)->orderBy('id')->get(['created_at', 'model', 'input_tokens', 'output_tokens']) as $r) {
            $d = \Illuminate\Support\Carbon::parse((string) $r->created_at, 'UTC')->setTimezone($tz)->toDateString();
            $acc[$d] = $acc[$d] ?? [0, 0.0];
            $long = (int) $r->input_tokens > AiPricing::LONG_PROMPT;
            $acc[$d][0]++;
            $acc[$d][1] += AiPricing::cost($r->model, (int) $r->input_tokens, (int) $r->output_tokens, $long ? (int) $r->input_tokens : 0, $long ? (int) $r->output_tokens : 0);
        }
        foreach ($acc as $d => $v) {
            $daily[$d] = ['requests' => $v[0], 'cost' => round($v[1], 4)];
        }
        $series = [];
        for ($d = $windows['30d']->copy(); $d->lte($now); $d->addDay()) {
            $k = $d->toDateString();
            $series[] = ['date' => $k] + ($daily[$k] ?? ['requests' => 0, 'cost' => 0]);
        }
        $topups = self::topups();
        $credit = null;
        if ($topups) {
            $added = array_sum(array_map(fn ($t) => (float) $t['amount'], $topups));
            $first = min(array_map(fn ($t) => (string) $t['date'], $topups));
            $spent = $priced(DB::table('ai_usage')->where('created_at', '>=', \Illuminate\Support\Carbon::parse($first, $tz)->startOfDay()->utc()))['cost'];
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
            'prices' => AiPricing::table(), 'long_prompt' => AiPricing::LONG_PROMPT,
            'totals' => $totals, 'daily' => $series,
            'credit' => $credit, 'topups' => $topups,
        ];
    }

    /* ── BREAKDOWN, incl. BY USER (2026-11-26, Anthony: "for the AI usage report under the breakdown substab add another
       substab to clean up the data presented in that main screen and add by user as well to see which users are using
       AI"). One date range (Toronto dates, inclusive) for every table; each row priced per model. A call with no
       person (nightly jobs, the website chatbot) is "System". Platform admins only: the routes sit in the
       role:platform_admin group, so nothing here is scoped to an agency. */

    /** [fromUtc, toUtc, fromDate, toDate] for an inclusive Toronto date range; at most 366 days, default the last 30. */
    public static function range(?string $from, ?string $to): array
    {
        $tz = 'America/Toronto';
        $now = \Illuminate\Support\Carbon::now($tz);
        try { $f = $from ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay() : null; } catch (\Throwable $e) { $f = null; }
        try { $e2 = $to ? \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay() : null; } catch (\Throwable $e) { $e2 = null; }
        $e2 = $e2 ?: $now->copy()->endOfDay();
        $f = $f ?: $e2->copy()->subDays(29)->startOfDay();
        if ($f->gt($e2)) [$f, $e2] = [$e2->copy()->startOfDay(), $f->copy()->endOfDay()];
        if ($f->diffInDays($e2) > 366) $f = $e2->copy()->subDays(365)->startOfDay();

        return [$f->copy()->utc(), $e2->copy()->utc(), $f->toDateString(), $e2->toDateString()];
    }

    /** What a feature key means, in words (the raw route / command stays alongside it on screen). */
    public static function featureLabel(?string $k): string
    {
        $k = (string) $k;
        static $known = [
            'cli:kiddietrac:generate-digests' => 'Nightly daily digests',
            'cli:kiddietrac:parent-summary' => 'Parent daily summaries',
            'cli:kiddietrac:educator-summary' => 'Educator daily summaries',
            'cli:kiddietrac:admin-digest' => 'Admin digest',
            'cli:kt:minute' => 'Every-minute jobs',
            'cli:kt:lesson-images' => 'Lesson plan pictures (scheduled)',
            'cli:queue:work' => 'Background jobs',
            'cli:unknown' => 'Server task',
            'api/v1/provider/lesson-plans/fill-images' => 'Lesson plan pictures',
            'api/v1/ai/assist/ask' => 'Ask AI',
            'api/v1/ai/assist/write' => 'AI writing help',
            'api/v1/ai/assist/food-photo' => 'Food photo reading',
            'api/v1/ai/weekly-recap/{child}' => 'Weekly recap',
            'api/v1/help/ask' => 'Help: Ask AI',
            'api/v1/marketing-site/chat/ai' => 'Website chatbot',
            'api/v1/provider/play-spark/generate' => 'Play Spark ideas',
            'api/v1/provider/play-spark/scan' => 'Play Spark shelf scan',
            'api/v1/provider/observations/structure' => 'Observation writing help',
            'api/v1/report-cards/generate' => 'Report cards',
            'api/v1/parent/children/{child}/digest/{date}' => "Parent: child's day digest",
            'api/v1/platform/marketing-site/translations' => 'Website translations',
            'api/v1/platform/ai/test' => 'Connection test',
            'api/v1/educator-points/thanks/note' => 'Thank-you notes',
            'api/v1/sales/prospects/parse' => 'Sales prospect reading',
        ];
        if (isset($known[$k])) return $known[$k];
        if (str_starts_with($k, 'job:')) {
            $n = substr($k, 4);
            return 'Background: ' . trim(preg_replace('/(?<!^)([A-Z])/', ' $1', $n));
        }
        if (str_starts_with($k, 'cli:')) return 'Server task: ' . substr($k, 4);
        $path = preg_replace('#^api/v1/#', '', $k);
        $path = preg_replace('#\{[^}]+\}#', '', (string) $path);
        $words = trim(preg_replace('#[/_\-]+#', ' ', (string) $path));

        return $words !== '' ? ucfirst($words) : 'Other';
    }

    private const ROLE_RANK = ['platform_admin' => 1, 'reseller' => 2, 'agency_admin' => 3, 'centre_director' => 4, 'home_visitor' => 5,
        'educator' => 6, 'cook' => 7, 'staff' => 8, 'guardian' => 9];

    private static function roleLabel(?string $r): string
    {
        return $r ? ucwords(str_replace('_', ' ', $r === 'platform_admin' ? 'super admin' : $r)) : '';
    }

    /** GET /platform/ai/breakdown?from&to - KPIs plus every table for the range. */
    public static function breakdown(?string $from, ?string $to): array
    {
        [$fu, $tu, $fd, $td] = self::range($from, $to);
        $base = fn () => DB::table('ai_usage')->whereBetween('created_at', [$fu, $tu]);
        $names = DB::table('agencies')->pluck('name', 'id');

        // One pass grouped by everything a table needs, priced per model; tables fold it.
        $rows = $base()->selectRaw('agency_id, user_id, feature, model, MAX(created_at) last_at, ' . AiPricing::sumsSql())
            ->groupBy('agency_id', 'user_id', 'feature', 'model')->get();
        $kpi = ['requests' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0, 'users' => 0, 'system_requests' => 0, 'unpriced_models' => []];
        $fold = [];
        $add = function (string $table, $key, $r, float $c) use (&$fold) {
            $k = (string) $key;
            $x = $fold[$table][$k] ?? ['key' => $key, 'requests' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0, 'last_at' => null, 'features' => []];
            $x['requests'] += (int) $r->n; $x['input'] += (int) $r->i; $x['output'] += (int) $r->o; $x['cost'] += $c;
            if (! $x['last_at'] || (string) $r->last_at > $x['last_at']) $x['last_at'] = (string) $r->last_at;
            if ($table === 'user') {
                $x['features'][(string) $r->feature] = ($x['features'][(string) $r->feature] ?? 0) + (int) $r->n;
                $x['agencies'][(int) $r->agency_id] = ($x['agencies'][(int) $r->agency_id] ?? 0) + (int) $r->n;
            }
            $fold[$table][$k] = $x;
        };
        foreach ($rows as $r) {
            $c = AiPricing::rowCost($r);
            $kpi['requests'] += (int) $r->n; $kpi['input'] += (int) $r->i; $kpi['output'] += (int) $r->o; $kpi['cost'] += $c;
            if (! $r->user_id) $kpi['system_requests'] += (int) $r->n;
            if ($r->model && ! AiPricing::known($r->model)) $kpi['unpriced_models'][$r->model] = true;
            $add('agency', $r->agency_id ? (int) $r->agency_id : 0, $r, $c);
            $add('feature', (string) $r->feature, $r, $c);
            $add('model', (string) $r->model, $r, $c);
            $add('user', $r->user_id ? (int) $r->user_id : 0, $r, $c);
        }
        $kpi['users'] = count(array_filter(array_keys($fold['user'] ?? []), fn ($k) => (int) $k > 0));
        $kpi['cost'] = round($kpi['cost'], 4);
        $kpi['unpriced_models'] = array_keys($kpi['unpriced_models']);
        $iso = fn ($s) => $s ? \Illuminate\Support\Carbon::parse($s, 'UTC')->toIso8601String() : null;
        $out = function (string $table, callable $label) use (&$fold, $iso) {
            $list = array_values(array_map(function ($x) use ($label, $iso) {
                $row = ['key' => $x['key'], 'requests' => $x['requests'], 'input' => $x['input'], 'output' => $x['output'],
                    'cost' => round($x['cost'], 4), 'last_at' => $iso($x['last_at'])];
                return $row + $label($x);
            }, $fold[$table] ?? []));
            usort($list, fn ($a, $b) => ($b['cost'] <=> $a['cost']) ?: ($b['requests'] <=> $a['requests']));
            return $list;
        };

        $byUser = $out('user', fn ($x) => ['features' => $x['features'], 'agencies' => $x['agencies'] ?? []]);
        $ids = array_values(array_filter(array_map(fn ($u) => (int) $u['key'], $byUser)));
        $people = $ids ? DB::table('users')->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'preferred_name', 'email', 'deleted_at'])->keyBy('id') : collect();
        $roles = $ids ? DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->whereIn('ra.user_id', $ids)->where('ra.active', 1)
            ->get(['ra.user_id', 'ra.role', 'ra.agency_id', 'ra.centre_id', 'c.agency_id as c_agency', 'c.name as centre'])->groupBy('user_id') : collect();
        // Parents hold no role row for their centre (it is on their family); show it when they have one.
        $guardianCentres = $ids ? DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->whereIn('g.user_id', $ids)->get(['g.user_id', 'c.agency_id', 'c.name'])->groupBy('user_id') : collect();
        foreach ($byUser as &$u) {
            $agencyCounts = $u['agencies']; arsort($agencyCounts);
            $agencyId = (int) (array_key_first($agencyCounts) ?? 0);
            $feat = $u['features']; arsort($feat);
            $u['top_features'] = array_map(fn ($k, $n) => ['key' => $k, 'label' => self::featureLabel($k), 'requests' => $n], array_slice(array_keys($feat), 0, 3), array_slice(array_values($feat), 0, 3));
            unset($u['features'], $u['agencies']);
            $u['agency'] = $agencyId ? (string) ($names[$agencyId] ?? ('Agency #' . $agencyId)) : 'No agency (platform)';
            $u['agency_count'] = count(array_filter(array_keys($agencyCounts)));
            if (! (int) $u['key']) {
                $u += ['name' => 'System', 'system' => true, 'role' => 'Automatic', 'centre' => '', 'email' => ''];
                continue;
            }
            $p = $people[(int) $u['key']] ?? null;
            $nm = $p ? trim(($p->preferred_name ?: $p->first_name) . ' ' . $p->last_name) : '';
            $u['name'] = $nm !== '' ? $nm : 'Removed person';
            $u['email'] = $p ? (string) $p->email : '';
            $u['removed'] = ! $p || $p->deleted_at !== null;
            // Their role at the agency they used AI in (else their highest role anywhere), and that role's centre.
            $ra = collect($roles[(int) $u['key']] ?? []);
            $here = $ra->filter(fn ($r) => $agencyId && ((int) $r->agency_id === $agencyId || (int) $r->c_agency === $agencyId));
            // A super admin is a super admin wherever they used it (their platform row has no agency; a test parent
            // account at the same agency must not relabel them "Guardian").
            $plat = $ra->where('role', 'platform_admin');
            $pick = ($plat->isNotEmpty() ? $plat : ($here->isNotEmpty() ? $here : $ra))->sortBy(fn ($r) => self::ROLE_RANK[$r->role] ?? 50)->values();
            $top = $pick->first();
            $u['role'] = $top ? self::roleLabel($top->role) : '';
            $centres = $pick->where('role', $top->role ?? null)->pluck('centre')->filter()->unique()->values();
            $gc = collect($guardianCentres[(int) $u['key']] ?? [])->filter(fn ($g) => ! $agencyId || (int) $g->agency_id === $agencyId);
            if (! $top && $gc->isNotEmpty()) $u['role'] = 'Guardian';
            if ($centres->isEmpty() && (! $top || $top->role === 'guardian')) $centres = $gc->pluck('name')->filter()->unique()->values();
            $u['centre'] = $centres->isEmpty() ? '' : $centres->first() . ($centres->count() > 1 ? ' +' . ($centres->count() - 1) : '');
        }
        unset($u);

        return [
            'from' => $fd, 'to' => $td, 'kpi' => $kpi,
            'by_user' => $byUser,
            'by_agency' => $out('agency', fn ($x) => ['label' => (int) $x['key'] ? (string) ($names[(int) $x['key']] ?? ('Agency #' . $x['key'])) : 'Platform (no agency)']),
            'by_feature' => $out('feature', fn ($x) => ['label' => self::featureLabel($x['key'])]),
            'by_model' => $out('model', fn ($x) => ['label' => (string) $x['key'] ?: 'unknown', 'priced' => AiPricing::known($x['key']),
                'rates' => array_combine(['in', 'out', 'long_in', 'long_out'], AiPricing::rates($x['key']))]),
            'prices' => AiPricing::table(), 'long_prompt' => AiPricing::LONG_PROMPT,
        ];
    }

    /** GET /platform/ai/users/{id}/calls - one person's (0 = System's) latest calls in the range: what, when, tokens,
     *  cost. ai_usage keeps no prompt or answer, so none can be shown. */
    public static function userCalls(int $userId, ?string $from, ?string $to, int $limit = 200): array
    {
        [$fu, $tu, $fd, $td] = self::range($from, $to);
        $q = DB::table('ai_usage')->whereBetween('created_at', [$fu, $tu]);
        $userId > 0 ? $q->where('user_id', $userId) : $q->whereNull('user_id');
        $total = (clone $q)->count();
        $names = DB::table('agencies')->pluck('name', 'id');
        $calls = $q->orderByDesc('id')->limit($limit)->get(['agency_id', 'feature', 'model', 'input_tokens', 'output_tokens', 'created_at'])
            ->map(function ($r) use ($names) {
                $long = (int) $r->input_tokens > AiPricing::LONG_PROMPT;
                return [
                    'at' => \Illuminate\Support\Carbon::parse((string) $r->created_at, 'UTC')->toIso8601String(),
                    'feature' => (string) $r->feature, 'label' => self::featureLabel($r->feature), 'model' => (string) $r->model,
                    'agency' => $r->agency_id ? (string) ($names[$r->agency_id] ?? ('Agency #' . $r->agency_id)) : '',
                    'input' => (int) $r->input_tokens, 'output' => (int) $r->output_tokens,
                    'cost' => round(AiPricing::cost($r->model, (int) $r->input_tokens, (int) $r->output_tokens, $long ? (int) $r->input_tokens : 0, $long ? (int) $r->output_tokens : 0), 5),
                ];
            })->values()->all();

        return ['from' => $fd, 'to' => $td, 'total' => $total, 'shown' => count($calls), 'calls' => $calls];
    }
}
