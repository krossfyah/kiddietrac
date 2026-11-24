<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyStripe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — ACH / acss_debit (Canadian Pre-Authorized Debit) payment
 * method for families. Lives alongside the credit-card autopay from
 * v22p51.
 *
 * On the centre's own Stripe account (2026-11-05, App\Support\AgencyStripe), like every other family payment.
 */
final class BillingV3Controller extends Controller
{
    private function stripe(object $family): array
    {
        $aid = AgencyStripe::agencyForFamily((int) $family->id);
        $client = AgencyStripe::client($aid);
        abort_unless($client, 503, AgencyStripe::NOT_SET);

        return [$client, $aid];
    }

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

    public function achSetupIntent(Request $request): JsonResponse
    {
        $family = $this->parentFamily($request);
        [$client, $aid] = $this->stripe($family);
        $customerId = $this->customer($client, $family, (string) $request->user()->email);
        $si = $client->setupIntents->create([
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
            'publishable_key' => AgencyStripe::publishable($aid),
        ]);
    }

    public function saveAch(Request $request): JsonResponse
    {
        $data = $request->validate(['payment_method' => 'required|string|max:120']);
        $family = $this->parentFamily($request);
        [$client] = $this->stripe($family);
        $customerId = $this->customer($client, $family, (string) $request->user()->email);

        try {
            $pm = $client->paymentMethods->retrieve($data['payment_method']);
        } catch (\Throwable $e) {
            abort(422, 'That bank account could not be found. Please add it again.');
        }
        if (($pm->customer ?? null) !== $customerId) {
            $pm = $client->paymentMethods->attach($pm->id, ['customer' => $customerId]);
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
            $client->customers->update($customerId, [
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
