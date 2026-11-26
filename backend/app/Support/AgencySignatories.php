<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * WHO SPEAKS FOR AN AGENCY ON ITS PAPERS (2026-11-26, Anthony: "director and administrator should include everyone
 * in the agency - Anthony is not a part of the agency, I am only the super admin/admin - I should not be included
 * or mentioned in these report cards").
 *
 * One rule for the report card's "Director / Administrator" box and for who may sign "Approved by":
 *   - the agency's own people: active centre_director rows on the agency's centres and active agency_admin rows
 *     on the agency, active users that are not deleted;
 *   - NEVER a platform admin (any platform_admin row, even when they also hold agency_admin there), an
 *     integration account, a reseller's people (reseller_admin, or a grant made by a reseller), or support /
 *     view-as access (that is a session, never a role row, so it can never be listed).
 */
class AgencySignatories
{
    private static array $outsiders = [];

    /** Users who are never one of an agency's own people. */
    public static function outsiderIds(): array
    {
        if (! isset(self::$outsiders['ids'])) {
            $ids = DB::table('role_assignments')
                ->where(fn ($q) => $q->whereIn('role', ['platform_admin', 'integration', 'reseller_admin'])->orWhereNotNull('granted_by_reseller_id'))
                ->pluck('user_id')
                ->merge(DB::table('users')->where('email', 'like', 'integration+%')->pluck('id'))
                ->map(fn ($v) => (int) $v)->unique()->values()->all();
            self::$outsiders['ids'] = $ids;
        }
        return self::$outsiders['ids'];
    }

    /** A platform admin / super admin: never listed, never signs "Approved by". */
    public static function isPlatformAdmin(int $userId): bool
    {
        return $userId > 0 && DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->exists();
    }

    /** May this user sign a card as one of the agency's own admins? (The caller also checks they hold the role.) */
    public static function mayApprove(int $userId): bool
    {
        return $userId > 0 && ! in_array($userId, self::outsiderIds(), true);
    }

    /** ['directors' => [full names], 'admins' => [full names]] - everyone, no cap. Someone who is both is listed once, as admin. */
    public static function forAgency(int $agencyId): array
    {
        if ($agencyId <= 0) {
            return ['directors' => [], 'admins' => []];
        }
        $out = self::outsiderIds();
        $people = fn ($q) => $q->join('users as u', 'u.id', '=', 'ra.user_id')->where('ra.active', 1)
            ->whereNull('ra.granted_by_reseller_id')
            ->where('u.status', 'active')->whereNull('u.deleted_at')
            ->when($out, fn ($w) => $w->whereNotIn('u.id', $out))
            ->orderBy('u.first_name')->orderBy('u.last_name')->get(['u.id', 'u.first_name', 'u.last_name']);
        $admins = $people(DB::table('role_assignments as ra')->where('ra.role', 'agency_admin')->where('ra.agency_id', $agencyId));
        $adminIds = $admins->pluck('id')->map(fn ($v) => (int) $v)->all();
        $directors = $people(DB::table('role_assignments as ra')->where('ra.role', 'centre_director')
            ->join('centres as c', 'c.id', '=', 'ra.centre_id')->where('c.agency_id', $agencyId)->whereNull('c.deleted_at'))
            ->reject(fn ($u) => in_array((int) $u->id, $adminIds, true));
        $name = fn ($u) => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return [
            'directors' => $directors->map($name)->filter()->unique()->values()->all(),
            'admins' => $admins->map($name)->filter()->unique()->values()->all(),
        ];
    }
}
