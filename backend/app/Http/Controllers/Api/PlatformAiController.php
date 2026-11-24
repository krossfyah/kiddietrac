<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiConfig;
use App\Support\Audit;
use App\Support\PlatformSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform → AI usage (2026-10-28): the Claude connection (key + model, set here), the account's live state, the
 * estimated credit left and full usage stats. Platform admins only (route group). See App\Support\AiConfig.
 */
class PlatformAiController extends Controller
{
    /** GET /platform/ai */
    public function show(): JsonResponse
    {
        return response()->json(AiConfig::overview());
    }

    /** PUT /platform/ai/settings {key?, model?} - a new key or model is tried against Anthropic before it is kept. */
    public function save(Request $request): JsonResponse
    {
        $d = $request->validate([
            'key' => ['nullable', 'string', 'min:20', 'max:400'],
            'model' => ['nullable', 'string', 'in:' . implode(',', array_keys(AiConfig::MODELS))],
        ]);
        $key = isset($d['key']) ? trim($d['key']) : null;
        $model = $d['model'] ?? null;
        if ($key || $model) {
            [$ok, $status, $msg] = AiConfig::test($key ?: null, $model ?: null);
            if (! $ok) {
                return response()->json(['message' => 'Not saved: ' . $msg], 422);
            }
        }
        $what = [];
        if ($key) {
            PlatformSettings::setSecret(AiConfig::KEY, $key);
            $what[] = 'the Claude API key (…' . substr($key, -4) . ')';
        }
        if ($model) {
            PlatformSettings::set(AiConfig::MODEL, $model);
            $what[] = 'the AI model (' . $model . ')';
        }
        if ($what) {
            AiConfig::apply();
            AiConfig::noteReply(true, 200);
            Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'platform.ai_settings', 'entity_type' => 'platform_setting',
                'payload' => json_encode(['summary' => 'Changed ' . implode(' and ', $what)]), 'created_at' => now()]);
        }

        return $this->show();
    }

    /** PUT /platform/ai/off {agency_ids: int[]} - the agencies with AI switched off (test agencies). */
    public function saveOff(Request $request): JsonResponse
    {
        $d = $request->validate(['agency_ids' => ['present', 'array'], 'agency_ids.*' => ['integer']]);
        $live = \Illuminate\Support\Facades\DB::table('agencies')->pluck('name', 'id');
        $ids = array_values(array_unique(array_filter(array_map('intval', $d['agency_ids']), fn ($i) => isset($live[$i]))));
        PlatformSettings::set(AiConfig::OFF, json_encode($ids));
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'platform.ai_off', 'entity_type' => 'platform_setting',
            'payload' => json_encode(['summary' => 'AI switched off for: ' . ($ids ? implode(', ', array_map(fn ($i) => $live[$i], $ids)) : 'no agency')]), 'created_at' => now()]);

        return $this->show();
    }

    /** POST /platform/ai/test - one tiny call with the saved key and model. */
    public function test(): JsonResponse
    {
        [$ok, $status, $msg] = AiConfig::test();
        AiConfig::noteReply($ok, $status, $ok ? '' : $msg);

        return response()->json(['ok' => $ok, 'message' => $ok ? 'Connected — Claude answered on ' . AiConfig::model() . '.' : $msg] + ['overview' => AiConfig::overview()], $ok ? 200 : 422);
    }

    /** POST /platform/ai/topups {amount, date, note?} - record credits bought, for the estimated balance. */
    public function addTopup(Request $request): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'], 'date' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:120']]);
        $list = AiConfig::topups();
        $list[] = ['id' => bin2hex(random_bytes(4)), 'amount' => round((float) $d['amount'], 2), 'date' => substr((string) $d['date'], 0, 10),
            'note' => (string) ($d['note'] ?? ''), 'by' => (int) $request->user()->id];
        PlatformSettings::set(AiConfig::TOPUPS, json_encode($list));
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'platform.ai_topup', 'entity_type' => 'platform_setting',
            'payload' => json_encode(['summary' => 'Recorded $' . number_format((float) $d['amount'], 2) . ' of Claude credits added on ' . substr((string) $d['date'], 0, 10)]), 'created_at' => now()]);

        return $this->show();
    }

    /** DELETE /platform/ai/topups/{id} */
    public function removeTopup(Request $request, string $id): JsonResponse
    {
        $list = array_values(array_filter(AiConfig::topups(), fn ($t) => ($t['id'] ?? '') !== $id));
        PlatformSettings::set(AiConfig::TOPUPS, json_encode($list));
        Audit::write(['user_id' => (int) $request->user()->id, 'action' => 'platform.ai_topup_removed', 'entity_type' => 'platform_setting',
            'payload' => json_encode(['summary' => 'Removed a recorded Claude credit top-up']), 'created_at' => now()]);

        return $this->show();
    }
}
