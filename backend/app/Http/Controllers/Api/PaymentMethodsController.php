<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Parent Wallet. Stores multiple payment methods per family
 * with a single is_default flag. Mirrors Procare's 6-method limit.
 *
 * On the centre's own Stripe account (2026-11-05, App\Support\AgencyStripe), like every other family payment; it used
 * KiddieTrac's .env key, a placeholder, so adding a card always answered "not set up".
 */
final class PaymentMethodsController extends Controller
{
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
        $data = $request->validate([
            'payment_method' => 'required|string|max:120',
            'nickname' => 'nullable|string|max:60',
            'set_default' => 'nullable|boolean',
        ]);
        $family = $this->parentFamily($request);
        $client = $this->stripe($family);

        $count = DB::table('payment_methods')->where('family_id', $family->id)->where('active', 1)->count();
        abort_unless($count < 6, 422, 'Wallet limit reached (6 methods)');

        $customerId = $this->customer($client, $family, (string) $request->user()->email);

        try {
            $pm = $client->paymentMethods->retrieve($data['payment_method']);
        } catch (\Throwable $e) {
            abort(422, 'That card could not be found. Please add it again.');
        }
        if (($pm->customer ?? null) !== $customerId) {
            $pm = $client->paymentMethods->attach($pm->id, ['customer' => $customerId]);
        }

        $isDefault = $data['set_default'] ?? ($count === 0);
        if ($isDefault) {
            DB::table('payment_methods')->where('family_id', $family->id)->update(['is_default' => 0]);
            $client->customers->update($customerId, ['invoice_settings' => ['default_payment_method' => $pm->id]]);
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
        $client = AgencyStripe::client(AgencyStripe::agencyForFamily((int) $family->id));
        if ($client && $family->stripe_customer_id) {
            try { $client->customers->update($family->stripe_customer_id, ['invoice_settings' => ['default_payment_method' => $pm->stripe_pm_id]]); } catch (\Throwable $e) { /* the local default still moves */ }
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
        if ($client = AgencyStripe::client(AgencyStripe::agencyForFamily((int) $family->id))) {
            try { $client->paymentMethods->detach($pm->stripe_pm_id); } catch (\Throwable $e) { /* swallow */ }
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
        $data = $request->validate(['type' => 'required|in:card,acss_debit']);
        $family = $this->parentFamily($request);
        $client = $this->stripe($family);
        $aid = AgencyStripe::agencyForFamily((int) $family->id);
        $customerId = $this->customer($client, $family, (string) $request->user()->email);
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
        $si = $client->setupIntents->create($params);
        return response()->json(['client_secret' => $si->client_secret, 'publishable_key' => AgencyStripe::publishable($aid)]);
    }

    private function stripe(object $family): \Stripe\StripeClient
    {
        $client = AgencyStripe::client(AgencyStripe::agencyForFamily((int) $family->id));
        abort_unless($client, 503, AgencyStripe::NOT_SET);

        return $client;
    }

    /** The family's customer on this agency's account (remade if the centre changed Stripe account). */
    private function customer(\Stripe\StripeClient $client, object $family, string $email): string
    {
        $id = (string) ($family->stripe_customer_id ?? '');
        if ($id !== '') {
            try { $c = $client->customers->retrieve($id); if (empty($c->deleted)) return $id; } catch (\Throwable $e) {}
        }
        $c = $client->customers->create(['name' => (string) $family->family_name, 'email' => $email, 'metadata' => ['family_id' => (string) $family->id]]);
        DB::table('families')->where('id', $family->id)->update(['stripe_customer_id' => $c->id, 'updated_at' => now()]);
        $family->stripe_customer_id = $c->id;

        return $c->id;
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
