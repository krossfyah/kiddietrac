<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Where Zum Rails tells us what actually happened.
 *
 * This is the ONLY authority on whether money moved. An Interac e-Transfer is accepted
 * immediately and settles minutes or days later, so the create response means "they took
 * the instruction" and nothing more. Marking an invoice paid from that response would
 * mark invoices paid that are not.
 *
 * Unauthenticated by necessity — Zum calls it, not a signed-in user — so it is guarded by
 * a shared secret we set in their portal, compared in constant time. Unrecognised
 * transaction ids are acknowledged rather than argued with: a 200 stops them retrying
 * forever for something we will never know about.
 */
class ZumWebhookController extends Controller
{
    /** Their statuses, mapped to the four our portal understands. */
    private const MAP = [
        // Their documented set: InProgress, Completed, Failed, Cancelled, Scheduled,
        // InReview, Pending Cancellation. Compared lower-cased with spaces and
        // underscores stripped, so "Pending Cancellation" matches whichever way they
        // write it.
        'started' => 'submitted',
        'inprogress' => 'submitted',
        'pending' => 'submitted',
        'scheduled' => 'submitted',
        'completed' => 'settled',
        'succeeded' => 'settled',
        'success' => 'settled',
        'failed' => 'failed',
        'error' => 'failed',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
        'pendingcancellation' => 'cancelling',
        // Zum has flagged it for review. Not settled, not failed — somebody has to look,
        // so it gets its own status rather than being flattened into "submitted".
        'inreview' => 'in_review',
    ];


