<?php

namespace App\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Giftbit gift cards for educator rewards (2026-10-20).
 *
 * Anthony: "wire up giftbit integration to kiddietrac". Built against Giftbit's public API
 * (github.com/Giftbit/Giftbit-API-Docs, openapi.yaml as of 2026-10-13):
 *
 *   roots      testbed https://api-testbed.giftbit.com/papi/v1, live https://api.giftbit.com/papi/v1
 *   auth       Authorization: Bearer <token>   (GET /ping checks it)
 *   catalogue  GET /brands?currencyisocode=&region=&limit=&offset=   GET /brands/{code} (min/max or fixed prices)
 *              GET /regions (Canada = a region id the brands filter takes)
 *   balance    GET /funds  -> fundsbycurrency.{CAD,USD}.available_in_cents
 *   order      POST /direct_links {id, price_in_cents, brand_codes|region, link_count}
 *              `id` is OUR key and idempotent: a retry with the same id never buys twice.
 *              Response: direct_links[] (may be empty while Giftbit prepares it), campaign.status
 *              API_CREATING | AWAITING_FUNDS | CAMPAIGN_CREATED, info.code INFO_CAMPAIGN_CREATED[_FUNDS_REQUIRED|_FUNDS_PENDING]
 *   link later GET /links/{id} -> directlinks[].link (INFO_LINKS_GENERATION_IN_PROGRESS while not ready)
 *   status     GET /gifts?campaign_id={id} -> gifts[].{uuid,status: SENT_AND_REDEEMABLE|REDEEMED|GIVER_CANCELLED|EXPIRED}
 *   cancel     DELETE /gifts/{uuid}   (not once redeemed)
 *   errors     only 200 is success; body {error:{code,name,message},status}; 429/5xx/timeouts -> retry with backoff
 *
 * DIRECT LINKS, NOT GIFTBIT'S EMAIL. The link is stored encrypted on the award and shown only
 * to the educator in My points; KiddieTrac's own bell + email tell them it is there. That keeps
 * the card behind their sign-in and the message in the agency's own branding.
 *
 * WHOSE ACCOUNT PAYS. Each agency connects its OWN Giftbit account (agencies.settings->giftbit:
 * env, encrypted token, enabled) and funds its own cards, so every gift is billed to the
 * agency that gave it. There is NO KiddieTrac/platform fallback account (removed 2026-11-02,
 * Anthony: gift cards are agency specific): no account of its own = not connected.
 */
final class GiftCardProvider
{
    public const PROVIDER = 'giftbit';
    private const ROOTS = ['testbed' => 'https://api-testbed.giftbit.com/papi/v1', 'live' => 'https://api.giftbit.com/papi/v1'];

    /* ───────────── settings ───────────── */

    private static function agencyRaw(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];

