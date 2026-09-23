<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Support\InvoiceStatus;

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
                    /* Not due yet reads "Scheduled" rather than "Open". A zero
                       balance still wins: money received is not a matter of
                       interpretation. */
                    'status_label' => InvoiceStatus::label(
                        (string) $e->status, $e->due_at, (float) $e->balance_due
                    ),
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
    /**
     * GET /parent/external-invoices/{id}/link
     *
     * Hands back the provider's own link for ONE externally-issued invoice, after
     * checking the signed-in guardian belongs to the family it was raised against.
     *
     * A link rather than a file on purpose: pdf_url is named for what we hoped it was,
     * but the provider answers it with text/html — it is a tokenised invoice PAGE on
     * their site, not a PDF. Calling it a download would promise a file that never
     * arrives.
     *
     * Returned through the API instead of printed into the page so the token stays out
     * of the DOM, and so ownership is checked on OUR side rather than resting on the
     * provider's token alone.
     */
    public function externalInvoiceLinkForParent(Request $request, int $id): JsonResponse
    {
        $familyIds = DB::table('guardians')
            ->where('user_id', $request->user()->id)
            ->pluck('family_id')->filter()->map(fn ($v) => (int) $v)->all();

        $inv = DB::table('external_invoices')->where('id', $id)->first();
        abort_unless($inv, 404, 'Invoice not found');
        // ?: [0] — an empty family list must match nothing, never everything.
        abort_unless(in_array((int) $inv->family_id, $familyIds ?: [0], true), 403);
        abort_unless($inv->pdf_url, 404, 'No document for this invoice');

        return response()->json([
            'url' => $inv->pdf_url,
            'number' => $inv->number,
        ]);
    }

    /**
     * The same document, opened by STAFF.
     *
     * externalInvoiceLinkForParent() resolves the caller's families through `guardians`,
     * which is exactly right for a parent and answers 403 for everybody else — an
     * agency admin is not a guardian of anyone. The payment-schedules screen is used by
     * both, and its "View invoice" button called the parent route for everybody: staff
     * could see the row, and were told "Forbidden. Required role: guardian" the moment
     * they clicked it. Listing a document you are then refused is worse than not
     * listing it.
     *
     * Scoped by the ACTIVE AGENCY, not by guardianship — `external_invoices.agency_id`
     * is stamped at import. 404 rather than 403 for an invoice outside it: another
     * agency's billing is not ours to confirm the existence of.
     */
    public function externalInvoiceLinkForAgency(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 403);

        $inv = DB::table('external_invoices')->where('id', $id)->first();
        abort_unless($inv && (int) $inv->agency_id === (int) $agencyId, 404, 'Invoice not found');
        abort_unless($inv->pdf_url, 404, 'No document for this invoice');

        return response()->json([
            'url' => $inv->pdf_url,
            'number' => $inv->number,
        ]);
    }

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

        /* A DRAFT IS NOT OWED, AND IT IS NOT PAID EITHER (2026-09-17).
           Added with the union below: an invoice raised by a payment schedule sits as
           `draft` until the first of its month. Counting one as open would put money in
           the "outstanding" figure that nobody has been asked for yet — the same mistake
           as the 159 "open" invoices that were merely scheduled. It is listed, because a
           family with only drafts must still be findable; it is not counted. */
        $isOpenStatus = fn ($s) => ! in_array(strtolower((string) $s), ['paid', 'void', 'draft'], true);

        /* ACCOUNTING IS ALL THE MONEY, NOT ONE OF THE TWO TABLES.

           This screen read `external_invoices` alone — the invoices imported from the
           provider's own system — so a family billed from inside KiddieTrac appeared
           nowhere on it. Anthony: "under accounting the new families that have been
           entered are not showing up either such as the sandford-saganek family."

           He is right, and it is structural rather than about that family: iLearn holds
           478 provider invoices and 8 native ones, and exactly one family — the one he
           named — is native-only, which is why it took until now to be visible as a
           problem. Every family entered in KiddieTrac from here on is in that position.

           So both sources are unioned into one shape and everything downstream — the
           filters, the search, the sort, the pagination, the family dropdown — works
           over the union unchanged. `kt_source` says which table a row came from, because
           the actions differ: a provider invoice opens at the provider, a KiddieTrac one
           opens in our own viewer. Same money, one list, honest about its origin.

           Related: [[kiddietrac-parent-ledger-external]] and
           [[kiddietrac-outstanding-balances]] — both say the same thing about other
           screens, which is how a third one was found reading only half the money. */
        $unionOf = function () use ($agencyId) {
            $ext = DB::table('external_invoices')
                ->where('agency_id', $agencyId)
                /* EVERY string column is collated explicitly on BOTH sides.
                   `external_invoices` is utf8mb4_unicode_ci and `invoices` is
                   utf8mb4_general_ci, and MySQL refuses to UNION two columns whose
                   collations differ: "Illegal mix of collations". Same family of trap as
                   the latin1 tables that 500 on Unicode — the schema was built in two
                   eras and nothing lines them up. Naming one collation here beats
                   rewriting two live tables to find out what else depends on them. */
                ->selectRaw("id, agency_id, family_id,"
                    . " CONVERT(external_source USING utf8mb4) COLLATE utf8mb4_unicode_ci as external_source,"
                    . " CONVERT(number USING utf8mb4) COLLATE utf8mb4_unicode_ci as number,"
                    . " CONVERT(status USING utf8mb4) COLLATE utf8mb4_unicode_ci as status,"
                    . " issued_at, due_at, total, amount_paid, balance_due,"
                    . " CONVERT(currency USING utf8mb4) COLLATE utf8mb4_unicode_ci as currency,"
                    . " CONVERT(description USING utf8mb4) COLLATE utf8mb4_unicode_ci as description,"
                    . " CONVERT(items USING utf8mb4) COLLATE utf8mb4_unicode_ci as items,"
                    . " CONVERT(source_label USING utf8mb4) COLLATE utf8mb4_unicode_ci as source_label,"
                    . " CONVERT(pdf_url USING utf8mb4) COLLATE utf8mb4_unicode_ci as pdf_url,"
                    . " CONVERT('provider' USING utf8mb4) COLLATE utf8mb4_unicode_ci as kt_source,"
                    . " CONVERT('parent' USING utf8mb4) COLLATE utf8mb4_unicode_ci as counterparty");

            $native = DB::table('invoices as i')
                ->join('centres as c', 'c.id', '=', 'i.centre_id')
                ->where('c.agency_id', $agencyId)
                ->selectRaw("i.id, c.agency_id as agency_id, i.family_id,"
                    . " CONVERT('kiddietrac' USING utf8mb4) COLLATE utf8mb4_unicode_ci as external_source,"
                    . " CONVERT(i.invoice_number USING utf8mb4) COLLATE utf8mb4_unicode_ci as number,"
                    . " CONVERT(i.status USING utf8mb4) COLLATE utf8mb4_unicode_ci as status,"
                    . " i.issued_at, i.due_at, i.total, i.amount_paid, i.balance_due,"
                    . " CONVERT('CAD' USING utf8mb4) COLLATE utf8mb4_unicode_ci as currency,"
                    . " CONVERT(i.notes USING utf8mb4) COLLATE utf8mb4_unicode_ci as description,"
                    . " CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_unicode_ci as items,"
                    . " CONVERT('KiddieTrac' USING utf8mb4) COLLATE utf8mb4_unicode_ci as source_label,"
                    . " CONVERT(i.pdf_url USING utf8mb4) COLLATE utf8mb4_unicode_ci as pdf_url,"
                    . " CONVERT('kiddietrac' USING utf8mb4) COLLATE utf8mb4_unicode_ci as kt_source,"
                    . " CONVERT('parent' USING utf8mb4) COLLATE utf8mb4_unicode_ci as counterparty");

            /* THE THIRD SOURCE: what the agency bills or pays somebody who is not a
               family — an educator or provider's pay invoice, a contractor's, or one
               raised against a parent outside the enrolment billing. `payee_invoices`
               already carries the answer in its own `kind` column, which is where the
               filter's options come from rather than a list invented here. Anything
               with an unrecognised kind lands in "Other", visible rather than dropped.

               Its money columns are shaped differently — `reference` not `number`,
               `amount`/`subtotal` not `total`, and no running paid figure — so they are
               mapped rather than assumed, and a paid one reports its balance as zero.
               (Anthony, 2026-09-17) */
            $payee = DB::table('payee_invoices')
                ->where('agency_id', $agencyId)
                ->selectRaw("id, agency_id, payee_family_id as family_id,"
                    . " CONVERT('kiddietrac' USING utf8mb4) COLLATE utf8mb4_unicode_ci as external_source,"
                    . " CONVERT(reference USING utf8mb4) COLLATE utf8mb4_unicode_ci as number,"
                    . " CONVERT(status USING utf8mb4) COLLATE utf8mb4_unicode_ci as status,"
                    . " created_at as issued_at, period_end as due_at,"
                    . " COALESCE(subtotal, amount) as total,"
                    . " CASE WHEN status = 'paid' THEN COALESCE(subtotal, amount) ELSE 0 END as amount_paid,"
                    . " CASE WHEN status IN ('paid','void') THEN 0 ELSE COALESCE(subtotal, amount) END as balance_due,"
                    . " CONVERT('CAD' USING utf8mb4) COLLATE utf8mb4_unicode_ci as currency,"
                    . " CONVERT(payee_name USING utf8mb4) COLLATE utf8mb4_unicode_ci as description,"
                    . " CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_unicode_ci as items,"
                    . " CONVERT('KiddieTrac' USING utf8mb4) COLLATE utf8mb4_unicode_ci as source_label,"
                    . " CONVERT(NULL USING utf8mb4) COLLATE utf8mb4_unicode_ci as pdf_url,"
                    . " CONVERT('payee' USING utf8mb4) COLLATE utf8mb4_unicode_ci as kt_source,"
                    . " CONVERT(CASE WHEN kind IN ('educator','parent','contractor') THEN kind ELSE 'misc' END"
                    . "   USING utf8mb4) COLLATE utf8mb4_unicode_ci as counterparty");

            return $ext->unionAll($native)->unionAll($payee);
        };

        // Family labels (primary guardian's name) for every family in this agency's
        // invoice set — both sources — so each row and the filter dropdown show a name.
        $familyIds = DB::query()->fromSub($unionOf(), 'ei')
            ->whereNotNull('ei.family_id')->distinct()->pluck('ei.family_id')->all();
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

        $statsBase = DB::query()->fromSub($unionOf(), 'ei')
            ->where('ei.status', '!=', 'void');
        $base = DB::query()->fromSub($unionOf(), 'ei')
            ->leftJoin('agencies as a', 'a.id', '=', 'ei.agency_id');

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

        /* WHO THE INVOICE IS WITH. One dropdown rather than four sub-tabs — Anthony
           asked for tabs and then said a filter would be "easier and cleaner", which it
           is: the screen already has a filter row, and the totals above the table then
           describe whatever is being looked at instead of a fixed slice. */
        $party = strtolower(trim((string) $request->query('counterparty', '')));
        if ($party !== '' && $party !== 'all' && in_array($party, ['parent', 'educator', 'contractor', 'misc'], true)) {
            $statsBase->where('ei.counterparty', $party);
            $base->where('ei.counterparty', $party);
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
                /* WHICH TABLE, so the row's actions can differ. A provider invoice opens
                   at the provider through /link; a KiddieTrac one has no such link and
                   opens in our own viewer. The screen keys on this rather than guessing
                   from the label. */
                'kt_source'    => $r->kt_source ?? 'provider',
                'counterparty' => $r->counterparty ?? 'parent',
                'source_label' => ($r->kt_source ?? '') === 'kiddietrac'
                    ? 'KiddieTrac'
                    : ($r->agency_name ?: ($r->source_label ?: ucfirst((string) $r->external_source))),
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
    /* VOID AN INVOICE THAT CAME FROM iLEARN (2026-09-17).

       Anthony: "for existing invoices that are scheduled allow them to be edited, voided
       etc (these are the ones that came from ilearn system)."

       128 of the 129 `open` external invoices are future-dated — the ones the table shows
       as Scheduled. They could be edited but never cancelled, so the only way to withdraw
       one was to go and do it in iLearn.

       THE SYNC CAN UNDO THIS, and the caller is told so rather than finding out. This
       writes to KiddieTrac's copy; iLearn remains the system of record and the next sync
       may set the status back. That is exactly what the existing Edit dialog warns about,
       and voiding deserves the same warning because the consequence is larger.

       The family and the office get the same two letters a KiddieTrac void sends — from
       their side an invoice is an invoice, and "your invoice is cancelled" should not
       depend on which system raised it. */
    /* MONEY THAT ARRIVED OUTSIDE THE RAILS (2026-09-17).

       Anthony: "add the resend invoice and manual paid functions for those parents that
       paid via cash of EFT outside of zum rails etc so a popup comes up to confirm
       payment with reference number and allow for partial payment."

       recordPayment() already did this for a KiddieTrac invoice. A SYNCED one had no way
       to take a payment at all, so a parent who handed over cash against an iLearn
       invoice could only be recorded by editing the amount_paid field by hand - which
       leaves no record of when it arrived, how, under what reference, or who took it.

       A REAL PAYMENT ROW, not a number nudged. `payments` already has an
       external_invoice_id column, so the receipt lives in the same table as every other
       payment and the invoice's totals are DERIVED from the rows rather than typed.

       PARTIAL IS THE NORMAL CASE, not an error: a family paying half now and half on
       Friday is ordinary, and the status follows the arithmetic - paid when the balance
       reaches zero, partial while anything is still outstanding.

       OVERPAYMENT IS REFUSED rather than silently absorbed. Taking $400 against a $250
       invoice usually means the wrong invoice is open; a balance that cannot go below
       zero would hide that. */
    /* THE API'S WORDS ARE NOT THE COLUMN'S WORDS (2026-09-17).

       `payments.method` is an ENUM:
         stripe_card, stripe_ach, interac, eft, card, cash, cheque, manual

       Both manual-payment endpoints validate against a friendlier list - cash, cheque,
       e_transfer, bank_transfer, credit_card_offline, other - of which only `cash` and
       `cheque` are actually in the enum. So FOUR of the six methods have always failed
       with "Data truncated for column 'method'" and a 500, including e-Transfer, which is
       how most families pay outside the rails. Found while testing the external twin;
       recordPayment() has carried it since it was written, and it is the same shape as
       the `reference` vs `reference_number` bug documented in that method.

       Mapped rather than renamed: the friendly values are what the UI and any existing
       caller send, and changing the ENUM would mean an ALTER on a live payments table. */
    private static function paymentMethodValue(string $given): string
    {
        $map = [
            'e_transfer' => 'interac',          // Interac e-Transfer
            'bank_transfer' => 'eft',
            'credit_card_offline' => 'card',
            'other' => 'manual',
        ];

        $v = $map[$given] ?? $given;

        /* Anything still outside the enum is recorded as a manual payment rather than
           throwing: the money arrived, and a 500 loses that fact entirely. */
        return in_array($v, ['stripe_card', 'stripe_ach', 'interac', 'eft', 'card', 'cash', 'cheque', 'manual'], true)
            ? $v : 'manual';
    }

    public function recordExternalPayment(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');
        $row = DB::table('external_invoices')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'That invoice no longer exists.');

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', 'in:cash,cheque,e_transfer,bank_transfer,credit_card_offline,other'],
            'paid_at' => ['nullable', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'add_surcharge' => ['nullable', 'boolean'],
        ]);

        if ((string) $row->status === 'void') {
            return response()->json(['message' => 'That invoice is void. Nothing is owed on it.'], 422);
        }

        /* A SYNCED INVOICE HAS NO LINES OF OURS, so the fee is added to its total
           instead. The billing system remains the system of record and the next sync may
           reset the figure - the dialog says so before the box is ticked. */
        $amount = round((float) $data['amount'], 2);
        if (! empty($data['add_surcharge'])) {
            $pct = \App\Services\PaymentSurcharge::percentFor($agencyId, (string) $data['method']);
            $fee = \App\Services\PaymentSurcharge::feeOn($amount, $pct);
            if ($fee > 0.005) {
                DB::table('external_invoices')->where('id', $id)
                    ->update(['total' => round((float) $row->total + $fee, 2), 'updated_at' => now()]);
                $row = DB::table('external_invoices')->where('id', $id)->first();
                // The family paid the fee as well, so the payment recorded includes it.
                $amount = round($amount + $fee, 2);
            }
        }

        $alreadyPaid = round((float) DB::table('payments')->where('external_invoice_id', $id)
            ->where('status', 'succeeded')->sum('amount'), 2);
        /* The synced figure is the starting point: iLearn may have recorded payments this
           table knows nothing about, and treating our own rows as the whole story would
           let the same money be taken twice. */
        $baseline = max(round((float) $row->amount_paid, 2), $alreadyPaid);
        $outstanding = round((float) $row->total - $baseline, 2);

        if ($amount > $outstanding + 0.005) {
            return response()->json([
                'message' => 'That is more than the ' . '$' . number_format(max(0, $outstanding), 2)
                    . ' outstanding on ' . ($row->number ?: ('#' . $id))
                    . '. Check the invoice before recording it.',
                'outstanding' => max(0, $outstanding),
            ], 422);
        }

        DB::table('payments')->insert([
            'external_invoice_id' => $id,
            'family_id' => $row->family_id,
            'amount' => $amount,
            'method' => self::paymentMethodValue((string) $data['method']),
            'paid_at' => $data['paid_at'] ?? now(),
            'reference_number' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by_id' => $request->user()->id,
            'status' => 'succeeded',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $paid = round($baseline + $amount, 2);
        $balance = round(max(0, (float) $row->total - $paid), 2);
        $status = $balance <= 0.005 ? 'paid' : 'open';

        DB::table('external_invoices')->where('id', $id)->update([
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'status' => $status,
            'updated_at' => now(),
        ]);

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => 'payment.recorded_manually',
                'entity_type' => 'external_invoice',
                'entity_id' => $id,
                'payload' => json_encode([
                    'number' => $row->number,
                    'family_id' => (int) $row->family_id,
                    'amount' => $amount,
                    'method' => $data['method'],
                    'reference' => $data['reference'] ?? null,
                    'balance_after' => $balance,
                    'summary' => 'Recorded $' . number_format($amount, 2) . ' by ' . $data['method']
                        . ' against ' . ($row->number ?: ('#' . $id))
                        . (($data['reference'] ?? '') !== '' ? ' (ref ' . $data['reference'] . ')' : '')
                        . '; balance is now $' . number_format($balance, 2) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the receipt over its own audit row */ }

        return response()->json([
            'status' => $status,
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'message' => '$' . number_format($amount, 2) . ' recorded against '
                . ($row->number ?: ('#' . $id))
                . ($balance > 0.005 ? '. $' . number_format($balance, 2) . ' still outstanding.' : '. Paid in full.'),
        ]);
    }

    /* WHO A RESEND WOULD GO TO, for any invoice in the ledger.

       The addresses are SHOWN and editable rather than assumed: a resend that silently
       picks its own recipient is how an invoice reaches the wrong inbox
       ([[never-default-a-recipient]]). `guardians` holds no email, so this joins users. */
    /* What each method would cost, so the Record-payment dialog can show the fee BEFORE
       anybody agrees to it. Read-only and charges nothing. */
    /* Email a receipt for one payment. Defaults to the most recent payment on the
       invoice, because that is the one somebody has just taken. */
    public function emailReceipt(Request $request, int $invoiceId): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email', 'max:180'],
            'payment_id' => ['nullable', 'integer'],
        ]);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'That invoice no longer exists.');
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        $paymentId = $data['payment_id'] ?? DB::table('payments')->where('invoice_id', $invoiceId)
            ->where('status', 'succeeded')->orderByDesc('paid_at')->orderByDesc('id')->value('id');
        abort_unless($paymentId, 422, 'No payment has been recorded against this invoice yet.');

        // Scoped: a payment id from another invoice must not be receipted against this one.
        $owns = DB::table('payments')->where('id', $paymentId)->where('invoice_id', $invoiceId)->exists();
        abort_unless($owns, 404, 'No such payment on this invoice.');

        $r = \App\Services\PaymentReceipt::build((int) $paymentId);
        abort_unless($r, 422, 'That receipt could not be built.');

        if (! \App\Support\Suppression::agencyNotificationsEnabled($r['agency_id'])) {
            return response()->json([
                'sent' => false,
                'reason' => 'Email is switched off for this agency (Settings → "Send notifications and emails").',
            ], 409);
        }

        \App\Services\AgencyMailer::forAgency($r['agency_id'])->html($r['html'],
            function ($m) use ($data, $r) {
                $m->to($data['to'])->subject($r['subject']);
                /* The figures are IN the attachment and nowhere else. If dompdf could not
                   produce one the covering note still goes, and the response says the
                   receipt is missing rather than pretending it was sent. */
                if (! empty($r['pdf'])) {
                    $m->attachData($r['pdf'], $r['filename'], ['mime' => 'application/pdf']);
                }
            });

        return response()->json([
            'sent' => true,
            'payment_id' => (int) $paymentId,
            'attached' => ! empty($r['pdf']),
        ]);
    }

    public function surchargeRates(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');

        return response()->json(['rates' => \App\Services\PaymentSurcharge::rates($agencyId)]);
    }

    public function billingContacts(Request $request, int $familyId): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');

        // The FAMILY must belong to the caller's agency, not merely exist.
        $ok = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->where('c.agency_id', $agencyId)->exists();
        abort_unless($ok, 404, 'No such family.');

        $emails = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $familyId)->whereNotNull('u.email')
            ->orderByDesc('g.is_primary')
            ->pluck('u.email')->unique()->values()->all();

        if (! $emails) {
            $fallback = DB::table('families')->where('id', $familyId)->value('primary_email');
            if ($fallback) { $emails = [$fallback]; }
        }

        return response()->json(['emails' => $emails]);
    }

    public function voidExternalInvoice(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:300']]);

        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 400, 'No active agency.');
        $row = DB::table('external_invoices')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'That invoice no longer exists.');

        if ((string) $row->status === 'void') {
            return response()->json(['message' => 'That invoice is already void.'], 422);
        }

        /* NET of what has been paid. An invoice with money against it cannot simply be
           cancelled — the same rule the KiddieTrac void applies, for the same reason:
           voiding it would leave a payment attached to nothing. */
        $held = round((float) $row->amount_paid, 2);
        if ($held > 0.005) {
            return response()->json([
                'message' => 'This invoice has $' . number_format($held, 2) . ' paid against it. '
                    . 'Refund or reallocate that in iLearn first, then void it here.',
                'amount_held' => $held,
            ], 422);
        }

        DB::table('external_invoices')->where('id', $id)->update([
            'status' => 'void',
            'balance_due' => 0,
            'updated_at' => now(),
        ]);

        try {
            \App\Support\Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => 'invoice.voided_external',
                'entity_type' => 'external_invoice',
                'entity_id' => $id,
                'payload' => json_encode([
                    'number' => $row->number,
                    'family_id' => (int) $row->family_id,
                    'total' => (float) $row->total,
                    'source' => $row->external_source,
                    'reason' => $data['reason'] ?? null,
                    'summary' => 'Voided ' . ($row->number ?: ('external #' . $id)) . ' ($'
                        . number_format((float) $row->total, 2) . '), synced from '
                        . ($row->external_source ?: 'the billing system')
                        . (($data['reason'] ?? '') !== '' ? ': ' . $data['reason'] : '')
                        . '. The next sync may restore it.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the void over its own audit row */ }

        /* The letters read `invoice_number`; an external row calls it `number`. Mapped
           rather than duplicated, so both kinds of void say the same thing. */
        $row->invoice_number = $row->number;
        $row->id = $id;
        $this->notifyVoided($request, $row, (string) ($data['reason'] ?? ''));

        return response()->json([
            'status' => 'void',
            'message' => ($row->number ?: ('#' . $id)) . ' has been voided. '
                . 'It is cancelled in KiddieTrac; the next sync from '
                . ($row->external_source ?: 'the billing system') . ' may restore it.',
        ]);
    }

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

        /* WHO THE INVOICE IS ACTUALLY FOR (2026-09-17).

           Anthony: "the popup should show all the info on the parent and their child and
           if multiple child show this info as well." The dialog had a family NAME and
           nothing else, so an admin adding a charge could not see who they were billing
           or which children it covered - and a family with three children looked exactly
           like a family with one.

           `guardians` holds no name or email; those live on the user, which is why this
           joins rather than selecting from guardians alone. Children are listed with the
           room and status an office actually asks about, and withdrawn ones are kept
           rather than hidden - an invoice may well cover a child who has since left, and
           dropping them would make the charge look unattached to anybody. */
        $guardians = DB::table('guardians as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->where('g.family_id', $invoice->family_id)
            ->orderByDesc('g.is_primary')
            ->get([
                'g.id', 'g.relationship', 'g.is_primary', 'g.can_receive_billing',
                'g.billing_share_pct',
                'u.id as user_id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone',
            ]);

        $children = DB::table('children as c')
            ->leftJoin('rooms as r', 'r.id', '=', 'c.primary_room_id')
            ->where('c.family_id', $invoice->family_id)
            ->whereNull('c.deleted_at')
            ->orderBy('c.first_name')
            ->get([
                'c.id', 'c.first_name', 'c.last_name', 'c.preferred_name',
                'c.date_of_birth', 'c.enrollment_status', 'c.photo_url',
                DB::raw('r.name as room_name'),
            ]);

        return response()->json([
            'invoice' => $withHistory,
            'family' => $family,
            'lines' => $lines,
            'payments' => $payments,
            'refunds' => $refunds,
            'guardians' => $guardians,
            'children' => $children,
        ]);
    }

    /* ADD A LINE TO AN INVOICE (2026-09-17).

       Anthony: "add ability to add a line item to add or deduct additional charges with a
       description and to add optional tax."

       A CREDIT IS A NEGATIVE LINE, not a second concept. "Deduct" could have been a
       separate discount field, but then two places would move the same total and the
       invoice document would need to learn about both; a line of -25.00 renders, sums
       and refunds exactly like a line of 25.00, and the parent sees plainly what was
       taken off and why.

       DESCRIPTION IS REQUIRED. An unexplained charge on a childcare invoice is the thing
       a parent phones about, and "Adjustment" tells whoever answers nothing.

       NOT ON A VOID INVOICE. Void means the whole document is withdrawn; adding a line
       to one would put money back on a piece of paper the family has been told to ignore.
       A PAID one is allowed on purpose - a late fee raised after payment is ordinary -
       and the recalculation reopens the balance, which the dialog warns about first. */
    public function addLine(Request $request, int $invoiceId): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:200',
            'amount' => 'required|numeric|not_in:0|min:-100000|max:100000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'child_id' => 'nullable|integer',
            'line_type' => 'nullable|string|max:40',
        ]);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'That invoice no longer exists.');
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        if ((string) $invoice->status === 'void') {
            return response()->json([
                'message' => 'That invoice is void. Raise a new one rather than adding to it.',
            ], 422);
        }

        /* A child id from another family would print a stranger's child on this
           family's invoice. Scoped rather than trusted. */
        $childId = $data['child_id'] ?? null;
        if ($childId) {
            $ok = DB::table('children')->where('id', $childId)
                ->where('family_id', $invoice->family_id)->exists();
            if (! $ok) { $childId = null; }
        }

        $ALLOWED_TYPES = ['tuition', 'subsidy', 'late_fee', 'extra_day', 'field_trip',
            'meal', 'supply', 'adjustment', 'tax'];
        $lineType = $data['line_type'] ?? null;
        if (! $lineType || ! in_array($lineType, $ALLOWED_TYPES, true)) { $lineType = 'adjustment'; }

        $amount = round((float) $data['amount'], 2);
        $taxRate = isset($data['tax_rate']) && $data['tax_rate'] !== null && (float) $data['tax_rate'] > 0
            ? round((float) $data['tax_rate'], 2)
            : null;

        $lineId = DB::table('invoice_lines')->insertGetId([
            'invoice_id' => $invoiceId,
            'child_id' => $childId,
            'description' => trim((string) $data['description']),
            /* `line_type` is an ENUM (tuition, subsidy, late_fee, extra_day, field_trip,
               meal, supply, adjustment, tax) and 'credit' is NOT one of its values - a
               negative line failed with a 500 until this said 'adjustment'. A credit IS
               an adjustment; the sign carries the direction, not the type. A caller may
               still name a more specific one, validated against the enum below. */
            'line_type' => $lineType,
            'quantity' => 1,
            'unit_amount' => $amount,
            'amount' => $amount,
            'tax_rate' => $taxRate,
        ]);

        $totals = $this->applyLineDelta($invoiceId, $amount, $taxRate);

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'invoice.line_added',
                'entity_type' => 'invoice',
                'entity_id' => $invoiceId,
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'line_id' => $lineId,
                    'description' => trim((string) $data['description']),
                    'amount' => $amount,
                    'tax_rate' => $taxRate,
                    'new_total' => $totals['total'],
                    'summary' => ($amount < 0 ? 'Credited ' : 'Charged ') . '$' . number_format(abs($amount), 2)
                        . ($taxRate ? ' plus ' . $taxRate . '% tax' : '')
                        . ' on ' . ($invoice->invoice_number ?: ('#' . $invoiceId))
                        . ' ("' . trim((string) $data['description']) . '"); total is now $'
                        . number_format($totals['total'], 2) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the edit over its own audit row */ }

        return response()->json(['status' => 'added', 'line_id' => $lineId] + $totals);
    }

    /* EDIT A LINE: its wording, its amount, its tax (2026-09-17).

       Anthony: "description should be editable for accounting and invoices."

       A description is the sentence a parent reads when they are working out what they
       are being charged for, and it was the one thing on an invoice that could be wrong
       and not fixed - the only options were to delete the line and retype it, which
       renumbers nothing but does lose the line's place in the order.

       THE AMOUNT MOVES BY ITS DIFFERENCE, not by replacement. applyLineDelta() adds a
       delta to figures that are already correct, so changing 25.00 to 40.00 sends +15.00
       through exactly the same path as adding a 15.00 line would - which matters because
       this invoice's subtotal is NOT guaranteed to equal the sum of its lines
       ([[kiddietrac-lines-do-not-sum-to-subtotal]]) and must never be recomputed. */
    public function updateLine(Request $request, int $invoiceId, int $lineId): JsonResponse
    {
        $data = $request->validate([
            'description' => 'required|string|max:200',
            'amount' => 'nullable|numeric|not_in:0|min:-100000|max:100000',
            'tax_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'That invoice no longer exists.');
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        if ((string) $invoice->status === 'void') {
            return response()->json(['message' => 'That invoice is void.'], 422);
        }

        $line = DB::table('invoice_lines')->where('id', $lineId)->where('invoice_id', $invoiceId)->first();
        abort_unless($line, 404, 'No such line on this invoice.');

        $oldAmount = (float) $line->amount;
        $oldRate = $line->tax_rate === null ? null : (float) $line->tax_rate;
        $newAmount = array_key_exists('amount', $data) && $data['amount'] !== null
            ? round((float) $data['amount'], 2) : $oldAmount;
        $newRate = array_key_exists('tax_rate', $data)
            ? ((float) ($data['tax_rate'] ?? 0) > 0 ? round((float) $data['tax_rate'], 2) : null)
            : $oldRate;

        DB::table('invoice_lines')->where('id', $lineId)->update([
            'description' => trim((string) $data['description']),
            'unit_amount' => $newAmount,
            'amount' => $newAmount,
            'tax_rate' => $newRate,
        ]);

        /* Two deltas rather than one: take the old line off exactly as a delete would,
           then put the new one on exactly as an add would. Computing a single combined
           delta would have to get the tax arithmetic right in both directions at once. */
        $this->applyLineDelta($invoiceId, -$oldAmount, $oldRate);
        $totals = $this->applyLineDelta($invoiceId, $newAmount, $newRate);

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'invoice.line_updated',
                'entity_type' => 'invoice',
                'entity_id' => $invoiceId,
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'line_id' => $lineId,
                    'from' => ['description' => $line->description, 'amount' => $oldAmount, 'tax_rate' => $oldRate],
                    'to' => ['description' => trim((string) $data['description']), 'amount' => $newAmount, 'tax_rate' => $newRate],
                    'new_total' => $totals['total'],
                    'summary' => 'Edited a line on ' . ($invoice->invoice_number ?: ('#' . $invoiceId))
                        . ': "' . $line->description . '" $' . number_format($oldAmount, 2)
                        . ' -> "' . trim((string) $data['description']) . '" $' . number_format($newAmount, 2)
                        . '; total is now $' . number_format($totals['total'], 2) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the edit over its own audit row */ }

        return response()->json(['status' => 'updated'] + $totals);
    }

    /* THE INVOICE'S OWN DESCRIPTION. Accounting lists this in its Description column and
       had no way to change it for a KiddieTrac invoice - the Edit beside it patches the
       synced iLearn copy, which this is not in.

       Only the note. Deliberately NOT the totals: money on this invoice moves by adding,
       editing or removing a LINE, so that every change to a figure has a line explaining
       it. A free-hand total edit would let the bottom of the invoice disagree with the
       lines printed above it, with nothing to say why. */
    public function updateNotes(Request $request, int $invoiceId): JsonResponse
    {
        $data = $request->validate([
            'notes' => 'nullable|string|max:1000',
        ]);

        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'That invoice no longer exists.');
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        $notes = trim((string) ($data['notes'] ?? ''));
        DB::table('invoices')->where('id', $invoiceId)
            ->update(['notes' => $notes !== '' ? $notes : null, 'updated_at' => now()]);

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'invoice.description_updated',
                'entity_type' => 'invoice',
                'entity_id' => $invoiceId,
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'from' => $invoice->notes,
                    'to' => $notes,
                    'summary' => 'Changed the description on ' . ($invoice->invoice_number ?: ('#' . $invoiceId))
                        . ' to "' . $notes . '".',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the edit over its own audit row */ }

        return response()->json(['status' => 'updated', 'notes' => $notes]);
    }

    /* Remove a line. Only one this invoice owns, and never the last one - an invoice
       with no lines is a total with nothing behind it. */
    public function deleteLine(Request $request, int $invoiceId, int $lineId): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'That invoice no longer exists.');
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        if ((string) $invoice->status === 'void') {
            return response()->json(['message' => 'That invoice is void.'], 422);
        }

        $line = DB::table('invoice_lines')->where('id', $lineId)->where('invoice_id', $invoiceId)->first();
        abort_unless($line, 404, 'No such line on this invoice.');

        if (DB::table('invoice_lines')->where('invoice_id', $invoiceId)->count() <= 1) {
            return response()->json([
                'message' => 'An invoice needs at least one line. Void the invoice instead.',
            ], 422);
        }

        DB::table('invoice_lines')->where('id', $lineId)->delete();
        // The same delta, negated: removing a line takes back exactly what adding it put on.
        $totals = $this->applyLineDelta($invoiceId, -1 * (float) $line->amount,
            $line->tax_rate === null ? null : (float) $line->tax_rate);

        try {
            \App\Support\Audit::write([
                'agency_id' => DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id'),
                'user_id' => $request->user()->id,
                'action' => 'invoice.line_removed',
                'entity_type' => 'invoice',
                'entity_id' => $invoiceId,
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'description' => $line->description,
                    'amount' => (float) $line->amount,
                    'new_total' => $totals['total'],
                    'summary' => 'Removed "' . $line->description . '" ($'
                        . number_format((float) $line->amount, 2) . ') from '
                        . ($invoice->invoice_number ?: ('#' . $invoiceId))
                        . '; total is now $' . number_format($totals['total'], 2) . '.',
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the edit over its own audit row */ }

        return response()->json(['status' => 'removed'] + $totals);
    }

    /* THE LINES ARE NOT THE SOURCE OF TRUTH FOR THE SUBTOTAL (2026-09-17).

       This first rebuilt every figure from the lines, which is the textbook answer and
       is WRONG for this schema. Checked before shipping it: **13 of 25 invoices have
       lines that do not sum to their stored subtotal.** A CWELCC invoice is the clearest
       case — subtotal 1450.00, subsidy 435.00, and one line reading
       "Infant tuition (July 2026, CWELCC-adjusted) = 1015.00", the NET figure. Rebuilding
       from that line would have set subtotal to 1015, then subtracted the subsidy again
       and made a $1,015.00 invoice say $580.00. Adding a $25 late fee would have silently
       taken $435 off the family's bill.

       So a line applies a DELTA to figures that are already correct, rather than
       replacing them with a recomputation that assumes an invariant this data does not
       hold. The delta comes from the line itself in the same request that wrote it, so
       there is nothing to drift out of step — and the untouched part of the invoice is
       left exactly as whatever raised it intended.

       Subsidy and discount are never touched: they are computed elsewhere, from
       enrolment and sibling rules, and are not line items. */
    private function applyLineDelta(int $invoiceId, float $amount, ?float $taxRate): array
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first([
            'subtotal', 'tax_amount', 'subsidy_amount', 'discount_amount', 'amount_paid', 'status',
        ]);

        $taxDelta = ($taxRate !== null && $taxRate > 0) ? ($amount * ($taxRate / 100)) : 0.0;

        $subtotal = round((float) ($inv->subtotal ?? 0) + $amount, 2);
        $tax = round((float) ($inv->tax_amount ?? 0) + $taxDelta, 2);
        $total = round($subtotal - (float) ($inv->subsidy_amount ?? 0)
            - (float) ($inv->discount_amount ?? 0) + $tax, 2);
        $paid = (float) ($inv->amount_paid ?? 0);
        // Never negative: an overpaid invoice owes nothing, it does not owe backwards.
        $balance = round(max(0, $total - $paid), 2);

        $update = [
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'total' => $total,
            'balance_due' => $balance,
            'updated_at' => now(),
        ];

        /* A paid invoice that grows a charge is no longer paid, and one whose balance
           reaches zero is. Left alone otherwise: draft stays draft, void never reaches
           here, and 'sent' vs 'overdue' is derived at display time
           ([[kiddietrac-invoice-scheduled-status]]), not stored. */
        $status = (string) ($inv->status ?? '');
        if ($status === 'paid' && $balance > 0.005) { $update['status'] = 'sent'; }
        if ($balance <= 0.005 && $paid > 0 && in_array($status, ['sent', 'open', 'overdue', 'unpaid'], true)) {
            $update['status'] = 'paid';
        }

        DB::table('invoices')->where('id', $invoiceId)->update($update);

        return [
            'subtotal' => $subtotal,
            'tax_amount' => $tax,
            'subsidy_amount' => (float) ($inv->subsidy_amount ?? 0),
            'discount_amount' => (float) ($inv->discount_amount ?? 0),
            'total' => $total,
            'amount_paid' => $paid,
            'balance_due' => $balance,
            'status' => $update['status'] ?? $status,
        ];
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

                $invCentreId = (int) $data['centre_id'];
                Mail::html($mailHtml, function ($m) use ($emails, $num, $pdf, $invCentreId) {
                    \App\Support\MailScope::centre($m, $invCentreId);
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

        /* Resolved ONCE, not per family: numbering is an agency-wide convention and
           this sits above a loop that can raise dozens of invoices. */
        $numberingAgencyId = (int) DB::table('centres')->where('id', $centreId)->value('agency_id') ?: null;

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

            /* $siblingTiers and $emailQueue were read and written inside without being
               imported, and NEITHER threw — which is why this was never reported:

                 · `empty($siblingTiers)` on an undefined variable is quietly true,
                   so the sibling-discount branch never ran. Families with two or
                   more children were billed full price, silently.
                 · `$emailQueue[] = $invoiceId` created a NEW array local to the
                   closure. The foreach after the transaction reads the outer one,
                   which stayed empty, so generated invoices were never emailed.

               $emailQueue by reference because it is filled here and read after the
               transaction commits. (2026-09-10) */
            DB::transaction(function () use ($familyId, $childEnrollments, $centreId, $issueDate, $dueDate, $siblingTiers, &$emailQueue, &$generated) {
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
                /* Through the agency's numbering convention. This one carried NO
                   sequence at all, so two batches for the same family in the same month
                   produced the same string twice; InvoiceNumber::deduplicate() closes
                   that even on a format without {SEQ}. */
                $invoiceNumber = \App\Services\InvoiceNumber::next($numberingAgencyId, [
                    'date' => now(), 'family_id' => $familyId,
                ]);

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
                    \App\Support\Notify::write([
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
            // The agency's chosen template — see InvoiceDocument.
            $pdf = \App\Services\InvoiceDocument::pdf($invoiceId);
        } catch (\Throwable $e) {
            Log::warning('invoice PDF failed for email', ['invoice' => $invoiceId, 'error' => $e->getMessage()]);
        }

        $invCentreId = (int) ($inv->centre_id ?? 0);
        Mail::html($html, function ($m) use ($emails, $num, $pdf, $invCentreId) {
            \App\Support\MailScope::centre($m, $invCentreId ?: null);
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
    /* ISSUE A DRAFT EARLY - the manual counterpart to invoices:issue-scheduled (2026-09-17).

       "how do we take invoices out of draft status?"

       Until now there was no answer a person could act on. A payment schedule raises
       every instalment as a DRAFT dated the first of the month it falls due in, and the
       06:00 cron flips each one to 'sent' when that morning arrives. Correct, but it left
       an admin looking at a column of DRAFT badges with nothing to press and no way to
       issue one today - so this is that button, doing exactly what the cron does to one
       invoice, on demand.

       DRAFT ONLY, AND FORWARD ONLY. A draft is the one status nothing in the platform
       reads as owed: it is absent from the family balance, from Outstanding, and from
       every reminder. Issuing is therefore the moment the money becomes real, which is
       why it is deliberate and audited, and why this refuses anything that is not still
       a draft rather than quietly "re-issuing" a sent, paid or voided invoice.

       ISSUED_AT MOVES TO TODAY. The date was a promise about when the invoice would go
       out; issuing it early makes that promise wrong, and an invoice stamped 1 October
       that the family can see on 17 September is how a ledger starts lying. The DUE date
       is untouched - the family agreed to it and it is what every reminder counts from.

       It does NOT email. Neither does the cron: issuing makes the invoice owed and
       visible, and sending it is a separate, explicit action on the invoice. */
    public function issue(Request $request, int $invoiceId): JsonResponse
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404);
        $this->assertStaffForFamily($request, (int) $invoice->family_id);

        if ((string) $invoice->status !== 'draft') {
            return response()->json([
                'message' => 'Only a draft can be issued. ' . ($invoice->invoice_number ?: ('#' . $invoiceId))
                    . ' is already ' . $invoice->status . '.',
            ], 422);
        }

        $today = now()->toDateString();
        $wasScheduledFor = $invoice->issued_at ? substr((string) $invoice->issued_at, 0, 10) : null;
        $early = $wasScheduledFor && $wasScheduledFor > $today;

        /* Guarded on draft inside the write as well: the 06:00 run and a director
           pressing the button in the same second must not both issue it. */
        $ok = DB::table('invoices')->where('id', $invoiceId)->where('status', 'draft')->update([
            'status' => 'sent',
            'issued_at' => $early ? $today : $invoice->issued_at,
            // Null here means the cron or an import did it; a person's id means a person.
            'issued_by_user_id' => $request->user()->id,
            'updated_at' => now(),
        ]);
        if (! $ok) {
            return response()->json(['message' => 'That invoice was issued a moment ago by someone else.'], 422);
        }

        try {
            $fam = DB::table('families')->where('id', $invoice->family_id)->value('family_name');
            $agencyId = DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id');
            \App\Support\Audit::write([
                'agency_id' => $agencyId,
                'user_id' => $request->user()->id,
                'action' => 'invoice.issued_manually',
                'entity_type' => 'invoice',
                'entity_id' => $invoiceId,
                'payload' => json_encode([
                    'invoice_number' => $invoice->invoice_number,
                    'family_id' => (int) $invoice->family_id,
                    'family' => $fam,
                    'total' => (float) $invoice->total,
                    'due_at' => $invoice->due_at,
                    'was_scheduled_for' => $wasScheduledFor,
                    'issued_early' => $early,
                    'summary' => 'Issued ' . ($invoice->invoice_number ?: ('#' . $invoiceId))
                        . ' ($' . number_format((float) $invoice->total, 2) . ') to ' . ($fam ?: ('family ' . $invoice->family_id))
                        . ' by hand' . ($early ? ' - ' . $wasScheduledFor . ' ahead of its scheduled issue date.' : '.'),
                ]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) { /* never fail the issue over its own audit row */ }

        foreach (DB::table('guardians')->where('family_id', $invoice->family_id)->pluck('user_id') as $gid) {
            try {
                \App\Support\Notify::write([
                    'user_id' => $gid, 'type' => 'invoice',
                    'title' => 'New invoice',
                    'body' => ($invoice->invoice_number ?: 'An invoice') . ' for $'
                        . number_format((float) $invoice->total, 2) . ' is now due '
                        . ($invoice->due_at ? substr((string) $invoice->due_at, 0, 10) : 'shortly') . '.',
                    'data' => json_encode(['link' => '#billing', 'invoice_id' => $invoiceId]),
                    'created_at' => now(),
                ]);
            } catch (\Throwable $e) { /* a notification must not fail the issue */ }
        }

        return response()->json([
            'status' => 'issued',
            'issued_at' => $early ? $today : $invoice->issued_at,
            'message' => ($invoice->invoice_number ?: ('#' . $invoiceId)) . ' is now issued'
                . ($early ? ' (' . $wasScheduledFor . ' ahead of schedule)' : '')
                . ' and owed by the family. Email it separately if they should get a copy.',
        ]);
    }

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

        /* A SCHEDULE'S INSTALMENT DIES WITH ITS INVOICE (2026-09-17).

           Voiding the invoice used to leave the payment_plan_installments row `pending`,
           so the schedule went on counting money that no longer existed: the card's
           total and instalment count disagreed with the invoices underneath it, and the
           06:00 issue run would happily raise the next one as though nothing happened.
           Scoped to THIS invoice, so a hand-made invoice no schedule owns is untouched. */
        $planIds = DB::table('payment_plan_installments')->where('invoice_id', $invoiceId)
            ->where('status', 'pending')->pluck('payment_plan_id')->unique();
        if ($planIds->isNotEmpty()) {
            DB::table('payment_plan_installments')->where('invoice_id', $invoiceId)
                ->where('status', 'pending')->update(['status' => 'cancelled']);
            foreach ($planIds as $pid) {
                /* The stored total is a cache of the live instalments; recomputed here so
                   it cannot drift from what the rows actually say. */
                $live = DB::table('payment_plan_installments')->where('payment_plan_id', $pid)
                    ->where('status', '!=', 'cancelled')->get(['amount']);
                DB::table('payment_plans')->where('id', $pid)->update([
                    'total_amount' => round($live->sum(function ($r) { return (float) $r->amount; }), 2),
                    'installment_count' => $live->count(),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->auditVoid($request, $invoice, (string) ($data['reason'] ?? ''));
        $this->notifyVoided($request, $invoice, (string) ($data['reason'] ?? ''));

        return response()->json([
            'message' => 'Invoice ' . ($invoice->invoice_number ?: ('#' . $invoiceId)) . ' has been voided.',
            'status' => 'void',
        ]);
    }

    /* WHO NEEDS TO KNOW AN INVOICE WAS CANCELLED (2026-09-17).

       Anthony: "add a void function ... and emails sent on voided invoices to
       admins/directors and parent (with appropriate wording)."

       TWO AUDIENCES, TWO LETTERS. A parent needs reassurance and one clear instruction:
       this is cancelled, you do not owe it, ignore any reminder that already went out,
       and do not pay it if you were about to. An admin needs the facts: which invoice,
       how much, who voided it and why. One letter to both would either alarm the family
       with internal detail or leave the office without the detail it needs.

       A BLANK REASON IS NOT SHOWN TO A PARENT. The reason is an internal note, and
       "No reason given" reads as suspicious in a customer-facing email; the parent
       letter simply omits it while the staff letter says plainly that none was recorded.

       IT NEVER FAILS THE VOID. The money question is settled by the time this runs, so
       an unreachable mail server must not leave an invoice half-voided. Every send is
       individually guarded, for the same reason the audit write is.

       The agency mail gate is honoured ([[kiddietrac-mail-gate-agency-scoping]]): an
       agency with notifications off sends nothing, rather than sending "quietly". */
    /* COMPOSING the two letters, separately from SENDING them (2026-09-17).

       Pure: it reads the invoice and returns HTML. Nothing is mailed, so the exact
       wording can be rendered and reviewed against any invoice without a real family
       receiving anything - which matters, because Test Agency has email switched off
       and would otherwise leave this text unverifiable anywhere but production.

       TWO AUDIENCES, TWO LETTERS. A parent needs reassurance and one clear instruction:
       this is cancelled, you do not owe it, ignore any reminder that already went out,
       do not pay it if you were about to. An admin needs the facts: which invoice, how
       much, who voided it and why. One letter to both would either alarm the family with
       internal detail or leave the office without the detail it needs.

       A BLANK REASON IS NOT SHOWN TO A PARENT. The reason is an internal note, and
       "no reason given" reads as suspicious in a customer-facing email; the parent
       letter omits it entirely while the staff letter says plainly that none was
       recorded.

       @return array{parent:string,staff:string,subject_parent:string,subject_staff:string,agency_id:int}|null
     */
    private function voidLetters($invoice, string $reason, string $actor): ?array
    {
        $invoiceId = (int) $invoice->id;
        $number = (string) ($invoice->invoice_number ?: ('#' . $invoiceId));
        $money = '$' . number_format((float) $invoice->total, 2);
        /* An EXTERNAL invoice carries agency_id directly and has no centre_id; a
           KiddieTrac one is the other way round. Either resolves the same agency, and
           the letters below do not care which table the row came from. */
        $agencyId = (int) ($invoice->agency_id
            ?? DB::table('centres')->where('id', $invoice->centre_id ?? 0)->value('agency_id'));
        if (! $agencyId) { return null; }

        $agencyName = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'your childcare provider');
        $familyName = (string) (DB::table('families')->where('id', $invoice->family_id)->value('family_name') ?: 'this family');
        $due = $invoice->due_at ? Carbon::parse($invoice->due_at)->format('j F Y') : null;
        $esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

        $cap = 'font-size:12px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;color:#64748B;';
        $val = 'font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 10px;';
        $facts = '<tr><td style="padding:6px 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="' . $cap . '">Invoice</div><div style="' . $val . '">' . $esc($number) . '</div>'
            . '<div style="' . $cap . '">Amount</div>'
            . '<div style="font-size:16px;font-weight:700;color:#0F172A;margin:2px 0 ' . ($due ? '10px' : '0') . ';">' . $esc($money) . '</div>'
            . ($due ? '<div style="' . $cap . '">Was due</div><div style="font-size:15px;color:#0F172A;margin-top:2px;">' . $esc($due) . '</div>' : '')
            . '</div></td></tr>';

        $parentBody = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
            . 'We are writing to let you know that the invoice below has been <strong>cancelled</strong> by '
            . $esc($agencyName) . '.</td></tr>'
            . $facts
            . '<tr><td style="padding:14px 0 0;font-size:15px;line-height:1.6;color:#334155;">'
            . '<strong>There is nothing you need to do.</strong> This amount is no longer owed and has been '
            . 'removed from your balance. If you have already had a reminder about it, please disregard that '
            . 'reminder, and if you were about to pay it, there is no longer any need.'
            . '</td></tr>'
            . '<tr><td style="padding:12px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'If you have already paid this invoice, the amount will be returned to you or applied to another '
            . 'invoice, and we will be in touch. If a replacement invoice is needed you will receive it '
            . 'separately. Any questions, just reply to this email.</td></tr>'
            . '</table>';

        $staffBody = '<table width="100%" cellpadding="0" cellspacing="0" border="0">'
            . '<tr><td style="padding:0 0 14px;font-size:15px;line-height:1.6;color:#334155;">'
            . 'An invoice for <strong>' . $esc($familyName) . '</strong> has been voided. The family has been '
            . 'told it is cancelled and that no payment is due.</td></tr>'
            . $facts
            . '<tr><td style="padding:14px 0 0;"><div style="background:#F1F5F9;border-radius:10px;padding:14px 16px;">'
            . '<div style="' . $cap . '">Voided by</div>'
            . '<div style="font-size:15px;color:#0F172A;margin:2px 0 10px;">' . $esc($actor ?: 'a staff member') . '</div>'
            . '<div style="' . $cap . '">Reason</div>'
            . '<div style="font-size:15px;color:#0F172A;margin-top:2px;">'
            . ($reason !== '' ? $esc($reason) : '<span style="color:#94A3B8;">No reason recorded</span>')
            . '</div></div></td></tr>'
            . '<tr><td style="padding:14px 0 0;font-size:14px;line-height:1.6;color:#64748B;">'
            . 'The invoice keeps its number and stays in Accounting marked Void, so the trail is intact. If it '
            . 'belonged to a payment schedule, that instalment has been withdrawn and the schedule total '
            . 'adjusted to match.</td></tr>'
            . '</table>';

        return [
            'agency_id' => $agencyId,
            'parent' => \App\Services\EmailTemplate::wrap($agencyId, $parentBody, [
                'eyebrow' => 'INVOICE CANCELLED',
                'title' => 'Invoice ' . $number . ' has been cancelled',
                'subtitle' => $money . ' is no longer owed',
                'preheader' => 'Invoice ' . $number . ' for ' . $money . ' has been cancelled. Nothing to do.',
            ]),
            'staff' => \App\Services\EmailTemplate::wrap($agencyId, $staffBody, [
                'eyebrow' => 'INVOICE VOIDED - STAFF',
                'title' => $number . ' voided for ' . $familyName,
                'subtitle' => $money . ', voided by ' . ($actor ?: 'a staff member'),
                'preheader' => $number . ' (' . $money . ') voided for ' . $familyName . '.',
            ]),
            'subject_parent' => 'Invoice ' . $number . ' has been cancelled - nothing to do',
            'subject_staff' => 'Invoice voided: ' . $number . ' - ' . $familyName,
        ];
    }

    /* SENDING them. Resolves who gets what and mails it.

       It never fails the void: the money question is settled by the time this runs, so
       an unreachable mail server must not leave an invoice half-voided. Every send is
       individually guarded, for the same reason the audit write is.

       The agency mail gate is honoured - an agency with notifications off sends nothing
       rather than sending "quietly" ([[kiddietrac-mail-gate-agency-scoping]]). */
    private function notifyVoided(Request $request, $invoice, string $reason): void
    {
        try {
            $agencyId = (int) ($invoice->agency_id
                ?? DB::table('centres')->where('id', $invoice->centre_id ?? 0)->value('agency_id'));
            if (! $agencyId || ! \App\Support\Suppression::agencyNotificationsEnabled($agencyId)) { return; }

            $actor = trim((string) (($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')));
            $letters = $this->voidLetters($invoice, $reason, $actor);
            if (! $letters) { return; }

            $guardians = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $invoice->family_id)->whereNotNull('u.email')
                ->get(['u.email', 'u.first_name', 'u.last_name'])->unique('email');

            foreach ($guardians as $g) {
                try {
                    $name = trim(($g->first_name ?? '') . ' ' . ($g->last_name ?? ''));
                    \App\Services\AgencyMailer::forAgency($agencyId)->html($letters['parent'],
                        function ($m) use ($g, $name, $letters) {
                            $m->to($g->email, $name ?: null)->subject($letters['subject_parent']);
                        });
                } catch (\Throwable $inner) { report($inner); }
            }

            $staff = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', 1)
                ->whereIn('ra.role', ['agency_admin', 'centre_director'])
                ->whereNotNull('u.email')
                ->get(['u.email', 'u.first_name', 'u.last_name'])->unique('email');

            foreach ($staff as $u) {
                try {
                    $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
                    \App\Services\AgencyMailer::forAgency($agencyId)->html($letters['staff'],
                        function ($m) use ($u, $name, $letters) {
                            $m->to($u->email, $name ?: null)->subject($letters['subject_staff']);
                        });
                } catch (\Throwable $inner) { report($inner); }
            }
        } catch (\Throwable $outer) {
            /* The void itself already succeeded; a failure here is a notification
               problem, not a billing one. */
            report($outer);
        }
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
            /* THE PROCESSOR'S CUT, PASSED ON (2026-09-17). Off unless asked for: a
               surcharge is a charge, and adding one because a rate happens to be
               configured would bill families nobody decided to bill. */
            'add_surcharge' => ['nullable', 'boolean'],
        ]);

        /* Added BEFORE the payment is recorded, so the payment clears an invoice that
           already includes the fee. The other order leaves the invoice briefly paid and
           then unpaid again, which is what a parent's app would show. */
        /* THE AMOUNT ENTERED IS WHAT IS BEING SETTLED, and the fee is charged ON it. The
           payment then recorded is settled + fee, because that is what the family actually
           handed over and what has to clear the invoice.

           Computing the fee on the entered figure and recording only that figure looked
           right and was not: settling a $500 balance left $14.50 owing, and a user who
           "helpfully" typed the fee-inclusive $514.50 got 2.9% of THAT ($14.92) and was
           left owing $0.42. Either way the invoice never reached zero. */
        $surcharge = 0.0;
        if (! empty($data['add_surcharge'])) {
            $agencyId = (int) DB::table('centres')->where('id', $invoice->centre_id)->value('agency_id');
            $pct = \App\Services\PaymentSurcharge::percentFor($agencyId, (string) $data['method']);
            $surcharge = \App\Services\PaymentSurcharge::feeOn((float) $data['amount'], $pct);

            if ($surcharge > 0.005) {
                DB::table('invoice_lines')->insert([
                    'invoice_id' => $invoiceId,
                    'description' => \App\Services\PaymentSurcharge::lineLabel((string) $data['method'], $pct),
                    'line_type' => 'adjustment',
                    'quantity' => 1,
                    'unit_amount' => $surcharge,
                    'amount' => $surcharge,
                ]);
                $this->applyLineDelta($invoiceId, $surcharge, null);
                // The invoice moved, so the figures the rest of this method works from must.
                $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
                // ...and so does the payment: the family paid the fee too.
                $data['amount'] = round((float) $data['amount'] + $surcharge, 2);
            }
        }

        DB::transaction(function () use ($invoiceId, $invoice, $data, $request) {
            DB::table('payments')->insert([
                'invoice_id' => $invoiceId,
                'family_id' => $invoice->family_id,
                'amount' => $data['amount'],
                'method' => self::paymentMethodValue((string) $data['method']),
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
                /* AMOUNT_PAID WAS NEVER WRITTEN (found 2026-09-17).

                   This updated the balance and the status and left `amount_paid` at 0,
                   so a fully paid invoice read "total $514.61, paid $0.00, balance $0.00"
                   - which is what Accounting's PAID column shows, and what the receipt
                   and the invoice document read. Worse, applyLineDelta() derives the new
                   balance from amount_paid, so adding a line to an invoice with payments
                   against it recomputed the balance as though nothing had been paid.

                   Derived from the payment rows, not incremented, so it cannot drift. */
                'amount_paid' => $totalPaid,
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
                if (!empty($rows)) \App\Support\Notify::write($rows);
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
            /* Added 2026-09-17: the edit dialog shows a tax line and what has been paid,
               and both were simply missing from this shape - tax_amount came back null
               on an invoice that plainly carried tax. */
            'discount_amount' => (float) ($i->discount_amount ?? 0),
            'tax_amount' => (float) ($i->tax_amount ?? 0),
            'amount_paid' => (float) ($i->amount_paid ?? 0),
            /* Accounting's Description column reads this, and the edit dialog now writes
               it — it was never returned, so the field reloaded empty after every save
               and looked as though the save had not taken. */
            'notes' => $i->notes ?? null,
            'total' => (float) $i->total,
            'balance_due' => (float) ($i->balance_due ?? $i->total),
            'status' => $i->status,
            // Not due yet reads "Scheduled" — see App\Support\InvoiceStatus.
            'status_label' => InvoiceStatus::label(
                (string) $i->status, $i->due_at, (float) ($i->balance_due ?? $i->total)
            ),
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
