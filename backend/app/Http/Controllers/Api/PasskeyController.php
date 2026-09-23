<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Passkeys;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Passkeys — enrol, manage, and sign in.
 *
 * SIGN-IN IS USERNAMELESS. The credential names the account, so nothing is typed. That is
 * not a flourish: eight live accounts share three email addresses here, and an address
 * cannot identify a person on this platform. A discoverable credential can.
 *
 * THE SIGN-IN PATH IS NOT A SHORTCUT PAST THE FRONT DOOR. Everything login() does after a
 * correct password, this does too — the account-status check, AccountStatus::markClaimed,
 * last_login stamping, device-type refresh, the device_tokens row, the same audit payload
 * and the same must_change_password flag. A second way in that skipped half of them would
 * be a way to launder around them.
 *
 * WHAT A PASSKEY DOES AND DOES NOT REPLACE (Anthony, 2026-09-22): additional method, the
 * password stays as the recovery path, and everyone still rotates at 90 days. So removing
 * your last passkey locks nobody out, and holding one exempts nobody from rotation.
 */
class PasskeyController extends Controller
{
    /* ───────────────────────── enrolment (signed in) ───────────────────────── */

    /** POST /passkeys/register/options */
    public function registerOptions(Request $request): JsonResponse
    {
        $user = $request->user();
        $lib = Passkeys::lib();

        /* EXCLUDE WHAT THEY ALREADY HAVE, so the authenticator says "you already have one
           here" instead of silently making a second credential for the same account on
           the same device — which looks like success and leaves a duplicate nobody can
           tell apart in the list. */
        $exclude = DB::table('user_passkeys')->where('user_id', $user->id)
            ->pluck('credential_id')
            ->map(fn ($c) => Passkeys::unb64u($c))
            ->filter()->values()->all();

        $args = $lib->getCreateArgs(
            /* The user handle the authenticator stores and hands back at sign-in. It is
               the user ID and nothing else — no email, no name: this value is readable on
               the device and syncs to a password manager, so it must not carry anything
               about the person. */
            (string) $user->id,
            (string) ($user->username ?: $user->email),
            trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')) ?: 'KiddieTrac user',
            60,
            true,                 // resident/discoverable key — required for usernameless
            true,                 // user verification: biometric or PIN, not mere presence
            null,                 // attachment: platform or roaming, the device's choice
            $exclude              // array, never null: the signature defaults to []
        );

        $handle = Passkeys::stash($lib->getChallenge()->getBinaryString(), ['uid' => (int) $user->id]);

        return response()->json(['handle' => $handle, 'options' => $args->publicKey]);
    }

