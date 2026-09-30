<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Grant tracking & reconciliation (2026-09-29).
 *
 * After comparing KiddieTrac with ChildCarePro ("grant & subsidy reconciliation"), Anthony
 * asked for grant tracking: CWELCC fee reduction was covered, but not the operating,
 * wage-enhancement and one-time funding centres must spend within a period and report on.
 *
 * Reconciliation per grant: awarded vs received vs spent; unspent = received - spent -
 * returned (+/- adjustments); outstanding = awarded - received; pacing compares the share
 * of the period elapsed with the share of the award spent, so under- and over-spending
 * show up before the report is due. Directors see their own centres' grants and
 * agency-wide ones; agency admins manage all.
 */
class GrantController extends Controller
{
    use ResolvesCentreContext;

    public const TYPES = [
        'operating' => 'General operating',
        'wage_enhancement' => 'Wage enhancement',
        'cwelcc_cost_based' => 'CWELCC cost-based funding',
        'capital' => 'Capital / repairs',
        'one_time' => 'One-time / special purpose',
        'other' => 'Other',
    ];
    public const CATEGORIES = [
        'wages' => 'Wages', 'benefits' => 'Benefits', 'rent' => 'Rent / occupancy', 'utilities' => 'Utilities',
        'food' => 'Food', 'supplies' => 'Program supplies', 'equipment' => 'Equipment', 'repairs' => 'Repairs & maintenance',
        'training' => 'Training', 'admin' => 'Administration', 'other' => 'Other',
    ];
    private const KINDS = ['received', 'spent', 'adjustment', 'returned'];

