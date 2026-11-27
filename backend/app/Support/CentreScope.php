<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WHICH CENTRES MAY THIS CALLER TOUCH, inside an agency already resolved (2026-10-05).
 *
 * For modules that used to be agency_admin only and now also admit a centre director
 * (expenses, purchase orders, suppliers, fee plans). Anthony: "open to directors, own
 * centre only".
 *
 *   null  — agency-wide caller (agency_admin / platform_admin): no extra filter, the
 *           module behaves exactly as before.
 *   int[] — everyone else: the centres Visibility gives them. FAILS CLOSED — [] means
 *           nothing; always `?: [0]` in a whereIn.
 *
 * Rows with centre_id NULL are agency-wide records. A centre-scoped caller may READ
 * them where a module shares them as a catalogue (suppliers, fee plans) but never
 * write them: canWrite() is false for null.
 */
final class CentreScope
{
    private const WIDE = ['platform_admin', 'agency_admin'];

    /** @return int[]|null */
    public static function ids(Request $request, int $agencyId): ?array
    {
        // Roles in the ACTIVE agency (2026-11-05): an admin role held in another agency must not
        // make this one agency-wide.
        if (array_intersect(AgencyRole::names($request), self::WIDE)) {
            return null;
        }
        return Visibility::centreIds($agencyId, $request->user());
    }

    /**
     * THE PEOPLE OF THESE CENTRES (2026-11-13, Forms Manager for directors): anyone holding an active role placed
     * at one of them (staff, and the guardian rows that carry their family's centre) plus the guardians of the
     * families anchored there. For modules whose rows belong to a PERSON, not a centre (signed forms, file
     * requests). Fails closed: no centres, nobody.
     *
     * @param  int[] $centreIds
     * @return int[]
     */
    public static function peopleIds(array $centreIds): array
    {
        $centreIds = array_values(array_unique(array_map('intval', $centreIds)));
        if (! $centreIds) {
            return [];
        }
        return DB::table('role_assignments')->where('active', 1)->whereIn('centre_id', $centreIds)->pluck('user_id')
            ->merge(DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                ->whereIn('f.centre_id', $centreIds)->whereNull('f.deleted_at')->whereNotNull('g.user_id')
                ->pluck('g.user_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /**
     * For a caller: null = agency-wide (no filter, exactly as before); otherwise the people of their centres.
     *
     * @return int[]|null
     */
    public static function people(Request $request, int $agencyId): ?array
    {
        $scope = self::ids($request, $agencyId);
        return $scope === null ? null : self::peopleIds($scope);
    }

    /** May this caller change a row with this centre_id? */
    public static function canWrite(?array $scope, $centreId): bool
    {
        return $scope === null || ($centreId !== null && in_array((int) $centreId, $scope, true));
    }

    /** May this caller see a row with this centre_id? Agency-wide rows only when $shared. */
    public static function canRead(?array $scope, $centreId, bool $shared = false): bool
    {
        if ($scope === null) {
            return true;
        }
        return $centreId === null ? $shared : in_array((int) $centreId, $scope, true);
    }

    /**
     * WHO RUNS THESE CENTRES (2026-11-26, absence-email leak).
     *
     * Recipients for a notice about something AT a centre: the agency's admins
     * (agency-wide) plus the directors placed at THOSE centres — never the directors
     * of the agency's other centres. Centres outside this agency are dropped, and
     * with no centre at all it is admins only (fails closed for directors).
     *
     * Returns a role_assignments(ra) JOIN users(u) builder: pluck('u.email') / ('u.id').
     *
     * @param  int|int[]|null $centreIds
     */
    public static function managers(int $agencyId, $centreIds)
    {
        $centreIds = array_values(array_unique(array_filter(array_map('intval', (array) $centreIds))));
        if ($centreIds) {
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->whereIn('id', $centreIds)
                ->pluck('id')->map(fn ($v) => (int) $v)->all();
        }
        // Never an outsider (super admin / integration / reseller grant), even with an agency role (AgencyOutsiders, 2026-11-26).
        return AgencyOutsiders::exclude(DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id'))
            ->where('ra.active', true)->whereNull('u.deleted_at')
            // KiddieTrac HR (2026-11-27): someone whose HR file says they have left is off every approver list, even
            // while their account stays open for the last few days.
            ->whereNotExists(fn ($x) => $x->from('hr_employee_files as hef')->whereColumn('hef.user_id', 'u.id')
                ->where('hef.agency_id', $agencyId)->where('hef.status', 'terminated'))
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where(fn ($w) => $w->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId));
                if ($centreIds) {
                    $q->orWhere(fn ($w) => $w->where('ra.role', 'centre_director')->whereIn('ra.centre_id', $centreIds));
                }
            });
    }

    /** The centres of this agency a person is (or was) placed at, from their role rows. @return int[] */
    public static function centresOfUser(int $userId, int $agencyId): array
    {
        return DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $userId)->where('c.agency_id', $agencyId)
            ->pluck('c.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }
}
