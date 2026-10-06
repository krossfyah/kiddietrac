<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * "Connect Gmail by signing in" for RSVP sender accounts (2026-10-06).
 *
 * WHY NOT SMTP: this host blocks ALL outbound SMTP — smtp.gmail.com on 587/465/25,
 * smtp.office365.com:587 and SendGrid's 2525 all time out; only HTTPS (443) leaves the box.
 * Anthony's Town of Mono Gmail sender failed with exactly that ("Connection timed out").
 * So a Gmail sender signs in with Google (OAuth web-server flow, gmail.send scope only) and
 * mail goes out through the Gmail API over HTTPS — see App\Mail\GmailApiTransport.
 *
 * The refresh and access tokens are stored with Crypt and never leave the server. The
 * OAuth `state` is HMAC-signed with APP_KEY, expires, and can be used once.
 */
final class RsvpGmail
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.send';
    private const STATE_TTL = 900;   // 15 minutes to finish signing in

    /** A dedicated Gmail client, else the social-login Google client if that is set. */
    public static function clientId(): ?string
    {
        $v = env('GOOGLE_GMAIL_CLIENT_ID') ?: config('services.google_gmail.client_id') ?: config('services.google.client_id');

        return $v ? (string) $v : null;
    }

    public static function clientSecret(): ?string
    {
        $v = env('GOOGLE_GMAIL_CLIENT_ID')
            ? env('GOOGLE_GMAIL_CLIENT_SECRET')
            : (config('services.google_gmail.client_id') ? config('services.google_gmail.client_secret') : config('services.google.client_secret'));

        return $v ? (string) $v : null;
    }

    public static function configured(): bool
    {
        return (bool) (self::clientId() && self::clientSecret());
    }

    /** 'dedicated' | 'login' | null — which Google client Connect would use. */
    public static function clientKind(): ?string
    {
        if (env('GOOGLE_GMAIL_CLIENT_ID') || config('services.google_gmail.client_id')) return 'dedicated';

        return config('services.google.client_id') ? 'login' : null;
    }

    /** The exact URI registered on the Google OAuth client. FIXED — it is what was entered
        in Google Cloud, so it must never be derived from anything that could change. */
    public const REDIRECT_URI = 'https://api.kiddietrac.com/api/v1/rsvp/google/callback';

    public static function redirectUri(): string
    {
        return self::REDIRECT_URI;
    }

    /* ── state: signed, expiring, single-use ── */

    public static function makeState(int $senderId, int $agencyId, int $userId): string
    {
        $nonce = Str::random(32);
        $payload = rtrim(strtr(base64_encode(json_encode(['s' => $senderId, 'a' => $agencyId, 'u' => $userId, 'e' => time() + self::STATE_TTL, 'n' => $nonce])), '+/', '-_'), '=');
        Cache::put('rsvp-gstate:' . $nonce, $userId, self::STATE_TTL + 60);

        return $payload . '.' . hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /** @return array{s:int,a:int,u:int}|null — null when forged, expired or already used. */
    public static function readState(?string $state): ?array
    {
        $state = (string) $state;
        if (! preg_match('/^[A-Za-z0-9_-]{20,600}\.[a-f0-9]{64}$/', $state)) return null;
        [$payload, $sig] = explode('.', $state, 2);
        if (! hash_equals(hash_hmac('sha256', $payload, (string) config('app.key')), $sig)) return null;
        $d = json_decode((string) base64_decode(strtr($payload, '-_', '+/')), true);
        if (! is_array($d) || empty($d['n']) || (int) ($d['e'] ?? 0) < time()) return null;
        // Single use: the nonce is taken out of the cache; a replay finds nothing.
        if (Cache::pull('rsvp-gstate:' . $d['n']) === null) return null;

        return ['s' => (int) $d['s'], 'a' => (int) $d['a'], 'u' => (int) $d['u']];
    }

    public static function authUrl(object $sender, int $agencyId, int $userId): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => self::clientId(), 'redirect_uri' => self::redirectUri(), 'response_type' => 'code',
            'scope' => self::SCOPES, 'access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'false',
            'state' => self::makeState((int) $sender->id, $agencyId, $userId),
            // Suggest the address already on the sender, if any; Google lets them pick another.
            'login_hint' => $sender->google_email ?: ($sender->from_address ?: null),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Exchange the code, and store the connection on the sender.
     * @return array{ok:bool, reason?:string, email?:string}
     */
    public static function connect(object $sender, string $code): array
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
            'redirect_uri' => self::redirectUri(), 'grant_type' => 'authorization_code',
        ]);
        if (! $r->successful()) {
            return ['ok' => false, 'reason' => 'exchange', 'detail' => (string) ($r->json('error_description') ?: $r->json('error') ?: ('HTTP ' . $r->status()))];
        }
        $j = $r->json();
        if (empty($j['refresh_token'])) {
            return ['ok' => false, 'reason' => 'no_refresh'];
        }
        if (! str_contains((string) ($j['scope'] ?? ''), 'gmail.send')) {
            return ['ok' => false, 'reason' => 'scope'];
        }
        // The id_token came straight from Google's token endpoint over TLS, so its claims
        // can be read without re-verifying the signature (OpenID Connect, section 3.1.3.7).
        $claims = [];
        $parts = explode('.', (string) ($j['id_token'] ?? ''));
        if (count($parts) === 3) {
            $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        }
        $email = strtolower(trim((string) ($claims['email'] ?? '')));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || empty($claims['email_verified'])) {
            return ['ok' => false, 'reason' => 'no_email'];
        }
        DB::table('rsvp_senders')->where('id', $sender->id)->update([
            'type' => 'gmail_oauth', 'google_email' => $email,
            // Gmail sends as the signed-in account, so the From is that address.
            'from_address' => $email,
            'google_refresh_enc' => Crypt::encryptString((string) $j['refresh_token']),
            'google_access_enc' => Crypt::encryptString((string) $j['access_token']),
            'google_token_expires_at' => now()->addSeconds(max(60, (int) ($j['expires_in'] ?? 3600)) - 60),
            'google_connected_at' => now(), 'google_status' => 'connected',
            'smtp_pass_enc' => null, 'verified_at' => now(),
            'last_test_status' => 'connected', 'last_test_error' => null, 'last_test_at' => now(), 'updated_at' => now(),
        ]);

        return ['ok' => true, 'email' => $email, 'changed_from' => ($sender->from_address && strtolower($sender->from_address) !== $email) ? $sender->from_address : null];
    }

    /**
     * A current access token, refreshed when it is about to expire (or when forced).
     * invalid_grant — revoked, or a Testing-mode token past its 7 days — marks the sender
     * "needs reconnecting" and throws a sentence the admin can act on.
     */
    public static function accessToken(object $sender, bool $force = false): string
    {
        $row = DB::table('rsvp_senders')->where('id', $sender->id)->first();
        if (! $row || empty($row->google_refresh_enc)) {
            throw new \RuntimeException('This Gmail sender is not connected yet. Press “Connect Gmail” on the Sender accounts tab.');
        }
        if (! $force && ! empty($row->google_access_enc) && $row->google_token_expires_at && strtotime((string) $row->google_token_expires_at . ' UTC') > time() + 30) {
            try { return Crypt::decryptString($row->google_access_enc); } catch (\Throwable $e) { /* refresh below */ }
        }
        if (! self::configured()) {
            throw new \RuntimeException('Gmail sending is not set up on this platform yet (no Google client).');
        }
        try { $refresh = Crypt::decryptString($row->google_refresh_enc); } catch (\Throwable $e) { $refresh = ''; }
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'refresh_token', 'refresh_token' => $refresh,
            'client_id' => self::clientId(), 'client_secret' => self::clientSecret(),
        ]);
        if (! $r->successful()) {
            $err = (string) ($r->json('error') ?: ('HTTP ' . $r->status()));
            if ($err === 'invalid_grant' || $err === 'unauthorized_client') {
                $msg = 'Gmail access for ' . ($row->google_email ?: 'this account') . ' was revoked or has expired. Reconnect it on the Sender accounts tab.';
                DB::table('rsvp_senders')->where('id', $row->id)->update(['google_status' => 'needs_reconnect', 'google_access_enc' => null,
                    'verified_at' => null, 'last_test_status' => 'needs_reconnect', 'last_test_error' => $msg, 'last_test_at' => now(), 'updated_at' => now()]);
                throw new \RuntimeException($msg);
            }
            throw new \RuntimeException('Google refused the token refresh: ' . ($r->json('error_description') ?: $err));
        }
        $j = $r->json();
        $upd = ['google_access_enc' => Crypt::encryptString((string) $j['access_token']),
            'google_token_expires_at' => now()->addSeconds(max(60, (int) ($j['expires_in'] ?? 3600)) - 60), 'updated_at' => now()];
        if (! empty($j['refresh_token'])) $upd['google_refresh_enc'] = Crypt::encryptString((string) $j['refresh_token']);
        DB::table('rsvp_senders')->where('id', $row->id)->update($upd);

        return (string) $j['access_token'];
    }

    /** Best effort: tell Google to forget the grant when a sender is deleted. */
    public static function revoke(object $sender): void
    {
        try {
            if (empty($sender->google_refresh_enc)) return;
            Http::asForm()->timeout(10)->post('https://oauth2.googleapis.com/revoke', ['token' => Crypt::decryptString($sender->google_refresh_enc)]);
        } catch (\Throwable $e) {
        }
    }
}
