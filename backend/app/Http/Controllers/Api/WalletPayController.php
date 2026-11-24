<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Helcim;
use App\Support\PaymentProviders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * APPLE PAY + GOOGLE PAY ON INVOICES (2026-11-03).
 *
 * Anthony: "wire it up for all processors". What each processor can do:
 *   - Stripe: Apple Pay and Google Pay. In a browser through the Express Checkout Element (Safari shows Apple Pay,
 *     Chrome Google Pay); inside the iOS/Android apps through the native @capacitor-community/stripe plugin, because
 *     Apple blocks Apple Pay JS in our WKWebView and Android WebView has no dependable Google Pay. Both confirm the
 *     same PaymentIntent created here.
 *   - Helcim: Google Pay inside HelcimPay.js (digitalWallet google-pay). Helcim has no Apple Pay online yet.
 *   - Zum Rails: none. Their API has no wallet token input (card payments charge a card saved on the Zum user).
 *
 * The money goes to the AGENCY's own Stripe account (Payment providers), never the platform's. The amount is the
 * invoice's balance (or the payer's split-billing share) worked out here - never a number from the browser.
 * A payment is recorded only after Stripe says the PaymentIntent succeeded: by stripeConfirm() when the sheet closes,
 * or by the webhook (StripeParentPayController::webhook) if the app was closed first. AutopayRunner::record dedupes.
 */
class WalletPayController extends Controller
{
    /** The domains parents open the portal on; each needs registering with the agency's Stripe for Apple Pay. */
    private const BASE_DOMAINS = ['app.kiddietrac.com'];

    /** The agency's Stripe: [client, publishable key, live?, apple merchant id] or null when it has none. */
    public static function stripeFor(int $agencyId): ?array
    {
        $k = \App\Support\AgencyStripe::keys($agencyId);   // the one place agency keys are read (2026-11-05)
        if (! $k) return null;

        return [new \Stripe\StripeClient($k['secret']), $k['publishable'], $k['live'], $k['apple_merchant_id'] ?: 'merchant.com.kiddietrac.app'];
    }

    /**
     * WALLET DEMO (2026-11-04): a TEST agency can show Apple Pay / Google Pay with no processor at all -
     * agencies.settings.wallet_demo = true. Only while the agency has no working Stripe (real Stripe, even test keys,
     * takes over by itself) and never for a public website demo (that has its own simulated checkout).
     */
    public static function demoOn(int $agencyId): bool
    {
        if (\App\Support\PublicDemo::isAgency($agencyId)) return false;
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);

