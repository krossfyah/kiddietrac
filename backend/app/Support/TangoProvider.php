<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Tango Card gift cards for educator rewards (2026-10-20), the second provider beside Giftbit.
 *
 * Built against Tango's API v2 (RaaS) as published at developers.tangocard.com (docs .md pages
 * and the sandbox swagger.json, read 2026-10-20):
 *
 *   roots     sandbox https://integration-api.tangocard.com/raas/v2, production https://api.tangocard.com/raas/v2
 *   auth      HTTP Basic: platform name + API key (Tango also offers OAuth; Basic is supported)
 *   balance   GET /accounts/{accountIdentifier} -> currentBalance, currencyCode
 *   catalogue GET /catalogs?verbose=true&currencyCode= -> brands[].items[] {utid, rewardName, currencyCode,
 *             status, valueType FIXED_VALUE|VARIABLE_VALUE, faceValue, minValue, maxValue, fulfillmentType}
 *   order     POST /orders {customerIdentifier, accountIdentifier, utid, amount, deliveryMethod, externalRefID}
 *             deliveryMethod EMBEDDED: Tango sends nothing; the reward comes back in reward.credentialList
 *             (redemptionUrl / cardNumber / pin ...) and is shown only to the educator in My points.
 *             externalRefID is idempotent per account (one order per id, <= 100 alphanumerics).
 *   status    GET /orders/{referenceOrderID} -> status, lineItemStatus, reward
 *   cancel    POST /lineItems/{referenceLineItemID}/cancel {reasonCode} -- only an unredeemed choice
 *             link within 5 days of sending; Tango refuses anything else and we say so.
 *   errors    ErrorInfo {httpCode, message, errors[{code, message}]}; 1 TPS limit -> wait 1s and retry
 *             with the same externalRefID.
 *
 * Accounts: each agency connects its own Tango platform/account (agencies.settings->tango) and
 * pays for its own cards. There is no KiddieTrac/platform fallback account (removed 2026-11-02):
 * no account of its own = not connected. Secrets are encrypted and only ever shown masked.
 */
final class TangoProvider
{
    public const PROVIDER = 'tango';
    private const ROOTS = ['sandbox' => 'https://integration-api.tangocard.com/raas/v2', 'production' => 'https://api.tangocard.com/raas/v2'];
    private const FIELDS = ['platform_name', 'customer_identifier', 'account_identifier'];

    private static function dec(?string $v): ?string
    {
        if (! $v) { return null; }
        try { return Crypt::decryptString($v); } catch (\Throwable $e) { return null; }
    }

    private static function mask(?string $t): ?string
    {
        return $t ? '••••' . substr($t, -4) : null;
    }

    private static function agencyRaw(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return (isset($s['tango']) && is_array($s['tango'])) ? $s['tango'] : [];
    }

    private static function make(string $env, ?string $platform, ?string $key, ?string $customer, ?string $accountId, string $acctKey, string $source): ?array
    {
        if (! $platform || ! $key || ! $customer || ! $accountId) {
            return null;
        }
        $env = $env === 'production' ? 'production' : 'sandbox';

        return ['provider' => self::PROVIDER, 'source' => $source, 'env' => $env, 'base' => self::ROOTS[$env],
            'platform' => $platform, 'token' => $key, 'customer' => $customer, 'account' => $accountId, 'key' => $acctKey];
    }

    /** The Tango account an agency's cards are bought from, or null. */
    public static function account(int $agencyId): ?array
    {
        $a = self::agencyRaw($agencyId);
        if (! empty($a['enabled'])) {
            $acct = self::make((string) ($a['env'] ?? 'sandbox'), $a['platform_name'] ?? null, self::dec($a['api_key'] ?? null),
                $a['customer_identifier'] ?? null, $a['account_identifier'] ?? null, 'ta' . $agencyId, 'agency');
            if ($acct) { return $acct; }
        }
        return null;
    }

    public static function connected(int $agencyId): bool
    {
        return self::account($agencyId) !== null;
    }

