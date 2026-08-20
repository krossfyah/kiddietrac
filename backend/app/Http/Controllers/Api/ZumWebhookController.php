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

        return response()->json(['ok' => true]);
    }
    public function handle(Request $request): JsonResponse
    {
        $secret = (string) config('services.zumrails.webhook_secret');
        if ($secret !== '') {
            $given = (string) ($request->header('X-Zum-Signature') ?: $request->input('secret', ''));
            if (! hash_equals($secret, $given)) {
                return response()->json(['message' => 'Bad signature'], 401);
            }
        }

        $payload = $request->all();
        $zumId = $payload['TransactionId'] ?? $payload['Id'] ?? ($payload['data']['Id'] ?? null);
        $raw = strtolower((string) ($payload['Status'] ?? $payload['Event'] ?? ($payload['data']['Status'] ?? '')));
        $raw = str_replace([' ', '_', '-'], '', $raw);

        if (! $zumId) {
            Log::info('zumrails webhook without a transaction id', ['keys' => array_keys($payload)]);

            return response()->json(['ok' => true]);
        }

        // A refund settles through the same webhook. Checked first: a refund id will
        // never match a transaction id, and treating one as the other would credit an
        // invoice that is being refunded.
        $refund = DB::table('zum_refunds')->where('zum_refund_id', $zumId)->first();
        if ($refund) {
            return $this->handleRefund($refund, $raw, $payload);
        }

        $row = DB::table('zum_transactions')->where('zum_transaction_id', $zumId)->first();
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

        DB::transaction(function () use ($row, $status, $payload) {
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
                    $paid = round((float) $inv->amount_paid + (float) $row->amount, 2);
                    $balance = round((float) $inv->total - $paid, 2);
                    DB::table('invoices')->where('id', $inv->id)->update([
                        'amount_paid' => $paid,
                        'balance_due' => max(0, $balance),
                        'status' => $balance <= 0.005 ? 'paid' : $inv->status,
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        return response()->json(['ok' => true]);
    }
}
