<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
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
    use ResolvesCentreContext;

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
        // SECURITY (v22p96): the prior blanket `$isStaff` accepted any active staff
        // role anywhere, so a director/admin of agency A — or a switched
        // platform_admin — could read/disable agency B's billing schedule. Now must
        // be a guardian of THIS family, staff of its centre, or a platform_admin
        // scoped to its agency.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
    }
}
