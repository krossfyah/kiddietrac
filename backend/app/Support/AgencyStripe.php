<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE AGENCY'S OWN STRIPE, FOR EVERY FAMILY PAYMENT (2026-11-05).
 *
 * The Stripe end-to-end on the Test Agency found family payments split across two accounts: Apple Pay / Google Pay
 * went to the agency's Stripe (Finance > Payment providers), while saved cards, autopay, staff "Charge saved card"
 * and refunds used KiddieTrac's own key from .env (StripeConfig) - which is a placeholder. So a refund of a wallet
 * payment failed with "Invalid API Key provided: sk_live_", and no parent could save a card. Anthony: "Agency's own
 * Stripe for all". KiddieTrac's key (StripeConfig) is now only for KiddieTrac's own subscription billing.
 *
 * Every family charge, saved card, refund and webhook goes through here. A charge lands in the centre's account
 * and a refund comes back out of it.
 */
final class AgencyStripe
{
    public const NOT_SET = 'Card payments are not set up for your centre yet.';

    /** Where Stripe sends family payment events (StripeParentPayController::webhook). */
    // Outside the v1 prefix (routes/api.php, "v22p51 PUBLIC ROUTES"); /api/v1/... answered 404 to every Stripe delivery.
    public const WEBHOOK_PATH = '/api/stripe/parent-webhook';
    public const WEBHOOK_EVENTS = ['payment_intent.succeeded'];

    /** The agency's Stripe client, or null when it has no working keys. Keys must be SHAPED like keys (StripeConfig). */
    public static function client(int $agencyId): ?\Stripe\StripeClient
    {
        $k = self::keys($agencyId);

        return $k ? new \Stripe\StripeClient($k['secret']) : null;
    }

    /** ['secret','publishable','live','webhook_secret','apple_merchant_id'] or null. */
    public static function keys(int $agencyId): ?array
    {
        if ($agencyId <= 0 || ! PaymentProviders::configured($agencyId, PaymentProviders::STRIPE)) return null;
        $c = PaymentProviders::config($agencyId, PaymentProviders::STRIPE);
        $sk = trim((string) ($c['secret_key'] ?? ''));
        $pk = trim((string) ($c['publishable_key'] ?? ''));
        if (! preg_match('/^(sk|rk)_(live|test)_.{20,}$/', $sk) || ! preg_match('/^pk_(live|test)_.{20,}$/', $pk)) return null;

        return ['secret' => $sk, 'publishable' => $pk, 'live' => str_contains($sk, '_live_'),
            'webhook_secret' => trim((string) ($c['webhook_secret'] ?? '')),
            'apple_merchant_id' => trim((string) ($c['apple_merchant_id'] ?? ''))];
    }

    public static function publishable(int $agencyId): ?string
    {
        return self::keys($agencyId)['publishable'] ?? null;
    }

    public static function agencyForFamily(int $familyId): int
    {
        return (int) DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('f.id', $familyId)->value('c.agency_id');
    }

    public static function agencyForInvoice(int $invoiceId): int
    {
        return (int) DB::table('invoices as i')->join('centres as c', 'c.id', '=', 'i.centre_id')
            ->where('i.id', $invoiceId)->value('c.agency_id');
    }

    /** The agency's charging currency, lower case (CAD unless the agency says USD). */
    public static function currency(int $agencyId): string
    {
        $cur = strtoupper(trim((string) DB::table('agencies')->where('id', $agencyId)->value('currency')));

        return in_array($cur, ['CAD', 'USD'], true) ? strtolower($cur) : 'cad';
    }

