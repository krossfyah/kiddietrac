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
