<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * KIDDIETRAC'S OWN EXPENSES — Sales → Expenses (2026-10-24).
 * Anthony: "under sales section add expenses to manage all expenses with kiddietrac for travel,
 * 3rd party services, monthly generic expenses, food etc - full system with graphs, stats".
 * His choices: superadmins see everything and approve; sales reps log their own and see only
 * theirs. Submitted → approved / rejected → reimbursed (personally paid items). Recurring costs
 * are entered once and added on their date by kt:expenses-recurring.
 * Receipts live on the PRIVATE disk and are only ever served through this controller.
 */
class PlatformExpenseController extends Controller
{
    public const CATEGORIES = [
        'travel' => ['Travel & mileage', '✈️'], 'meals' => ['Meals & food', '🍽️'], 'third_party' => ['Third-party services', '🤝'],
        'software' => ['Software & subscriptions', '💻'], 'phone_internet' => ['Phone & internet', '📶'], 'marketing' => ['Marketing & events', '📣'],
        'office' => ['Office & supplies', '🖇️'], 'rent_utilities' => ['Rent & utilities', '🏢'], 'professional' => ['Professional fees', '⚖️'],
        'hardware' => ['Hardware & equipment', '🖥️'], 'training' => ['Training', '🎓'], 'other' => ['Other', '🧾'],
    ];
    public const METHODS = ['company_card' => 'Company card', 'personal' => 'Paid personally', 'bank_transfer' => 'Bank transfer', 'other' => 'Other'];
    private const DISK = 'local';
    private const DIR = 'platform-expenses';

    private function isSuper(Request $r): bool
    {
        return DB::table('role_assignments')->where('user_id', (int) $r->user()->id)->where('role', 'platform_admin')->where('active', 1)->exists();
    }
    private function assertSuper(Request $r): void
    {
        abort_unless($this->isSuper($r), 403, 'Only a superadmin can do that.');
    }
    private function audit(Request $r, string $action, ?int $id, string $summary): void
    {
        \App\Support\Audit::write(['user_id' => $r->user()->id, 'action' => $action, 'entity_type' => 'platform_expense', 'entity_id' => $id,
            'payload' => json_encode(['summary' => $summary], JSON_UNESCAPED_UNICODE), 'created_at' => now()]);
    }
    private function money(int $cents, string $cur): string
    {
        return ($cur === 'USD' ? 'US$' : '$') . number_format($cents / 100, 2);
    }
    private function find(Request $r, int $id)
    {
        $e = DB::table('platform_expenses')->where('id', $id)->whereNull('deleted_at')->first();
        abort_unless($e, 404);
        abort_unless($this->isSuper($r) || (int) $e->user_id === (int) $r->user()->id, 403);
        return $e;
    }
    private function shape($e, array $names, bool $super): array
    {
        return [
            'id' => (int) $e->id, 'user_id' => (int) $e->user_id, 'user' => $names[$e->user_id] ?? null,
            'category' => $e->category, 'vendor' => $e->vendor, 'supplier_id' => isset($e->supplier_id) && $e->supplier_id ? (int) $e->supplier_id : null,
            'description' => $e->description, 'expense_date' => $e->expense_date,
            'amount' => round($e->amount_cents / 100, 2), 'tax' => round($e->tax_cents / 100, 2), 'total' => round(($e->amount_cents + $e->tax_cents) / 100, 2),
            'currency' => $e->currency, 'payment_method' => $e->payment_method, 'reimbursable' => (bool) $e->reimbursable, 'status' => $e->status,
            'lead_id' => $e->lead_id ? (int) $e->lead_id : null, 'recurring' => (bool) $e->recurring_id,
            'has_receipt' => (bool) $e->receipt_path, 'receipt_name' => $e->receipt_name, 'notes' => $e->notes,
            'approved_at' => $e->approved_at, 'approved_by' => $e->approved_by ? ($names[$e->approved_by] ?? null) : null,
            'reject_reason' => $e->reject_reason, 'reimbursed_at' => $e->reimbursed_at, 'created_at' => $e->created_at,
            'can_edit' => $super || ($e->status === 'submitted'),
        ];
    }

