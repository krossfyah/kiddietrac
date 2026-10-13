<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;

/**
 * Social sign-in (Google / Microsoft / Facebook) via Laravel Socialite.
 *
 * Security model (per product decision): LINK TO EXISTING ACCOUNTS ONLY. A social
 * login only succeeds if the provider's verified email matches an already-invited
 * KiddieTrac user; it never creates a new account. Each provider stays hidden
 * (providers() omits it) until its client_id/secret are set in .env, so this can
 * ship dark and light up per provider as credentials are added.
 *
 * Register each provider's redirect URI as:
 *   https://api.kiddietrac.com/api/v1/auth/social/{provider}/callback
 */
class SocialAuthController extends Controller
{
    private const PROVIDERS = ['google', 'microsoft', 'facebook'];
    private const FRONTEND = 'https://app.kiddietrac.com';

    /* SIGN-IN FROM THE PHONE APP (2026-10-13, Anthony: "using google or microsoft to sign
       in using mobile apk goes to the mobile site vs staying in the mobile apk").

       The app's WebView may not show Google's sign-in (Google refuses embedded WebViews),
       so Android handed the whole flow to Chrome, and the callback below then signed the
       person in to the website IN CHROME. Now the app opens the flow in a secure browser
       tab with ?app=1&cc=<challenge>, and the callback, instead of a token in a website
       URL, sends a one-time code back to the app through its own link (APP_RETURN). The
       app trades the code for a token at appExchange().

       PKCE-style: the app made a secret (verifier) and sent only its SHA-256 (cc). Any
       other app on the phone that claimed the same link would get the code but not the
       verifier, so the code is useless to it. One use, two minutes. */
    private const APP_RETURN = 'com.kiddietrac.app://auth';
    private const APP_CODE_TTL = 120;

    /** The PKCE challenge when this sign-in came from the app, else null. */
    private function appChallenge(?string $state): ?string
    {
        if (! is_string($state) || strncmp($state, 'app.', 4) !== 0) return null;
        $cc = substr($state, 4);
        return preg_match('/^[A-Za-z0-9_-]{43}$/', $cc) ? $cc : null;
    }

    private function enabled(string $provider): bool
    {
        return in_array($provider, self::PROVIDERS, true)
            && (bool) config("services.$provider.client_id")
            && (bool) config("services.$provider.client_secret");
    }

    /** Which providers are configured — the login page shows a button per entry. */
    public function providers()
    {
        return response()->json([
            'providers' => array_values(array_filter(self::PROVIDERS, fn ($p) => $this->enabled($p))),
        ]);
    }

    public function redirect(string $provider)
    {
        if (! $this->enabled($provider)) {
            return $this->fail("That sign-in method isn't enabled yet.");
        }
        try {
            // A fresh driver: Socialite caches them, and with() would stay on the cached one,
            // so a later plain sign-in in the same process would be marked as the app's.
            Socialite::forgetDrivers();
            $driver = Socialite::driver($provider)->stateless();
            $cc = (string) request()->query('cc', '');
            if (request()->query('app') === '1' && preg_match('/^[A-Za-z0-9_-]{43}$/', $cc)) {
                // Comes back to callback() untouched as ?state=, which is how it knows.
                $driver->with(['state' => 'app.' . $cc]);
            }
            return $driver->redirect();
        } catch (\Throwable $e) {
            return $this->fail('Could not start ' . ucfirst($provider) . ' sign-in.');
        }
    }

    public function callback(string $provider)
    {
        $this->appCc = $this->appChallenge(request()->query('state'));
        if (! $this->enabled($provider)) {
            return $this->fail("That sign-in method isn't enabled yet.");
        }
        try {
            $su = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            return $this->fail('Sign-in with ' . ucfirst($provider) . ' failed. Please try again.');
        }

        $email = strtolower(trim((string) ($su->getEmail() ?? '')));
        if ($email === '') {
            return $this->fail('Your ' . ucfirst($provider) . ' account did not share an email address, so we could not match it.');
        }

        /* Exactly one account, or we do not guess — the same rule as SsoController, and
           for the same reason: the social_identities row written below is permanent, so
           a guess here signs somebody into the wrong account for good. This also stops
           picking a switched-off account, which the old ->first() could do and which
           then failed the status check below for a reason the user could not act on. */
        $user = \App\Support\EmailAccounts::soleLive($email);

        if (! $user && \App\Support\EmailAccounts::isAmbiguous($email)) {
            return $this->fail(
                'More than one KiddieTrac account uses ' . $email . '. Sign in with your '
                . 'username and password, then link ' . ucfirst((string) $provider)
                . ' from your account settings.'
            );
        }

        if (! $user) {
            return $this->fail('No KiddieTrac account is linked to ' . $email . '. Ask your administrator to invite you first.');
        }
        if (in_array($user->status ?? '', ['suspended', 'deactivated'], true)) {
            return $this->fail('This account is not active. Please contact your administrator.');
        }

        // Remember the link so the same provider identity maps straight here next time.
        DB::table('social_identities')->updateOrInsert(
            ['provider' => $provider, 'provider_id' => (string) $su->getId()],
            ['user_id' => $user->id, 'email' => $email, 'updated_at' => now(), 'created_at' => now()]
        );
        \App\Support\AccountStatus::markClaimed((int) $user->id);
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);

        if ($this->appCc) {
            // The app gets a code, not a token: the token is made in appExchange().
            $code = bin2hex(random_bytes(24));
            \Illuminate\Support\Facades\Cache::put('kt_social_app:' . hash('sha256', $code),
                ['user_id' => (int) $user->id, 'provider' => $provider, 'cc' => $this->appCc], self::APP_CODE_TTL);
            return redirect()->away(self::APP_RETURN . '?code=' . $code);
        }

