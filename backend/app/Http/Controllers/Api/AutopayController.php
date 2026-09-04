<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v13: Autopay.
 *
 * Flow:
 *   1. Parent on billing screen taps "Enable autopay"
 *   2. POST /parent/billing/setup-intent → backend creates SetupIntent on the
 *      AGENCY'S Connect account, returns client_secret to frontend
 *   3. Frontend uses Stripe.js elements to collect card → confirms SetupIntent
 *   4. Frontend POSTs /parent/billing/confirm-autopay { payment_method_id }
 *   5. Backend saves PM on family + flips autopay_enabled = true
 *   6. Nightly cron `php artisan kiddietrac:auto-charge-invoices` charges sent invoices
 *
 * Stripe-Account header is used on every call because payments live on the
 * agency's Connect account.
 */
final class AutopayController extends Controller
{
    private function stripeKey(): ?string
    {
        return env('STRIPE_SECRET_KEY');
    }

    private function stripe(string $method, string $path, array $params = [], ?string $accountId = null)
    {
        $key = $this->stripeKey();
        if (!$key) return ['status' => 0, 'body' => ['error' => ['message' => 'Stripe not configured']]];

        $url = 'https://api.stripe.com/v1/' . ltrim($path, '/');
        $headers = $accountId ? ['Stripe-Account' => $accountId] : [];

        $http = Http::withBasicAuth($key, '')->withHeaders($headers);
        if ($method === 'POST') $resp = $http->asForm()->post($url, $params);
        else                    $resp = $http->get($url, $params);

        return ['status' => $resp->status(), 'body' => $resp->json() ?: []];
    }

    /**
     * GET /api/v1/parent/billing/autopay-status
     * Show current autopay status + card on file.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $familyIds = DB::table('guardians')->where('user_id', $user->id)->pluck('family_id')->all();
        if (empty($familyIds)) return response()->json(['families' => []]);

        $families = DB::table('families')->whereIn('id', $familyIds)->whereNull('deleted_at')->get()->map(function ($f) {
            return [
                'family_id' => $f->id,
                'family_name' => $f->family_name,
                'autopay_enabled' => (bool) $f->autopay_enabled,
                'card_last4' => $f->autopay_card_last4,
                'card_brand' => $f->autopay_card_brand,
            ];
        });

        return response()->json(['families' => $families]);
    }

    /**
     * POST /api/v1/parent/billing/setup-intent
     * Returns a Stripe SetupIntent client_secret for collecting a card.
     */
    public function setupIntent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => ['required', 'integer'],
        ]);

        $user = $request->user();
        if (! $this->isGuardianOf($user->id, $data['family_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $family = DB::table('families')->where('id', $data['family_id'])->first();
        $centre = DB::table('centres')->where('id', $family->centre_id)->first();
        $agency = DB::table('agencies')->where('id', $centre->agency_id)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $connectId = $settings['stripe']['connect_id'] ?? null;

        if (!$connectId) {
            return response()->json([
                'message' => 'Online payments not yet set up for this centre.',
                'needs_setup' => true,
            ], 503);
        }

        // Get-or-create Stripe Customer for this family ON the connect account
        $customerId = $family->stripe_customer_id;
        if (!$customerId) {
            $r = $this->stripe('POST', 'customers', [
                'email' => $family->primary_email ?? $user->email,
                'name' => $family->family_name,
                'metadata[family_id]' => (string) $family->id,
                'metadata[centre_id]' => (string) $centre->id,
            ], $connectId);
            if ($r['status'] !== 200) {
                return response()->json([
                    'message' => 'Could not create customer',
                    'detail' => $r['body']['error']['message'] ?? null,
                ], 500);
            }
            $customerId = $r['body']['id'];
            DB::table('families')->where('id', $family->id)->update([
                'stripe_customer_id' => $customerId,
                'updated_at' => now(),
            ]);
        }

        // Create SetupIntent
        $r = $this->stripe('POST', 'setup_intents', [
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types[]' => 'card',
            'metadata[family_id]' => (string) $family->id,
        ], $connectId);

        if ($r['status'] !== 200) {
            return response()->json([
                'message' => 'Could not create setup intent',
                'detail' => $r['body']['error']['message'] ?? null,
            ], 500);
        }

        return response()->json([
            'client_secret' => $r['body']['client_secret'],
            'connect_account_id' => $connectId,
            'customer_id' => $customerId,
            'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        ]);
    }

    /**
     * POST /api/v1/parent/billing/confirm-autopay
     * Called after Stripe.js confirms the SetupIntent — saves the payment method.
     */
    public function confirmAutopay(Request $request): JsonResponse
    {
        $data = $request->validate([
            'family_id' => ['required', 'integer'],
            'payment_method_id' => ['required', 'string', 'max:120'],
        ]);

        $user = $request->user();
        if (! $this->isGuardianOf($user->id, $data['family_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $family = DB::table('families')->where('id', $data['family_id'])->first();
        $centre = DB::table('centres')->where('id', $family->centre_id)->first();
        $agency = DB::table('agencies')->where('id', $centre->agency_id)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $connectId = $settings['stripe']['connect_id'] ?? null;

        // Fetch payment method details (last4, brand)
        $r = $this->stripe('GET', 'payment_methods/' . $data['payment_method_id'], [], $connectId);
        $last4 = null;
        $brand = null;
        if ($r['status'] === 200 && isset($r['body']['card'])) {
            $last4 = $r['body']['card']['last4'] ?? null;
            $brand = $r['body']['card']['brand'] ?? null;
        }

        DB::table('families')->where('id', $family->id)->update([
            'autopay_enabled' => 1,
            'autopay_payment_method_id' => $data['payment_method_id'],
            'autopay_card_last4' => $last4,
            'autopay_card_brand' => $brand,
            'updated_at' => now(),
        ]);

        \App\Support\Audit::write([
            'user_id' => $user->id,
            'action' => 'autopay.enabled',
            'entity_type' => 'family',
            'entity_id' => $family->id,
            'payload' => json_encode(['last4' => $last4, 'brand' => $brand]),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'card_last4' => $last4,
            'card_brand' => $brand,
        ]);
    }

    /**
     * POST /api/v1/parent/billing/disable-autopay
     */
    public function disableAutopay(Request $request): JsonResponse
    {
        $data = $request->validate(['family_id' => ['required', 'integer']]);
        $user = $request->user();
        if (! $this->isGuardianOf($user->id, $data['family_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        DB::table('families')->where('id', $data['family_id'])->update([
            'autopay_enabled' => 0,
            'autopay_payment_method_id' => null,
            'autopay_card_last4' => null,
            'autopay_card_brand' => null,
            'updated_at' => now(),
        ]);

        \App\Support\Audit::write([
            'user_id' => $user->id,
            'action' => 'autopay.disabled',
            'entity_type' => 'family',
            'entity_id' => $data['family_id'],
            'payload' => null,
            'created_at' => now(),
        ]);

        return response()->json(['success' => true]);
    }

    /* ─── HELPERS ────────────────────────────────────────────────── */

    private function isGuardianOf(int $userId, int $familyId): bool
    {
        return DB::table('guardians')
            ->where('user_id', $userId)
            ->where('family_id', $familyId)
            ->exists();
    }
}
