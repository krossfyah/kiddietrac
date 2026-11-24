<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC'S OWN SUPPLIERS — Sales → Expenses → Suppliers (2026-11-02).
 * Anthony: "add a expense in sales - can you make it where I can add a supplier and allow to choose this
 * supplier in the vendor drop down".
 *
 * Same door as the expenses (/sales group: sales_rep + platform_admin). Anyone who logs expenses may ADD a
 * supplier, because they are the ones standing at the till; only a superadmin may rename, change or
 * archive one, because a rename rewrites the vendor on everyone's expenses. Archiving hides it from the
 * dropdown; past expenses keep their vendor text. Adding a name that already exists (any case) returns
 * that supplier instead of a duplicate, and brings an archived one back.
 *
 * Not the agency Finance → Expenses suppliers (table `suppliers`) — those belong to an agency.
 */
class SalesSupplierController extends Controller
{
    public static function key(string $name): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name)));
    }

    private function isSuper(Request $r): bool
    {
        return DB::table('role_assignments')->where('user_id', (int) $r->user()->id)->where('role', 'platform_admin')->where('active', 1)->exists();
    }

    private function audit(Request $r, string $action, int $id, string $summary, array $more = []): void
    {
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => $action, 'entity_type' => 'sales_supplier', 'entity_id' => $id,
            'payload' => json_encode(array_merge(['summary' => $summary], $more), JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }

    private function shape($s, array $use = [], array $names = []): array
    {
        $u = $use[$s->id] ?? null;
        return [
            'id' => (int) $s->id, 'name' => $s->name, 'website' => $s->website, 'email' => $s->email, 'phone' => $s->phone, 'notes' => $s->notes,
            'archived' => (bool) $s->archived_at, 'archived_at' => $s->archived_at,
            'created_by' => $s->created_by ? ($names[$s->created_by] ?? null) : null, 'created_at' => $s->created_at,
            'expense_count' => $u ? (int) $u->n : 0, 'spent_cents' => $u ? (int) $u->cents : 0,
        ];
    }

    /** GET /sales/suppliers?archived=1 — A–Z. The usage counts only cover expenses this person can see. */
    public function index(Request $r): JsonResponse
    {
        $super = $this->isSuper($r);
        $q = DB::table('sales_suppliers')->orderBy('name');
        if (! $r->boolean('archived')) $q->whereNull('archived_at');
        $rows = $q->get();
        $use = DB::table('platform_expenses')->whereNull('deleted_at')->whereNotNull('supplier_id')->where('status', '!=', 'rejected')
            ->when(! $super, fn ($x) => $x->where('user_id', (int) $r->user()->id))
            ->where('currency', 'CAD')
            ->selectRaw('supplier_id, COUNT(*) n, SUM(amount_cents + tax_cents) cents')->groupBy('supplier_id')->get()->keyBy('supplier_id')->all();
        $uids = $rows->pluck('created_by')->filter()->unique();
        // First names only for anyone who is not a superadmin (staff names stay short and private).
        $names = $uids->count() ? DB::table('users')->whereIn('id', $uids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => $super ? trim($u->first_name . ' ' . $u->last_name) : $u->first_name])->all() : [];
        return response()->json(['is_super' => $super, 'suppliers' => $rows->map(fn ($s) => $this->shape($s, $use, $names))->values()]);
    }

    private function details(Request $r, bool $nameRequired): array
    {
        return $r->validate([
            'name' => ($nameRequired ? 'required' : 'sometimes') . '|string|max:160',
            'website' => 'nullable|string|max:255', 'email' => 'nullable|email|max:190',
            'phone' => 'nullable|string|max:40', 'notes' => 'nullable|string|max:2000',
        ], ['name.required' => 'Give the supplier a name.', 'email.email' => 'That email address does not look right.']);
    }

    private function clean(array $d): array
    {
        $out = [];
        foreach (['website', 'email', 'phone', 'notes'] as $k) if (array_key_exists($k, $d)) $out[$k] = trim((string) $d[$k]) !== '' ? trim((string) $d[$k]) : null;
        if (! empty($out['website']) && ! preg_match('~^https?://~i', $out['website'])) $out['website'] = 'https://' . $out['website'];
        return $out;
    }

    /** POST /sales/suppliers — anyone on the sales team. Same name (any case) = that supplier, not a copy. */
    public function store(Request $r): JsonResponse
    {
        $d = $this->details($r, true);
        $name = trim(preg_replace('/\s+/u', ' ', $d['name']));
        abort_if($name === '', 422, 'Give the supplier a name.');
        $k = self::key($name);
        $have = DB::table('sales_suppliers')->where('name_key', $k)->first();
        if ($have) {
            $upd = [];
            // Fill blanks only — a rep adding "Staples" again never overwrites what is on file.
            foreach ($this->clean($d) as $f => $v) if ($v !== null && empty($have->$f)) $upd[$f] = $v;
            if ($have->archived_at) { $upd['archived_at'] = null; $upd['archived_by'] = null; }
            if ($upd) DB::table('sales_suppliers')->where('id', $have->id)->update($upd + ['updated_at' => now()]);
            if ($have->archived_at) $this->audit($r, 'sales_supplier.restored', (int) $have->id, 'Brought back the supplier ' . $have->name . ' (it was added again)', ['name' => $have->name]);
            $s = DB::table('sales_suppliers')->find($have->id);
            return response()->json(['supplier' => $this->shape($s), 'existed' => true]);
        }
        $id = DB::table('sales_suppliers')->insertGetId(['name' => $name, 'name_key' => $k, 'created_by' => $r->user()->id,
            'created_at' => now(), 'updated_at' => now()] + $this->clean($d));
        $s = DB::table('sales_suppliers')->find($id);
        $this->audit($r, 'sales_supplier.added', $id, 'Added the supplier ' . $name . ' to the KiddieTrac expense suppliers',
            array_filter(['name' => $name, 'website' => $s->website, 'email' => $s->email, 'phone' => $s->phone]));
        return response()->json(['supplier' => $this->shape($s), 'existed' => false], 201);
    }

    /** PATCH /sales/suppliers/{id} — superadmin: details, rename (carried to linked expenses), restore. */
    public function update(Request $r, int $id): JsonResponse
    {
        abort_unless($this->isSuper($r), 403, 'Only a superadmin can change a supplier.');
        $s = DB::table('sales_suppliers')->find($id);
        abort_unless($s, 404, 'That supplier no longer exists.');
        $d = $this->details($r, false);
        $upd = $this->clean($d);
        $changes = [];
        foreach ($upd as $f => $v) if ((string) $s->$f !== (string) $v) $changes[] = $f;
        $renamed = false;
        if (array_key_exists('name', $d)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $d['name']));
            abort_if($name === '', 422, 'Give the supplier a name.');
            $k = self::key($name);
            abort_if(DB::table('sales_suppliers')->where('name_key', $k)->where('id', '!=', $id)->exists(), 422, 'There is already a supplier called ' . $name . '.');
            if ($name !== $s->name) { $upd['name'] = $name; $upd['name_key'] = $k; $renamed = true; }
        }
        $restore = $r->has('archived') && ! $r->boolean('archived') && $s->archived_at;
        if ($restore) { $upd['archived_at'] = null; $upd['archived_by'] = null; }
        DB::transaction(function () use ($id, $upd, $renamed) {
            if ($upd) DB::table('sales_suppliers')->where('id', $id)->update($upd + ['updated_at' => now()]);
            if ($renamed) {
                DB::table('platform_expenses')->where('supplier_id', $id)->update(['vendor' => $upd['name']]);
                DB::table('platform_expense_recurring')->where('supplier_id', $id)->update(['vendor' => $upd['name']]);
            }
        });
        if ($renamed) $this->audit($r, 'sales_supplier.changed', $id, 'Renamed the supplier ' . $s->name . ' to ' . $upd['name'] . ' (its expenses now show the new name)', ['from' => $s->name, 'to' => $upd['name']]);
        elseif ($changes) $this->audit($r, 'sales_supplier.changed', $id, 'Changed the supplier ' . $s->name . ' (' . implode(', ', $changes) . ')', ['name' => $s->name, 'fields' => $changes]);
        if ($restore) $this->audit($r, 'sales_supplier.restored', $id, 'Brought back the archived supplier ' . ($upd['name'] ?? $s->name), ['name' => $upd['name'] ?? $s->name]);
        return response()->json(['supplier' => $this->shape(DB::table('sales_suppliers')->find($id))]);
    }

    /** DELETE /sales/suppliers/{id} — superadmin: archive. Hidden from the dropdown; past expenses keep their vendor. */
    public function destroy(Request $r, int $id): JsonResponse
    {
        abort_unless($this->isSuper($r), 403, 'Only a superadmin can archive a supplier.');
        $s = DB::table('sales_suppliers')->find($id);
        abort_unless($s, 404, 'That supplier no longer exists.');
        if (! $s->archived_at) {
            DB::table('sales_suppliers')->where('id', $id)->update(['archived_at' => now(), 'archived_by' => $r->user()->id, 'updated_at' => now()]);
            $this->audit($r, 'sales_supplier.archived', $id, 'Archived the supplier ' . $s->name . ' (past expenses keep it)', ['name' => $s->name]);
        }
        return response()->json(['ok' => true]);
    }

    /**
     * For the expense forms: resolve what was picked into [supplier_id, vendor text].
     * A supplier id wins and its NAME becomes the vendor; plain text is linked when it matches a supplier.
     */
    public static function resolve($supplierId, ?string $vendor): array
    {
        if ($supplierId) {
            $s = DB::table('sales_suppliers')->find((int) $supplierId);
            if ($s) return [(int) $s->id, $s->name];
        }
        $vendor = $vendor !== null ? trim($vendor) : null;
        if ($vendor === null || $vendor === '') return [null, null];
        $s = DB::table('sales_suppliers')->where('name_key', self::key($vendor))->first();
        return $s ? [(int) $s->id, $s->name] : [null, $vendor];
    }
}
