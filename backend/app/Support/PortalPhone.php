<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls from the toll-free menu ringing INSIDE the KiddieTrac portal (2026-10-22, call
 * menu stage 2). Anthony chose "both [phones and portal] and configurable".
 *
 * How it works (Telnyx WebRTC):
 *   - one Telnyx *credential connection* ("KiddieTrac portal ringing") with SIP-URI
 *     calling limited to our own account (sip_uri_calling_preference = internal);
 *   - one *telephony credential* per staff member who rings in the portal; its
 *     sip_username is what the call menu dials: <Sip>sip:{user}@sip.telnyx.com</Sip>,
 *     alongside the department's phone numbers, all at once;
 *   - the portal fetches a 24-hour login token (POST /telephony_credentials/{id}/token)
 *     and keeps the @telnyx/webrtc client registered while it is open (kt-portal-phone.js).
 * The person sees a ringing card with Answer / Decline; answering connects the caller.
 *
 * PER ACCOUNT (2026-10-23, the outbound dialer). The same browser phone places calls
 * (App\Support\Dialer), and an agency with its own Telnyx account dials from it, so the
 * connection and credentials are kept per Telnyx account. The phone line's account
 * (agency 2, PhoneLineController::LINE_AGENCY) keeps the setting names it always had.
 * Everything is created on first use and remembered in PlatformSettings.
 */
final class PortalPhone
{
    private const API = 'https://api.telnyx.com/v2';
    public const LINE_ACCOUNT = 2;
    public const CONN_KEY = 'voice.webrtc.connection_id';
    public const CREDS_KEY = 'voice.webrtc.credentials';     // {user_id: {id, sip_username}}

    private static function acct(?int $account): int
    {
        return $account ?: self::LINE_ACCOUNT;
    }

    private static function connKey(int $a): string
    {
        return $a === self::LINE_ACCOUNT ? self::CONN_KEY : self::CONN_KEY . '.' . $a;
    }

    private static function credsKey(int $a): string
    {
        return $a === self::LINE_ACCOUNT ? self::CREDS_KEY : self::CREDS_KEY . '.' . $a;
    }

    private static function key(int $a): string
    {
        return Telnyx::apiKey($a);
    }

    /** The credential connection, created the first time. Null + reason when it cannot be. */
    public static function connection(?int $account = null): array
    {
        $a = self::acct($account);
        $key = self::key($a);
        if ($key === '') {
            return [null, 'The Telnyx account for this line is not connected.'];
        }
        $id = (string) PlatformSettings::get(self::connKey($a), '');
        if ($id !== '') {
            try {
                $r = Http::withToken($key)->timeout(15)->get(self::API . '/credential_connections/' . rawurlencode($id));
                if ($r->successful()) {
                    return [$id, null];
                }
                if ($r->status() !== 404) {
                    return [$id, null];        // Telnyx unreachable just now: keep using it
                }
            } catch (\Throwable $e) {
                return [$id, null];
            }
        }
        try {
            $r = Http::withToken($key)->timeout(20)->post(self::API . '/credential_connections', [
                'connection_name' => 'KiddieTrac portal phone',
                'user_name' => 'ktportal' . strtolower(\Illuminate\Support\Str::random(10)),
                'password' => \Illuminate\Support\Str::random(24),
                'active' => true,
                'sip_uri_calling_preference' => 'internal',
                'anchorsite_override' => 'Latency',
            ]);
            $new = (string) ($r->json('data.id') ?? '');
            if (! $r->successful() || $new === '') {
                Log::warning('Portal phone: credential connection not created', ['account' => $a, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
                return [null, 'Telnyx did not create the portal phone connection (HTTP ' . $r->status() . ').'];
            }
            PlatformSettings::set(self::connKey($a), $new);
            PlatformSettings::set(self::credsKey($a), '{}');      // credentials belong to a connection
            return [$new, null];
        } catch (\Throwable $e) {
            return [null, 'Telnyx could not be reached to set up the portal phone.'];
        }
    }

    private static function creds(int $a): array
    {
        $raw = PlatformSettings::get(self::credsKey($a), '{}');
        return is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
    }

    /** This person's credential (created the first time): [id, sip_username] or null. */
    public static function credential(int $userId, ?int $account = null): ?array
    {
        $a = self::acct($account);
        $all = self::creds($a);
        if (isset($all[$userId]['id'], $all[$userId]['sip_username'])) {
            return $all[$userId];
        }
        [$conn, $err] = self::connection($a);
        if (! $conn) {
            return null;
        }
        try {
            $r = Http::withToken(self::key($a))->timeout(20)->post(self::API . '/telephony_credentials', [
                'connection_id' => $conn, 'name' => 'kt-user-' . $userId, 'tag' => 'kiddietrac-portal',
            ]);
            $id = (string) ($r->json('data.id') ?? '');
            $sip = (string) ($r->json('data.sip_username') ?? '');
            if (! $r->successful() || $id === '' || $sip === '') {
                Log::warning('Portal phone: credential not created', ['account' => $a, 'user' => $userId, 'status' => $r->status()]);
                return null;
            }
            $all[$userId] = ['id' => $id, 'sip_username' => $sip];
            PlatformSettings::set(self::credsKey($a), json_encode($all));
            return $all[$userId];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Seconds a fresh Telnyx login token lasts, and how long the same one is handed out again. */
    public const TOKEN_LIFE = 86400;
    private const TOKEN_REUSE = 12 * 3600;

    /**
     * A 24-hour login token for the portal's WebRTC client. The same token is handed out for 12 hours,
     * so a reload or a reconnect does not wait on Telnyx each time.
     */
    public static function token(int $userId, ?int $account = null): ?string
    {
        return self::session($userId, $account)['token'] ?? null;
    }

    /** ['token' => string, 'ttl' => seconds the token has left] or null. */
    public static function session(int $userId, ?int $account = null): ?array
    {
        $a = self::acct($account);
        $c = self::credential($userId, $a);
        if (! $c) {
            return null;
        }
        $ck = 'portal-phone-token:' . $a . ':' . $c['id'];
        $hit = Cache::get($ck);
        if (is_array($hit) && isset($hit['token'], $hit['at']) && time() - $hit['at'] < self::TOKEN_REUSE) {
            return ['token' => $hit['token'], 'ttl' => self::TOKEN_LIFE - (time() - $hit['at'])];
        }
        try {
            $r = Http::withToken(self::key($a))->connectTimeout(5)->timeout(8)->post(self::API . '/telephony_credentials/' . rawurlencode($c['id']) . '/token');
            if ($r->status() === 404) {
                // The credential was removed at Telnyx: forget it, make a new one next time.
                $all = self::creds($a);
                unset($all[$userId]);
                PlatformSettings::set(self::credsKey($a), json_encode($all));
                Cache::forget($ck);
                return null;
            }
            $t = trim(trim($r->body()), "\" \n");
            if (! $r->successful() || strlen($t) <= 20) {
                return null;
            }
            Cache::put($ck, ['token' => $t, 'at' => time()], self::TOKEN_REUSE);
            return ['token' => $t, 'ttl' => self::TOKEN_LIFE];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** SIP addresses to ring for these people — only those who already have a credential. */
    public static function sipTargets(array $userIds, ?int $account = null): array
    {
        $all = self::creds(self::acct($account));
        $out = [];
        foreach ($userIds as $uid) {
            if (isset($all[(int) $uid]['sip_username'])) {
                $out[] = 'sip:' . $all[(int) $uid]['sip_username'] . '@sip.telnyx.com';
            }
        }
        return $out;
    }
}
