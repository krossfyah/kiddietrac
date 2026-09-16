<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Refund;
use Stripe\Stripe;

/**
 * v22p58 — Partial + multi-refund support on a single payment.
 * Mirrors Procare's same-day-ACH + partial refund behaviour.
 */
final class RefundsController extends Controller
{
    use ResolvesCentreContext;

    public function __construct()
    {
        if ($k = env('STRIPE_SECRET')) Stripe::setApiKey($k);
    }

    public function listForPayment(Request $request, int $paymentId): JsonResponse
    {
        // SECURITY (v22p96): a payment's refunds are family-private — load the
        // payment and gate on its family (staff of its centre, the family's
        // guardians, or a platform_admin scoped to that agency). Was readable by
        // payment id for any caller.
        $payment = DB::table('payments')->where('id', $paymentId)->first();
        abort_unless($payment, 404);
        abort_unless($this->canAccessFamilyScoped($request, (int) $payment->family_id), 403);
        $rows = DB::table('payment_refunds')->where('payment_id', $paymentId)
            ->orderByDesc('created_at')->get();
        $totalRefunded = $rows->where('status', '!=', 'failed')->sum('amount');
        return response()->json([
            'data' => $rows,
            'payment' => $payment,
            'total_refunded' => $totalRefunded,
            'remaining_refundable' => $payment ? max(0, (float) $payment->amount - (float) $totalRefunded) : 0,
        ]);
    }

    /**
     * v22p98 — recent payments in the active agency, so the Refunds screen can
     * offer a picker instead of forcing the admin to type a raw Payment ID.
     * Scoped to the resolved (header-aware) agency's centres.
     */
    public function recentPayments(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['data' => []]);
        /* The centres this person runs, not every centre in the agency. A director
           at centre 18 was being shown four centre-16 payments complete with family
           names and amounts (2026-09-03). An admin still gets the whole agency. */
        $centreIds = $this->visibleCentreIds($request);
        $rows = DB::table('payments as p')
            ->join('families as f', 'f.id', '=', 'p.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->orderByDesc('p.created_at')
            ->limit(60)
            ->select('p.id', 'p.amount', 'p.created_at', 'p.method', 'p.family_id', 'f.family_name')
            ->get();
        $ids = $rows->pluck('id')->all();
        $refunded = DB::table('payment_refunds')->whereIn('payment_id', $ids ?: [0])
            ->where('status', '!=', 'failed')
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) tot')->pluck('tot', 'payment_id');
        $out = $rows->map(function ($p) use ($refunded) {
            $r = (float) ($refunded[$p->id] ?? 0);
            return [
                'kind' => 'payment',
                'id' => $p->id, 'amount' => (float) $p->amount, 'date' => $p->created_at,
                'method' => $p->method, 'family_name' => $p->family_name,
                'reference' => null,
                'refunded' => $r, 'refundable' => max(0, (float) $p->amount - $r),
            ];
        })->values()->all();

        /* MONEY THAT HAS NO RECEIPT ROW.

           Everything iLearn has received came through the integration, which writes
           external_invoices and no payment row — $40,261.54 across 227 invoices that
           this picker could not see, on the only agency that has any. Offering the
           invoice is the only handle there is on that money; choosing one goes through
           /refunds/invoice, which writes the receipt at that moment.

           An invoice that already HAS a receipt row is skipped: it is listed above as
           the receipt, which carries the real refund history. */
        $haveReceipt = DB::table('payments')->whereNotNull('external_invoice_id')
            ->pluck('external_invoice_id')->all();

