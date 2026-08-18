<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetEmail;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

final class AuthController extends Controller
{
    private const RESET_TOKEN_TTL_MINUTES = 60;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            // "email" is really the login identifier now — an email OR a username
            // (usernames let one person hold several accounts under one email).
            'email' => ['required', 'string', 'max:180'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'device_platform' => ['required', 'string', 'in:ios,android,web,unknown'],
            'code' => ['nullable', 'string', 'min:6', 'max:14'],
            'username' => ['nullable', 'string', 'max:50'],   // optional disambiguator
        ]);

        $login = trim((string) $data['email']);
        $uname = ! empty($data['username']) ? mb_strtolower(trim((string) $data['username'])) : '';
        $user = null;
        if ($uname !== '') {
            // v22p101 — STRICT email + username. A username disambiguator was
            // provided; resolve by username, and when an email was ALSO entered
            // (the "Email or username" field held a real email), require BOTH to
            // belong to the SAME account. This closes a bug where a mismatched /
            // browser-autofilled username on a shared email signed the user into
            // the wrong account. If the pair doesn't match one account → invalid.
            $q = User::whereRaw('LOWER(username) = ?', [$uname]);
            if (strpos($login, '@') !== false) {
                $q->whereRaw('LOWER(email) = ?', [mb_strtolower($login)]);
            }
            $user = $q->first();
        } elseif (strpos($login, '@') !== false) {
            // Looks like an email. One match → use it; shared by several → ask for
            // the username so we sign them into the right account.
            $matches = User::where('email', $login)->get();
            if ($matches->count() > 1) {
                return response()->json([
                    'needs_username' => true,
                    'message' => 'More than one account uses this email. Enter your username to sign in.',
                ], 200);
            }
            $user = $matches->first();
        } else {
            // No "@" → treat the identifier as a username.
            $user = User::whereRaw('LOWER(username) = ?', [mb_strtolower($login)])->first();
        }

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit($request, null, 'login_failed', null, null, "login: {$login}");
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => ['email' => ['Invalid credentials.']],
            ], 422);
        }

        if (in_array($user->status, ['suspended', 'inactive'], true)) {
            return response()->json(['message' => 'Account is not active.'], 403);
        }

        // Scheduled maintenance / downtime — nobody signs in except platform
        // admins (so they can manage or lift the window).
        if (\App\Http\Controllers\Api\MaintenanceController::isDown()) {
            $isPlatformAdmin = \Illuminate\Support\Facades\DB::table('role_assignments')
                ->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', true)->exists();
            if (! $isPlatformAdmin) {
                $w = \App\Http\Controllers\Api\MaintenanceController::window();
                return response()->json([
                    'message' => ($w && $w->message) ? $w->message : 'KiddieTrac is temporarily down for scheduled maintenance. Please try again shortly.',
                    'maintenance' => true,
                ], 503);
            }
        }

        // v22p7.1: TOTP gate — block token issue if MFA is enabled and the
        // provided code is missing or invalid. Accepts a 6-digit TOTP
        // or a one-time recovery code; consumes the recovery code if used.
        if ($user->two_factor_enabled && $user->two_factor_secret) {
            $code = $data['code'] ?? null;
            if (! $code) {
                return response()->json([
                    'mfa_required' => true,
                    'message' => 'Enter your 6-digit authenticator code to complete sign-in.',
                ], 200);
            }
            $secret = decrypt($user->two_factor_secret);
            $valid = \App\Support\Totp::verify($secret, $code);
            if (! $valid) {
                $raw = DB::table('users')->where('id', $user->id)->value('two_factor_recovery_codes');
                $hashes = $raw ? (json_decode($raw, true) ?: []) : [];
                foreach ($hashes as $i => $h) {
                    if (Hash::check($code, $h)) {
                        $valid = true;
                        unset($hashes[$i]);
                        DB::table('users')->where('id', $user->id)->update([
                            'two_factor_recovery_codes' => json_encode(array_values($hashes)),
                        ]);
                        break;
                    }
                }
            }
            if (! $valid) {
                $this->audit($request, $user->id, 'mfa_failed', 'user', $user->id);
                return response()->json([
                    'message' => 'Invalid authenticator code.',
                    'errors' => ['code' => ['Code did not match.']],
                ], 422);
            }
        }

        $tokenObj = $user->createToken(
            $data['device_name'],
            ['*'],
            now()->addDays(30)
        );

        // v22p39: stamp last login so the admin Users tab can surface
        // 'last seen' next to each user.
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'updated_at' => now(),
        ]);

        // Refresh the analytics device type from the ACTUAL device signing in.
        // It used to be captured once at onboarding and never updated, so a person
        // onboarded via desktop Chrome device-emulation (UA "Android 10; K") stayed
        // "Android" forever even on their real iPhone. device_platform is the native
        // signal (ios/android from the app); for the web we sniff the User-Agent.
        $this->refreshDeviceType($user, (string) ($data['device_platform'] ?? 'unknown'), (string) $request->userAgent());

        DB::table('device_tokens')->updateOrInsert(
            ['user_id' => $user->id, 'device_name' => $data['device_name']],
            [
                'platform' => $data['device_platform'],
                'token' => Str::random(80),
                'last_active_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->audit($request, $user->id, 'login', 'user', $user->id);

        return response()->json([
            'token' => $tokenObj->plainTextToken,
            'expires_at' => $tokenObj->accessToken->expires_at?->toIso8601String(),
            'user' => $this->formatUser($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->formatUser($request->user()));
    }

    /**
     * v22p20 — list every agency this user has agency_admin access to.
     * Used by the frontend to populate the agency switcher.
     */
    public function myAgencies(Request $request): JsonResponse
    {
        // v22p21: platform_admin sees all agencies; agency_admin sees only theirs.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        if ($isPlatformAdmin) {
            $rows = DB::table('agencies')
                ->whereNull('deleted_at')
                ->select('id', 'name', 'slug', 'logo_url')
                ->orderBy('name')
                ->get();
            return response()->json(['agencies' => $rows, 'is_platform_admin' => true]);
        }
        $rows = DB::table('role_assignments')
            ->join('agencies', 'agencies.id', '=', 'role_assignments.agency_id')
            ->where('role_assignments.user_id', $request->user()->id)
            ->where('role_assignments.role', 'agency_admin')
            ->where('role_assignments.active', true)
            ->whereNull('agencies.deleted_at')
            ->select('agencies.id', 'agencies.name', 'agencies.slug', 'agencies.logo_url')
            ->distinct()
            ->orderBy('agencies.name')
            ->get();
        return response()->json(['agencies' => $rows]);
    }

    /**
     * v22p20 — validate that the caller has access to the target agency.
     * Frontend stores it in sessionStorage and sends as X-Active-Agency-Id.
     */
    public function setActiveAgency(Request $request): JsonResponse
    {
        $data = $request->validate(['agency_id' => ['required', 'integer']]);
        // v22p21: platform_admin can switch to ANY active agency.
        $isPlatformAdmin = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', true)
            ->exists();
        $ok = $isPlatformAdmin
            ? DB::table('agencies')->where('id', $data['agency_id'])->whereNull('deleted_at')->exists()
            : DB::table('role_assignments')
                ->where('user_id', $request->user()->id)
                ->where('role', 'agency_admin')
                ->where('agency_id', $data['agency_id'])
                ->where('active', true)
                ->exists();
        if (! $ok) {
            return response()->json(['message' => 'You do not have access to that agency.'], 403);
        }
        $agency = DB::table('agencies')->where('id', $data['agency_id'])->first(['id', 'name', 'slug']);
        return response()->json(['active' => $agency]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        return response()->json(['message' => 'Logged out']);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'first_name' => ['string', 'max:80'],
            'last_name' => ['string', 'max:80'],
            'preferred_name' => ['nullable', 'string', 'max:80'],
            'phone' => ['required', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:60'],
            // Optional username — settable/changeable here after the fact.
            'username' => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],
        ]);

        if (array_key_exists('username', $data) && $data['username'] !== null && $data['username'] !== '') {
            $taken = DB::table('users')
                ->whereRaw('LOWER(username) = ?', [mb_strtolower(trim($data['username']))])
                ->where('id', '!=', $request->user()->id)
                ->whereNull('deleted_at')->exists();
            if ($taken) {
                return response()->json([
                    'message' => 'That username is already taken.',
                    'errors' => ['username' => ['That username is already taken — try another.']],
                ], 422);
            }
            $data['username'] = trim($data['username']);
        } else {
            // Empty/absent → don't overwrite an existing username on a partial save.
            unset($data['username']);
        }

        DB::table('users')
            ->where('id', $request->user()->id)
            ->update([...$data, 'updated_at' => now()]);

        return response()->json($this->formatUser(User::find($request->user()->id)));
    }

    public function changePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', \App\Services\PasswordPolicy::rule()],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password incorrect.',
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        \App\Services\PasswordPolicy::assertNotRecentlyUsed($user->id, $data['new_password'], 'new_password');
        $newHash = Hash::make($data['new_password']);
        DB::table('users')->where('id', $user->id)->update([
            'password' => $newHash,
            'updated_at' => now(),
        ]);
        \App\Services\PasswordPolicy::record($user->id, $newHash);

        $this->audit($request, $user->id, 'password_changed', 'user', $user->id);

        return response()->json(['message' => 'Password updated']);
    }

    /**
     * POST /api/v1/auth/forgot
     * Generates a reset token and emails it.
     * Always returns success (prevents email enumeration).
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        $user = DB::table('users')->where('email', $data['email'])->first();

        if ($user) {
            // Invalidate any previous tokens for this email
            DB::table('password_resets')
                ->where('email', $data['email'])
                ->whereNull('used_at')
                ->update(['used_at' => now()]);

            $token = Str::random(64);
            DB::table('password_resets')->insert([
                'email' => $data['email'],
                'token' => hash('sha256', $token), // Store hash, not plaintext
                'expires_at' => now()->addMinutes(self::RESET_TOKEN_TTL_MINUTES),
                'requester_ip' => $request->ip(),
                'created_at' => now(),
            ]);

            // Build reset URL — the reset page is served by the SPA/portal at
            // app.kiddietrac.com. The old link used config('app.url') = the API
            // host (api.kiddietrac.com) → 404; config('app.frontend_url') is the
            // unset dev default (localhost:3000). Use the production portal URL
            // directly, matching the rest of the codebase. (v22p97)
            $portalUrl = 'https://app.kiddietrac.com';
            $resetUrl = $portalUrl.'/reset-password.html?token='.$token.'&email='.urlencode($data['email']);

            try {
                Mail::to($data['email'])->send(new PasswordResetEmail(
                    recipientName: $user->first_name ?? 'there',
                    resetUrl: $resetUrl,
                    expiresInMinutes: (string) self::RESET_TOKEN_TTL_MINUTES,
                ));
                $this->audit($request, $user->id, 'password_reset_requested', 'user', $user->id);
            } catch (Throwable $e) {
                Log::error('Password reset email failed', ['error' => $e->getMessage(), 'email' => $data['email']]);
                // Still return success to user — don't leak whether the email exists
            }
        }

        return response()->json([
            'message' => 'If that email exists in our system, you\'ll receive a reset link shortly.',
        ]);
    }

    /**
     * POST /api/v1/auth/reset
     * Consumes a token and sets a new password.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string'],
            'new_password' => ['required', 'string', \App\Services\PasswordPolicy::rule()],
        ]);

        $hashedToken = hash('sha256', $data['token']);
        $record = DB::table('password_resets')
            ->where('email', $data['email'])
            ->where('token', $hashedToken)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $record) {
            return response()->json([
                'message' => 'Invalid or expired reset link. Request a new one.',
            ], 422);
        }

        $user = ($record->user_id ?? null)
            ? DB::table('users')->where('id', $record->user_id)->first()
            : DB::table('users')->where('email', $data['email'])->first();
        if (! $user) {
            return response()->json(['message' => 'Account not found.'], 422);
        }

        \App\Services\PasswordPolicy::assertNotRecentlyUsed($user->id, $data['new_password'], 'new_password');
        $newHash = Hash::make($data['new_password']);

        DB::transaction(function () use ($user, $data, $newHash, $record): void {
            DB::table('users')->where('id', $user->id)->update([
                'password' => $newHash,
                'status' => 'active',
                'updated_at' => now(),
            ]);
            \App\Services\PasswordPolicy::record($user->id, $newHash);

            DB::table('password_resets')->where('id', $record->id)->update([
                'used_at' => now(),
            ]);

            // Invalidate all existing tokens — force re-login on all devices
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->where('tokenable_type', \App\Models\User::class)
                ->delete();
        });

        $this->audit($request, $user->id, 'password_reset_completed', 'user', $user->id);

        return response()->json(['message' => 'Password updated. Please log in with your new password.']);
    }

    /**
     * POST /api/v1/auth/set-password
     * v22p93 — token-only set-password for invite links (no email required).
     * Identifies the user from the token alone, sets the password, and returns
     * an auth token so the user is signed in immediately (no second login).
     */
    public function setPasswordFromToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => ['required', 'string'],
            'password' => ['required', 'string', \App\Services\PasswordPolicy::rule()],
        ]);

        // Accept either a plaintext token or its sha256 (invites store the hash).
        $hashed = hash('sha256', $data['token']);
        $record = DB::table('password_resets')
            ->where(function ($q) use ($data, $hashed) {
                $q->where('token', $data['token'])->orWhere('token', $hashed);
            })
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $record) {
            return response()->json(['message' => 'This link is invalid or has expired. Ask for a new invite.'], 422);
        }

        // Prefer the exact account the token was minted for (emails can be shared
        // across accounts now); fall back to email for older tokens.
        $user = ($record->user_id ?? null)
            ? User::find((int) $record->user_id)
            : User::where('email', $record->email)->first();
        if (! $user) {
            return response()->json(['message' => 'Account not found for this link.'], 422);
        }

        \App\Services\PasswordPolicy::assertNotRecentlyUsed($user->id, $data['password']);
        $newHash = Hash::make($data['password']);

        DB::transaction(function () use ($user, $newHash, $record): void {
            DB::table('users')->where('id', $user->id)->update([
                'password'   => $newHash,
                'status'     => 'active',
                'updated_at' => now(),
            ]);
            DB::table('password_resets')->where('id', $record->id)->update(['used_at' => now()]);
            // Invalidate any other outstanding invite/reset tokens for THIS account
            // (by user id when we have it, so a shared email's other accounts keep theirs).
            DB::table('password_resets')
                ->when($record->user_id ?? null, fn ($q) => $q->where('user_id', $record->user_id), fn ($q) => $q->where('email', $record->email))
                ->whereNull('used_at')->update(['used_at' => now()]);
        });
        \App\Services\PasswordPolicy::record($user->id, $newHash);

        $tokenObj = $user->createToken('set-password', ['*'], now()->addDays(30));
        DB::table('users')->where('id', $user->id)->update([
            'last_login_at' => now(), 'last_login_ip' => $request->ip(), 'updated_at' => now(),
        ]);
        $this->audit($request, $user->id, 'password_set_via_invite', 'user', $user->id);

        return response()->json([
            'token'      => $tokenObj->plainTextToken,
            'expires_at' => $tokenObj->accessToken->expires_at?->toIso8601String(),
            'user'       => $this->formatUser($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        return response()->json([
            'message' => 'Self-registration is not enabled. Your childcare centre will invite you.',
        ], 403);
    }

    /**
     * Update users.profile_extras.device_type / device_detail from the device that
     * is actually signing in. Mirrors the client detectDevice(): native platform
     * (ios/android) wins; otherwise sniff the User-Agent. Keeps the "which device
     * families use" analytics current instead of frozen at onboarding.
     */
    private function refreshDeviceType(User $user, string $platform, string $ua): void
    {
        $platform = strtolower(trim($platform));
        $isIpad = (bool) preg_match('/iPad/i', $ua)
            || (preg_match('/Macintosh/i', $ua) && (int) ($_SERVER['HTTP_SEC_CH_UA_MOBILE'] ?? 0));
        if ($platform === 'ios') {
            $type = $isIpad ? 'Apple iPad' : 'Apple iPhone';
            $native = 'KiddieTrac iOS app · ';
        } elseif ($platform === 'android') {
            $type = 'Android';
            $native = 'KiddieTrac Android app · ';
        } else { // web / unknown → sniff the UA
            $native = '';
            if (preg_match('/iPhone/i', $ua))            $type = 'Apple iPhone';
            elseif (preg_match('/iPad/i', $ua))          $type = 'Apple iPad';
            elseif (preg_match('/Android/i', $ua))       $type = 'Android';
            elseif (preg_match('/Windows|Macintosh|Linux|CrOS/i', $ua)) $type = 'Desktop / laptop';
            else                                          $type = 'Other';
        }

        try {
            $extras = is_string($user->profile_extras ?? null)
                ? (json_decode($user->profile_extras, true) ?: [])
                : (is_array($user->profile_extras ?? null) ? $user->profile_extras : []);
            $extras['device_type'] = $type;
            $extras['device_detail'] = mb_substr($native . $ua, 0, 160);
            DB::table('users')->where('id', $user->id)->update(['profile_extras' => json_encode($extras)]);
        } catch (\Throwable $e) { /* analytics only — never block login */ }
    }

    private function formatUser(User $user): array
    {
        $assignments = DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->get();

        $roles = $assignments->pluck('role')->unique()->values()->all();
        $centreId = $assignments->whereNotNull('centre_id')->first()?->centre_id;
        $agencyId = $assignments->whereNotNull('agency_id')->first()?->agency_id;

        $centre = $centreId
            ? DB::table('centres')->where('id', $centreId)->select('id', 'name')->first()
            : null;

        // Provider identity: in this system a home-daycare "provider" IS a centre,
        // linked to the person by a matching email. Surface a flag + the current
        // bio so the onboarding wizard can require a full bio from providers.
        $providerCentre = $user->email
            ? DB::table('centres')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])
                ->whereNull('deleted_at')
                ->select('id', 'provider_bio')
                ->first()
            : null;

        // The agency's timezone. Every time shown in the app should be the
        // agency's local time, not the device's — a parent travelling, or a
        // phone left on another zone, must not see a different day.
        // Guardians hold no agency role, so reach it through their family's centre.
        $tzAgencyId = $agencyId ?: DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $user->id)
            ->value('c.agency_id');
        $agencyTz = $tzAgencyId
            ? DB::table('agencies')->where('id', $tzAgencyId)->value('timezone')
            : null;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'username' => $user->username ?? null,
            'agency_timezone' => $agencyTz ?: 'America/Toronto',
            // Which agency a platform admin lands in by default. Without this the
            // switcher fell back to agencies[0] — alphabetically the LIVE agency —
            // so a super admin opened the app inside real families' data.
            // DEFAULT_ADMIN_AGENCY_ID in .env; empty = first agency, as before.
            'default_agency_id' => env('DEFAULT_ADMIN_AGENCY_ID') ? (int) env('DEFAULT_ADMIN_AGENCY_ID') : null,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'sex' => $user->sex ?? null,
            'name' => trim(($user->first_name ?? '').' '.($user->last_name ?? '')),
            'preferred_name' => $user->preferred_name ?? null,
            'phone' => $user->phone ?? null,
            'locale' => $user->locale ?? 'en-CA',
            'timezone' => $user->timezone ?? 'America/Toronto',
            'photo_url' => $user->photo_url ?? null,
            'status' => $user->status ?? 'active',
            'roles' => $roles,
            'primary_role' => $this->pickPrimaryRole($roles),
            'centre_id' => $centreId,
            'agency_id' => $agencyId,
            'centre' => $centre,
            // v22p3.5: onboarding state surfaced so the frontend wizard knows
            // whether to auto-trigger on this user's next dashboard load.
            'onboarded_at' => $user->onboarded_at ?? null,
            // Provider (home-daycare) identity — drives the mandatory bio step.
            'is_provider' => (bool) $providerCentre,
            'provider_centre_id' => $providerCentre->id ?? null,
            'provider_bio' => $providerCentre->provider_bio ?? null,
            'profile_extras' => is_string($user->profile_extras ?? null)
                ? json_decode($user->profile_extras, true)
                : ($user->profile_extras ?? null),
        ];
    }

    /**
     * PATCH /auth/me/provider-bio
     * Lets a home-daycare provider write/edit their own bio after onboarding
     * (onboarding only fires once, so already-onboarded providers need this).
     * Saves to their matched centre (centres.provider_bio).
     */
    public function updateProviderBio(Request $request): JsonResponse
    {
        $data = $request->validate(['provider_bio' => ['required', 'string', 'max:4000']]);
        $user = $request->user();
        $centre = $user->email
            ? DB::table('centres')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])
                ->whereNull('deleted_at')
                ->first(['id'])
            : null;
        if (! $centre) {
            return response()->json(['message' => 'Only providers can set a provider bio.'], 403);
        }
        $bio = trim($data['provider_bio']);
        if (mb_strlen($bio) < 40) {
            return response()->json([
                'message' => 'Please write at least a sentence or two (40+ characters) so families get to know you.',
                'errors' => ['provider_bio' => ['Your bio is a little short.']],
            ], 422);
        }
        DB::table('centres')->where('id', $centre->id)->update(['provider_bio' => $bio, 'updated_at' => now()]);

        return response()->json(['ok' => true, 'provider_bio' => $bio]);
    }

    /**
     * PATCH /auth/me/onboarding
     * Single-shot wizard submission: profile + address + role-specific extras.
     * Marks the user as onboarded so the wizard does not re-trigger.
     * v22p3.5.
     */
    public function updateOnboarding(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Standard profile fields (also editable via /auth/me)
            'first_name'      => ['nullable', 'string', 'max:80'],
            'last_name'       => ['nullable', 'string', 'max:80'],
            'sex'             => ['nullable', 'string', 'max:16'],
            'preferred_name'  => ['nullable', 'string', 'max:80'],
            'phone'           => ['nullable', 'string', 'max:40'],
            'direct_phone'    => ['nullable', 'string', 'max:40'],
            'home_phone'      => ['nullable', 'string', 'max:40'],
            'locale'          => ['nullable', 'string', 'max:10'],
            'timezone'        => ['nullable', 'string', 'max:60'],

            // Address (lands in profile_extras to avoid altering users table)
            'address_line1'   => ['nullable', 'string', 'max:200'],
            'address_line2'   => ['nullable', 'string', 'max:200'],
            'city'            => ['nullable', 'string', 'max:80'],
            'province'        => ['nullable', 'string', 'max:40'],
            'postal_code'     => ['nullable', 'string', 'max:12'],

            // Demographics + device (analytics; stored in profile_extras). Race /
            // ethnicity is OPTIONAL and self-reported. Device is auto-detected by the
            // client (Android / iOS / iPadOS / Desktop) with a UA detail string.
            'ethnicity'       => ['nullable', 'string', 'max:80'],
            'device_type'     => ['nullable', 'string', 'max:40'],
            'device_detail'   => ['nullable', 'string', 'max:160'],

            // Common emergency contact
            'emergency_contact_name'     => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone'    => ['nullable', 'string', 'max:40'],
            'emergency_contact_email'    => ['nullable', 'email', 'max:160'],
            'emergency_contact_relation' => ['nullable', 'string', 'max:80'],

            // Role-specific extras — captured as a generic object so we don't
            // grow the users table for every new field a role needs.
            'role_extras'     => ['nullable', 'array'],

            // Provider bio — home-daycare providers describe themselves for parents.
            // Saved to their centre (centres.provider_bio), required for providers.
            'provider_bio'    => ['nullable', 'string', 'max:4000'],

            // Additional emergency contacts (optional): [{name, phone, email, relation}, ...]
            'extra_contacts'            => ['nullable', 'array'],
            'extra_contacts.*.name'     => ['nullable', 'string', 'max:120'],
            'extra_contacts.*.phone'    => ['nullable', 'string', 'max:40'],
            'extra_contacts.*.email'    => ['nullable', 'email', 'max:160'],
            'extra_contacts.*.relation' => ['nullable', 'string', 'max:80'],

            // Optional username — lets one person hold several accounts under one
            // email and sign in to the right one. Letters/numbers/._- only.
            'username'        => ['nullable', 'string', 'min:3', 'max:50', 'regex:/^[A-Za-z0-9._-]+$/'],

            // Mark as done (default true)
            'complete'        => ['nullable', 'boolean'],
        ]);

        $user = $request->user();

        // A person cannot be their OWN emergency contact — that defeats the point.
        // The email is the reliable identity signal; the client also checks name/phone.
        if (! empty($data['emergency_contact_email'])
            && mb_strtolower(trim($data['emergency_contact_email'])) === mb_strtolower(trim((string) $user->email))) {
            return response()->json([
                'message' => 'Your emergency contact can’t be yourself — please give someone else who can be reached in an emergency.',
                'errors' => ['emergency_contact_email' => ['Enter a different person’s email.']],
            ], 422);
        }

        // Username uniqueness (case-insensitive), ignoring this user's own row.
        if (! empty($data['username'])) {
            $taken = DB::table('users')
                ->whereRaw('LOWER(username) = ?', [mb_strtolower(trim($data['username']))])
                ->where('id', '!=', $user->id)
                ->whereNull('deleted_at')
                ->exists();
            if ($taken) {
                return response()->json([
                    'message' => 'That username is already taken.',
                    'errors' => ['username' => ['That username is already taken — try another.']],
                ], 422);
            }
        }
        $existingExtras = is_string($user->profile_extras ?? null)
            ? (json_decode($user->profile_extras, true) ?: [])
            : (is_array($user->profile_extras ?? null) ? $user->profile_extras : []);

        $extras = array_replace($existingExtras, array_filter([
            'direct_phone'            => $data['direct_phone']    ?? null,
            'home_phone'              => $data['home_phone']      ?? null,
            'address_line1'           => $data['address_line1']   ?? null,
            'address_line2'           => $data['address_line2']   ?? null,
            'city'                    => $data['city']            ?? null,
            'province'                => $data['province']        ?? null,
            'postal_code'             => $data['postal_code']     ?? null,
            'ethnicity'               => $data['ethnicity']       ?? null,
            'device_type'             => $data['device_type']     ?? null,
            'device_detail'           => $data['device_detail']   ?? null,
            'emergency_contact_name'     => $data['emergency_contact_name']     ?? null,
            'emergency_contact_phone'    => $data['emergency_contact_phone']    ?? null,
            'emergency_contact_email'    => $data['emergency_contact_email']    ?? null,
            'emergency_contact_relation' => $data['emergency_contact_relation'] ?? null,
            'extra_contacts'          => !empty($data['extra_contacts'])
                ? array_values(array_filter($data['extra_contacts'], fn ($c) => !empty($c['name']) || !empty($c['phone']) || !empty($c['email'])))
                : null,
            'role_extras'             => $data['role_extras']     ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $userUpdate = array_filter([
            'first_name'     => $data['first_name']     ?? null,
            'last_name'      => $data['last_name']      ?? null,
            'sex'            => $data['sex']            ?? null,
            'preferred_name' => $data['preferred_name'] ?? null,
            'phone'          => $data['phone']          ?? null,
            'locale'         => $data['locale']         ?? null,
            'timezone'       => $data['timezone']       ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        if (! empty($data['username'])) {
            $userUpdate['username'] = trim($data['username']);
        }
        $userUpdate['profile_extras'] = json_encode($extras);
        $userUpdate['updated_at']     = now();
        $wasOnboarded = ! empty($user->onboarded_at);
        $isCompleting = ($data['complete'] ?? true) === true;
        if ($isCompleting) {
            $userUpdate['onboarded_at'] = now();
        }

        // Provider bio — a home-daycare provider (a centre matched by email) must
        // write a full bio during onboarding so families know who will care for
        // their child. Required when completing; saved to their centre. Checked
        // BEFORE the user update so a missing bio doesn't mark them onboarded.
        $providerCentre = $user->email
            ? DB::table('centres')
                ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])
                ->whereNull('deleted_at')
                ->first(['id', 'provider_bio'])
            : null;
        if ($providerCentre) {
            $bio = trim((string) ($data['provider_bio'] ?? ''));
            if ($isCompleting && mb_strlen($bio) < 40) {
                return response()->json([
                    'message' => 'Please write a short bio (at least a sentence or two) so families know who will be caring for their child.',
                    'errors' => ['provider_bio' => ['Your provider bio is required — tell families a little about yourself.']],
                ], 422);
            }
            if ($bio !== '') {
                DB::table('centres')->where('id', $providerCentre->id)->update([
                    'provider_bio' => $bio, 'updated_at' => now(),
                ]);
            }
        }

        DB::table('users')->where('id', $user->id)->update($userUpdate);

        // Onboarding-success confirmation — sent ONCE, the first time onboarding
        // completes. Uses the branded layout (logo header + privacy/terms footer);
        // AccountNotice carries X-KT-Invite so it reaches a just-onboarded user
        // (agency master suppression still applies). Never block finishing on it.
        if ($isCompleting && ! $wasOnboarded) {
            try {
                $name   = trim((($data['first_name'] ?? $user->first_name) ?? '') . ' ' . (($data['last_name'] ?? $user->last_name) ?? ''));
                $portal = config('app.url', 'https://app.kiddietrac.com');
                $body   = "You're all set — your Kiddietrac account is ready to use.\n\n"
                        . "Sign in anytime to access your portal, stay up to date, and manage your details. "
                        . "We've saved your profile and preferences, so you can jump right in.\n\n"
                        . "Need a hand? Reply to your administrator or contact info@kiddietrac.com. "
                        . "Our Privacy Policy and Terms of Use are linked at the bottom of this email.";
                $ctaLabel = 'Go to your portal';

                // Use the agency-editable "onboarding-welcome" template when set (#77).
                // Resolve the user's agency (staff → role_assignments, parent → family).
                $onbAgencyId = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->whereNotNull('agency_id')->value('agency_id')
                    ?: DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $user->id)->where('ra.active', true)->value('c.agency_id')
                    ?: DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')->where('g.user_id', $user->id)->value('c.agency_id');
                if ($onbAgencyId) {
                    $agencyName = (string) (DB::table('agencies')->where('id', $onbAgencyId)->value('name') ?? 'Kiddietrac');
                    $md = ['name' => $name ?: 'there', 'agency_name' => $agencyName, 'portal_url' => $portal];
                    $flat = fn ($s) => trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>', '</div>'], "\n", (string) $s)));
                    $hd = $flat(\App\Support\EmailTemplates::block((int) $onbAgencyId, 'onboarding-welcome', 'heading', $md));
                    $bd = $flat(\App\Support\EmailTemplates::block((int) $onbAgencyId, 'onboarding-welcome', 'body', $md));
                    $cl = $flat(\App\Support\EmailTemplates::block((int) $onbAgencyId, 'onboarding-welcome', 'cta_label', $md));
                    if ($bd !== '') $body = ($hd !== '' ? $hd . "\n\n" : '') . $bd;
                    if ($cl !== '') $ctaLabel = $cl;
                }
                \Illuminate\Support\Facades\Mail::to($user->email, $name ?: null)->queue(
                    (new \App\Mail\AccountNotice(
                        recipientName: $name ?: 'there',
                        subjectLine:   'Welcome to Kiddietrac — your account is ready',
                        bodyText:      $body,
                        ctaLabel:      $ctaLabel,
                        ctaUrl:        $portal,
                    ))->onQueue('mail')
                );
            } catch (\Throwable $e) {
                // email must never block onboarding completion
            }
        }

        return response()->json($this->formatUser(User::find($user->id)));
    }

    private function pickPrimaryRole(array $roles): ?string
    {
        // v22p39: platform_admin gets the agency_admin shell so users who
        // hold only the platform role still render a nav and a dashboard.
        // Before this fix, Safia Ali (platform_admin only) hit
        // primary_role=null on login and the shell hung on 'Loading your
        // workspace…'. Mapping to agency_admin works because the v17 nav
        // already includes the platform-overview / all-agencies links
        // via agency-switcher injection.
        return match (true) {
            in_array('agency_admin', $roles, true) => 'agency_admin',
            in_array('platform_admin', $roles, true) => 'agency_admin',
            in_array('centre_director', $roles, true) => 'centre_director',
            in_array('educator', $roles, true) => 'educator',
            in_array('guardian', $roles, true) => 'guardian',
            in_array('home_visitor', $roles, true) => 'home_visitor',
            in_array('sales_rep', $roles, true) => 'sales_rep',
            in_array('auditor', $roles, true) => 'auditor',
            default => $roles[0] ?? null,
        };
    }

    private function audit(Request $request, ?int $userId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void
    {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $userId,
                // Stamp the acting agency so login/mfa events are agency-scoped in
                // the per-agency audit log + activity feed (no cross-tenant bleed).
                'agency_id' => $userId ? \App\Support\AuditScope::resolve((int) $userId, $request) : null,
                'entity_type' => 'centre', 'entity_id' => null,
                'action' => $action,
                'entity_type' => $targetType,
                'entity_id' => $targetId,
                // audit_logs.payload has a json_valid() CHECK — a bare string
                // (e.g. "email: x") violated it, so login_failed / mfa_failed were
                // silently dropped. Encode to valid JSON so they're actually logged.
                'payload' => $details === null ? null : json_encode($details),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Don't fail the request just because audit failed
            Log::warning('Audit log failed', ['error' => $e->getMessage()]);
        }
    }
}
