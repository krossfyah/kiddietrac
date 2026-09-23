<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Services\PasswordPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS LOCKED OUT, AND LETTING THEM BACK IN (2026-09-21).
 *
 * Anthony: "add mechanism for admins to unlock a user if they locked themselves out."
 *
 * The automatic hold is five minutes and clears itself, so this exists for the case where
 * five minutes is five minutes too long: an educator at the door with families arriving,
 * who has mistyped her password five times because the phone keyboard capitalised it.
 *
 * Lifting a lock is recorded, not just done. A security control an administrator can
 * switch off silently is not a control, and "who let this account back in, and when" is
 * the first question anyone reviewing an incident will ask.
 */
final class SecurityLockController extends Controller
{
    /** GET /admin/security/locked-accounts — who is being held right now, and why. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $this->assertAdmin($request, $agencyId);

        /* Only this agency's people. A locked account is a security fact about a person,
           and an admin at one agency has no business reading another's. */
        $members = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)->where('ra.active', true)
            ->whereNull('u.deleted_at')
            ->distinct()
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.username']);

        $locked = [];
        foreach ($members as $m) {
            /* Somebody can be held under either identifier, because the hold is keyed on
               what was TYPED and a person with a username can be reached both ways. */
            foreach (array_filter([$m->email, $m->username]) as $identifier) {
                $mins = PasswordPolicy::lockedFor((string) $identifier);
                if ($mins > 0) {
                    $locked[] = [
                        'user_id' => (int) $m->id,
                        'name' => trim(($m->first_name ?? '') . ' ' . ($m->last_name ?? '')),
                        'login' => $identifier,
                        'minutes_remaining' => $mins,
                    ];
                    break;
                }
            }
        }

        return response()->json([
            'data' => $locked,
            /* The screen builds its dropdown from this, so the list lives in one place
               and a new reason does not need a matching edit in the JavaScript. */
            'reasons' => self::REASONS,
            'policy' => [
                'lock_after' => PasswordPolicy::LOCK_AFTER,
                'lock_minutes' => PasswordPolicy::LOCK_MINUTES,
                'window_minutes' => PasswordPolicy::COUNT_WINDOW_MINUTES,
            ],
        ]);
    }

    /**
     * POST /admin/users/{user}/unlock-login — lift the hold now.
     *
     * A REASON IS REQUIRED (2026-09-21). Anthony: "a pop up with a reason code as well".
     *
     * Lifting a lockout is switching off a security control for one person, and the
     * audit row has to answer "why" as well as "who" - otherwise a reviewer reading
     * "Anthony unlocked Safia" six weeks later has no way to tell a mistyped password
     * from somebody being talked into it over the phone. Social engineering is the usual
     * route to an account, and "the helpdesk unlocked it for a caller" is how it ends.
     *
     * A closed list, not free text: a dropdown of five reasons is answered honestly in
     * one tap, where a text box gets "asdf". `other` is the exception and is the only
     * one that demands a written note.
     */
    private const REASONS = [
        'forgot_password' => 'Forgot their password',
        'typo' => 'Mistyped it / phone keyboard',
        'shared_device' => 'Wrong account on a shared device',
        'returning_staff' => 'Returning after time away',
        'other' => 'Other (explained below)',
    ];

    public function unlock(Request $request, int $user): JsonResponse
    {
        $agencyId = $this->agency($request);
        $this->assertAdmin($request, $agencyId);

        $data = $request->validate([
            'reason' => ['required', 'string', \Illuminate\Validation\Rule::in(array_keys(self::REASONS))],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        if ($data['reason'] === 'other' && trim((string) ($data['note'] ?? '')) === '') {
            return response()->json([
                'message' => 'Please say what the reason was.',
                'errors' => ['note' => ['Required when the reason is "Other".']],
            ], 422);
        }

        abort_unless(DB::table('role_assignments')->where('user_id', $user)
            ->where('agency_id', $agencyId)->where('active', true)->exists(), 403, 'Not in this agency.');

        $u = DB::table('users')->where('id', $user)->first(['id', 'first_name', 'last_name', 'email', 'username']);
        abort_unless($u, 404, 'No such person.');

        $by = $request->user();
        $byName = trim(($by->first_name ?? '') . ' ' . ($by->last_name ?? '')) ?: ($by->email ?? ('user ' . $by->id));
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: ('user ' . $user);

        /* BOTH identifiers are cleared. A hold is keyed on what was typed, so clearing
           only the email would leave somebody who signs in by username still shut out -
           and they would have been told "ask your administrator", who just did. */
        $cleared = [];
        foreach (array_filter([$u->email, $u->username]) as $identifier) {
            Audit::write([
                'user_id' => (int) $by->id,
                'agency_id' => $agencyId,
                'action' => 'security.login_unlocked',
                'entity_type' => 'user',
                'entity_id' => $user,
                'payload' => json_encode([
                    'summary' => $byName . ' lifted the sign-in hold on ' . $name
                        . ' (' . $identifier . ') after too many failed attempts. Reason: '
                        . self::REASONS[$data['reason']]
                        . (trim((string) ($data['note'] ?? '')) !== '' ? ' - ' . trim((string) $data['note']) : '') . '.',
                    /* lockedFor() matches on this, so the spelling matters. */
                    'login' => mb_strtolower(trim((string) $identifier)),
                    'reason_code' => $data['reason'],
                    'reason' => self::REASONS[$data['reason']],
                    'note' => trim((string) ($data['note'] ?? '')) ?: null,
                    'unlocked_by' => $byName,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
            $cleared[] = $identifier;
        }

        return response()->json([
            'ok' => true,
            'name' => $name,
            'cleared' => $cleared,
            'message' => $name . ' can sign in again now.',
        ]);
    }

    private function agency(Request $request): int
    {
        $active = (int) $request->header('X-Active-Agency-Id');
        abort_unless($active > 0, 422, 'No active agency.');

        return $active;
    }

    /**
     * Only an administrator may see who is locked out or lift a hold.
     *
     * Named here rather than left to the route prefix: an /admin/ path says where a
     * setting is EDITED, not who may call it, and that assumption has cost this codebase
     * a leak before.
     */
    private function assertAdmin(Request $request, int $agencyId): void
    {
        $ok = DB::table('role_assignments')->where('user_id', $request->user()->id)
            ->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where('role', 'platform_admin')
                    ->orWhere(function ($a) use ($agencyId) {
                        $a->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', $agencyId);
                    });
            })->exists();

        abort_unless($ok, 403, 'Administrators only.');
    }
}
