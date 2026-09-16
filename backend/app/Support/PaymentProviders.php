<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Per-agency payment credentials, read and written in one place.
 *
 * The rule this exists to enforce: an agency's money moves on that agency's credentials.
 * Nothing here ever falls back to another agency, and nothing falls back to the platform
 * .env either — a silent fallback would take one customer's card payment on another
 * customer's account, which is the worst failure this system could have.
 *
 * Secrets are encrypted at rest and never leave the server. forDisplay() is what the
 * portal gets: booleans and last-four hints, never a value that could be replayed.
 */
final class PaymentProviders
{
    public const ZUM = 'zumrails';
    public const STRIPE = 'stripe';

    /** Which keys each provider holds, and which of them are secret. */
    private const FIELDS = [
        self::ZUM => [
            'base_url' => ['label' => 'API base URL', 'secret' => false],
            /* Zum's own quickstart posts a WalletId alongside every transaction, and
               their docs say plainly: "If you are using the API, you will need the
               wallet ID to create transactions involving wallet." Money collected from
               a parent lands in the agency's Zum wallet and money paid out leaves it,
               so without this there is nowhere for a transaction to go. */
            'wallet_id' => ['label' => 'Zum wallet ID', 'secret' => false],
            'username' => ['label' => 'API username', 'secret' => true],
            'password' => ['label' => 'API password', 'secret' => true],
            'webhook_secret' => ['label' => 'Webhook secret', 'secret' => true],
            'refund_path' => ['label' => 'Refund endpoint path', 'secret' => false],
        ],
        self::STRIPE => [
            'publishable_key' => ['label' => 'Publishable key', 'secret' => false],
            'secret_key' => ['label' => 'Secret key', 'secret' => true],
            'webhook_secret' => ['label' => 'Webhook signing secret', 'secret' => true],
        ],
    ];

    public static function providers(): array
    {
        return array_keys(self::FIELDS);
    }

    public static function fields(string $provider): array
    {
        return self::FIELDS[$provider] ?? [];
    }

    /** The row, or null. */
    private static function row(int $agencyId, string $provider): ?object
    {
        return DB::table('agency_payment_providers')
            ->where('agency_id', $agencyId)->where('provider', $provider)->first();
    }

    /**
     * Decrypted credentials for one agency's provider. Server-side only.
     *
     * Returns an empty array when the provider is switched off, so a disabled provider
     * cannot move money even if credentials are still stored against it.
     */
    public static function config(int $agencyId, string $provider): array
    {
        $row = self::row($agencyId, $provider);
        if (! $row || ! $row->enabled) {
            return [];
        }

        $secrets = [];
        if ($row->secrets) {
            try {
                $secrets = json_decode(Crypt::decryptString($row->secrets), true) ?: [];
            } catch (\Throwable $e) {
                // A key rotation or a hand-edited row. Empty beats guessing, and it is
                // logged so it is not a mystery.
                Log::error('payment provider secrets could not be decrypted', [
                    'agency_id' => $agencyId, 'provider' => $provider,
                ]);

                return [];
            }
        }

        return $secrets + ['mode' => $row->mode];
    }

    public static function configured(int $agencyId, string $provider): bool
    {
        $c = self::config($agencyId, $provider);

        return $provider === self::ZUM
            ? (! empty($c['base_url']) && ! empty($c['username']) && ! empty($c['password']))
            : ! empty($c['secret_key']);
    }

    /**
     * What the portal may see: whether each secret is set, and a last-four hint. Never
     * the values themselves — a settings screen has no reason to be able to replay a key.
     */
    public static function forDisplay(int $agencyId, string $provider): array
    {
        $row = self::row($agencyId, $provider);
        $c = $row ? self::configAllowingDisabled($agencyId, $provider) : [];

        $out = [
            'provider' => $provider,
            'enabled' => (bool) ($row->enabled ?? false),
            'mode' => $row->mode ?? 'sandbox',
            'configured' => self::configured($agencyId, $provider),
            'updated_at' => $row->updated_at ?? null,
            'fields' => [],
        ];

        foreach (self::FIELDS[$provider] ?? [] as $key => $meta) {
            $val = (string) ($c[$key] ?? '');
            $out['fields'][$key] = [
                'label' => $meta['label'],
                'secret' => $meta['secret'],
                'set' => $val !== '',
                // A non-secret is shown in full; a secret only ever as a tail, which is
                // enough to tell two keys apart without being usable.
                'value' => $meta['secret'] ? null : $val,
                'hint' => ($meta['secret'] && $val !== '') ? '••••'.mb_substr($val, -4) : null,
            ];
        }

        return $out;
    }

