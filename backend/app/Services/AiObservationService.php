<?php

namespace App\Services;

use App\Models\Child;
use App\Models\Observation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiObservationService v21
 *
 * Educator types a freeform paragraph about what a child did. This service:
 *   1. Calls Claude with strict-fact, HDLH-aware prompt
 *   2. Returns structured JSON: { domain, hdlh_milestones[], parent_summary }
 *   3. Caller saves to observations table
 *
 * Uses Haiku 4.5 by default (cheap and accurate for this structured task).
 *
 * HDLH = "How Does Learning Happen" — Ontario's pedagogical framework with
 * 4 foundations: Belonging, Well-Being, Engagement, Expression.
 */
class AiObservationService
{
    protected string $apiKey;
    protected string $model;
    protected array $framework = [];

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key') ?? '';
        $this->model  = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    public function isConfigured(): bool
    {
        return !empty($this->apiKey);
    }

    /**
     * Structure an educator's raw observation.
     *
     * @param Child $child
     * @param string $rawText
     * @return array{success:bool, structured?:array, error?:string, tokens_used?:int, model?:string}
     */
    public function structure(Child $child, string $rawText, ?array $framework = null): array
    {
        // The agency's learning framework (2026-09-29); HDLH when the caller has none.
        $this->framework = $framework ?: \App\Support\LearningFrameworks::forAgency(null);
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'AI service not configured (missing API key)'];
        }

        $rawText = trim($rawText);
        if (mb_strlen($rawText) < 10) {
            return ['success' => false, 'error' => 'Observation text too short (need at least 10 characters)'];
        }
        if (mb_strlen($rawText) > 3000) {
            return ['success' => false, 'error' => 'Observation text too long (max 3000 characters)'];
        }

        $childName = method_exists($child, 'getDisplayNameAttribute')
            ? $child->display_name
            : trim(($child->first_name ?? '') . ' ' . ($child->last_name ?? ''));

        $ageHuman = 'unknown';
        try {
            $age = $child->age;
            if (is_array($age)) {
                $years  = (int) round((float)($age['years']  ?? 0));
                $months = (int) round((float)($age['months'] ?? 0));
                $ageHuman = ($years > 0 ? $years . 'y ' : '') . $months . 'm';
            }
        } catch (\Throwable $e) {
            // Stay with 'unknown'
        }

        $prompt = $this->buildPrompt($childName, $ageHuman, $rawText);

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(45)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 800,
                'system'     => $this->systemPrompt(),
                'messages'   => [['role' => 'user', 'content' => $prompt]],
            ]);

            if (! $response->successful()) {
                $body = $response->body();
                Log::error('AiObservationService: Anthropic API error', [
                    'child_id' => $child->id,
                    'status'   => $response->status(),
                    'body'     => $body,
                ]);
                $detail = '';
                $json = $response->json();
                if (is_array($json) && isset($json['error']['message'])) {
                    $detail = $json['error']['message'];
                }
                return ['success' => false, 'error' => $detail ?: ('API ' . $response->status())];
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';
            $tokensUsed = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);

            // Parse JSON from the model. The model should return only JSON.
            $structured = $this->parseStructured($text);
            if ($structured !== null) { $structured = $this->normalise($structured); }
            if ($structured === null) {
                Log::error('AiObservationService: could not parse model output', [
                    'child_id' => $child->id,
                    'raw'      => mb_substr($text, 0, 500),
                ]);
                return ['success' => false, 'error' => 'Could not parse AI output (model returned non-JSON)'];
            }

            return [
                'success'     => true,
                'structured'  => $structured,
                'tokens_used' => $tokensUsed,
                'model'       => $this->model,
            ];
        } catch (\Throwable $e) {
            Log::error('AiObservationService: exception', [
                'child_id' => $child->id,
                'error'    => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Try to extract JSON from the model's reply.
     * The system prompt asks for raw JSON only, but be defensive — strip code fences if present.
     */
    private function parseStructured(string $text): ?array
    {
        $text = trim($text);

        // Strip markdown code fences if present
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);

        $parsed = json_decode($text, true);
        if (! is_array($parsed)) return null;

        // Validate shape
        $required = ['domain', 'hdlh_milestones', 'parent_summary'];
        foreach ($required as $k) {
            if (! array_key_exists($k, $parsed)) return null;
        }
        if (! is_array($parsed['hdlh_milestones'])) {
            $parsed['hdlh_milestones'] = [];
        }
        return $parsed;
    }

    /* Keep the model inside the platform's vocabulary: a domain the rest of KiddieTrac
       recognises, and framework areas that belong to the agency's framework. */
    private function normalise(array $s): array
    {
        $alias = ['language' => 'language_literacy', 'creative_expression' => 'creative_arts', 'creative' => 'creative_arts',
                  'social' => 'social_emotional', 'emotional' => 'social_emotional', 'motor' => 'physical'];
        $d = strtolower(trim((string) ($s['domain'] ?? '')));
        $d = $alias[$d] ?? $d;
        $s['domain'] = in_array($d, \App\Support\LearningFrameworks::DOMAINS, true) ? $d : 'social_emotional';
        $keys = array_column($this->framework['areas'] ?? [], 'key');
        $s['hdlh_milestones'] = array_values(array_filter(array_map(function ($m) use ($keys) {
            if (! is_array($m)) return null;
            $f = strtolower(trim((string) ($m['foundation'] ?? $m['area'] ?? '')));
            $f = str_replace(['-', ' '], '_', $f) === 'well_being' ? 'wellbeing' : str_replace(['-', ' '], '_', $f);
            if (! in_array($f, $keys, true)) return null;
            return ['foundation' => $f, 'milestone' => mb_substr((string) ($m['milestone'] ?? ''), 0, 160), 'evidence' => mb_substr((string) ($m['evidence'] ?? ''), 0, 300)];
        }, (array) ($s['hdlh_milestones'] ?? []))));
        return $s;
    }

    protected function systemPrompt(): string
    {
        $fw = $this->framework;
        $areaKeys = implode('|', array_column($fw['areas'], 'key'));
        $areaLines = implode("\n", array_map(fn ($a) => '    * ' . $a['key'] . ' - ' . $a['label'] . ': ' . $a['hint'], $fw['areas']));
        $domains = implode('|', \App\Support\LearningFrameworks::DOMAINS);
        $name = $fw['name'] . ' (' . $fw['short'] . ', ' . $fw['region'] . ')';

        return <<<PROMPT
You are part of Kiddietrac, a childcare platform. Your job is to take an
educator's freeform observation note about a child and structure it into a JSON
object that:
  1. Links the observation to the areas of the agency's learning framework: {$name}
  2. Identifies developmental milestones demonstrated
  3. Produces a warm, factual one-paragraph summary suitable for the child's parent

Your output MUST be valid JSON only — no markdown, no preamble, no code fences.

JSON schema:
{
  "domain": "{$domains}",
  "hdlh_milestones": [
    {
      "foundation": "{$areaKeys}",
      "milestone": "<short 5-12 word skill or behaviour demonstrated>",
      "evidence": "<short factual quote-like sentence from the educator note>"
    }
  ],
  "parent_summary": "<one paragraph, 2-4 sentences, 50-100 words, warm but factual, suitable for a parent>"
}

RULES (non-negotiable):
- Use ONLY facts present in the educator's note. Never invent details.
- "domain" must match what's predominantly observed, from the list above exactly.
- "hdlh_milestones" should have 1-3 items (don't pad). "foundation" must be one of these
  framework area keys exactly:
{$areaLines}
- "parent_summary" is for the family — friendly but specific. Use the child's name.
  No emoji. Maximum one exclamation mark. Refer to facts, not adjectives like "amazing".
- If the observation is too vague to extract milestones, return hdlh_milestones=[].
- Write the parent_summary in the same language as the educator's note (Canadian English by default).
PROMPT;
    }

    protected function buildPrompt(string $childName, string $age, string $raw): string
    {
        return "Child: {$childName} (age {$age})\n\n"
             . "Educator's observation:\n\"\"\"\n{$raw}\n\"\"\"\n\n"
             . "Return the JSON object now.";
    }
}
