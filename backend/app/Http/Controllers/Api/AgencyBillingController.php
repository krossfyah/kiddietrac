<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Notify;
use App\Support\PlatformAccounts as A;
use App\Support\PlatformBilling;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * An agency's own KiddieTrac invoices, for its admins only (2026-10-25, Anthony: "Invoices for
 * agency's should show up in the portal for admins only to see"). Routes: role agency_admin |
 * platform_admin, always the ACTIVE agency. Pay early, pay in part (reported, KiddieTrac
 * confirms), prepay the year, download the invoice and every receipt.
 */
class AgencyBillingController extends Controller
{
    use ResolvesCentreContext;

    private function aid(Request $r): int
    {
        $aid = (int) ($this->resolveAgencyId($r) ?: 0);
        abort_unless($aid, 403, 'Choose an agency first.');
        return $aid;
    }

    private function mine(Request $r, int $id): object
    {
        $inv = DB::table('platform_invoices')->where('id', $id)->where('agency_id', $this->aid($r))->whereNotIn('status', ['draft'])->first();
        abort_unless($inv, 404, 'That invoice is not available.');
        return $inv;
    }

    /** GET /admin/kt-invoices */
    public function index(Request $r): JsonResponse
    {
        $aid = $this->aid($r);
        $s = A::settings();
        $rows = DB::table('platform_invoices')->where('agency_id', $aid)->where('status', '!=', 'draft')->orderByDesc('issued_at')->orderByDesc('id')->limit(60)->get();
        $pays = DB::table('platform_invoice_payments')->whereIn('invoice_id', $rows->pluck('id')->all() ?: [0])->orderBy('paid_on')->get()->groupBy('invoice_id');
        $today = \App\Support\AgencyTime::todayDate()->toDateString();
        $list = $rows->map(function ($i) use ($pays, $today) {
            $bal = A::balance($i);
            $pending = (int) collect($pays[$i->id] ?? [])->where('status', 'pending')->sum('amount_cents');
            return ['id' => (int) $i->id, 'number' => $i->number, 'period_start' => $i->period_start, 'period_end' => $i->period_end,
                'issued_at' => $i->issued_at, 'due_at' => $i->due_at, 'currency' => $i->currency, 'amount_cents' => (int) $i->amount_cents, 'tax_rate_bps' => (int) $i->tax_rate_bps,
                'paid_cents' => (int) $i->amount_paid_cents, 'balance_cents' => $bal, 'pending_cents' => $pending, 'status' => $i->status,
                'overdue' => $bal > 0 && $i->due_at && (string) $i->due_at < $today && $i->status !== 'void', 'prepay' => str_ends_with((string) $i->number, '-PY'),
                'payments' => collect($pays[$i->id] ?? [])->map(fn ($p) => ['id' => (int) $p->id, 'amount_cents' => (int) $p->amount_cents, 'method' => A::METHODS[$p->method] ?? $p->method,
                    'reference' => $p->reference, 'paid_on' => $p->paid_on, 'status' => $p->status, 'receipt_number' => $p->receipt_number, 'return_reason' => $p->return_reason ?? null])->values()];
        })->values();
        $owing = $list->filter(fn ($x) => $x['status'] !== 'void')->sum('balance_cents');
        return response()->json(['invoices' => $list, 'owing_cents' => $owing, 'currency' => $rows->first()->currency ?? 'CAD',
            'customer_number' => \App\Support\SalesCustomers::numberForAgency($aid ? (int) $aid : null),   // 2026-11-11
            'payment_instructions' => $s['payment_instructions'], 'terms' => $s['terms_email'], 'prepay_discount_pct' => (float) $s['prepay_discount_pct'],
            // What each method adds, so "I've paid" can show the total to send (2026-10-25).
            'surcharges' => $s['surcharge_enabled'] ? $s['surcharges'] : (object) [], 'surcharge_taxable' => (bool) $s['surcharge_taxable'],
            'surcharge_note' => A::surchargeSentence($s),
            'methods' => A::METHODS, 'can_prepay' => \App\Support\Licences::managed($aid)]);
    }

