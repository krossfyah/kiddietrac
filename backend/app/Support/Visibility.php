<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Which centres may a person see, given an agency that has already been resolved?
 *
 * The rule lives here rather than only in ResolvesCentreContext because not every
 * controller uses that trait — CalendarOverlayController and OperationsV2Controller
 * each carry their own private resolveAgencyId(), and adding the trait beside a
 * private method of the same name is asking for the shadowing bug that already cost
 * a day this week (a class's resolveCentreId(Child) silently overrode the trait's
 * resolveCentreId(User) and every guardian's incident list 500'd).
 *
 * So: one plain static, callable from anywhere, and the trait delegates to it. The
 * agency is an argument, not something this class resolves — deciding WHICH agency
 * is a separate question with its own header rules, and mixing the two is how a
 * tenant check ends up standing in for a person check.
 *
 * FAILS CLOSED. An unknown role, no agency, or a staff member with no centre
 * assignment returns []. Callers must treat [] as "no rows" — always `?: [0]` in
 * the whereIn — never as "no filter".
 */
final class Visibility
{
    /** Sees every centre in the agency. */
    private const AGENCY_WIDE = ['agency_admin', 'auditor'];

    /** Confined to the centres named on their own assignments. */
    private const CENTRE_SCOPED = ['centre_director', 'educator', 'home_visitor'];

    /**
     * @param  int  $agencyId  already resolved, and already checked against the caller
     * @return int[] centre ids, always within that agency
     */
    public static function centreIds(int $agencyId, $user): array
    {
        if (! $user || ! $agencyId) {
            return [];
        }

        $inAgency = DB::table('centres')->where('agency_id', $agencyId)
            ->whereNull('deleted_at')->pluck('id')->map(fn ($v) => (int) $v)->all();

        $roles = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->get(['role', 'centre_id', 'agency_id']);

        if ($roles->isEmpty()) {
            return [];
        }

        /* A platform admin is already confined to the agency they switched into —
           resolveAgencyId refuses to guess one for them — and sees all of it. */
        if ($roles->contains(fn ($r) => $r->role === 'platform_admin')) {
            return $inAgency;
        }

        if ($roles->contains(fn ($r) => in_array($r->role, self::AGENCY_WIDE, true)
                && (int) $r->agency_id === $agencyId)) {
            return $inAgency;
        }

        /* Intersected with the agency so a stale assignment cannot reach outside it. */
        $theirs = $roles
            ->filter(fn ($r) => in_array($r->role, self::CENTRE_SCOPED, true))
            ->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->unique()->values()->all();

        return array_values(array_intersect($theirs, $inAgency));
    }

    /** The same rule followed through to children, for child-shaped tables. */
    public static function childIds(int $agencyId, $user): array
    {
        $centreIds = self::centreIds($agencyId, $user);
        if (! $centreIds) {
            return [];
        }

        return DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('c.deleted_at')
            ->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }
}
