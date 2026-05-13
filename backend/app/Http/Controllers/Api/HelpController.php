<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AnthropicService;
use App\Services\HelpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class HelpController extends Controller
{
    public function __construct(
        private readonly HelpService $help = new HelpService(),
        private readonly AnthropicService $ai = new AnthropicService(),
    ) {}

    /**
     * GET /api/v1/help
     * Returns the article list (categorized) for the user's role.
     */
    public function index(Request $request): JsonResponse
    {
        $role = $this->resolveRole($request->user());

        return response()->json([
            'role' => $role,
            'categorized' => $this->help->categorizedForRole($role),
        ]);
    }

    /**
     * GET /api/v1/help/{slug}
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $role = $this->resolveRole($request->user());
        $article = $this->help->getArticle($slug, $role);

        if (!$article) {
            return response()->json(['message' => 'Article not found'], 404);
        }

        return response()->json(['article' => $article]);
    }

    /**
     * POST /api/v1/help/ask
     * Ask Claude a natural-language question, grounded in the help corpus.
     */
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $role = $this->resolveRole($request->user());

        if (!$this->ai->isConfigured()) {
            return response()->json([
                'answer' => "AI help isn't currently available. Please browse the help articles or email support@kiddietrac.com.",
                'sources' => [],
                'configured' => false,
            ]);
        }

        try {
            $answer = $this->askAI($data['question'], $role);
            return response()->json([
                'answer' => $answer['answer'],
                'sources' => $answer['sources'],
                'configured' => true,
            ]);
        } catch (Throwable $e) {
            Log::error('Help AI failed', ['error' => $e->getMessage(), 'q' => $data['question']]);
            return response()->json([
                'answer' => "I couldn't get an answer right now. Try browsing the help articles, or contact your centre director.",
                'sources' => [],
                'error' => true,
            ]);
        }
    }

    /**
     * Calls Anthropic with the help corpus + user question. Returns answer + cited sources.
     */
    private function askAI(string $question, string $role): array
    {
        $corpus = $this->help->getCorpusForRole($role);
        $apiKey = config('services.anthropic.api_key');

        if (!$apiKey) {
            throw new RuntimeException('Anthropic API key not configured');
        }

        $roleLabel = match ($role) {
            'centre_director', 'agency_admin' => 'a childcare centre director',
            'educator' => 'an early childhood educator',
            'guardian' => 'a parent',
            default => 'a Kiddietrac user',
        };

        $systemPrompt = <<<PROMPT
You are the Kiddietrac help assistant. The user asking is {$roleLabel}.

Below is the FULL help documentation for Kiddietrac. ONLY answer questions using information from these articles. If the answer isn't in the docs, say so honestly and suggest contacting support.

When you reference a help article, mention its title in the answer (e.g. "see 'How to add a new child'"). At the END of your answer, list the slugs of any articles you cited, in this exact format on its own line:

SOURCES: slug-one, slug-two

If you didn't reference any specific article, output: SOURCES: none

Keep answers concise (2-4 short paragraphs). Friendly but professional. Don't invent features that aren't in the docs.

═══════════════════════════════════════════
HELP DOCUMENTATION
═══════════════════════════════════════════

{$corpus}
PROMPT;

        $response = Http::timeout(30)
            ->withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model', 'claude-opus-4-7'),
                'max_tokens' => 800,
                'system' => $systemPrompt,
                'messages' => [
                    ['role' => 'user', 'content' => $question],
                ],
            ]);

        if (!$response->successful()) {
            throw new RuntimeException('Anthropic API error: '.$response->status());
        }

        $body = $response->json();
        $text = trim($body['content'][0]['text'] ?? '');

        // Extract SOURCES line
        $sources = [];
        if (preg_match('/SOURCES:\s*(.+)$/im', $text, $m)) {
            $rawSources = trim($m[1]);
            if (strtolower($rawSources) !== 'none') {
                $sources = array_filter(array_map('trim', explode(',', $rawSources)));
            }
            $text = trim(preg_replace('/SOURCES:.*$/im', '', $text));
        }

        // Resolve slugs to titles
        $resolvedSources = [];
        foreach ($sources as $slug) {
            $article = $this->help->getArticle($slug, $role);
            if ($article) {
                $resolvedSources[] = [
                    'slug' => $article['slug'],
                    'title' => $article['title'],
                ];
            }
        }

        return [
            'answer' => $text,
            'sources' => $resolvedSources,
        ];
    }

    private function resolveRole($user): string
    {
        if (!$user) return 'parent';

        $roles = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->pluck('role')
            ->unique()
            ->all();

        return match (true) {
            in_array('agency_admin', $roles, true) => 'agency_admin',
            in_array('centre_director', $roles, true) => 'centre_director',
            in_array('educator', $roles, true) => 'educator',
            in_array('guardian', $roles, true) => 'guardian',
            default => 'guardian',
        };
    }
}
