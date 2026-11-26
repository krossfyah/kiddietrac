<?php

namespace App\Support;

/**
 * WHAT A CLAUDE CALL COSTS, PER MODEL (2026-11-26).
 *
 * Until now every AI cost was one flat rate ($3 in / $15 out per million tokens, Usage & spend's AI rates) for every
 * model. KiddieTrac mostly runs on Haiku, which is far cheaper, and draws pictures on Sonnet, so the estimates were off by
 * a long way. Prices are Anthropic's list prices in US$ per million tokens [input, output]. Haiku 5.5 costs more for a
 * prompt over 100K tokens ('long'); that is decided per call, so the sums carry the long-prompt tokens separately
 * (sumsSql()). A model not listed here (a new one) falls back to the flat AI rates in Usage & spend.
 *
 * Every reader of ai_usage that shows money prices it here: AiConfig (Platform → AI usage) and UsageSpend
 * (Usage & spend, the weekly summary and the per-agency AI threshold alerts).
 */
class AiPricing
{
    /** Longest matching prefix wins, so a dated id (claude-haiku-5-5-20261001) prices as its family. */
    public const MODELS = [
        'claude-haiku-5-5' => ['in' => 0.10, 'out' => 0.50, 'long' => [0.50, 2.50]],
        'claude-sonnet-5-5' => ['in' => 2.00, 'out' => 10.00],
        'claude-sonnet-5' => ['in' => 2.00, 'out' => 10.00],
        'claude-opus-5-5' => ['in' => 4.00, 'out' => 20.00],
        'claude-opus-5' => ['in' => 5.00, 'out' => 25.00],
        'claude-fable-5-2' => ['in' => 8.00, 'out' => 40.00],
        'claude-fable-5-1' => ['in' => 10.00, 'out' => 50.00],
        'claude-fable-5' => ['in' => 10.00, 'out' => 50.00],
    ];

    /** A prompt above this many input tokens is priced at the model's 'long' rate (when it has one). */
    public const LONG_PROMPT = 100000;

    private static ?array $flat = null;

    /** [in, out, longIn, longOut] in $ per million tokens. */
    public static function rates(?string $model): array
    {
        $m = strtolower(trim((string) $model));
        $best = null;
        foreach (self::MODELS as $prefix => $p) {
            if (str_starts_with($m, $prefix) && ($best === null || strlen($prefix) > strlen($best))) $best = $prefix;
        }
        if ($best !== null) {
            $p = self::MODELS[$best];
            $long = $p['long'] ?? [$p['in'], $p['out']];

            return [$p['in'], $p['out'], $long[0], $long[1]];
        }
        if (self::$flat === null) {
            $r = UsageSpend::rates();
            self::$flat = [(float) $r['ai_input_mtok'], (float) $r['ai_output_mtok']];
        }

        return [self::$flat[0], self::$flat[1], self::$flat[0], self::$flat[1]];
    }

    /** Whether a model is priced from the list above (false = the flat fallback rate). */
    public static function known(?string $model): bool
    {
        $m = strtolower(trim((string) $model));
        foreach (array_keys(self::MODELS) as $prefix) {
            if (str_starts_with($m, $prefix)) return true;
        }

        return false;
    }

    /** Cost of $in/$out tokens on $model, of which $inLong/$outLong came from long prompts. Unrounded. */
    public static function cost(?string $model, int $in, int $out, int $inLong = 0, int $outLong = 0): float
    {
        [$ri, $ro, $li, $lo] = self::rates($model);

        return (($in - $inLong) * $ri + ($out - $outLong) * $ro + $inLong * $li + $outLong * $lo) / 1e6;
    }

    /** Cost of one grouped row that selected sumsSql() and the model column. */
    public static function rowCost(object $r): float
    {
        return self::cost((string) ($r->model ?? ''), (int) ($r->i ?? 0), (int) ($r->o ?? 0), (int) ($r->il ?? 0), (int) ($r->ol ?? 0));
    }

    /** Aggregates to select with GROUP BY model: n, i, o, and the long-prompt share il / ol. */
    public static function sumsSql(): string
    {
        $l = (int) self::LONG_PROMPT;

        return 'COUNT(*) n, COALESCE(SUM(input_tokens),0) i, COALESCE(SUM(output_tokens),0) o, '
            . "COALESCE(SUM(CASE WHEN input_tokens > $l THEN input_tokens ELSE 0 END),0) il, "
            . "COALESCE(SUM(CASE WHEN input_tokens > $l THEN output_tokens ELSE 0 END),0) ol";
    }

    /** Price list for the screen's footnote. */
    public static function table(): array
    {
        $out = [];
        foreach (self::MODELS as $m => $p) {
            $out[] = ['model' => $m, 'in' => $p['in'], 'out' => $p['out'], 'long' => $p['long'] ?? null];
        }

        return $out;
    }
}