    /** GET /admin/kt-invoices/{id}/pdf */
    public function pdf(Request $r, int $id)
    {
        $inv = $this->mine($r, $id);
        $pdf = app(\App\Services\PlatformInvoicePdf::class)->render((string) $inv->number);
        abort_unless($pdf, 500, 'The invoice could not be produced.');
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $inv->number . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    /** GET /admin/kt-invoices/receipts/{payment} */
    public function receipt(Request $r, int $payment)
    {
        $p = DB::table('platform_invoice_payments')->where('id', $payment)->where('agency_id', $this->aid($r))->whereIn('status', ['confirmed', 'refund'])->first();
        abort_unless($p, 404, 'That receipt is not available.');
        $pdf = A::receiptPdf((int) $p->id);
        abort_unless($pdf, 500, 'The receipt could not be produced.');
        return response($pdf, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="' . $p->receipt_number . '.pdf"', 'Cache-Control' => 'private, no-store']);
    }

    /** POST /admin/kt-invoices/{id}/report-payment {amount, method, reference?, paid_on?} — pending until KiddieTrac confirms. */
    public function reportPayment(Request $r, int $id): JsonResponse
    {
        $inv = $this->mine($r, $id);
        $d = $r->validate(['amount' => ['required', 'numeric', 'min:0.01'], 'method' => ['required', 'in:' . implode(',', array_keys(A::METHODS))],
            'reference' => ['nullable', 'string', 'max:120'], 'paid_on' => ['nullable', 'date'], 'note' => ['nullable', 'string', 'max:500']]);
        $res = A::record((int) $inv->id, (int) round($d['amount'] * 100), $d['method'], $d['reference'] ?? null, $d['paid_on'] ?? null, (int) $r->user()->id, 'pending', false, $d['note'] ?? null);
        $agency = (string) DB::table('agencies')->where('id', $inv->agency_id)->value('name');
        $amt = PlatformBilling::money((int) round($d['amount'] * 100), $inv->currency);
        $admins = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->unique();
        Notify::write($admins->map(fn ($u) => ['user_id' => (int) $u, 'type' => 'platform_invoice', 'title' => '💳 ' . $agency . ' reported a payment: ' . $amt,
            'body' => 'Invoice ' . $inv->number . ' · ' . (A::METHODS[$d['method']] ?? $d['method']) . ($d['reference'] ?? null ? ' · ' . $d['reference'] : '') . '. Confirm it in Sales → Invoices.',
            'data' => json_encode(['link' => '#sales-invoices']), 'created_at' => now()])->values()->all());
        Audit::write(['user_id' => (int) $r->user()->id, 'agency_id' => $inv->agency_id, 'action' => 'platform_invoice.payment_reported', 'entity_type' => 'platform_invoice', 'entity_id' => $inv->id,
            'payload' => json_encode(['summary' => 'Reported a payment of ' . $amt . ' on invoice ' . $inv->number . ' (waiting for KiddieTrac to confirm)']), 'created_at' => now()]);
        return response()->json(['ok' => true, 'payment_id' => (int) $res['payment']->id]);
    }

    /** POST /admin/kt-invoices/prepay-year — a 12-month prepayment invoice, emailed to the billing contacts. */
    public function prepayYear(Request $r): JsonResponse
    {
        $aid = $this->aid($r);
        $res = A::prepayYear($aid, (int) $r->user()->id);
        $sent = A::send($res['invoice'], (int) $res['invoice']->amount_cents > 0 ? 'prepay' : 'invoice');
        return response()->json(['invoice' => ['id' => (int) $res['invoice']->id, 'number' => $res['invoice']->number, 'amount_cents' => (int) $res['invoice']->amount_cents], 'sent' => $sent], 201);
    }
}
