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
        $role = $this->resolveRole($request->user(), $request);

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
        $role = $this->resolveRole($request->user(), $request);
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

        $role = $this->resolveRole($request->user(), $request);

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

    private function resolveRole($user, ?Request $request = null): string
    {
        if (!$user) return 'parent';

        $roles = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->pluck('role')
            ->unique()
            ->all();

        // Platform admins previewing a role via "View as" send X-View-As-Role, so
        // Help shows THAT role's articles instead of the admin's own.
        if ($request && in_array('platform_admin', $roles, true)) {
            $map = [
                'guardian' => 'guardian', 'parent' => 'guardian',
                'educator' => 'educator',
                'centre_director' => 'centre_director', 'director' => 'centre_director',
                'agency_admin' => 'agency_admin',
                'auditor' => 'auditor',
            ];
            $va = strtolower(trim((string) $request->header('X-View-As-Role')));
            if (isset($map[$va])) {
                return $map[$va];
            }
        }

        return match (true) {
            in_array('agency_admin', $roles, true) => 'agency_admin',
            in_array('centre_director', $roles, true) => 'centre_director',
            in_array('educator', $roles, true) => 'educator',
            in_array('guardian', $roles, true) => 'guardian',
            in_array('auditor', $roles, true) => 'auditor',
            default => 'guardian',
        };
    }

    /**
     * GET /api/v1/help/dashboard
     * Featured + popular for the help-home panels.
     */
    public function dashboard(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $role = $this->resolveRole($request->user(), $request);
        $agencyId = optional($request->user())->agency_id ?? null;

        // Featured: from help_article_featured (agency-specific or global)
        $featuredSlugs = \Illuminate\Support\Facades\DB::table('help_article_featured')
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('agency_id')->orWhere('agency_id', $agencyId);
            })
            ->orderBy('sort')
            ->limit(6)
            ->pluck('slug')
            ->toArray();

        $featured = collect($featuredSlugs)->map(function ($slug) use ($role) {
            $a = $this->help->getArticle($slug, $role);
            return $a ? ['slug' => $slug, 'title' => $a['title'], 'category' => $a['category']] : null;
        })->filter()->values();

        // Popular: most viewed in last 30 days, scoped by agency if available
        $popularRows = \Illuminate\Support\Facades\DB::table('help_article_views')
            ->select('slug', \Illuminate\Support\Facades\DB::raw('COUNT(*) as views'))
            ->where('viewed_at', '>=', now()->subDays(30))
            ->when($agencyId, fn($q) => $q->where('agency_id', $agencyId))
            ->groupBy('slug')
            ->orderByDesc('views')
            ->limit(6)
            ->get();

        $popular = $popularRows->map(function ($row) use ($role) {
            $a = $this->help->getArticle($row->slug, $role);
            return $a ? ['slug' => $row->slug, 'title' => $a['title'], 'category' => $a['category'], 'views' => (int) $row->views] : null;
        })->filter()->values();

        return response()->json([
            'featured' => $featured,
            'popular' => $popular,
        ]);
    }

    /**
     * POST /api/v1/help/{slug}/view
     */
    public function trackView(\Illuminate\Http\Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        \Illuminate\Support\Facades\DB::table('help_article_views')->insert([
            'slug' => $slug,
            'user_id' => optional($user)->id,
            'role' => $this->resolveRole($user),
            'agency_id' => optional($user)->agency_id,
            'viewed_at' => now(),
            'ip' => $request->ip(),
        ]);
        return response()->json(['ok' => true]);
    }

    /**
     * POST /api/v1/help/{slug}/feedback  { helpful: bool, comment?: string }
     */
    public function feedback(\Illuminate\Http\Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'helpful' => ['required', 'boolean'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $user = $request->user();
        \Illuminate\Support\Facades\DB::table('help_article_feedback')->insert([
            'slug' => $slug,
            'user_id' => optional($user)->id,
            'role' => $this->resolveRole($user),
            'helpful' => $data['helpful'],
            'comment' => $data['comment'] ?? null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true]);
    }

    /**
     * GET /api/v1/help/analytics  (admin only)
     */
    public function analytics(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $role = $this->resolveRole($user);
        if (!in_array($role, ['agency_admin', 'platform_admin', 'centre_director'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $agencyId = $user->agency_id ?? null;
        $isPlatform = $role === 'platform_admin';

        // Top viewed (30d)
        $topViews = \Illuminate\Support\Facades\DB::table('help_article_views')
            ->select('slug', \Illuminate\Support\Facades\DB::raw('COUNT(*) as views'))
            ->where('viewed_at', '>=', now()->subDays(30))
            ->when(!$isPlatform && $agencyId, fn($q) => $q->where('agency_id', $agencyId))
            ->groupBy('slug')
            ->orderByDesc('views')
            ->limit(20)
            ->get();

        // Helpful vs not (per article)
        $feedback = \Illuminate\Support\Facades\DB::table('help_article_feedback')
            ->select('slug',
                \Illuminate\Support\Facades\DB::raw('SUM(CASE WHEN helpful = 1 THEN 1 ELSE 0 END) as yes'),
                \Illuminate\Support\Facades\DB::raw('SUM(CASE WHEN helpful = 0 THEN 1 ELSE 0 END) as no')
            )
            ->groupBy('slug')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->slug => ['yes' => (int) $row->yes, 'no' => (int) $row->no]]);

        // Comments (negative only — actionable)
        $negComments = \Illuminate\Support\Facades\DB::table('help_article_feedback')
            ->where('helpful', false)
            ->whereNotNull('comment')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get(['slug', 'comment', 'created_at']);

        // Totals
        $totalViews30 = \Illuminate\Support\Facades\DB::table('help_article_views')
            ->where('viewed_at', '>=', now()->subDays(30))
            ->when(!$isPlatform && $agencyId, fn($q) => $q->where('agency_id', $agencyId))
            ->count();

        $askedCount30 = 0;  // TODO: track AI asks separately

        return response()->json([
            'totals' => [
                'views_30d' => $totalViews30,
                'feedback_count' => $feedback->sum(fn($v) => $v['yes'] + $v['no']),
                'helpful_pct' => $feedback->sum(fn($v) => $v['yes']) + $feedback->sum(fn($v) => $v['no']) > 0
                    ? round(100 * $feedback->sum(fn($v) => $v['yes']) / ($feedback->sum(fn($v) => $v['yes']) + $feedback->sum(fn($v) => $v['no'])))
                    : null,
            ],
            'top_views' => $topViews->map(fn ($row) => [
                'slug' => $row->slug,
                'views' => (int) $row->views,
                'feedback' => $feedback[$row->slug] ?? ['yes' => 0, 'no' => 0],
            ]),
            'negative_comments' => $negComments,
        ]);
    }

    /**
     * POST /api/v1/help/featured  (admin) { slug, sort? }
     * DELETE /api/v1/help/featured/{slug}  (admin)
     */
    public function pinFeatured(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $role = $this->resolveRole($user);
        if (!in_array($role, ['agency_admin', 'platform_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'slug' => ['required', 'string'],
            'sort' => ['nullable', 'integer'],
        ]);
        \Illuminate\Support\Facades\DB::table('help_article_featured')->updateOrInsert(
            ['agency_id' => $role === 'platform_admin' ? null : $user->agency_id, 'slug' => $data['slug']],
            ['sort' => $data['sort'] ?? 0, 'updated_at' => now(), 'created_at' => now()],
        );
        return response()->json(['ok' => true]);
    }

    public function unpinFeatured(\Illuminate\Http\Request $request, string $slug): \Illuminate\Http\JsonResponse
    {
        $user = $request->user();
        $role = $this->resolveRole($user);
        if (!in_array($role, ['agency_admin', 'platform_admin'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        \Illuminate\Support\Facades\DB::table('help_article_featured')
            ->where('slug', $slug)
            ->where(function ($q) use ($user, $role) {
                if ($role === 'platform_admin') $q->whereNull('agency_id');
                else $q->where('agency_id', $user->agency_id);
            })
            ->delete();
        return response()->json(['ok' => true]);
    }
}
