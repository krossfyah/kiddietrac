<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * IS THIS FEATURE ON FOR THIS AGENCY? - for the code that has no route (2026-11-26).
 *
 * Anthony: "when agency is setup and addon's are added does this reflect the addon's and functionality per agency
 * setup correctly - full sweep here on current and future wiring with existing and new addon's".
 *
 * The API was gated (EnforcePlanFeatures maps each sold feature's paths) and the menu was gated (hidden_screens), but
 * almost no scheduled job asked: an agency without Immunizations still had immunization reminders emailed to its
 * parents, one without Marketing still sent drip campaigns, one without Accounting still synced to QuickBooks, and
 * one without Autopay still had cards charged at night. Every job that does a sold feature's work for an agency now
 * asks here first, with the same answer the API gives (EnforcePlanFeatures::allows -> the licence's plan level +
 * add-ons, explicit On/Off winning). kt:addon-coverage fails when a scheduled job is mapped to a feature and does not.
 *
 * Unknown or missing agency = on, the same "absent means allowed" rule as the catalogue: a job that cannot tell whose
 * row it holds keeps doing what it did. AI has its own door (AiBundle::on, which also honours "AI switched off").
 */
final class Features
{
    /** @var array<string,bool> per-process memo: a nightly run asks about the same agency once per row */
    private static array $memo = [];

    public static function on(?int $agencyId, string $key): bool
    {
        if (! $agencyId) {
            return true;
        }
        $k = $agencyId . ':' . $key;
        if (! array_key_exists($k, self::$memo)) {
            try {
                self::$memo[$k] = \App\Http\Middleware\EnforcePlanFeatures::allows($agencyId, $key);
            } catch (\Throwable $e) {
                self::$memo[$k] = true;
            }
        }

        return self::$memo[$k];
    }

    /** True when the job must skip this agency; leaves a trail (a silent skip is its own hazard). */
    public static function skips(?int $agencyId, string $key, string $what = ''): bool
    {
        if (self::on($agencyId, $key)) {
            return false;
        }
        try {
            Log::info('FEATURE OFF: ' . ($what !== '' ? $what : $key) . ' skipped', ['agency' => $agencyId, 'feature' => $key]);
        } catch (\Throwable $e) {
        }

        return true;
    }

    public static function forCentre(?int $centreId, string $key): bool
    {
        $aid = $centreId ? \Illuminate\Support\Facades\DB::table('centres')->where('id', $centreId)->value('agency_id') : null;

        return self::on($aid ? (int) $aid : null, $key);
    }

    /** Tests and long runs that change a licence mid-process. */
    public static function forget(): void
    {
        self::$memo = [];
    }
}
