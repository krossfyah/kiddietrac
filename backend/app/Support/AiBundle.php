<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * The AI Bundle (2026-10-28, KT-ADD-0006, $25/month, feature key `ai_tools`).
 *
 * Anthony: "add a new add-on bundle for AI bundle - $25.00, utilizing AI to generate daily summaries, emails and
 * assisting with observation entries". His choices: $25 per month; NO plan includes it (plan_min 'addon', like SMS);
 * the AI that used to be free moves into it, and the agencies already using AI keep it on a complimentary line.
 *
 * Route-level gating lives in EnforcePlanFeatures (ai_tools paths). This is for the AI that runs inside a shared
 * endpoint or a scheduled job - the parent end-of-day write-up, the parent day story, photo tagging - where the
 * feature is not its own route. Without the bundle each one does exactly what it does when the AI is unavailable:
 * the email still goes (without the AI note), the day story is the templated one.
 */
final class AiBundle
{
    /** Per-request memo: the same agency is asked about once per child in the nightly run. */
    private static array $memo = [];

    public static function on(?int $agencyId): bool
    {
        if (! $agencyId) {
            return false;
        }
        // AI switched off for this agency in Platform → AI usage (test agencies): no AI, whatever the licence says.
        if (AiConfig::offFor($agencyId)) {
            return false;
        }
        if (! array_key_exists($agencyId, self::$memo)) {
            try {
                self::$memo[$agencyId] = (bool) (\App\Http\Controllers\Api\FeatureFlagController::effectiveFor($agencyId)['ai_tools'] ?? false);
            } catch (\Throwable $e) {
                self::$memo[$agencyId] = false;
            }
        }

        return self::$memo[$agencyId];
    }

    public static function forChild(int $childId): bool
    {
        $agencyId = DB::table('children as ch')->join('families as fa', 'fa.id', '=', 'ch.family_id')
            ->join('centres as ce', 'ce.id', '=', 'fa.centre_id')->where('ch.id', $childId)->value('ce.agency_id');

        return self::on($agencyId ? (int) $agencyId : null);
    }

    public static function forCentre(?int $centreId): bool
    {
        $agencyId = $centreId ? DB::table('centres')->where('id', $centreId)->value('agency_id') : null;

        return self::on($agencyId ? (int) $agencyId : null);
    }
}
