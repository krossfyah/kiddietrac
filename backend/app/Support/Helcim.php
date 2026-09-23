<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Helcim - card payments, refunds and voids, on the agency's own Helcim account.
 *
 * HOW A PAYMENT HAPPENS HERE, and why it is worth the extra round trip:
 *
 *   1. initializeCheckout() asks Helcim for a checkout session and gets back a
 *      checkoutToken (for the browser) and a secretToken (which never leaves us).
 *   2. The browser renders HelcimPay.js, which collects the card INSIDE HELCIM'S OWN
 *      IFRAME. A card number never touches a KiddieTrac page, request log or database,
 *      so none of this is in PCI scope. That is the reason to use their hosted flow
 *      rather than posting card data to /payment/purchase ourselves.
 *   3. The browser hands back the result plus a hash; verifyHash() proves with the
 *      secretToken that the result came from Helcim and was not edited on the way.
 *
 * AMOUNTS ARE DOLLARS. Helcim takes 10.50, not 1050 - the opposite of Stripe, which is
 * exactly the sort of difference that quietly bills somebody a hundred times over. Every
 * amount crossing this boundary is rounded to 2dp and passed as a decimal, never cents.
 *
 * THERE IS NO SANDBOX HOST. Helcim test and live traffic both go to api.helcim.com; what
 * makes a call a test is the token. So mode here is a LABEL for the humans, not a switch
 * - production() says so plainly rather than letting anyone assume a sandbox token is
 * being kept away from real money by the URL.
 */
final class Helcim
{
    private const BASE = 'https://api.helcim.com/v2';

    /** Kept well under the web request budget so a slow call fails rather than hangs. */
    private const TIMEOUT = 25;

    public static function configured(int $agencyId): bool
    {
        return PaymentProviders::configured($agencyId, PaymentProviders::HELCIM);
    }

    public static function currency(int $agencyId): string
    {
        $c = PaymentProviders::config($agencyId, PaymentProviders::HELCIM);
        $cur = strtoupper(trim((string) ($c['currency'] ?? '')));

        // CAD unless told otherwise: every agency on this platform banks in Canada, and a
        // silently wrong currency is a charge the family disputes.
        return in_array($cur, ['CAD', 'USD'], true) ? $cur : 'CAD';
    }

    /** Is this agency pointed at REAL money? */
    public static function production(int $agencyId): bool
    {
        return PaymentProviders::mode($agencyId, PaymentProviders::HELCIM) === 'production';
    }

    /**
     * An idempotency key Helcim will accept: 25-36 chars of [A-Za-z0-9_-].
     *
     * Required on every money-moving call. A retried request carrying the same key is
     * answered with the FIRST result instead of charging twice, which is the difference
     * between a timeout being harmless and a family being billed again.
     */
    private static function idempotencyKey(): string
    {
        return (string) Str::uuid();     // 36 chars, hyphens only
    }

    private static function token(int $agencyId): string
    {
        $c = PaymentProviders::config($agencyId, PaymentProviders::HELCIM);
        $t = trim((string) ($c['api_token'] ?? ''));
        if ($t === '') {
            /* Never fall through to another agency token or a platform default. An empty
               credential must stop the payment, not redirect the money. */
            throw new \RuntimeException('Helcim is not configured for this agency.');
        }

        return $t;
    }

    /**
     * One call. Returns [ok, data, error].
     *
     * Helcim reports failure two ways - an HTTP error, and a 200 carrying an errors array
     * - and a caller that only checks the status code will happily record a declined card
     * as a successful payment. Both are collapsed into one answer here so no caller has to
     * remember that.
     */
    private static function call(int $agencyId, string $method, string $path, array $body = []): array
    {
        $url = self::BASE . $path;

        try {
            $req = Http::withHeaders([
                'api-token' => self::token($agencyId),
                'accept' => 'application/json',
                'content-type' => 'application/json',
                'idempotency-key' => self::idempotencyKey(),
            ])->timeout(self::TIMEOUT);

            $res = $method === 'GET' ? $req->get($url, $body) : $req->post($url, $body);
            $json = $res->json();
            if (! is_array($json)) {
                $json = [];
            }

            if (! empty($json['errors'])) {
                $errs = is_array($json['errors']) ? $json['errors'] : [$json['errors']];

                return [false, $json, implode('; ', array_map('strval', $errs))];
            }

            if (! $res->successful()) {
                return [false, $json, 'Helcim returned HTTP ' . $res->status()
                    . ($res->body() ? ': ' . mb_substr($res->body(), 0, 300) : '')];
            }

            /* A purchase can come back 200 with status DECLINED. That is not an error in
               HTTP terms and it is absolutely not a payment. */
            if (isset($json['status']) && strtoupper((string) $json['status']) === 'DECLINED') {
                return [false, $json, 'The card was declined.'];
            }

            return [true, $json, null];
        } catch (\Throwable $e) {
            /* Never log the token or the body - a card-bearing payload must not end up in
               a log file. The path and the message are enough to diagnose. */
            Log::error('Helcim call failed', [
                'agency_id' => $agencyId, 'path' => $path, 'error' => $e->getMessage(),
            ]);

            return [false, [], $e->getMessage()];
        }
    }