    /** GET /sales/expenses?from=&to= — the list, plus stats and chart series for the screen. */
    public function index(Request $r): JsonResponse
    {
        $super = $this->isSuper($r);
        $me = (int) $r->user()->id;
        $from = $r->query('from') ?: now()->startOfYear()->toDateString();
        $to = $r->query('to') ?: now()->toDateString();
        $base = DB::table('platform_expenses')->whereNull('deleted_at');
        if (! $super) $base->where('user_id', $me);

        $rows = (clone $base)->whereBetween('expense_date', [$from, $to])->orderByDesc('expense_date')->orderByDesc('id')->limit(3000)->get();
        $uids = $rows->pluck('user_id')->merge($rows->pluck('approved_by'))->filter()->unique();
        $names = $uids->count() ? DB::table('users')->whereIn('id', $uids)->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim($u->first_name . ' ' . $u->last_name)])->all() : [];
        $counted = $rows->where('status', '!=', 'rejected');
        $sum = fn ($set, $cur = 'CAD') => (int) $set->where('currency', $cur)->sum(fn ($e) => $e->amount_cents + $e->tax_cents);

        // Month by month for the last 12 months, regardless of the chosen range (the trend chart).
        $months = [];
        for ($i = 11; $i >= 0; $i--) $months[now()->startOfMonth()->subMonths($i)->format('Y-m')] = 0;
        $trend = (clone $base)->where('status', '!=', 'rejected')->where('expense_date', '>=', now()->startOfMonth()->subMonths(11)->toDateString())
            ->get(['expense_date', 'amount_cents', 'tax_cents', 'currency']);
        foreach ($trend as $e) { $m = substr((string) $e->expense_date, 0, 7); if (isset($months[$m]) && $e->currency === 'CAD') $months[$m] += $e->amount_cents + $e->tax_cents; }

        $byCat = [];
        foreach ($counted as $e) { if ($e->currency !== 'CAD') continue; $byCat[$e->category] = ($byCat[$e->category] ?? 0) + $e->amount_cents + $e->tax_cents; }
        arsort($byCat);
        $vendors = [];
        foreach ($counted as $e) { if (! $e->vendor || $e->currency !== 'CAD') continue; $k = mb_strtolower(trim($e->vendor)); $vendors[$k] = ['vendor' => trim($e->vendor), 'cents' => ($vendors[$k]['cents'] ?? 0) + $e->amount_cents + $e->tax_cents]; }
        usort($vendors, fn ($a, $b) => $b['cents'] <=> $a['cents']);

        $thisMonth = (clone $base)->where('status', '!=', 'rejected')->where('expense_date', '>=', now()->startOfMonth()->toDateString())->get();
        $lastMonth = (clone $base)->where('status', '!=', 'rejected')->whereBetween('expense_date', [now()->subMonthNoOverflow()->startOfMonth()->toDateString(), now()->subMonthNoOverflow()->endOfMonth()->toDateString()])->get();
        $pending = (clone $base)->where('status', 'submitted')->get();
        $owed = (clone $base)->where('status', 'approved')->where('reimbursable', 1)->get();
        $recurring = $super ? DB::table('platform_expense_recurring')->orderByDesc('active')->orderBy('next_date')->get() : collect();
        $monthlyRecurring = 0;
        foreach ($recurring->where('active', 1) as $rc) {
            $c = $rc->amount_cents + $rc->tax_cents;
            $monthlyRecurring += (int) round($rc->interval === 'annual' ? $c / 12 : ($rc->interval === 'quarterly' ? $c / 3 : $c));
        }
        $span = max(1, Carbon::parse($from)->startOfMonth()->diffInMonths(Carbon::parse($to)->startOfMonth()) + 1);

        return response()->json([
            'is_super' => $super, 'from' => $from, 'to' => $to,
            'categories' => collect(self::CATEGORIES)->map(fn ($v, $k) => ['key' => $k, 'label' => $v[0], 'icon' => $v[1]])->values(),
            'methods' => self::METHODS,
            'expenses' => $rows->map(fn ($e) => $this->shape($e, $names, $super))->values(),
            'stats' => [
                'total_cents' => $sum($counted), 'total_usd_cents' => $sum($counted, 'USD'), 'count' => $counted->count(),
                'this_month_cents' => $sum($thisMonth), 'last_month_cents' => $sum($lastMonth),
                'pending_count' => $pending->count(), 'pending_cents' => $sum($pending) + $sum($pending, 'USD'),
                'owed_count' => $owed->count(), 'owed_cents' => $sum($owed) + $sum($owed, 'USD'),
                'monthly_recurring_cents' => $monthlyRecurring, 'avg_month_cents' => (int) round($sum($counted) / $span),
                'tax_cents' => (int) $counted->where('currency', 'CAD')->sum('tax_cents'),
            ],
            'by_month' => collect($months)->map(fn ($c, $m) => ['month' => $m, 'cents' => $c])->values(),
            'by_category' => collect($byCat)->map(fn ($c, $k) => ['key' => $k, 'label' => self::CATEGORIES[$k][0] ?? $k, 'cents' => $c])->values(),
            'top_vendors' => array_slice($vendors, 0, 8),
            // The Vendor dropdown (2026-11-02): KiddieTrac's own suppliers, A-Z (SalesSupplierController).
            'suppliers' => DB::table('sales_suppliers')->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])->values(),
            'recurring' => $recurring->map(fn ($rc) => ['id' => (int) $rc->id, 'category' => $rc->category, 'vendor' => $rc->vendor,
                'supplier_id' => isset($rc->supplier_id) && $rc->supplier_id ? (int) $rc->supplier_id : null, 'description' => $rc->description,
                'amount' => round($rc->amount_cents / 100, 2), 'tax' => round($rc->tax_cents / 100, 2), 'currency' => $rc->currency, 'payment_method' => $rc->payment_method,
                'interval' => $rc->interval, 'next_date' => $rc->next_date, 'active' => (bool) $rc->active])->values(),
        ]);
    }

    private function validated(Request $r): array
    {
        return $r->validate([
            'category' => 'required|string|in:' . implode(',', array_keys(self::CATEGORIES)),
            'vendor' => 'nullable|string|max:160', 'supplier_id' => 'nullable|integer', 'description' => 'nullable|string|max:500',
            'expense_date' => 'required|date', 'amount' => 'required|numeric|min:0|max:1000000', 'tax' => 'nullable|numeric|min:0|max:1000000',
            'currency' => 'nullable|in:CAD,USD', 'payment_method' => 'nullable|in:' . implode(',', array_keys(self::METHODS)),
            'lead_id' => 'nullable|integer', 'notes' => 'nullable|string|max:2000',
            'receipt' => 'nullable|file|max:15360|mimes:pdf,jpg,jpeg,png,heic,webp,gif',
        ]);
    }
    private function storeReceipt(Request $r): array
    {
        if (! $r->hasFile('receipt')) return [null, null];
        $f = $r->file('receipt');
        $ext = strtolower($f->getClientOriginalExtension() ?: 'pdf');
        $path = $f->storeAs(self::DIR, Str::uuid() . '.' . $ext, self::DISK);
        return [$path, mb_substr($f->getClientOriginalName() ?: ('receipt.' . $ext), 0, 200)];
    }

    /** POST /sales/expenses — a rep's entry is submitted for approval; a superadmin's can be logged as approved. */
    public function store(Request $r): JsonResponse
    {
        $d = $this->validated($r);
        $super = $this->isSuper($r);
        [$d['supplier_id'], $d['vendor']] = SalesSupplierController::resolve($d['supplier_id'] ?? null, $d['vendor'] ?? null);
        [$path, $name] = $this->storeReceipt($r);
        $method = $d['payment_method'] ?? 'company_card';
        $status = $super && $r->boolean('approved', true) ? 'approved' : 'submitted';
        $id = DB::table('platform_expenses')->insertGetId([
            'user_id' => $r->user()->id, 'category' => $d['category'], 'vendor' => $d['vendor'] ?? null, 'supplier_id' => $d['supplier_id'], 'description' => $d['description'] ?? null,
            'expense_date' => $d['expense_date'], 'amount_cents' => (int) round($d['amount'] * 100), 'tax_cents' => (int) round(($d['tax'] ?? 0) * 100),
            'currency' => $d['currency'] ?? 'CAD', 'payment_method' => $method, 'reimbursable' => $method === 'personal',
            'status' => $status, 'lead_id' => $d['lead_id'] ?? null, 'notes' => $d['notes'] ?? null,
            'receipt_path' => $path, 'receipt_name' => $name,
            'approved_by' => $status === 'approved' ? $r->user()->id : null, 'approved_at' => $status === 'approved' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $label = (self::CATEGORIES[$d['category']][0] ?? $d['category']) . (! empty($d['vendor']) ? ' · ' . $d['vendor'] : '');
        $amt = $this->money((int) round(($d['amount'] + ($d['tax'] ?? 0)) * 100), $d['currency'] ?? 'CAD');
        $this->audit($r, 'platform_expense.added', $id, 'Added an expense: ' . $label . ' ' . $amt . ($status === 'submitted' ? ' (submitted for approval)' : ''));
        if ($status === 'submitted') {
            $who = trim(($r->user()->first_name ?? '') . ' ' . ($r->user()->last_name ?? ''));
            $admins = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->unique();
            Notify::write($admins->map(fn ($u) => ['user_id' => (int) $u, 'type' => 'platform_expense', 'title' => '🧾 Expense to approve: ' . $amt,
                'body' => ($who ?: 'A sales rep') . ' · ' . $label, 'data' => json_encode(['link' => '#sales-expenses']), 'created_at' => now()])->values()->all());
        }
        return response()->json(['id' => $id, 'status' => $status], 201);
    }

    /** POST /sales/expenses/{id} — edit (multipart, so a receipt can be replaced). Reps only while submitted. */
    public function update(Request $r, int $id): JsonResponse
    {
        $e = $this->find($r, $id);
        $super = $this->isSuper($r);
        abort_unless($super || $e->status === 'submitted', 422, 'This expense has been reviewed and can no longer be changed.');
        $d = $this->validated($r);
        [$d['supplier_id'], $d['vendor']] = SalesSupplierController::resolve($d['supplier_id'] ?? null, $d['vendor'] ?? null);
        [$path, $name] = $this->storeReceipt($r);
        $method = $d['payment_method'] ?? $e->payment_method;
        $upd = ['category' => $d['category'], 'vendor' => $d['vendor'] ?? null, 'supplier_id' => $d['supplier_id'], 'description' => $d['description'] ?? null,
            'expense_date' => $d['expense_date'], 'amount_cents' => (int) round($d['amount'] * 100), 'tax_cents' => (int) round(($d['tax'] ?? 0) * 100),
            'currency' => $d['currency'] ?? $e->currency, 'payment_method' => $method, 'reimbursable' => $method === 'personal',
            'lead_id' => $d['lead_id'] ?? null, 'notes' => $d['notes'] ?? null, 'updated_at' => now()];
        if ($path) {
            if ($e->receipt_path) Storage::disk(self::DISK)->delete($e->receipt_path);
            $upd['receipt_path'] = $path; $upd['receipt_name'] = $name;
        }
        DB::table('platform_expenses')->where('id', $id)->update($upd);
        $this->audit($r, 'platform_expense.changed', $id, 'Changed an expense (' . (self::CATEGORIES[$d['category']][0] ?? $d['category']) . ', ' . $this->money($upd['amount_cents'] + $upd['tax_cents'], $upd['currency']) . ')');
        return response()->json(['ok' => true]);
    }

    public function destroy(Request $r, int $id): JsonResponse
    {
        $e = $this->find($r, $id);
        abort_unless($this->isSuper($r) || $e->status === 'submitted', 422, 'This expense has been reviewed and can no longer be deleted.');
        DB::table('platform_expenses')->where('id', $id)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'platform_expense.deleted', $id, 'Deleted an expense of ' . $this->money($e->amount_cents + $e->tax_cents, $e->currency));
        return response()->json(['ok' => true]);
    }

    private function tell($e, string $title, string $body): void
    {
        Notify::write([['user_id' => (int) $e->user_id, 'type' => 'platform_expense', 'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => '#sales-expenses']), 'created_at' => now()]]);
    }
    public function approve(Request $r, int $id): JsonResponse
    {
        $this->assertSuper($r);
        $e = $this->find($r, $id);
        DB::table('platform_expenses')->where('id', $id)->update(['status' => 'approved', 'approved_by' => $r->user()->id, 'approved_at' => now(), 'reject_reason' => null, 'updated_at' => now()]);
        $amt = $this->money($e->amount_cents + $e->tax_cents, $e->currency);
        $this->audit($r, 'platform_expense.approved', $id, 'Approved an expense of ' . $amt);
        if ((int) $e->user_id !== (int) $r->user()->id) $this->tell($e, '✅ Expense approved: ' . $amt, $e->reimbursable ? 'It will be reimbursed to you.' : ($e->vendor ?: ''));
        return response()->json(['ok' => true]);
    }
    public function reject(Request $r, int $id): JsonResponse
    {
        $this->assertSuper($r);
        $e = $this->find($r, $id);
        $reason = trim((string) $r->input('reason', ''));
        DB::table('platform_expenses')->where('id', $id)->update(['status' => 'rejected', 'reject_reason' => $reason ?: null, 'approved_by' => $r->user()->id, 'approved_at' => now(), 'updated_at' => now()]);
        $amt = $this->money($e->amount_cents + $e->tax_cents, $e->currency);
        $this->audit($r, 'platform_expense.rejected', $id, 'Rejected an expense of ' . $amt . ($reason ? ': ' . $reason : ''));
        if ((int) $e->user_id !== (int) $r->user()->id) $this->tell($e, '❌ Expense not approved: ' . $amt, $reason ?: 'Ask a superadmin for details.');
        return response()->json(['ok' => true]);
    }
    public function reimburse(Request $r, int $id): JsonResponse
    {
        $this->assertSuper($r);
        $e = $this->find($r, $id);
        abort_unless(in_array($e->status, ['approved', 'submitted'], true), 422, 'Only an approved expense can be marked reimbursed.');
        DB::table('platform_expenses')->where('id', $id)->update(['status' => 'reimbursed', 'reimbursed_by' => $r->user()->id, 'reimbursed_at' => now(),
            'approved_by' => $e->approved_by ?: $r->user()->id, 'approved_at' => $e->approved_at ?: now(), 'updated_at' => now()]);
        $amt = $this->money($e->amount_cents + $e->tax_cents, $e->currency);
        $this->audit($r, 'platform_expense.reimbursed', $id, 'Marked an expense of ' . $amt . ' reimbursed');
        if ((int) $e->user_id !== (int) $r->user()->id) $this->tell($e, '💸 Reimbursed: ' . $amt, $e->vendor ?: '');
        return response()->json(['ok' => true]);
    }

    /** GET /sales/expenses/{id}/receipt — the submitter or a superadmin. */
    public function receipt(Request $r, int $id)
    {
        $e = $this->find($r, $id);
        abort_unless($e->receipt_path && Storage::disk(self::DISK)->exists($e->receipt_path), 404);
        return response()->download(Storage::disk(self::DISK)->path($e->receipt_path), $e->receipt_name ?: basename($e->receipt_path));
    }

    // ── recurring (superadmin) ──
    public function recurringStore(Request $r): JsonResponse
    {
        $this->assertSuper($r);
        $d = $r->validate(['category' => 'required|in:' . implode(',', array_keys(self::CATEGORIES)), 'vendor' => 'nullable|string|max:160', 'supplier_id' => 'nullable|integer',
            'description' => 'nullable|string|max:500', 'amount' => 'required|numeric|min:0|max:1000000', 'tax' => 'nullable|numeric|min:0|max:1000000',
            'currency' => 'nullable|in:CAD,USD', 'payment_method' => 'nullable|in:' . implode(',', array_keys(self::METHODS)),
            'interval' => 'required|in:monthly,quarterly,annual', 'next_date' => 'required|date']);
        [$d['supplier_id'], $d['vendor']] = SalesSupplierController::resolve($d['supplier_id'] ?? null, $d['vendor'] ?? null);
        $id = DB::table('platform_expense_recurring')->insertGetId(['category' => $d['category'], 'vendor' => $d['vendor'] ?? null, 'supplier_id' => $d['supplier_id'], 'description' => $d['description'] ?? null,
            'amount_cents' => (int) round($d['amount'] * 100), 'tax_cents' => (int) round(($d['tax'] ?? 0) * 100), 'currency' => $d['currency'] ?? 'CAD',
            'payment_method' => $d['payment_method'] ?? 'company_card', 'interval' => $d['interval'], 'next_date' => $d['next_date'], 'active' => 1,
            'created_by' => $r->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($r, 'platform_expense.recurring_added', $id, 'Added a recurring expense: ' . ($d['vendor'] ?? $d['category']) . ' ' . $d['interval']);
        return response()->json(['id' => $id], 201);
    }
    public function recurringUpdate(Request $r, int $id): JsonResponse
    {
        $this->assertSuper($r);
        $rc = DB::table('platform_expense_recurring')->find($id);
        abort_unless($rc, 404);
        $d = $r->validate(['active' => 'nullable|boolean', 'amount' => 'nullable|numeric|min:0|max:1000000', 'next_date' => 'nullable|date',
            'vendor' => 'nullable|string|max:160', 'supplier_id' => 'nullable|integer', 'interval' => 'nullable|in:monthly,quarterly,annual']);
        $upd = ['updated_at' => now()];
        if (array_key_exists('active', $d) && $d['active'] !== null) $upd['active'] = (bool) $d['active'];
        if (isset($d['amount'])) $upd['amount_cents'] = (int) round($d['amount'] * 100);
        if (isset($d['next_date'])) $upd['next_date'] = $d['next_date'];
        if (isset($d['vendor']) || ! empty($d['supplier_id'])) [$upd['supplier_id'], $upd['vendor']] = SalesSupplierController::resolve($d['supplier_id'] ?? null, $d['vendor'] ?? null);
        if (isset($d['interval'])) $upd['interval'] = $d['interval'];
        DB::table('platform_expense_recurring')->where('id', $id)->update($upd);
        $this->audit($r, 'platform_expense.recurring_changed', $id, 'Changed the recurring expense ' . ($rc->vendor ?: $rc->category));
        return response()->json(['ok' => true]);
    }
    public function recurringDestroy(Request $r, int $id): JsonResponse
    {
        $this->assertSuper($r);
        $rc = DB::table('platform_expense_recurring')->find($id);
        abort_unless($rc, 404);
        DB::table('platform_expense_recurring')->where('id', $id)->delete();
        $this->audit($r, 'platform_expense.recurring_removed', $id, 'Removed the recurring expense ' . ($rc->vendor ?: $rc->category));
        return response()->json(['ok' => true]);
    }

    /** Daily (kt:expenses-recurring): add each recurring cost on its date, then move the date on. */
    public static function runRecurring(bool $dry = false): array
    {
        $today = now()->toDateString();
        $made = [];
        foreach (DB::table('platform_expense_recurring')->where('active', 1)->where('next_date', '<=', $today)->get() as $rc) {
            $next = $rc->next_date;
            $guard = 0;
            while ($next <= $today && $guard++ < 24) {
                $made[] = ['recurring_id' => $rc->id, 'date' => $next, 'vendor' => $rc->vendor, 'cents' => $rc->amount_cents + $rc->tax_cents];
                if (! $dry) {
                    DB::table('platform_expenses')->insert(['user_id' => $rc->created_by, 'category' => $rc->category, 'vendor' => $rc->vendor, 'supplier_id' => $rc->supplier_id ?? null,
                        'description' => $rc->description, 'expense_date' => $next, 'amount_cents' => $rc->amount_cents, 'tax_cents' => $rc->tax_cents,
                        'currency' => $rc->currency, 'payment_method' => $rc->payment_method, 'reimbursable' => 0, 'status' => 'approved',
                        'recurring_id' => $rc->id, 'approved_by' => $rc->created_by, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                }
                $c = Carbon::parse($next);
                $next = ($rc->interval === 'annual' ? $c->addYearNoOverflow() : ($rc->interval === 'quarterly' ? $c->addMonthsNoOverflow(3) : $c->addMonthNoOverflow()))->toDateString();
            }
            if (! $dry) DB::table('platform_expense_recurring')->where('id', $rc->id)->update(['next_date' => $next, 'updated_at' => now()]);
        }
        return $made;
    }
}
