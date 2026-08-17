<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin wrapper around Anthropic's Messages API for generating
 * parent-facing daily digests.
 *
 * Configuration: set ANTHROPIC_API_KEY in .env
 */
final class AnthropicService
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const API_VERSION = '2023-06-01';
    private const DEFAULT_MODEL = 'claude-opus-4-7';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly string $model = self::DEFAULT_MODEL,
        private readonly int $maxTokens = 600,
        private readonly int $timeoutSeconds = 30,
    ) {}

    /**
     * The key lives at services.anthropic.KEY (see config/services.php).
     * This class was reading services.anthropic.API_KEY, which does not exist —
     * so isConfigured() was always false and every feature routed through this
     * service (Help answers, event summaries) silently did nothing. Read the real
     * key, keeping the old path as a fallback in case someone sets it.
     */
    private function key(): ?string
    {
        return $this->apiKey
            ?: config('services.anthropic.key')
            ?: config('services.anthropic.api_key');
    }

    /** The model configured for this deployment, not a hard-coded default. */
    private function modelName(): string
    {
        return config('services.anthropic.model') ?: $this->model;
    }

    public function isConfigured(): bool
    {
        return !empty($this->key());
    }

    /**
     * Generate a warm, parent-friendly summary of a child's day.
     */
    public function generateDailyDigest(array $context): string
    {
        $apiKey = $this->key();
        if (!$apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY not configured.');
        }

        $prompt = $this->buildDigestPrompt($context);

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
            ->post(self::API_URL, [
                'model' => $this->modelName(),
                'max_tokens' => $this->maxTokens,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Anthropic API request failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('AI digest generation failed: '.$response->status());
        }

        $body = $response->json();
        $text = $body['content'][0]['text'] ?? '';

        if (empty(trim($text))) {
            throw new RuntimeException('AI digest returned empty response.');
        }

        return trim($text);
    }

    private function buildDigestPrompt(array $context): string
    {
        $childName = $context['child_name'] ?? 'this child';
        $ageHuman = $context['age'] ?? '';
        $roomName = $context['room'] ?? 'the room';
        $events = $context['events'] ?? [];
        $checks = $context['checks'] ?? [];

        // Format events as readable bullets
        $eventLines = [];
        foreach ($events as $e) {
            $time = $e['time'] ?? '';
            $type = $e['type'] ?? '';
            $detail = $e['detail'] ?? '';
            $eventLines[] = "- {$time} — {$type}: {$detail}";
        }

        foreach ($checks as $c) {
            $time = $c['time'] ?? '';
            $type = $c['type'] === 'check_in' ? 'Arrived' : 'Picked up';
            $eventLines[] = "- {$time} — {$type}";
        }

        $eventBlock = empty($eventLines)
            ? '(no events logged today)'
            : implode("\n", $eventLines);

        return <<<PROMPT
You are writing a warm, friendly daily digest for a parent about their child's day at a childcare centre. Your audience is a busy parent who wants to feel connected to their child's day in 30 seconds of reading.

CHILD: {$childName}
AGE: {$ageHuman}
ROOM: {$roomName}

EVENTS LOGGED TODAY:
{$eventBlock}

WRITE THE DIGEST FOLLOWING THESE RULES:

1. Length: 2-3 short paragraphs, around 100-150 words total
2. Tone: Warm but never saccharine. No "what a wonderful day!" platitudes. Specific over generic.
3. Structure: Lead with the most parent-relevant info (mood, meals, naps), then notable activities, then anything they should know
4. Use the child's first name (just "{$childName}", no "their" or "your child")
5. Never invent events that aren't in the log. If meals weren't recorded, don't mention meals.
6. If the log is sparse, be honest: "We didn't capture as much today, but here's what we did note..."
7. Don't editorialize about whether the day was good/bad — just report what happened
8. End with one sentence that gives the parent a starting point for conversation with their child

OUTPUT ONLY THE DIGEST TEXT. No headers, no preamble, no "Dear parent". Just the digest.
PROMPT;
    }

    /**
     * The warm, written-to-the-parent message at the top of the end-of-day email.
     *
     * Different job from generateDailyDigest(): that one narrates the child's day
     * from the events. This one is addressed TO the parent and has the whole day
     * in front of it — logs, photos and their captions, messages with the centre,
     * and the sign-in/out times — so it can pull the day together and say
     * something worth reading, rather than listing back what is already listed
     * below it in the email.
     */
    public function generateParentSummary(array $context): string
    {
        $apiKey = $this->key();
        if (!$apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY not configured.');
        }

        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
            ->post(self::API_URL, [
                'model' => $this->modelName(),
                'max_tokens' => 700,
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildParentSummaryPrompt($context)],
                ],
            ]);

        if (!$response->successful()) {
            Log::error('Anthropic parent summary failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);
            throw new RuntimeException('AI parent summary failed: ' . $response->status());
        }

        $text = $response->json()['content'][0]['text'] ?? '';
        if (empty(trim($text))) {
            throw new RuntimeException('AI parent summary returned empty response.');
        }

        return trim($text);
    }

    private function buildParentSummaryPrompt(array $c): string
    {
        $name = $c['child_name'] ?? 'the child';
        $facts = json_encode($c['facts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are an experienced, warm early-childhood educator writing the end-of-day note home
to {$name}'s parents at a childcare centre.

Here is everything that was recorded for {$name} today, as JSON:

{$facts}

Write the note that goes at the top of their daily summary email.

Rules:
- Address the parents directly and warmly ("{$name} had a lovely day…"). Never say "Dear parent".
- 2 to 3 short paragraphs, about 90-150 words in total. Plain sentences, no bullet points.
- Be SPECIFIC: use the real details above — what they ate, how they slept, what the photo
  captions show they were doing, their mood. Details are what a parent actually wants.
- Draw the day together and say something human about it. Do not simply list the logs back:
  the parent can already see the full list of logs, photos and times further down the email.
- If they did not sleep well, ate little, or seemed unsettled, say so kindly and factually —
  parents need the honest picture, not a sales pitch. Never invent anything that is not in
  the data. If there is very little data, keep it short rather than padding it out.
- No medical advice, no diagnosis, no judgement of the parents.
- End with one friendly closing line from the team at the centre.

OUTPUT ONLY THE NOTE. No subject line, no headings, no sign-off block, no quotation marks.
PROMPT;
    }

    /** A warm WEEKLY wrap-up for parents, written from a week of aggregated facts. */
    public function generateWeeklySummary(array $context): string
    {
        $apiKey = $this->key();
        if (!$apiKey) {
            throw new RuntimeException('ANTHROPIC_API_KEY not configured.');
        }
        $response = Http::timeout($this->timeoutSeconds)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => self::API_VERSION,
                'content-type' => 'application/json',
            ])
            ->post(self::API_URL, [
                'model' => $this->modelName(),
                'max_tokens' => 800,
                'messages' => [
                    ['role' => 'user', 'content' => $this->buildWeeklyPrompt($context)],
                ],
            ]);
        if (!$response->successful()) {
            Log::error('Anthropic weekly summary failed', ['status' => $response->status(), 'body' => $response->body()]);
            throw new RuntimeException('AI weekly summary failed: ' . $response->status());
        }
        $text = $response->json()['content'][0]['text'] ?? '';
        if (empty(trim($text))) {
            throw new RuntimeException('AI weekly summary returned empty response.');
        }
        return trim($text);
    }

    private function buildWeeklyPrompt(array $c): string
    {
        $name = $c['child_name'] ?? 'the child';
        $weekLabel = $c['week_label'] ?? 'this week';
        $wk = (int) ($c['week_of_year'] ?? 0);
        $facts = json_encode($c['facts'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return <<<PROMPT
You are a warm, experienced early-childhood educator writing a WEEKLY wrap-up note home to {$name}'s parents at a childcare centre, for {$weekLabel}.

Here is everything recorded for {$name} this week, aggregated per day, as JSON. Each day may include a "menu" object with the meals actually served that day:

{$facts}

Write the note that sits at the top of their weekly summary email.

Rules:
- Address the parents directly and warmly. Never "Dear parent".
- 3 to 4 short paragraphs, about 160-220 words. Plain sentences, no bullet points, no headings.
- Talk about the WEEK'S PATTERNS, not a single day: how eating went across the week, how naps/sleep looked, activity highlights, and overall mood/settling. Use the real numbers (e.g. "napped well most afternoons", "ate heartily on all but Wednesday").
- RECAP THE FOOD: mention a couple of specific meals from the "menu" data the children were served this week (e.g. "loved Tuesday's spaghetti"), when menu data is present.
- REASSURE the parents: make them feel their child was safe, cared for, seen as an individual, and supported to learn and play. Warmth and trust are the point of this email.
- VARY YOUR LANGUAGE. This note goes out every week, so it must not read from a template. Do NOT reuse the same opening or closing sentence week to week. Vary sentence structure and vocabulary. Use variation seed {$wk} to pick a genuinely different opening line and a different closing line each week. Avoid clichés like "what a wonderful week".
- Be specific and honest. If some days had little logged, or eating/sleep dipped, say so kindly and factually. Never invent anything not in the data. If the week is sparse, keep it shorter.
- No medical advice, no diagnosis, no judgement of the parents.
- End with one warm, non-repetitive closing line from the team.

OUTPUT ONLY THE NOTE. No subject line, no headings, no sign-off block, no quotation marks.
PROMPT;
    }
}
