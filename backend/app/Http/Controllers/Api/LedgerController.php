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
        $familyId = $familyId ?: (int) DB::table('guardians')->where('user_id', $request->user()->id)->value('family_id');
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
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 404, 'No family linked');
        return $this->familyLedger($request, (int) $famId);
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

    // Build a PDF (bytes) for one invoice using the white-label renderer.
    private function renderInvoicePdf(int $invoiceId): ?string
    {
        $html = app(InvoicePdfRenderer::class)->renderFromInvoiceId($invoiceId);
        if ($html === null) {
            return null;
        }
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
        $dompdf->render();

        return $dompdf->output();
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
