<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class InvoiceController extends Controller
{
    use ResolvesCentreContext;

    /**
     * GET /api/v1/parent/children/{child}/invoices
     */
    public function forChild(Request $request, int $childId): JsonResponse
    {
        if (!$this->canAccessChild($request->user(), $childId)) {
            abort(403);
        }

        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $limit = min(12, (int) $request->input('limit', 6));
        $statusFilter = $request->input('status'); // current, all, paid, unpaid

        $q = DB::table('invoices')
            ->where('family_id', $child->family_id)
            ->orderByDesc('issued_at')
            ->limit($limit);

        if ($statusFilter === 'current') {
            // Most recent unpaid invoice
            $q->whereIn('status', ['sent', 'partial', 'overdue', 'draft']);
        } elseif ($statusFilter === 'paid') {
            $q->where('status', 'paid');
        } elseif ($statusFilter === 'unpaid') {
            $q->whereIn('status', ['sent', 'partial', 'overdue']);
        }

        $invoices = $q->get();

        // If no invoices yet, generate a synthetic "current month" view from enrollment data
        if ($invoices->isEmpty() && $statusFilter === 'current') {
            $synthetic = $this->buildSyntheticCurrentInvoice($childId);
            return response()->json(['invoices' => $synthetic ? [$synthetic] : []]);
        }

        return response()->json([
            'invoices' => $invoices->map(fn ($i) => $this->formatInvoice($i))->all(),
        ]);
    }

    /**
     * GET /api/v1/director/invoices
     */
    public function index(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) {
            return response()->json(['invoices' => []]);
        }

        $q = DB::table('invoices')
            ->join('families', 'families.id', '=', 'invoices.family_id')
            ->where('invoices.centre_id', $centreId)
            ->select(
                'invoices.*',
                'families.family_name',
            )
            ->orderByDesc('invoices.issued_at');

        if ($statusFilter = $request->input('status')) {
            $q->where('invoices.status', $statusFilter);
        }

        $invoices = $q->limit(100)->get();

        $stats = [
            'total_outstanding' => (float) DB::table('invoices')
                ->where('centre_id', $centreId)
                ->whereIn('status', ['sent', 'partial', 'overdue'])
                ->sum('balance_due'),
            'overdue_count' => DB::table('invoices')
                ->where('centre_id', $centreId)
                ->where('status', 'overdue')
                ->count(),
            'paid_this_month' => (float) DB::table('invoices')
                ->where('centre_id', $centreId)
                ->where('status', 'paid')
                ->whereMonth('issued_at', now()->month)
                ->sum('total'),
        ];

        return response()->json([
            'invoices' => $invoices->map(fn ($i) => $this->formatInvoice($i))->all(),
            'stats' => $stats,
        ]);
    }

    /**
     * GET /api/v1/director/invoices/{invoice}
     */
    public function show(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$this->authorizeCentreAccess($request->user(), (int) $invoice->centre_id)) {
            abort(403);
        }

        $lines = DB::table('invoice_lines')
            ->where('invoice_id', $invoiceId)
            ->get();

        $payments = DB::table('payments')
            ->where('invoice_id', $invoiceId)
            ->orderByDesc('paid_at')
            ->get();

        $family = DB::table('families')->where('id', $invoice->family_id)->first();

        return response()->json([
            'invoice' => $this->formatInvoice($invoice),
            'family' => $family,
            'lines' => $lines,
            'payments' => $payments,
        ]);
    }

    /**
     * POST /api/v1/director/invoices/generate
     * Generate invoices for the current month for every enrolled family.
     */
    public function generateBatch(Request $request): JsonResponse
    {
        $centreId = $this->resolveCentreId($request->user());
        if (!$centreId) {
            return response()->json(['message' => 'No centre access'], 403);
        }

        $month = $request->input('month', now()->month);
        $year = $request->input('year', now()->year);
        $issueDate = Carbon::createFromDate($year, $month, 1);
        $dueDate = $issueDate->copy()->addDays(15);

        // v22p9: load sibling-discount tiers from agency settings.
        $agencyId = DB::table("centres")->where("id", $centreId)->value("agency_id");
        $siblingTiers = [];
        if ($agencyId) {
            $rawSettings = DB::table("agencies")->where("id", $agencyId)->value("settings");
            $settings = $rawSettings ? json_decode($rawSettings, true) : [];
            $siblingTiers = collect($settings["sibling_discounts"] ?? [])
                ->sortBy("rank")
                ->values()
                ->all();
        }


        // Get all enrolled children at this centre with their families
        $enrollments = DB::table('enrollments')
            ->join('children', 'children.id', '=', 'enrollments.child_id')
            ->join('families', 'families.id', '=', 'children.family_id')
            ->leftJoin('rooms', 'rooms.id', '=', 'enrollments.room_id')
            ->where('families.centre_id', $centreId)
            ->whereNull('enrollments.end_date')
            ->where('children.enrollment_status', 'enrolled')
            ->select(
                'families.id as family_id',
                'children.id as child_id',
                'children.first_name',
                'children.last_name',
                'enrollments.monthly_fee',
                'rooms.name as room_name',
            )
            ->get()
            ->groupBy('family_id');

        $generated = 0;
        $skipped = 0;

        foreach ($enrollments as $familyId => $childEnrollments) {
            // Skip if invoice already exists for this family for this month
            $exists = DB::table('invoices')
                ->where('family_id', $familyId)
                ->whereMonth('issued_at', $month)
                ->whereYear('issued_at', $year)
                ->exists();

            if ($exists) {
                $skipped++;
                continue;
            }

            DB::transaction(function () use ($familyId, $childEnrollments, $centreId, $issueDate, $dueDate, &$generated) {
                $subtotal = 0;
                $subsidyTotal = 0;
                $lineItems = [];

                foreach ($childEnrollments as $en) {
                    $subtotal += (float) $en->monthly_fee;

                    $subsidy = DB::table('subsidies')
                        ->where('child_id', $en->child_id)
                        ->where('active', true)
                        ->where('valid_from', '<=', $issueDate)
                        ->where(function ($q) use ($issueDate) {
                            $q->whereNull('valid_to')->orWhere('valid_to', '>=', $issueDate);
                        })
                        ->first();
                    $subsidyAmount = $subsidy ? (float) $subsidy->monthly_amount : 0;
                    $subsidyTotal += $subsidyAmount;

                    $lineItems[] = [
                        'description' => "Tuition — {$en->first_name} {$en->last_name} ({$en->room_name})",
                        'amount' => $en->monthly_fee,
                        'subsidy' => $subsidyAmount,
                        'net' => $en->monthly_fee - $subsidyAmount,
                    ];
                }

                // v22p9: sibling discounts — apply per child by enrollment rank.
                $discountTotal = 0.0;
                $discountLines = [];
                if (! empty($siblingTiers) && $childEnrollments->count() > 1) {
                    // Rank by enrollment start_date asc (oldest = rank 1, no discount)
                    $ranked = DB::table("enrollments")
                        ->join("children", "children.id", "=", "enrollments.child_id")
                        ->where("children.family_id", $familyId)
                        ->whereNull("enrollments.end_date")
                        ->orderBy("enrollments.start_date")
                        ->select("children.id as child_id", "children.first_name", "enrollments.monthly_fee")
                        ->get();
                    $pos = 1;
                    foreach ($ranked as $r) {
                        $rank = $pos++;
                        if ($rank <= 1) continue;
                        // Pick the highest matching tier (rank >= tier.rank)
                        $appliedTier = null;
                        foreach ($siblingTiers as $t) {
                            if ((int) $t["rank"] <= $rank) $appliedTier = $t;
                        }
                        if (! $appliedTier) continue;
                        $pct = (float) $appliedTier["percent"];
                        $disc = round((float) $r->monthly_fee * ($pct / 100), 2);
                        $discountTotal += $disc;
                        $discountLines[] = [
                            "child_id" => $r->child_id,
                            "description" => "Sibling discount (".$pct."%) — ".$r->first_name,
                            "amount" => -$disc,
                        ];
                    }
                }

                $total = $subtotal - $subsidyTotal - $discountTotal;
                $invoiceNumber = 'INV-'.now()->format('Ym').'-'.str_pad((string) $familyId, 4, '0', STR_PAD_LEFT);

                $invoiceId = DB::table('invoices')->insertGetId([
                    'centre_id' => $centreId,
                    'family_id' => $familyId,
                    'invoice_number' => $invoiceNumber,
                    'issued_at' => $issueDate,
                    'due_at' => $dueDate,
                    'subtotal' => $subtotal,
                    'subsidy_amount' => $subsidyTotal,
                    'total' => $total,
                    'balance_due' => $total,
                    'status' => 'sent',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                foreach ($lineItems as $line) {
                    DB::table('invoice_lines')->insert([
                        'invoice_id' => $invoiceId,
                        'description' => $line['description'],
                        'amount' => $line['amount'],
                        'subsidy_amount' => $line['subsidy'],
                        'net_amount' => $line['net'],
                        'created_at' => now(),
                    ]);
                }

                // v22p9: also insert each sibling-discount line.
                foreach ($discountLines as $dl) {
                    DB::table("invoice_lines")->insert([
                        "invoice_id" => $invoiceId,
                        "child_id" => $dl["child_id"],
                        "description" => $dl["description"],
                        "line_type" => "adjustment",
                        "quantity" => 1,
                        "unit_amount" => $dl["amount"],
                        "amount" => $dl["amount"],
                    ]);
                }

                $generated++;
            });
        }

        return response()->json([
            'generated' => $generated,
            'skipped' => $skipped,
            'message' => "Generated {$generated} invoices for ".$issueDate->format('F Y'),
        ]);
    }

    /**
     * POST /api/v1/director/invoices/{invoice}/payments
     * Record an offline payment (e-transfer, cheque, cash).
     */
    public function recordPayment(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        if (!$invoice) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$this->authorizeCentreAccess($request->user(), (int) $invoice->centre_id)) {
            abort(403);
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,cheque,e_transfer,bank_transfer,credit_card_offline,other'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        DB::transaction(function () use ($invoiceId, $invoice, $data, $request) {
            DB::table('payments')->insert([
                'invoice_id' => $invoiceId,
                'family_id' => $invoice->family_id,
                'amount' => $data['amount'],
                'method' => $data['method'],
                'paid_at' => $data['paid_at'] ?? now(),
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
                'created_at' => now(),
            ]);

            $totalPaid = (float) DB::table('payments')
                ->where('invoice_id', $invoiceId)
                ->sum('amount');

            $newBalance = max(0, (float) $invoice->total - $totalPaid);
            $newStatus = match (true) {
                $newBalance <= 0.01 => 'paid',
                $totalPaid > 0 => 'partial',
                default => $invoice->status,
            };

            DB::table('invoices')->where('id', $invoiceId)->update([
                'balance_due' => $newBalance,
                'status' => $newStatus,
                'updated_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Payment recorded'], 201);
    }

    // ─── helpers ────────────────────────────────────────────────

    private function buildSyntheticCurrentInvoice(int $childId): ?array
    {
        $enrollment = DB::table('enrollments')
            ->where('child_id', $childId)
            ->whereNull('end_date')
            ->first();

        if (!$enrollment) {
            return null;
        }

        $subsidy = DB::table('subsidies')
            ->where('child_id', $childId)
            ->where('active', true)
            ->first();

        $subtotal = (float) $enrollment->monthly_fee;
        $subsidyAmount = $subsidy ? (float) $subsidy->monthly_amount : 0;
        $total = $subtotal - $subsidyAmount;

        return [
            'id' => null,
            'invoice_number' => 'Estimated — '.now()->format('F Y'),
            'issue_date' => now()->startOfMonth()->toDateString(),
            'due_date' => now()->endOfMonth()->toDateString(),
            'subtotal' => $subtotal,
            'subsidy_amount' => $subsidyAmount,
            'total' => $total,
            'balance_due' => $total,
            'status' => 'estimate',
            'status_label' => 'Estimate',
            'is_estimate' => true,
        ];
    }

    private function formatInvoice(object $i): array
    {
        return [
            'id' => $i->id,
            'invoice_number' => $i->invoice_number,
            'family_name' => $i->family_name ?? null,
            'issue_date' => $i->issued_at,
            'due_date' => $i->due_at,
            'subtotal' => (float) $i->subtotal,
            'subsidy_amount' => (float) ($i->subsidy_amount ?? 0),
            'total' => (float) $i->total,
            'balance_due' => (float) ($i->balance_due ?? $i->total),
            'status' => $i->status,
            'status_label' => ucfirst($i->status),
            'is_estimate' => false,
        ];
    }

    private function canAccessChild($user, int $childId): bool
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) {
            return false;
        }

        if (DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $child->family_id)
            ->exists()) {
            return true;
        }

        $family = DB::table('families')->where('id', $child->family_id)->first();
        return $family ? $this->authorizeCentreAccess($user, (int) $family->centre_id) : false;
    }
}