        $token = User::find($user->id)->createToken($provider . '-sso', ['*'], now()->addHours(12))->plainTextToken;
        \App\Support\SignInAlert::check((int) $user->id, request(), 'social');

        // Hand the token to the SPA via the URL fragment (never sent to servers/logs).
        return redirect(self::FRONTEND . '/index.html#kt_social=' . urlencode($token));
    }

    private ?string $appCc = null;

    private function fail(string $msg)
    {
        // From the app, the message goes back to the app, not to a website in Chrome.
        if ($this->appCc) {
            return redirect()->away(self::APP_RETURN . '?error=' . rawurlencode($msg));
        }
        return redirect(self::FRONTEND . '/index.html?social_error=' . urlencode($msg));
    }

    /** POST /auth/social/app-exchange {code, verifier} → {token}. One use, two minutes. */
    public function appExchange(Request $request)
    {
        $data = $request->validate([
            'code'     => 'required|string|regex:/^[a-f0-9]{48}$/',
            'verifier' => 'required|string|regex:/^[A-Za-z0-9_-]{43,128}$/',
        ]);
        $row = \Illuminate\Support\Facades\Cache::pull('kt_social_app:' . hash('sha256', $data['code']));
        $cc = rtrim(strtr(base64_encode(hash('sha256', $data['verifier'], true)), '+/', '-_'), '=');
        if (! is_array($row) || ! hash_equals((string) $row['cc'], $cc)) {
            return response()->json(['message' => 'That sign-in link has expired. Please try again.'], 422);
        }
        $user = User::find((int) $row['user_id']);
        if (! $user || in_array($user->status ?? '', ['suspended', 'deactivated'], true)) {
            return response()->json(['message' => 'This account is not active. Please contact your administrator.'], 403);
        }
        $token = $user->createToken($row['provider'] . '-sso', ['*'], now()->addHours(12))->plainTextToken;
        \App\Support\SignInAlert::check((int) $user->id, $request, 'social');
        return response()->json(['token' => $token]);
    }

    /* ── Portal config (platform-admin only; routes are role:platform_admin) ──
       Reads/writes ONLY the OAuth keys; secrets are write-only (never returned).
       No other .env key is ever exposed or touched. */

    private const ENV_KEYS = [
        'GOOGLE_CLIENT_ID', 'GOOGLE_CLIENT_SECRET',
        'FACEBOOK_CLIENT_ID', 'FACEBOOK_CLIENT_SECRET',
        'MICROSOFT_CLIENT_ID', 'MICROSOFT_CLIENT_SECRET', 'MICROSOFT_TENANT',
    ];

    public function configGet(Request $request)
    {
        $base = rtrim((string) config('app.url'), '/');
        $out = [];
        foreach (self::PROVIDERS as $p) {
            // client_id is NOT secret (it's sent to the browser during OAuth) — safe to show.
            $out[$p] = [
                'client_id'  => (string) config("services.$p.client_id"),
                'has_secret' => (bool) config("services.$p.client_secret"),
                'configured' => $this->enabled($p),
                'redirect'   => $base . "/api/v1/auth/social/$p/callback",
            ];
            if ($p === 'microsoft') {
                $out[$p]['tenant'] = (string) (config('services.microsoft.tenant') ?: 'common');
            }
        }
        return response()->json(['providers' => $out]);
    }

    public function configSave(Request $request)
    {
        $data = $request->validate([
            'provider'      => 'required|in:google,microsoft,facebook',
            'client_id'     => 'nullable|string|max:255',
            'client_secret' => 'nullable|string|max:500',
            'tenant'        => 'nullable|string|max:120',
            'clear'         => 'nullable|boolean',
        ]);
        $p = $data['provider'];
        $prefix = strtoupper($p);
        $clean = fn ($v) => trim(preg_replace('/[\r\n\x00]+/', '', (string) $v));
        $kv = [];

        if ($request->boolean('clear')) {
            $kv["{$prefix}_CLIENT_ID"] = '';
            $kv["{$prefix}_CLIENT_SECRET"] = '';
        } else {
            if (array_key_exists('client_id', $data) && $data['client_id'] !== null) {
                $kv["{$prefix}_CLIENT_ID"] = $clean($data['client_id']);
            }
            // Only overwrite the secret when a new one is supplied (blank keeps the current one).
            if (! empty($data['client_secret'])) {
                $kv["{$prefix}_CLIENT_SECRET"] = $clean($data['client_secret']);
            }
            if ($p === 'microsoft' && ! empty($data['tenant'])) {
                $kv['MICROSOFT_TENANT'] = $clean($data['tenant']);
            }
        }

        if (! empty($kv)) {
            $this->writeEnv($kv);
        }
        Artisan::call('config:clear');

        return response()->json(['status' => 'saved']);
    }

    private function writeEnv(array $kv): void
    {
        $path = base_path('.env');
        if (! is_file($path) || ! is_writable($path)) {
            abort(500, 'Config file is not writable on the server.');
        }
        $content = file_get_contents($path);
        foreach ($kv as $key => $val) {
            if (! in_array($key, self::ENV_KEYS, true)) continue;   // whitelist — never touch anything else
            $line = $key . '=' . $val;
            $pattern = '/^' . preg_quote($key, '/') . '=.*$/m';
            if (preg_match($pattern, $content)) {
                $content = preg_replace($pattern, $line, $content, 1);
            } else {
                $content = rtrim($content, "\n") . "\n" . $line . "\n";
            }
        }
        file_put_contents($path, $content, LOCK_EX);
    }
}
