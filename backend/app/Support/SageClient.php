<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SAGE ACCOUNTING (cloud) — the connection (2026-10-09; same model as XeroClient/QboClient).
 *
 * Sage's access tokens last FIVE MINUTES and its refresh tokens rotate on every use (the old
 * one dies), so refreshes are serialised per agency under a lock and the new refresh token
 * is saved before anything else. Every data call names the business in X-Business.
 */
final class SageClient
{
    public const API = 'https://api.accounting.sage.com/v3.1/';
    public const TOKEN = 'https://oauth.accounting.sage.com/token';

    public static function row(int $agencyId): ?object
    {
        return DB::table('agency_sage')->where('agency_id', $agencyId)->first();
    }

    public static function redirectUri(): string
    {
        return rtrim((string) config('app.url'), '/') . '/api/sage/callback';   // public routes sit outside /v1
    }

    public static function connected(?object $row): bool
    {
        return $row && ! empty($row->refresh_token) && ! empty($row->business_id);
    }

    public static function tokenRequest(object $row, array $form): array
    {
        $res = Http::asForm()->timeout(30)->acceptJson()->post(self::TOKEN, array_merge($form, [
            'client_id' => (string) $row->client_id, 'client_secret' => (string) XeroClient::dec($row->client_secret),
        ]));
        if (! $res->ok()) {
            $err = $res->json('error') ?: ('HTTP ' . $res->status());
            throw new AccountingSyncException('Sage refused the token request: ' . $err . ($res->json('error_description') ? ' — ' . $res->json('error_description') : ''), $res->status(), $err);
        }
        return $res->json();
    }

    public static function accessToken(int $agencyId): string
    {
        $fresh = fn (?object $r) => $r && $r->token_expires_at && now()->addSeconds(30)->lt(\Illuminate\Support\Carbon::parse($r->token_expires_at)) ? XeroClient::dec($r->access_token) : null;
        $row = self::row($agencyId);
        if (! $row || empty($row->refresh_token)) throw new AccountingSyncException('Sage is not connected for this agency.');
        if ($tok = $fresh($row)) return $tok;
        return Cache::lock('sage-refresh-' . $agencyId, 30)->block(25, function () use ($agencyId, $fresh) {
            $row = self::row($agencyId);
            if ($tok = $fresh($row)) return $tok;
            try {
                $tok = self::tokenRequest($row, ['grant_type' => 'refresh_token', 'refresh_token' => (string) XeroClient::dec($row->refresh_token)]);
            } catch (AccountingSyncException $e) {
                if ($e->oauthError === 'invalid_grant') {
                    DB::table('agency_sage')->where('agency_id', $agencyId)->update(['access_token' => null, 'refresh_token' => null, 'token_expires_at' => null,
                        'last_error' => 'Sage ended the connection (revoked in Sage, or unused for 31 days). Connect again.', 'updated_at' => now()]);
                    throw new AccountingSyncException('Sage ended the connection — connect again from Settings → Sage.');
                }
                throw $e;
            }
            DB::table('agency_sage')->where('agency_id', $agencyId)->update([
                'access_token' => XeroClient::enc($tok['access_token'] ?? null),
                'refresh_token' => XeroClient::enc($tok['refresh_token'] ?? XeroClient::dec($row->refresh_token)),
                'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 300)),
                'updated_at' => now(),
            ]);
            return (string) $tok['access_token'];
        });
    }

    public static function call(int $agencyId, string $method, string $path, ?array $body = null, array $query = [], array $headers = []): array
    {
        $row = self::row($agencyId);
        if (! self::connected($row)) throw new AccountingSyncException('Sage is not connected for this agency.');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $req = Http::withToken(self::accessToken($agencyId))->timeout(40)->acceptJson()
                ->withHeaders(array_merge(['X-Business' => $row->business_id], $headers));
            $url = self::API . ltrim($path, '/') . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
            $res = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);
            if ($res->status() === 429 && $attempt === 0) { sleep(3); continue; }
            if ($res->status() === 401 && $attempt === 0) { DB::table('agency_sage')->where('agency_id', $agencyId)->update(['token_expires_at' => null]); continue; }
            if ($res->successful()) return (array) ($res->json() ?? []);
            throw new AccountingSyncException(self::errorText($res), $res->status(), (string) ($res->json('0.$dataCode') ?? ''));
        }
        throw new AccountingSyncException('Sage did not answer.');
    }

    /** All items of a list endpoint (follows $next, capped). */
    public static function all(int $agencyId, string $path, array $query = [], int $max = 1000): array
    {
        $out = [];
        $query = array_merge(['items_per_page' => 200, 'page' => 1], $query);
        while (count($out) < $max) {
            $r = self::call($agencyId, 'GET', $path, null, $query);
            $out = array_merge($out, (array) ($r['$items'] ?? []));
            if (empty($r['$next'])) break;
            $query['page']++;
        }
        return $out;
    }

    public static function errorText($res): string
    {
        $msgs = [];
        $j = $res->json();
        foreach ((array) (is_array($j) && array_is_list($j) ? $j : []) as $e) {
            if (! empty($e['$message'])) $msgs[] = $e['$message'] . (! empty($e['$source']) ? ' (' . $e['$source'] . ')' : '');
        }
        if (! $msgs && is_array($j) && ! empty($j['$message'])) $msgs[] = $j['$message'];
        return 'Sage: ' . ($msgs ? implode(' ', array_unique($msgs)) : ('HTTP ' . $res->status()));
    }

    public static function businesses(string $accessToken): array
    {
        $res = Http::withToken($accessToken)->timeout(30)->acceptJson()->get(self::API . 'businesses');
        if (! $res->ok()) throw new AccountingSyncException('Sage did not list your businesses (HTTP ' . $res->status() . ').');
        $items = $res->json('$items') ?? (array_is_list((array) $res->json()) ? $res->json() : []);
        return array_values(array_map(fn ($b) => ['id' => $b['id'], 'name' => $b['displayed_as'] ?? $b['name'] ?? 'Business',
            'country' => $b['country']['id'] ?? null], (array) $items));
    }

    public static function revoke(object $row): void
    {
        $rt = XeroClient::dec($row->refresh_token ?? null);
        if (! $rt || empty($row->client_id)) return;
        try {
            Http::asForm()->timeout(20)->post('https://oauth.accounting.sage.com/revoke', ['token' => $rt,
                'client_id' => (string) $row->client_id, 'client_secret' => (string) XeroClient::dec($row->client_secret)]);
        } catch (\Throwable $e) { Log::info('sage revoke failed', ['agency' => $row->agency_id, 'error' => $e->getMessage()]); }
    }
}
