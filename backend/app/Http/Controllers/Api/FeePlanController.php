<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p86 — reusable tuition / fee plans (the fee schedule). A plan is a named
 * amount + billing frequency, optionally scoped to a centre/room/age group.
 * These are the building blocks for invoice automation.
 */
final class FeePlanController extends Controller
{
    private function agencyId(Request $r): ?int
    {
        $h = (int) $r->header('X-Active-Agency-Id');
        if ($h && DB::table('role_assignments')->where('user_id', $r->user()->id)->where('active', true)
                ->where(function ($q) use ($h) { $q->where('role', 'platform_admin')->orWhere('agency_id', $h); })->exists()) {
            return $h;   // active-agency header honoured only for a platform_admin or a real member of that agency
        }
        $v = DB::table('role_assignments')->where('user_id', $r->user()->id)
            ->where('active', 1)->value('agency_id');
        return $v ? (int) $v : null;
    }

    public function index(Request $r): JsonResponse
    {
        $aid = $this->agencyId($r);
        if (!$aid) return response()->json(['plans' => []]);

        $plans = DB::table('fee_plans')->where('agency_id', $aid)->whereNull('deleted_at')
            ->orderByDesc('active')->orderBy('name')->get();
        $centreNames = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $roomNames = DB::table('rooms')->pluck('name', 'id');

        $out = $plans->map(function ($p) use ($centreNames, $roomNames) {
            return [
                'id' => $p->id,
                'name' => $p->name,
                'amount' => (float) $p->amount,
                'frequency' => $p->frequency,
                'centre_id' => $p->centre_id,
                'centre_name' => $p->centre_id ? ($centreNames[$p->centre_id] ?? null) : null,
                'room_id' => $p->room_id,
                'room_name' => $p->room_id ? ($roomNames[$p->room_id] ?? null) : null,
                'age_group' => $p->age_group,
                'description' => $p->description,
                'active' => (bool) $p->active,
            ];
        })->all();

        return response()->json(['plans' => $out]);
    }

    public function store(Request $r): JsonResponse
    {
        $aid = $this->agencyId($r);
        if (!$aid) return response()->json(['message' => 'No agency access'], 403);
        $d = $this->validateData($r);
        $id = DB::table('fee_plans')->insertGetId(array_merge($d, [
            'agency_id' => $aid, 'created_at' => now(), 'updated_at' => now(),
        ]));
        return response()->json(['id' => $id, 'message' => 'Plan saved'], 201);
    }

    public function update(Request $r, int $id): JsonResponse
    {
        $aid = $this->agencyId($r);
        $row = DB::table('fee_plans')->where('id', $id)->where('agency_id', $aid)->whereNull('deleted_at')->first();
        if (!$row) return response()->json(['message' => 'Not found'], 404);
        $d = $this->validateData($r);
        $d['updated_at'] = now();
        DB::table('fee_plans')->where('id', $id)->update($d);
        return response()->json(['message' => 'Updated']);
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $aid = $this->agencyId($r);
        $n = DB::table('fee_plans')->where('id', $id)->where('agency_id', $aid)->whereNull('deleted_at')
            ->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => $n ? 'Deleted' : 'Not found']);
    }

    /**
     * POST /admin/fee-plans/{id}/generate-invoices  { family_ids[], status?, due_days? }
     * Invoice automation: create an invoice for each selected family using this
     * plan's amount as a tuition line. Matches the existing invoices schema.
     */
    public function generateInvoices(Request $r, int $id): JsonResponse
    {
        $aid = $this->agencyId($r);
        $plan = DB::table('fee_plans')->where('id', $id)->where('agency_id', $aid)->whereNull('deleted_at')->first();
        if (!$plan) return response()->json(['message' => 'Plan not found'], 404);

        $data = $r->validate([
            'family_ids' => ['required', 'array', 'min:1'],
            'family_ids.*' => ['integer'],
            'status' => ['nullable', 'in:draft,sent'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:120'],
        ]);
        $status = $data['status'] ?? 'draft';
        $dueDays = $data['due_days'] ?? 14;
        $amount = round((float) $plan->amount, 2);

        $issue = now();
        $periodStart = $issue->copy()->startOfMonth();
        $periodEnd = $issue->copy()->endOfMonth();
        $due = $issue->copy()->addDays($dueDays);

        // Restrict to families in this agency (and the plan's centre, if scoped).
        $famQ = DB::table('families')->whereIn('id', $data['family_ids'])->whereNull('deleted_at');
        if ($plan->centre_id) {
            $famQ->where('centre_id', $plan->centre_id);
        } else {
            $centreIds = DB::table('centres')->where('agency_id', $aid)->pluck('id')->all();
            if (empty($centreIds)) return response()->json(['created' => 0]);
            $famQ->whereIn('centre_id', $centreIds);
        }
        $families = $famQ->get(['id', 'centre_id', 'family_name']);

        $created = 0;
        foreach ($families as $f) {
            $num = 'INV-' . $issue->format('Ym') . '-' . str_pad((string) $f->id, 4, '0', STR_PAD_LEFT)
                . '-' . strtoupper(substr(md5(uniqid('', true)), 0, 3));
            $invId = DB::table('invoices')->insertGetId([
                'centre_id' => $f->centre_id,
                'family_id' => $f->id,
                'invoice_number' => $num,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'issued_at' => $issue,
                'due_at' => $due,
                'subtotal' => $amount,
                'subsidy_amount' => 0,
                'total' => $amount,
                'balance_due' => $amount,
                'status' => $status,
                'notes' => 'Auto-generated from plan: ' . $plan->name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('invoice_lines')->insert([
                'invoice_id' => $invId,
                'description' => $plan->name . ' (' . $plan->frequency . ')',
                'line_type' => 'tuition',
                'quantity' => 1,
                'unit_amount' => $amount,
                'amount' => $amount,
            ]);
            $created++;
        }

        \App\Support\Audit::write([
            'user_id' => $r->user()->id,
            'action' => 'fee_plan.invoices_generated',
            'entity_type' => 'fee_plan',
            'entity_id' => $id,
            'payload' => json_encode(['created' => $created, 'amount' => $amount, 'status' => $status]),
            'created_at' => now(),
        ]);

        return response()->json(['created' => $created, 'status' => $status]);
    }

    private function validateData(Request $r): array
    {
        $d = $r->validate([
            'name' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'frequency' => ['required', 'in:weekly,biweekly,monthly,one_time'],
            'centre_id' => ['nullable', 'integer'],
            'room_id' => ['nullable', 'integer'],
            'age_group' => ['nullable', 'string', 'max:40'],
            'description' => ['nullable', 'string', 'max:500'],
            'active' => ['nullable', 'boolean'],
        ]);
        return [
            'name' => $d['name'],
            'amount' => $d['amount'],
            'frequency' => $d['frequency'],
            'centre_id' => $d['centre_id'] ?? null,
            'room_id' => $d['room_id'] ?? null,
            'age_group' => $d['age_group'] ?? null,
            'description' => $d['description'] ?? null,
            'active' => array_key_exists('active', $d) ? (int) $d['active'] : 1,
        ];
    }
}
