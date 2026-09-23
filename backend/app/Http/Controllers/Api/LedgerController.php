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

/**
 * v22p58 — Family ledger with running balance + statement PDF on demand.
 * Computed on-the-fly from invoices, payments, and refunds.
 */
final class LedgerController extends Controller
{
    use ResolvesCentreContext;

    public function familyLedger(Request $request, int $familyId): JsonResponse
    {
        $this->assertAccess($request, $familyId);
        $family = DB::table('families')->where('id', $familyId)->first();
        abort_unless($family, 404);

        $entries = collect();

        // Invoices = debits
        DB::table('invoices')->where('family_id', $familyId)
            ->orderBy('issued_at')
            ->select('id', 'invoice_number', 'issued_at', 'due_at', 'total', 'status', 'period_start', 'period_end')
            ->get()->each(function ($inv) use ($entries) {
                $daysLate = 0;
                $unpaid = ! in_array(strtolower((string) $inv->status), ['paid', 'void', 'cancelled', 'refunded']);
                if ($unpaid && $inv->due_at && Carbon::parse($inv->due_at)->isPast()) {
                    $daysLate = (int) Carbon::parse($inv->due_at)->startOfDay()->diffInDays(now()->startOfDay());
                }
                $entries->push([
                    'date' => $inv->issued_at,
                    'type' => 'invoice',
                    'invoice_id' => $inv->id,
                    'reference' => $inv->invoice_number,
                    'description' => 'Invoice ' . $inv->invoice_number
                        . ($inv->period_start ? ' (' . Carbon::parse($inv->period_start)->format('M j') . ' - ' . Carbon::parse($inv->period_end)->format('M j') . ')' : ''),
                    'debit' => (float) $inv->total,
                    'credit' => 0,
                    'status' => $inv->status,
                    'due_at' => $inv->due_at,
                    'days_late' => $daysLate,
                ]);
            });

        /* EXTERNAL INVOICES — for iLearn this is where the money actually is.
           Of 47 iLearn families, exactly ONE has a row in `invoices`; 38 have rows in
           `external_invoices` (462 of them). This builder only ever read `invoices`, so
           every one of those parents opened their account ledger to no transactions and
           a zero in every total. Same shape as the outstanding-balance work: owed money
           spans two tables that use different status words.

           There are also NO `payments` rows for iLearn — what was received is
           `amount_paid` on the invoice itself — so the credit is taken from there rather
           than from the payments table, which would have contributed nothing.

           Deduped by invoice number so a family holding both an internal and an imported
           copy of one invoice is not billed twice on screen; the internal row wins
           because it is the one with a PDF and a payable action behind it. */
        $internalNumbers = DB::table('invoices')->where('family_id', $familyId)
            ->pluck('invoice_number')->filter()->map(fn ($v) => (string) $v)->all();

        DB::table('external_invoices')->where('family_id', $familyId)
            ->orderBy('issued_at')
            ->select('id', 'number', 'issued_at', 'due_at', 'total', 'amount_paid',
                     'balance_due', 'status', 'description', 'external_updated_at')
            ->get()->each(function ($inv) use ($entries, $internalNumbers) {
                // A void invoice is not money owed and never was.
                if (in_array(strtolower((string) $inv->status), ['void', 'cancelled', 'voided'], true)) {
                    return;
                }
                if ($inv->number && in_array((string) $inv->number, $internalNumbers, true)) {
                    return;
                }

                $issued = $inv->issued_at ?: ($inv->due_at ?: $inv->external_updated_at);
                $daysLate = 0;
                if ((float) $inv->balance_due > 0.005 && $inv->due_at && Carbon::parse($inv->due_at)->isPast()) {
                    $daysLate = (int) Carbon::parse($inv->due_at)->startOfDay()->diffInDays(now()->startOfDay());
                }

                $entries->push([
                    'date' => $issued,
                    'type' => 'invoice',
                    // Deliberately null: the per-invoice PDF and email actions are routes
                    // over OUR invoices table, and would 404 for one of these.
                    'invoice_id' => null,
                    'reference' => $inv->number,
                    'description' => 'Invoice ' . $inv->number
                        . ($inv->description ? ' — ' . $inv->description : ''),
                    'debit' => (float) $inv->total,
                    'credit' => 0,
                    'status' => $inv->status,
                    'due_at' => $inv->due_at,
                    'days_late' => $daysLate,
                ]);

                /* SETTLED = total - balance_due, NOT amount_paid.
                   The provider's amount_paid cannot be trusted: on 20 of 402 live
                   invoices it exceeds the invoice total, several at exactly double it
                   (a $189 invoice carrying $378 "paid"). Crediting that would have told
                   parents they had paid $2,860.74 more than they actually had, and a
                   ledger that overstates what somebody has paid is worse than one that
                   shows nothing. balance_due agrees with each invoice's own status in
                   every case, so deriving the credit from it makes every row net to
                   exactly what the provider says is still owed. */
                $paid = round((float) $inv->total - (float) $inv->balance_due, 2);
                if ($paid > 0.005) {
                    /* The provider records what was paid but not when. external_updated_at
                       is the closest thing to a settlement date; it is used only when it
                       is not BEFORE the invoice, so the running balance can never show a
                       payment arriving ahead of the charge it settles. */
                    $paidAt = $issued;
                    if ($inv->external_updated_at && $issued
                        && Carbon::parse($inv->external_updated_at)->gte(Carbon::parse($issued))) {
                        $paidAt = $inv->external_updated_at;
                    }
                    $entries->push([
                        'date' => $paidAt,
                        'type' => 'payment',
                        'reference' => $inv->number,
                        'description' => 'Payment received — ' . $inv->number,
                        'debit' => 0,
                        'credit' => $paid,
                        'status' => 'received',
                    ]);
                }
            });

        // Payments = credits
        DB::table('payments')->where('family_id', $familyId)
            ->whereNotNull('paid_at')
            ->orderBy('paid_at')
            ->select('id', 'paid_at', 'amount', 'method', 'reference_number')
            ->get()->each(function ($p) use ($entries) {
                $entries->push([
                    'date' => $p->paid_at,
                    'type' => 'payment',
                    'reference' => $p->reference_number,
                    'description' => 'Payment via ' . ($p->method ?: 'manual'),
                    'debit' => 0,
                    'credit' => (float) $p->amount,
                    'status' => 'received',
                ]);
            });

        // Refunds = debits (negative credit)
        DB::table('payment_refunds as pr')
            ->join('payments as p', 'p.id', '=', 'pr.payment_id')
            ->where('p.family_id', $familyId)
            ->whereIn('pr.status', ['succeeded', 'pending', 'manual'])
            ->orderBy('pr.refunded_at')
            ->select('pr.refunded_at', 'pr.amount', 'pr.reason')
            ->get()->each(function ($r) use ($entries) {
                $entries->push([
                    'date' => $r->refunded_at,
                    'type' => 'refund',
                    'reference' => null,
                    'description' => 'Refund: ' . ($r->reason ?: 'other'),
                    'debit' => (float) $r->amount,
                    'credit' => 0,
                    'status' => 'refunded',
                ]);
            });

        // Sort + running balance
        $sorted = $entries->sortBy('date')->values();
        $balance = 0;
        $sorted = $sorted->map(function ($e) use (&$balance) {
            $balance += $e['debit'] - $e['credit'];
            $e['running_balance'] = round($balance, 2);
            return $e;
        });

        return response()->json([
            'family' => [
                'id' => $family->id,
                'family_name' => $family->family_name,
                'primary_email' => $family->primary_email,
            ],
            'data' => $sorted,
            'current_balance' => round($balance, 2),
            'total_invoiced' => round($sorted->sum('debit'), 2),
            'total_paid' => round($sorted->where('type', 'payment')->sum('credit'), 2),
            'total_refunded' => round($sorted->where('type', 'refund')->sum('debit'), 2),
            'days_overdue' => (int) ($sorted->where('type', 'invoice')->max('days_late') ?: 0),
        ]);
    }