    public static function agencyConfig(int $agencyId): array
    {
        $a = self::agencyRaw($agencyId);
        $acct = self::account($agencyId);

        return [
            'env' => ($a['env'] ?? 'sandbox') === 'production' ? 'production' : 'sandbox',
            'enabled' => ! empty($a['enabled']),
            'platform_name' => $a['platform_name'] ?? '',
            'customer_identifier' => $a['customer_identifier'] ?? '',
            'account_identifier' => $a['account_identifier'] ?? '',
            'key_mask' => self::mask(self::dec($a['api_key'] ?? null)),
            'connected' => (bool) $acct,
            'using_env' => $acct['env'] ?? null,
        ];
    }

    private static function clean(string $v): string
    {
        return mb_substr(trim($v), 0, 100);
    }

    public static function saveAgency(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $t = (isset($s['tango']) && is_array($s['tango'])) ? $s['tango'] : [];
            if (array_key_exists('env', $in)) { $t['env'] = $in['env'] === 'production' ? 'production' : 'sandbox'; }
            if (array_key_exists('enabled', $in)) { $t['enabled'] = (bool) $in['enabled']; }
            foreach (self::FIELDS as $f) {
                if (array_key_exists($f, $in)) { $t[$f] = self::clean((string) $in[$f]); }
            }
            if (! empty($in['api_key'])) { $t['api_key'] = Crypt::encryptString(trim((string) $in['api_key'])); }
            if (! empty($in['clear_key'])) { $t['api_key'] = null; $t['enabled'] = false; }
            $s['tango'] = $t;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        self::forget('ta' . $agencyId);

        return self::agencyConfig($agencyId);
    }

    /* ───────────── HTTP ───────────── */

    /** One call. 429/5xx/timeouts retried after a 1-second wait (Tango's 1 TPS rule). Returns [ok, status, json, error]. */
    public static function call(array $acct, string $method, string $path, array $data = [], int $tries = 3): array
    {
        $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Could not reach Tango Card.'];
        for ($i = 0; $i < $tries; $i++) {
            if ($i > 0) { usleep(1000000 * $i); }
            try {
                $req = Http::withBasicAuth($acct['platform'], $acct['token'])->acceptJson()->timeout(25);
                $url = $acct['base'] . $path;
                $r = $method === 'GET' ? $req->get($url, $data) : $req->post($url, $data);
                $j = $r->json() ?: [];
                if ($r->status() >= 200 && $r->status() < 300) {
                    return ['ok' => true, 'status' => $r->status(), 'json' => $j, 'error' => null];
                }
                $last = ['ok' => false, 'status' => $r->status(), 'json' => $j, 'error' => self::explain($r->status(), $j)];
                if ($r->status() !== 429 && $r->status() < 500) {
                    return $last;
                }
            } catch (ConnectionException $e) {
                $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Tango Card did not answer in time.'];
            } catch (\Throwable $e) {
                report($e);
                $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Could not reach Tango Card.'];
            }
        }

        return $last;
    }

    /** Tango's ErrorInfo, in words an admin can act on. */
    public static function explain(int $status, array $j): string
    {
        $msg = (string) ($j['errors'][0]['message'] ?? $j['message'] ?? '');
        if ($status === 401 || $status === 403) {
            return 'Tango Card did not accept the platform name and API key (or they cannot use that account). Check them under Settings → Payment providers → Gift cards.';
        }
        if ($status === 429) {
            return 'Tango Card is limiting requests right now. Try again in a moment.';
        }
        if ($status >= 500) {
            return 'Tango Card had a problem on their side. Try again shortly.';
        }
        if ($status === 409) {
            return 'Tango Card already has an order with this reference.' . ($msg !== '' ? ' (' . $msg . ')' : '');
        }

        return 'Tango Card refused it' . ($msg !== '' ? ': ' . $msg : '.');
    }

    private static function cacheKey(string $acctKey, string $what): string
    {
        return 'tango:' . $acctKey . ':' . (int) Cache::get('tango:gen:' . $acctKey, 0) . ':' . $what;
    }

    private static function forget(string $acctKey): void
    {
        Cache::forever('tango:gen:' . $acctKey, (int) Cache::get('tango:gen:' . $acctKey, 0) + 1);
    }

    /* ───────────── reads ───────────── */

