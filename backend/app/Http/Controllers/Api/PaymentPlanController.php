<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p63 — Custom payment plans.
 * Director creates a plan: $1,200 split into 4 monthly installments
 * starting June 1. Each installment generates its own invoice line on
 * its due date (handled by a daily cron — for MVP we record metadata).
 */
final class PaymentPlanController extends Controller
{
    public function listForFamily(Request $request, int $familyId): JsonResponse
    {
        $plans = DB::table('payment_plans')->where('family_id', $familyId)
            ->orderByDesc('created_at')->get();
        $planIds = $plans->pluck('id');
        $installments = DB::table('payment_plan_installments')
            ->whereIn('payment_plan_id', $planIds)
            ->orderBy('due_date')->get()->groupBy('payment_plan_id');
        $plans->transform(function ($p) use ($installments) {
            $p->installments = $installments->get($p->id, collect());
            return $p;
        });
        return response()->json(['data' => $plans]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => 'required|integer',
            'total_amount' => 'required|numeric|min:0.01',
            'installment_count' => 'required|integer|min:2|max:24',
            'first_due_date' => 'required|date',
            'cadence' => 'required|in:weekly,biweekly,monthly',
            'notes' => 'nullable|string|max:1000',
        ]);
        $this->assertStaff($request);

        $per = round((float) $data['total_amount'] / (int) $data['installment_count'], 2);

        $planId = DB::table('payment_plans')->insertGetId([
            'family_id' => $data['family_id'],
            'total_amount' => $data['total_amount'],
            'installment_count' => $data['installment_count'],
            'status' => 'active',
            'notes' => $data['notes'] ?? null,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $cursor = Carbon::parse($data['first_due_date']);
        for ($i = 1; $i <= (int) $data['installment_count']; $i++) {
            // Last installment carries any rounding remainder
            $amt = ($i === (int) $data['installment_count'])
                ? round((float) $data['total_amount'] - $per * ((int) $data['installment_count'] - 1), 2)
                : $per;
            DB::table('payment_plan_installments')->insert([
                'payment_plan_id' => $planId,
                'due_date' => $cursor->toDateString(),
                'amount' => $amt,
                'status' => 'pending',
                'created_at' => now(),
            ]);
            $cursor = $cursor->copy()->add(match ($data['cadence']) {
                'weekly' => '1 week', 'biweekly' => '2 weeks', 'monthly' => '1 month',
            });
        }

        // Notify guardians
        $gids = DB::table('guardians')->where('family_id', $data['family_id'])->pluck('user_id');
        foreach ($gids as $gid) {
            DB::table('notifications')->insert([
                'user_id' => $gid, 'type' => 'payment_plan',
                'title' => 'Payment plan created',
                'body' => '$' . number_format((float) $data['total_amount'], 2) . ' over ' . $data['installment_count'] . ' ' . $data['cadence'] . ' installments',
                'data' => json_encode(['link' => '#payment-plans', 'plan_id' => $planId]),
                'created_at' => now(),
            ]);
        }

        return response()->json(['id' => $planId], 201);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $this->assertStaff($request);
        DB::table('payment_plans')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);
        DB::table('payment_plan_installments')->where('payment_plan_id', $id)
            ->where('status', 'pending')->update(['status' => 'cancelled']);
        return response()->json(['status' => 'cancelled']);
    }

    public function myPlans(Request $request): JsonResponse
    {
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 404);
        return $this->listForFamily($request, (int) $famId);
    }

    private function assertStaff(Request $request): void
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['centre_director', 'agency_admin', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
    }
}
