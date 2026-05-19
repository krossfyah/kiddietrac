<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p9 — sibling discount tier config (agency-scoped).
 * Stored as agencies.settings JSON: { sibling_discounts: [{rank, percent}, ...] }.
 */
final class SiblingDiscountController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);
        $settings = $this->readSettings($agencyId);
        return response()->json([
            'tiers' => $settings['sibling_discounts'] ?? [],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency context'], 403);

        $data = $request->validate([
            'tiers' => ['present', 'array'],
            'tiers.*.rank' => ['required', 'integer', 'min:2', 'max:20'],
            'tiers.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $settings = $this->readSettings($agencyId);
        $settings['sibling_discounts'] = collect($data['tiers'])
            ->sortBy('rank')
            ->values()
            ->all();

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'updated_at' => now(),
        ]);

        return response()->json([
            'tiers' => $settings['sibling_discounts'],
            'message' => 'Sibling discount tiers updated',
        ]);
    }

    private function resolveAgencyId(Request $request): ?int
    {
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }

    private function readSettings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        return $raw ? (json_decode($raw, true) ?: []) : [];
    }
}
