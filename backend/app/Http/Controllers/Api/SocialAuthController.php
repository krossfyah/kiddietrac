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
            return Socialite::driver($provider)->stateless()->redirect();
        } catch (\Throwable $e) {
            return $this->fail('Could not start ' . ucfirst($provider) . ' sign-in.');
        }
    }

    public function callback(string $provider)
    {
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

        $user = DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->whereNull('deleted_at')->first();
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
        DB::table('users')->where('id', $user->id)->update(['last_login_at' => now()]);

        $token = User::find($user->id)->createToken($provider . '-sso', ['*'], now()->addHours(12))->plainTextToken;

        // Hand the token to the SPA via the URL fragment (never sent to servers/logs).
        return redirect(self::FRONTEND . '/index.html#kt_social=' . urlencode($token));
    }

    private function fail(string $msg)
    {
        return redirect(self::FRONTEND . '/index.html?social_error=' . urlencode($msg));
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
