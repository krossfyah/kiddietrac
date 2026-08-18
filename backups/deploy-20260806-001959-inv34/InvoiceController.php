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
    public function index(Request $request)
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

        // v22p46: CSV export — ?format=csv streams all matching invoices
        // (capped at 2000 rows to keep memory in check) without the stats
        // payload.
        if (strtolower((string) $request->query('format', '')) === 'csv') {
            $rows = $q->limit(2000)->get();
            $filename = 'invoices-' . now()->format('Y-m-d') . '.csv';
            return new \Symfony\Component\HttpFoundation\StreamedResponse(function () use ($rows) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF");
                fputcsv($out, [
                    'Invoice #', 'Family', 'Status', 'Period start', 'Period end',
                    'Issued', 'Due', 'Subtotal', 'Subsidy', 'Discount', 'Tax',
                    'Total', 'Paid', 'Balance due',
                ]);
                foreach ($rows as $i) {
                    fputcsv($out, [
                        $i->invoice_number, $i->family_name, $i->status,
                        $i->period_start, $i->period_end,
                        $i->issued_at, $i->due_at,
                        $i->subtotal, $i->subsidy_amount, $i->discount_amount ?? 0, $i->tax_amount ?? 0,
                        $i->total, $i->amount_paid, $i->balance_due,
                    ]);
                }
                fclose($out);
            }, 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="' . $filename . '"',
                'Cache-Control' => 'no-store',
            ]);
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
     * GET /api/v1/parent/external-invoices
     * Invoices produced by an external agency platform (e.g. iLearn) and pushed
     * into KiddieTrac (external_invoices). Scoped to the logged-in guardian's
     * family/families. Read-only — these are collected in the source platform.
     */
    public function externalForParent(Request $request): JsonResponse
    {
        $familyIds = DB::table('guardians')
            ->where('user_id', $request->user()->id)
            ->pluck('family_id')
            ->filter()->unique()->values();

        if ($familyIds->isEmpty()) {
            return response()->json(['invoices' => [], 'stats' => ['open_total' => 0, 'open_count' => 0, 'paid_total' => 0, 'paid_count' => 0], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 1]]);
        }

        $isOpenStatus = fn ($s) => ! in_array(strtolower((string) $s), ['paid', 'void'], true);

        // Stats base = the family's whole set (search must NOT change the totals).
        $statsBase = DB::table('external_invoices as ei')
            ->whereIn('ei.family_id', $familyIds)
            ->where('ei.status', '!=', 'void');

        $base = DB::table('external_invoices as ei')
            ->leftJoin('agencies as a', 'a.id', '=', 'ei.agency_id')
            ->whereIn('ei.family_id', $familyIds)
            ->where('ei.status', '!=', 'void');

        // Search across number / description / status.
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $base->where(function ($w) use ($like) {
                $w->where('ei.number', 'like', $like)
                  ->orWhere('ei.description', 'like', $like)
                  ->orWhere('ei.status', 'like', $like);
            });
        }

        // Stats over the WHOLE set (not the search-filtered / paged subset).
        $all = $statsBase->get(['ei.status', 'ei.balance_due', 'ei.total']);
        $openAll = $all->filter(fn ($r) => $isOpenStatus($r->status));
        $stats = [
            'open_total' => round((float) $openAll->sum('balance_due'), 2),
            'open_count' => $openAll->count(),
            'paid_total' => round((float) $all->filter(fn ($r) => strtolower((string) $r->status) === 'paid')->sum('total'), 2),
            'paid_count' => $all->filter(fn ($r) => strtolower((string) $r->status) === 'paid')->count(),
        ];

        // Pagination (over the search-filtered set).
        $perPage = max(5, min(50, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $base)->count();
        $pages = max(1, (int) ceil($total / $perPage));

        // Sorting — user-selectable column, else the default (open first, then
        // earliest DUE date, so the invoice due soonest is at the top).
        $sortMap = ['due' => 'ei.due_at', 'amount' => 'ei.balance_due', 'number' => 'ei.number', 'status' => 'ei.status', 'issued' => 'ei.issued_at'];
        $sortKey = (string) $request->query('sort', '');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $rowsQ = (clone $base);
        if (isset($sortMap[$sortKey])) {
            $rowsQ->orderBy($sortMap[$sortKey], $dir)->orderByDesc('ei.id');
        } else {
            $rowsQ->orderByRaw("CASE WHEN ei.status IN ('paid') THEN 1 ELSE 0 END asc")
                ->orderByRaw('ei.due_at IS NULL asc')->orderBy('ei.due_at', 'asc')->orderByDesc('ei.id');
        }
        $rows = $rowsQ->forPage($page, $perPage)->get(['ei.*', 'a.name as agency_name']);

        // Whether online (Stripe) payment is available for these invoices. Off
        // unless the agency has Stripe configured (per-agency Connect keys).
        $stripeEnabled = false;

        return response()->json([
            'invoices' => $rows->map(fn ($r) => [
                'id'           => (int) $r->id,
                'source'       => $r->external_source,
                // Always show the FULL agency name (e.g. "iLearn Home Childcare"),
                // not the short source label.
                'source_label' => $r->agency_name ?: ($r->source_label ?: ucfirst((string) $r->external_source)),
                'number'       => $r->number,
                'status'       => $r->status,
                'issued_at'    => $r->issued_at,
                'due_at'       => $r->due_at,
                'total'        => (float) $r->total,
                'amount_paid'  => (float) $r->amount_paid,
                'balance_due'  => (float) $r->balance_due,
                'currency'     => $r->currency ?: 'CAD',
                'description'  => $r->description,
                'items'        => $r->items ? json_decode($r->items, true) : [],
                'pdf_url'      => $r->pdf_url ?? null,
                'is_open'      => $isOpenStatus($r->status),
            ])->values(),
            'stats' => $stats,
            'meta'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages, 'sort' => $sortKey, 'dir' => $dir, 'stripe_enabled' => $stripeEnabled],
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
     * v22p42 — POST /api/v1/admin/invoices/generate-batch
     *   { centre_id, month?, year? }
     *
     * Lets an agency_admin / platform_admin run the monthly invoicing
     * for a specific centre in their agency (without first impersonating
     * the centre director). Body re-uses generateBatch() but threads the
     * caller-supplied centre_id into the resolver via a synthetic input
     * field. Safer than overriding $this->resolveCentreId because the
     * existing director path keeps working unchanged.
     */
    public function generateBatchByCentre(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['required', 'integer'],
            'month' => ['nullable', 'integer', 'between:1,12'],
            'year'  => ['nullable', 'integer', 'between:2020,2100'],
        ]);

        // Re-use the inheritance path via a synthetic-input forward.
        $request->merge(['_centre_id_override' => (int) $data['centre_id']]);
        return $this->generateBatch($request);
    }

    /**
     * POST /api/v1/director/invoices/generate
     * Generate invoices for the current month for every enrolled family.
     */
    public function generateBatch(Request $request): JsonResponse
    {
        // v22p42: allow an agency_admin caller to target a specific centre
        // by setting _centre_id_override (forwarded by generateBatchByCentre()).
        // Validates ownership before honouring the override.
        $override = (int) $request->input('_centre_id_override');
        if ($override > 0) {
            $callerAgencyId = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->whereIn('role', ['agency_admin', 'platform_admin'])
                ->where('active', true)
                ->value('agency_id');
            $centreAgencyId = (int) DB::table('centres')->where('id', $override)->value('agency_id');
            $isPlatform = DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'platform_admin')->where('active', true)->exists();
            $ok = $isPlatform || ($callerAgencyId && $callerAgencyId === $centreAgencyId);
            if (!$ok) return response()->json(['message' => 'Centre not in your agency'], 403);
            $centreId = $override;
        } else {
            $centreId = $this->resolveCentreId($request->user());
        }
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

                // v22p42: invoices schema requires period_start + period_end NOT NULL.
                // Pre-existing bug — generateBatch never set these so the first call
                // 500'd with 'Field period_start doesn't have a default value'. Derive
                // them from the issue date (= first of the billed month).
                $periodStart = $issueDate->copy()->startOfMonth();
                $periodEnd   = $issueDate->copy()->endOfMonth();

                $invoiceId = DB::table('invoices')->insertGetId([
                    'centre_id' => $centreId,
                    'family_id' => $familyId,
                    'invoice_number' => $invoiceNumber,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
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

                // v22p42: actual invoice_lines schema uses line_type +
                // unit_amount + amount (no subsidy_amount/net_amount columns
                // on the line itself — those aggregate up to the invoice).
                // Emit two lines per family: gross tuition, and a separate
                // subsidy adjustment row (negative-sign described).
                foreach ($lineItems as $line) {
                    DB::table('invoice_lines')->insert([
                        'invoice_id' => $invoiceId,
                        'description' => $line['description'],
                        'line_type'   => 'tuition',
                        'quantity'    => 1,
                        'unit_amount' => $line['amount'],
                        'amount'      => $line['amount'],
                    ]);
                    if (!empty($line['subsidy']) && (float) $line['subsidy'] > 0) {
                        DB::table('invoice_lines')->insert([
                            'invoice_id' => $invoiceId,
                            'description' => 'CWELCC subsidy — ' . $line['description'],
                            'line_type'   => 'subsidy',
                            'quantity'    => 1,
                            'unit_amount' => -1 * abs((float) $line['subsidy']),
                            'amount'      => -1 * abs((float) $line['subsidy']),
                        ]);
                    }
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

                // Notify each guardian of the family that a new invoice is ready
                // (drives the parent app's Billing badge + notifications inbox).
                $invTitle = 'New invoice: ' . $invoiceNumber;
                $invBody = 'Your invoice for $' . number_format($total, 2) . ' is ready. Due ' . $dueDate->format('M j, Y') . '.';
                foreach (DB::table('guardians')->where('family_id', $familyId)->pluck('user_id') as $gid) {
                    DB::table('notifications')->insert([
                        'user_id' => $gid,
                        'type' => 'invoice',
                        'title' => $invTitle,
                        'body' => $invBody,
                        'data' => json_encode(['link' => '#billing', 'invoice_id' => $invoiceId]),
                        'created_at' => now(),
                    ]);
                    try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, $invTitle, $invBody, '#billing'); } catch (\Throwable $e) {}
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

            // v22p48: notify the family + recording staff member when an
            // invoice is paid off. Insert one notifications row per guardian
            // on the family + a single row for the recording user as a
            // receipt acknowledgement.
            if ($newStatus === 'paid') {
                $guardianIds = DB::table('guardians')->where('family_id', $invoice->family_id)->pluck('user_id')->all();
                $rows = [];
                $title = 'Invoice ' . $invoice->invoice_number . ' paid in full';
                $bodyPreview = '$' . number_format((float) $invoice->total, 2) . ' · receipt available in your billing tab';
                $now = now();
                foreach ($guardianIds as $gid) {
                    $rows[] = [
                        'user_id' => (int) $gid,
                        'type' => 'payment',
                        'title' => $title,
                        'body' => $bodyPreview,
                        'data' => json_encode([
                            'url' => '/dashboard.html#billing',
                            'invoice_id' => (int) $invoiceId,
                        ]),
                        'created_at' => $now,
                    ];
                }
                // Also notify the staff member who recorded the payment
                $rows[] = [
                    'user_id' => (int) $request->user()->id,
                    'type' => 'invoice',
                    'title' => 'Payment recorded · ' . $invoice->invoice_number,
                    'body' => 'Marked paid in full. ' . $bodyPreview,
                    'data' => json_encode([
                        'url' => '/dashboard.html#admin-billing',
                        'invoice_id' => (int) $invoiceId,
                    ]),
                    'created_at' => $now,
                ];
                if (!empty($rows)) DB::table('notifications')->insert($rows);
            }
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
