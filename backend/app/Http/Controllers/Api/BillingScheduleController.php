<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Weekly or monthly billing schedule per family.
 * Used by AutopayChargeCommand v22p51 — extended to check
 * billing_schedules.next_charge_at when present.
 */
final class BillingScheduleController extends Controller
{
    public function get(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId);
        $row = DB::table('billing_schedules')->where('family_id', $familyId)->first();
        return response()->json(['data' => $row]);
    }

    public function set(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => 'required|integer',
            'frequency' => 'required|in:weekly,biweekly,monthly',
            'day_of_week' => 'nullable|integer|between:0,6',
            'day_of_month' => 'nullable|integer|between:1,31',
            'anchor_date' => 'nullable|date',
        ]);
        $this->assertAccess($request, (int) $data['family_id']);
        $next = $this->computeNextCharge($data);
        DB::table('billing_schedules')->updateOrInsert(
            ['family_id' => $data['family_id']],
            [
                'frequency' => $data['frequency'],
                'day_of_week' => $data['day_of_week'] ?? null,
                'day_of_month' => $data['day_of_month'] ?? null,
                'anchor_date' => $data['anchor_date'] ?? null,
                'next_charge_at' => $next,
                'active' => 1,
                'created_at' => DB::raw('COALESCE(created_at, NOW())'),
                'updated_at' => now(),
            ]
        );
        return response()->json(['next_charge_at' => $next]);
    }

    public function disable(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId);
        DB::table('billing_schedules')->where('family_id', $familyId)->update([
            'active' => 0, 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'disabled']);
    }

    private function computeNextCharge(array $d): string
    {
        $now = Carbon::now();
        return match ($d['frequency']) {
            'weekly' => $now->copy()->next($d['day_of_week'] ?? 1)->toDateTimeString(),
            'biweekly' => $now->copy()->next($d['day_of_week'] ?? 1)->addWeek()->toDateTimeString(),
            'monthly' => $now->copy()->addMonth()->startOfMonth()->addDays(max(0, ($d['day_of_month'] ?? 1) - 1))->toDateTimeString(),
        };
    }

    private function assertAccess(Request $request, int $familyId): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', 1)->exists();
        if ($isStaff) return;
        $hasFamily = DB::table('guardians')->where('user_id', $u->id)->where('family_id', $familyId)->exists();
        abort_unless($hasFamily, 403);
    }
}