        $ext = DB::table('external_invoices as e')
            ->join('families as f', 'f.id', '=', 'e.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])
            ->where('e.amount_paid', '>', 0)
            ->whereNotIn('e.status', ['void', 'voided', 'cancelled'])
            ->when($haveReceipt, fn ($q) => $q->whereNotIn('e.id', $haveReceipt))
            ->orderByDesc('e.due_at')
            ->limit(200)
            ->get(['e.id', 'e.number', 'e.amount_paid', 'e.due_at', 'e.source_label', 'f.family_name']);

        foreach ($ext as $e) {
            $out[] = [
                'kind' => 'external',
                'id' => (int) $e->id,
                'amount' => (float) $e->amount_paid,
                'date' => $e->due_at,
                'method' => $e->source_label ?: 'iLearn',
                'family_name' => $e->family_name,
                'reference' => $e->number,
                'refunded' => 0.0,
                'refundable' => round((float) $e->amount_paid, 2),
            ];
        }

        return response()->json(['data' => array_values(array_filter(
            $out, fn ($x) => $x['refundable'] > 0.005
        ))]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->assertMayRefund($request);

        $data = $request->validate([
            'payment_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:1000',
            /* Required, on every route that reaches here. A refund is the one money
               movement with nobody on the other side confirming it, so it carries the
               deliberate act of somebody putting their name to it — not merely whoever
               happened to be logged in. */
            'signature' => 'required|string|max:400000',
            'signed_name' => 'required|string|max:160',
        ]);

        abort_unless(
            str_starts_with($data['signature'], 'data:image/'),
            422,
            'The signature was not captured. Please sign in the box and try again.'
        );
        $payment = DB::table('payments')->where('id', $data['payment_id'])->first();
        abort_unless($payment, 404);
        $this->assertAccess($request, (int) $payment->family_id);

        /* 'manual' MUST be counted. It is the status every non-Stripe refund gets —
           the entire else-branch below — so leaving it out meant manual refunds did
           not count against the payment and the same money could be refunded over and
           over, each pass reversing more off the invoice than was ever paid. */
        $alreadyRefunded = (float) DB::table('payment_refunds')
            ->where('payment_id', $payment->id)
            ->whereIn('status', ['succeeded', 'pending', 'manual'])
            ->sum('amount');
        $remaining = round((float) $payment->amount - $alreadyRefunded, 2);
        abort_if(
            $data['amount'] > $remaining + 0.005,
            422,
            'Cannot refund $' . number_format((float) $data['amount'], 2)
                . '; only $' . number_format($remaining, 2) . ' of this payment is left to refund.'
        );

        $stripeRefundId = null;
        $status = 'succeeded';
        if (env('STRIPE_SECRET') && !empty($payment->stripe_payment_id)) {
            try {
                $cents = (int) round($data['amount'] * 100);
                $refund = Refund::create([
                    'payment_intent' => $payment->stripe_payment_id,
                    'amount' => $cents,
                    'reason' => match ($data['reason'] ?? '') {
                        'duplicate' => 'duplicate',
                        'fraud', 'fraudulent' => 'fraudulent',
                        default => 'requested_by_customer',
                    },
                    'metadata' => [
                        'payment_id' => (string) $payment->id,
                        'family_id' => (string) $payment->family_id,
                        'initiated_by_user_id' => (string) $request->user()->id,
                        'notes' => substr((string) ($data['notes'] ?? ''), 0, 500),
                    ],
                ]);
                $stripeRefundId = $refund->id;
                $status = $refund->status === 'succeeded' ? 'succeeded' : 'pending';
            } catch (\Throwable $e) {
                Log::warning('Stripe refund failed', ['payment' => $payment->id, 'msg' => $e->getMessage()]);
                return response()->json(['error' => $e->getMessage()], 422);
            }
        } else {
            // manual refund — admin records it for audit only
            $status = 'manual';
        }

        $id = DB::table('payment_refunds')->insertGetId([
            'payment_id' => $payment->id,
            'amount' => $data['amount'],
            'reason' => $data['reason'] ?? null,
            'stripe_refund_id' => $stripeRefundId,
            'refund_method' => $stripeRefundId ? 'stripe' : 'manual',
            'status' => $status,
            'initiated_by_id' => $request->user()->id,
            'refunded_at' => now(),
            'notes' => $data['notes'] ?? null,
            // Who put their name to it, when, and from where.
            'approver_signature_data' => $data['signature'],
            'approver_signed_name' => trim($data['signed_name']),
            'approver_signed_at' => now(),
            'approver_signature_ip' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /* The audit row NAMES what was refunded, not that a refund happened. A count is
           useless to somebody asking six months later which money went back. */
        \App\Support\Audit::write([
            'user_id' => $request->user()->id,
            'agency_id' => $this->resolveAgencyId($request),
            'action' => 'refund.created',
            'entity_type' => 'payment_refund',
            'entity_id' => $id,
            'payload' => json_encode([
                'summary' => 'Refunded $' . number_format((float) $data['amount'], 2)
                    . ' of a $' . number_format((float) $payment->amount, 2) . ' receipt'
                    . ($payment->invoice_id ? ' on invoice #' . $payment->invoice_id : '')
                    . ($payment->external_invoice_id ? ' on external invoice #' . $payment->external_invoice_id : '')
                    . ', approved by ' . trim($data['signed_name']),
                'amount' => round((float) $data['amount'], 2),
                'payment_id' => (int) $payment->id,
                'family_id' => (int) $payment->family_id,
                'invoice_id' => $payment->invoice_id ? (int) $payment->invoice_id : null,
                'external_invoice_id' => $payment->external_invoice_id ? (int) $payment->external_invoice_id : null,
                'method' => $stripeRefundId ? 'stripe' : 'manual',
                'status' => $status,
                'reason' => $data['reason'] ?? null,
                'approved_by' => trim($data['signed_name']),
                'signature_on_file' => true,
            ]),
        ]);

        DB::table('payments')->where('id', $payment->id)->update([
            'refunded_at' => now(),
            'notes' => trim(($payment->notes ?? '') . ' [refund $' . $data['amount'] . ']'),
        ]);

        /* Put the money back on the invoice.

           This did not happen at all before: a fully refunded invoice still read
           `paid` with balance_due 0, so the family appeared to owe nothing while the
           money had been given back to them. Every outstanding-balance figure, every
           statement and every reminder inherited that error.

           Recomputed from the refund total rather than by subtracting one amount, so
           two partial refunds cannot drift, and clamped at zero because a refund must
           never make an invoice look overpaid. */
        /* NATIVE INVOICES ONLY, DELIBERATELY.

           An external invoice's status, amount_paid and balance_due are overwritten by
           the integration sync on every single run — see IntegrationController's upsert.
           A refund written there would be erased the next time iLearn was polled, and
           the money would read as collected again while the refund still sat in the
           audit trail. The ledger re-opens the balance from payment_refunds instead,
           which nothing overwrites. */
        if ($payment->invoice_id) {
            $inv = DB::table('invoices')->where('id', $payment->invoice_id)->first();
            if ($inv) {
                $refundedNow = (float) DB::table('payment_refunds')
                    ->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
                    ->where('payments.invoice_id', $inv->id)
                    ->whereIn('payment_refunds.status', ['succeeded', 'pending', 'manual'])
                    ->sum('payment_refunds.amount');

                $paidGross = (float) DB::table('payments')
                    ->where('invoice_id', $inv->id)
                    ->where('status', 'succeeded')
                    ->sum('amount');

                $netPaid = max(0.0, round($paidGross - $refundedNow, 2));
                $total = (float) $inv->total;
                $balance = max(0.0, round($total - $netPaid, 2));

                /* NOT `$status` — that already holds the REFUND's status ('manual' or
                   'succeeded'), and overwriting it made the response, the parent's
                   notification wording and the payout offer all read the invoice's
                   status instead of the refund's. */
                $invStatus = $inv->status;
                if (! in_array($invStatus, ['void', 'draft'], true)) {
                    if ($netPaid <= 0.005) {
                        // Fully given back. `refunded` rather than `sent`, so the history
                        // is not silently rewritten into "was never paid".
                        $invStatus = $refundedNow > 0.005 ? 'refunded' : 'sent';
                    } elseif ($balance > 0.005) {
                        $invStatus = 'partial';
                    } else {
                        $invStatus = 'paid';
                    }
                }

                DB::table('invoices')->where('id', $inv->id)->update([
                    'amount_paid' => $netPaid,
                    'balance_due' => $balance,
                    'status' => $invStatus,
                    'updated_at' => now(),
                ]);
            }
        }

        /* Tell the guardians what ACTUALLY happened.
           "Refund processed" went out even when status was `manual` — a refund that is
           recorded and sends nothing, which is what every non-Stripe payment gets. A
           parent reading that has been told their money is on its way when nobody has
           sent it. */
        $moneyReturned = $status === 'succeeded';
        $title = $moneyReturned
            ? 'Refund sent: $' . number_format((float) $data['amount'], 2)
            : 'Refund approved: $' . number_format((float) $data['amount'], 2);
        $tail = $moneyReturned
            ? 'It should reach your account in a few days.'
            : 'Your centre will arrange the payment with you.';
        $reasonText = trim(($data['reason'] ?? '') . (($data['notes'] ?? '') !== '' ? ' — ' . $data['notes'] : ''));

        $gids = DB::table('guardians')->where('family_id', $payment->family_id)->pluck('user_id');
        foreach ($gids as $gid) {
            \App\Support\Notify::write([
                'user_id' => $gid, 'type' => 'refund',
                'title' => $title,
                'body' => trim($reasonText . ($reasonText !== '' ? ' — ' : '') . $tail),
                'data' => json_encode(['link' => '#billing', 'payment_id' => $payment->id]),
                'created_at' => now(),
            ]);
        }

        /* A manual refund has moved NO money. Name who should receive it so the
           screen can offer to send it now, rather than leaving that step to whoever
           remembers. Only offered when it could actually be honoured. */
        $payout = null;
        if ($status === 'manual') {
            $payee = DB::table('guardians as g')
                ->join('users as u', 'u.id', '=', 'g.user_id')
                ->where('g.family_id', $payment->family_id)
                ->whereNull('u.deleted_at')
                ->select('u.id', 'u.first_name', 'u.last_name')
                ->first();
            $agencyId = DB::table('families as f')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('f.id', $payment->family_id)
                ->value('c.agency_id');

            if ($payee && $agencyId && \App\Support\ZumRails::configured((int) $agencyId)) {
                $payout = [
                    'user_id' => (int) $payee->id,
                    'name' => trim($payee->first_name . ' ' . $payee->last_name),
                    'amount' => round((float) $data['amount'], 2),
                ];
            }
        }

        return response()->json([
            'id' => $id,
            'status' => $status,
            'stripe_refund_id' => $stripeRefundId,
            // Present only when nothing has actually been sent and sending is possible.
            'payout' => $payout,
        ], 201);
    }

    /**
     * Only an agency admin or a centre director may return money.
     *
     * Asked as a capability, never by comparing a role string: a null-returning
     * primaryRole() has already flipped four guardian checks at once on this platform,
     * and a role check that fails open on a refund endpoint hands the till to everybody.
     */
    private function assertMayRefund(Request $request): void
    {
        $u = $request->user();
        abort_unless(
            $u && ($u->isAgencyAdmin() || $u->isDirector() || $this->isPlatformAdminUser($u)),
            403,
            'Only an agency admin or a centre director can approve a refund.'
        );
    }

    /**
     * Every invoice on this account with money against it, and how much of that money
     * can still be handed back.
     *
     * Both kinds. An external invoice usually has no receipt row at all — that is the
     * whole reason $37,904.48 was unrefundable — so its receipt is described from the
     * invoice's own amount_paid and only actually written when a refund is approved.
     */
    public function refundableInvoices(Request $request): JsonResponse
    {
        $this->assertMayRefund($request);

        $userId = (int) $request->query('user_id', 0);
        abort_if($userId <= 0, 422, 'A user is required.');

        $famIds = DB::table('guardians')->where('user_id', $userId)->pluck('family_id')->all();
        if (! $famIds) {
            return response()->json(['invoices' => []]);
        }
        foreach ($famIds as $fid) {
            $this->assertAccess($request, (int) $fid);
        }

        /* What each receipt has already had taken back. 'manual' counts: it is the
           status every non-Stripe refund gets, and leaving it out is how the same money
           could be refunded repeatedly. */
        $refundedByPayment = DB::table('payment_refunds')
            ->whereIn('status', ['succeeded', 'pending', 'manual'])
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) t')
            ->pluck('t', 'payment_id');

        $payments = DB::table('payments')->whereIn('family_id', $famIds)
            ->where(function ($q) { $q->whereNull('status')->orWhere('status', 'succeeded'); })
            ->get(['id', 'invoice_id', 'external_invoice_id', 'amount', 'method', 'paid_at', 'created_at', 'reference_number']);

        $out = [];

        foreach (DB::table('invoices')->whereIn('family_id', $famIds)
            ->whereNotIn('status', ['void', 'draft'])
            ->get(['id', 'invoice_number', 'total', 'amount_paid', 'balance_due', 'status', 'due_at']) as $i) {
            $rec = [];
            foreach ($payments as $p) {
                if ((int) $p->invoice_id !== (int) $i->id) { continue; }
                $already = (float) ($refundedByPayment[$p->id] ?? 0);
                $rec[] = [
                    'payment_id' => (int) $p->id, 'amount' => (float) $p->amount,
                    'refunded' => $already, 'refundable' => max(0.0, round((float) $p->amount - $already, 2)),
                    'method' => $p->method, 'date' => $p->paid_at ?: $p->created_at,
                    'reference' => $p->reference_number, 'exists' => true,
                ];
            }
            if ($rec) {
                $out[] = $this->invoiceCard('native', (int) $i->id, (string) $i->invoice_number,
                    $i, $rec);
            }
        }

        foreach (DB::table('external_invoices')->whereIn('family_id', $famIds)
            ->whereNotIn('status', ['void', 'voided', 'cancelled'])
            ->where('amount_paid', '>', 0)
            ->get(['id', 'number', 'total', 'amount_paid', 'balance_due', 'status', 'due_at', 'source_label']) as $i) {
            $existing = null;
            foreach ($payments as $p) {
                if ((int) $p->external_invoice_id === (int) $i->id) { $existing = $p; break; }
            }
            if ($existing) {
                $already = (float) ($refundedByPayment[$existing->id] ?? 0);
                $rec = [[
                    'payment_id' => (int) $existing->id, 'amount' => (float) $existing->amount,
                    'refunded' => $already, 'refundable' => max(0.0, round((float) $existing->amount - $already, 2)),
                    'method' => $existing->method, 'date' => $existing->paid_at ?: $existing->created_at,
                    'reference' => $existing->reference_number, 'exists' => true,
                ]];
            } else {
                /* No receipt row yet — it is written only if a refund is actually
                   approved, so nothing is invented for the 227 invoices nobody touches. */
                $rec = [[
                    'payment_id' => null, 'amount' => (float) $i->amount_paid,
                    'refunded' => 0.0, 'refundable' => round((float) $i->amount_paid, 2),
                    'method' => 'external', 'date' => null,
                    'reference' => $i->number, 'exists' => false,
                ]];
            }
            $out[] = $this->invoiceCard('external', (int) $i->id,
                (string) ($i->number ?: ('#' . $i->id)), $i, $rec);
        }

        usort($out, fn ($a, $b) => strcmp((string) $b['due_at'], (string) $a['due_at']));

        return response()->json(['invoices' => array_values(array_filter(
            $out, fn ($c) => $c['refundable'] > 0.005
        ))]);
    }

    /** One invoice as the refund picker needs it. */
    private function invoiceCard(string $kind, int $id, string $number, object $i, array $receipts): array
    {
        $refundable = 0.0;
        foreach ($receipts as $r) { $refundable += (float) $r['refundable']; }

        return [
            'kind' => $kind, 'id' => $id, 'number' => $number,
            'total' => (float) $i->total,
            'paid' => (float) ($i->amount_paid ?? 0),
            'balance_due' => (float) ($i->balance_due ?? 0),
            'status' => (string) $i->status,
            'due_at' => $i->due_at,
            'source' => $kind === 'external' ? ($i->source_label ?? 'iLearn') : 'KiddieTrac',
            'receipts' => $receipts,
            'refundable' => round($refundable, 2),
        ];
    }

    /**
     * Find invoices by the number printed on them.
     *
     * Returns EVERY match, never the first: 'PINVO-05082026' belongs to eleven different
     * families, and picking one for the user would offer to refund a stranger. Exact
     * matches first; if none, a contains-search so a partial number still finds it.
     */
    public function lookupInvoice(Request $request): JsonResponse
    {
        $this->assertMayRefund($request);

        $q = trim((string) $request->query('number', ''));
        abort_if($q === '', 422, 'Enter an invoice number to look up.');
        abort_if(mb_strlen($q) > 80, 422, 'That does not look like an invoice number.');

        $centreIds = $this->visibleCentreIds($request) ?: [0];

        /* What each receipt has already had taken back. 'manual' counts — it is the
           status every non-Stripe refund gets. */
        $refundedByPayment = DB::table('payment_refunds')
            ->whereIn('status', ['succeeded', 'pending', 'manual'])
            ->groupBy('payment_id')->selectRaw('payment_id, SUM(amount) t')
            ->pluck('t', 'payment_id');

        $out = [];

        // ── external invoices ────────────────────────────────────────────────
        $ext = DB::table('external_invoices as e')
            ->join('families as f', 'f.id', '=', 'e.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNotIn('e.status', ['void', 'voided', 'cancelled'])
            ->where(fn ($w) => $w->whereRaw('LOWER(e.number) = ?', [mb_strtolower($q)])
                                 ->orWhere('e.number', 'like', '%' . $q . '%'))
            ->orderByRaw('CASE WHEN LOWER(e.number) = ? THEN 0 ELSE 1 END', [mb_strtolower($q)])
            ->limit(25)
            ->get(['e.id', 'e.number', 'e.total', 'e.amount_paid', 'e.balance_due', 'e.status',
                   'e.due_at', 'e.source_label', 'f.family_name']);

        foreach ($ext as $e) {
            $existing = DB::table('payments')->where('external_invoice_id', $e->id)->first(['id', 'amount']);
            $already = $existing ? (float) ($refundedByPayment[$existing->id] ?? 0) : 0.0;
            $received = $existing ? (float) $existing->amount : (float) $e->amount_paid;
            $out[] = [
                'kind' => 'external',
                'id' => (int) $e->id,
                'payment_id' => $existing ? (int) $existing->id : null,
                'number' => (string) ($e->number ?: ('#' . $e->id)),
                'family_name' => $e->family_name,
                'total' => (float) $e->total,
                'amount' => $received,
                'refunded' => $already,
                'refundable' => max(0.0, round($received - $already, 2)),
                'status' => (string) $e->status,
                'date' => $e->due_at,
                'method' => $e->source_label ?: 'iLearn',
            ];
        }

        // ── native invoices ──────────────────────────────────────────────────
        $nat = DB::table('invoices as i')
            ->join('families as f', 'f.id', '=', 'i.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNotIn('i.status', ['void', 'draft'])
            ->where(fn ($w) => $w->whereRaw('LOWER(i.invoice_number) = ?', [mb_strtolower($q)])
                                 ->orWhere('i.invoice_number', 'like', '%' . $q . '%'))
            ->limit(25)
            ->get(['i.id', 'i.invoice_number', 'i.total', 'i.amount_paid', 'i.balance_due',
                   'i.status', 'i.due_at', 'f.family_name']);

        foreach ($nat as $i) {
            /* A native invoice can have several receipts; the refund comes off ONE of
               them, so the one with the most left is offered and the rest are named. */
            $best = null; $bestLeft = 0.0; $left = 0.0;
            foreach (DB::table('payments')->where('invoice_id', $i->id)
                ->where('status', 'succeeded')->get(['id', 'amount']) as $pay) {
                $done = (float) ($refundedByPayment[$pay->id] ?? 0);
                $rem = round((float) $pay->amount - $done, 2);
                $left += max(0.0, $rem);
                if ($rem > $bestLeft) { $bestLeft = $rem; $best = $pay; }
            }
            $out[] = [
                'kind' => 'native',
                'id' => (int) $i->id,
                'payment_id' => $best ? (int) $best->id : null,
                'number' => (string) $i->invoice_number,
                'family_name' => $i->family_name,
                'total' => (float) $i->total,
                'amount' => (float) ($i->amount_paid ?? 0),
                'refunded' => 0.0,
                'refundable' => round($bestLeft, 2),
                'status' => (string) $i->status,
                'date' => $i->due_at,
                'method' => 'KiddieTrac',
            ];
        }

        /* Nothing refundable is still a MATCH, and says why — "that invoice has no money
           on it" is a useful answer, whereas "not found" for a number the person is
           reading off a page is not. */
        foreach ($out as &$m) {
            $m['reason'] = $m['refundable'] > 0.005 ? null
                : (($m['amount'] ?? 0) > 0.005
                    ? 'Everything received against this invoice has already been refunded.'
                    : 'Nothing has been received against this invoice yet.');
        }
        unset($m);

        return response()->json(['query' => $q, 'matches' => array_values($out)]);
    }

    /**
     * Refund against a chosen invoice.
     *
     * Resolves the receipt the money actually arrived on — writing one for an external
     * invoice that has never had one — and then hands off to create(), so a refund
     * started from an invoice goes through exactly the same guards, write-back,
     * notification and audit as one started from a payment. There is one refund path,
     * not two.
     */
    public function createForInvoice(Request $request): JsonResponse
    {
        $this->assertMayRefund($request);

        $data = $request->validate([
            'kind' => 'required|in:native,external',
            'invoice_id' => 'required|integer',
            'amount' => 'required|numeric|min:0.01',
            'payment_id' => 'nullable|integer',
            'reason' => 'nullable|string|max:200',
            'notes' => 'nullable|string|max:1000',
            'signature' => 'required|string|max:400000',
            'signed_name' => 'required|string|max:160',
        ]);

        $paymentId = $data['payment_id'] ?? null;

        if ($data['kind'] === 'external') {
            $inv = DB::table('external_invoices')->where('id', $data['invoice_id'])->first();
            abort_unless($inv, 404, 'That invoice no longer exists.');
            $this->assertAccess($request, (int) $inv->family_id);

            $existing = DB::table('payments')->where('external_invoice_id', $inv->id)->first();
            if ($existing) {
                $paymentId = (int) $existing->id;
            } else {
                abort_if(
                    (float) $inv->amount_paid <= 0.005,
                    422,
                    'Nothing has been received against this invoice, so there is nothing to refund.'
                );

                /* THE RECEIPT THIS MONEY NEVER HAD.

                   Written now rather than backfilled across 227 rows, and pointed at the
                   external invoice through its own column — never through invoice_id,
                   which is a foreign key to a DIFFERENT table whose ids overlap these
                   (native 9..64, external 2..464), so #22 there is somebody else's
                   invoice entirely. */
                $paymentId = (int) DB::table('payments')->insertGetId([
                    'invoice_id' => null,
                    'external_invoice_id' => (int) $inv->id,
                    'family_id' => (int) $inv->family_id,
                    'amount' => (float) $inv->amount_paid,
                    'method' => 'manual',
                    'status' => 'succeeded',
                    'reference_number' => $inv->number,
                    'paid_at' => $inv->external_updated_at ?: $inv->due_at ?: now(),
                    'notes' => 'Receipt recorded from ' . ($inv->source_label ?: 'the integration')
                        . ' so this invoice could be refunded. The money was received at source.',
                    'recorded_by_id' => $request->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                \App\Support\Audit::write([
                    'user_id' => $request->user()->id,
                    'agency_id' => $this->resolveAgencyId($request),
                    'action' => 'payment.receipt_recorded',
                    'entity_type' => 'payment',
                    'entity_id' => $paymentId,
                    'payload' => json_encode([
                        'summary' => 'Recorded the $' . number_format((float) $inv->amount_paid, 2)
                            . ' receipt already held against ' . ($inv->number ?: ('external invoice #' . $inv->id))
                            . ', so it could be refunded',
                        'external_invoice_id' => (int) $inv->id,
                        'family_id' => (int) $inv->family_id,
                        'amount' => (float) $inv->amount_paid,
                    ]),
                ]);
            }
        } else {
            $inv = DB::table('invoices')->where('id', $data['invoice_id'])->first();
            abort_unless($inv, 404, 'That invoice no longer exists.');
            $this->assertAccess($request, (int) $inv->family_id);

            if (! $paymentId) {
                /* No receipt named, so use the one with the most left on it. Refunding
                   more than a single receipt holds is refused with the real figure
                   rather than silently split across receipts, which would produce refund
                   records nobody asked for. */
                $best = null; $bestLeft = 0.0;
                foreach (DB::table('payments')->where('invoice_id', $inv->id)
                    ->where('status', 'succeeded')->get(['id', 'amount']) as $p) {
                    $done = (float) DB::table('payment_refunds')->where('payment_id', $p->id)
                        ->whereIn('status', ['succeeded', 'pending', 'manual'])->sum('amount');
                    $left = round((float) $p->amount - $done, 2);
                    if ($left > $bestLeft) { $bestLeft = $left; $best = $p; }
                }
                abort_unless($best, 422, 'No payment on this invoice has anything left to refund.');
                $paymentId = (int) $best->id;
            }
        }

        $request->merge(['payment_id' => $paymentId]);

        return $this->create($request);
    }

    private function assertAccess(Request $request, int $familyId): void
    {
        // SECURITY (v22p96): the prior check accepted ANY active staff role on the
        // platform, so a director/admin of agency A — or a switched platform_admin —
        // could refund agency B's payments. Now must be staff of THIS family's
        // centre (or a platform_admin scoped to its agency). Route middleware
        // already keeps guardians off the refund-create action.
        abort_unless($this->canAccessFamilyScoped($request, $familyId), 403);
    }
}
