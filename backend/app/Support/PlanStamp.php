<?php

namespace App\Support;

use App\Http\Controllers\Api\FeatureFlagController;
use Illuminate\Support\Facades\DB;

/**
 * WHAT THE PORTAL SHOWS FOLLOWS THE LICENCE AT ONCE (2026-11-26, Anthony: added the HR add-on to iLearn in
 * Platform -> Licences, opened HR and got "Not included in this plan"; "full sweep").
 *
 * The menu, the tiles, search, the right panel, the phone bars, the Upgrade popup and the route guard all read
 * hidden_screens / locked_screens / ai_bundle from the user saved in the tab (kt_user), and that copy was refreshed
 * only when the page booted. A licence line added, removed, cancelled, renewed or converted, or a feature switched
 * in Platform -> Agencies, reached the API at once but no open tab: it kept the plan it was given until a reload.
 *
 * This is a short fingerprint of everything those fields are computed from, for the agency in view:
 *   - every agency_licences row of the agency (status, kind, plan, feature keys, dates - whole rows),
 *   - the agency's explicit feature switches (agencies.feature_flags),
 *   - AI switched off for it (AiConfig::offFor, ai_bundle),
 *   - the agency's today (a paid-through feature ends on a date, with no row changing),
 *   - the person's roles (Play Spark and the Upgrade list depend on them),
 *   - the feature catalogue itself (a deploy that moves a screen into a feature).
 * GET /badges carries it as plan_v (every open tab asks every 10 s) and /auth/me carries the one its answer was
 * computed with. When they differ, kt-badges.js raises kt:plan-changed and kt-topbar.js asks /auth/me once and
 * adopts the new plan. It only has to CHANGE when the answer may have changed; the client compares the real
 * fields before redrawing anything, so a false alarm costs one /auth/me.
 *
 * Two queries, no cache: a cached plan is the bug this exists to remove.
 */
final class PlanStamp
{
    /** The agency whose plan /auth/me answers with: the role's (or a parent's family's) agency, or X-Active-Agency-Id
     *  for somebody entitled to use it. formatUser() and GET /badges both ask here, so the two can never disagree. */
    public static function applyHeader(int $userId, ?int $default): ?int
    {
        $featureAgencyId = $default ?: null;
        try {
            $hdr = (int) request()->header('X-Active-Agency-Id');
            if ($hdr) {
                $mayUse = DB::table('role_assignments')
                    ->where('user_id', $userId)->where('active', true)
                    ->where(function ($q) use ($hdr) {
                        $q->where('agency_id', $hdr)->orWhere('role', 'platform_admin');
                    })->exists();
                if ($mayUse) {
                    $featureAgencyId = $hdr;
                }
            }
        } catch (\Throwable $e) {
            // No request context (a console render of this payload) - keep the role's agency.
        }

        return $featureAgencyId;
    }

    /** The same default formatUser() works out: the first active role's agency, else a parent's family's agency. */
    public static function featureAgency(int $userId): ?int
    {
        $agencyId = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->whereNotNull('agency_id')->orderBy('id')->value('agency_id');
        $default = $agencyId ?: DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('g.user_id', $userId)
            ->value('c.agency_id');

        return self::applyHeader($userId, $default ? (int) $default : null);
    }

    public static function forAgency(?int $agencyId, array $roles = []): string
    {
        static $catalogue = null;
        $catalogue ??= md5(json_encode(FeatureFlagController::FEATURES));
        $roles = array_values(array_unique($roles));
        sort($roles);
        $parts = [$agencyId ?: 0, implode(',', $roles), $catalogue];
        if ($agencyId) {
            $parts[] = json_encode(DB::table('agency_licences')->where('agency_id', $agencyId)->orderBy('id')->get());
            $parts[] = (string) DB::table('agencies')->where('id', $agencyId)->value('feature_flags');
            $parts[] = rescue(fn () => AiConfig::offFor($agencyId) ? 'ai-off' : 'ai', '', false);
            $parts[] = rescue(fn () => AgencyTime::todayDate($agencyId)->toDateString(), '', false);
        }

        return substr(sha1(implode('|', $parts)), 0, 12);
    }

    /** The stamp for this request's person, in the agency they are looking at. */
    public static function forUser(int $userId): string
    {
        $roles = DB::table('role_assignments')->where('user_id', $userId)->where('active', true)->pluck('role')->all();

        return self::forAgency(self::featureAgency($userId), $roles);
    }
}
