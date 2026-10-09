<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * QUICKBOOKS ONLINE — the connection (rebuilt 2026-10-09 on the Xero model; see XeroClient).
 *
 * Each agency brings its own Intuit app (client id + secret from developer.intuit.com); the
 * platform's QBO_CLIENT_ID / QBO_CLIENT_SECRET are a fallback. Tokens are encrypted. Intuit
 * access tokens last an hour and every refresh may return a NEW refresh token, so refreshes
 * are serialised per agency and the newest refresh token is always the one saved.
 */
final class QboClient
{
    public const MINOR = '75';

    public static function row(int $agencyId): ?object
    {
        return DB::table('agency_qbo')->where('agency_id', $agencyId)->first();
    }

    public static function redirectUri(): string
    {
        // Public callbacks are registered outside the /v1 group (see routes/api.php).
        return rtrim((string) config('app.url'), '/') . '/api/qbo/callback';
    }

    /** [client_id, client_secret, using_platform_fallback] */
    public static function creds(?object $row): array
    {
        $id = $row->client_id ?? null;
        $secret = XeroClient::dec($row->client_secret ?? null);
        if ($id && $secret) return [$id, $secret, false];
        $pid = config('integrations.qbo_client_id');
        $psec = config('integrations.qbo_client_secret');
        return ($pid && $psec) ? [$pid, $psec, true] : [null, null, false];
    }

    public static function connected(?object $row): bool
    {
        return $row && ! empty($row->refresh_token) && ! empty($row->realm_id);
    }

    public static function base(object $row): string
    {
        return ($row->environment === 'sandbox' ? 'https://sandbox-quickbooks.api.intuit.com' : 'https://quickbooks.api.intuit.com')
            . '/v3/company/' . $row->realm_id . '/';
    }

    public static function tokenRequest(object $row, array $form): array
    {
        [$id, $secret] = self::creds($row);
        $res = Http::asForm()->timeout(30)->acceptJson()->withBasicAuth((string) $id, (string) $secret)
            ->post('https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer', $form);
        if (! $res->ok()) {
            $err = $res->json('error') ?: ('HTTP ' . $res->status());
            throw new AccountingSyncException('QuickBooks refused the token request: ' . $err . ($res->json('error_description') ? ' — ' . $res->json('error_description') : ''), $res->status(), $err);
        }
        return $res->json();
    }

    public static function accessToken(int $agencyId): string
    {
        $fresh = function (?object $r) {
            return $r && $r->token_expires_at && now()->addSeconds(120)->lt(\Illuminate\Support\Carbon::parse($r->token_expires_at)) ? XeroClient::dec($r->access_token) : null;
        };
        $row = self::row($agencyId);
        if (! $row || empty($row->refresh_token)) throw new AccountingSyncException('QuickBooks is not connected for this agency.');
        if ($tok = $fresh($row)) return $tok;
        return Cache::lock('qbo-refresh-' . $agencyId, 30)->block(25, function () use ($agencyId, $fresh) {
            $row = self::row($agencyId);
            if ($tok = $fresh($row)) return $tok;
            try {
                $tok = self::tokenRequest($row, ['grant_type' => 'refresh_token', 'refresh_token' => (string) XeroClient::dec($row->refresh_token)]);
            } catch (AccountingSyncException $e) {
                if ($e->oauthError === 'invalid_grant') {
                    DB::table('agency_qbo')->where('agency_id', $agencyId)->update([
                        'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null,
                        'last_error' => 'QuickBooks ended the connection (revoked in QuickBooks, or it expired). Connect again.', 'updated_at' => now(),
                    ]);
                    throw new AccountingSyncException('QuickBooks ended the connection — connect again from Settings → QuickBooks.');
                }
                throw $e;
            }
            DB::table('agency_qbo')->where('agency_id', $agencyId)->update([
                'access_token' => XeroClient::enc($tok['access_token'] ?? null),
                'refresh_token' => XeroClient::enc($tok['refresh_token'] ?? XeroClient::dec($row->refresh_token)),
                'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 3600)),
                'updated_at' => now(),
            ]);
            return (string) $tok['access_token'];
        });
    }

    /** One Accounting API call. $path is relative to the company, e.g. "invoice" or "query". */
    public static function call(int $agencyId, string $method, string $path, ?array $body = null, array $query = []): array
    {
        $row = self::row($agencyId);
        if (! self::connected($row)) throw new AccountingSyncException('QuickBooks is not connected for this agency.');
        $query['minorversion'] = self::MINOR;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $req = Http::withToken(self::accessToken($agencyId))->timeout(40)->acceptJson();
            $url = self::base($row) . ltrim($path, '/') . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            $res = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);
            if ($res->status() === 429 && $attempt === 0) { sleep(3); continue; }
            if ($res->status() === 401 && $attempt === 0) {
                DB::table('agency_qbo')->where('agency_id', $agencyId)->update(['token_expires_at' => null]);
                continue;
            }
            if ($res->successful()) return (array) $res->json();
            throw new AccountingSyncException(self::errorText($res), $res->status(), (string) ($res->json('Fault.Error.0.code') ?? ''));
        }
        throw new AccountingSyncException('QuickBooks did not answer.');
    }

    /** SELECT … via the query endpoint; returns the entity list. */
    public static function query(int $agencyId, string $sql, string $entity): array
    {
        $r = self::call($agencyId, 'GET', 'query', null, ['query' => $sql]);
        return (array) ($r['QueryResponse'][$entity] ?? []);
    }

    public static function q(string $s): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $s);
    }

    public static function errorText($res): string
    {
        $msgs = [];
        foreach ((array) ($res->json('Fault.Error') ?? []) as $e) {
            $m = trim(($e['Message'] ?? '') . (! empty($e['Detail']) && ($e['Detail'] ?? '') !== ($e['Message'] ?? '') ? ': ' . $e['Detail'] : ''));
            if ($m !== '') $msgs[] = $m;
        }
        return 'QuickBooks: ' . ($msgs ? implode(' ', array_unique($msgs)) : ('HTTP ' . $res->status()));
    }

    public static function revoke(object $row): void
    {
        $rt = XeroClient::dec($row->refresh_token ?? null);
        [$id, $secret] = self::creds($row);
        if (! $rt || ! $id) return;
        try {
            Http::timeout(20)->acceptJson()->withBasicAuth((string) $id, (string) $secret)
                ->post('https://developer.api.intuit.com/v2/oauth2/tokens/revoke', ['token' => $rt]);
        } catch (\Throwable $e) { Log::info('qbo revoke failed', ['agency' => $row->agency_id, 'error' => $e->getMessage()]); }
    }
}
