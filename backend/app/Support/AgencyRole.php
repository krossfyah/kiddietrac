<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A ROLE COUNTS IN THE AGENCY YOU ARE WORKING IN (2026-10-31, data-leakage rule).
 *
 * Role checks across the API asked "does this person hold agency_admin / centre_director / educator ANYWHERE?",
 * while the data they then served came from the ACTIVE agency (X-Active-Agency-Id, honoured whenever the person has
 * any membership there). So someone who is an admin in agency A and only an educator - or a parent - in agency B could
 * open agency B with admin powers: payee invoices, staff banking details, settings, reports.
 *
 * constrain() narrows a role_assignments query to the rows that belong to the active agency (direct agency_id, or a
 * centre of it), plus platform_admin rows. It only narrows when the request names an active agency the person really
 * belongs to - the same condition under which controllers use that agency - so with no header nothing changes.
 * Platform admins are never narrowed.
 */
final class AgencyRole
{
    private const KEY = 'kt.agency_role.active';

    /** The active agency this request is acting in, when it is one the person belongs to; null = don't narrow. */
    public static function activeAgency(?Request $request): ?int
    {
        if (! $request || ! $request->user()) return null;
        if ($request->attributes->has(self::KEY)) return $request->attributes->get(self::KEY);
        $agency = null;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header > 0) {
            $rows = UserRoles::all($request);
            $isPlatform = false;
            foreach ($rows as $r) { if ($r->role === 'platform_admin') { $isPlatform = true; break; } }
            if (! $isPlatform) {
                $centres = self::centreIds($header);
                $member = false;
                foreach ($rows as $r) {
                    if ((int) $r->agency_id === $header || ($r->centre_id && in_array((int) $r->centre_id, $centres, true))) { $member = true; break; }
                }
                if (! $member) {
                    // A parent: membership through a family at one of the agency's centres.
                    $member = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')
                        ->where('g.user_id', $request->user()->id)->whereIn('f.centre_id', $centres ?: [0])->exists();
                }
                if ($member) $agency = $header;
            }
        }
        $request->attributes->set(self::KEY, $agency);
        return $agency;
    }

    /** @return int[] */
    public static function centreIds(int $agencyId): array
    {
        // Memoised on the request (never a static: workers are long-lived and a centre added later must count).
        $req = app()->bound('request') ? request() : null;
        $key = 'kt.agency_role.centres.' . $agencyId;
        if ($req && $req->attributes->has($key)) return $req->attributes->get($key);
        $ids = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->map(fn ($v) => (int) $v)->all();
        if ($req) $req->attributes->set($key, $ids);
        return $ids;
    }

    /** Narrow a role_assignments query (any alias-free builder) to the active agency, keeping platform_admin rows. */
    public static function constrain($query, ?Request $request = null)
    {
        $request = $request ?: (app()->bound('request') ? request() : null);
        $agency = self::activeAgency($request);
        if ($agency === null) return $query;
        $centres = self::centreIds($agency);
        return $query->where(function ($w) use ($agency, $centres) {
            $w->where('role', 'platform_admin')
              ->orWhere('agency_id', $agency)
              ->orWhereIn('centre_id', $centres ?: [0]);
        });
    }

    /** Role names this request's user holds in the active agency (all of them when there is no active agency). */
    public static function names(Request $request): array
    {
        $agency = self::activeAgency($request);
        $rows = UserRoles::all($request);
        if ($agency !== null) {
            $centres = self::centreIds($agency);
            $rows = array_filter($rows, fn ($r) => $r->role === 'platform_admin' || (int) $r->agency_id === $agency
                || ($r->centre_id && in_array((int) $r->centre_id, $centres, true)));
        }
        return array_values(array_unique(array_map(fn ($r) => (string) $r->role, $rows)));
    }
}
