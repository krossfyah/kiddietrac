<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * AiLessonPlanService v21.
 *
 * Generates a 5-day lesson plan for one room for one week, using:
 *   - Age group
 *   - Theme (e.g. "Spring growth", "Community helpers")
 *   - Optional starter notes from the educator
 *
 * Output is structured JSON with:
 *   - 5 days, each with 2-4 activities aligned to HDLH foundations
 *   - Required materials list
 *   - Parent-facing family share blurb
 */
class AiLessonPlanService
{
    protected string $apiKey;
    protected string $model;

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
     * Generate a week plan.
     *
     * @param array $params { age_group, theme, week_starting, room_name?, starter_notes? }
     * @return array { success, plan?, error?, tokens_used?, model? }
     */
    public function generate(array $params): array
    {
        if (! $this->isConfigured()) {
            return ['success' => false, 'error' => 'AI service not configured'];
        }

        $age = trim((string)($params['age_group'] ?? ''));
        $theme = trim((string)($params['theme'] ?? ''));
        $week = trim((string)($params['week_starting'] ?? ''));
        if ($age === '' || $theme === '') {
            return ['success' => false, 'error' => 'age_group and theme are required'];
        }

        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type'      => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model'      => $this->model,
                'max_tokens' => 2000,
                'system'     => $this->systemPrompt(),
                'messages'   => [['role' => 'user', 'content' => $this->buildPrompt($params)]],
            ]);

            if (! $response->successful()) {
                Log::error('AiLessonPlanService: Anthropic API error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                $json = $response->json();
                $detail = is_array($json) && isset($json['error']['message']) ? $json['error']['message'] : ('API ' . $response->status());
                return ['success' => false, 'error' => $detail];
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';
            $tokens = ($body['usage']['input_tokens'] ?? 0) + ($body['usage']['output_tokens'] ?? 0);

            $plan = $this->parsePlan($text);
            if ($plan === null) {
                return ['success' => false, 'error' => 'Could not parse AI output (non-JSON)'];
            }

            return [
                'success'     => true,
                'plan'        => $plan,
                'tokens_used' => $tokens,
                'model'       => $this->model,
            ];
        } catch (\Throwable $e) {
            Log::error('AiLessonPlanService: exception', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function parsePlan(string $text): ?array
    {
        $text = trim($text);
        $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
        $text = preg_replace('/\s*```$/', '', $text);
        $p = json_decode($text, true);
        if (! is_array($p)) return null;
        if (! isset($p['days']) || ! is_array($p['days'])) return null;
        return $p;
    }

    protected function systemPrompt(): string
    {
        return <<<PROMPT
You are a lead educator at a licensed Ontario childcare centre. You design
weekly lesson plans aligned with the province's "How Does Learning Happen"
(HDLH) pedagogical framework. HDLH's four foundations are:
  - Belonging: a sense of connection to others
  - Well-Being: physical and emotional health
  - Engagement: active, focused participation
  - Expression: communicating thoughts, feelings, ideas

Your output MUST be valid JSON only - no markdown, no preamble, no code fences.

Schema:
{
  "theme": "<echo the requested theme>",
  "age_group": "<echo>",
  "days": [
    {
      "day": "Monday",
      "headline": "<short 4-8 word focus for the day>",
      "activities": [
        {
          "title": "<activity name>",
          "description": "<2-3 sentence description for educators>",
          "hdlh_foundation": "belonging|wellbeing|engagement|expression",
          "duration_min": 15,
          "materials": ["item 1", "item 2"]
        }
      ],
      "family_blurb": "<one sentence parents will see in the daily update>"
    }
  ],
  "materials_summary": ["consolidated material 1", "consolidated material 2"],
  "family_share": "<2-3 sentence blurb to share with families about the week>"
}

RULES:
- 5 days (Monday-Friday).
- Each day: 2-4 activities (don't pad).
- Activities should be developmentally appropriate for the requested age group:
    * infant (0-18m): sensory, attachment, gross motor
    * toddler (18m-3y): parallel play, language, fine motor exploration
    * preschool (3-5y): cooperative play, pre-literacy, problem-solving
    * kindergarten (4-6y): structured play, early academic skills
- Distribute HDLH foundations across the week (don't make all activities "engagement").
- Materials should be practical, low-cost, commonly available at a childcare centre.
- Write warmly but factually. No emoji. Canadian English.
- If the requested theme is inappropriate or unsafe, replace with a related safe theme
  and include the reason in family_share.
PROMPT;
    }

    protected function buildPrompt(array $params): string
    {
        $lines = [
            "Generate a weekly lesson plan with these parameters:",
            "",
            "Theme:         " . ($params['theme'] ?? ''),
            "Age group:     " . ($params['age_group'] ?? ''),
            "Week starting: " . ($params['week_starting'] ?? '(this week)'),
            "Room name:     " . ($params['room_name'] ?? '(any)'),
        ];
        if (!empty($params['starter_notes'])) {
            $lines[] = "Educator notes: " . $params['starter_notes'];
        }
        $lines[] = '';
        $lines[] = "Return the JSON plan now.";
        return implode("\n", $lines);
    }
}
