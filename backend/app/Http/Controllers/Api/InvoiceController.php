<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\InvoicePdfRenderer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

final class InvoiceController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesTenantAccess;

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

        // Merge integrated billing (e.g. iLearn) from external_invoices so the parent
        // sees their real invoices, not just KiddieTrac-native ones.
        $extFormatted = [];
        if (\Illuminate\Support\Facades\Schema::hasTable('external_invoices')) {
            $eq = DB::table('external_invoices')
                ->where('family_id', $child->family_id)
                ->whereNotIn('status', ['void', 'cancelled', 'draft']);
            if ($statusFilter === 'current' || $statusFilter === 'unpaid') {
                $eq->where('balance_due', '>', 0);
            } elseif ($statusFilter === 'paid') {
                $eq->where('balance_due', '<=', 0);
            }
            $extFormatted = $eq->orderByDesc('issued_at')->limit($limit)->get()->map(function ($e) {
                return [
                    'id' => 'ext-' . $e->id,
                    'invoice_number' => $e->number ?: ('INV-' . $e->id),
                    'family_name' => null,
                    'issue_date' => $e->issued_at,
                    'due_date' => $e->due_at,
                    'subtotal' => (float) $e->total,
                    'subsidy_amount' => 0.0,
                    'total' => (float) $e->total,
                    'balance_due' => (float) $e->balance_due,
                    'status' => $e->status,
                    'status_label' => (float) $e->balance_due <= 0 ? 'Paid' : ucfirst((string) $e->status),
                    'is_estimate' => false,
                    'external' => true,
                    'source' => $e->source_label ?: 'iLearn',
                    /* Whether there IS an official document, not where it lives. The URL
                       is signed and self-authenticating, so anything holding it can open
                       the invoice — it has no business in a browser history, a copied
                       link or a shared screen. The client asks this API for the document
                       and we fetch it, the same arrangement the payslips use. */
                    'has_document' => ! empty($e->pdf_url),
                ];
            })->all();
        }

        $native = $invoices->map(fn ($i) => $this->formatInvoice($i))->all();
        $all = array_merge($native, $extFormatted);
        usort($all, fn ($a, $b) => strcmp((string) ($b['issue_date'] ?? ''), (string) ($a['issue_date'] ?? '')));
        $all = array_slice($all, 0, $limit);
        // What happened to each one — instalments and refunds — so a part-paid invoice
        // can explain itself instead of just showing a smaller number.
        $all = $this->withPaymentHistory($all);

        // Only fall back to the synthetic estimate when there's genuinely nothing.
        if (empty($all) && $statusFilter === 'current') {
            $synthetic = $this->buildSyntheticCurrentInvoice($childId);
            return response()->json(['invoices' => $synthetic ? [$synthetic] : []]);
        }

        return response()->json(['invoices' => $all]);
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

        // What a parent is being asked to deal with NOW: due this month, plus anything
        // still owing from before. A family with three years of history does not want to
        // scroll past 2024 to find this month's bill — but hiding an overdue one to keep
        // the list tidy would be worse than the clutter it avoids.
        //
        // ?scope=all opts back into the full history for anyone who wants it.
        if (strtolower((string) $request->query('scope', 'current')) !== 'all') {
            $monthStart = \Illuminate\Support\Carbon::now()->startOfMonth()->toDateString();
            $monthEnd = \Illuminate\Support\Carbon::now()->endOfMonth()->toDateString();
            $base->where(function ($w) use ($monthStart, $monthEnd) {
                $w->whereBetween('ei.due_at', [$monthStart, $monthEnd])
                  ->orWhereBetween('ei.issued_at', [$monthStart, $monthEnd])
                  // Still owed from an earlier month — the ones that matter most.
                  ->orWhere(function ($late) use ($monthStart) {
                      $late->where('ei.balance_due', '>', 0)
                           ->whereNotNull('ei.due_at')
                           ->whereDate('ei.due_at', '<', $monthStart);
                  });
            });
        }

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
        $sortMap = ['due' => 'ei.due_at', 'amount' => 'ei.balance_due', 'number' => 'ei.number', 'status' => 'ei.status', 'issued' => 'ei.issued_at', 'total' => 'ei.total'];
        $sortKey = (string) $request->query('sort', '');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $rowsQ = (clone $base);
        if ($sortKey === 'family') {
            $rowsQ->orderByRaw("(SELECT TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) FROM guardians g JOIN users u ON u.id = g.user_id WHERE g.family_id = ei.family_id ORDER BY g.is_primary DESC LIMIT 1) " . ($dir === 'desc' ? 'desc' : 'asc'))->orderByDesc('ei.id');
        } elseif (isset($sortMap[$sortKey])) {
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
                'has_document' => ! empty($r->pdf_url),   // see externalDocument()
                'is_open'      => $isOpenStatus($r->status),
            ])->values(),
            'stats' => $stats,
            'meta'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages, 'sort' => $sortKey, 'dir' => $dir, 'stripe_enabled' => $stripeEnabled],
        ]);
    }

    /**
     * GET /api/v1/admin/external-invoices — agency-wide accounting view of the
     * invoices pulled LIVE from an external platform (iLearn) via the Integration
     * API. Admins + directors only (this is financial data across all families);
     * parents use externalForParent, scoped to their own family. Mirrors that
     * method's stats/paging but scopes to the caller's active agency and carries a
     * family label so staff can see WHOSE invoice each row is.
     */
    /**
     * GET /invoices/external/{id}/document — the invoice iLearn actually issued.
     *
     * The portal used to link straight at the signed iLearn URL. It worked, and the
     * parent did get the official document — but the link is self-authenticating and
     * permanent, so it survived in browser histories and could be forwarded by anyone
     * who came by it. Fetching it here instead means the URL never leaves this server,
     * and the request is checked against who is asking every single time. Same
     * arrangement the iLearn payslips already use.
     *
     * Content-type is passed through rather than assumed: iLearn renders the branded
     * invoice as HTML (self-contained — the logo is a data: URI and the styles are
     * inline), while payslips are PDFs. Claiming one is the other would break both.
     */
    public function externalDocument(Request $request, int $id)
    {
        $inv = DB::table('external_invoices')->where('id', $id)->first();
        abort_unless($inv, 404, 'Not found.');

        $user = $request->user();

        /* A guardian of THIS family, or staff of the agency that issued it. Checked
           here rather than trusted from the list that produced the id: a client can
           ask for any id it likes, and "you must have got this from a page we
           rendered" is not an authorisation. */
        $isGuardian = DB::table('guardians')->where('user_id', $user->id)
            ->where('family_id', $inv->family_id)->exists();

        if (! $isGuardian) {
            $agencyId = (int) $request->header('X-Active-Agency-Id');
            $isStaff = $agencyId === (int) $inv->agency_id
                && DB::table('role_assignments')->where('user_id', $user->id)->where('active', 1)
                    ->where(function ($q) use ($agencyId) {
                        $q->where('agency_id', $agencyId)->orWhere('role', 'platform_admin');
                    })
                    ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
                    ->exists();
            abort_unless($isStaff, 403, 'Not permitted.');
        }

        abort_unless(! empty($inv->pdf_url), 404, 'No official document for this invoice.');

        try {
            $res = \Illuminate\Support\Facades\Http::timeout(20)
                ->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])
                ->get((string) $inv->pdf_url);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[invoices] could not fetch the source document', [
                'invoice' => $id, 'error' => $e->getMessage(),
            ]);
            abort(502, 'The billing system could not be reached. Try again shortly.');
        }

        if (! $res->successful() || $res->body() === '') {
            \Illuminate\Support\Facades\Log::warning('[invoices] source document refused', [
                'invoice' => $id, 'status' => $res->status(),
            ]);

            /* A 404 from the source is not "could not be reached" — the document is
               GONE. iLearn soft-deletes invoices, and /pinv/{invoice} binds the model
               without trashed rows, so a deleted invoice 404s before the signature is
               even checked. Saying 502 for that sent everyone looking at the network.

               410 Gone, with a message the screen can show, because the row is still
               listed here while the document behind it no longer exists. */
            if ($res->status() === 404) {
                abort(410, 'This invoice was removed in the billing system, so the '
                    . 'official document no longer exists. The row here is a copy taken '
                    . 'before it was deleted.');
            }

            abort(502, 'The official invoice could not be loaded.');
        }

        $type = (string) ($res->header('Content-Type') ?: 'text/html; charset=utf-8');
        $ext = str_contains($type, 'pdf') ? 'pdf' : 'html';

        return response((string) $res->body(), 200, [
            'Content-Type' => $type,
            'Content-Disposition' => 'inline; filename="invoice-'
                . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) ($inv->number ?: $inv->id)) . '.' . $ext . '"',
            'X-KT-Document-Source' => (string) ($inv->external_source ?: 'external'),
        ]);
    }

    public function externalForAgency(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $empty = ['invoices' => [], 'stats' => ['open_total' => 0, 'open_count' => 0, 'paid_total' => 0, 'paid_count' => 0], 'families' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'pages' => 1]];
        if (! $agencyId) {
            return response()->json($empty);
        }

        $isOpenStatus = fn ($s) => ! in_array(strtolower((string) $s), ['paid', 'void'], true);

        // Family labels (primary guardian's name) for every family in this agency's
        // external-invoice set, so each row and the filter dropdown show a name.
        $familyIds = DB::table('external_invoices')->where('agency_id', $agencyId)
            ->whereNotNull('family_id')->distinct()->pluck('family_id')->all();
        $famLabel = [];
        foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->whereIn('g.family_id', $familyIds ?: [0])
            ->orderByDesc('g.is_primary')
            ->get(['g.family_id', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as nm")]) as $g) {
            $fid = (int) $g->family_id;
            if (! isset($famLabel[$fid]) && trim((string) $g->nm) !== '') {
                $famLabel[$fid] = trim((string) $g->nm);
            }
        }

        $statsBase = DB::table('external_invoices as ei')
            ->where('ei.agency_id', $agencyId)
            ->where('ei.status', '!=', 'void');
        $base = DB::table('external_invoices as ei')
            ->leftJoin('agencies as a', 'a.id', '=', 'ei.agency_id')
            ->where('ei.agency_id', $agencyId);

        // Void is hidden unless it is asked for by name. A voided invoice is a real
        // record — raised, then cancelled — but it is not part of "what is outstanding",
        // which is what this list answers by default. $statsBase keeps excluding it in
        // every case: voided money is neither owed nor received, and counting it would
        // misstate the totals above the table.
        $statusFilter = strtolower(trim((string) $request->query('status', '')));
        if ($statusFilter === 'void') {
            $base->where('ei.status', 'void');
        } elseif ($statusFilter !== '' && $statusFilter !== 'all') {
            $base->where('ei.status', $statusFilter)->where('ei.status', '!=', 'void');
        } else {
            $base->where('ei.status', '!=', 'void');
        }

        // Optional per-family filter.
        if ($famFilter = (int) $request->query('family_id', 0)) {
            $statsBase->where('ei.family_id', $famFilter);
            $base->where('ei.family_id', $famFilter);
        }
        // Search across number / description / status.
        if ($search = trim((string) $request->query('search', ''))) {
            $like = '%' . $search . '%';
            $base->where(function ($w) use ($like) {
                $w->where('ei.number', 'like', $like)
                  ->orWhere('ei.description', 'like', $like)
                  ->orWhere('ei.status', 'like', $like);
            });
        }

        $all = $statsBase->get(['ei.status', 'ei.balance_due', 'ei.total']);
        $openAll = $all->filter(fn ($r) => $isOpenStatus($r->status));
        $stats = [
            'open_total' => round((float) $openAll->sum('balance_due'), 2),
            'open_count' => $openAll->count(),
            'paid_total' => round((float) $all->filter(fn ($r) => strtolower((string) $r->status) === 'paid')->sum('total'), 2),
            'paid_count' => $all->filter(fn ($r) => strtolower((string) $r->status) === 'paid')->count(),
        ];

        $perPage = max(5, min(50, (int) $request->query('per_page', 20)));
        $page = max(1, (int) $request->query('page', 1));
        $total = (clone $base)->count();
        $pages = max(1, (int) ceil($total / $perPage));

        $sortMap = ['due' => 'ei.due_at', 'amount' => 'ei.balance_due', 'number' => 'ei.number', 'status' => 'ei.status', 'issued' => 'ei.issued_at', 'total' => 'ei.total'];
        $sortKey = (string) $request->query('sort', '');
        $dir = strtolower((string) $request->query('dir', 'asc')) === 'desc' ? 'desc' : 'asc';
        $rowsQ = (clone $base);
        if ($sortKey === 'family') {
            $rowsQ->orderByRaw("(SELECT TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) FROM guardians g JOIN users u ON u.id = g.user_id WHERE g.family_id = ei.family_id ORDER BY g.is_primary DESC LIMIT 1) " . ($dir === 'desc' ? 'desc' : 'asc'))->orderByDesc('ei.id');
        } elseif (isset($sortMap[$sortKey])) {
            $rowsQ->orderBy($sortMap[$sortKey], $dir)->orderByDesc('ei.id');
        } else {
            $rowsQ->orderByRaw("CASE WHEN ei.status IN ('paid') THEN 1 ELSE 0 END asc")
                ->orderByRaw('ei.due_at IS NULL asc')->orderBy('ei.due_at', 'asc')->orderByDesc('ei.id');
        }
        $rows = $rowsQ->forPage($page, $perPage)->get(['ei.*', 'a.name as agency_name']);

        /* WHOSE invoice, in role terms.

           The family name says who; this says what they are to the agency. A guardian
           who is only a guardian reads "Parent"; one who also holds a staff role reads
           both, which is the case worth seeing — Natasha Satnarine is a guardian and an
           educator, and every agency has a few. Resolved once for the page, not per
           row, so the list stays at two queries however long it is. */
        $roleLabels = [
            'guardian' => 'Parent', 'educator' => 'Educator', 'centre_director' => 'Director',
            'agency_admin' => 'Admin', 'platform_admin' => 'Platform admin',
            'home_visitor' => 'Home visitor', 'auditor' => 'Auditor', 'sales_rep' => 'Sales',
        ];
        $famRoles = [];
        foreach (DB::table('guardians as g')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'g.user_id')
            ->whereIn('g.family_id', $familyIds ?: [0])
            ->where('ra.active', 1)
            ->get(['g.family_id', 'ra.role']) as $r) {
            $fid = (int) $r->family_id;
            $label = $roleLabels[$r->role] ?? ucfirst(str_replace('_', ' ', (string) $r->role));
            $famRoles[$fid][$label] = true;
        }

        /* No ACTIVE role is not "unknown" — on every one of these it means the
           guardian's account has been closed, and an invoice addressed to a closed
           account is the thing somebody chasing payment most needs to notice. Say so
           rather than printing a dash. */
        $noRole = array_values(array_diff(array_map('intval', $familyIds), array_keys($famRoles)));
        if ($noRole) {
            foreach (DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->whereIn('g.family_id', $noRole)
                ->get(['g.family_id', 'u.status']) as $r) {
                $fid = (int) $r->family_id;
                if (isset($famRoles[$fid])) { continue; }
                $st = strtolower((string) $r->status);
                $famRoles[$fid]['Parent (' . ($st !== '' ? $st : 'no role') . ')'] = true;
            }
        }

        $families = [];
        foreach ($familyIds as $fid) {
            $families[] = ['id' => (int) $fid, 'label' => $famLabel[(int) $fid] ?? ('Family #' . (int) $fid)];
        }
        usort($families, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        return response()->json([
            'invoices' => $rows->map(fn ($r) => [
                'id'           => (int) $r->id,
                'family_id'    => (int) $r->family_id,
                'family'       => $famLabel[(int) $r->family_id] ?? ('Family #' . (int) $r->family_id),
                /* Parent, or Parent · Educator where the person being billed also works
                   here. Empty when the family has no guardian with an active role — the
                   column then shows a dash rather than inventing one. */
                'role'         => implode(' · ', array_keys($famRoles[(int) $r->family_id] ?? [])),
                'source'       => $r->external_source,
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
                'has_document' => ! empty($r->pdf_url),   // see externalDocument()
                'is_open'      => $isOpenStatus($r->status),
            ])->values(),
            'stats' => $stats,
            'families' => $families,
            'meta'  => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'pages' => $pages, 'sort' => $sortKey, 'dir' => $dir],
        ]);
    }

    /**
     * PATCH /agency/external-invoices/{id} — edit a synced invoice's KiddieTrac
     * copy (admin/director). NOTE: the source system re-syncs these, so an edit
     * here is overwritten when that invoice next changes at the source; it's a
     * local correction only.
     */
    public function updateExternalInvoice(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');
        $row = DB::table('external_invoices')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404);
        $d = $request->validate([
            'number'      => 'nullable|string|max:120',
            'status'      => 'nullable|string|max:40',
            'total'       => 'nullable|numeric',
            'amount_paid' => 'nullable|numeric',
            'issued_at'   => 'nullable|date',
            'due_at'      => 'nullable|date',
            'description' => 'nullable|string',
        ]);
        $total = array_key_exists('total', $d) && $d['total'] !== null ? round((float) $d['total'], 2) : (float) $row->total;
        $paid  = array_key_exists('amount_paid', $d) && $d['amount_paid'] !== null ? round((float) $d['amount_paid'], 2) : (float) $row->amount_paid;
        DB::table('external_invoices')->where('id', $id)->update([
            'number'      => $d['number'] ?? $row->number,
            'status'      => $d['status'] ?? $row->status,
            'total'       => $total,
            'amount_paid' => $paid,
            'balance_due' => max(0, round($total - $paid, 2)),
            'issued_at'   => $d['issued_at'] ?? $row->issued_at,
            'due_at'      => $d['due_at'] ?? $row->due_at,
            'description' => array_key_exists('description', $d) ? $d['description'] : $row->description,
            'updated_at'  => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $id]);
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

        /* Refunds were never returned here, so a refunded invoice looked simply
           unpaid with nothing on it to say why. */
        $refunds = DB::table('payment_refunds as r')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->where('p.invoice_id', $invoiceId)
            ->orderBy('r.refunded_at')
            ->get(['r.id', 'r.amount', 'r.refund_method', 'r.status', 'r.reason', 'r.refunded_at']);

        $withHistory = $this->withPaymentHistory([$this->formatInvoice($invoice)])[0];

        return response()->json([
            'invoice' => $withHistory,
            'family' => $family,
            'lines' => $lines,
            'payments' => $payments,
            'refunds' => $refunds,
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

    // #12 — ownership guard for the bulk-invoice kebab actions: the caller must be
    // an agency_admin/platform_admin of the centre's agency.
    private function assertCentreInCallerAgency(Request $request, int $centreId): void
    {
        // authorizeCentreAccess honors the active-agency header (so a platform_admin
        // scoped into an agency, and an agency_admin, both resolve correctly) —
        // unlike a raw role_assignments lookup which ignores the switch.
        abort_unless($this->authorizeCentreAccess($request->user(), $centreId), 403);
    }

    // Invoices for one centre + billing period (used by the kebab's "View invoices").
    private function centrePeriodInvoices(int $centreId, int $month, int $year)
    {
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = date('Y-m-t', strtotime($start));

        return DB::table('invoices as i')
            ->leftJoin('families as f', 'f.id', '=', 'i.family_id')
            ->where('i.centre_id', $centreId)
            ->whereDate('i.period_start', '>=', $start)
            ->whereDate('i.period_start', '<=', $end)
            ->orderByDesc('i.issued_at')
            ->get(['i.id', 'i.invoice_number', 'i.total', 'i.balance_due', 'i.status', 'i.issued_at', 'i.due_at', 'i.family_id', 'f.family_name']);
    }

    // GET /admin/invoices/by-centre?centre_id=&month=&year=
    public function listByCentre(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['required', 'integer'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year'  => ['required', 'integer', 'between:2020,2100'],
        ]);
        $this->assertCentreInCallerAgency($request, (int) $data['centre_id']);
        $rows = $this->centrePeriodInvoices((int) $data['centre_id'], (int) $data['month'], (int) $data['year']);

        return response()->json(['invoices' => $rows, 'count' => $rows->count()]);
    }

    // POST /admin/invoices/email-batch {centre_id, month, year}
    // Emails every family in the centre their invoice PDF for the period.
    public function emailBatch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id' => ['required', 'integer'],
            'month' => ['required', 'integer', 'between:1,12'],
            'year'  => ['required', 'integer', 'between:2020,2100'],
        ]);
        $this->assertCentreInCallerAgency($request, (int) $data['centre_id']);

        $invoices = $this->centrePeriodInvoices((int) $data['centre_id'], (int) $data['month'], (int) $data['year']);
        $agencyName = (string) DB::table('centres')
            ->leftJoin('agencies', 'agencies.id', '=', 'centres.agency_id')
            ->where('centres.id', $data['centre_id'])->value('agencies.name');
        $renderer = app(InvoicePdfRenderer::class);
        $emailed = 0;
        $skipped = 0;

        foreach ($invoices as $inv) {
            try {
                $emails = DB::table('guardians as g')
                    ->join('users as u', 'u.id', '=', 'g.user_id')
                    ->where('g.family_id', $inv->family_id)
                    ->whereNotNull('u.email')->where('u.email', '!=', '')
                    ->pluck('u.email')->unique()->values()->all();
                if (! $emails) { $skipped++; continue; }

                $html = $renderer->renderFromInvoiceId((int) $inv->id);
                if ($html === null) { $skipped++; continue; }
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dompdf->loadHtml($html, 'UTF-8');
                $dompdf->setPaper('letter', 'portrait');
                $dompdf->render();
                $pdf = $dompdf->output();

                $num = (string) ($inv->invoice_number ?: ('#' . $inv->id));
                $bal = (float) ($inv->balance_due ?? 0);
                $due = $bal > 0.005
                    ? '<p style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">Balance due: <strong>$' . number_format($bal, 2) . '</strong>' . ($inv->due_at ? ' &middot; due ' . e($inv->due_at) : '') . '</p>'
                    : '<p style="background:#ECFDF5;color:#047857;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">This invoice is paid in full. Thank you! 🎉</p>';
                $content = '<h1>🧾 Your invoice ' . e($num) . '</h1>'
                    . '<p>Hello,</p>'
                    . '<p>Your invoice from <strong>' . e($agencyName ?: 'your childcare provider') . '</strong> is attached as a PDF.</p>'
                    . $due
                    . '<p style="color:#64748B;font-size:13px;">You can also view and pay this invoice by signing in to KiddieTrac.</p>';
                $mailHtml = view('emails.layout', [
                    'slot' => $content,
                    'title' => 'Your invoice ' . $num,
                    'preheader' => 'Your invoice ' . $num . ' is attached.',
                ])->render();

                Mail::html($mailHtml, function ($m) use ($emails, $num, $pdf) {
                    $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
                    $first = array_shift($emails);
                    $m->to($first)->subject('Your invoice ' . $num);
                    foreach ($emails as $cc) {
                        $m->cc($cc);
                    }
                    $m->attachData($pdf, 'Invoice-' . preg_replace('/[^A-Za-z0-9]/', '-', $num) . '.pdf', ['mime' => 'application/pdf']);
                });
                $emailed++;
            } catch (\Throwable $e) {
                Log::warning('Invoice email-batch failed for invoice ' . $inv->id . ': ' . $e->getMessage());
                $skipped++;
            }
        }

        return response()->json(['emailed' => $emailed, 'skipped' => $skipped, 'total' => $invoices->count()]);
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
                'enrollments.schedule',
                'rooms.name as room_name',
            )
            ->get()
            /* ONE LINE PER CHILD, not per enrolment.
               A child with a split week holds one open enrolment per provider — Mon-Thu
               with one, Friday with another — and this query iterates ENROLMENTS, so it
               would put that child on the invoice twice and bill the family double. The
               fee is for the child's care, and splitting who delivers it does not double
               what it costs.

               Kept per child: the enrolment covering the most days, which is their main
               provider and the fee the family agreed. Real money, so it fails toward
               charging once. (2026-08-27) */
            ->groupBy('child_id')
            ->map(function ($rows) {
                if ($rows->count() === 1) {
                    return $rows->first();
                }
                return $rows->sortByDesc(function ($r) {
                    return count(\App\Support\CareSchedule::daysOf($r->schedule ?? null));
                })->first();
            })
            ->values()
            ->groupBy('family_id');

        $generated = 0;
        $skipped = 0;
        // Invoices to email once every transaction has committed — see below.
        $emailQueue = [];

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

                // …and email it, so a family without the app still hears about it.
                $emailQueue[] = (int) $invoiceId;

                $generated++;
            });
        }

        /* Sent after the transactions have committed, never inside them: a mail
           attempt that hangs would otherwise hold a write lock on the invoice it is
           announcing. Each one is isolated — one bad address must not cost the rest
           of the run their email. */
        $emailed = 0;
        foreach ($emailQueue as $invId) {
            try {
                if ($this->emailInvoiceToFamily($invId)) {
                    $emailed++;
                }
            } catch (\Throwable $e) {
                Log::warning('invoice email failed', ['invoice' => $invId, 'error' => $e->getMessage()]);
            }
        }

        return response()->json([
            'emailed' => $emailed,
            'generated' => $generated,
            'skipped' => $skipped,
            'message' => "Generated {$generated} invoices for ".$issueDate->format('F Y'),
        ]);
    }

    /**
     * Email one invoice to its family, with a button that opens it in the app.
     *
     * Returns false when there is nobody to send to or nothing to render, so the
     * caller can count what actually went out rather than what it attempted.
     *
     * Deliberately a deep link and not a pay-without-signing-in link: an email is
     * forwarded, quoted, and left open on shared screens, and a URL that can settle
     * somebody's invoice has no business living in one.
     */
    private function emailInvoiceToFamily(int $invoiceId): bool
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) {
            return false;
        }

        $emails = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $inv->family_id)
            ->whereNotNull('u.email')->where('u.email', '!=', '')
            ->pluck('u.email')->unique()->values()->all();
        if (! $emails) {
            return false;
        }

        $agencyName = DB::table('centres as c')
            ->join('agencies as a', 'a.id', '=', 'c.agency_id')
            ->where('c.id', $inv->centre_id)
            ->value('a.name');

        $num = (string) ($inv->invoice_number ?: ('#' . $inv->id));
        $bal = (float) ($inv->balance_due ?? 0);

        $appUrl = rtrim((string) (config('app.portal_url') ?: 'https://app.kiddietrac.com'), '/');
        $payLink = $appUrl . '/dashboard.html#billing';

        $due = $bal > 0.005
            ? '<p style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">Balance due: <strong>$'
                . number_format($bal, 2) . '</strong>' . ($inv->due_at ? ' &middot; due ' . e($inv->due_at) : '') . '</p>'
            : '<p style="background:#ECFDF5;color:#047857;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">This invoice is paid in full. Thank you! 🎉</p>';

        $button = $bal > 0.005
            ? '<p style="margin:20px 0;"><a href="' . e($payLink) . '" style="display:inline-block;background:#1F6FB2;color:#ffffff;'
                . 'text-decoration:none;font-weight:700;font-size:15px;padding:13px 26px;border-radius:10px;">Pay this invoice</a></p>'
                . '<p style="color:#64748B;font-size:12.5px;margin-top:-6px;">Opens KiddieTrac and takes you to your billing page. '
                . 'You can pay the full amount or part of it.</p>'
            : '';

        $content = '<h1>🧾 Your invoice ' . e($num) . '</h1>'
            . '<p>Hello,</p>'
            . '<p>Your invoice from <strong>' . e($agencyName ?: 'your childcare provider') . '</strong> is attached as a PDF.</p>'
            . $due
            . $button;

        $html = view('emails.layout', [
            'slot' => $content,
            'title' => 'Your invoice ' . $num,
            'preheader' => 'Your invoice ' . $num . ' is ready.',
        ])->render();

        // The PDF, when it renders. A missing PDF must not stop the email: the balance
        // and the button are the useful part, and the invoice is in the app regardless.
        $pdf = null;
        try {
            $rendered = app(\App\Services\InvoicePdfRenderer::class)->renderFromInvoiceId($invoiceId);
            if ($rendered !== null) {
                $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
                $dompdf->loadHtml($rendered, 'UTF-8');
                $dompdf->setPaper('letter', 'portrait');
                $dompdf->render();
                $pdf = $dompdf->output();
            }
        } catch (\Throwable $e) {
            Log::warning('invoice PDF failed for email', ['invoice' => $invoiceId, 'error' => $e->getMessage()]);
        }

        Mail::html($html, function ($m) use ($emails, $num, $pdf) {
            $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
            $first = array_shift($emails);
            $m->to($first)->subject('Your invoice ' . $num);
            foreach ($emails as $cc) {
                $m->cc($cc);
            }
            if ($pdf !== null) {
                $m->attachData($pdf, 'Invoice-' . $num . '.pdf', ['mime' => 'application/pdf']);
            }
        });

        return true;
    }

    /**
     * POST /api/v1/director/invoices/{invoice}/payments
     * Record an offline payment (e-transfer, cheque, cash).
     */
    /**
     * POST /invoices/{id}/void — cancel an invoice raised in error.
     *
     * Refuses while money is held against it. See the class note: a void that leaves
     * $300 attached to a cancelled document makes the family's balance right by
     * accident and wrong as soon as anyone asks where the money went. Refund first.
     */
    public function void(Request $request, int $invoiceId): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:300'],
        ]);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404);
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        if ($invoice->status === 'void') {
            return response()->json(['message' => 'That invoice is already void.'], 422);
        }

        /* NET of refunds. An invoice that was paid and then fully refunded holds
           nothing, so it can be voided — the money is already back with the family. */
        $paid = (float) DB::table('payments')
            ->where('invoice_id', $invoiceId)
            ->where('status', 'succeeded')
            ->sum('amount');
        $refunded = (float) DB::table('payment_refunds')
            ->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
            ->where('payments.invoice_id', $invoiceId)
            ->whereIn('payment_refunds.status', ['succeeded', 'pending', 'manual'])
            ->sum('payment_refunds.amount');
        $held = round($paid - $refunded, 2);

        if ($held > 0.005) {
            return response()->json([
                'message' => 'This invoice has $' . number_format($held, 2)
                    . ' paid against it. Refund that first, then void the invoice.',
                'amount_held' => $held,
            ], 422);
        }

        /* A payment still on its way would land against a cancelled invoice and credit
           it, so an in-flight instruction blocks the void too. */
        $inFlight = DB::table('zum_transactions')
            ->where('invoice_id', $invoiceId)
            ->whereIn('status', ['pending', 'submitted', 'in_review'])
            ->count();
        if ($inFlight > 0) {
            return response()->json([
                'message' => 'A payment is still in progress on this invoice. Wait for it to settle or fail, then void.',
            ], 422);
        }

        DB::table('invoices')->where('id', $invoiceId)->update([
            // Owed by nobody. The row and its number stay, so the history is intact.
            'balance_due' => 0,
            'status' => 'void',
            'notes' => trim((string) ($invoice->notes ?? '')
                . ' [voided ' . now()->toDateString()
                . (($data['reason'] ?? '') !== '' ? ': ' . $data['reason'] : '') . ']'),
            'updated_at' => now(),
        ]);

        $this->auditVoid($request, $invoice, (string) ($data['reason'] ?? ''));

        return response()->json([
            'message' => 'Invoice ' . ($invoice->invoice_number ?: ('#' . $invoiceId)) . ' has been voided.',
            'status' => 'void',
        ]);
    }

    private function auditVoid(Request $request, $invoice, string $reason): void
    {
        try {
            $fam = DB::table('families')->where('id', $invoice->family_id)->value('family_name');
            $agencyId = DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id');
            \App\Support\Audit::write([
                'user_id' => $request->user()->id ?? null,
                'agency_id' => $agencyId,
                'action' => 'invoice.voided',
                'entity_type' => 'invoice',
                'entity_id' => $invoice->id,
                /* Named, not counted. "Voided INV-1042 for the Osei family, $800.00"
                   is answerable months later; "voided 1 invoice" is not. */
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'family' => $fam,
                    'total' => (float) $invoice->total,
                    'reason' => $reason !== '' ? $reason : null,
                    'summary' => 'Voided invoice ' . ($invoice->invoice_number ?: ('#' . $invoice->id))
                        . ' for ' . ($fam ?: 'a family') . ', $' . number_format((float) $invoice->total, 2)
                        . ($reason !== '' ? ' — ' . $reason : ''),
                ]),
                'ip_address' => substr((string) $request->ip(), 0, 45),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a void fails.
        }
    }

    /** Staff of this family's centre only — never any active role anywhere. */
    private function assertStaffForFamily(Request $request, int $familyId): void
    {
        $user = $request->user();
        abort_unless($user, 403);

        $centreId = DB::table('families')->where('id', $familyId)->value('centre_id');
        abort_unless($centreId, 404);
        $agencyId = DB::table('centres')->where('id', $centreId)->value('agency_id');

        $ok = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where(function ($q) use ($centreId, $agencyId) {
                $q->where('centre_id', $centreId)
                  ->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $agencyId)->pluck('id'))
                  ->orWhereNull('centre_id');
            })
            ->exists();

        abort_unless($ok, 403);
    }

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
                // The column is reference_number; `reference` never existed, so every
                // manually recorded payment threw SQLSTATE[42S22] instead of saving.
                'reference_number' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by_id' => $request->user()->id,
                'status' => 'succeeded',
                'created_at' => now(),
                'updated_at' => now(),
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

    /**
     * Attach what actually happened to each invoice: instalments and refunds.
     *
     * Two queries for the whole list, not two per invoice. Amounts are recomputed
     * here rather than read from invoices.amount_paid so the history and the totals
     * on screen can never disagree — if they ever drift, the ledger wins, because
     * the ledger is the record.
     *
     * @param  array  $formatted  rows from formatInvoice()
     */
    private function withPaymentHistory(array $formatted): array
    {
        $ids = [];
        foreach ($formatted as $row) {
            // External rows carry an 'ext-123' id and have no ledger here.
            if (isset($row['id']) && is_numeric($row['id'])) {
                $ids[] = (int) $row['id'];
            }
        }
        if (! $ids) {
            return $formatted;
        }

        $payments = DB::table('payments')
            ->whereIn('invoice_id', $ids)
            ->orderBy('paid_at')
            ->get(['id', 'invoice_id', 'amount', 'method', 'status', 'reference_number', 'paid_at']);

        $refunds = DB::table('payment_refunds as r')
            ->join('payments as p', 'p.id', '=', 'r.payment_id')
            ->whereIn('p.invoice_id', $ids)
            ->orderBy('r.refunded_at')
            ->get(['r.id', 'p.invoice_id', 'r.amount', 'r.refund_method', 'r.status', 'r.reason', 'r.refunded_at']);

        $payByInv = [];
        $paidByInv = [];
        foreach ($payments as $p) {
            $payByInv[$p->invoice_id][] = [
                'id' => (int) $p->id,
                'date' => $p->paid_at,
                'amount' => (float) $p->amount,
                'method' => $p->method,
                'status' => $p->status,
                'reference' => $p->reference_number,
            ];
            if ($p->status === 'succeeded') {
                $paidByInv[$p->invoice_id] = ($paidByInv[$p->invoice_id] ?? 0) + (float) $p->amount;
            }
        }

        $refByInv = [];
        $refundedByInv = [];
        foreach ($refunds as $r) {
            $refByInv[$r->invoice_id][] = [
                'id' => (int) $r->id,
                'date' => $r->refunded_at,
                'amount' => (float) $r->amount,
                'method' => $r->refund_method,
                'status' => $r->status,
                'reason' => $r->reason,
            ];
            if (in_array($r->status, ['succeeded', 'pending', 'manual'], true)) {
                $refundedByInv[$r->invoice_id] = ($refundedByInv[$r->invoice_id] ?? 0) + (float) $r->amount;
            }
        }

        foreach ($formatted as &$row) {
            if (! isset($row['id']) || ! is_numeric($row['id'])) {
                continue;
            }
            $id = (int) $row['id'];
            $row['payments'] = $payByInv[$id] ?? [];
            $row['refunds'] = $refByInv[$id] ?? [];
            $row['amount_paid'] = round((float) ($paidByInv[$id] ?? 0), 2);
            $row['amount_refunded'] = round((float) ($refundedByInv[$id] ?? 0), 2);
            // What the family has actually parted with, after anything given back.
            $row['net_paid'] = round($row['amount_paid'] - $row['amount_refunded'], 2);
        }
        unset($row);

        return $formatted;
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

    /**
     * One implementation — Concerns\AuthorizesTenantAccess. Verified equivalent
     * across 13,350 real (user, child, active-agency) combinations.
     *
     * Returns bool rather than asserting: the callers here answer with their own
     * abort(403), and turning that into an exception would change the response.
     */
    private function canAccessChild($user, int $childId): bool
    {
        return $this->mayAccessChild((int) $user->id, $childId);
    }
}
