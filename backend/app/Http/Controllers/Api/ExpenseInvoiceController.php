<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ExpenseInvoiceController extends Controller
{
    use ResolvesCentreContext;

    private function agencyOrFail(Request $request): int
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            abort(403, 'No agency context.');
        }
        return (int) $agencyId;
    }

    private function scopedCentreId(?int $centreId, int $agencyId): ?int
    {
        if (! $centreId) {
            return null;
        }
        $ok = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->exists();
        return $ok ? $centreId : null;
    }

    private function ownedSupplierId(?int $supplierId, int $agencyId): ?int
    {
        if (! $supplierId) {
            return null;
        }
        return DB::table('suppliers')->where('id', $supplierId)->where('agency_id', $agencyId)->whereNull('deleted_at')->exists()
            ? $supplierId : null;
    }

    private function normaliseLines(array $rawLines): array
    {
        $lines = [];
        $subtotal = 0.0;
        foreach ($rawLines as $l) {
            $desc = trim((string) ($l['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $qty = round((float) ($l['quantity'] ?? 1), 2);
            $unit = round((float) ($l['unit_price'] ?? 0), 2);
            $amount = array_key_exists('amount', $l) && $l['amount'] !== null && $l['amount'] !== ''
                ? round((float) $l['amount'], 2)
                : round($qty * $unit, 2);
            $lines[] = [
                'description' => mb_substr($desc, 0, 200),
                'quantity'    => $qty,
                'unit_price'  => $unit,
                'amount'      => $amount,
                'category'    => isset($l['category']) ? mb_substr((string) $l['category'], 0, 80) : null,
            ];
            $subtotal += $amount;
        }
        return [$lines, round($subtotal, 2)];
    }

    /** Derive the status from paid vs total (unless it's a terminal/manual state). */
    private function paymentStatus(float $total, float $paid, ?string $dueDate, string $current): string
    {
        if (in_array($current, ['void', 'draft', 'awaiting_approval'], true) && $paid <= 0.0) {
            return $current; // keep pre-approval / void state until a payment lands
        }
        if ($total > 0 && $paid + 0.005 >= $total) {
            return 'paid';
        }
        if ($paid > 0) {
            return 'partial';
        }
        // Approved but unpaid: overdue if past due date, else approved.
        if ($dueDate && Carbon::parse($dueDate)->endOfDay()->isPast()) {
            return 'overdue';
        }
        return $current === 'draft' || $current === 'awaiting_approval' ? $current : 'approved';
    }

    /** GET /admin/expense-invoices */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $q = DB::table('expense_invoices as ei')
            ->leftJoin('suppliers as s', 's.id', '=', 'ei.supplier_id')
            ->where('ei.agency_id', $agencyId)
            ->whereNull('ei.deleted_at')
            ->select('ei.*', 's.name as supplier_name', DB::raw('ROUND(ei.total - ei.amount_paid, 2) as balance'));

        if ($request->filled('status')) {
            $q->where('ei.status', $request->input('status'));
        }
        if ($request->filled('supplier_id')) {
            $q->where('ei.supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('centre_id')) {
            $q->where('ei.centre_id', (int) $request->input('centre_id'));
        }
        if ($request->boolean('unpaid')) {
            $q->whereNotIn('ei.status', ['paid', 'void']);
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(function ($w) use ($s) {
                $w->where('ei.reference', 'like', "%{$s}%")
                    ->orWhere('ei.invoice_number', 'like', "%{$s}%")
                    ->orWhere('s.name', 'like', "%{$s}%");
            });
        }

        return response()->json(['expense_invoices' => $q->orderByDesc('ei.id')->limit(500)->get()]);
    }

    /** POST /admin/expense-invoices */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $data = $request->validate([
            'supplier_id'    => ['required', 'integer'],
            'centre_id'      => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'category'       => ['nullable', 'string', 'max:80'],
            'issue_date'     => ['nullable', 'date'],
            'due_date'       => ['nullable', 'date'],
            'tax'            => ['nullable', 'numeric'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'file_url'       => ['nullable', 'string', 'max:500'],
            'status'         => ['nullable', 'in:draft,awaiting_approval'],
            'lines'          => ['array'],
        ]);

        $supplierId = $this->ownedSupplierId((int) $data['supplier_id'], $agencyId);
        if (! $supplierId) {
            return response()->json(['message' => 'Supplier not found in this agency.'], 422);
        }

        [$lines, $subtotal] = $this->normaliseLines($data['lines'] ?? []);
        $tax = round((float) ($data['tax'] ?? 0), 2);

        $id = DB::transaction(function () use ($request, $agencyId, $data, $supplierId, $lines, $subtotal, $tax) {
            $newId = DB::table('expense_invoices')->insertGetId([
                'agency_id'      => $agencyId,
                'centre_id'      => $this->scopedCentreId($data['centre_id'] ?? null, $agencyId),
                'supplier_id'    => $supplierId,
                'reference'      => 'PENDING',
                'invoice_number' => $data['invoice_number'] ?? null,
                'status'         => $data['status'] ?? 'draft',
                'category'       => $data['category'] ?? null,
                'issue_date'     => $data['issue_date'] ?? null,
                'due_date'       => $data['due_date'] ?? null,
                'subtotal'       => $subtotal,
                'tax'            => $tax,
                'total'          => round($subtotal + $tax, 2),
                'amount_paid'    => 0,
                'notes'          => $data['notes'] ?? null,
                'file_url'       => $data['file_url'] ?? null,
                'created_by_id'  => $request->user()->id,
                'created_at'     => now(),
                'updated_at'     => now(),
            ]);
            DB::table('expense_invoices')->where('id', $newId)->update(['reference' => 'EXP-' . str_pad((string) $newId, 6, '0', STR_PAD_LEFT)]);
            foreach ($lines as $l) {
                DB::table('expense_invoice_lines')->insert($l + ['expense_invoice_id' => $newId, 'created_at' => now()]);
            }
            return $newId;
        });

        return response()->json($this->hydrate($agencyId, $id), 201);
    }

    /** GET /admin/expense-invoices/{bill} */
    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $payload = $this->hydrate($agencyId, $id);
        if (! $payload) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($payload);
    }

    /** PATCH /admin/expense-invoices/{bill} */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'supplier_id'    => ['sometimes', 'integer'],
            'centre_id'      => ['nullable', 'integer'],
            'invoice_number' => ['nullable', 'string', 'max:120'],
            'category'       => ['nullable', 'string', 'max:80'],
            'issue_date'     => ['nullable', 'date'],
            'due_date'       => ['nullable', 'date'],
            'tax'            => ['nullable', 'numeric'],
            'notes'          => ['nullable', 'string', 'max:5000'],
            'file_url'       => ['nullable', 'string', 'max:500'],
            'lines'          => ['sometimes', 'array'],
        ]);

        $patch = ['updated_at' => now()];
        if (array_key_exists('supplier_id', $data)) {
            $sid = $this->ownedSupplierId((int) $data['supplier_id'], $agencyId);
            if (! $sid) {
                return response()->json(['message' => 'Supplier not found in this agency.'], 422);
            }
            $patch['supplier_id'] = $sid;
        }
        foreach (['invoice_number', 'category', 'issue_date', 'due_date', 'notes', 'file_url'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = $data[$f];
            }
        }
        if (array_key_exists('centre_id', $data)) {
            $patch['centre_id'] = $this->scopedCentreId($data['centre_id'], $agencyId);
        }

        DB::transaction(function () use ($id, $bill, $data, &$patch) {
            $subtotal = (float) $bill->subtotal;
            if (array_key_exists('lines', $data)) {
                [$lines, $subtotal] = $this->normaliseLines($data['lines']);
                DB::table('expense_invoice_lines')->where('expense_invoice_id', $id)->delete();
                foreach ($lines as $l) {
                    DB::table('expense_invoice_lines')->insert($l + ['expense_invoice_id' => $id, 'created_at' => now()]);
                }
                $patch['subtotal'] = $subtotal;
            }
            $tax = array_key_exists('tax', $data) ? round((float) $data['tax'], 2) : (float) $bill->tax;
            $patch['tax'] = $tax;
            $total = round($subtotal + $tax, 2);
            $patch['total'] = $total;
            // Re-derive status against the (possibly changed) total.
            $patch['status'] = $this->paymentStatus($total, (float) $bill->amount_paid, $patch['due_date'] ?? $bill->due_date, (string) $bill->status);
            DB::table('expense_invoices')->where('id', $id)->update($patch);
        });

        return response()->json($this->hydrate($agencyId, $id));
    }

    /** POST /admin/expense-invoices/{bill}/approve */
    public function approve(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if (! in_array($bill->status, ['draft', 'awaiting_approval'], true)) {
            return response()->json(['message' => 'Only draft or awaiting-approval bills can be approved.'], 422);
        }
        $status = $this->paymentStatus((float) $bill->total, (float) $bill->amount_paid, $bill->due_date, 'approved');
        DB::table('expense_invoices')->where('id', $id)->update([
            'status'         => $status,
            'approved_by_id' => $request->user()->id,
            'approved_at'    => now(),
            'updated_at'     => now(),
        ]);
        return response()->json($this->hydrate($agencyId, $id));
    }

    /** POST /admin/expense-invoices/{bill}/payments — record a payment. */
    public function recordPayment(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($bill->status === 'void') {
            return response()->json(['message' => 'Cannot pay a void bill.'], 422);
        }

        $data = $request->validate([
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'method'    => ['nullable', 'in:cash,cheque,e_transfer,bank_transfer,credit_card,other'],
            'paid_at'   => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes'     => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($request, $agencyId, $id, $bill, $data) {
            DB::table('expense_payments')->insert([
                'expense_invoice_id' => $id,
                'agency_id'          => $agencyId,
                'amount'             => round((float) $data['amount'], 2),
                'method'             => $data['method'] ?? 'bank_transfer',
                'paid_at'            => $data['paid_at'] ?? now(),
                'reference'          => $data['reference'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'recorded_by_id'     => $request->user()->id,
                'created_at'         => now(),
            ]);
            $paid = round((float) $bill->amount_paid + (float) $data['amount'], 2);
            DB::table('expense_invoices')->where('id', $id)->update([
                'amount_paid' => $paid,
                'status'      => $this->paymentStatus((float) $bill->total, $paid, $bill->due_date, (string) $bill->status),
                'updated_at'  => now(),
            ]);
        });

        return response()->json($this->hydrate($agencyId, $id));
    }

    /** POST /admin/expense-invoices/{bill}/void */
    public function void(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('expense_invoices')->where('id', $id)->update(['status' => 'void', 'updated_at' => now()]);
        return response()->json($this->hydrate($agencyId, $id));
    }

    /** DELETE /admin/expense-invoices/{bill} — soft delete. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('expense_invoices')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** GET /admin/expenses/summary — headline expense figures for the agency. */
    public function summary(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $centreFilter = $request->filled('centre_id') ? (int) $request->input('centre_id') : null;

        $base = fn () => DB::table('expense_invoices')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->when($centreFilter, fn ($q) => $q->where('centre_id', $centreFilter));

        $outstanding = (float) (clone $base())->whereNotIn('status', ['paid', 'void'])->sum(DB::raw('total - amount_paid'));
        $overdue = (clone $base())->where('status', '!=', 'void')
            ->whereNotIn('status', ['paid'])
            ->whereNotNull('due_date')->whereDate('due_date', '<', now()->toDateString())
            ->selectRaw('COUNT(*) c, ROUND(SUM(total - amount_paid),2) amt')->first();

        $monthStart = now()->startOfMonth()->toDateString();
        $paidThisMonth = (float) DB::table('expense_payments')
            ->where('agency_id', $agencyId)
            ->when($centreFilter, function ($q) use ($centreFilter) {
                $q->whereIn('expense_invoice_id', DB::table('expense_invoices')->where('centre_id', $centreFilter)->pluck('id'));
            })
            ->whereDate('paid_at', '>=', $monthStart)->sum('amount');

        $byStatus = (clone $base())->groupBy('status')->selectRaw('status, COUNT(*) c, ROUND(SUM(total),2) total')->get();
        $byCategory = (clone $base())->where('status', '!=', 'void')
            ->groupBy('category')->selectRaw("COALESCE(category,'Uncategorised') category, ROUND(SUM(total),2) total")->orderByDesc('total')->limit(12)->get();

        return response()->json([
            'summary' => [
                'outstanding'       => round($outstanding, 2),
                'overdue_count'     => (int) ($overdue->c ?? 0),
                'overdue_amount'    => round((float) ($overdue->amt ?? 0), 2),
                'paid_this_month'   => round($paidThisMonth, 2),
                'supplier_count'    => DB::table('suppliers')->where('agency_id', $agencyId)->whereNull('deleted_at')->when($centreFilter, fn ($q) => $q->where('centre_id', $centreFilter))->count(),
                'open_po_count'     => DB::table('purchase_orders')->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('status', ['draft', 'ordered'])->when($centreFilter, fn ($q) => $q->where('centre_id', $centreFilter))->count(),
                'by_status'         => $byStatus,
                'by_category'       => $byCategory,
            ],
        ]);
    }

    /** Assemble a bill with lines, supplier, PO ref and payments, scoped to agency. */
    private function hydrate(int $agencyId, int $id): ?array
    {
        $bill = DB::table('expense_invoices')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $bill) {
            return null;
        }
        $bill->balance = round((float) $bill->total - (float) $bill->amount_paid, 2);
        $bill->supplier = DB::table('suppliers')->where('id', $bill->supplier_id)->first();
        $bill->lines = DB::table('expense_invoice_lines')->where('expense_invoice_id', $id)->get();
        $bill->payments = DB::table('expense_payments')->where('expense_invoice_id', $id)->orderByDesc('paid_at')->get();
        $bill->purchase_order = $bill->purchase_order_id
            ? DB::table('purchase_orders')->where('id', $bill->purchase_order_id)->value('po_number')
            : null;
        return ['expense_invoice' => $bill];
    }
}
