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

    public function isConfigured(): bool
    {
        return !empty($this->apiKey ?? config('services.anthropic.api_key'));
    }

    /**
     * Generate a warm, parent-friendly summary of a child's day.
     */
    public function generateDailyDigest(array $context): string
    {
        $apiKey = $this->apiKey ?? config('services.anthropic.api_key');
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
                'model' => $this->model,
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
}
