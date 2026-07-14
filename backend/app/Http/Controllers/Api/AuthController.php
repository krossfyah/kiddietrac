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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:120'],
            'device_platform' => ['required', 'string', 'in:ios,android,web,unknown'],
            'code' => ['nullable', 'string', 'min:6', 'max:14'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $this->audit($request, null, 'login_failed', null, null, "email: {$data['email']}");
            return response()->json([
                'message' => 'Invalid credentials.',
                'errors' => ['email' => ['Invalid credentials.']],
            ], 422);
        }

        if (in_array($user->status, ['suspended', 'inactive'], true)) {
            return response()->json(['message' => 'Account is not active.'], 403);
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
            'phone' => ['nullable', 'string', 'max:40'],
            'date_of_birth' => ['nullable', 'date'],
            'locale' => ['nullable', 'string', 'max:10'],
            'timezone' => ['nullable', 'string', 'max:60'],
        ]);

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

        $user = DB::table('users')->where('email', $data['email'])->first();
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

        $user = User::where('email', $record->email)->first();
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
            // Invalidate any other outstanding invite/reset tokens for this email.
            DB::table('password_resets')->where('email', $record->email)->whereNull('used_at')->update(['used_at' => now()]);
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
            'agency_timezone' => $agencyTz ?: 'America/Toronto',
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
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
            'profile_extras' => is_string($user->profile_extras ?? null)
                ? json_decode($user->profile_extras, true)
                : ($user->profile_extras ?? null),
        ];
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
            'preferred_name'  => ['nullable', 'string', 'max:80'],
            'phone'           => ['nullable', 'string', 'max:40'],
            'locale'          => ['nullable', 'string', 'max:10'],
            'timezone'        => ['nullable', 'string', 'max:60'],

            // Address (lands in profile_extras to avoid altering users table)
            'address_line1'   => ['nullable', 'string', 'max:200'],
            'address_line2'   => ['nullable', 'string', 'max:200'],
            'city'            => ['nullable', 'string', 'max:80'],
            'province'        => ['nullable', 'string', 'max:40'],
            'postal_code'     => ['nullable', 'string', 'max:12'],

            // Common emergency contact
            'emergency_contact_name'  => ['nullable', 'string', 'max:120'],
            'emergency_contact_phone' => ['nullable', 'string', 'max:40'],

            // Role-specific extras — captured as a generic object so we don't
            // grow the users table for every new field a role needs.
            'role_extras'     => ['nullable', 'array'],

            // Mark as done (default true)
            'complete'        => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $existingExtras = is_string($user->profile_extras ?? null)
            ? (json_decode($user->profile_extras, true) ?: [])
            : (is_array($user->profile_extras ?? null) ? $user->profile_extras : []);

        $extras = array_replace($existingExtras, array_filter([
            'address_line1'           => $data['address_line1']   ?? null,
            'address_line2'           => $data['address_line2']   ?? null,
            'city'                    => $data['city']            ?? null,
            'province'                => $data['province']        ?? null,
            'postal_code'             => $data['postal_code']     ?? null,
            'emergency_contact_name'  => $data['emergency_contact_name']  ?? null,
            'emergency_contact_phone' => $data['emergency_contact_phone'] ?? null,
            'role_extras'             => $data['role_extras']     ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        $userUpdate = array_filter([
            'first_name'     => $data['first_name']     ?? null,
            'last_name'      => $data['last_name']      ?? null,
            'preferred_name' => $data['preferred_name'] ?? null,
            'phone'          => $data['phone']          ?? null,
            'locale'         => $data['locale']         ?? null,
            'timezone'       => $data['timezone']       ?? null,
        ], fn ($v) => $v !== null && $v !== '');

        $userUpdate['profile_extras'] = json_encode($extras);
        $userUpdate['updated_at']     = now();
        if (($data['complete'] ?? true) === true) {
            $userUpdate['onboarded_at'] = now();
        }

        DB::table('users')->where('id', $user->id)->update($userUpdate);

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
            in_array('auditor', $roles, true) => 'auditor',
            default => $roles[0] ?? null,
        };
    }

    private function audit(Request $request, ?int $userId, string $action, ?string $targetType = null, ?int $targetId = null, ?string $details = null): void
    {
        try {
            DB::table('audit_logs')->insert([
                'user_id' => $userId,
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