    /** Same decryption, ignoring the enabled flag — only for building the display. */
    private static function configAllowingDisabled(int $agencyId, string $provider): array
    {
        $row = self::row($agencyId, $provider);
        if (! $row || ! $row->secrets) {
            return [];
        }
        try {
            return json_decode(Crypt::decryptString($row->secrets), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Save. Only the keys present in $values are touched.
     *
     * A blank secret means "leave it alone", not "erase it" — otherwise every save from a
     * screen that cannot show the current value would wipe the keys. To clear one, send
     * the string 'null'.
     */
    /**
     * sandbox or production, for a configured provider.
     *
     * Whether money is real is not a detail a caller should have to dig for. The parent
     * billing screen in particular has to say so out loud: a sandbox provider enabled on
     * a live agency puts working-looking payment buttons in front of real families.
     */
    public static function mode(int $agencyId, string $provider): ?string
    {
        $row = self::row($agencyId, $provider);

        return $row ? ($row->mode ?: 'sandbox') : null;
    }

    public static function save(int $agencyId, string $provider, array $values, ?int $byUserId = null): void
    {
        $existing = self::configAllowingDisabled($agencyId, $provider);
        $next = $existing;

        foreach (self::FIELDS[$provider] ?? [] as $key => $meta) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $v = $values[$key];
            if ($v === null) {
                continue;
            }
            $v = trim((string) $v);

            if ($meta['secret'] && $v === '') {
                continue;                       // blank secret = unchanged
            }
            if ($v === 'null') {
                unset($next[$key]);             // explicit clear
                continue;
            }
            $next[$key] = $v;
        }

        $row = self::row($agencyId, $provider);
        $payload = [
            'enabled' => array_key_exists('enabled', $values) ? (bool) $values['enabled'] : (bool) ($row->enabled ?? false),
            'mode' => in_array($values['mode'] ?? null, ['sandbox', 'production'], true)
                ? $values['mode']
                : ($row->mode ?? 'sandbox'),
            'secrets' => $next ? Crypt::encryptString(json_encode($next)) : null,
            'updated_by_id' => $byUserId,
            'updated_at' => now(),
        ];

        if ($row) {
            DB::table('agency_payment_providers')->where('id', $row->id)->update($payload);
        } else {
            DB::table('agency_payment_providers')->insert($payload + [
                'agency_id' => $agencyId,
                'provider' => $provider,
                'created_at' => now(),
            ]);
        }
    }

    /**
     * Which agency a Zum webhook belongs to.
     *
     * Callbacks arrive unauthenticated, so the agency is found by matching the shared
     * secret against each configured agency in turn, compared in constant time. Returns
     * null when nothing matches — an unmatched callback is never guessed at.
     */
    /**
     * Which agency signed this callback, given the raw body and the signature header.
     *
     * Zum signs with HMAC-SHA256 over the raw request body, keyed with the webhook secret
     * we set in their portal — and because each agency has its own secret, the signature
     * is also the identity. That is why this returns an agency rather than a boolean.
     *
     * The RAW body matters. Re-serialising the parsed payload would change key order and
     * spacing and produce a different digest for the same message, so the caller must pass
     * $request->getContent(), never json_encode($request->all()).
     *
     * Hex and base64 are both accepted because providers differ in how they render the
     * digest, and the plaintext secret is still honoured last for a static-secret setup.
     * Every comparison is constant time.
     */
    /**
     * EVERY agency whose secret validates this signature.
     *
     * Two agencies can share one provider account — a sandbox pointed at by both —
     * and then they share a webhook secret, so the singular form below can only
     * return the first of them. Callers that need to attribute a callback to a
     * specific record should take the candidates from here and let the record's own
     * agency decide, checking it is among them.
     */
    public static function agenciesForWebhookSignature(string $provider, string $given, string $rawBody): array
    {
        $given = trim($given);
        if ($given === '') {
            return [];
        }
        if (str_contains($given, '=') && ! str_ends_with($given, '=')) {
            $parts = explode('=', $given, 2);
            if (strlen($parts[1]) > 16) {
                $given = $parts[1];
            }
        }

        $out = [];
        $rows = DB::table('agency_payment_providers')
            ->where('provider', $provider)->where('enabled', true)->get(['agency_id']);

        foreach ($rows as $r) {
            $secret = (string) (self::config((int) $r->agency_id, $provider)['webhook_secret'] ?? '');
            if ($secret === '') {
                continue;
            }
            $hex = hash_hmac('sha256', $rawBody, $secret);
            $b64 = base64_encode(hex2bin($hex) ?: '');
            if (hash_equals($hex, strtolower($given))
                || hash_equals($b64, $given)
                || hash_equals($secret, $given)) {
                $out[] = (int) $r->agency_id;
            }
        }

        return $out;
    }

    public static function agencyForWebhookSignature(string $provider, string $given, string $rawBody): ?int
    {
        $given = trim($given);
        if ($given === '') {
            return null;
        }
        // Some senders prefix the scheme, e.g. "sha256=abc123".
        if (str_contains($given, '=') && ! str_ends_with($given, '=')) {
            $parts = explode('=', $given, 2);
            if (strlen($parts[1]) > 16) {
                $given = $parts[1];
            }
        }

        $rows = DB::table('agency_payment_providers')
            ->where('provider', $provider)->where('enabled', true)->get(['agency_id']);

        foreach ($rows as $r) {
            $secret = (string) (self::config((int) $r->agency_id, $provider)['webhook_secret'] ?? '');
            if ($secret === '') {
                continue;
            }

            $hex = hash_hmac('sha256', $rawBody, $secret);
            $b64 = base64_encode(hex2bin($hex) ?: '');

            if (hash_equals($hex, strtolower($given))
                || hash_equals($b64, $given)
                || hash_equals($secret, $given)) {
                return (int) $r->agency_id;
            }
        }

        return null;
    }

    public static function agencyForWebhookSecret(string $provider, string $given): ?int
    {
        if ($given === '') {
            return null;
        }
        $rows = DB::table('agency_payment_providers')
            ->where('provider', $provider)->where('enabled', true)->get(['agency_id']);

        foreach ($rows as $r) {
            $c = self::config((int) $r->agency_id, $provider);
            $secret = (string) ($c['webhook_secret'] ?? '');
            if ($secret !== '' && hash_equals($secret, $given)) {
                return (int) $r->agency_id;
            }
        }

        return null;
    }
}
