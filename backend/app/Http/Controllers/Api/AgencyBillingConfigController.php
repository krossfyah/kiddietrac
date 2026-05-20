<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Agency billing settings.
 * - Late-fee percent / cap / grace days (was hardcoded in v22p49)
 * - SMS enabled flag
 * - Default locale
 * Stripe customer creation / autopay toggle lives in
 * StripeParentPayController.
 */
final class AgencyBillingConfigController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $row = DB::table('agencies')->where('id', $agencyId)->first([
            'id', 'name', 'late_fee_percent', 'late_fee_cap', 'late_fee_grace_days',
            'sms_enabled', 'default_locale',
        ]);
        return response()->json(['data' => $row]);
    }

    public function update(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAdmin($request, $agencyId);
        $data = $request->validate([
            'late_fee_percent'    => 'nullable|numeric|min:0|max:25',
            'late_fee_cap'        => 'nullable|numeric|min:0|max:500',
            'late_fee_grace_days' => 'nullable|integer|min:0|max:60',
            'sms_enabled'         => 'nullable|boolean',
            'default_locale'      => 'nullable|string|in:en,fr,es',
        ]);
        DB::table('agencies')->where('id', $agencyId)->update($data + ['updated_at' => now()]);
        return response()->json(['status' => 'updated']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }

    private function assertAgencyAdmin(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->where('role', 'agency_admin')
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
