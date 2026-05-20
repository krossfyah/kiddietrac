<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;
use Stripe\Customer;
use Stripe\SetupIntent;
use Stripe\PaymentMethod;
use Stripe\PaymentIntent;

/**
 * v22p51 — Stripe recurring auto-pay for families.
 * Distinct from StripeBillingController (which handles platform↔agency
 * billing). This is family↔agency: parents save a card, monthly invoices
 * auto-charge against it.
 *
 * Endpoints used by the parent portal:
 *   POST /parent/billing/setup-intent  → SetupIntent client_secret
 *   POST /parent/billing/save-card     → mark default PM + autopay on
 *   POST /parent/billing/autopay       → toggle autopay
 *   GET  /parent/billing/status        → current PM + autopay state
 *
 * Director side:
 *   POST /invoices/{id}/charge         → manually charge a saved card
 *
 * Cron (in console.php):
 *   invoices:autopay-charge runs daily at 03:00, charges any
 *   {status:sent, autopay_enabled=1, balance_due>0} invoice.
 */
final class StripeParentPayController extends Controller
{
    public function __construct()
    {
        $key = env('STRIPE_SECRET');
        if ($key) Stripe::setApiKey($key);
    }

    public function status(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request);
        return response()->json([
            'autopay_enabled' => (bool) $family->autopay_enabled,
            'has_card'        => !empty($family->autopay_payment_method_id),
            'card_last4'      => $family->autopay_payment_method_id
                ? $this->cardLast4($family->stripe_customer_id, $family->autopay_payment_method_id) : null,
        ]);
    }

    public function setupIntent(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503, 'Stripe not configured');
        $family = $this->parentFamily($request);
        $customerId = $family->stripe_customer_id;
        if (!$customerId) {
            $customer = Customer::create([
                'name'  => (string) $family->family_name,
                'email' => (string) $request->user()->email,
                'metadata' => ['family_id' => (string) $family->id],
            ]);
            $customerId = $customer->id;
            DB::table('families')->where('id', $family->id)
                ->update(['stripe_customer_id' => $customerId, 'updated_at' => now()]);
        }
        $si = SetupIntent::create([
            'customer' => $customerId,
            'usage'    => 'off_session',
            'payment_method_types' => ['card'],
        ]);
        return response()->json([
            'client_secret' => $si->client_secret,
            'publishable_key' => env('STRIPE_KEY'),
        ]);
    }

    public function saveCard(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503);
        $data = $request->validate(['payment_method' => 'required|string']);
        $family = $this->parentFamily($request);
        PaymentMethod::retrieve($data['payment_method'])
            ->attach(['customer' => $family->stripe_customer_id]);
        Customer::update($family->stripe_customer_id, [
            'invoice_settings' => ['default_payment_method' => $data['payment_method']],
        ]);
        DB::table('families')->where('id', $family->id)->update([
            'autopay_payment_method_id' => $data['payment_method'],
            'autopay_enabled'   => 1,
            'updated_at'        => now(),
        ]);
        return response()->json(['status' => 'saved', 'autopay_enabled' => true]);
    }

    public function toggleAutopay(Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $family = $this->parentFamily($request);
        if ($data['enabled'] && empty($family->autopay_payment_method_id)) {
            return response()->json(['error' => 'Save a card first'], 422);
        }
        DB::table('families')->where('id', $family->id)->update([
            'autopay_enabled' => $data['enabled'] ? 1 : 0,
            'updated_at'      => now(),
        ]);
        return response()->json(['autopay_enabled' => $data['enabled']]);
    }

    /**
     * Manual charge: director presses "Charge saved card" on an invoice.
     */
    public function chargeInvoice(Request $request, int $invoiceId): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503);
        $invoice = DB::table('invoices')->where('id', $invoiceId)->first();
        abort_unless($invoice, 404);
        $family = DB::table('families')->where('id', $invoice->family_id)->first();
        abort_unless($family && $family->autopay_payment_method_id, 422, 'Family has no saved card');
        $cents = (int) round(((float) $invoice->balance_due) * 100);
        abort_unless($cents > 0, 422, 'Nothing to charge');

        try {
            $pi = PaymentIntent::create([
                'amount'   => $cents,
                'currency' => strtolower(env('STRIPE_CURRENCY', 'cad')),
                'customer' => $family->stripe_customer_id,
                'payment_method' => $family->autopay_payment_method_id,
                'off_session' => true,
                'confirm' => true,
                'metadata' => [
                    'invoice_id' => (string) $invoice->id,
                    'family_id'  => (string) $family->id,
                    'agency_id'  => (string) $invoice->agency_id,
                ],
            ]);
            return response()->json(['status' => $pi->status, 'amount' => $cents]);
        } catch (\Throwable $e) {
            Log::warning('Stripe charge failed', ['invoice' => $invoiceId, 'msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * Stripe webhook — payment_intent.succeeded creates a payment row.
     */
    public function webhook(Request $request): JsonResponse
    {
        $body = $request->getContent();
        $sig = $request->header('Stripe-Signature');
        $secret = env('STRIPE_PARENT_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET'));
        try {
            $event = \Stripe\Webhook::constructEvent($body, $sig, $secret);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'sig'], 400);
        }
        if ($event->type === 'payment_intent.succeeded') {
            $pi = $event->data->object;
            $invoiceId = (int) ($pi->metadata->invoice_id ?? 0);
            if ($invoiceId) {
                $amount = ((int) $pi->amount_received) / 100;
                $exists = DB::table('payments')->where('stripe_pi_id', $pi->id)->exists();
                if (!$exists) {
                    DB::table('payments')->insert([
                        'invoice_id'   => $invoiceId,
                        'amount'       => $amount,
                        'method'       => 'stripe',
                        'paid_at'      => now(),
                        'stripe_pi_id' => $pi->id,
                        'created_at'   => now(),
                        'updated_at'   => now(),
                    ]);
                    $inv = DB::table('invoices')->where('id', $invoiceId)->first();
                    if ($inv) {
                        $newBal = max(0, ((float) $inv->balance_due) - $amount);
                        DB::table('invoices')->where('id', $invoiceId)->update([
                            'balance_due' => $newBal,
                            'status'      => $newBal <= 0.01 ? 'paid' : $inv->status,
                            'updated_at'  => now(),
                        ]);
                    }
                }
            }
        }
        return response()->json(['ok' => true]);
    }

    private function parentFamily(Request $request)
    {
        $u = $request->user();
        $famId = DB::table('guardians')->where('user_id', $u->id)->value('family_id');
        abort_unless($famId, 404, 'No family linked');
        $f = DB::table('families')->where('id', $famId)->first();
        abort_unless($f, 404);
        return $f;
    }

    private function cardLast4(string $customerId, string $pmId): ?string
    {
        try {
            $pm = PaymentMethod::retrieve($pmId);
            return $pm->card->last4 ?? null;
        } catch (\Throwable $e) { return null; }
    }
}
