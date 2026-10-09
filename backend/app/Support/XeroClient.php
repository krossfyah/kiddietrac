<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * XERO — the connection (2026-10-09, Anthony: "build out xero integration to kiddietrac and
 * add configuration front end to settings like you did for quickbooks").
 *
 * Each agency brings its own Xero app (client id + secret from developer.xero.com), the same
 * model as QuickBooks. Tokens are stored ENCRYPTED. Xero access tokens last 30 minutes and
 * refresh tokens ROTATE on every use — the old one dies the moment a new one is issued — so a
 * refresh is serialised per agency under a lock and the new refresh token is saved before
 * anything else happens. Two overlapping refreshes would otherwise disconnect the agency.
 */
final class XeroClient
{
    public const API = 'https://api.xero.com/api.xro/2.0/';
    public const IDENTITY = 'https://identity.xero.com/connect/';

    /* Xero's scopes. Apps created from March 2026 get the granular accounting scopes;
       older apps still use accounting.transactions. Chosen per agency on the settings
       screen, because only the agency knows which kind of app it made. */
    public const SCOPES = [
        'granular' => 'openid profile email offline_access accounting.contacts accounting.settings.read accounting.invoices accounting.payments',
        'classic'  => 'openid profile email offline_access accounting.contacts accounting.settings.read accounting.transactions',
    ];

    public static function row(int $agencyId): ?object
    {
        return DB::table('agency_xero')->where('agency_id', $agencyId)->first();
    }

    public static function redirectUri(): string
    {
        // The public callback is registered outside the /v1 group (beside /api/qbo/callback).
        return rtrim((string) config('app.url'), '/') . '/api/xero/callback';
    }

    public static function secret(?object $row): ?string
    {
        if (! $row || empty($row->client_secret)) return null;
        try { return Crypt::decryptString($row->client_secret); } catch (\Throwable $e) { return null; }
    }

    public static function dec(?string $v): ?string
    {
        if ($v === null || $v === '') return null;
        try { return Crypt::decryptString($v); } catch (\Throwable $e) { return null; }
    }

    public static function enc(?string $v): ?string
    {
        return ($v === null || $v === '') ? null : Crypt::encryptString($v);
    }

    public static function connected(?object $row): bool
    {
        return $row && ! empty($row->refresh_token) && ! empty($row->tenant_id);
    }

    /** Exchange an authorisation code (or a refresh token) at Xero's identity server. */
    public static function tokenRequest(object $row, array $form): array
    {
        $res = Http::asForm()->timeout(30)
            ->withBasicAuth((string) $row->client_id, (string) self::secret($row))
            ->post(self::IDENTITY . 'token', $form);
        if (! $res->ok()) {
            $err = $res->json('error') ?: ('HTTP ' . $res->status());
            throw new XeroException('Xero refused the token request: ' . $err . ($res->json('error_description') ? ' — ' . $res->json('error_description') : ''), $res->status(), $err);
        }
        return $res->json();
    }

    /** A live access token for this agency, refreshing (once, under a lock) when needed. */
    public static function accessToken(int $agencyId): string
    {
        $row = self::row($agencyId);
        if (! $row || empty($row->refresh_token)) throw new XeroException('Xero is not connected for this agency.');
        if ($row->token_expires_at && now()->addSeconds(90)->lt(\Illuminate\Support\Carbon::parse($row->token_expires_at)) && ($tok = self::dec($row->access_token))) {
            return $tok;
        }
        return Cache::lock('xero-refresh-' . $agencyId, 30)->block(25, function () use ($agencyId) {
            $row = self::row($agencyId);   // another worker may have refreshed while we waited
            if ($row->token_expires_at && now()->addSeconds(90)->lt(\Illuminate\Support\Carbon::parse($row->token_expires_at)) && ($tok = self::dec($row->access_token))) {
                return $tok;
            }
            try {
                $tok = self::tokenRequest($row, ['grant_type' => 'refresh_token', 'refresh_token' => (string) self::dec($row->refresh_token)]);
            } catch (XeroException $e) {
                if ($e->oauthError === 'invalid_grant') {
                    // The refresh token is dead (revoked in Xero, or unused for 60 days): say so plainly.
                    DB::table('agency_xero')->where('agency_id', $agencyId)->update([
                        'access_token' => null, 'refresh_token' => null, 'token_expires_at' => null,
                        'last_error' => 'Xero ended the connection (it was revoked in Xero or not used for 60 days). Connect again.',
                        'updated_at' => now(),
                    ]);
                    throw new XeroException('Xero ended the connection — connect again from Settings → Xero.');
                }
                throw $e;
            }
            DB::table('agency_xero')->where('agency_id', $agencyId)->update([
                'access_token' => self::enc($tok['access_token'] ?? null),
                'refresh_token' => self::enc($tok['refresh_token'] ?? self::dec($row->refresh_token)),
                'token_expires_at' => now()->addSeconds((int) ($tok['expires_in'] ?? 1800)),
                'updated_at' => now(),
            ]);
            return (string) $tok['access_token'];
        });
    }

    /**
     * One call to the Accounting API. Retries once on a short rate-limit wait; turns Xero's
     * validation errors into a readable message.
     */
    public static function call(int $agencyId, string $method, string $path, ?array $body = null, array $query = [], array $headers = []): array
    {
        $row = self::row($agencyId);
        if (! self::connected($row)) throw new XeroException('Xero is not connected for this agency.');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $req = Http::withToken(self::accessToken($agencyId))->timeout(40)
                ->withHeaders(array_merge(['xero-tenant-id' => $row->tenant_id, 'Accept' => 'application/json'], $headers));
            $url = self::API . ltrim($path, '/') . ($query ? '?' . http_build_query($query) : '');
            $res = $body === null ? $req->send($method, $url) : $req->send($method, $url, ['json' => $body]);
            if ($res->status() === 429 && $attempt === 0) {
                $wait = (int) ($res->header('Retry-After') ?: 0);
                if ($wait > 0 && $wait <= 8) { sleep($wait); continue; }
                throw new XeroException('Xero rate limit reached — try again in ' . max(1, (int) ceil(max($wait, 60) / 60)) . ' minute(s).', 429);
            }
            if ($res->status() === 401 && $attempt === 0) {
                DB::table('agency_xero')->where('agency_id', $agencyId)->update(['token_expires_at' => null]);   // force a refresh
                continue;
            }
            if ($res->successful()) return (array) $res->json();
            throw new XeroException(self::errorText($res), $res->status());
        }
        throw new XeroException('Xero did not answer.');
    }

    public static function errorText($res): string
    {
        $j = $res->json();
        $msgs = [];
        foreach ((array) ($j['Elements'] ?? []) as $el) {
            foreach ((array) ($el['ValidationErrors'] ?? []) as $v) { if (! empty($v['Message'])) $msgs[] = $v['Message']; }
        }
        if (! $msgs && ! empty($j['Message'])) $msgs[] = $j['Message'];
        if (! $msgs && ! empty($j['Detail'])) $msgs[] = $j['Detail'];
        if (! $msgs && ! empty($j['Title'])) $msgs[] = $j['Title'];
        return 'Xero: ' . ($msgs ? implode(' ', array_unique($msgs)) : ('HTTP ' . $res->status()));
    }

    /** The organisations this token can reach. */
    public static function connections(string $accessToken): array
    {
        $res = Http::withToken($accessToken)->timeout(30)->acceptJson()->get('https://api.xero.com/connections');
        if (! $res->ok()) throw new XeroException('Xero did not list your organisations (HTTP ' . $res->status() . ').');
        return array_values(array_filter(array_map(fn ($c) => ($c['tenantType'] ?? '') === 'ORGANISATION'
            ? ['id' => $c['tenantId'], 'name' => $c['tenantName'] ?? 'Organisation'] : null, (array) $res->json())));
    }

    /** Revoke the refresh token at Xero (drops the connection there too). Best-effort. */
    public static function revoke(object $row): void
    {
        $rt = self::dec($row->refresh_token ?? null);
        if (! $rt || empty($row->client_id)) return;
        try {
            Http::asForm()->timeout(20)->withBasicAuth((string) $row->client_id, (string) self::secret($row))
                ->post(self::IDENTITY . 'revocation', ['token' => $rt]);
        } catch (\Throwable $e) { Log::info('xero revoke failed', ['agency' => $row->agency_id, 'error' => $e->getMessage()]); }
    }
}
