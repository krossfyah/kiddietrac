<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class SupplierController extends Controller
{
    use ResolvesCentreContext;

    /** Resolve + guard the active agency, or abort 403. */
    private function agencyOrFail(Request $request): int
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) {
            abort(403, 'No agency context.');
        }
        return (int) $agencyId;
    }

    /** A centre_id is only accepted if it belongs to the active agency; else null (agency-wide). */
    private function scopedCentreId(?int $centreId, int $agencyId): ?int
    {
        if (! $centreId) {
            return null;
        }
        $ok = DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->whereNull('deleted_at')->exists();
        return $ok ? $centreId : null;
    }

    /** GET /admin/suppliers */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $q = DB::table('suppliers')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at');

        if ($request->filled('centre_id')) {
            $q->where('centre_id', (int) $request->input('centre_id'));
        }
        if ($request->filled('active')) {
            $q->where('is_active', filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('search')) {
            $s = trim((string) $request->input('search'));
            $q->where(function ($w) use ($s) {
                $w->where('name', 'like', "%{$s}%")
                    ->orWhere('contact_name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%");
            });
        }

        $suppliers = $q->orderBy('name')->get();

        // Attach outstanding balance (unpaid bill total) per supplier.
        $balances = DB::table('expense_invoices')
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->whereNotIn('status', ['void', 'paid'])
            ->groupBy('supplier_id')
            ->selectRaw('supplier_id, ROUND(SUM(total - amount_paid), 2) AS outstanding, COUNT(*) AS open_bills')
            ->get()->keyBy('supplier_id');

        $suppliers = $suppliers->map(function ($s) use ($balances) {
            $b = $balances[$s->id] ?? null;
            $s->outstanding = (float) ($b->outstanding ?? 0);
            $s->open_bills = (int) ($b->open_bills ?? 0);
            return $s;
        });

        return response()->json(['suppliers' => $suppliers]);
    }

    /** POST /admin/suppliers */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);

        $data = $request->validate([
            'name'             => ['required', 'string', 'max:200'],
            'centre_id'        => ['nullable', 'integer'],
            'contact_name'     => ['nullable', 'string', 'max:150'],
            'email'            => ['nullable', 'email', 'max:190'],
            'phone'            => ['nullable', 'string', 'max:60'],
            'address'          => ['nullable', 'string', 'max:2000'],
            'tax_number'       => ['nullable', 'string', 'max:60'],
            'default_category' => ['nullable', 'string', 'max:80'],
            'notes'            => ['nullable', 'string', 'max:5000'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        $id = DB::table('suppliers')->insertGetId([
            'agency_id'        => $agencyId,
            'centre_id'        => $this->scopedCentreId($data['centre_id'] ?? null, $agencyId),
            'name'             => $data['name'],
            'contact_name'     => $data['contact_name'] ?? null,
            'email'            => $data['email'] ?? null,
            'phone'            => $data['phone'] ?? null,
            'address'          => $data['address'] ?? null,
            'tax_number'       => $data['tax_number'] ?? null,
            'default_category' => $data['default_category'] ?? null,
            'notes'            => $data['notes'] ?? null,
            'is_active'        => $data['is_active'] ?? true,
            'created_by_id'    => $request->user()->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        return response()->json(['supplier' => DB::table('suppliers')->where('id', $id)->first()], 201);
    }

    /** GET /admin/suppliers/{supplier} */
    public function show(Request $request, int $supplierId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $supplier = DB::table('suppliers')->where('id', $supplierId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $supplier) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $bills = DB::table('expense_invoices')
            ->where('supplier_id', $supplierId)->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderByDesc('issue_date')->orderByDesc('id')->limit(50)->get();
        return response()->json(['supplier' => $supplier, 'bills' => $bills]);
    }

    /** PATCH /admin/suppliers/{supplier} */
    public function update(Request $request, int $supplierId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $supplier = DB::table('suppliers')->where('id', $supplierId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $supplier) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validate([
            'name'             => ['sometimes', 'required', 'string', 'max:200'],
            'centre_id'        => ['nullable', 'integer'],
            'contact_name'     => ['nullable', 'string', 'max:150'],
            'email'            => ['nullable', 'email', 'max:190'],
            'phone'            => ['nullable', 'string', 'max:60'],
            'address'          => ['nullable', 'string', 'max:2000'],
            'tax_number'       => ['nullable', 'string', 'max:60'],
            'default_category' => ['nullable', 'string', 'max:80'],
            'notes'            => ['nullable', 'string', 'max:5000'],
            'is_active'        => ['nullable', 'boolean'],
        ]);

        $patch = [];
        foreach (['name', 'contact_name', 'email', 'phone', 'address', 'tax_number', 'default_category', 'notes', 'is_active'] as $f) {
            if (array_key_exists($f, $data)) {
                $patch[$f] = $data[$f];
            }
        }
        if (array_key_exists('centre_id', $data)) {
            $patch['centre_id'] = $this->scopedCentreId($data['centre_id'], $agencyId);
        }
        $patch['updated_at'] = now();

        DB::table('suppliers')->where('id', $supplierId)->update($patch);
        return response()->json(['supplier' => DB::table('suppliers')->where('id', $supplierId)->first()]);
    }

    /** DELETE /admin/suppliers/{supplier} — soft delete. */
    public function destroy(Request $request, int $supplierId): JsonResponse
    {
        $agencyId = $this->agencyOrFail($request);
        $supplier = DB::table('suppliers')->where('id', $supplierId)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $supplier) {
            return response()->json(['message' => 'Not found'], 404);
        }
        DB::table('suppliers')->where('id', $supplierId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true]);
    }
}
