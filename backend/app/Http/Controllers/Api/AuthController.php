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

        $tokenObj = $user->createToken(
            $data['device_name'],
            ['*'],
            now()->addDays(30)
        );

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
            'new_password' => ['required', 'string', 'min:8'],
        ]);

        $user = $request->user();

        if (! Hash::check($data['current_password'], $user->password)) {
            return response()->json([
                'message' => 'Current password incorrect.',
                'errors' => ['current_password' => ['Current password is incorrect.']],
            ], 422);
        }

        DB::table('users')->where('id', $user->id)->update([
            'password' => Hash::make($data['new_password']),
            'updated_at' => now(),
        ]);

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

            // Build reset URL — points at the SPA, which calls /auth/reset
            $appUrl = config('app.url', 'https://app.kiddietrac.com');
            $resetUrl = $appUrl.'/reset-password.html?token='.$token.'&email='.urlencode($data['email']);

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
            'new_password' => ['required', 'string', 'min:8'],
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

        DB::transaction(function () use ($user, $data, $record): void {
            DB::table('users')->where('id', $user->id)->update([
                'password' => Hash::make($data['new_password']),
                'status' => 'active',
                'updated_at' => now(),
            ]);

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

        return [
            'id' => $user->id,
            'email' => $user->email,
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
        ];
    }

    private function pickPrimaryRole(array $roles): ?string
    {
        return match (true) {
            in_array('agency_admin', $roles, true) => 'agency_admin',
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
                'payload' => $details,
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
