<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\LearningFrameworks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Learning framework (2026-09-29). See App\Support\LearningFrameworks.
 *
 * GET  /agency/learning-framework   any signed-in member: the agency's resolved framework
 *                                   plus the catalogue (screens label areas from it)
 * POST /admin/learning-framework    agency admin: choose one, or define a custom one
 */
class LearningFrameworkController extends Controller
{
    use ResolvesCentreContext;

    public function show(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $catalogue = [];
        foreach (LearningFrameworks::all() as $k => $f) {
            $catalogue[] = ['key' => $k, 'name' => $f['name'], 'short' => $f['short'], 'region' => $f['region'],
                'areas' => array_map(fn ($ak, $v) => ['key' => $ak, 'label' => $v[0], 'hint' => $v[1]], array_keys($f['areas']), $f['areas'])];
        }
        $settings = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];

        return response()->json([
            'framework' => LearningFrameworks::forAgency($agencyId),
            'catalogue' => $catalogue,
            'custom' => [
                'name' => (string) ($settings['learning_framework']['custom_name'] ?? ''),
                'areas' => array_values((array) ($settings['learning_framework']['custom_areas'] ?? [])),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        $data = $request->validate([
            'key' => ['required', 'string', 'in:' . implode(',', array_keys(LearningFrameworks::all()))],
            'custom_name' => ['nullable', 'string', 'max:80'],
            'custom_areas' => ['nullable', 'array', 'max:10'],
            'custom_areas.*.label' => ['required_with:custom_areas', 'string', 'max:60'],
            'custom_areas.*.hint' => ['nullable', 'string', 'max:200'],
            'custom_areas.*.key' => ['nullable', 'string', 'max:40'],
        ]);
        $cfg = ['key' => $data['key']];
        if ($data['key'] === 'CUSTOM') {
            $areas = [];
            $seen = [];
            foreach ((array) ($data['custom_areas'] ?? []) as $a) {
                $label = trim((string) $a['label']);
                if ($label === '') continue;
                // Keep an existing key (records already point at it); new areas get a slug.
                $key = preg_match('/^[a-z0-9_]{1,40}$/', (string) ($a['key'] ?? '')) ? $a['key'] : LearningFrameworks::slug($label);
                while (isset($seen[$key])) $key .= '_2';
                $seen[$key] = true;
                $areas[] = ['key' => $key, 'label' => $label, 'hint' => trim((string) ($a['hint'] ?? ''))];
            }
            if (count($areas) < 2) {
                return response()->json(['message' => 'A custom framework needs at least two areas.'], 422);
            }
            $cfg['custom_name'] = trim((string) ($data['custom_name'] ?? '')) ?: 'Our framework';
            $cfg['custom_areas'] = $areas;
        }

        DB::transaction(function () use ($agencyId, $cfg) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $settings = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $settings['learning_framework'] = $cfg;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        });
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId,
                'action' => 'settings.learning_framework', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Learning framework set to ' . $cfg['key'], 'framework' => $cfg]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }

        return response()->json(['framework' => LearningFrameworks::forAgency($agencyId)]);
    }
}