        return is_array($s) && ! empty($s['wallet_demo']);
    }

    private function familiesFor(Request $request): array
    {
        return DB::table('guardians')->where('user_id', $request->user()->id)
            ->pluck('family_id')->map(fn ($v) => (int) $v)->all();
    }

    /** The parent's own invoice, its agency, and what is owed on it now (dollars). */
    private function owed(Request $request, int $invoiceId): array
    {
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_if(! $invoice, 404, 'Invoice not found.');
        abort_unless(in_array((int) $invoice->family_id, $this->familiesFor($request), true), 403, 'That invoice is not yours.');
        $agencyId = (int) DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $invoice->family_id)->value('c.agency_id');
        abort_if($agencyId <= 0, 422, 'This family is not attached to an agency.');

        // What is still owed, not the total: a part-paid invoice must not be charged in full (as HelcimController).
        $paid = (float) DB::table('payments')->where('invoice_id', $invoice->id)->where('status', 'succeeded')->sum('amount');
        // Refunded money is owed again; a void, draft or refunded invoice owes nothing (2026-11-05: a void one could be paid).
        $back = (float) DB::table('payment_refunds')->join('payments', 'payments.id', '=', 'payment_refunds.payment_id')
            ->where('payments.invoice_id', $invoice->id)->whereIn('payment_refunds.status', ['succeeded', 'pending', 'manual'])->sum('payment_refunds.amount');
        $due = in_array($invoice->status, ['void', 'draft', 'refunded'], true) ? 0.0 : round((float) $invoice->total - $paid + $back, 2);
        $gid = \App\Support\BillingSplit::guardianFor((int) $request->user()->id, (int) $invoice->family_id);
        if ($gid && ($share = \App\Support\BillingSplit::shareFor($invoice, $gid))) {
            $due = round(min($due, (float) $share['balance']), 2);
        }

        return [$invoice, $agencyId, max(0, $due), $gid];
    }

    private function currency(int $agencyId): string
    {
        $cur = strtoupper(trim((string) DB::table('agencies')->where('id', $agencyId)->value('currency')));

        return in_array($cur, ['CAD', 'USD'], true) ? $cur : 'CAD';
    }

    private function country(int $agencyId): string
    {
        $c = strtolower(trim((string) DB::table('agencies')->where('id', $agencyId)->value('country')));

        return in_array($c, ['us', 'usa', 'united states', 'united states of america'], true) ? 'US' : 'CA';
    }

    /** GET /parent/wallet-pay/options?invoice_id= - which wallet buttons this invoice can show, and for how much. */
    public function options(Request $request): JsonResponse
    {
        $id = (int) $request->validate(['invoice_id' => 'required|integer'])['invoice_id'];
        [$invoice, $agencyId, $due] = $this->owed($request, $id);
        $stripe = self::stripeFor($agencyId);
        $label = (string) (DB::table('agencies')->where('id', $agencyId)->value('name') ?: 'Childcare');

        return response()->json([
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => (string) ($invoice->invoice_number ?? $invoice->id),
            'amount' => $due,
            'amount_cents' => (int) round($due * 100),
            'currency' => $this->currency($agencyId),
            'country' => $this->country($agencyId),
            'label' => mb_substr($label, 0, 60),
            'stripe' => $stripe && $due > 0 ? ['publishable_key' => $stripe[1], 'live' => $stripe[2],
                'apple_pay' => true, 'google_pay' => true, 'merchant_identifier' => $stripe[3]] : null,
            'helcim' => Helcim::configured($agencyId) && $due > 0 ? ['google_pay' => true, 'apple_pay' => false, 'live' => Helcim::production($agencyId)] : null,
            // Test agency wallet demo: both wallets, simulated - no processor is called and no money moves.
            'demo' => ! $stripe && $due > 0 && self::demoOn($agencyId) ? ['apple_pay' => true, 'google_pay' => true] : null,
            // Zum's API has no Apple Pay / Google Pay; the parent's other ways to pay stay on the screen.
            'zum' => null,
        ]);
    }

    /** POST /parent/wallet-pay/stripe-intent {invoice_id, wallet} - the PaymentIntent the wallet sheet confirms. */
    public function stripeIntent(Request $request): JsonResponse
    {
        $d = $request->validate(['invoice_id' => 'required|integer', 'wallet' => 'required|in:apple_pay,google_pay,browser']);
        [$invoice, $agencyId, $due, $gid] = $this->owed($request, (int) $d['invoice_id']);
        abort_if($due <= 0.004, 422, in_array($invoice->status, ['void', 'draft'], true) ? 'This invoice was cancelled, so there is nothing to pay.' : 'This invoice is already paid.');
        $stripe = self::stripeFor($agencyId);
        abort_unless($stripe, 422, 'Apple Pay and Google Pay are not set up for your centre.');
        $num = (string) ($invoice->invoice_number ?? $invoice->id);

        /* ONE PAYMENT AT A TIME (2026-11-05, Stripe end-to-end): two taps, or both parents at once, each got their own
           PaymentIntent and both were charged. The invoice's open intent (per payer on a split invoice) is handed back
           instead, at today's amount; a new one is made only when there is none still waiting to be paid. Anything that
           still gets through twice is refunded when it is recorded (AutopayRunner::record). */
        $slot = 'kt-wallet-pi:' . $invoice->id . ':' . ($gid ?: 'f');
        $lock = \Illuminate\Support\Facades\Cache::lock($slot . ':lock', 15);
        try { $lock->block(8); } catch (\Throwable $e) { return response()->json(['message' => 'A payment for this invoice is already starting. Please wait a moment.'], 409); }
        try {
            $open = (string) \Illuminate\Support\Facades\Cache::get($slot, '');
            if ($open !== '') {
                try {
                    $prev = $stripe[0]->paymentIntents->retrieve($open);
                    if ($prev->status === 'succeeded') {
                        // Paid (and recorded now if the sheet closed first). Anything still owed gets a fresh intent.
                        self::recordIntent($prev);
                        \Illuminate\Support\Facades\Cache::forget($slot);
                        $due = $this->owed($request, (int) $invoice->id)[2];
                        if ($due <= 0.004) return response()->json(['message' => 'This invoice has just been paid.'], 422);
                    }
                    if ($prev->status === 'processing') {
                        return response()->json(['message' => 'A payment for this invoice is already going through.'], 409);
                    }
                    if (in_array($prev->status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                        $cents = (int) round($due * 100);
                        if ((int) $prev->amount !== $cents) $prev = $stripe[0]->paymentIntents->update($prev->id, ['amount' => $cents]);
                        return response()->json(['client_secret' => $prev->client_secret, 'payment_intent_id' => $prev->id,
                            'amount_cents' => (int) $prev->amount, 'currency' => strtoupper((string) $prev->currency)]);
                    }
                } catch (\Throwable $e) { /* gone or cancelled: make a new one */ }
            }
            $pi = $this->newIntent($stripe, $invoice, $agencyId, $due, $gid, $d['wallet'], $num, (int) $request->user()->id);
            if ($pi instanceof JsonResponse) return $pi;
            \Illuminate\Support\Facades\Cache::put($slot, $pi->id, now()->addHours(2));
        } finally {
            optional($lock)->release();
        }

        return response()->json(['client_secret' => $pi->client_secret, 'payment_intent_id' => $pi->id,
            'amount_cents' => (int) $pi->amount, 'currency' => strtoupper((string) $pi->currency)]);
    }

    private function newIntent(array $stripe, object $invoice, int $agencyId, float $due, ?int $gid, string $wallet, string $num, int $uid)
    {
        try {
            return $stripe[0]->paymentIntents->create([
                'amount' => (int) round($due * 100),
                'currency' => strtolower($this->currency($agencyId)),
                // Apple Pay and Google Pay are card payments to Stripe.
                'payment_method_types' => ['card'],
                'description' => 'Invoice ' . $num,
                'metadata' => [
                    'invoice_id' => (string) $invoice->id, 'family_id' => (string) $invoice->family_id,
                    'agency_id' => (string) $agencyId, 'payer_guardian_id' => $gid ? (string) $gid : '',
                    'kt_wallet' => $wallet, 'kt_user' => (string) $uid,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Wallet PaymentIntent failed', ['invoice' => $invoice->id, 'agency' => $agencyId, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The payment could not be started. Please try again or use another way to pay.'], 422);
        }
    }

    /** POST /parent/wallet-pay/stripe-confirm {invoice_id, payment_intent_id} - record it once Stripe says it succeeded. */
    public function stripeConfirm(Request $request): JsonResponse
    {
        $d = $request->validate(['invoice_id' => 'required|integer', 'payment_intent_id' => ['required', 'string', 'max:80', 'regex:/^pi_[A-Za-z0-9]+$/']]);
        [$invoice, $agencyId] = $this->owed($request, (int) $d['invoice_id']);
        $stripe = self::stripeFor($agencyId);
        abort_unless($stripe, 422, 'Apple Pay and Google Pay are not set up for your centre.');
        try {
            $pi = $stripe[0]->paymentIntents->retrieve($d['payment_intent_id']);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'That payment could not be found.'], 404);
        }
        // The intent must be the one made for THIS invoice by THIS agency's account.
        abort_unless((string) ($pi->metadata->invoice_id ?? '') === (string) $invoice->id, 403, 'That payment is for a different invoice.');
        if ($pi->status !== 'succeeded') {
            return response()->json(['paid' => false, 'status' => $pi->status]);
        }
        self::recordIntent($pi);
        $inv = DB::table('invoices')->where('id', $invoice->id)->first(['balance_due', 'status']);

        return response()->json(['paid' => true, 'amount' => ((int) $pi->amount_received) / 100,
            'balance_due' => (float) ($inv->balance_due ?? 0), 'status' => $inv->status ?? null]);
    }

    /**
     * POST /parent/wallet-pay/demo {invoice_id, wallet} - the simulated Apple Pay / Google Pay payment of a test agency
     * (demoOn). Calls no processor and takes no card details. Pays what owed() says (balance, or the payer's split
     * share); the row is written to web_demo_payments, so kt:reset-web-demo (03:30 Toronto) puts the invoice back.
     */
    /** The dummy cards the demo wallet sheet offers (kt-wallet-pay.js DEMO_CARDS): key => [name, last four]. */
    private const DEMO_CARDS = [
        'visa' => ['Visa', '4242'], 'visa_debit' => ['Visa Debit', '5556'], 'mastercard' => ['Mastercard', '4444'],
        'mc_debit' => ['Mastercard Debit', '8210'], 'amex' => ['American Express', '0005'], 'discover' => ['Discover', '1117'],
        'jcb' => ['JCB', '0505'], 'unionpay' => ['UnionPay', '0005'], 'diners' => ['Diners Club', '0004'],
    ];

    public function demoPay(Request $request): JsonResponse
    {
        $d = $request->validate(['invoice_id' => 'required|integer', 'wallet' => 'required|in:apple_pay,google_pay',
            'card' => 'nullable|in:' . implode(',', array_keys(self::DEMO_CARDS))]);
        [$invoice, $agencyId, $due, $gid] = $this->owed($request, (int) $d['invoice_id']);
        abort_unless(self::demoOn($agencyId) && ! self::stripeFor($agencyId), 422, 'Apple Pay and Google Pay are not set up for your centre.');
        abort_if($due <= 0.004, 422, 'This invoice is already paid.');
        $wallet = $d['wallet'] === 'apple_pay' ? 'Apple Pay' : 'Google Pay';
        $c = self::DEMO_CARDS[$d['card'] ?? 'visa'];
        $wallet .= ' (' . $c[0] . ' ending ' . $c[1] . ')';
        $ref = 'DEMO-WALLET-' . strtoupper(\Illuminate\Support\Str::random(8));
        $uid = (int) $request->user()->id;
        $out = DB::transaction(function () use ($invoice, $due, $gid, $wallet, $ref) {
            $inv = DB::table('invoices')->where('id', $invoice->id)->lockForUpdate()->first();
            if ((float) $inv->balance_due < 0.005 || in_array($inv->status, ['paid', 'void', 'draft', 'refunded'], true)) abort(422, 'This invoice is already paid.');
            $amt = round(min($due, (float) $inv->balance_due), 2);
            $pid = DB::table('payments')->insertGetId(['invoice_id' => $inv->id, 'family_id' => $inv->family_id, 'payer_guardian_id' => $gid,
                'amount' => $amt, 'method' => 'stripe_card', 'status' => 'succeeded', 'reference_number' => $ref, 'paid_at' => now(),
                'notes' => $wallet, 'recorded_by_id' => null, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('web_demo_payments')->insert(['invoice_id' => $inv->id, 'payment_id' => $pid, 'prev_amount_paid' => $inv->amount_paid,
                'prev_balance_due' => $inv->balance_due, 'prev_status' => $inv->status, 'created_at' => now()]);
            $bal = round((float) $inv->balance_due - $amt, 2);
            DB::table('invoices')->where('id', $inv->id)->update(['amount_paid' => round((float) $inv->amount_paid + $amt, 2), 'balance_due' => $bal,
                'status' => $bal < 0.005 ? 'paid' : 'partial', 'updated_at' => now()]);

            return ['amount' => $amt, 'balance_due' => $bal, 'status' => $bal < 0.005 ? 'paid' : 'partial'];
        });
        $num = (string) ($invoice->invoice_number ?? $invoice->id);
        // The payer's own receipt bell only: a test agency can hold real staff, so no one else is told about a pretend payment.
        try {
            \App\Support\Notify::write(['user_id' => $uid, 'type' => 'payment', 'title' => '✅ Payment received - thank you!',
                'body' => 'We received $' . number_format($out['amount'], 2) . ' (' . $wallet . ') for invoice ' . $num . '. Receipt ' . $ref . '.',
                'data' => json_encode(['link' => '#billing', 'invoice_id' => (int) $invoice->id]), 'created_at' => now()]);
        } catch (\Throwable $e) {}
        try {
            \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'payments.wallet_demo', 'entity_type' => 'invoice', 'entity_id' => (int) $invoice->id,
                'payload' => json_encode(['summary' => 'Paid invoice ' . $num . ' with ' . $wallet . ' (demo - simulated, no money moved)', 'amount' => $out['amount'], 'reference' => $ref]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['paid' => true, 'demo' => true, 'reference' => $ref, 'wallet' => $wallet] + $out);
    }

    /** A succeeded wallet PaymentIntent -> one payment row (AutopayRunner::record dedupes on the intent id). */
    public static function recordIntent($pi): void
    {
        $invoiceId = (int) ($pi->metadata->invoice_id ?? 0);
        if (! $invoiceId || $pi->status !== 'succeeded') return;
        $wallet = (string) ($pi->metadata->kt_wallet ?? '');
        $note = ['apple_pay' => 'Apple Pay', 'google_pay' => 'Google Pay', 'browser' => 'Apple Pay / Google Pay'][$wallet] ?? 'Card';
        \App\Support\AutopayRunner::record($invoiceId, ! empty($pi->metadata->family_id) ? (int) $pi->metadata->family_id : null,
            (string) $pi->id, ((int) $pi->amount_received) / 100,
            ! empty($pi->metadata->payer_guardian_id) ? (int) $pi->metadata->payer_guardian_id : null, $note);
    }

    /**
     * POST /admin/payment-providers/stripe/wallet-domains - register the portal's domains with the agency's Stripe
     * account so Apple Pay shows in Safari (Stripe verifies each through the file at
     * /.well-known/apple-developer-merchantid-domain-association, which the portal serves). Agency admins only.
     */
    public function registerDomains(Request $request): JsonResponse
    {
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        $uid = (int) $request->user()->id;
        $ok = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
        abort_unless($ok && $agencyId > 0, 403, 'Only an agency admin can do this.');
        $stripe = self::stripeFor($agencyId);
        abort_unless($stripe, 422, 'Add this agency\'s Stripe keys first.');
        $a = DB::table('agencies')->where('id', $agencyId)->first(['custom_domain']);
        $domains = self::BASE_DOMAINS;
        $custom = strtolower(trim((string) ($a->custom_domain ?? ''), " /\t"));
        if ($custom !== '' && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $custom)) $domains[] = $custom;
        $out = [];
        foreach (array_unique($domains) as $dom) {
            try {
                $r = $stripe[0]->paymentMethodDomains->create(['domain_name' => $dom, 'enabled' => true]);
                try { $r = $stripe[0]->paymentMethodDomains->validate($r->id); } catch (\Throwable $e) {}
                $out[] = ['domain' => $dom, 'apple_pay' => (string) ($r->apple_pay->status ?? 'unknown'), 'google_pay' => (string) ($r->google_pay->status ?? 'unknown')];
            } catch (\Throwable $e) {
                $out[] = ['domain' => $dom, 'error' => $e->getMessage()];
            }
        }
        try {
            \App\Support\Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'payments.wallet_domains', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                'payload' => json_encode(['summary' => 'Registered ' . implode(', ', array_column($out, 'domain')) . ' for Apple Pay / Google Pay with Stripe', 'result' => $out]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['domains' => $out]);
    }
}
