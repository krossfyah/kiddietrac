<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WHEN AN ACCOUNT'S ROLES CHANGE, ITS SESSIONS END.
 *
 * The API already reads role_assignments live on every request, so a revoked role cannot
 * be USED after the change. What survived was the session itself: the browser kept the
 * roles it cached at sign-in, so it went on drawing a menu and screens the server would
 * refuse — one account produced 55 forbidden /provider/* calls over three days that way.
 *
 * Ending the session is the honest answer, and it is the one Anthony chose: whoever is
 * signed in gets a clean sign-in that reflects what they may now do, rather than a UI
 * quietly at odds with their access. It also makes a revocation behave like the security
 * action it usually is — an offboarding, a mistake being corrected — instead of leaving a
 * live token in a browser that still believes it is an admin.
 *
 * Deliberately named and called explicitly rather than hooked to a model event:
 * role_assignments is written through the query builder in a dozen places (seeders,
 * imports, signup), and most of those are for accounts nobody is signed in to. This is
 * for the deliberate, human-driven grant and revoke.
 */
class RoleChange
{
    /**
     * Revoke every API token held by these accounts.
     *
     * @param  int|int[]  $userIds
     * @return int  tokens revoked
     */
    public static function endSessions($userIds): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $userIds)));
        if (! $ids) {
            return 0;
        }

        try {
            $n = DB::table('personal_access_tokens')
                ->where('tokenable_type', \App\Models\User::class)
                ->whereIn('tokenable_id', $ids)
                ->delete();

            if ($n > 0) {
                Log::info('Roles changed — sessions ended', ['users' => $ids, 'tokens' => $n]);
            }

            return $n;
        } catch (\Throwable $e) {
            /* A role change must not fail because the sign-out did. The server still
               refuses whatever the old role could do, so the worst case is the stale UI
               this exists to prevent — not a role that failed to change. */
            Log::warning('Could not end sessions after a role change', [
                'users' => $ids, 'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }
}
