<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Per-agency display timezone.
 *
 * app.timezone is UTC and every occurred_at is stored in UTC, but children's
 * clock-in / activity times must be SHOWN in the agency's local wall-clock
 * (Eastern for all current Ontario agencies). Formatting a UTC timestamp with
 * ->format('g:i A') straight off gave UTC times (an 8:49 PM check-in read as
 * 12:49 AM). Everything user-facing that prints an event time should route the
 * value through fmt() so it lands in the agency's zone.
 */
final class AgencyTime
{
    private const DEFAULT_TZ = 'America/Toronto';

    /** Cache so a request that formats many rows hits the DB once per agency. */
    private static array $cache = [];

    /** Display timezone for an agency (settings.timezone, else Ontario default). */
    public static function tz(?int $agencyId = null): string
    {
        if (! $agencyId) {
            return self::DEFAULT_TZ;
        }
        if (isset(self::$cache[$agencyId])) {
            return self::$cache[$agencyId];
        }
        $settings = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $arr = $settings ? (json_decode($settings, true) ?: []) : [];
        $tz = $arr['timezone'] ?? null;
        if (! $tz || ! in_array($tz, timezone_identifiers_list(), true)) {
            $tz = self::DEFAULT_TZ;
        }
        return self::$cache[$agencyId] = $tz;
    }

    /** Display timezone resolved from a centre id (centre → agency). */
    public static function tzForCentre(?int $centreId): string
    {
        if (! $centreId) {
            return self::DEFAULT_TZ;
        }
        $agencyId = DB::table('centres')->where('id', $centreId)->value('agency_id');
        return self::tz($agencyId ? (int) $agencyId : null);
    }

    /** Format a UTC-stored timestamp in the given zone. Null-safe. */
    public static function fmt($ts, string $tz, string $format = 'g:i A'): ?string
    {
        if (! $ts) {
            return null;
        }
        return Carbon::parse($ts, 'UTC')->setTimezone($tz)->format($format);
    }
}
