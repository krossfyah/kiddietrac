<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AiBundle;
use App\Support\AskKiddieTrac;
use App\Support\AskScope;
use App\Support\Audit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * POST /ai/assist/ask {question, history?} - Ask KiddieTrac, the admin AI assistant (2026-11-03, AI Bundle).
 *
 * Route: role:centre_director,agency_admin,platform_admin + throttle (per user); EnforcePlanFeatures gates the
 * ai/assist prefix to ai_tools, and this checks AiBundle::on for the ACTIVE agency as well (the route map skips an
 * agency with no licence row; this does not). The scope - agency, centres, permissions - is built here and nowhere
 * else; see AskScope and AskTools. Every question is audited (ai.ask) with the areas looked at, never the answer.
 */
class AskController extends Controller
{
    use ResolvesCentreContext;

    private const PER_DAY = 150;

    public function ask(Request $request): JsonResponse
    {
        $d = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:600'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:4000'],
        ]);
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            return response()->json(['message' => 'Choose an agency first.'], 400);
        }
        // Fail closed: asked about an agency the person does not belong to, answer nothing (never their own instead).
        $hdr = (int) $request->header('X-Active-Agency-Id');
        if ($hdr > 0 && $hdr !== (int) $agencyId) {
            return response()->json(['message' => 'Ask KiddieTrac can only answer about an agency you belong to.'], 403);
        }
        $scope = AskScope::fromRequest($request, $agencyId);
        if (! $scope) {
            return response()->json(['message' => 'Ask KiddieTrac is for administrators and centre directors of this agency.'], 403);
        }
        if (! AiBundle::on($agencyId)) {
            return response()->json(['message' => 'Ask KiddieTrac is part of the AI Bundle.', 'upsell' => true], 403);
        }
        $dayKey = 'ai-ask-day:' . $scope->userId;
        if (RateLimiter::tooManyAttempts($dayKey, self::PER_DAY)) {
            return response()->json(['message' => 'You have asked a lot of questions today. Ask KiddieTrac will be ready again tomorrow.'], 429);
        }
        RateLimiter::hit($dayKey, 86400);

        $q = trim(preg_replace('/\s+/u', ' ', (string) $d['question']));
        $res = AskKiddieTrac::run($scope, $q, $d['history'] ?? []);

        $areas = array_map(fn ($t) => \App\Support\AskTools::LABELS[$t] ?? $t, $res['tools']);
        Audit::write([
            'user_id' => $scope->userId,
            'agency_id' => $agencyId,
            'action' => 'ai.ask',
            'payload' => json_encode([
                // The question (people ask in their own words, so it is kept short) and what was looked at - never the answer.
                'summary' => 'Asked Ask KiddieTrac: "' . mb_substr($q, 0, 200) . '"' . ($areas ? ' (looked at ' . implode(', ', array_unique($areas)) . ')' : ''),
                'question' => mb_substr($q, 0, 300),
                'tools' => $res['tools'],
                'answered' => $res['ok'],
                'model' => $res['usage']['model'] ?? null,
                'tokens_in' => ($res['usage']['input'] ?? 0) + ($res['usage']['cache_read'] ?? 0) + ($res['usage']['cache_write'] ?? 0),
                'tokens_out' => $res['usage']['output'] ?? 0,
                'centres' => $scope->isWide() ? 'all' : count($scope->centreIds),
            ], JSON_UNESCAPED_UNICODE),
        ]);

        if (! $res['ok'] && ($res['status'] ?? 200) >= 500) {
            return response()->json(['message' => $res['answer']], $res['status']);
        }

        return response()->json([
            'answer' => $res['answer'],
            'links' => $res['links'],
            'followups' => $res['followups'],
        ]);
    }
}
