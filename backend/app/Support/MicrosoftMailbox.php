<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * "SIGN IN WITH MICROSOFT" FOR MAILBOXES (2026-10-20, support ticket #157).
 *
 * Microsoft 365 refuses a password over IMAP ("Server disables LOGIN"), so a Microsoft
 * mailbox in the Email client is read through Microsoft Graph with the person's own
 * Microsoft sign-in (delegated OAuth), using the same Azure app as "Sign in with
 * Microsoft" on the login page. That app's redirect URI is reused: SocialAuthController
 * hands any callback whose state starts "mbx." to callback() here, so no new redirect
 * URI has to be registered in Azure.
 *
 * WHY THE APP FINISHES THE CONNECTION, NOT THE CALLBACK. The callback is a plain browser
 * redirect with no KiddieTrac session. If it saved the tokens itself, anyone could start
 * a sign-in for their own email account and send the Microsoft link to somebody else;
 * that person's mailbox would land in the sender's account. So the callback only parks
 * the result under a one-time nonce and returns to the app, and finish() saves it only
 * for the SAME signed-in KiddieTrac user who started it. The nonce never reaches the
 * person who started a link they sent to someone else.
 *
 * Tokens: the refresh token is stored encrypted on the account (oauth_token); access
 * tokens are cached, encrypted, for their lifetime. The stored password is cleared on
 * connect, since a Microsoft mailbox no longer uses it.
 */
class MicrosoftMailbox
{
    /* Mail.ReadWrite: read, mark read, flag. Mail.ReadWrite.Shared: a shared mailbox
       (office@...) the person has Full Access to. Neither needs admin consent unless the
       organisation has switched user consent off. */
    public const SCOPES = 'offline_access openid profile email User.Read Mail.ReadWrite Mail.ReadWrite.Shared';
    /* Automatic replies (2026-11-03, Anthony: "can mail client read out of office settings already set previously?").
       Asked for at sign-in only. A refresh asks for it only when the stored sign-in says it was granted ('ms' in
       oauth_token): asking a refresh for a permission that was never granted is refused (invalid_grant) and would
       knock out a mailbox that was connected before this existed. */
    public const SETTINGS_SCOPE = 'MailboxSettings.ReadWrite';
    /* SENDING FROM ANY MAILBOX (2026-11-09, Anthony: "why can't we reply to emails from the agency email client?" ->
       yes, Microsoft + Gmail). The platform mail app can only send for KiddieTrac's own Microsoft 365 organisation, so
       an agency's mailbox sends with its OWN sign-in: delegated Mail.Send (Mail.Send.Shared for a shared mailbox the
       person has Send As on). Same rule as SETTINGS_SCOPE: asked at sign-in, recorded as 'snd' in oauth_token, and a
       refresh asks for it only when it was granted. A mailbox connected before this sends after one Reconnect. */
    public const SEND_SCOPE = 'Mail.Send Mail.Send.Shared';
    private const STATE_TTL = 900;
    private const DONE_TTL = 600;
    private const GRAPH = 'https://graph.microsoft.com/v1.0';
    private const FRONTEND = 'https://app.kiddietrac.com';

    public static function configured(): bool
    {
        return (bool) config('services.microsoft.client_id') && (bool) config('services.microsoft.client_secret');
    }

    public static function uses(object $a): bool
    {
        return ($a->auth_type ?? 'password') === 'microsoft';
    }

    /** Always 'common': any work, school or personal Microsoft account (see PlatformSettings, 2026-11-05). */
    private static function tenant(): string
    {
        return 'common';
    }

    private static function redirectUri(): string
    {
        return (string) config('services.microsoft.redirect');
    }

    /**
     * The Microsoft sign-in page for this account. $cal (2026-11-25, ExternalCalendar): "Connect calendar" - the same
     * sign-in asking for the calendar too; ['scope' => personal|agency, 'agency_id' => ?int] comes back to finish().
     * A mailbox whose calendar is already connected keeps asking for it on a plain Reconnect, so reconnecting mail
     * never quietly drops the calendar.
     */
    public static function authorizeUrl(object $a, int $userId, string $returnTo = 'email', ?array $cal = null): string
    {
        $state = 'mbx.' . bin2hex(random_bytes(20));
        // Where the person lands afterwards: Email, or Sales -> Email for the shared sales mailbox (2026-10-26).
        $returnTo = in_array($returnTo, ['email', 'sales-email', 'calendar'], true) ? $returnTo : 'email';
        $withCal = $cal !== null || \App\Support\ExternalCalendar::granted($a);
        Cache::put('kt_mbx_state:' . hash('sha256', $state), ['account_id' => (int) $a->id, 'user_id' => $userId, 'return_to' => $returnTo, 'cal' => $cal, 'with_cal' => $withCal], self::STATE_TTL);
        return 'https://login.microsoftonline.com/' . rawurlencode(self::tenant()) . '/oauth2/v2.0/authorize?' . http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => self::redirectUri(),
            'response_mode' => 'query',
            'scope' => self::SCOPES . ' ' . self::SETTINGS_SCOPE . ' ' . self::SEND_SCOPE . ($withCal ? ' ' . \App\Support\ExternalCalendar::MS_SCOPE : ''),
            'state' => $state,
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function isOurs($state): bool
    {
        return is_string($state) && strncmp($state, 'mbx.', 4) === 0;
    }

    /** Microsoft's redirect back. Parks the result for finish(); never saves it. */
    public static function callback(Request $r)
    {
        $st = Cache::pull('kt_mbx_state:' . hash('sha256', (string) $r->query('state')));
        if (! $st) {
            return self::back(null, 'This Microsoft sign-in link has expired or was already used. Start again from Email, Manage accounts.');
        }
        $to = $st['return_to'] ?? 'email';
        if ($r->query('error')) {
            return self::back(null, self::explainAad((string) $r->query('error'), (string) $r->query('error_description')), $to);
        }
        $code = (string) $r->query('code');
        if ($code === '') {
            return self::back(null, 'Microsoft did not send back a sign-in code. Please try again.', $to);
        }
        try {
            $tok = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri(),
                'scope' => self::SCOPES . ' ' . self::SETTINGS_SCOPE . ' ' . self::SEND_SCOPE . (! empty($st['with_cal']) ? ' ' . \App\Support\ExternalCalendar::MS_SCOPE : '')]);
            $me = self::send($tok['access_token'], 'GET', self::GRAPH . '/me', ['$select' => 'mail,userPrincipalName'])->json();
        } catch (\Throwable $e) {
            return self::back(null, $e->getMessage(), $to);
        }
        if (! empty($st['cal']) && stripos((string) ($tok['scope'] ?? ''), 'Calendars.ReadWrite') === false) {
            return self::back(null, 'Microsoft signed you in, but did not allow KiddieTrac to use the calendar. Nothing was changed. Try again and allow the calendar, or ask your Microsoft 365 administrator.', $to);
        }
        $identity = strtolower(trim((string) (($me['mail'] ?? '') ?: ($me['userPrincipalName'] ?? ''))));
        $nonce = bin2hex(random_bytes(20));
        Cache::put('kt_mbx_done:' . hash('sha256', $nonce), $st + [
            'tok' => Crypt::encryptString(json_encode($tok)),
            'identity' => $identity,
        ], self::DONE_TTL);
        return self::back($nonce, null, $to);
    }

    private static function back(?string $nonce, ?string $err, string $to = 'email')
    {
        // A calendar connect returns to Settings > Calendar with its own key, so the Email screen does not take it.
        if ($to === 'calendar') {
            return redirect()->away(self::FRONTEND . '/dashboard.html?' . ($nonce ? 'kcal=' . $nonce : 'kcal_error=' . rawurlencode((string) $err)) . '#settings/calendar');
        }
        $q = $nonce ? 'mbx=' . $nonce : 'mbx_error=' . rawurlencode((string) $err);
        return redirect()->away(self::FRONTEND . '/dashboard.html?' . $q . '#' . (in_array($to, ['email', 'sales-email'], true) ? $to : 'email'));
    }

    /** Called by the app, signed in. Returns [ok, message, account_id, calendar request (or null)]. */
    public static function finish(int $userId, string $nonce): array
    {
        $p = Cache::pull('kt_mbx_done:' . hash('sha256', $nonce));
        if (! $p) {
            return [false, 'This Microsoft sign-in has expired. Please sign in with Microsoft again.', null];
        }
        if ((int) $p['user_id'] !== $userId) {
            return [false, 'This Microsoft sign-in was started from a different KiddieTrac account, so it was not connected.', null];
        }
        // Their own mailbox, or the shared sales mailbox (only reachable from Sales -> Email, behind the sales roles).
        $a = DB::table('email_accounts')->where('id', $p['account_id'])
            ->where(fn ($q) => $q->where('user_id', $userId)->orWhere('shared_scope', \App\Support\SalesMailbox::SCOPE))
            ->whereNull('deleted_at')->first();
        if (! $a) {
            return [false, 'That email account has been removed.', null];
        }
        $tok = json_decode(Crypt::decryptString($p['tok']), true) ?: [];
        $identity = (string) $p['identity'];
        $mailbox = strtolower(trim((string) $a->email_address));

        // Can this sign-in actually open the mailbox? Checked before anything is saved.
        try {
            self::send($tok['access_token'] ?? '', 'GET', self::GRAPH . self::basePath($identity, $mailbox) . '/mailFolders/inbox', ['$select' => 'id']);
        } catch (\Throwable $e) {
            $msg = $identity !== $mailbox
                ? 'You signed in to Microsoft as ' . $identity . ', which cannot open ' . $mailbox . '. Sign in as ' . $mailbox
                  . ', or ask your Microsoft 365 administrator to give ' . $identity . ' Full Access to that mailbox.'
                : 'Microsoft signed you in but would not open the mailbox: ' . $e->getMessage();
            return [false, $msg, (int) $a->id];
        }
        if (empty($tok['refresh_token'])) {
            return [false, 'Microsoft did not allow KiddieTrac to stay signed in to this mailbox. Please try again.', (int) $a->id];
        }

        DB::table('email_accounts')->where('id', $a->id)->update([
            'auth_type' => 'microsoft',
            'oauth_token' => Crypt::encryptString(json_encode(['refresh_token' => $tok['refresh_token']] + self::flags((string) ($tok['scope'] ?? ''), true, true, true))),
            'oauth_identity' => $identity,
            'oauth_connected_at' => now(),
            'secret' => null,
            'status' => 'connected',
            'status_detail' => 'Signed in with Microsoft as ' . $identity,
            'updated_at' => now(),
        ] + self::cols(['auth_failed_at' => null, 'token_refreshed_at' => now()]));
        self::cacheAccess((int) $a->id, $tok);
        return [true, 'Connected', (int) $a->id, $p['cal'] ?? null];
    }

    /** The mailbox's Graph path: the person's own, or a shared one they can open. */
    private static function basePath(?string $identity, string $mailbox): string
    {
        $mailbox = strtolower(trim($mailbox));
        return (! $identity || strtolower($identity) === $mailbox) ? '/me' : '/users/' . rawurlencode($mailbox);
    }

    private static function cacheAccess(int $id, array $tok): void
    {
        if (! empty($tok['access_token'])) {
            Cache::put('kt_mbx_at:' . $id, Crypt::encryptString($tok['access_token']), max(60, ((int) ($tok['expires_in'] ?? 3600)) - 300));
        }
    }

    /** A live access token for the account, refreshing it when needed. */
    private static function accessToken(object $a, bool $fresh = false): string
    {
        $key = 'kt_mbx_at:' . $a->id;
        if (! $fresh && ($c = Cache::get($key))) {
            try { return Crypt::decryptString($c); } catch (\Throwable $e) {}
        }
        /* STAY SIGNED IN (2026-11-02, Anthony: "keep the connection alive without me signing in all the time").
           Read the refresh token from the ROW, not from the copy this request loaded: another request (or the
           watcher) may have rotated it a moment ago. Microsoft keeps an older refresh token valid after rotation,
           so a race costs nothing - but saving a stale one over a fresh one would, a day later. */
        $rt = null; $ms = false; $snd = false; $cal = false;
        $stored = DB::table('email_accounts')->where('id', $a->id)->value('oauth_token') ?: $a->oauth_token;
        try { $j = json_decode(Crypt::decryptString((string) $stored), true) ?: []; $rt = $j['refresh_token'] ?? null; $ms = ! empty($j['ms']); $snd = ! empty($j['snd']); $cal = ! empty($j['cal']); } catch (\Throwable $e) {}
        $was = ['ms' => $ms, 'snd' => $snd, 'cal' => $cal];
        if (! $rt) {
            self::authFailed($a, 'Microsoft sign-in is missing. Reconnect this mailbox (Sign in with Microsoft).');
            throw new \RuntimeException('This mailbox needs to be signed in with Microsoft again (Manage accounts, Sign in with Microsoft).');
        }
        try {
            try {
                $tok = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $rt]
                    + ($ms || $snd || $cal ? ['scope' => self::SCOPES . ($ms ? ' ' . self::SETTINGS_SCOPE : '') . ($snd ? ' ' . self::SEND_SCOPE : '') . ($cal ? ' ' . \App\Support\ExternalCalendar::MS_SCOPE : '')] : []));
            } catch (\RuntimeException $e) {
                // An extra permission was withdrawn on its own: keep the mailbox; what is still granted is read back
                // from the answer (no scope asked = everything the sign-in still has).
                if (! ($ms || $snd || $cal) || $e->getCode() !== 401) throw $e;
                $tok = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $rt]);
                $f = self::flags((string) ($tok['scope'] ?? ''), true, true, true);
                $ms = ! empty($f['ms']); $snd = ! empty($f['snd']); $cal = ! empty($f['cal']);
            }
        } catch (\RuntimeException $e) {
            // 401 = invalid_grant: revoked, password changed, MFA now required, or 90 days unused. Network trouble is not.
            if ($e->getCode() === 401) {
                self::authFailed($a, 'Microsoft sign-in expired or was withdrawn (' . mb_substr($e->getMessage(), 0, 200) . '). Reconnect this mailbox (Sign in with Microsoft).');
                throw new \RuntimeException('Microsoft sign-in for this mailbox has expired or was withdrawn. Choose Reconnect and sign in with Microsoft again.');
            }
            throw $e;
        }
        // Microsoft hands out a NEW refresh token on (almost) every use; the newest is always the one kept.
        $upd = self::cols(['token_refreshed_at' => now()]);
        $newRt = ! empty($tok['refresh_token']) ? (string) $tok['refresh_token'] : $rt;
        if ($newRt !== $rt || $was !== ['ms' => $ms, 'snd' => $snd, 'cal' => $cal]) {
            $upd['oauth_token'] = Crypt::encryptString(json_encode(['refresh_token' => $newRt] + ($ms ? ['ms' => 1] : []) + ($snd ? ['snd' => 1] : []) + ($cal ? ['cal' => 1] : [])));
        }
        if ($upd) {
            try { DB::table('email_accounts')->where('id', $a->id)->update($upd); } catch (\Throwable $e) {}
        }
        self::cacheAccess((int) $a->id, $tok);
        return (string) $tok['access_token'];
    }

    /** The extra permissions a token's granted scope string carries, as stored in oauth_token ('ms', 'snd', 'cal'). */
    private static function flags(string $scope, bool $settings, bool $send, bool $calendar = false): array
    {
        $out = [];
        if ($settings && stripos($scope, self::SETTINGS_SCOPE) !== false) $out['ms'] = 1;
        if ($send && preg_match('/(^|[\s\/])Mail\.Send(\s|$)/i', $scope)) $out['snd'] = 1;
        if ($calendar && preg_match('/(^|[\s\/])Calendars\.ReadWrite(\s|$)/i', $scope)) $out['cal'] = 1;
        return $out;
    }

    /** Was this mailbox signed in with permission to send as itself (SEND_SCOPE)? */
    public static function canSend(object $a): bool
    {
        if (! self::uses($a) || ! empty($a->auth_failed_at ?? null)) return false;
        try { return ! empty((json_decode(Crypt::decryptString((string) $a->oauth_token), true) ?: [])['snd']); } catch (\Throwable $e) { return false; }
    }

    /** Send a draft with the mailbox's own sign-in (Graph wants an empty body with a length, not "{}"). */
    public static function sendDraft(object $a, string $draftId): void
    {
        $url = self::baseUrl($a) . '/messages/' . rawurlencode($draftId) . '/send';
        $post = fn (string $t) => Http::withToken($t)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(60)->withBody('', 'application/json')->post($url);
        $r = $post(self::accessToken($a));
        if ($r->status() === 401) $r = $post(self::accessToken($a, true));
        if (! $r->successful()) {
            $msg = (string) ($r->json('error.message') ?: ('HTTP ' . $r->status()));
            if ($r->status() === 403) $msg = 'this sign-in may not send from ' . $a->email_address . ' (' . $msg . '). For a shared mailbox, its Microsoft 365 administrator gives you Send As';
            throw new \RuntimeException('Microsoft would not send it: ' . $msg . '.');
        }
    }

    /** Columns added by the 2026_11_02 watch migration, dropped from an update until it has run. */
    private static function cols(array $c): array
    {
        static $have = null;
        if ($have === null) {
            try { $have = array_flip(\Illuminate\Support\Facades\Schema::getColumnListing('email_accounts')); } catch (\Throwable $e) { $have = []; }
        }
        return array_intersect_key($c, $have);
    }

    /**
     * Microsoft refused the stored sign-in. Mark the account, and tell the people who use it ONCE: only the request
     * that flips auth_failed_at from empty writes the bell, so a screen retrying every minute is not a bell every
     * minute. Reconnecting (finish()) clears it.
     */
    private static function authFailed(object $a, string $detail): void
    {
        try {
            Cache::forget('kt_mbx_at:' . $a->id);
            $base = ['status' => 'error', 'status_detail' => mb_substr($detail, 0, 480), 'updated_at' => now()];
            if (! self::cols(['auth_failed_at' => 1])) {
                DB::table('email_accounts')->where('id', $a->id)->update($base);
                return;
            }
            $first = DB::table('email_accounts')->where('id', $a->id)->whereNull('auth_failed_at')->update($base + ['auth_failed_at' => now()]);
            if (! $first) return;
            $box = (string) $a->email_address;
            $link = ($a->shared_scope ?? null) === \App\Support\SalesMailbox::SCOPE ? '#sales-email' : '#email';
            $rows = [];
            foreach (self::recipients($a) as $uid) {
                $rows[] = ['user_id' => $uid, 'type' => 'email_reconnect', 'title' => '🔌 Reconnect ' . $box,
                    'body' => 'Microsoft stopped accepting KiddieTrac\'s sign-in for ' . $box . ' (a password change or a withdrawn permission). Open Email and choose Reconnect.',
                    'data' => json_encode(['link' => $link, 'account_id' => (int) $a->id, 'kt_category' => 'email', 'severity' => 'warning']), 'created_at' => now()];
            }
            \App\Support\Notify::write($rows);
            \Illuminate\Support\Facades\Log::warning('mailbox sign-in refused', ['account' => $a->id, 'mailbox' => $box]);
        } catch (\Throwable $e) {}
    }

    /**
     * Who is told about this mailbox (new mail, reconnect): a sales mailbox belongs to the sales team - everyone the
     * /sales/email routes let in (role:sales_rep, platform_admin passes any role) - and a personal one to its owner.
     */
    public static function recipients(object $a): array
    {
        if (($a->shared_scope ?? null) !== \App\Support\SalesMailbox::SCOPE) {
            return $a->user_id ? [(int) $a->user_id] : [];
        }
        return DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->whereIn('ra.role', ['sales_rep', 'platform_admin'])->where('ra.active', 1)
            ->whereNull('u.deleted_at')->where('u.status', 'active')
            ->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
    }

    /** The daily keep-alive: use the refresh token (and keep the new one) so it never reaches Microsoft's 90-day idle limit. */
    public static function keepAlive(object $a): void
    {
        self::accessToken($a, true);
    }

    /**
     * Can this sign-in read and change the mailbox's settings (automatic replies)? Read from the access token's
     * scp claim; a token that is not a readable JWT (personal Microsoft accounts) is tried for real by the caller.
     * null = cannot tell.
     */
    public static function canSettings(object $a): ?bool
    {
        $p = explode('.', self::accessToken($a));
        $c = count($p) === 3 ? json_decode((string) base64_decode(strtr($p[1], '-_', '+/')), true) : null;
        if (! is_array($c) || ! isset($c['scp'])) return null;
        return stripos((string) $c['scp'], 'MailboxSettings.Read') !== false;
    }

    /** The account's own Graph base URL (/me or /users/{mailbox}). */
    public static function baseUrl(object $a): string
    {
        return self::GRAPH . self::basePath($a->oauth_identity ?? null, (string) $a->email_address);
    }

    /**
     * Upload one large attachment to a draft through an upload session (Graph refuses a single request over ~3 MB).
     * The session URL is pre-authorised: Microsoft rejects an Authorization header on it.
     */
    public static function uploadLarge(object $a, string $draftId, string $name, string $type, string $path): void
    {
        $size = (int) filesize($path);
        $s = self::call($a, 'POST', '/messages/' . rawurlencode($draftId) . '/attachments/createUploadSession', [], [
            'AttachmentItem' => ['attachmentType' => 'file', 'name' => $name, 'size' => $size, 'contentType' => $type ?: 'application/octet-stream'],
        ])->json();
        $url = (string) ($s['uploadUrl'] ?? '');
        if ($url === '') throw new \RuntimeException('Microsoft would not accept the attachment ' . $name . '.');
        $fh = fopen($path, 'rb');
        $chunk = 320 * 1024 * 12;   // Graph wants multiples of 320 KiB
        try {
            for ($off = 0; $off < $size; $off += $chunk) {
                $bytes = fread($fh, $chunk);
                $end = $off + strlen($bytes) - 1;
                $r = Http::withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(60)
                    ->withHeaders(['Content-Range' => 'bytes ' . $off . '-' . $end . '/' . $size])
                    ->withBody($bytes, 'application/octet-stream')->put($url);
                if (! $r->successful()) throw new \RuntimeException('The attachment ' . $name . ' did not upload (' . $r->status() . '). Please try again.');
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * One Graph call against the account's mailbox. $path is relative to the mailbox
     * ("/mailFolders"). Throws RuntimeException with a message a person can act on.
     */
    public static function call(object $a, string $method, string $path, array $query = [], $json = null)
    {
        $url = self::GRAPH . self::basePath($a->oauth_identity ?? null, (string) $a->email_address) . $path;
        try {
            return self::send(self::accessToken($a), $method, $url, $query, $json);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 401) throw $e;
            // A revoked or stale access token: mint a fresh one once.
            return self::send(self::accessToken($a, true), $method, $url, $query, $json);
        }
    }

    /**
     * Several mailbox calls in Graph JSON batches of 20 (the $batch limit) - used by bulk delete / move / mark / flag
     * (2026-11-03). $requests: [key => ['method' => 'POST', 'path' => '/messages/{id}/move', 'body' => [...]]], paths
     * relative to the mailbox like call(). Returns [key => ['status' => int, 'body' => array]]. Items Graph throttles
     * (429) or that fail with a 5xx are tried once more after a short pause.
     */
    public static function batch(object $a, array $requests): array
    {
        $base = self::basePath($a->oauth_identity ?? null, (string) $a->email_address);
        $out = [];
        $todo = $requests;
        for ($round = 0; $round < 2 && $todo; $round++) {
            if ($round > 0) usleep(1500000);
            $retry = [];
            foreach (array_chunk($todo, 20, true) as $chunk) {
                $reqs = []; $map = []; $n = 0;
                foreach ($chunk as $key => $r) {
                    $id = (string) (++$n);
                    $map[$id] = $key;
                    $one = ['id' => $id, 'method' => $r['method'], 'url' => $base . $r['path']];
                    if (array_key_exists('body', $r)) { $one['body'] = $r['body']; $one['headers'] = ['Content-Type' => 'application/json']; }
                    $reqs[] = $one;
                }
                try {
                    $resp = self::send(self::accessToken($a), 'POST', self::GRAPH . '/$batch', [], ['requests' => $reqs]);
                } catch (\RuntimeException $e) {
                    if ($e->getCode() !== 401) throw $e;
                    $resp = self::send(self::accessToken($a, true), 'POST', self::GRAPH . '/$batch', [], ['requests' => $reqs]);
                }
                foreach ((array) ($resp->json('responses') ?? []) as $r) {
                    $key = $map[(string) ($r['id'] ?? '')] ?? null;
                    if ($key === null) continue;
                    $st = (int) ($r['status'] ?? 0);
                    if (($st === 429 || $st >= 500) && $round === 0) { $retry[$key] = $chunk[$key]; continue; }
                    $out[$key] = ['status' => $st, 'body' => (array) ($r['body'] ?? [])];
                }
                foreach ($map as $key) {
                    if (! isset($out[$key]) && ! isset($retry[$key])) $out[$key] = ['status' => 0, 'body' => []];
                }
            }
            $todo = $retry;
        }
        return $out;
    }

    /** $json: an array, or an object (an empty object is sent as {} - Graph refuses [] for "no options"). */
    private static function send(string $token, string $method, string $url, array $query = [], $json = null)
    {
        if ($query) {
            // Graph wants its $-options with a literal "$"; http_build_query would encode it.
            $url .= '?' . implode('&', array_map(fn ($k, $v) => $k . '=' . rawurlencode((string) $v), array_keys($query), $query));
        }
        try {
            $req = Http::withToken($token)->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(25);
            $resp = $json !== null ? $req->asJson()->send($method, $url, ['json' => $json]) : $req->send($method, $url);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach Microsoft. Please try again in a moment.');
        }
        if ($resp->successful()) return $resp;
        $err = (array) ($resp->json('error') ?? []);
        $msg = (string) ($err['message'] ?? '');
        if ($resp->status() === 401) throw new \RuntimeException('Microsoft did not accept the sign-in.', 401);
        if ($resp->status() === 403 || ($err['code'] ?? '') === 'ErrorAccessDenied') {
            throw new \RuntimeException('Microsoft refused access to this mailbox' . ($msg ? ' (' . $msg . ')' : '') . '.', 403);
        }
        if ($resp->status() === 404) throw new \RuntimeException('Not found in the mailbox (it may have been moved or deleted).', 404);
        throw new \RuntimeException('Microsoft returned an error (' . $resp->status() . ')' . ($msg ? ': ' . $msg : '') . '.', $resp->status());
    }

    /** Token endpoint. Code 401 = the refresh token is dead (sign in again). */
    private static function tokenRequest(array $params): array
    {
        try {
            $resp = Http::asForm()->withOptions(['curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4]])->timeout(20)
                ->post('https://login.microsoftonline.com/' . rawurlencode(self::tenant()) . '/oauth2/v2.0/token', $params + [
                    'client_id' => config('services.microsoft.client_id'),
                    'client_secret' => config('services.microsoft.client_secret'),
                    'scope' => self::SCOPES,
                ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not reach Microsoft sign-in. Please try again in a moment.');
        }
        $j = (array) ($resp->json() ?? []);
        if ($resp->successful() && ! empty($j['access_token'])) return $j;
        $err = (string) ($j['error'] ?? 'error');
        throw new \RuntimeException(self::explainAad($err, (string) ($j['error_description'] ?? '')), $err === 'invalid_grant' ? 401 : 0);
    }

    /** Azure AD errors in words a person can act on. */
    private static function explainAad(string $err, string $desc): string
    {
        if ($err === 'access_denied' && ! preg_match('/AADSTS(65001|90094|90095)/', $desc)) {
            return 'Microsoft sign-in was cancelled, so the mailbox was not connected.';
        }
        if (preg_match('/AADSTS(65001|90094|90095)/', $desc) || $err === 'consent_required') {
            return 'Your Microsoft 365 organisation requires an administrator to approve KiddieTrac reading mail. '
                . 'Ask your Microsoft 365 admin to approve the KiddieTrac app (Microsoft Entra admin centre, Enterprise applications), then sign in again.';
        }
        if (preg_match('/AADSTS700016/', $desc)) return 'The KiddieTrac Microsoft app is not available to your organisation. Please contact KiddieTrac support.';
        if (preg_match('/AADSTS(7000215|7000222)/', $desc)) return 'KiddieTrac\'s Microsoft app key is invalid or expired. Please contact KiddieTrac support.';
        if (preg_match('/AADSTS50011/', $desc)) return 'Microsoft rejected KiddieTrac\'s return address. Please contact KiddieTrac support.';
        if ($err === 'invalid_grant') return 'Microsoft sign-in for this mailbox has expired or was withdrawn. Please sign in with Microsoft again.';
        $first = trim(preg_replace('/\s+/', ' ', strtok($desc ?: $err, "\r\n")));
        return 'Microsoft sign-in failed: ' . mb_substr(preg_replace('/^AADSTS\d+:\s*/', '', $first), 0, 220);
    }
}