    public function index(Request $request): JsonResponse
    {
        [$agencyId, $centreIds] = $this->scope($request);
        $q = DB::table('grants as g')->leftJoin('centres as c', 'c.id', '=', 'g.centre_id')->where('g.agency_id', $agencyId);
        if ($centreIds !== null) {
            $q->where(fn ($w) => $w->whereNull('g.centre_id')->orWhereIn('g.centre_id', $centreIds));
        }
        if ($request->query('status') === 'closed') $q->where('g.status', 'closed');
        elseif ($request->query('status') !== 'all') $q->where('g.status', 'active');
        $grants = $q->orderBy('g.period_end')->get(['g.*', 'c.name as centre_name']);
        $totals = DB::table('grant_transactions')->whereIn('grant_id', $grants->pluck('id'))
            ->selectRaw('grant_id, kind, SUM(amount) as total')->groupBy('grant_id', 'kind')->get()->groupBy('grant_id');
        foreach ($grants as $g) {
            $g->reconciliation = $this->reconcile($g, $totals[$g->id] ?? collect());
        }

        return response()->json(['grants' => $grants, 'types' => self::TYPES, 'categories' => self::CATEGORIES,
            'centres' => DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
                ->when($centreIds !== null, fn ($w) => $w->whereIn('id', $centreIds))->orderBy('name')->get(['id', 'name']),
            'can_manage' => $centreIds === null]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $g = $this->grant($request, $id);
        $tx = DB::table('grant_transactions as t')->leftJoin('users as u', 'u.id', '=', 't.created_by_id')
            ->leftJoin('expense_invoices as e', 'e.id', '=', 't.expense_invoice_id')
            ->where('t.grant_id', $id)->orderByDesc('t.txn_date')->orderByDesc('t.id')
            ->get(['t.*', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as by_name"), 'e.invoice_number as expense_number']);
        $totals = $tx->groupBy('kind')->map(fn ($r, $k) => (object) ['kind' => $k, 'total' => $r->sum('amount')])->values();
        $g->reconciliation = $this->reconcile($g, $totals);
        // Month-by-month and by category, for the funder's report.
        $months = [];
        foreach ($tx as $t) {
            $m = substr((string) $t->txn_date, 0, 7);
            $months[$m] = $months[$m] ?? ['month' => $m, 'received' => 0, 'spent' => 0];
            if ($t->kind === 'received') $months[$m]['received'] += (float) $t->amount;
            if ($t->kind === 'spent') $months[$m]['spent'] += (float) $t->amount;
        }
        ksort($months);
        $byCat = $tx->where('kind', 'spent')->groupBy(fn ($t) => $t->category ?: 'other')
            ->map(fn ($r, $k) => ['category' => $k, 'label' => self::CATEGORIES[$k] ?? ucfirst($k), 'spent' => round($r->sum('amount'), 2)])->values();

        return response()->json(['grant' => $g, 'transactions' => $tx, 'months' => array_values($months), 'by_category' => $byCat,
            'types' => self::TYPES, 'categories' => self::CATEGORIES,
            'expenses' => DB::table('expense_invoices')->where('agency_id', $g->agency_id)->whereNull('deleted_at')
                ->when($g->centre_id, fn ($w) => $w->where(fn ($x) => $x->whereNull('centre_id')->orWhere('centre_id', $g->centre_id)))
                ->orderByDesc('issue_date')->limit(200)->get(['id', 'invoice_number', 'reference', 'total', 'issue_date', 'category'])]);
    }

    public function save(Request $request, ?int $id = null): JsonResponse
    {
        [$agencyId, $centreIds] = $this->scope($request);
        abort_unless($centreIds === null, 403, 'Only an agency admin can set up grants.');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'funder' => ['nullable', 'string', 'max:160'],
            'program_type' => ['required', 'in:' . implode(',', array_keys(self::TYPES))],
            'centre_id' => ['nullable', 'integer'],
            'reference' => ['nullable', 'string', 'max:80'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount_awarded' => ['required', 'numeric', 'min:0', 'max:100000000'],
            'reporting_due' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'status' => ['nullable', 'in:active,closed'],
        ]);
        if (! empty($data['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $data['centre_id'])->where('agency_id', $agencyId)->exists(), 422, 'That centre is not in this agency.');
        }
        $data['status'] = $data['status'] ?? 'active';
        if ($id) {
            $this->grant($request, $id);
            DB::table('grants')->where('id', $id)->update($data + ['updated_at' => now()]);
        } else {
            $id = DB::table('grants')->insertGetId($data + ['agency_id' => $agencyId, 'created_by_id' => (int) $request->user()->id,
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'grants.saved', 'grant', $id, 'Saved grant "' . $data['name'] . '"');

        return response()->json(['id' => $id]);
    }

    public function addTransaction(Request $request, int $id): JsonResponse
    {
        $g = $this->grant($request, $id);
        $data = $request->validate([
            'kind' => ['required', 'in:' . implode(',', self::KINDS)],
            'txn_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'max:100000000', function ($a, $v, $fail) use ($request) {
                if ($request->input('kind') !== 'adjustment' && (float) $v <= 0) $fail('The amount must be more than zero.');
            }],
            'category' => ['nullable', 'in:' . implode(',', array_keys(self::CATEGORIES))],
            'description' => ['nullable', 'string', 'max:250'],
            'reference' => ['nullable', 'string', 'max:80'],
            'expense_invoice_id' => ['nullable', 'integer'],
        ]);
        if (! empty($data['expense_invoice_id'])) {
            abort_unless(DB::table('expense_invoices')->where('id', $data['expense_invoice_id'])->where('agency_id', $g->agency_id)->exists(), 422, 'Unknown expense bill.');
        }
        if ($data['kind'] !== 'spent') { $data['category'] = null; $data['expense_invoice_id'] = null; }
        $tid = DB::table('grant_transactions')->insertGetId($data + ['grant_id' => $id, 'created_by_id' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, (int) $g->agency_id, 'grants.transaction', 'grant', $id,
            ucfirst($data['kind']) . ' $' . number_format((float) $data['amount'], 2) . ' on grant "' . $g->name . '"');

        return response()->json(['id' => $tid], 201);
    }

    public function deleteTransaction(Request $request, int $id, int $txnId): JsonResponse
    {
        $g = $this->grant($request, $id);
        $t = DB::table('grant_transactions')->where('id', $txnId)->where('grant_id', $id)->first();
        abort_unless($t, 404);
        DB::table('grant_transactions')->where('id', $txnId)->delete();
        $this->audit($request, (int) $g->agency_id, 'grants.transaction_removed', 'grant', $id,
            'Removed ' . $t->kind . ' $' . number_format((float) $t->amount, 2) . ' (' . $t->txn_date . ') from grant "' . $g->name . '"');

        return response()->json(['ok' => true]);
    }

    public function csv(Request $request, int $id): Response
    {
        $g = $this->grant($request, $id);
        $tx = DB::table('grant_transactions')->where('grant_id', $id)->orderBy('txn_date')->orderBy('id')->get();
        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['Grant', $g->name]);
        fputcsv($fh, ['Funder', $g->funder, 'Reference', $g->reference]);
        fputcsv($fh, ['Period', $g->period_start . ' to ' . $g->period_end, 'Awarded', number_format((float) $g->amount_awarded, 2, '.', '')]);
        fputcsv($fh, []);
        fputcsv($fh, ['Date', 'Type', 'Category', 'Description', 'Reference', 'Amount']);
        $clean = fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v;
        foreach ($tx as $t) {
            fputcsv($fh, [$t->txn_date, $t->kind, $t->category ? (self::CATEGORIES[$t->category] ?? $t->category) : '', $clean($t->description), $clean($t->reference), number_format((float) $t->amount, 2, '.', '')]);
        }
        $r = $this->reconcile($g, $tx->groupBy('kind')->map(fn ($x, $k) => (object) ['kind' => $k, 'total' => $x->sum('amount')])->values());
        fputcsv($fh, []);
        foreach (['awarded' => 'Awarded', 'received' => 'Received', 'spent' => 'Spent', 'returned' => 'Returned', 'adjustments' => 'Adjustments', 'unspent' => 'Unspent (received - spent - returned + adjustments)', 'outstanding' => 'Still to be received'] as $k => $label) {
            fputcsv($fh, [$label, number_format((float) $r[$k], 2, '.', '')]);
        }
        rewind($fh);
        $this->audit($request, (int) $g->agency_id, 'grants.exported', 'grant', $id, 'Exported grant "' . $g->name . '"');

        return new Response("\xEF\xBB\xBF" . stream_get_contents($fh), 200, ['Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="grant-' . $id . '-' . now()->format('Y-m-d') . '.csv"']);
    }

    /* ── helpers ── */

    private function reconcile(object $g, $totals): array
    {
        $sum = fn ($k) => (float) (collect($totals)->firstWhere('kind', $k)->total ?? 0);
        $awarded = (float) $g->amount_awarded;
        $received = $sum('received'); $spent = $sum('spent'); $returned = $sum('returned'); $adj = $sum('adjustment');
        $start = Carbon::parse($g->period_start)->startOfDay(); $end = Carbon::parse($g->period_end)->endOfDay();
        $days = max(1, $start->diffInDays($end) + 1);
        $elapsed = now()->lt($start) ? 0 : (now()->gt($end) ? $days : $start->diffInDays(now()) + 1);
        $timePct = round($elapsed / $days * 100, 1);
        $spentPct = $awarded > 0 ? round($spent / $awarded * 100, 1) : 0;
        $pace = 'on_track';
        if ($awarded > 0 && $timePct >= 25 && $spentPct < $timePct - 20) $pace = 'underspending';
        if ($awarded > 0 && $spentPct > $timePct + 15) $pace = 'ahead';
        if ($spent > $awarded && $awarded > 0) $pace = 'overspent';

        return ['awarded' => round($awarded, 2), 'received' => round($received, 2), 'spent' => round($spent, 2), 'returned' => round($returned, 2),
            'adjustments' => round($adj, 2), 'unspent' => round($received - $spent - $returned + $adj, 2), 'outstanding' => round(max(0, $awarded - $received), 2),
            'time_pct' => $timePct, 'spent_pct' => $spentPct, 'pace' => $pace];
    }

    /** [agencyId, centreIds|null]; null centre list = agency admin / platform admin (all). */
    private function scope(Request $request): array
    {
        $agencyId = (int) $this->resolveAgencyId($request);
        abort_unless($agencyId > 0, 400, 'Select an agency first.');
        $uid = (int) $request->user()->id;
        $admin = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
        if ($admin) return [$agencyId, null];
        $centres = DB::table('role_assignments as r')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('r.user_id', $uid)->where('r.active', 1)->where('r.role', 'centre_director')->where('c.agency_id', $agencyId)->pluck('c.id')->all();
        abort_unless($centres, 403);

        return [$agencyId, array_map('intval', $centres)];
    }

    private function grant(Request $request, int $id): object
    {
        [$agencyId, $centreIds] = $this->scope($request);
        $g = DB::table('grants as g')->leftJoin('centres as c', 'c.id', '=', 'g.centre_id')->where('g.id', $id)->where('g.agency_id', $agencyId)
            ->first(['g.*', 'c.name as centre_name']);
        abort_unless($g, 404);
        abort_unless($centreIds === null || $g->centre_id === null || in_array((int) $g->centre_id, $centreIds, true), 403);
        return $g;
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }
}
