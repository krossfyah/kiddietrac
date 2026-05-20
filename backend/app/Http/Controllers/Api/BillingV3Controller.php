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

/**
 * v22p57 — ACH / acss_debit (Canadian Pre-Authorized Debit) payment
 * method for families. Lives alongside the credit-card autopay from
 * v22p51.
 */
final class BillingV3Controller extends Controller
{
    public function __construct()
    {
        if ($k = env('STRIPE_SECRET')) Stripe::setApiKey($k);
    }

    public function achSetupIntent(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503, 'Stripe not configured');
        $family = $this->parentFamily($request);
        // Ensure Stripe customer exists
        $customerId = $family->stripe_customer_id;
        if (!$customerId) {
            $customer = Customer::create([
                'name' => (string) $family->family_name,
                'email' => (string) $request->user()->email,
                'metadata' => ['family_id' => (string) $family->id],
            ]);
            $customerId = $customer->id;
            DB::table('families')->where('id', $family->id)
                ->update(['stripe_customer_id' => $customerId, 'updated_at' => now()]);
        }
        $si = SetupIntent::create([
            'customer' => $customerId,
            'payment_method_types' => ['acss_debit'],
            'payment_method_options' => [
                'acss_debit' => [
                    'currency' => 'cad',
                    'mandate_options' => [
                        'payment_schedule' => 'sporadic',
                        'transaction_type' => 'personal',
                    ],
                ],
            ],
            'usage' => 'off_session',
        ]);
        return response()->json([
            'client_secret' => $si->client_secret,
            'publishable_key' => env('STRIPE_KEY'),
        ]);
    }

    public function saveAch(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503);
        $data = $request->validate(['payment_method' => 'required|string']);
        $family = $this->parentFamily($request);

        $pm = PaymentMethod::retrieve($data['payment_method']);
        if (($pm->customer ?? null) !== $family->stripe_customer_id) {
            $pm->attach(['customer' => $family->stripe_customer_id]);
        }
        $bank = $pm->acss_debit ?? null;
        DB::table('families')->where('id', $family->id)->update([
            'ach_payment_method_id' => $pm->id,
            'ach_last4' => $bank->last4 ?? null,
            'ach_bank_name' => $bank->bank_name ?? null,
            'ach_verified_at' => now(),
            'updated_at' => now(),
        ]);
        // Also set as default for autopay if no card on file
        if (empty($family->autopay_payment_method_id)) {
            Customer::update($family->stripe_customer_id, [
                'invoice_settings' => ['default_payment_method' => $pm->id],
            ]);
            DB::table('families')->where('id', $family->id)->update([
                'autopay_payment_method_id' => $pm->id,
                'autopay_card_brand' => 'acss_debit',
                'autopay_card_last4' => $bank->last4 ?? null,
                'autopay_enabled' => 1,
                'updated_at' => now(),
            ]);
        }
        return response()->json([
            'status' => 'saved',
            'last4' => $bank->last4 ?? null,
            'bank_name' => $bank->bank_name ?? null,
        ]);
    }

    public function achStatus(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request);
        return response()->json([
            'has_ach' => !empty($family->ach_payment_method_id),
            'last4' => $family->ach_last4,
            'bank_name' => $family->ach_bank_name,
            'verified_at' => $family->ach_verified_at,
        ]);
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
}
