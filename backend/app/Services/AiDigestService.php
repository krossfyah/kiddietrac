<?php

namespace App\Services;

use App\Models\AiDailyDigest;
use App\Models\Child;
use App\Models\DailyEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiDigestService
 *
 * Generates a personalized end-of-day summary for each child by summarizing
 * the day's daily_events through Anthropic's Claude API.
 *
 * Key design decisions:
 *  - We assemble the digest from STRUCTURED EVENTS ONLY (no freeform AI invention).
 *  - The model is instructed to use only the supplied facts, never invent.
 *  - Output is warm but factual — written for an anxious parent.
 *  - We cache results per child per day (regenerate only if new events arrive).
 *  - We use claude-haiku for cost — ~$0.001 per digest at 500 tokens out.
 *
 * Run nightly via scheduled job:  php artisan kiddietrac:generate-digests
 */
class AiDigestService
{
    protected string $apiKey;
    protected string $model;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key') ?? '';
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }


    /**
     * Returns true if the Anthropic API key is configured.
     * Called by DailyEventController::digest to decide whether to attempt
     * live AI generation or fall back to a templated digest.
     *
     * Added in v19 — previously called from the controller but never defined,
     * silently 500-ing for past-date and after-4-PM digest requests.
     */
    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }
    /**
     * Generate (or regenerate) the digest for a child for a given date.
     */
    public function generate(Child $child, ?string $date = null): ?AiDailyDigest
    {
        $date ??= today()->toDateString();

        // Skip if no events that day
        $events = DailyEvent::where('child_id', $child->id)
            ->whereDate('occurred_at', $date)
            ->orderBy('occurred_at')
            ->get();

        if ($events->isEmpty()) {
            return null;
        }

        // Build factual summary from events — this is the source of truth
        $factSummary = $this->buildFactSummary($child, $events, $date);

        $prompt = $this->buildPrompt($child, $factSummary);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post('https://api.anthropic.com/v1/messages', [
                'model' => $this->model,
                'max_tokens' => 400,
                'system' => $this->systemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if (! $response->successful()) {
                Log::error('Anthropic API error', [
                    'child_id' => $child->id,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $body = $response->json();
            $digestText = $body['content'][0]['text'] ?? '';
            $tokensUsed = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);

            // Save (one per child per day — upsert)
            return AiDailyDigest::updateOrCreate(
                ['child_id' => $child->id, 'digest_date' => $date],
                [
                    'body' => trim($digestText),
                    'source_event_ids' => $events->pluck('id')->toArray(),
                    'model_used' => $this->model,
                    'tokens_used' => $tokensUsed,
                    'generated_at' => now(),
                    'language' => $child->preferred_lang ?? 'en-CA',
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Digest generation failed', [
                'child_id' => $child->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Builds a structured fact summary for the LLM prompt.
     * This is intentionally verbose & factual — the LLM transforms it into prose.
     */
    protected function buildFactSummary(Child $child, $events, string $date): array
    {
        $summary = [
            'child_name' => $child->display_name,
            'age' => $child->age['human'],
            'date' => $date,
            'meals' => [],
            'naps' => [],
            'activities' => [],
            'diaper_or_bathroom' => 0,
            'moods' => [],
            'incidents' => [],
            'observations' => [],
        ];

        $napStart = null;
        foreach ($events as $e) {
            $p = $e->payload ?? [];
            switch ($e->event_type) {
                case 'meal':
                case 'snack':
                    $summary['meals'][] = [
                        'time' => $e->occurred_at->format('g:i A'),
                        'meal' => $p['meal'] ?? $e->event_type,
                        'items' => $p['items'] ?? [],
                        'amount' => $p['amount'] ?? 'unknown',
                    ];
                    break;
                case 'nap_start':
                    $napStart = $e->occurred_at;
                    break;
                case 'nap_end':
                    if ($napStart) {
                        $summary['naps'][] = [
                            'start' => $napStart->format('g:i A'),
                            'end' => $e->occurred_at->format('g:i A'),
                            'duration_min' => $napStart->diffInMinutes($e->occurred_at),
                        ];
                        $napStart = null;
                    }
                    break;
                case 'activity':
                    $summary['activities'][] = [
                        'name' => $p['name'] ?? 'activity',
                        'domain' => $p['domain'] ?? null,
                        'duration_min' => $p['duration_min'] ?? null,
                        'note' => $e->notes,
                    ];
                    break;
                case 'mood':
                    $summary['moods'][] = $p['score'] ?? 'recorded';
                    break;
                case 'diaper':
                case 'bathroom':
                    $summary['diaper_or_bathroom']++;
                    break;
                case 'incident':
                    $summary['incidents'][] = $e->notes ?? 'incident logged';
                    break;
            }
        }

        // Include any observations made today
        $observations = $child->observations()
            ->whereDate('observed_at', $date)
            ->where('shared_with_family', true)
            ->get(['domain', 'body']);
        foreach ($observations as $o) {
            $summary['observations'][] = [
                'domain' => $o->domain,
                'body' => $o->body,
            ];
        }

        return $summary;
    }

    protected function systemPrompt(): string
    {
        return <<<PROMPT
You are writing a warm, calm end-of-day update for a parent about their child's
day at a licensed Ontario childcare centre. You are part of the Kiddietrac
platform.

Your rules — these are non-negotiable:
1. Use ONLY the facts in the JSON I provide. Never invent details, foods,
   conversations, or developmental observations not present.
2. Write in 2–4 sentences. Maximum 80 words.
3. Warm but factual tone. Not saccharine. Parents are anxious — they want clear
   reassurance and specifics, not poetry.
4. Lead with the child's overall vibe (calm, social, energetic, tired), then
   give 1–2 concrete moments (meal, activity, milestone). End on something
   parent-positive (no incidents → say so; a small win → highlight it).
5. NEVER use exclamation points more than once. NEVER use emoji.
6. Refer to the child by their preferred name.
7. If there were incidents, mention them briefly and reassuringly — never
   minimize or hide them.
8. If meal amounts were small ("none" or "little"), acknowledge it neutrally.
   Do not editorialize about parenting.
9. If the data is sparse (1-2 events), keep the digest correspondingly short.
10. Write in the child's family's preferred language if specified. Default to
    natural Canadian English.
PROMPT;
    }

    protected function buildPrompt(Child $child, array $summary): string
    {
        return "Generate a daily digest for this child using only these facts:\n\n"
            . json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Bulk generation: invoked by the scheduled job at end of day.
     */
    public function generateForCentre(int $centreId, ?string $date = null): array
    {
        $date ??= today()->toDateString();
        $stats = ['generated' => 0, 'skipped' => 0, 'failed' => 0];

        $children = Child::enrolled()
            ->whereHas('currentEnrollment.room', fn($q) => $q->where('centre_id', $centreId))
            ->get();

        foreach ($children as $child) {
            try {
                $digest = $this->generate($child, $date);
                if ($digest) $stats['generated']++;
                else $stats['skipped']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                Log::error('Bulk digest error', ['child' => $child->id, 'err' => $e->getMessage()]);
            }

            // Rate limit: max ~20/sec to be polite to Anthropic
            usleep(50_000);
        }

        return $stats;
    }
}