    public function familyLedgerPdf(Request $request, ?int $familyId = null): \Symfony\Component\HttpFoundation\Response
    {
        // v22p98: the /parent/ledger/pdf route passes no familyId — resolve the
        // signed-in guardian's own family (was a "too few arguments" 500).
        if (! $familyId) {
            /* The statement has to be for the family the parent is LOOKING at, so the
               same family_id the screen is showing is honoured here — validated against
               their own families, never trusted from the query string. */
            $famIds = DB::table('guardians')->where('user_id', $request->user()->id)
                ->pluck('family_id')->filter()->map(fn ($v) => (int) $v)->all();
            $requested = (int) $request->query('family_id', 0);
            $familyId = in_array($requested, $famIds, true) ? $requested : (int) ($famIds[0] ?? 0);
        }
        abort_unless($familyId, 404);
        $this->assertAccess($request, (int) $familyId);
        $resp = $this->familyLedger($request, $familyId);
        $payload = json_decode($resp->getContent(), true);
        $family = $payload['family'];
        $rows = $payload['data'];

        $agencyId = (int) DB::table('families')->where('families.id', $familyId)
            ->join('centres', 'centres.id', '=', 'families.centre_id')->value('centres.agency_id');
        $agency = DB::table('agencies')->where('id', $agencyId)->first();

        $html = view('pdf.ledger', [
            'agency' => $agency,
            'family' => (object) $family,
            'rows' => $rows,
            'balance' => $payload['current_balance'],
            'totals' => $payload,
        ])->render();

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();
        return new \Symfony\Component\HttpFoundation\Response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Statement-' . preg_replace('/[^A-Za-z0-9]/', '-', $family['family_name']) . '-' . now()->format('Y-m-d') . '.pdf"',
        ]);
    }

    public function myLedger(Request $request): JsonResponse
    {
        $u = $request->user();
        /* EVERY family they belong to, not whichever guardians row came back first.
           ->value('family_id') returns one arbitrarily, and two guardians here belong to
           two families each — so one family's invoices were unreachable, with nothing on
           screen to say a second account existed at all. */
        $famIds = DB::table('guardians')->where('user_id', $u->id)
            ->pluck('family_id')->filter()->unique()->map(fn ($v) => (int) $v)->values();
        abort_unless($famIds->isNotEmpty(), 404, 'No family linked');

        /* ONLY families that actually hold money.
           Both multi-family guardians here turned out to be duplicate records rather
           than second households — the same name twice (18/106 "Deborah Black",
           28/107 "Yashika Bajaj"), the duplicate carrying nothing at all. Offering a
           parent two identically-named tabs, one of them blank, reads as a broken
           screen. A family with no invoices and no payments has no ledger to show, so
           it is not something to switch to; the duplicates want merging in the data,
           which is not this endpoint's job to guess at. */
        $withActivity = $famIds->filter(function ($id) {
            return DB::table('invoices')->where('family_id', $id)->exists()
                || DB::table('external_invoices')->where('family_id', $id)->exists()
                || DB::table('payments')->where('family_id', $id)->exists();
        })->values();
        $choosable = $withActivity->isNotEmpty() ? $withActivity : $famIds;

        $requested = (int) $request->query('family_id', 0);
        // A requested family is honoured if it is theirs at all, so a bookmarked link to
        // an empty one still resolves rather than silently showing a different account.
        $famId = $famIds->contains($requested) ? $requested : (int) $choosable->first();

        $payload = json_decode($this->familyLedger($request, $famId)->getContent(), true);
        // The switcher is drawn only when there is more than one real account.
        $listIds = $choosable->contains($famId) ? $choosable : $choosable->concat([$famId])->unique()->values();
        $payload['families'] = DB::table('families')->whereIn('id', $listIds)
            ->orderBy('family_name')->get(['id', 'family_name'])->all();
        $payload['family_id'] = $famId;

        return response()->json($payload);
    }

    /* ─────────────────────────────────────────────────────────────────
       v23 (#34) — Per-invoice actions from the parent ledger kebab:
       View (frontend), Download (PDF), Email-to-myself. Both routes are
       parent-scoped: the invoice's family must be one the signed-in
       guardian belongs to (no centre/agency access needed).
       ───────────────────────────────────────────────────────────────── */

    // Ownership: the signed-in guardian must belong to the invoice's family.
    // Returns the invoice row (or aborts 403/404).
    private function assertGuardianOwnsInvoice(Request $request, int $invoiceId): object
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404, 'Invoice not found');
        $famIds = DB::table('guardians')
            ->where('user_id', $request->user()->id)
            ->pluck('family_id')->filter()->map(fn ($v) => (int) $v)->all();
        abort_unless(in_array((int) $invoice->family_id, $famIds, true), 403);

        return $invoice;
    }

    /* The invoice as the AGENCY has chosen to draw it. This named InvoicePdfRenderer
       directly, so a parent downloading their own invoice got the default KiddieTrac
       document while the agency had chosen iLearn's — the same invoice in two different
       liveries depending on who asked for it. (2026-09-17) */
    private function renderInvoicePdf(int $invoiceId): ?string
    {
        return \App\Services\InvoiceDocument::pdf($invoiceId);
    }

    /* GET /parent/invoices/{invoice}/document — the invoice to LOOK at.
       The PDF above is for keeping; this is what the app shows inline, so a parent sees
       the real document rather than a summary drawn from the same numbers. */
    public function myInvoiceDocument(Request $request, int $invoice): \Symfony\Component\HttpFoundation\Response
    {
        $this->assertGuardianOwnsInvoice($request, $invoice);
        $html = \App\Services\InvoiceDocument::html($invoice, false);
        abort_unless($html !== null, 404, 'Invoice not found');

        return new \Symfony\Component\HttpFoundation\Response($html, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }

    // GET /parent/invoices/{invoice}/pdf — download one invoice as a PDF.
    public function myInvoicePdf(Request $request, int $invoice): \Symfony\Component\HttpFoundation\Response
    {
        $inv = $this->assertGuardianOwnsInvoice($request, $invoice);
        $pdf = $this->renderInvoicePdf($invoice);
        abort_unless($pdf !== null, 404, 'Invoice not found');
        $num = preg_replace('/[^A-Za-z0-9]/', '-', (string) ($inv->number ?? $inv->id));

        return new \Symfony\Component\HttpFoundation\Response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Invoice-' . $num . '.pdf"',
        ]);
    }

    // POST /parent/invoices/{invoice}/email — email the invoice PDF to the
    // signed-in guardian's own address (branded layout, from noreply).
    public function emailMyInvoice(Request $request, int $invoice): JsonResponse
    {
        $inv = $this->assertGuardianOwnsInvoice($request, $invoice);
        $user = $request->user();
        $to = trim((string) ($user->email ?? ''));
        if ($to === '') {
            return response()->json(['message' => 'Your account has no email address on file.'], 422);
        }

        $pdf = $this->renderInvoicePdf($invoice);
        if ($pdf === null) {
            return response()->json(['message' => 'Invoice could not be rendered.'], 500);
        }

        $num = (string) ($inv->number ?? ('#' . $inv->id));
        $first = trim((string) ($user->first_name ?? '')) ?: 'there';
        $balance = (float) ($inv->balance_due ?? 0);
        $agency = DB::table('families')->where('families.id', $inv->family_id)
            ->join('centres', 'centres.id', '=', 'families.centre_id')
            ->leftJoin('agencies', 'agencies.id', '=', 'centres.agency_id')
            ->value('agencies.name');

        $due = $balance > 0.005
            ? '<p style="background:#FEF3C7;color:#92400E;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">Balance due: <strong>$' . number_format($balance, 2) . '</strong></p>'
            : '<p style="background:#ECFDF5;color:#047857;border-radius:8px;padding:12px 16px;font-size:14px;margin:14px 0;">This invoice is paid in full. Thank you! 🎉</p>';

        $content = '<h1>🧾 Your invoice ' . e($num) . '</h1>'
            . '<p>Hi ' . e($first) . ',</p>'
            . '<p>As requested, a copy of your invoice from <strong>' . e($agency ?: 'your childcare provider') . '</strong> is attached to this email as a PDF.</p>'
            . $due
            . '<p style="color:#64748B;font-size:13px;">You requested this copy from your KiddieTrac account. If you didn\'t, you can safely ignore this message.</p>';

        try {
            $html = view('emails.layout', [
                'slot' => $content,
                'title' => 'Your invoice ' . $num,
                'preheader' => 'A copy of your invoice ' . $num . ' is attached.',
            ])->render();

            Mail::html($html, function ($m) use ($to, $num, $pdf) {
                $m->from(config('mail.from.address', 'noreply@kiddietrac.com'), config('mail.from.name', 'KiddieTrac'));
                // Parent-requested copy of their OWN invoice — always deliver.
                $m->getHeaders()->addTextHeader('X-KT-Bypass-Suppression', '1');
                $m->to($to)->subject('Your invoice ' . $num);
                $m->attachData($pdf, 'Invoice-' . preg_replace('/[^A-Za-z0-9]/', '-', $num) . '.pdf', ['mime' => 'application/pdf']);
            });
        } catch (\Throwable $e) {
            Log::warning('Invoice email-to-self failed: ' . $e->getMessage());

            return response()->json(['message' => 'Could not send the email right now. Please try again.'], 500);
        }

        return response()->json(['ok' => true, 'email' => $to]);
    }

    private function assertAccess(Request $request, int $familyId): void
    {
        // SECURITY (v22p96): guardian of THIS family, staff of its centre, OR a
        // platform_admin SCOPED to the agency they've switched into. The prior
        // unconditional `if (isPlatformAdminUser) return;` let a super-admin read
        // any family's ledger in any tenant regardless of the active agency.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
    }
}