    /** POST /passkeys/register/verify */
    public function registerVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:64'],
            'client_data' => ['required', 'string'],
            'attestation' => ['required', 'string'],
            'label' => ['nullable', 'string', 'max:80'],
        ]);

        $user = $request->user();
        $pending = Passkeys::claim($data['handle']);
        if (! $pending || (int) ($pending['uid'] ?? 0) !== (int) $user->id) {
            /* Bound to the account that ASKED. Without this, a challenge minted for one
               account could be completed against another. */
            return response()->json(['message' => 'That enrolment expired. Please try again.'], 422);
        }

        try {
            $res = Passkeys::lib()->processCreate(
                Passkeys::unb64u($data['client_data']),
                Passkeys::unb64u($data['attestation']),
                $pending['challenge'],
                true,     // require user verification
                true,     // require the credential to be usable
                false     // do not fail on a missing attestation cert — we asked for none
            );
        } catch (Throwable $e) {
            $this->audit($user->id, 'passkey.enrol_failed', ['reason' => $e->getMessage()], $request);

            return response()->json(['message' => 'That passkey could not be verified.'], 422);
        }

        $credId = Passkeys::b64u($res->credentialId);

        /* Already known? Then this is the same key offered twice — re-label it rather
           than creating a row that makes the list ambiguous. */
        $existing = DB::table('user_passkeys')->where('credential_id', $credId)->first();
        if ($existing && (int) $existing->user_id !== (int) $user->id) {
            return response()->json(['message' => 'That passkey is already registered to another account.'], 409);
        }

        $label = trim((string) ($data['label'] ?? '')) ?: Passkeys::labelFor((string) $request->userAgent());

        if ($existing) {
            DB::table('user_passkeys')->where('id', $existing->id)
                ->update(['label' => $label, 'updated_at' => now()]);
            $id = (int) $existing->id;
        } else {
            $id = (int) DB::table('user_passkeys')->insertGetId([
                'user_id' => (int) $user->id,
                'credential_id' => $credId,
                'public_key' => $res->credentialPublicKey,
                'sign_count' => (int) ($res->signatureCounter ?? 0),
                'aaguid' => ! empty($res->AAGUID) ? bin2hex((string) $res->AAGUID) : null,
                'transports' => null,
                /* Synced to a password manager / iCloud / Google, or bound to this one
                   device? It changes what losing the device means, and it is what somebody
                   reviewing access needs to see. */
                'is_synced' => ! empty($res->isBackedUp) ? 1 : 0,
                'label' => $label,
                'created_ua' => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->audit($user->id, 'passkey.enrolled', [
            'summary' => 'Added a passkey "' . $label . '". It is an additional way to sign in; '
                . 'the password still works and still expires on the normal schedule.',
            'label' => $label,
            'passkey_id' => $id,
            'synced' => ! empty($res->isBackedUp),
        ], $request);

        return response()->json(['ok' => true, 'passkey' => $this->row($id)]);
    }

    /* ───────────────────────── management (signed in) ───────────────────────── */

    /** GET /passkeys */
    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('user_passkeys')->where('user_id', $request->user()->id)
            ->orderByDesc('last_used_at')->orderByDesc('id')
            ->get(['id', 'label', 'created_at', 'last_used_at', 'last_used_ip']);

        return response()->json([
            'passkeys' => $rows,
            /* Said plainly so the screen never has to imply otherwise. */
            'note' => 'Passkeys are an additional way to sign in. Your password still works.',
        ]);
    }

    /** DELETE /passkeys/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('user_passkeys')->where('id', $id)
            ->where('user_id', $request->user()->id)->first();
        /* Scoped to the owner in the QUERY, not checked afterwards: a passkey belonging
           to somebody else must be indistinguishable from one that does not exist. */
        if (! $row) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        DB::table('user_passkeys')->where('id', $id)->delete();

        $this->audit((int) $request->user()->id, 'passkey.removed', [
            'summary' => 'Removed the passkey "' . $row->label . '". Sign-in by password is unaffected.',
            'label' => $row->label,
        ], $request);

        return response()->json(['ok' => true]);
    }

    /* ───────────────────────── sign-in (public) ───────────────────────── */

    /** POST /auth/passkey/options — no account named, by design. */
    public function loginOptions(Request $request): JsonResponse
    {
        $lib = Passkeys::lib();
        /* No allowCredentials: the authenticator offers whatever it holds for this RP and
           tells us who it is. Naming candidates here would leak which accounts exist. */
        /* Positional, and the order matters: the LAST flag is requireUserVerification.
           Passing 'required' one slot early would silently set allowInternal instead and
           leave UV off - a sign-in that never checks a face or a PIN. */
        $args = $lib->getGetArgs(
            [],      // credentialIds: none, so the authenticator offers what it holds
            60,      // timeout
            true, true, true, true, true,   // usb, nfc, ble, hybrid, internal
            true     // requireUserVerification
        );
        $handle = Passkeys::stash($lib->getChallenge()->getBinaryString());

        return response()->json(['handle' => $handle, 'options' => $args->publicKey]);
    }

    /** POST /auth/passkey/verify */
    public function loginVerify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'handle' => ['required', 'string', 'max:64'],
            'credential_id' => ['required', 'string', 'max:512'],
            'client_data' => ['required', 'string'],
            'authenticator_data' => ['required', 'string'],
            'signature' => ['required', 'string'],
            'user_handle' => ['nullable', 'string', 'max:255'],
            'device_name' => ['required', 'string', 'max:120'],
            'device_platform' => ['required', 'string', 'in:ios,android,web,unknown'],
        ]);

        $pending = Passkeys::claim($data['handle']);
        if (! $pending) {
            return response()->json(['message' => 'That sign-in attempt expired. Please try again.'], 422);
        }

        $cred = DB::table('user_passkeys')->where('credential_id', $data['credential_id'])->first();
        if (! $cred) {
            $this->audit(null, 'passkey.login_unknown_credential', [
                'summary' => 'A passkey sign-in was attempted with a credential this platform does not know.',
            ], $request);

            return response()->json(['message' => 'That passkey is not registered.'], 422);
        }

        /* The authenticator's own claim about WHO, cross-checked against our record. They
           must agree: a credential row that says one user and an assertion that says
           another is either a bug or an attack, and neither should sign anyone in. */
        if (! empty($data['user_handle']) && (string) $data['user_handle'] !== (string) $cred->user_id) {
            $this->audit((int) $cred->user_id, 'passkey.login_handle_mismatch', [
                'summary' => 'A passkey assertion named a different account than the credential belongs to. Refused.',
            ], $request);

            return response()->json(['message' => 'That passkey could not be verified.'], 422);
        }

        try {
            Passkeys::lib()->processGet(
                Passkeys::unb64u($data['client_data']),
                Passkeys::unb64u($data['authenticator_data']),
                Passkeys::unb64u($data['signature']),
                (string) $cred->public_key,
                $pending['challenge'],
                (int) $cred->sign_count ?: null,
                true,    // require user verification
                true
            );
        } catch (Throwable $e) {
            $this->audit((int) $cred->user_id, 'passkey.login_failed', [
                'summary' => 'A passkey sign-in failed verification: ' . $e->getMessage(),
            ], $request);

            return response()->json(['message' => 'That passkey could not be verified.'], 422);
        }

        $user = DB::table('users')->where('id', $cred->user_id)->whereNull('deleted_at')->first();
        if (! $user) {
            return response()->json(['message' => 'That account no longer exists.'], 403);
        }

        /* THE SAME DOOR POLICY AS THE PASSWORD PATH. A switched-off account is switched
           off however you knock. */
        if (in_array($user->status, \App\Support\Audience::OFF_STATUSES, true)) {
            $this->audit((int) $user->id, 'passkey.login_blocked', [
                'summary' => 'A passkey sign-in was refused: the account is ' . $user->status . '.',
            ], $request);

            return response()->json(['message' => 'Account is not active.'], 403);
        }

        /* CLONE DETECTION. A counter that goes BACKWARDS means two copies of one
           credential are in use. Many platform authenticators always report 0 — that is
           normal and not a signal — so only a genuine decrease from a non-zero base is
           worth raising, and it is raised rather than silently accepted. */
        $newCount = $this->counterFrom($data['authenticator_data']);
        if ($newCount > 0 && (int) $cred->sign_count > 0 && $newCount <= (int) $cred->sign_count) {
            $this->audit((int) $user->id, 'passkey.counter_regression', [
                'summary' => 'A passkey signature counter did not advance (' . $cred->sign_count
                    . ' -> ' . $newCount . '). This can mean the credential has been cloned.',
            ], $request);
            try {
                DB::table('security_alerts')->insert([
                    'type' => 'passkey_counter_regression', 'severity' => 'high',
                    'subject' => 'user ' . $user->id,
                    'details' => 'Passkey "' . $cred->label . '" signature counter went '
                        . $cred->sign_count . ' -> ' . $newCount . '.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            } catch (Throwable $e) {
            }
        }

        DB::table('user_passkeys')->where('id', $cred->id)->update([
            'sign_count' => max((int) $cred->sign_count, $newCount),
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'updated_at' => now(),
        ]);

        return $this->issueSession($request, $user, $data, $cred);
    }

    /* ───────────────────────── shared with login() ───────────────────────── */

    /**
     * Everything the password path does once it is satisfied.
     *
     * Deliberately a copy of login()'s tail rather than a clever shared abstraction: the
     * two paths must stay identical, and the way that is noticed is by them sitting side
     * by side and being read together. If they drift, that is a bug in both.
     */
    private function issueSession(Request $request, $user, array $data, $cred): JsonResponse
    {
        $model = \App\Models\User::find($user->id);
        $tokenObj = $model->createToken($data['device_name'], ['*'], now()->addDays(30));

        \App\Support\AccountStatus::markClaimed((int) $user->id);

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

        $activeRoles = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('active', 1)->pluck('role')->unique()->values()->all();

        $this->audit((int) $user->id, 'login', [
            'method' => 'passkey',
            'passkey' => $cred->label,
            'account' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'username' => $user->username,
            'email' => $user->email,
            'device' => (string) ($data['device_platform'] ?? 'unknown'),
            'device_name' => (string) ($data['device_name'] ?? ''),
            'roles' => $activeRoles,
            'no_active_role' => $activeRoles === [],
        ], $request);

        /* must_change_password is honoured exactly as on the password path: the token is
           issued (changing a password needs an authenticated call) and
           EnsurePasswordChanged makes it useless for anything else. */
        return response()->json([
            'token' => $tokenObj->plainTextToken,
            'expires_at' => $tokenObj->accessToken->expires_at?->toIso8601String(),
            /* The SAME shape login() returns, from the same method, so a client cannot
               tell the two paths apart and no field quietly goes missing on this one. */
            'user' => app(AuthController::class)->formatUser(\App\Models\User::find($user->id)),
            'must_change_password' => (bool) ($user->must_change_password ?? false),
        ]);
    }

    /** The 32-bit big-endian counter at bytes 33..36 of authenticatorData. */
    private function counterFrom(string $b64u): int
    {
        $bin = Passkeys::unb64u($b64u);
        if (strlen($bin) < 37) {
            return 0;
        }
        $parts = unpack('N', substr($bin, 33, 4));

        return (int) ($parts[1] ?? 0);
    }

    private function row(int $id): ?object
    {
        return DB::table('user_passkeys')->where('id', $id)
            ->first(['id', 'label', 'created_at', 'last_used_at', 'last_used_ip']);
    }

    private function audit(?int $userId, string $action, array $payload, Request $request): void
    {
        try {
            Audit::write([
                'user_id' => $userId,
                /* Whose agency, not which header — a passkey sign-in arrives with no
                   X-Active-Agency-Id, exactly like a password one. */
                'agency_id' => $userId ? \App\Support\AuditScope::ownAgency($userId) : null,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $userId,
                'payload' => json_encode($payload),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
        }
    }
}