        return (isset($s['giftbit']) && is_array($s['giftbit'])) ? $s['giftbit'] : [];
    }

    private static function dec(?string $v): ?string
    {
        if (! $v) { return null; }
        try { return Crypt::decryptString($v); } catch (\Throwable $e) { return null; }
    }

    private static function mask(?string $t): ?string
    {
        return $t ? '••••' . substr($t, -4) : null;
    }

    /** The account an agency's gifts are bought from, or null when none is connected. */
    public static function account(int $agencyId): ?array
    {
        $a = self::agencyRaw($agencyId);
        $tok = self::dec($a['token'] ?? null);
        if ($tok && ! empty($a['enabled'])) {
            $env = ($a['env'] ?? 'testbed') === 'live' ? 'live' : 'testbed';
            return ['source' => 'agency', 'env' => $env, 'base' => self::ROOTS[$env], 'token' => $tok, 'key' => 'a' . $agencyId];
        }
        return null;
    }

    public static function connected(int $agencyId): bool
    {
        return self::account($agencyId) !== null;
    }

    /** What the agency admin's settings tab shows. The token is never returned, only masked. */
    public static function agencyConfig(int $agencyId): array
    {
        $a = self::agencyRaw($agencyId);
        $acct = self::account($agencyId);

        return [
            'env' => ($a['env'] ?? 'testbed') === 'live' ? 'live' : 'testbed',
            'enabled' => ! empty($a['enabled']),
            'token_mask' => self::mask(self::dec($a['token'] ?? null)),
            'connected' => (bool) $acct,
            'using_env' => $acct['env'] ?? null,
        ];
    }

    public static function saveAgency(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $g = (isset($s['giftbit']) && is_array($s['giftbit'])) ? $s['giftbit'] : [];
            if (array_key_exists('env', $in)) { $g['env'] = $in['env'] === 'live' ? 'live' : 'testbed'; }
            if (array_key_exists('enabled', $in)) { $g['enabled'] = (bool) $in['enabled']; }
            if (! empty($in['token'])) { $g['token'] = Crypt::encryptString(trim((string) $in['token'])); }
            if (! empty($in['clear_token'])) { $g['token'] = null; $g['enabled'] = false; }
            $s['giftbit'] = $g;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        self::forget('a' . $agencyId);

        return self::agencyConfig($agencyId);
    }

    /* ───────────── HTTP ───────────── */

    /**
     * One call to Giftbit. Retries 429, 5xx and timeouts with a short backoff (safe for orders:
     * the order id is idempotent). Returns [ok, status, json, error].
     */
    public static function call(array $acct, string $method, string $path, array $data = [], int $tries = 3): array
    {
        $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Could not reach Giftbit.'];
        for ($i = 0; $i < $tries; $i++) {
            if ($i > 0) { usleep((int) (min(4, 2 ** ($i - 1)) * 1000000)); }
            try {
                $req = Http::withToken($acct['token'])->acceptJson()->withHeaders(['Accept-Encoding' => 'identity'])->timeout(20);
                $url = $acct['base'] . $path;
                $r = $method === 'GET' ? $req->get($url, $data) : ($method === 'DELETE' ? $req->delete($url) : $req->post($url, $data));
                $j = $r->json() ?: [];
                if ($r->status() === 200) {
                    return ['ok' => true, 'status' => 200, 'json' => $j, 'error' => null];
                }
                $last = ['ok' => false, 'status' => $r->status(), 'json' => $j, 'error' => self::explain($r->status(), $j)];
                if ($r->status() !== 429 && $r->status() < 500) {
                    return $last;      // the request is wrong: retrying will not fix it
                }
            } catch (ConnectionException $e) {
                $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Giftbit did not answer in time.'];
            } catch (\Throwable $e) {
                report($e);
                $last = ['ok' => false, 'status' => 0, 'json' => [], 'error' => 'Could not reach Giftbit.'];
            }
        }

        return $last;
    }

    /** Giftbit's error, in words an admin can act on. */
    public static function explain(int $status, array $j): string
    {
        $code = (string) ($j['error']['code'] ?? '');
        $msg = (string) ($j['error']['message'] ?? $j['error']['name'] ?? '');
        if ($status === 401 || $status === 403) {
            return 'Giftbit did not accept the API token. Check it under Settings → Payment providers → Gift cards.';
        }
        if ($code === 'ERROR_CAMPAIGN_INVALID_BRAND' || $code === 'ERROR_BRANDS_NO_SUCH_BRAND') {
            return 'That gift card isn\'t available at that value. Pick another brand or amount.';
        }
        if ($status === 402) {
            return 'Giftbit could not charge the card on file for this account.';
        }
        if ($status === 429) {
            return 'Giftbit is busy right now. Try again in a minute.';
        }
        if ($status >= 500) {
            return 'Giftbit had a problem on their side. Try again shortly.';
        }

        return 'Giftbit refused it' . ($msg !== '' ? ': ' . $msg : '.') . ($code !== '' ? ' (' . $code . ')' : '');
    }

    private static function cacheKey(string $acctKey, string $what): string
    {
        return 'giftbit:' . $acctKey . ':' . (int) Cache::get('giftbit:gen:' . $acctKey, 0) . ':' . $what;
    }

    private static function forget(string $acctKey): void
    {
        Cache::forever('giftbit:gen:' . $acctKey, (int) Cache::get('giftbit:gen:' . $acctKey, 0) + 1);
    }

    /* ───────────── reads ───────────── */

    public static function ping(array $acct): array
    {
        $r = self::call($acct, 'GET', '/ping', [], 1);

        return $r['ok']
            ? ['ok' => true, 'message' => 'Connected to Giftbit (' . $acct['env'] . ') as ' . ($r['json']['displayname'] ?? $r['json']['username'] ?? 'your account') . '.']
            : ['ok' => false, 'message' => $r['error']];
    }

    /** Available balance in one currency, in dollars (null if Giftbit cannot be read). */
    public static function balance(array $acct, string $currency): ?array
    {
        $key = self::cacheKey($acct['key'], 'funds');
        $j = Cache::get($key);
        if ($j === null) {
            $r = self::call($acct, 'GET', '/funds', [], 1);
            if (! $r['ok']) { return null; }
            $j = $r['json'];
            Cache::put($key, $j, 120);
        }
        $c = $j['fundsbycurrency'][strtoupper($currency)] ?? null;
        if (! $c) { return ['available' => 0.0, 'reserved' => 0.0, 'pending' => 0.0]; }

        return ['available' => ($c['available_in_cents'] ?? 0) / 100, 'reserved' => ($c['reserved_in_cents'] ?? 0) / 100, 'pending' => ($c['pending_in_cents'] ?? 0) / 100];
    }

    private static function regionId(array $acct, string $currency): ?int
    {
        $name = strtoupper($currency) === 'USD' ? 'USA' : 'Canada';
        $key = self::cacheKey($acct['key'], 'regions');
        $list = Cache::get($key);
        if ($list === null) {
            $r = self::call($acct, 'GET', '/regions', [], 1);
            $list = $r['ok'] ? ($r['json']['regions'] ?? []) : [];
            if ($r['ok']) { Cache::put($key, $list, 86400); }
        }
        foreach ($list as $g) {
            if (strcasecmp((string) ($g['name'] ?? ''), $name) === 0) { return (int) $g['id']; }
        }

        return null;
    }

    /** The catalogue for the agency's currency/region: [{code, name, image, disclaimer}], cached 6h. */
    public static function brands(array $acct, string $currency): array
    {
        $key = self::cacheKey($acct['key'], 'brands:' . strtoupper($currency));
        $cached = Cache::get($key);
        if ($cached !== null) { return $cached; }
        $region = self::regionId($acct, $currency);
        $out = []; $offset = 0;
        for ($page = 0; $page < 10; $page++) {
            $q = ['currencyisocode' => strtoupper($currency), 'limit' => 100, 'offset' => $offset];
            if ($region) { $q['region'] = $region; }
            $r = self::call($acct, 'GET', '/brands', $q, 2);
            if (! $r['ok']) { return []; }
            foreach (($r['json']['brands'] ?? []) as $b) {
                if (empty($b['brand_code'])) { continue; }
                $out[] = ['code' => (string) $b['brand_code'], 'name' => (string) ($b['name'] ?? $b['brand_code']),
                    'image' => $b['image_url'] ?? null, 'disclaimer' => $b['disclaimer'] ?? null];
            }
            $total = (int) ($r['json']['total_count'] ?? 0);
            $offset += 100;
            if ($offset >= $total || empty($r['json']['brands'])) { break; }
        }
        usort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        Cache::put($key, $out, 21600);

        return $out;
    }

    /** One brand's values: {code, name, currency, variable, min, max, prices[]} in dollars, cached 24h. */
    public static function brand(array $acct, string $code): ?array
    {
        $code = preg_replace('/[^A-Za-z0-9_-]/', '', $code);
        if ($code === '') { return null; }
        $key = self::cacheKey($acct['key'], 'brand:' . $code);
        $c = Cache::get($key);
        if ($c !== null) { return $c ?: null; }
        $r = self::call($acct, 'GET', '/brands/' . rawurlencode($code), [], 2);
        if (! $r['ok']) { return null; }
        $b = $r['json']['brand'] ?? null;
        if (! $b) { return null; }
        $out = [
            'code' => (string) $b['brand_code'], 'name' => (string) ($b['name'] ?? $code), 'image' => $b['image_url'] ?? null,
            'currency' => strtoupper((string) ($b['fund_currencyisocode'] ?? '')),
            'variable' => (bool) ($b['variable_price'] ?? false),
            'min' => isset($b['min_price_in_cents']) ? $b['min_price_in_cents'] / 100 : null,
            'max' => isset($b['max_price_in_cents']) ? $b['max_price_in_cents'] / 100 : null,
            'prices' => array_map(fn ($p) => $p / 100, $b['allowed_prices_in_cents'] ?? []),
            'disclaimer' => $b['disclaimer'] ?? null,
        ];
        Cache::put($key, $out, 86400);

        return $out;
    }

    /** null when the amount suits the brand; otherwise why not. */
    public static function checkValue(array $brand, float $amount, string $currency): ?string
    {
        if ($brand['currency'] && $brand['currency'] !== strtoupper($currency)) {
            return $brand['name'] . ' cards are in ' . $brand['currency'] . ', and this agency rewards in ' . strtoupper($currency) . '.';
        }
        if ($brand['variable']) {
            if (($brand['min'] !== null && $amount < $brand['min']) || ($brand['max'] !== null && $amount > $brand['max'])) {
                return $brand['name'] . ' cards can be $' . number_format((float) $brand['min'], 2) . ' to $' . number_format((float) $brand['max'], 2) . '.';
            }
            return null;
        }
        foreach ($brand['prices'] as $p) {
            if (abs($p - $amount) < 0.005) { return null; }
        }

        return $brand['name'] . ' cards come in ' . implode(', ', array_map(fn ($p) => '$' . number_format($p, 2), $brand['prices'])) . ' only.';
    }

    /* ───────────── orders ───────────── */

    /** The body for one gift. `$brand` null = Giftbit's full catalogue for the region (they pick). */
    public static function buildOrder(string $orderId, float $amount, string $currency, ?string $brand): array
    {
        $body = ['id' => $orderId, 'price_in_cents' => (int) round($amount * 100), 'link_count' => 1];
        if ($brand) {
            $body['brand_codes'] = [$brand];
        } else {
            $body['region'] = strtoupper($currency) === 'USD' ? 'USA' : 'Canada';
        }

        return $body;
    }

    /** Our idempotency key: one award can only ever buy one card. Made ONCE per award and stored:
        the random tail means an award id reused after a rollback can never collide with an
        older Giftbit order (Giftbit returns the existing order for a repeated id). */
    public static function orderId(int $agencyId, int $awardId): string
    {
        return 'kt-' . $agencyId . '-reward-' . $awardId . '-' . strtolower(\Illuminate\Support\Str::random(8));
    }

    /** Place the order. Returns [ok, status, link, uuid, info, raw, error]. */
    public static function order(array $acct, string $orderId, float $amount, string $currency, ?string $brand): array
    {
        $r = self::call($acct, 'POST', '/direct_links', self::buildOrder($orderId, $amount, $currency, $brand), 3);
        if (! $r['ok']) {
            return ['ok' => false, 'error' => $r['error'], 'raw' => $r['json']];
        }

        return self::parseOrder($r['json']);
    }

    public static function parseOrder(array $j): array
    {
        $info = (string) ($j['info']['code'] ?? '');
        $link = null;
        foreach (($j['direct_links'] ?? []) as $l) {
            $link = is_string($l) ? $l : ($l['link'] ?? null);
            if ($link) { break; }
        }
        $campaignStatus = (string) ($j['campaign']['status'] ?? '');
        $awaiting = $campaignStatus === 'AWAITING_FUNDS' || str_starts_with($info, 'INFO_CAMPAIGN_CREATED_FUNDS');

        return [
            'ok' => true,
            'status' => $awaiting ? 'awaiting_funds' : ($link ? 'sent' : 'requested'),
            'link' => $link,
            'uuid' => $j['campaign']['uuid'] ?? null,
            'info' => $info ?: $campaignStatus,
            'raw' => $j,
        ];
    }

    /** The link, once Giftbit has made it. Returns [ready, link, error]. */
    public static function fetchLink(array $acct, string $orderId): array
    {
        $r = self::call($acct, 'GET', '/links/' . rawurlencode($orderId), [], 1);
        if (! $r['ok']) { return ['ready' => false, 'link' => null, 'error' => $r['error']]; }
        foreach (($r['json']['directlinks'] ?? []) as $l) {
            if (! empty($l['link'])) { return ['ready' => true, 'link' => $l['link'], 'error' => null]; }
        }

        return ['ready' => false, 'link' => null, 'error' => null];
    }

    /** The gift behind an order: [uuid, status] (SENT_AND_REDEEMABLE / REDEEMED / GIVER_CANCELLED / EXPIRED). */
    public static function gift(array $acct, string $orderId): ?array
    {
        $r = self::call($acct, 'GET', '/gifts', ['campaign_id' => $orderId, 'limit' => 1], 1);
        if (! $r['ok']) { return null; }
        $g = $r['json']['gifts'][0] ?? null;

        return $g ? ['uuid' => $g['uuid'] ?? null, 'status' => $g['status'] ?? null, 'brand' => $g['brand_code'] ?? null] : null;
    }

    /** Our award status for a Giftbit gift status. */
    public static function mapGiftStatus(?string $s, string $current): string
    {
        return match ($s) {
            'REDEEMED' => 'redeemed',
            'GIVER_CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
            'SENT_AND_REDEEMABLE' => $current === 'awaiting_funds' || $current === 'requested' ? 'sent' : $current,
            default => $current,
        };
    }

    public static function cancel(array $acct, string $giftUuid): array
    {
        $r = self::call($acct, 'DELETE', '/gifts/' . rawurlencode($giftUuid), [], 1);

        return $r['ok'] ? ['ok' => true, 'status' => $r['json']['gift']['status'] ?? 'GIVER_CANCELLED'] : ['ok' => false, 'error' => $r['error']];
    }
}
