<?php

namespace App\Support;

use Illuminate\Http\Request;

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
        if (array_intersect(UserRoles::names($request), self::WIDE)) {
            return null;
        }
        return Visibility::centreIds($agencyId, $request->user());
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
}