    /**
     * A refund reaching its end state.
     *
     * Only on SETTLED does money move back: the running refunded total goes up and the
     * invoice is credited back by exactly this refund, never by the whole payment.
     * A part-refunded invoice stops being "paid" and shows the balance again.
     */
    private function handleRefund($refund, string $raw, array $payload): JsonResponse
    {
        $status = self::MAP[$raw] ?? null;
        if (! $status) {
            return response()->json(['ok' => true]);
        }
        $wasStatus = $refund->status;
        // Same reasoning as payments: a duplicate "Completed" must not refund twice.
        // This one is worse if missed — it moves the refunded total past the payment.
        if ($refund->status === $status) {
            return response()->json(['ok' => true]);
        }
        if ($refund->status === 'settled' && $status !== 'settled') {
            return response()->json(['ok' => true]);
        }

        DB::transaction(function () use ($refund, $status, $payload) {
            DB::table('zum_refunds')->where('id', $refund->id)->update([
                'status' => $status,
                'settled_at' => $status === 'settled' ? now() : null,
                'last_response' => json_encode($payload),
                'updated_at' => now(),
            ]);

            if ($status !== 'settled') {
                return;
            }

            $txn = DB::table('zum_transactions')->where('id', $refund->zum_transaction_id_local)->first();
            if (! $txn) {
                return;
            }

            DB::table('zum_transactions')->where('id', $txn->id)->update([
                'refunded_amount' => round((float) $txn->refunded_amount + (float) $refund->amount, 2),
                'updated_at' => now(),
            ]);

            if (! $txn->invoice_id) {
                return;
            }
            $inv = DB::table('invoices')->where('id', $txn->invoice_id)->first();
            if (! $inv) {
                return;
            }

            /* Stamp the ledger row too. The refunds screen reads payments.refunded_at,
               so without this a refunded payment still reads as money we hold. */
            DB::table('payments')
                ->where('reference_number', 'ZUM-' . $txn->zum_transaction_id)
                ->update([
                    'refunded_at' => now(),
                    // Fully refunded reads as refunded; a partial one is still a payment
                    // that mostly stands, so its status is left alone.
                    'status' => (round((float) $txn->refunded_amount + (float) $refund->amount, 2)
                        >= round((float) $txn->amount, 2) - 0.005) ? 'refunded' : 'succeeded',
                    'updated_at' => now(),
                ]);

            $paid = round(max(0, (float) $inv->amount_paid - (float) $refund->amount), 2);
            $balance = round((float) $inv->total - $paid, 2);
            DB::table('invoices')->where('id', $inv->id)->update([
                'amount_paid' => $paid,
                'balance_due' => max(0, $balance),
                // A refunded invoice is owed again. Left alone if it was never marked
                // paid, so a manual status is not overwritten.
                'status' => ($inv->status === 'paid' && $balance > 0.005) ? 'sent' : $inv->status,
                'updated_at' => now(),
            ]);
        });

        // After the commit, like the payment side: an audit row for a refund that rolled
        // back would be worse than none.
        $this->auditRefund($refund, $wasStatus, $status, $payload);

        return response()->json(['ok' => true]);
    }
    public function handle(Request $request): JsonResponse
    {
        // With per-agency credentials the shared secret is also the identity: Zum calls
        // unauthenticated, so matching it against each configured agency tells us whose
        // callback this is. No match means we do not know, and a payments callback applied
        // to a guessed agency would move the wrong customer's money.
        /* `zumrails-signature` is the header Zum actually sends, carrying an HMAC-SHA256
           of the raw body. `X-Zum-Signature` and a body `secret` are kept for a static
           secret and for our own tests. Verified against the RAW body: re-encoding the
           parsed payload changes key order and breaks an otherwise valid digest. */
        $given = (string) ($request->header('zumrails-signature')
            ?: $request->header('X-Zum-Signature')
            ?: $request->input('secret', ''));
        /* EVERY agency this signature could belong to, not the first one that matched.
           Two agencies sharing a sandbox account share a secret, and picking the first
           would scope the lookup below to the wrong one — finding nothing, and leaving
           a real payment unsettled for ever. */
        $agencyIds = \App\Support\PaymentProviders::agenciesForWebhookSignature(
            \App\Support\PaymentProviders::ZUM, $given, $request->getContent()
        );
        $agencyId = $agencyIds[0] ?? null;   // still used where only a hint is needed

        // While no agency has configured Zum at all there is nothing to match against, and
        // rejecting everything would make the endpoint untestable. Once ANY agency is
        // live, an unmatched secret is refused.
        $anyConfigured = DB::table('agency_payment_providers')
            ->where('provider', \App\Support\PaymentProviders::ZUM)
            ->where('enabled', true)->exists();

        if ($anyConfigured && ! $agencyIds) {
            return response()->json(['message' => 'Bad signature'], 401);
        }

        $payload = $request->all();

        /* Their envelope is {"Type":"Transaction","Event":"Updated","Data":{…}} — capital
           Data, with the entity inside it. The lower-case 'data' fallback stays for any
           sender that differs. */
        $data = $payload['Data'] ?? $payload['data'] ?? [];
        if (! is_array($data)) {
            $data = [];
        }

        /* Only entities that move money. A User or Invoice callback is acknowledged and
           ignored rather than hunted for in the transactions table. */
        $type = strtolower(str_replace([' ', '_', '-'], '', (string) ($payload['Type'] ?? '')));
        if ($type !== '' && ! in_array($type, ['transaction', 'refund', 'recurrenttransaction'], true)) {
            return response()->json(['ok' => true]);
        }

        $zumId = $payload['TransactionId'] ?? $payload['Id'] ?? $data['Id'] ?? null;

        /* The status lives on the entity. `Event` is deliberately NOT consulted: it is
           "Created"/"Updated"/"StatusChange", an event type rather than a status, so it
           could only ever fall through as unmapped and hide the real value. */
        $raw = strtolower((string) (
            $data['TransactionStatus']
            ?? $data['Status']
            ?? $payload['TransactionStatus']
            ?? $payload['Status']
            ?? ''
        ));
        $raw = str_replace([' ', '_', '-'], '', $raw);

        if (! $zumId) {
            Log::info('zumrails webhook without a transaction id', ['keys' => array_keys($payload)]);

            return response()->json(['ok' => true]);
        }

        // A refund settles through the same webhook. Checked first: a refund id will
        // never match a transaction id, and treating one as the other would credit an
        // invoice that is being refunded.
        $refund = DB::table('zum_refunds')
            ->where('zum_refund_id', $zumId)
            ->when($agencyIds, fn ($q) => $q->whereIn('agency_id', $agencyIds))
            ->first();
        if ($refund) {
            return $this->handleRefund($refund, $raw, $payload);
        }

        /* ── THE CALLBACK MAY ONLY TOUCH ITS OWN AGENCY ───────────────────────
           This looked the transaction up by Zum id across the whole table and never
           compared it with the agency the secret resolved to. With two agencies on
           Zum — each with their own account and their own secret — a callback signed
           by agency B settled agency A's transaction and marked agency A's invoice
           paid. Demonstrated on 2026-09-01 against a $200 invoice.

           Scoped by agency now. A row whose agency cannot be established is refused
           rather than settled: an unattributable callback that moves money is worse
           than one that does nothing, and the sender retries. */
        $row = DB::table('zum_transactions')
            ->where('zum_transaction_id', $zumId)
            ->when($agencyIds, fn ($q) => $q->whereIn('agency_id', $agencyIds))
            ->first();

        /* The rule is unchanged — a callback may only touch an agency whose secret
           signed it — but "whose secret signed it" can now legitimately be more than
           one agency, so membership of the set is the test rather than equality. */
        if ($row && $agencyIds && ! in_array((int) ($row->agency_id ?? 0), $agencyIds, true)) {
            Log::warning('zumrails webhook refused: signed by another agency', [
                'zum_id' => $zumId, 'signed_by' => $agencyIds, 'belongs_to' => $row->agency_id ?? null,
            ]);

            return response()->json(['message' => 'Bad signature'], 401);
        }
        if (! $row) {
            // Not ours, or from an environment we are not tracking. Acknowledged so it
            // is not retried for ever.
            Log::info('zumrails webhook for an unknown transaction', ['zum_id' => $zumId]);

            return response()->json(['ok' => true]);
        }

        $status = self::MAP[$raw] ?? null;
        if (! $status) {
            Log::info('zumrails webhook with an unmapped status', ['zum_id' => $zumId, 'status' => $raw]);

            return response()->json(['ok' => true]);
        }

        // Already in this state: nothing to do. Providers resend webhooks — retries,
        // at-least-once delivery, an operator replay — so a duplicate "Completed" is
        // ordinary traffic. Without this it credits the invoice a second time.
        if ($row->status === $status) {
            return response()->json(['ok' => true]);
        }
        // And never walk a settled transaction backwards: webhooks arrive out of order,
        // and a late "in progress" after a "completed" must not un-pay an invoice.
        if ($row->status === 'settled' && $status !== 'settled') {
            return response()->json(['ok' => true]);
        }

        $credited = null;

        DB::transaction(function () use ($row, $status, $payload, &$credited) {
            DB::table('zum_transactions')->where('id', $row->id)->update([
                'status' => $status,
                'settled_at' => $status === 'settled' ? now() : null,
                'last_response' => json_encode($payload),
                'updated_at' => now(),
            ]);

            // Money in, settled, against an invoice: credit it. Only here — never on the
            // create response.
            if ($status === 'settled' && $row->direction === 'in' && $row->invoice_id) {
                $inv = DB::table('invoices')->where('id', $row->invoice_id)->first();
                if ($inv) {
                    /* The ledger row. `payments` is what the invoice screen, the family
                       statement, the receipt PDF and the refunds screen all read — none of
                       which knew a Zum payment existed, because only invoices.amount_paid
                       was being updated. The invoice said paid and the ledger showed
                       nothing: two halves of the accounting disagreeing about one payment.

                       Written in THIS transaction so they cannot diverge, and keyed on the
                       Zum transaction id so a repeated webhook updates the one row instead
                       of recording the money twice. */
                    DB::table('payments')->updateOrInsert(
                        ['reference_number' => 'ZUM-' . $row->zum_transaction_id],
                        [
                            'invoice_id' => (int) $inv->id,
                            'family_id' => $inv->family_id,
                            'amount' => round((float) $row->amount, 2),
                            /* Their name -> the ledger's. Explicit, because the enum
                               rejects anything else outright ("Data truncated for column
                               'method'"), and an unknown rail must still be recordable. */
                            'method' => [
                                'Interac' => 'interac',
                                'Eft' => 'eft',
                                'Ach' => 'eft',
                                'CreditCard' => 'card',
                            ][$row->method] ?? 'manual',
                            'status' => 'succeeded',
                            'paid_at' => now(),
                            'notes' => 'Zum Rails ' . $row->method,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $paid = round((float) $inv->amount_paid + (float) $row->amount, 2);
                    $balance = round((float) $inv->total - $paid, 2);
                    DB::table('invoices')->where('id', $inv->id)->update([
                        'amount_paid' => $paid,
                        'balance_due' => max(0, $balance),
                        /* `partial` when some of it is paid — the enum has always had it and
                           the manual payment path already sets it, but a payment made through
                           the app only ever set `paid`, so a part-paid invoice looked
                           untouched. Left alone once void or draft. */
                        'status' => in_array($inv->status, ['void', 'draft'], true)
                            ? $inv->status
                            : ($balance <= 0.005 ? 'paid' : ($paid > 0.005 ? 'partial' : $inv->status)),
                        'updated_at' => now(),
                    ]);
                    $credited = [
                        'invoice_id' => (int) $inv->id,
                        'invoice_number' => $inv->invoice_number,
                        'credited' => round((float) $row->amount, 2),
                        'amount_paid_now' => $paid,
                        'balance_now' => max(0, $balance),
                        'invoice_status' => $balance <= 0.005 ? 'paid' : $inv->status,
                    ];
                }
            }
        });

        $this->auditSettlement($row, $status, $credited);

        /* Everything above worked already; nobody was ever told. Sent AFTER the
           transaction commits so a rolled-back payment cannot email a receipt. */
        if ($status === 'settled') {
            \App\Services\PaymentNotifier::settled($row, $credited);
        } elseif ($status === 'failed') {
            \App\Services\PaymentNotifier::failed($row, (string) (
                $payload['Data']['FailedTransactionEvent']['Description']
                ?? $payload['Data']['ErrorMessage']
                ?? $payload['FailedTransactionEvent']['Description']
                ?? ''
            ));
        }

        return response()->json(['ok' => true]);
    }

    /**
     * The audit row for a settlement.
     *
     * This whole controller wrote none. Every other write in the portal is audited, and
     * yet the one event that moves money and marks an invoice paid left nothing behind
     * but a changed status column — so "who said this invoice was paid, and when" had no
     * answer. Written AFTER the transaction commits: an audit row for a settlement that
     * rolled back would be worse than none.
     *
     * Granular on purpose: which invoice, by number, for how much, and what the balance
     * became. A row saying "payment settled" answers nothing a month later.
     */
    /**
     * The audit row for a refund reaching an end state.
     *
     * The payment side has been audited since it was written; this side was not, so money
     * going BACK to a family left nothing behind. Named granularly for the same reason:
     * which refund, against which payment, for how much, and what it did to the invoice.
     */
    private function auditRefund(object $refund, string $from, string $to, array $payload): void
    {
        try {
            $txn = DB::table('zum_transactions')->where('id', $refund->zum_transaction_id_local)->first();
            $body = [
                'refund_id' => (int) $refund->id,
                'zum_refund_id' => $refund->zum_refund_id,
                'amount' => round((float) $refund->amount, 2),
                'reason' => $refund->reason,
                'from_status' => $from,
                'to_status' => $to,
            ];
            if ($txn) {
                $body['payment'] = [
                    'local_id' => (int) $txn->id,
                    'zum_transaction_id' => $txn->zum_transaction_id,
                    'method' => $txn->method,
                    'original_amount' => round((float) $txn->amount, 2),
                    'refunded_to_date' => round((float) $txn->refunded_amount, 2),
                ];
                if ($txn->invoice_id) {
                    $inv = DB::table('invoices')->where('id', $txn->invoice_id)->first();
                    if ($inv) {
                        $body['invoice'] = [
                            'invoice_id' => (int) $inv->id,
                            'invoice_number' => $inv->invoice_number,
                            'balance_now' => round((float) $inv->balance_due, 2),
                            'invoice_status' => $inv->status,
                        ];
                    }
                }
            }

            \App\Support\Audit::write([
                'user_id' => null,
                'agency_id' => $refund->agency_id ?? null,
                'action' => 'zum.refund_' . $to,
                'entity_type' => 'payment',
                'entity_id' => (int) $refund->zum_transaction_id_local,
                'payload' => json_encode($body),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Same rule as the payment side: never lose the refund over the paperwork.
            Log::warning('zumrails refund audit failed', [
                'refund' => $refund->id, 'error' => $e->getMessage(),
            ]);
        }
    }

    private function auditSettlement(object $row, string $status, ?array $credited): void
    {
        try {
            $payload = [
                'zum_transaction_id' => $row->zum_transaction_id,
                'method' => $row->method,
                'direction' => $row->direction,
                'amount' => round((float) $row->amount, 2),
                'from_status' => $row->status,
                'to_status' => $status,
            ];
            if ($credited) {
                $payload['invoice'] = $credited;
            }

            \App\Support\Audit::write([
                // Nobody signed in: the payment provider called us.
                'user_id' => null,
                'agency_id' => $row->agency_id ?? null,
                'action' => 'zum.' . $status,
                'entity_type' => $credited ? 'invoice' : 'payment',
                'entity_id' => $credited['invoice_id'] ?? (int) $row->id,
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // A settled payment must never be lost because it could not be written down.
            Log::warning('zumrails settlement audit failed', [
                'transaction' => $row->id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
