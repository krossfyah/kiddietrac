<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * A few AI-written lines on top of the staff emails (2026-10-28, the AI Bundle: "AI staff & director summaries ...
 * do not impact how things are done today but add this as an enhancement").
 *
 * Strictly additive: write() returns the paragraph or null, and every caller sends its email exactly as before when it
 * is null - no bundle, no key, an error, an empty answer. After one failure the AI is left alone for 30 minutes
 * (ai-summaries:resting), so a nightly run over every educator makes one failing call, not one per person (the
 * parent summary run logged ~30 identical "credit balance is too low" errors a night).
 */
final class AiSummaries
{
    private const PROMPTS = [
        'educator' => 'Write 2-3 warm, specific sentences for an early-childhood educator about the day they just had, '
            . 'from the facts below. Mention one or two real moments or numbers, each exactly as it is labelled. No greeting, no sign-off, no lists, '
            . 'no exclamation-mark overload. Never invent anything that is not in the facts.',
        'director' => 'Write 2-3 plain sentences for a childcare director summarising what needs their attention, '
            . 'from the items below, most urgent first. Name the kind of item and how many; be calm and practical. '
            . 'No greeting, no sign-off, no lists. Never invent anything that is not in the items.',
        // Parent daily summary, under the 5-day forecast (2026-11-02, WeatherForecast::dressNote). Weather only - no
        // child or family data goes in, so one note serves every family at the centre that day.
        'dress' => 'Write 2-3 short, practical sentences telling a parent how to dress their young child for childcare '
            . 'over the next five days, from the forecast below. Name the specific days that need something extra '
            . '(rain gear, warm layers, snow gear, sun hat and sunscreen, a spare change of clothes) and what to pack. '
            . 'Use the units given. Calm and friendly. No greeting, no sign-off, no lists, no emoji. At most 55 words. '
            . 'Call a day wet only when its chance of rain or snow is 50% or more. Focus on weekdays (the child is at '
            . 'childcare); mention Saturday or Sunday only if the weather turns sharply. If nothing needs anything extra, '
            . 'say so in one sentence. Never invent weather that is not in the forecast.',
    ];

    public static function write(int $agencyId, string $audience, array $facts): ?string
    {
        if (! isset(self::PROMPTS[$audience]) || ! AiBundle::on($agencyId) || Cache::has('ai-summaries:resting')) {
            return null;
        }
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) {
            return null;
        }
        $json = json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if (! $json || strlen($json) < 10) {
            return null;
        }
        UsageMeter::forAgency($agencyId);
        try {
            \App\Support\AiFresh::as('summary.' . $audience, $agencyId);   // fresh writing (AiFresh)
            $r = Http::timeout(30)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => 300,
                    'system' => 'You write short notes inside emails from a licensed childcare platform. Reply with only the note.',
                    'messages' => [['role' => 'user', 'content' => self::PROMPTS[$audience] . "\n\nFacts (JSON):\n" . mb_substr($json, 0, 12000)]],
                ]);
            if (! $r->successful()) {
                Log::warning('AI summary note failed', ['agency' => $agencyId, 'audience' => $audience, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 200)]);
                Cache::put('ai-summaries:resting', 1, now()->addMinutes(30));

                return null;
            }
            $text = trim((string) (\App\Support\AiConfig::text($r->json()) ?? ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('AI summary note: no answer', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            Cache::put('ai-summaries:resting', 1, now()->addMinutes(30));

            return null;
        }
    }

    /** The box the note sits in - the same lilac as the "✨ AI" help in the portal. */
    public static function box(string $label, string $text): string
    {
        return '<div class="kt-panel" style="background:#F5F3FF;border:1px solid #DDD6FE;border-radius:12px;padding:12px 14px;margin:12px 0;">'
            . '<div style="font-size:11px;font-weight:800;color:#6D28D9;letter-spacing:.6px;margin-bottom:5px;">✨ ' . e($label) . '</div>'
            . '<div style="font-size:14px;color:#1E293B;line-height:1.55;">' . nl2br(e($text)) . '</div></div>';
    }
}
