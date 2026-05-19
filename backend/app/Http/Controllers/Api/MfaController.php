<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * v22p7 — MFA (TOTP) — Phase A: enrolment endpoints only.
 *
 * The login-flow integration (require code on login when enabled) is
 * deferred to v22p7.1; this phase lets users opt-in but doesnt enforce.
 *
 * Endpoints (all auth-required, current user):
 *   GET  /api/v1/auth/mfa/status     status of the calling user's MFA
 *   POST /api/v1/auth/mfa/setup      generate new secret + recovery codes
 *                                    (NOT enabled until /confirm succeeds)
 *   POST /api/v1/auth/mfa/confirm    user provides first code; on match,
 *                                    sets two_factor_enabled=true
 *   POST /api/v1/auth/mfa/disable    requires current code; clears secret
 */
final class MfaController extends Controller
{
    public function status(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'enabled' => (bool) $u->two_factor_enabled,
            'has_secret' => $u->two_factor_secret !== null,
            'has_recovery_codes' => DB::table('users')->where('id', $u->id)
                ->whereNotNull('two_factor_recovery_codes')
                ->exists(),
        ]);
    }

    public function setup(Request $request): JsonResponse
    {
        $u = $request->user();
        $secret = Totp::generateSecret();
        $codes = Totp::generateRecoveryCodes();

        DB::table('users')->where('id', $u->id)->update([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => json_encode(array_map(fn ($c) => Hash::make($c), $codes)),
            'two_factor_enabled' => false, // not enabled until /confirm
            'updated_at' => now(),
        ]);

        return response()->json([
            'secret' => $secret,
            'otpauth_uri' => Totp::otpauthUri($secret, $u->email),
            'recovery_codes' => $codes, // shown once, never again
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $u = $request->user();
        if (! $u->two_factor_secret) {
            return response()->json(['message' => 'No setup in progress'], 422);
        }
        $secret = decrypt($u->two_factor_secret);
        if (! Totp::verify($secret, $data['code'])) {
            return response()->json(['message' => 'Code did not match — try again'], 422);
        }
        DB::table('users')->where('id', $u->id)->update([
            'two_factor_enabled' => true,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'MFA enabled']);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'min:6', 'max:14']]);
        $u = $request->user();
        if (! $u->two_factor_enabled) {
            return response()->json(['message' => 'MFA is not enabled'], 422);
        }

        $secret = $u->two_factor_secret ? decrypt($u->two_factor_secret) : null;
        $okWithTotp = $secret && Totp::verify($secret, $data['code']);
        $okWithRecovery = false;

        if (! $okWithTotp) {
            $stored = DB::table('users')->where('id', $u->id)->value('two_factor_recovery_codes');
            $hashes = $stored ? json_decode($stored, true) : [];
            foreach ($hashes ?? [] as $i => $h) {
                if (Hash::check($data['code'], $h)) {
                    $okWithRecovery = true;
                    // consume the used recovery code so it can't be re-used
                    unset($hashes[$i]);
                    DB::table('users')->where('id', $u->id)->update([
                        'two_factor_recovery_codes' => json_encode(array_values($hashes)),
                    ]);
                    break;
                }
            }
        }

        if (! $okWithTotp && ! $okWithRecovery) {
            return response()->json(['message' => 'Code did not match'], 422);
        }

        DB::table('users')->where('id', $u->id)->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => false,
            'updated_at' => now(),
        ]);
        return response()->json(['message' => 'MFA disabled']);
    }
}
