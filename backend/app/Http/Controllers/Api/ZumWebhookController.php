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
        'started' => 'submitted',
        'inprogress' => 'submitted',
        'in_progress' => 'submitted',
        'pending' => 'submitted',
        'completed' => 'settled',
        'succeeded' => 'settled',
        'success' => 'settled',
        'failed' => 'failed',
        'error' => 'failed',
        'cancelled' => 'cancelled',
        'canceled' => 'cancelled',
    ];

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

        if (! $zumId) {
            Log::info('zumrails webhook without a transaction id', ['keys' => array_keys($payload)]);

            return response()->json(['ok' => true]);
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

        // Never walk a settled transaction backwards. Webhooks arrive out of order, and a
        // late "in progress" after a "completed" must not un-pay an invoice.
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
