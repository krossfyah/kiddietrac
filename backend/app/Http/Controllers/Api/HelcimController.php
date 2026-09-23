<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Helcim;
use App\Support\PaymentProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Card payments through Helcim.
 *
 * TWO CALLS, AND THE SECOND ONE IS NOT OPTIONAL. checkout() opens a session and hands the
 * browser a checkoutToken; complete() takes back what HelcimPay.js produced and is the
 * ONLY place a payment row is written. Nothing the browser says about an amount, an
 * invoice or a success is believed: the secretToken stays here, the hash is checked
 * against it, and the amount recorded is the amount Helcim reports, not the amount the
 * page asked for.
 *
 * Without that, a page could post "paid $0.01" for a $900 invoice and be believed.
 */
class HelcimController extends Controller
{
    /**
     * Which agency's Helcim account is this payment going to?
     *
     * A family belongs to a centre, and a centre to an agency - that chain is the answer,
     * NOT the X-Active-Agency-Id header. A parent has no business choosing which agency
     * banks their money, and a header is theirs to set.
     */
    private function agencyForFamily(int $familyId): int
    {
        $agencyId = (int) DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)
            ->value('c.agency_id');

        abort_if($agencyId <= 0, 422, 'This family is not attached to an agency.');

        return $agencyId;
    }

    /** The families this signed-in user may pay for. */
    private function familiesFor(Request $request): array
    {
        return DB::table('guardians')->where('user_id', $request->user()->id)
            ->pluck('family_id')->map(fn ($v) => (int) $v)->all();
    }

    /**
     * POST /parent/helcim/checkout - open a hosted checkout for an invoice.
     *
     * The AMOUNT IS TAKEN FROM THE INVOICE, never from the request. A client-supplied
     * amount is a client-supplied discount.
     */
    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_id' => 'required|integer',
        ]);

        $mine = $this->familiesFor($request);
        $invoice = DB::table('invoices')->where('id', (int) $data['invoice_id'])->first();
        abort_if(! $invoice, 404, 'Invoice not found.');
        abort_unless(in_array((int) $invoice->family_id, $mine, true), 403, 'That invoice is not yours.');

        $agencyId = $this->agencyForFamily((int) $invoice->family_id);
        abort_unless(Helcim::configured($agencyId), 422, 'Card payments are not set up for this agency.');

        /* What is actually still owed, not the invoice total - a part-paid invoice must
           not re-charge the whole amount. */
        $paid = (float) DB::table('payments')->where('invoice_id', $invoice->id)
            ->where('status', 'succeeded')->sum('amount');
        $due = round((float) $invoice->total - $paid, 2);
        abort_if($due <= 0, 422, 'This invoice is already paid.');

        [$ok, $res, $err] = Helcim::initializeCheckout(
            $agencyId,
            $due,
            (string) ($invoice->invoice_number ?? $invoice->id),
            'FAM-' . $invoice->family_id
        );

        if (! $ok || empty($res['checkoutToken'])) {
            return response()->json(['error' => $err ?: 'Could not start the payment.'], 422);
        }

        /* The secretToken is held HERE, against this one checkout, and never sent to the
           browser. It is what proves the result genuine in complete(). Kept in the session
           row rather than the response for exactly that reason. */
        DB::table('helcim_checkouts')->insert([
            'agency_id' => $agencyId,
            'family_id' => (int) $invoice->family_id,
            'invoice_id' => (int) $invoice->id,
            'user_id' => (int) $request->user()->id,
            'checkout_token' => (string) $res['checkoutToken'],
            'secret_token' => (string) ($res['secretToken'] ?? ''),
            'amount' => $due,
            'currency' => Helcim::currency($agencyId),
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'checkout_token' => $res['checkoutToken'],
            'amount' => $due,
            'currency' => Helcim::currency($agencyId),
            /* Said out loud so a test token on a live agency cannot masquerade as a real
               payment button in front of a real family. */
            'live' => Helcim::production($agencyId),
        ]);
    }

    /**
     * POST /parent/helcim/complete - record the result of a hosted checkout.
     *
     * Idempotent by the checkout token: HelcimPay.js can fire its completion more than
     * once, and a double-posted result must not become two payment rows.
     */
    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'checkout_token' => 'required|string|max:120',
            // The RAW JSON string exactly as the browser received it. Re-encoding a parsed
            // object changes key order and spacing, and the hash would never match.
            'raw_data' => 'required|string|max:20000',
            'hash' => 'required|string|max:200',
        ]);

        $sess = DB::table('helcim_checkouts')
            ->where('checkout_token', $data['checkout_token'])->first();
        abort_if(! $sess, 404, 'Unknown checkout.');
        abort_unless((int) $sess->user_id === (int) $request->user()->id, 403, 'Not your checkout.');

        if ($sess->status === 'paid' && $sess->payment_id) {
            return response()->json(['payment_id' => (int) $sess->payment_id, 'already' => true]);
        }

        if (! Helcim::verifyHash($data['raw_data'], (string) $sess->secret_token, $data['hash'])) {
            /* Recorded, because a failing hash is either a bug or an attempt to forge a
               payment, and both are worth being able to see. */
            $this->audit((int) $sess->agency_id, 'payment.helcim_hash_failed', (int) $sess->id, [
                'checkout_token' => $sess->checkout_token,
                'summary' => 'A Helcim payment result did not match its secret token and was '
                    . 'REFUSED. Either the response was altered in the browser, or the checkout '
                    . 'session it names belongs to a different payment.',
            ], $request);
            DB::table('helcim_checkouts')->where('id', $sess->id)
                ->update(['status' => 'rejected', 'updated_at' => now()]);

            return response()->json(['error' => 'That payment could not be verified.'], 422);
        }

        $paid = json_decode($data['raw_data'], true) ?: [];
        $txnId = (int) ($paid['transactionId'] ?? 0);
        $status = strtoupper((string) ($paid['status'] ?? ''));

        if ($txnId <= 0 || $status !== 'APPROVED') {
            DB::table('helcim_checkouts')->where('id', $sess->id)
                ->update(['status' => 'failed', 'updated_at' => now()]);

            return response()->json(['error' => 'The payment was not approved.'], 422);
        }

        /* THE AMOUNT HELCIM SETTLED, not the amount we asked for. If those differ - a
           partial payment, a convenience fee - the invoice must follow the money. */
        $amount = round((float) ($paid['amount'] ?? $sess->amount), 2);

        $paymentId = DB::transaction(function () use ($sess, $amount, $txnId, $paid) {
            $id = DB::table('payments')->insertGetId([
                'invoice_id' => $sess->invoice_id,
                'family_id' => $sess->family_id,
                'amount' => $amount,
                'method' => 'helcim_card',
                'status' => 'succeeded',
                /* The provider reference lives where every other provider puts it, so the
                   reconcile command and the refund path can both find it. */
                'reference_number' => (string) $txnId,
                'paid_at' => now(),
                'notes' => trim('Helcim ' . ($paid['cardType'] ?? '') . ' '
                    . ($paid['cardNumber'] ?? '') . ' approval ' . ($paid['approvalCode'] ?? '')),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('helcim_checkouts')->where('id', $sess->id)->update([
                'status' => 'paid',
                'transaction_id' => $txnId,
                'payment_id' => $id,
                /* Kept so a later refund can be matched to the card it came from without
                   storing anything that can be charged on its own. */
                'card_token' => (string) ($paid['cardToken'] ?? ''),
                'updated_at' => now(),
            ]);

            return $id;
        });

        $this->applyToInvoice((int) $sess->invoice_id);

        return response()->json(['payment_id' => $paymentId, 'amount' => $amount]);
    }

    /**
     * Bring the invoice into line with what has actually been paid.
     *
     * A DELTA against payments, never a recompute from the lines: 13 of 25 invoices have
     * lines that do not sum to their own subtotal, so rebuilding a total from them would
     * quietly change what the family owes.
     */
    private function applyToInvoice(int $invoiceId): void
    {
        $inv = DB::table('invoices')->where('id', $invoiceId)->first();
        if (! $inv) {
            return;
        }

        $paid = (float) DB::table('payments')->where('invoice_id', $invoiceId)
            ->where('status', 'succeeded')->sum('amount');
        $balance = round((float) $inv->total - $paid, 2);

        /* void and refunded are decisions somebody made ABOUT the invoice; a payment
           arriving afterwards does not undo them, and quietly flipping one back to paid
           would hide whatever that decision was for. Leave those alone and let a human
           look. */
        $status = $inv->status;
        if (! in_array($status, ['void', 'refunded'], true)) {
            $status = $balance <= 0.005 ? 'paid' : ($paid > 0 ? 'partial' : $status);
        }

        DB::table('invoices')->where('id', $invoiceId)->update([
            'amount_paid' => $paid,
            'balance_due' => max(0, $balance),
            'status' => $status,
            'updated_at' => now(),
        ]);
    }

    /**
     * POST /admin/payment-providers/helcim/test - does the stored token work?
     *
     * A button, rather than finding out when a parent cannot pay.
     */
    public function test(Request $request): JsonResponse
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Only an agency administrator can test payment settings.');

        $agencyId = (int) $request->header('X-Active-Agency-Id');
        abort_if($agencyId <= 0, 422, 'No active agency.');

        if (! Helcim::configured($agencyId)) {
            return response()->json(['ok' => false, 'error' => 'No API token is stored yet.'], 200);
        }

        [$works, $err] = Helcim::testConnection($agencyId);

        return response()->json([
            'ok' => $works,
            'error' => $works ? null : ($err ?: 'Helcim did not accept that token.'),
            'live' => Helcim::production($agencyId),
            'currency' => Helcim::currency($agencyId),
        ]);
    }

    private function audit(int $agencyId, string $action, ?int $entityId, array $payload, Request $request): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => optional($request->user())->id,
                'agency_id' => $agencyId,
                'action' => $action,
                'entity_type' => 'payment',
                'entity_id' => $entityId,
                'payload' => json_encode($payload),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