    /** Test connection: a harmless read of the account. */
    public static function ping(array $acct): array
    {
        $r = self::call($acct, 'GET', '/accounts/' . rawurlencode($acct['account']), [], 1);
        if (! $r['ok']) {
            return ['ok' => false, 'message' => $r['error']];
        }
        $j = $r['json'];

        return ['ok' => true, 'message' => 'Connected to Tango Card (' . $acct['env'] . '), account '
            . ($j['displayName'] ?? $acct['account']) . '. Balance: $' . number_format((float) ($j['currentBalance'] ?? 0), 2) . ' ' . ($j['currencyCode'] ?? '') . '.'];
    }

    /** Available balance (the account has one currency). */
    public static function balance(array $acct, string $currency): ?array
    {
        $key = self::cacheKey($acct['key'], 'acct');
        $j = Cache::get($key);
        if ($j === null) {
            $r = self::call($acct, 'GET', '/accounts/' . rawurlencode($acct['account']), [], 1);
            if (! $r['ok']) { return null; }
            $j = $r['json'];
            Cache::put($key, $j, 120);
        }

        return ['available' => (float) ($j['currentBalance'] ?? 0), 'reserved' => 0.0, 'pending' => 0.0,
            'currency' => strtoupper((string) ($j['currencyCode'] ?? $currency))];
    }

    /** Catalogue items in the agency currency, digital and active: brand-shaped like Giftbit's. */
    public static function catalog(array $acct, string $currency): array
    {
        $key = self::cacheKey($acct['key'], 'catalog:' . strtoupper($currency));
        $c = Cache::get($key);
        if ($c !== null) { return $c; }
        $r = self::call($acct, 'GET', '/catalogs', ['verbose' => 'true', 'currencyCode' => strtoupper($currency)], 2);
        if (! $r['ok']) { return []; }
        $out = self::parseCatalog($r['json'], $currency);
        Cache::put($key, $out, 21600);

        return $out;
    }

