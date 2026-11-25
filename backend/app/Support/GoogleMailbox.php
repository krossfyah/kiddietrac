<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "SIGN IN WITH GOOGLE" FOR MAILBOXES (2026-11-04, Anthony: "the mail client should be able to add mailboxes like how
 * outlook does it without any secret keys").
 *
 * The Gmail twin of MicrosoftMailbox: a Gmail / Google Workspace mailbox is read through the Gmail API with the
 * person's own Google sign-in, instead of IMAP with an app password. Same Google client as the RSVP "Connect Gmail"
 * (RsvpGmail::pair - Platform -> Email settings), and the same registered redirect URI: RsvpController::googleCallback
 * hands any state starting "gmbx." to callback() here, so nothing new has to be registered in Google Cloud.
 *
 * Same safety shape as Microsoft: the callback only parks the result under a one-time nonce and returns to the app;
 * finish() saves it for the SAME signed-in KiddieTrac user who started it. The nonce starts with "g" so the one
 * /email/microsoft/finish endpoint (and the screen's return handling) serves both.
 *
 * While the Google project is in Testing mode, Google ends every sign-in after 7 days and only listed test users can
 * sign in; the mailbox then shows Reconnect. Verification of the gmail.modify scope removes both limits.
 */
class GoogleMailbox
{
    public const SCOPES = 'openid email https://www.googleapis.com/auth/gmail.modify';
    private const STATE_TTL = 900;
    private const DONE_TTL = 600;
    private const API = 'https://gmail.googleapis.com/gmail/v1/users/me';
    private const FRONTEND = 'https://app.kiddietrac.com';

    public static function configured(): bool
    {
        return RsvpGmail::configured();
    }

    public static function uses(object $a): bool
    {
        return ($a->auth_type ?? 'password') === 'google';
    }

    public static function isOurs($state): bool
    {
        return is_string($state) && strncmp($state, 'gmbx.', 5) === 0;
    }

    /**
     * The Google sign-in page for this account. $cal (2026-11-25, ExternalCalendar): "Connect calendar" asks for
     * calendar.events as well; a mailbox whose calendar is already connected keeps asking for it on a Reconnect (Google
     * issues one refresh token for what was granted in that sign-in).
     */
    public static function authorizeUrl(object $a, int $userId, string $returnTo = 'email', ?array $cal = null): string
    {
        $state = 'gmbx.' . bin2hex(random_bytes(20));
        $returnTo = in_array($returnTo, ['email', 'sales-email', 'calendar'], true) ? $returnTo : 'email';
        $withCal = $cal !== null || ExternalCalendar::granted($a);
        Cache::put('kt_gmbx_state:' . hash('sha256', $state), ['account_id' => (int) $a->id, 'user_id' => $userId, 'return_to' => $returnTo, 'cal' => $cal, 'with_cal' => $withCal], self::STATE_TTL);
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => RsvpGmail::clientId(),
            'redirect_uri' => RsvpGmail::redirectUri(),
            'response_type' => 'code',
            'scope' => self::SCOPES . ($withCal ? ' ' . ExternalCalendar::GOOGLE_SCOPE : ''),
            'access_type' => 'offline',
            'prompt' => 'consent select_account',
            'include_granted_scopes' => 'false',
            'login_hint' => (string) $a->email_address,
            'state' => $state,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /** Google's redirect back. Parks the result for finish(); never saves it. */
    public static function callback(Request $r)
    {
        $st = Cache::pull('kt_gmbx_state:' . hash('sha256', (string) $r->query('state')));
        if (! $st) {
            return self::back(null, 'This Google sign-in link has expired or was already used. Start again from Email, Manage accounts.');
        }
        $to = $st['return_to'] ?? 'email';
        if ($r->query('error')) {
            return self::back(null, $r->query('error') === 'access_denied'
                ? 'Google sign-in was cancelled, or this Google account is not allowed to connect yet. Nothing was changed.'
                : 'Google could not sign you in (' . mb_substr((string) $r->query('error'), 0, 80) . '). Please try again.', $to);
        }
        $code = (string) $r->query('code');
        if ($code === '' || strlen($code) > 2048) {
            return self::back(null, 'Google did not send back a sign-in code. Please try again.', $to);
        }
        try {
            $tok = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => RsvpGmail::redirectUri()]);
        } catch (\Throwable $e) {
            return self::back(null, $e->getMessage(), $to);
        }
        if (! str_contains((string) ($tok['scope'] ?? ''), 'gmail.modify')) {
            return self::back(null, 'Google signed you in, but the box allowing KiddieTrac to read your mail was not ticked. Please sign in again and allow it.', $to);
        }
        if (! empty($st['cal']) && ! str_contains((string) ($tok['scope'] ?? ''), 'calendar.events')) {
            return self::back(null, 'Google signed you in, but the box allowing KiddieTrac to use your calendar was not ticked. Nothing was changed. Try again and allow it.', $to);
        }
        // Straight from Google's token endpoint over TLS, so the id_token claims can be read as they are (OIDC 3.1.3.7).
        $claims = [];
        $parts = explode('.', (string) ($tok['id_token'] ?? ''));
        if (count($parts) === 3) $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true) ?: [];
        $identity = strtolower(trim((string) ($claims['email'] ?? '')));
        $nonce = 'g' . bin2hex(random_bytes(20));
        Cache::put('kt_gmbx_done:' . hash('sha256', $nonce), $st + [
            'tok' => Crypt::encryptString(json_encode($tok)),
            'identity' => $identity,
        ], self::DONE_TTL);
        return self::back($nonce, null, $to);
    }

    private static function back(?string $nonce, ?string $err, string $to = 'email')
    {
        if ($to === 'calendar') {
            return redirect()->away(self::FRONTEND . '/dashboard.html?' . ($nonce ? 'kcal=' . $nonce : 'kcal_error=' . rawurlencode((string) $err)) . '#settings/calendar');
        }
        $q = $nonce ? 'mbx=' . $nonce : 'mbx_error=' . rawurlencode((string) $err);
        return redirect()->away(self::FRONTEND . '/dashboard.html?' . $q . '#' . (in_array($to, ['email', 'sales-email'], true) ? $to : 'email'));
    }

    /** Called by the app, signed in. Returns [ok, message, account_id, calendar request (or null)]. */
    public static function finish(int $userId, string $nonce): array
    {
        $p = Cache::pull('kt_gmbx_done:' . hash('sha256', $nonce));
        if (! $p) {
            return [false, 'This Google sign-in has expired. Please sign in with Google again.', null];
        }
        if ((int) $p['user_id'] !== $userId) {
            return [false, 'This Google sign-in was started from a different KiddieTrac account, so it was not connected.', null];
        }
        $a = DB::table('email_accounts')->where('id', $p['account_id'])
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('shared_scope', SalesMailbox::SCOPE))
            ->whereNull('deleted_at')->first();
        if (! $a) {
            return [false, 'That email account has been removed.', null];
        }
        $tok = json_decode(Crypt::decryptString($p['tok']), true) ?: [];
        $identity = (string) $p['identity'];
        $mailbox = strtolower(trim((string) $a->email_address));
        // A Google sign-in opens only its own mailbox (Gmail has no "Full Access" to someone else's).
        if ($identity !== '' && $identity !== $mailbox) {
            return [false, 'You signed in to Google as ' . $identity . ', not ' . $mailbox . '. Sign in as ' . $mailbox . ' (Google lets you pick the account).', (int) $a->id];
        }
        try {
            self::send((string) ($tok['access_token'] ?? ''), 'GET', self::API . '/profile');
        } catch (\Throwable $e) {
            return [false, 'Google signed you in but would not open the mailbox: ' . $e->getMessage(), (int) $a->id];
        }
        if (empty($tok['refresh_token'])) {
            return [false, 'Google did not allow KiddieTrac to stay signed in to this mailbox. Please try again.', (int) $a->id];
        }
        DB::table('email_accounts')->where('id', $a->id)->update([
            'provider' => 'google',
            'auth_type' => 'google',
            'oauth_token' => Crypt::encryptString(json_encode(['refresh_token' => $tok['refresh_token']] + (str_contains((string) ($tok['scope'] ?? ''), 'calendar.events') ? ['cal' => 1] : []))),
            'oauth_identity' => $identity ?: $mailbox,
            'oauth_connected_at' => now(),
            'secret' => null,
            'status' => 'connected',
            'status_detail' => 'Signed in with Google as ' . ($identity ?: $mailbox),
            'updated_at' => now(),
        ] + self::cols(['auth_failed_at' => null, 'token_refreshed_at' => now()]));
        self::cacheAccess((int) $a->id, $tok);
        return [true, 'Connected', (int) $a->id, $p['cal'] ?? null];
    }

    private static function cacheAccess(int $id, array $tok): void
    {
        if (! empty($tok['access_token'])) {
            Cache::put('kt_gmbx_at:' . $id, Crypt::encryptString($tok['access_token']), max(60, ((int) ($tok['expires_in'] ?? 3600)) - 300));
        }
    }

    private static function accessToken(object $a, bool $fresh = false): string
    {
        $key = 'kt_gmbx_at:' . $a->id;
        if (! $fresh && ($c = Cache::get($key))) {
            try { return Crypt::decryptString($c); } catch (\Throwable $e) {}
        }
        $rt = null; $flags = [];
        $stored = DB::table('email_accounts')->where('id', $a->id)->value('oauth_token') ?: $a->oauth_token;
        try { $j = json_decode(Crypt::decryptString((string) $stored), true) ?: []; $rt = $j['refresh_token'] ?? null; unset($j['refresh_token']); $flags = $j; } catch (\Throwable $e) {}
        if (! $rt) {
            self::authFailed($a, 'Google sign-in is missing. Reconnect this mailbox (Sign in with Google).');
            throw new \RuntimeException('This mailbox needs to be signed in with Google again (Manage accounts, Sign in with Google).');
        }
        try {
            $tok = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $rt]);
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 401) {
                self::authFailed($a, 'Google sign-in expired or was withdrawn. Reconnect this mailbox (Sign in with Google).');
                throw new \RuntimeException('Google sign-in for this mailbox has expired or was withdrawn. Choose Reconnect and sign in with Google again.');
            }
            throw $e;
        }
        $upd = self::cols(['token_refreshed_at' => now()]);
        if (! empty($tok['refresh_token']) && $tok['refresh_token'] !== $rt) {
            $upd['oauth_token'] = Crypt::encryptString(json_encode(['refresh_token' => $tok['refresh_token']] + $flags));   // keeps 'cal' (2026-11-25)
        }
        if ($upd) {
            try { DB::table('email_accounts')->where('id', $a->id)->update($upd); } catch (\Throwable $e) {}
        }
        self::cacheAccess((int) $a->id, $tok);
        return (string) $tok['access_token'];
    }

    private static function cols(array $c): array
    {
        static $have = null;
        if ($have === null) {
            try { $have = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('email_accounts')); } catch (\Throwable $e) { $have = []; }
        }
        return array_intersect_key($c, $have);
    }

    /** Google refused the saved sign-in: the account shows Reconnect (needs_reconnect) until finish() runs again. */
    private static function authFailed(object $a, string $detail): void
    {
        try {
            Cache::forget('kt_gmbx_at:' . $a->id);
            DB::table('email_accounts')->where('id', $a->id)->update(['status' => 'error', 'status_detail' => mb_substr($detail, 0, 480), 'updated_at' => now()]
                + self::cols(['auth_failed_at' => now()]));
        } catch (\Throwable $e) {}
    }

    /** Any Google API call on this sign-in (a full URL - the calendar, 2026-11-25). Retries once with a fresh token on 401. */
    public static function callUrl(object $a, string $method, string $url, array $query = [], $json = null)
    {
        try {
            return self::send(self::accessToken($a), $method, $url, $query, $json);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 401) throw $e;
            return self::send(self::accessToken($a, true), $method, $url, $query, $json);
        }
    }

    /** One Gmail API call (path under /users/me). Retries once with a fresh token on 401. */
    public static function call(object $a, string $method, string $path, array $query = [], $json = null)
    {
        try {
            return self::send(self::accessToken($a), $method, self::API . $path, $query, $json);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 401) throw $e;
            return self::send(self::accessToken($a, true), $method, self::API . $path, $query, $json);
        }
    }

    /**
     * Several GETs at once (the message list needs one per message). Keys are kept; a failed one is null.
     * @param array<string,array{0:string,1:array}> $reqs key => [path, query]
     */
    public static function pool(object $a, array $reqs): array
    {
        if (! $reqs) return [];
        $tok = self::accessToken($a);
        $out = [];
        foreach (array_chunk($reqs, 25, true) as $chunk) {
            $res = Http::pool(function ($pool) use ($chunk, $tok) {
                $calls = [];
                foreach ($chunk as $k => [$path, $query]) {
                    $calls[] = $pool->as((string) $k)->withToken($tok)->acceptJson()->timeout(20)->get(self::API . $path, $query);
                }
                return $calls;
            });
            foreach ($chunk as $k => $_) {
                $r = $res[(string) $k] ?? null;
                $out[$k] = ($r instanceof \Illuminate\Http\Client\Response && $r->successful()) ? $r->json() : null;
            }
        }
        return $out;
    }

    private static function send(string $token, string $method, string $url, array $query = [], $json = null)
    {
        $req = Http::withToken($token)->acceptJson()->timeout(25);
        $full = $query ? $url . '?' . self::qs($query) : $url;
        $r = $json === null ? $req->send($method, $full) : $req->send($method, $full, ['json' => $json]);
        if ($r->status() === 401) throw new \RuntimeException('Google sign-in needs refreshing.', 401);
        if (! $r->successful()) {
            $msg = (string) ($r->json('error.message') ?: ('HTTP ' . $r->status()));
            throw new \RuntimeException($r->status() === 404 ? 'That message was not found (it may have been moved or deleted).'
                : ($r->status() === 403 ? 'Google did not allow this (' . mb_substr($msg, 0, 120) . ').' : 'Gmail could not do that right now (' . mb_substr($msg, 0, 120) . ').'), $r->status());
        }
        return $r;
    }

    /** Gmail repeats a key for a list (labelIds=A&labelIds=B, metadataHeaders=...), which http_build_query cannot write. */
    private static function qs(array $q): string
    {
        $parts = [];
        foreach ($q as $k => $v) {
            foreach ((array) $v as $one) $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $one);
        }
        return implode('&', $parts);
    }

    private static function tokenRequest(array $params): array
    {
        $r = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', $params + [
            'client_id' => RsvpGmail::clientId(), 'client_secret' => RsvpGmail::clientSecret(),
        ]);
        if (! $r->successful()) {
            $err = (string) ($r->json('error') ?: ('HTTP ' . $r->status()));
            throw new \RuntimeException('Google refused the sign-in (' . $err . ').', in_array($err, ['invalid_grant', 'unauthorized_client'], true) ? 401 : 422);
        }
        return $r->json() ?: [];
    }
}
