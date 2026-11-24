<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * WHAT A RESELLER MAY REACH (2026-10-21).
 *
 * Anthony: a Reseller is a FULL AGENCY ADMIN of every agency it brought to the platform,
 * and of nothing else. Rather than teach hundreds of endpoints a new rule, the reseller
 * person is given real agency_admin role_assignments - one per agency whose
 * agencies.reseller_id is their reseller - marked granted_by_reseller_id. Every existing
 * guard (role middleware, resolveAgencyId, userBelongsToAgency, Visibility, the agency
 * switcher) then answers correctly with no change, and sync() withdraws a grant the
 * moment the agency leaves the reseller or the person leaves it.
 *
 * The other half is App\Http\Middleware\ResellerAgencyGuard: a reseller naming any
 * agency they do not own in X-Active-Agency-Id is refused (403), never quietly given
 * a different agency.
 */
final class ResellerAccess
{
    /** Reseller ids this person manages (active reseller_admin rows on active resellers). */
    public static function resellerIds(int $userId): array
    {
        return DB::table('role_assignments as ra')->join('resellers as r', 'r.id', '=', 'ra.reseller_id')
            ->where('ra.user_id', $userId)->where('ra.role', 'reseller_admin')->where('ra.active', true)
            ->where('r.status', 'active')
            ->pluck('r.id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public static function isReseller(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('role', 'reseller_admin')->where('active', true)->exists();
    }

    /** Live agencies owned by this person's reseller(s). */
    public static function ownedAgencyIds(int $userId): array
    {
        $rids = self::resellerIds($userId);
        if (! $rids) return [];
        return DB::table('agencies')->whereIn('reseller_id', $rids)->whereNull('deleted_at')
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public static function owns(int $userId, int $agencyId): bool
    {
        return in_array($agencyId, self::ownedAgencyIds($userId), true);
    }

    /**
     * Make the grants match ownership exactly. Idempotent; cheap enough to run on every
     * /auth/me and after any change to a reseller's agencies or people.
     *
     * @return array{granted:int, revoked:int}
     */
    public static function sync(int $userId): array
    {
        $granted = 0;
        $revoked = 0;
        $byReseller = [];
        foreach (self::resellerIds($userId) as $rid) {
            foreach (DB::table('agencies')->where('reseller_id', $rid)->whereNull('deleted_at')->pluck('id') as $aid) {
                $byReseller[(int) $aid] = $rid;
            }
        }
        $rows = DB::table('role_assignments')->where('user_id', $userId)->whereNotNull('granted_by_reseller_id')->get();
        $have = [];
        foreach ($rows as $r) {
            $aid = (int) $r->agency_id;
            $should = isset($byReseller[$aid]) && (int) $byReseller[$aid] === (int) $r->granted_by_reseller_id;
            if ($should && ! isset($have[$aid])) {
                $have[$aid] = true;
                if (! $r->active) { DB::table('role_assignments')->where('id', $r->id)->update(['active' => 1]); $granted++; }
            } elseif ($r->active) {
                DB::table('role_assignments')->where('id', $r->id)->update(['active' => 0]);
                $revoked++;
            }
        }
        foreach ($byReseller as $aid => $rid) {
            if (isset($have[$aid])) continue;
            DB::table('role_assignments')->insert([
                'user_id' => $userId, 'role' => 'agency_admin', 'agency_id' => $aid, 'centre_id' => null,
                'reseller_id' => null, 'granted_by_reseller_id' => $rid, 'active' => 1, 'created_at' => now(),
            ]);
            $granted++;
        }
        if ($granted || $revoked) {
            Cache::forget('auditscope.own.' . $userId);
        }
        return ['granted' => $granted, 'revoked' => $revoked];
    }

    /** Everyone who manages this reseller (after its agencies or people change). */
    public static function syncReseller(int $resellerId): void
    {
        $uids = DB::table('role_assignments')->where('reseller_id', $resellerId)->where('role', 'reseller_admin')
            ->pluck('user_id')->unique();
        // Anyone holding a grant from it too: a person removed from the reseller must lose them.
        $uids = $uids->merge(DB::table('role_assignments')->where('granted_by_reseller_id', $resellerId)->pluck('user_id'))->unique();
        foreach ($uids as $uid) {
            self::sync((int) $uid);
        }
    }
}