    public static function parseCatalog(array $j, string $currency): array
    {
        $out = [];
        foreach (($j['brands'] ?? []) as $b) {
            $items = array_values(array_filter($b['items'] ?? [], fn ($it) => strcasecmp((string) ($it['status'] ?? 'active'), 'active') === 0
                && strtoupper((string) ($it['currencyCode'] ?? '')) === strtoupper($currency)
                && strtoupper((string) ($it['fulfillmentType'] ?? 'DIGITAL')) === 'DIGITAL'));
            $img = null;
            foreach (['300w-326ppi', '200w-326ppi', '278w-326ppi', '130w-326ppi', '80w-326ppi'] as $sz) {
                if (! empty($b['imageUrls'][$sz])) { $img = $b['imageUrls'][$sz]; break; }
            }
            if (! $img && ! empty($b['imageUrls']) && is_array($b['imageUrls'])) { $img = reset($b['imageUrls']); }
            foreach ($items as $it) {
                $variable = strtoupper((string) ($it['valueType'] ?? '')) === 'VARIABLE_VALUE';
                $name = (string) ($b['brandName'] ?? $it['rewardName'] ?? $it['utid']);
                $out[] = [
                    'code' => (string) $it['utid'],
                    'name' => count($items) > 1 ? (string) ($it['rewardName'] ?? $name) : $name,
                    'image' => $img,
                    'currency' => strtoupper((string) $it['currencyCode']),
                    'variable' => $variable,
                    'min' => $variable ? (float) ($it['minValue'] ?? 0) : null,
                    'max' => $variable ? (float) ($it['maxValue'] ?? 0) : null,
                    'prices' => $variable ? [] : [(float) ($it['faceValue'] ?? 0)],
                    'disclaimer' => $b['disclaimer'] ?? null,
                    'redemption' => $it['redemptionInstructions'] ?? null,
                ];
            }
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    public static function brands(array $acct, string $currency): array
    {
        return array_map(fn ($b) => ['code' => $b['code'], 'name' => $b['name'], 'image' => $b['image'], 'disclaimer' => $b['disclaimer']],
            self::catalog($acct, $currency));
    }

    public static function brand(array $acct, string $utid, string $currency = 'CAD'): ?array
    {
        foreach (self::catalog($acct, $currency) as $b) {
            if ($b['code'] === $utid) { return $b; }
        }

        return null;
    }

    /* ───────────── orders ───────────── */

    /** externalRefID: Tango allows up to 100 alphanumerics; random tail so a reused award id never collides. */
    public static function orderId(int $agencyId, int $awardId): string
    {
        return 'KT' . $agencyId . 'R' . $awardId . 'X' . strtoupper(\Illuminate\Support\Str::random(10));
    }

    public static function buildOrder(array $acct, string $ref, string $utid, float $amount, string $note = ''): array
    {
        return [
            'customerIdentifier' => $acct['customer'],
            'accountIdentifier' => $acct['account'],
            'utid' => $utid,
            'amount' => round($amount, 2),
            // Tango sends nothing; the reward comes back here and is shown only to the educator.
            'deliveryMethod' => 'EMBEDDED',
            'externalRefID' => $ref,
            'notes' => mb_substr($note, 0, 1000),
        ];
    }

    public static function order(array $acct, string $ref, string $utid, float $amount, string $note = ''): array
    {
        $r = self::call($acct, 'POST', '/orders', self::buildOrder($acct, $ref, $utid, $amount, $note), 3);
        if (! $r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'raw' => $r['json']];
        }

        return self::parseOrder($r['json']);
    }

    /** [ok, status, link, code, instructions, order_id, line_id, info, raw] from an order view. */
    public static function parseOrder(array $j): array
    {
        $creds = [];
        foreach (($j['reward']['credentialList'] ?? []) as $c) {
            $creds[(string) ($c['credentialType'] ?? $c['label'] ?? '')] = (string) ($c['value'] ?? '');
        }
        if (! $creds && is_array($j['reward']['credentials'] ?? null)) {
            foreach ($j['reward']['credentials'] as $k => $v) { $creds[(string) $k] = (string) $v; }
        }
        $link = $creds['redemptionUrl'] ?? $creds['url'] ?? null;
        $codeParts = [];
        foreach (['cardNumber' => 'Card number', 'claimCode' => 'Claim code', 'code' => 'Code', 'pin' => 'PIN', 'securityCode' => 'Security code', 'eventNumber' => 'Event number'] as $k => $label) {
            if (! empty($creds[$k])) { $codeParts[] = $label . ': ' . $creds[$k]; }
        }
        $status = strtoupper((string) ($j['status'] ?? $j['orderStatus'] ?? ''));
        $line = strtoupper((string) ($j['lineItemStatus'] ?? ''));
        $ours = match (true) {
            str_contains($line, 'CANCEL') => 'cancelled',
            $status === 'FAILED' || str_contains($line, 'FAIL') => 'failed',
            $status === 'COMPLETE' && ($link || $codeParts) => 'sent',
            default => 'requested',
        };

        return [
            'ok' => true, 'status' => $ours,
            'link' => $link, 'code' => $codeParts ? implode('  ·  ', $codeParts) : null,
            'instructions' => $j['reward']['redemptionInstructions'] ?? $j['redemptionInstructions'] ?? null,
            'order_id' => $j['referenceOrderID'] ?? null, 'line_id' => $j['referenceLineItemID'] ?? null,
            'info' => trim($status . ($line ? '/' . $line : '')),
            'raw' => $j,
        ];
    }

    public static function getOrder(array $acct, string $referenceOrderID): ?array
    {
        $r = self::call($acct, 'GET', '/orders/' . rawurlencode($referenceOrderID), [], 1);

        return $r['ok'] ? self::parseOrder($r['json']) : null;
    }

    public static function cancel(array $acct, string $lineItemId): array
    {
        $r = self::call($acct, 'POST', '/lineItems/' . rawurlencode($lineItemId) . '/cancel',
            ['reasonCode' => 'OTHER', 'otherReason' => 'Cancelled by the agency in KiddieTrac'], 1);

        return $r['ok'] ? ['ok' => true] : ['ok' => false, 'error' => $r['error'] . ' Tango Card only cancels an unredeemed choice link within 5 days of sending.'];
    }

    /** The order view without the reward itself (that is the card). */
    public static function safeRaw($raw): ?string
    {
        if (! is_array($raw)) { return null; }
        unset($raw['reward']);

        return mb_substr(json_encode($raw), 0, 4000);
    }
}
