<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p74 — per-agency billing currency.
 * Lets each agency admin choose the currency used for invoices/billing display.
 */
final class CurrencyController extends Controller
{
    private const SUPPORTED = [
        'CAD' => ['symbol' => '$',  'label' => 'Canadian Dollar'],
        'USD' => ['symbol' => '$',  'label' => 'US Dollar'],
        'GBP' => ['symbol' => '£',  'label' => 'British Pound'],
        'EUR' => ['symbol' => '€',  'label' => 'Euro'],
        'AUD' => ['symbol' => '$',  'label' => 'Australian Dollar'],
        'NZD' => ['symbol' => '$',  'label' => 'New Zealand Dollar'],
    ];

    private function resolveAgencyId(Request $request): int
    {
        $h = $request->header('X-Active-Agency-Id');
        if ($h) return (int) $h;
        return (int) DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', 1)->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Admin only');
    }

    /** GET /admin/currency — public-ish (any authed user) so the UI can format money */
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $cur = DB::table('agencies')->where('id', $agencyId)->value('currency') ?: 'CAD';
        if (!isset(self::SUPPORTED[$cur])) $cur = 'CAD';
        return response()->json([
            'currency'  => $cur,
            'symbol'    => self::SUPPORTED[$cur]['symbol'],
            'label'     => self::SUPPORTED[$cur]['label'],
            'supported' => collect(self::SUPPORTED)->map(fn ($v, $k) => ['code' => $k, 'symbol' => $v['symbol'], 'label' => $v['label']])->values(),
        ]);
    }

    /** PATCH /admin/currency { currency } */
    public function update(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $data = $request->validate([
            'currency' => ['required', 'string', 'in:' . implode(',', array_keys(self::SUPPORTED))],
        ]);
        $agencyId = $this->resolveAgencyId($request);
        DB::table('agencies')->where('id', $agencyId)->update([
            'currency'   => $data['currency'],
            'updated_at' => now(),
        ]);
        return response()->json(['ok' => true, 'currency' => $data['currency']]);
    }
}
