<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Stripe\Customer;
use Stripe\PaymentMethod;
use Stripe\Stripe;

/**
 * v22p58 — Parent Wallet. Stores multiple payment methods per family
 * with a single is_default flag. Mirrors Procare's 6-method limit.
 */
final class PaymentMethodsController extends Controller
{
    public function __construct()
    {
        if ($k = env('STRIPE_SECRET')) Stripe::setApiKey($k);
    }

    public function list(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request);
        $rows = DB::table('payment_methods')
            ->where('family_id', $family->id)
            ->where('active', 1)
            ->orderByDesc('is_default')->orderBy('created_at')
            ->get();
        return response()->json(['data' => $rows, 'limit' => 6]);
    }

    public function add(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503, 'Stripe not configured');
        $data = $request->validate([
            'payment_method' => 'required|string',
            'nickname' => 'nullable|string|max:60',
            'set_default' => 'nullable|boolean',
        ]);
        $family = $this->parentFamily($request);

        $count = DB::table('payment_methods')->where('family_id', $family->id)->where('active', 1)->count();
        abort_unless($count < 6, 422, 'Wallet limit reached (6 methods)');

        // Ensure Stripe customer
        $customerId = $family->stripe_customer_id;
        if (!$customerId) {
            $customer = Customer::create([
                'name' => (string) $family->family_name,
                'email' => (string) $request->user()->email,
                'metadata' => ['family_id' => (string) $family->id],
            ]);
            $customerId = $customer->id;
            DB::table('families')->where('id', $family->id)->update([
                'stripe_customer_id' => $customerId, 'updated_at' => now(),
            ]);
        }

        $pm = PaymentMethod::retrieve($data['payment_method']);
        if (($pm->customer ?? null) !== $customerId) {
            $pm->attach(['customer' => $customerId]);
        }

        $isDefault = $data['set_default'] ?? ($count === 0);
        if ($isDefault) {
            DB::table('payment_methods')->where('family_id', $family->id)->update(['is_default' => 0]);
            Customer::update($customerId, ['invoice_settings' => ['default_payment_method' => $pm->id]]);
        }

        $card = $pm->card ?? null;
        $bank = $pm->acss_debit ?? null;
        $id = DB::table('payment_methods')->insertGetId([
            'family_id' => $family->id,
            'stripe_pm_id' => $pm->id,
            'type' => $pm->type,
            'last4' => $card->last4 ?? $bank->last4 ?? null,
            'brand' => $card->brand ?? null,
            'exp_month' => $card->exp_month ?? null,
            'exp_year' => $card->exp_year ?? null,
            'bank_name' => $bank->bank_name ?? null,
            'nickname' => $data['nickname'] ?? null,
            'is_default' => $isDefault ? 1 : 0,
            'active' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($isDefault) {
            DB::table('families')->where('id', $family->id)->update([
                'autopay_payment_method_id' => $pm->id,
                'autopay_card_last4' => $card->last4 ?? $bank->last4 ?? null,
                'autopay_card_brand' => $card->brand ?? ($pm->type === 'acss_debit' ? 'acss_debit' : null),
                'autopay_enabled' => 1,
                'updated_at' => now(),
            ]);
        }
        return response()->json(['id' => $id, 'is_default' => $isDefault], 201);
    }

    public function setDefault(Request $request, int $id): JsonResponse
    {
        $family = $this->parentFamily($request);
        $pm = DB::table('payment_methods')->where('id', $id)->where('family_id', $family->id)->first();
        abort_unless($pm, 404);
        DB::table('payment_methods')->where('family_id', $family->id)->update(['is_default' => 0]);
        DB::table('payment_methods')->where('id', $id)->update(['is_default' => 1, 'updated_at' => now()]);
        if (env('STRIPE_SECRET') && $family->stripe_customer_id) {
            Customer::update($family->stripe_customer_id, [
                'invoice_settings' => ['default_payment_method' => $pm->stripe_pm_id],
            ]);
        }
        DB::table('families')->where('id', $family->id)->update([
            'autopay_payment_method_id' => $pm->stripe_pm_id,
            'autopay_card_last4' => $pm->last4,
            'autopay_card_brand' => $pm->brand ?? $pm->type,
            'updated_at' => now(),
        ]);
        return response()->json(['status' => 'set_default']);
    }

    public function remove(Request $request, int $id): JsonResponse
    {
        $family = $this->parentFamily($request);
        $pm = DB::table('payment_methods')->where('id', $id)->where('family_id', $family->id)->first();
        abort_unless($pm, 404);
        if (env('STRIPE_SECRET')) {
            try { PaymentMethod::retrieve($pm->stripe_pm_id)->detach(); } catch (\Throwable $e) { /* swallow */ }
        }
        DB::table('payment_methods')->where('id', $id)->update(['active' => 0, 'is_default' => 0, 'updated_at' => now()]);
        // If this was the default, promote another
        if ($pm->is_default) {
            $next = DB::table('payment_methods')->where('family_id', $family->id)->where('active', 1)->orderBy('created_at')->first();
            if ($next) {
                DB::table('payment_methods')->where('id', $next->id)->update(['is_default' => 1]);
                DB::table('families')->where('id', $family->id)->update([
                    'autopay_payment_method_id' => $next->stripe_pm_id,
                    'autopay_card_last4' => $next->last4,
                    'autopay_card_brand' => $next->brand ?? $next->type,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('families')->where('id', $family->id)->update([
                    'autopay_payment_method_id' => null, 'autopay_card_last4' => null,
                    'autopay_card_brand' => null, 'autopay_enabled' => 0, 'updated_at' => now(),
                ]);
            }
        }
        return response()->json(['status' => 'removed']);
    }

    public function setupIntent(Request $request): JsonResponse
    {
        abort_unless(env('STRIPE_SECRET'), 503);
        $data = $request->validate(['type' => 'required|in:card,acss_debit']);
        $family = $this->parentFamily($request);
        $customerId = $family->stripe_customer_id;
        if (!$customerId) {
            $customer = Customer::create([
                'name' => (string) $family->family_name,
                'email' => (string) $request->user()->email,
                'metadata' => ['family_id' => (string) $family->id],
            ]);
            $customerId = $customer->id;
            DB::table('families')->where('id', $family->id)->update([
                'stripe_customer_id' => $customerId, 'updated_at' => now(),
            ]);
        }
        $params = [
            'customer' => $customerId,
            'payment_method_types' => [$data['type']],
            'usage' => 'off_session',
        ];
        if ($data['type'] === 'acss_debit') {
            $params['payment_method_options'] = [
                'acss_debit' => [
                    'currency' => 'cad',
                    'mandate_options' => [
                        'payment_schedule' => 'sporadic',
                        'transaction_type' => 'personal',
                    ],
                ],
            ];
        }
        $si = \Stripe\SetupIntent::create($params);
        return response()->json(['client_secret' => $si->client_secret, 'publishable_key' => env('STRIPE_KEY')]);
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