    /**
     * Open a hosted checkout. $amount is DOLLARS.
     *
     * Returns [ok, [checkoutToken, secretToken], error]. The secretToken is the caller's
     * to keep server-side; it is what verifyHash() needs and the browser must never see
     * it.
     */
    public static function initializeCheckout(
        int $agencyId,
        float $amount,
        ?string $invoiceNumber = null,
        ?string $customerCode = null,
        string $paymentType = 'purchase'
    ): array {
        $body = array_filter([
            'paymentType' => in_array($paymentType, ['purchase', 'preauth', 'verify'], true)
                ? $paymentType : 'purchase',
            'amount' => round($amount, 2),
            'currency' => self::currency($agencyId),
            'paymentMethod' => 'cc',
            'invoiceNumber' => $invoiceNumber,
            'customerCode' => $customerCode,
            /* Helcim shows its own receipt screen; ours follows immediately afterwards and
               would be the second one in a row. */
            'confirmationScreen' => false,
        ], fn ($v) => $v !== null);

        return self::call($agencyId, 'POST', '/helcim-pay/initialize', $body);
    }

    /**
     * Does this result really come from Helcim, unaltered?
     *
     * SHA-256 over the compact JSON of the response data with the session secretToken
     * appended, no separator. Helcim hashes the JSON-ESCAPED unicode form, so the encode
     * here must NOT use JSON_UNESCAPED_UNICODE or JSON_UNESCAPED_SLASHES - an accented
     * name in a cardholder field would otherwise hash differently at each end and every
     * such payment would be rejected as tampered with.
     *
     * Constant-time comparison: this is an authentication check, and a timing-variable one
     * invites exactly the forgery it exists to stop.
     */
    public static function verifyHash($data, string $secretToken, string $givenHash): bool
    {
        if ($secretToken === '' || $givenHash === '') {
            return false;
        }

        /* The RAW string as the browser received it, when we have it. Re-encoding a
           decoded array can reorder keys or re-space the JSON and produce a different
           digest for an identical message - the same trap as re-serialising a webhook body
           before checking its signature. */
        $json = is_string($data) ? $data : json_encode($data);
        if (! is_string($json)) {
            return false;
        }

        return hash_equals(hash('sha256', $json . $secretToken), strtolower(trim($givenHash)));
    }

    /**
     * Refund a settled transaction, in whole or in part. $amount is DOLLARS.
     *
     * NOTE THE FIELD NAME. Refund takes originalTransactionId; reverse below takes
     * cardTransactionId. They are not interchangeable, and Helcim will reject the wrong
     * one - which is the good outcome. The bad one is copying this call to build the other
     * and never noticing.
     */
    public static function refund(int $agencyId, int $originalTransactionId, float $amount, string $ip): array
    {
        return self::call($agencyId, 'POST', '/payment/refund', [
            'originalTransactionId' => $originalTransactionId,
            'amount' => round($amount, 2),
            'ipAddress' => $ip,
            'ecommerce' => true,
        ]);
    }

    /**
     * Void a transaction that has not settled yet. Always the whole amount.
     *
     * A void and a refund are not the same thing to the cardholder: a void disappears
     * before it reaches their statement, a refund appears next to the charge. Same day,
     * prefer this.
     */
    public static function reverse(int $agencyId, int $cardTransactionId, string $ip): array
    {
        return self::call($agencyId, 'POST', '/payment/reverse', [
            'cardTransactionId' => $cardTransactionId,
            'ipAddress' => $ip,
            'ecommerce' => true,
        ]);
    }

    /**
     * Does the stored token actually work?
     *
     * A zero-dollar verify session is the cheapest call that proves authentication without
     * moving money - an admin should be able to find out that a key is wrong by pressing a
     * button, not by a family payment failing on a Monday morning.
     */
    public static function testConnection(int $agencyId): array
    {
        [$ok, $data, $err] = self::initializeCheckout($agencyId, 0.00, null, null, 'verify');

        return [$ok && ! empty($data['checkoutToken']), $err];
    }
}
