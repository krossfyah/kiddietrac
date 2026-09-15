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
    /**
     * Every enrolment step, recorded.
     *
     * This controller wrote nothing at all. Asked why two-factor was not working for
     * somebody, the honest answer was that the log could not say whether she had tried
     * — and she had not, because a gate deadlock meant the request was never made. An
     * absent row and a failed row looked identical. See the same lesson in
     * AuthController's login/reset reasons.
     */
    private function trail(Request $request, string $action, array $extra = []): void
    {
        try {
            $u = $request->user();
            \App\Support\Audit::write([
                'user_id' => $u->id ?? null,
                'agency_id' => $u ? \App\Support\AuditScope::resolve((int) $u->id, $request) : null,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $u->id ?? null,
                'payload' => json_encode($extra + ['summary' => $this->summarise($action, $extra)]),
                'ip_address' => $request->ip(),
                'user_agent' => substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason enrolment fails.
        }
    }

    /** A sentence an auditor can read without knowing the action names. */
    private function summarise(string $action, array $extra): string
    {
        switch ($action) {
            case 'mfa.setup_started':
                return 'Started two-factor setup (a new secret and recovery codes were issued)';
            case 'mfa.enabled':
                return 'Two-factor enabled';
            case 'mfa.confirm_failed':
                return 'Two-factor code did not match during setup';
            case 'mfa.confirm_no_setup':
                return 'Tried to confirm two-factor with no setup in progress';
            case 'mfa.disabled':
                return 'Two-factor disabled (' . ($extra['method'] ?? 'code') . ')';
            case 'mfa.disable_failed':
                return 'Two-factor could not be disabled — code did not match';
            default:
                return $action;
        }
    }

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

        $this->trail($request, 'mfa.setup_started', ['recovery_codes_issued' => count($codes)]);

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
            $this->trail($request, 'mfa.confirm_no_setup');

            return response()->json(['message' => 'No setup in progress'], 422);
        }
        $secret = decrypt($u->two_factor_secret);
        if (! Totp::verify($secret, $data['code'])) {
            $this->trail($request, 'mfa.confirm_failed');

            return response()->json(['message' => 'Code did not match — try again'], 422);
        }
        DB::table('users')->where('id', $u->id)->update([
            'two_factor_enabled' => true,
            'updated_at' => now(),
        ]);
        $this->trail($request, 'mfa.enabled');

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
            $this->trail($request, 'mfa.disable_failed');

            return response()->json(['message' => 'Code did not match'], 422);
        }

        DB::table('users')->where('id', $u->id)->update([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_enabled' => false,
            'updated_at' => now(),
        ]);
        $this->trail($request, 'mfa.disabled', ['method' => $okWithTotp ? 'authenticator code' : 'recovery code']);

        return response()->json(['message' => 'MFA disabled']);
    }
}
