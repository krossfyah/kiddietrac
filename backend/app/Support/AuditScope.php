<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the AGENCY a write/audit action belongs to, so audit_logs rows can be
 * stamped with an agency_id and the per-agency audit log shows ONLY that agency's
 * activity (no cross-tenant leakage).
 *
 * Rules:
 *  - A platform_admin (super admin) acts inside whichever agency they've switched
 *    into (X-Active-Agency-Id) — that's the agency the action belongs to.
 *  - Everyone else is pinned to THEIR OWN agency, regardless of any header they
 *    send (a spoofed header can't retag their action into another tenant's log).
 */
class AuditScope
{
    /** The agency an action by $userId (optionally with $request) belongs to. */
    public static function resolve(?int $userId, $request = null): ?int
    {
        if (! $userId) {
            return null;
        }

        $isPlatform = self::isPlatformAdmin($userId);
        $header = 0;
        try {
            $req = $request ?: request();
            $header = (int) ($req ? $req->header('X-Active-Agency-Id') : 0);
        } catch (\Throwable $e) {
            $header = 0;
        }

        if ($isPlatform) {
            // The agency they've switched into (validated to a real, live agency).
            if ($header && self::agencyExists($header)) {
                return $header;
            }
            return null; // platform-level action with no agency context
        }

        // Non-platform: their own agency wins over any client-supplied header.
        return self::ownAgency($userId);
    }

    private static function isPlatformAdmin(int $userId): bool
    {
        return (bool) Cache::remember('auditscope.plat.' . $userId, 120, function () use ($userId) {
            return DB::table('role_assignments')->where('user_id', $userId)
                ->where('role', 'platform_admin')->where('active', true)->exists();
        });
    }

    private static function agencyExists(int $agencyId): bool
    {
        return (bool) Cache::remember('auditscope.agx.' . $agencyId, 300, function () use ($agencyId) {
            return DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists();
        });
    }

    /** A user's single owning agency (via role assignment agency_id, or its centre). */
    public static function ownAgency(int $userId): ?int
    {
        return Cache::remember('auditscope.own.' . $userId, 120, function () use ($userId) {
            $ra = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
                ->orderByRaw('agency_id IS NULL')->first(['agency_id', 'centre_id']);
            if ($ra) {
                if ($ra->agency_id) {
                    return (int) $ra->agency_id;
                }
                if ($ra->centre_id) {
                    $aid = DB::table('centres')->where('id', $ra->centre_id)->value('agency_id');
                    if ($aid) {
                        return (int) $aid;
                    }
                }
            }
            // Guardian → family → centre → agency.
            $aid = DB::table('guardians as g')
                ->join('families as f', 'f.id', '=', 'g.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->value('c.agency_id');

            return $aid ? (int) $aid : null;
        });
    }
}
