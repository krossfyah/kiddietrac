<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * v22p63 — SSO via Laravel Socialite.
 *  - GET  /sso/{provider}             returns redirect URL
 *  - GET  /sso/{provider}/callback    handles OAuth response, mints token
 *  - GET  /sso/providers              lists configured providers
 *
 * Providers: google, apple, microsoft. Inert until env keys are set.
 */
final class SsoController extends Controller
{
    private array $supported = ['google', 'apple', 'microsoft'];

    public function providers(): JsonResponse
    {
        $configured = [];
        if (env('GOOGLE_CLIENT_ID')) $configured[] = 'google';
        if (env('APPLE_CLIENT_ID') && env('APPLE_CLIENT_SECRET')) $configured[] = 'apple';
        if (env('MICROSOFT_CLIENT_ID')) $configured[] = 'microsoft';
        return response()->json(['providers' => $configured]);
    }

    public function redirect(string $provider): mixed
    {
        if (!in_array($provider, $this->supported, true)) abort(404);
        $envKey = strtoupper($provider) . '_CLIENT_ID';
        abort_unless(env($envKey), 503, "{$provider} SSO not configured");
        return Socialite::driver($provider)
            ->stateless()
            ->scopes($provider === 'apple' ? ['email', 'name'] : ['email', 'profile'])
            ->redirect();
    }

    public function callback(Request $request, string $provider): mixed
    {
        if (!in_array($provider, $this->supported, true)) abort(404);
        abort_unless(env(strtoupper($provider) . '_CLIENT_ID'), 503);

        try {
            $u = Socialite::driver($provider)->stateless()->user();
        } catch (\Throwable $e) {
            return $this->htmlError('SSO failed: ' . $e->getMessage());
        }

        $email = $u->getEmail();
        $providerUserId = (string) $u->getId();
        if (!$email && !$providerUserId) {
            return $this->htmlError('SSO did not return an email or stable ID. Please try email + password.');
        }

        // 1. Try existing identity link
        $identity = DB::table('sso_identities')
            ->where('provider', $provider)
            ->where('provider_user_id', $providerUserId)
            ->first();
        $user = null;
        if ($identity) {
            $user = DB::table('users')->where('id', $identity->user_id)->whereNull('deleted_at')->first();
            DB::table('sso_identities')->where('id', $identity->id)->update(['last_login_at' => now()]);
        }

        // 2. Try email match to existing account, then link
        if (!$user && $email) {
            $user = DB::table('users')->where('email', $email)->whereNull('deleted_at')->first();
            if ($user) {
                DB::table('sso_identities')->insertOrIgnore([
                    'user_id' => $user->id,
                    'provider' => $provider,
                    'provider_user_id' => $providerUserId,
                    'provider_email' => $email,
                    'raw_data' => json_encode(['name' => $u->getName(), 'avatar' => $u->getAvatar()]),
                    'linked_at' => now(),
                    'last_login_at' => now(),
                ]);
            }
        }

        // 3. Auto-create user if email is verifiable
        if (!$user && $email) {
            $nameBits = explode(' ', trim((string) $u->getName()), 2);
            $uid = DB::table('users')->insertGetId([
                'email' => $email,
                'first_name' => $nameBits[0] ?? 'User',
                'last_name' => $nameBits[1] ?? '',
                'password' => Hash::make(Str::random(40)),
                'status' => 'active',
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('sso_identities')->insertOrIgnore([
                'user_id' => $uid,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'provider_email' => $email,
                'raw_data' => json_encode(['name' => $u->getName()]),
                'linked_at' => now(),
                'last_login_at' => now(),
            ]);
            $user = DB::table('users')->where('id', $uid)->first();
        }

        if (!$user) return $this->htmlError('No account exists for ' . htmlspecialchars((string) $email) . '. Ask your centre to invite you first.');

        // Mint a Sanctum token + redirect back to the app
        $userModel = \App\Models\User::find($user->id);
        $token = $userModel->createToken('sso-' . $provider)->plainTextToken;
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);
        $appUrl = 'https://app.kiddietrac.com/dashboard.html#sso-callback?token=' . urlencode($token);
        return redirect($appUrl);
    }

    private function htmlError(string $msg): \Illuminate\Http\Response
    {
        return response(
            '<!doctype html><html><head><meta charset="utf-8"><title>Login error</title></head>'
            . '<body style="font-family:-apple-system,sans-serif;padding:60px;text-align:center;background:#F9FAFB;">'
            . '<h2 style="color:#B91C1C;">Sign-in failed</h2>'
            . '<p style="color:#475569;">' . $msg . '</p>'
            . '<p><a href="https://app.kiddietrac.com" style="color:#1F6080;">Back to login</a></p>'
            . '</body></html>',
            200,
            ['Content-Type' => 'text/html']
        );
    }
}
