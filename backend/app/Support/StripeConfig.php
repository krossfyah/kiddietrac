<?php

namespace App\Support;

/**
 * Is Stripe actually usable, or is the .env carrying a placeholder?
 *
 * Every guard in the app asked `env('STRIPE_SECRET')` and trusted the answer's
 * truthiness. This .env carries the bare prefixes — "sk_live_" and "pk_live_", eight
 * characters each — which are perfectly truthy, so all twelve guards passed and the
 * Stripe SDK was handed a key it then rejected:
 *
 *     Stripe\Exception\AuthenticationException: Invalid API Key provided: sk_live_
 *
 * A parent tapping "+ Add credit/debit card" got a 500 and a crash ticket instead of
 * being told the agency has no card payments set up (tickets #52/#53, 2026-09-08).
 *
 * So "configured" now means the key is SHAPED like a real one: a recognised prefix
 * followed by an actual body. Length is the part that matters — a prefix on its own is
 * exactly the placeholder we need to catch, and Stripe's own keys are far longer.
 */
class StripeConfig
{
    /** The secret key if it is usable, otherwise null (so it stays falsy at call sites). */
    public static function secret(): ?string
    {
        return self::usable((string) env('STRIPE_SECRET', ''));
    }

    /** The publishable key if usable, otherwise null — never hand the browser a stub. */
    public static function publishable(): ?string
    {
        return self::usable((string) env('STRIPE_KEY', ''));
    }

    public static function configured(): bool
    {
        return self::secret() !== null;
    }

    /** What a caller can show a parent when cards are not set up for their agency. */
    public const NOT_CONFIGURED = 'Card payments are not set up for this agency yet.';

    private static function usable(string $v): ?string
    {
        $v = trim($v);
        if ($v === '') {
            return null;
        }
        // sk_/pk_/rk_ + live|test + a body. Restricted keys (rk_) are included because
        // an agency may well be given one rather than a full secret.
        if (! preg_match('/^(sk|pk|rk)_(live|test)_.+$/', $v)) {
            return null;
        }
        // A real key runs to 100+ characters; the placeholders here are 8. Anything this
        // short is a prefix somebody left behind, not a credential.
        if (strlen($v) < 24) {
            return null;
        }

        return $v;
    }
}
