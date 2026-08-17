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
 * Stripe Connect Express billing for resellers.
 *
 * Pattern: Each agency (sub-tenant centre) has its own Stripe Connect account.
 * Platform (you, Kiddietrac) collects a fixed monthly platform fee from each agency
 * via Stripe Subscriptions on the agency's connected account, with destination charges
 * so the platform fee lands in your account.
 *
 * Required env vars:
 *   STRIPE_SECRET_KEY=sk_live_... (or sk_test_...)
 *   STRIPE_PLATFORM_FEE_PCT=10  (10% of each subscription)
 *   STRIPE_MONTHLY_PRICE_ID=price_...  (the platform's subscription price)
 *   STRIPE_WEBHOOK_SECRET=whsec_...
 *   STRIPE_CONNECT_RETURN_URL=https://app.kiddietrac.com/admin/billing?status=ok
 *   STRIPE_CONNECT_REFRESH_URL=https://app.kiddietrac.com/admin/billing?status=refresh
 *
 * Schema additions (see migration v9_billing_tables):
 *   agencies.stripe_connect_id varchar(80) nullable
 *   agencies.stripe_subscription_id varchar(80) nullable
 *   agencies.stripe_subscription_status varchar(40) nullable
 */
final class StripeBillingController extends Controller
{
    private function stripeKey(): ?string
    {
        return env('STRIPE_SECRET_KEY');
    }

    private function getAgencyId(Request $request): ?int
    {
        // v22p20: honor X-Active-Agency-Id header for multi-agency admins.
        // v22p21: platform_admin trumps tenant scope — they can target ANY agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatformAdmin) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) {
                return $activeId;
            }
            // platform_admin without header — default to the first agency.
            $first = DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
            if ($first) return (int) $first;
        }
        if ($activeId && DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('agency_id', $activeId)
                ->where('active', true)
                ->exists()) {
            return $activeId;
        }
        return DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'agency_admin')
            ->where('active', true)
            ->value('agency_id');
    }

    private function stripeRequest(string $method, string $endpoint, array $params = []): array
    {
        $key = $this->stripeKey();
        if (!$key) {
            return ['error' => 'Stripe not configured', 'status' => 0];
        }

        $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');

        if ($method === 'GET') {
            $response = Http::withBasicAuth($key, '')->get($url, $params);
        } elseif ($method === 'POST') {
            $response = Http::withBasicAuth($key, '')->asForm()->post($url, $params);
        } elseif ($method === 'DELETE') {
            $response = Http::withBasicAuth($key, '')->delete($url);
        } else {
            return ['error' => 'Invalid method'];
        }

        return [
            'status' => $response->status(),
            'body' => $response->json() ?: [],
        ];
    }

    /**
     * GET /api/v1/admin/billing/status
     * Returns current billing state for the agency.
     */
    public function status(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $billing = $settings['stripe'] ?? [];

        $configured = !empty($this->stripeKey());

        return response()->json([
            'configured' => $configured,
            'billing_status' => $agency->billing_status,
            'trial_ends_at' => $agency->trial_ends_at,
            'connect_id' => $billing['connect_id'] ?? null,
            'connect_status' => $billing['connect_status'] ?? 'not_connected',
            'subscription_id' => $billing['subscription_id'] ?? null,
            'subscription_status' => $billing['subscription_status'] ?? null,
            'platform_fee_pct' => (int) env('STRIPE_PLATFORM_FEE_PCT', 10),
        ]);
    }

    /**
     * POST /api/v1/admin/billing/connect
     * Initiate Stripe Connect onboarding. Returns URL to redirect to.
     */
    public function startConnect(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        if (!$this->stripeKey()) {
            return response()->json([
                'error' => 'Stripe is not configured. Add STRIPE_SECRET_KEY to .env first.',
            ], 503);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];

        $connectId = $settings['stripe']['connect_id'] ?? null;

        // Create Express account if not exists
        if (!$connectId) {
            $resp = $this->stripeRequest('POST', 'accounts', [
                'type' => 'express',
                'country' => 'CA',
                'email' => $agency->contact_email ?: $request->user()->email,
                'capabilities[card_payments][requested]' => 'true',
                'capabilities[transfers][requested]' => 'true',
                'business_type' => 'company',
                'business_profile[name]' => $agency->name,
                'metadata[agency_id]' => (string) $agencyId,
                'metadata[platform]' => 'kiddietrac',
            ]);

            if (($resp['status'] ?? 0) !== 200) {
                Log::error('Stripe account create failed', $resp);
                return response()->json(['error' => 'Stripe account creation failed', 'details' => $resp['body'] ?? null], 500);
            }

            $connectId = $resp['body']['id'];
            $settings['stripe'] = $settings['stripe'] ?? [];
            $settings['stripe']['connect_id'] = $connectId;
            $settings['stripe']['connect_status'] = 'created';

            DB::table('agencies')->where('id', $agencyId)->update([
                'settings' => json_encode($settings),
                'updated_at' => now(),
            ]);
        }

        // Create onboarding link
        $linkResp = $this->stripeRequest('POST', 'account_links', [
            'account' => $connectId,
            'refresh_url' => env('STRIPE_CONNECT_REFRESH_URL', 'https://app.kiddietrac.com/admin/billing?status=refresh'),
            'return_url' => env('STRIPE_CONNECT_RETURN_URL', 'https://app.kiddietrac.com/admin/billing?status=ok'),
            'type' => 'account_onboarding',
        ]);

        if (($linkResp['status'] ?? 0) !== 200) {
            return response()->json(['error' => 'Could not create onboarding link', 'details' => $linkResp['body'] ?? null], 500);
        }

        return response()->json([
            'onboarding_url' => $linkResp['body']['url'],
            'expires_at' => $linkResp['body']['expires_at'],
        ]);
    }

    /**
     * POST /api/v1/admin/billing/subscribe
     * After Connect onboarding done, create the platform subscription.
     */
    public function subscribe(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $priceId = env('STRIPE_MONTHLY_PRICE_ID');
        if (!$priceId) {
            return response()->json(['error' => 'No subscription price configured'], 503);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $connectId = $settings['stripe']['connect_id'] ?? null;

        if (!$connectId) {
            return response()->json(['error' => 'Must complete Stripe Connect first'], 422);
        }

        // Verify account is fully onboarded
        $acctResp = $this->stripeRequest('GET', "accounts/{$connectId}");
        if (($acctResp['status'] ?? 0) !== 200 || empty($acctResp['body']['charges_enabled'])) {
            return response()->json(['error' => 'Stripe Connect onboarding incomplete'], 422);
        }

        // Create customer on platform, then subscription with application fee
        $customerResp = $this->stripeRequest('POST', 'customers', [
            'email' => $agency->contact_email ?: $request->user()->email,
            'name' => $agency->name,
            'metadata[agency_id]' => (string) $agencyId,
        ]);

        if (($customerResp['status'] ?? 0) !== 200) {
            return response()->json(['error' => 'Could not create customer', 'details' => $customerResp['body'] ?? null], 500);
        }

        $customerId = $customerResp['body']['id'];
        $feePct = (int) env('STRIPE_PLATFORM_FEE_PCT', 10);

        $subResp = $this->stripeRequest('POST', 'subscriptions', [
            'customer' => $customerId,
            'items[0][price]' => $priceId,
            'application_fee_percent' => $feePct,
            'transfer_data[destination]' => $connectId,
            'metadata[agency_id]' => (string) $agencyId,
            'trial_period_days' => '14',
        ]);

        if (($subResp['status'] ?? 0) !== 200) {
            return response()->json(['error' => 'Subscription creation failed', 'details' => $subResp['body'] ?? null], 500);
        }

        $subId = $subResp['body']['id'];
        $subStatus = $subResp['body']['status'];

        $settings['stripe']['customer_id'] = $customerId;
        $settings['stripe']['subscription_id'] = $subId;
        $settings['stripe']['subscription_status'] = $subStatus;

        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'billing_status' => $subStatus === 'trialing' ? 'trial' : 'active',
            'updated_at' => now(),
        ]);

        return response()->json([
            'subscription_id' => $subId,
            'status' => $subStatus,
            'message' => 'Subscription created',
        ]);
    }

    /**
     * POST /api/v1/admin/billing/cancel
     */
    public function cancel(Request $request): JsonResponse
    {
        $agencyId = $this->getAgencyId($request);
        if (!$agencyId) return response()->json(['message' => 'No agency access'], 403);

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $settings = json_decode($agency->settings ?? '{}', true) ?: [];
        $subId = $settings['stripe']['subscription_id'] ?? null;

        if (!$subId) return response()->json(['message' => 'No active subscription'], 422);

        $resp = $this->stripeRequest('DELETE', "subscriptions/{$subId}");
        if (($resp['status'] ?? 0) !== 200) {
            return response()->json(['error' => 'Cancellation failed', 'details' => $resp['body'] ?? null], 500);
        }

        $settings['stripe']['subscription_status'] = 'canceled';
        DB::table('agencies')->where('id', $agencyId)->update([
            'settings' => json_encode($settings),
            'billing_status' => 'suspended',
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Subscription canceled']);
    }

    /**
     * POST /api/v1/stripe/webhook  (public, signature-verified)
     * Stripe sends events here to keep our DB in sync.
     */
    public function webhook(Request $request): JsonResponse
    {
        // SECURITY: verify the Stripe signature (HMAC). Previously only the PRESENCE
        // of the header was checked, so anyone could POST a forged event to flip any
        // agency's billing_status (free activation, or a tenant-wide DoS by suspending
        // a competitor). Fail CLOSED if the secret is not configured.
        $secret = env('STRIPE_WEBHOOK_SECRET');
        if (!$secret) {
            Log::warning('Stripe webhook rejected: STRIPE_WEBHOOK_SECRET not set');
            return response()->json(['error' => 'webhook not configured'], 400);
        }
        try {
            $event = \Stripe\Webhook::constructEvent($request->getContent(), (string) $request->header('Stripe-Signature'), $secret);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'invalid signature'], 400);
        }
        $type = $event->type ?? null;
        $object = json_decode(json_encode($event->data->object ?? []), true) ?: [];

        Log::info('Stripe webhook received', ['type' => $type, 'id' => $event->id ?? null]);

        switch ($type) {
            case 'invoice.paid':
            case 'invoice.payment_succeeded':
                $agencyId = $object['metadata']['agency_id'] ?? null;
                if ($agencyId) {
                    DB::table('agencies')->where('id', $agencyId)->update(['billing_status' => 'active']);
                }
                break;
            case 'invoice.payment_failed':
                $agencyId = $object['metadata']['agency_id'] ?? null;
                if ($agencyId) {
                    DB::table('agencies')->where('id', $agencyId)->update(['billing_status' => 'past_due']);
                }
                break;
            case 'customer.subscription.deleted':
                $subId = $object['id'] ?? null;
                if ($subId) {
                    // Find the agency by stripe subscription_id in settings JSON
                    $agency = DB::table('agencies')->whereRaw("JSON_EXTRACT(settings, '$.stripe.subscription_id') = ?", [$subId])->first();
                    if ($agency) {
                        DB::table('agencies')->where('id', $agency->id)->update(['billing_status' => 'suspended']);
                    }
                }
                break;
        }

        return response()->json(['received' => true]);
    }
}
