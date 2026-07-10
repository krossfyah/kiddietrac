<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PurchaseOrderController extends Controller
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

    /** Recompute + normalise line rows; returns [lines[], subtotal]. */
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
            $lines[] = ['description' => mb_substr($desc, 0, 200), 'quantity' => $qty, 'unit_price' => $unit, 'amount' => $amount];
            $subtotal += $amount;
        }
        return [$lines, round($subtotal, 2)];
    }

    /** GET /admin/purchase-orders */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $q = DB::table('purchase_orders as po')
            ->leftJoin('suppliers as s', 's.id', '=', 'po.supplier_id')
            ->where('po.agency_id', $agencyId)
            ->whereNull('po.deleted_at')
            ->select('po.*', 's.name as supplier_name');

        if ($request->filled('status')) {
            $q->where('po.status', $request->input('status'));
        }
        if ($request->filled('supplier_id')) {
            $q->where('po.supplier_id', (int) $request->input('supplier_id'));
        }
        if ($request->filled('centre_id')) {
            $q->where('po.centre_id', (int) $request->input('centre_id'));
        }

        return response()->json(['purchase_orders' => $q->orderByDesc('po.id')->limit(500)->get()]);
    }

    /** POST /admin/purchase-orders */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $data = $request->validate([
            'supplier_id'   => ['required', 'integer'],
            'centre_id'     => ['nullable', 'integer'],
            'status'        => ['nullable', 'in:draft,ordered,received,cancelled'],
            'order_date'    => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'category'      => ['nullable', 'string', 'max:80'],
            'tax'           => ['nullable', 'numeric'],
            'notes'         => ['nullable', 'string', 'max:5000'],
            'lines'         => ['array'],
        ]);

        $supplierId = $this->ownedSupplierId((int) $data['supplier_id'], $agencyId);
        if (! $supplierId) {
            return response()->json(['message' => 'Supplier not found in this agency.'], 422);
        }

        [$lines, $subtotal] = $this->normaliseLines($data['lines'] ?? []);
        $tax = round((float) ($data['tax'] ?? 0), 2);

        $poId = DB::transaction(function () use ($request, $agencyId, $data, $supplierId, $lines, $subtotal, $tax) {
            $id = DB::table('purchase_orders')->insertGetId([
                'agency_id'     => $agencyId,
                'centre_id'     => $this->scopedCentreId($data['centre_id'] ?? null, $agencyId),
                'supplier_id'   => $supplierId,
                'po_number'     => 'PENDING',
                'status'        => $data['status'] ?? 'draft',
                'order_date'    => $data['order_date'] ?? null,
                'expected_date' => $data['expected_date'] ?? null,
                'category'      => $data['category'] ?? null,
                'subtotal'      => $subtotal,
                'tax'           => $tax,
                'total'         => round($subtotal + $tax, 2),
                'notes'         => $data['notes'] ?? null,
                'created_by_id' => $request->user()->id,
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            DB::table('purchase_orders')->where('id', $id)->update(['po_number' => 'PO-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            foreach ($lines as $l) {
                DB::table('purchase_order_lines')->insert($l + ['purchase_order_id' => $id, 'created_at' => now()]);
            }
            return $id;
        });

        return response()->json($this->hydrate($agencyId, $poId), 201);
    }

    /** GET /admin/purchase-orders/{po} */
    public function show(Request $request, int $poId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $payload = $this->hydrate($agencyId, $poId);
        if (! $payload) {
            return response()->json(['message' => 'Not found'], 404);
        }
        return response()->json($payload);
    }

    /** PATCH /admin/purchase-orders/{po} */
    public function update(Request $request, int $poId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $po = DB::table('purchase_orders')->where('id', $poId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $po) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'supplier_id'   => ['sometimes', 'integer'],
            'centre_id'     => ['nullable', 'integer'],
            'status'        => ['nullable', 'in:draft,ordered,received,cancelled'],
            'order_date'    => ['nullable', 'date'],
            'expected_date' => ['nullable', 'date'],
            'category'      => ['nullable', 'string', 'max:80'],
            'tax'           => ['nullable', 'numeric'],
            'notes'         => ['nullable', 'string', 'max:5000'],
            'lines'         => ['sometimes', 'array'],
        ]);

        $patch = ['updated_at' => now()];
        if (array_key_exists('supplier_id', $data)) {
            $sid = $this->ownedSupplierId((int) $data['supplier_id'], $agencyId);
            if (! $sid) {
                return response()->json(['message' => 'Supplier not found in this agency.'], 422);
            }
            $patch['supplier_id'] = $sid;
        }
        foreach (['status', 'order_date', 'expected_date', 'category', 'notes'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = $data[$f];
            }
        }
        if (array_key_exists('centre_id', $data)) {
            $patch['centre_id'] = $this->scopedCentreId($data['centre_id'], $agencyId);
        }

        DB::transaction(function () use ($poId, $data, &$patch) {
            if (array_key_exists('lines', $data)) {
                [$lines, $subtotal] = $this->normaliseLines($data['lines']);
                DB::table('purchase_order_lines')->where('purchase_order_id', $poId)->delete();
                foreach ($lines as $l) {
                    DB::table('purchase_order_lines')->insert($l + ['purchase_order_id' => $poId, 'created_at' => now()]);
                }
                $tax = array_key_exists('tax', $data) ? round((float) $data['tax'], 2)
                    : (float) DB::table('purchase_orders')->where('id', $poId)->value('tax');
                $patch['subtotal'] = $subtotal;
                $patch['tax'] = $tax;
                $patch['total'] = round($subtotal + $tax, 2);
            } elseif (array_key_exists('tax', $data)) {
                $sub = (float) DB::table('purchase_orders')->where('id', $poId)->value('subtotal');
                $patch['tax'] = round((float) $data['tax'], 2);
                $patch['total'] = round($sub + $patch['tax'], 2);
            }
            DB::table('purchase_orders')->where('id', $poId)->update($patch);
        });

        return response()->json($this->hydrate($agencyId, $poId));
    }

    /** POST /admin/purchase-orders/{po}/status  body: {status} */
    public function updateStatus(Request $request, int $poId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $po = DB::table('purchase_orders')->where('id', $poId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $po) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $data = $request->validate(['status' => ['required', 'in:draft,ordered,received,cancelled']]);
        DB::table('purchase_orders')->where('id', $poId)->update(['status' => $data['status'], 'updated_at' => now()]);
        return response()->json($this->hydrate($agencyId, $poId));
    }

    /** POST /admin/purchase-orders/{po}/convert-to-bill — spawn an expense invoice from the PO. */
    public function convertToBill(Request $request, int $poId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $po = DB::table('purchase_orders')->where('id', $poId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $po) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $existing = DB::table('expense_invoices')->where('purchase_order_id', $poId)->whereNull('deleted_at')->first();
        if ($existing) {
            return response()->json(['message' => 'A bill already exists for this PO.', 'expense_invoice_id' => $existing->id], 409);
        }

        $lines = DB::table('purchase_order_lines')->where('purchase_order_id', $poId)->get();

        $billId = DB::transaction(function () use ($request, $agencyId, $po, $lines) {
            $id = DB::table('expense_invoices')->insertGetId([
                'agency_id'         => $agencyId,
                'centre_id'         => $po->centre_id,
                'supplier_id'       => $po->supplier_id,
                'purchase_order_id' => $po->id,
                'reference'         => 'PENDING',
                'status'            => 'awaiting_approval',
                'category'          => $po->category,
                'issue_date'        => now()->toDateString(),
                'subtotal'          => $po->subtotal,
                'tax'               => $po->tax,
                'total'             => $po->total,
                'amount_paid'       => 0,
                'notes'             => 'Created from ' . $po->po_number,
                'created_by_id'     => $request->user()->id,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
            DB::table('expense_invoices')->where('id', $id)->update(['reference' => 'EXP-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT)]);
            foreach ($lines as $l) {
                DB::table('expense_invoice_lines')->insert([
                    'expense_invoice_id' => $id,
                    'description'        => $l->description,
                    'quantity'           => $l->quantity,
                    'unit_price'         => $l->unit_price,
                    'amount'             => $l->amount,
                    'created_at'         => now(),
                ]);
            }
            DB::table('purchase_orders')->where('id', $po->id)->update(['status' => 'received', 'updated_at' => now()]);
            return $id;
        });

        return response()->json(['expense_invoice_id' => $billId, 'reference' => DB::table('expense_invoices')->where('id', $billId)->value('reference')], 201);
    }

    /** DELETE /admin/purchase-orders/{po} — soft delete. */
    public function destroy(Request $request, int $poId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $po = DB::table('purchase_orders')->where('id', $poId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $po) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('purchase_orders')->where('id', $poId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** Assemble a PO with its lines + supplier, scoped to the agency. */
    private function hydrate(int $agencyId, int $poId): ?array
    {
        $po = DB::table('purchase_orders')->where('id', $poId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $po) {
            return null;
        }
        $po->supplier = DB::table('suppliers')->where('id', $po->supplier_id)->first();
        $po->lines = DB::table('purchase_order_lines')->where('purchase_order_id', $poId)->get();
        return ['purchase_order' => $po];
    }
}