    /**
     * Give back the part of a charge that was more than was owed (Anthony, 2026-11-05: "Refund it automatically").
     * Two taps, two phones or a split payer paying twice can each be charged before either is recorded; the second
     * charge is refunded here, to the same card, the moment it is recorded. Writes the refund row and the audit line,
     * and tells the payer. Returns the refund status ('succeeded' / 'pending') or null when it could not be done.
     */
    public static function refundExcess(int $paymentId, string $piId, float $excess, int $agencyId, ?int $payerGuardianId, string $why): ?string
    {
        $excess = round($excess, 2);
        if ($excess < 0.01) return null;
        $client = self::client($agencyId);
        $status = null; $refundId = null; $err = null;
        if ($client) {
            try {
                $r = $client->refunds->create(['payment_intent' => $piId, 'amount' => (int) round($excess * 100), 'reason' => 'duplicate',
                    'metadata' => ['payment_id' => (string) $paymentId, 'kt_auto' => 'excess']], ['idempotency_key' => 'kt-excess-' . $piId]);
                $refundId = $r->id;
                $status = $r->status === 'succeeded' ? 'succeeded' : 'pending';
            } catch (\Throwable $e) {
                $err = $e->getMessage();
                Log::warning('automatic excess refund failed', ['payment' => $paymentId, 'pi' => $piId, 'msg' => $err]);
            }
        } else {
            $err = self::NOT_SET;
        }
        $money = '$' . number_format($excess, 2);
        if ($status) {
            DB::table('payment_refunds')->insert([
                'payment_id' => $paymentId, 'amount' => $excess, 'reason' => 'duplicate', 'stripe_refund_id' => $refundId,
                'refund_method' => 'stripe', 'status' => $status, 'initiated_by_id' => 0, 'refunded_at' => now(),
                'notes' => 'Automatic: ' . $why, 'approver_signed_name' => 'KiddieTrac (automatic)', 'approver_signed_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $p = DB::table('payments')->where('id', $paymentId)->first(['notes', 'invoice_id', 'family_id']);
        DB::table('payments')->where('id', $paymentId)->update([
            'refunded_at' => $status ? now() : null,
            'notes' => trim(($p->notes ?? '') . ($status ? ' [' . $money . ' refunded automatically: ' . $why . ']'
                : ' [OVERPAID ' . $money . ' - the automatic refund failed, refund it from Refunds]')),
            'updated_at' => now(),
        ]);
        try {
            Audit::write(['user_id' => null, 'agency_id' => $agencyId, 'action' => $status ? 'refund.auto_excess' : 'refund.auto_excess_failed',
                'entity_type' => 'payment', 'entity_id' => $paymentId,
                'payload' => json_encode(['summary' => ($status ? 'Refunded ' : 'Could not refund ') . $money . ' automatically on invoice #' . ($p->invoice_id ?? '?') . ': ' . $why,
                    'amount' => $excess, 'payment_intent' => $piId, 'refund' => $refundId, 'error' => $err]),
                'created_at' => now()]);
        } catch (\Throwable $e) {}
        if ($status) {
            $uid = $payerGuardianId ? DB::table('guardians')->where('id', $payerGuardianId)->value('user_id')
                : DB::table('guardians')->where('family_id', $p->family_id ?? 0)->orderByDesc('is_primary')->value('user_id');
            if ($uid) {
                try {
                    Notify::write(['user_id' => (int) $uid, 'type' => 'refund', 'title' => 'Refund sent: ' . $money,
                        'body' => 'You were charged ' . $money . ' more than was owed (' . $why . '), so it has gone straight back to the same card. It should reach your account in a few days.',
                        'data' => json_encode(['link' => '#billing', 'payment_id' => $paymentId]), 'created_at' => now()]);
                } catch (\Throwable $e) {}
            }
            PaidMail::refunded($paymentId, $excess, 'You were charged more than was owed (' . $why . ')', $status);   // and by email (2026-11-06)
        }
        // The office hears about it too, and is told to act when the refund failed (PaymentAlerts, 2026-11-05).
        PaymentAlerts::refunded($paymentId, $excess, $why, $status);

        return $status;
    }

    /**
     * Connect KiddieTrac's webhook to the agency's Stripe and keep its signing secret (Anthony, 2026-11-05: "Auto-connect
     * the webhook"). Without it a payment confirmed after the parent closed the app was taken but never recorded.
     * Stripe only shows a signing secret when the endpoint is created, so an endpoint we hold no secret for is replaced.
     * Returns ['ok' => bool, 'url' => ..., 'message' => ...].
     */
    public static function connectWebhook(int $agencyId, ?int $byUserId = null): array
    {
        $k = self::keys($agencyId);
        $url = rtrim((string) config('app.url'), '/') . self::WEBHOOK_PATH;
        if (! $k) return ['ok' => false, 'url' => $url, 'message' => 'Add this agency\'s Stripe keys first.'];
        $client = new \Stripe\StripeClient($k['secret']);
        try {
            $mine = null;
            foreach ($client->webhookEndpoints->all(['limit' => 100])->data as $w) {
                $u = rtrim((string) $w->url, '/');
                if ($u === $url) { $mine = $w; continue; }
                // One of ours at an old address (the first ones went to /api/v1/..., which does not exist): removed.
                if (str_ends_with($u, '/stripe/parent-webhook') && str_starts_with((string) $w->description, 'KiddieTrac:')) {
                    $client->webhookEndpoints->delete($w->id);
                }
            }
            if ($mine && $k['webhook_secret'] !== '' && $mine->status === 'enabled'
                && ! array_diff(self::WEBHOOK_EVENTS, (array) $mine->enabled_events)) {
                return ['ok' => true, 'url' => $url, 'message' => 'Already connected.', 'id' => $mine->id];
            }
            if ($mine) $client->webhookEndpoints->delete($mine->id);
            $w = $client->webhookEndpoints->create(['url' => $url, 'enabled_events' => self::WEBHOOK_EVENTS,
                'description' => 'KiddieTrac: records invoice payments made by families']);
            PaymentProviders::save($agencyId, PaymentProviders::STRIPE, ['webhook_secret' => (string) $w->secret], $byUserId);
            try {
                Audit::write(['user_id' => $byUserId, 'agency_id' => $agencyId, 'action' => 'payments.stripe_webhook', 'entity_type' => 'agency', 'entity_id' => $agencyId,
                    'payload' => json_encode(['summary' => 'Connected KiddieTrac\'s webhook to the agency\'s Stripe (' . ($k['live'] ? 'live' : 'test') . ' mode)', 'endpoint' => $w->id, 'url' => $url]),
                    'created_at' => now()]);
            } catch (\Throwable $e) {}

            return ['ok' => true, 'url' => $url, 'message' => 'Connected.', 'id' => $w->id];
        } catch (\Throwable $e) {
            Log::warning('stripe webhook connect failed', ['agency' => $agencyId, 'msg' => $e->getMessage()]);

            return ['ok' => false, 'url' => $url, 'message' => 'Stripe said: ' . $e->getMessage()];
        }
    }
}
