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

    /**
     * The agency's CURRENT DATE, as 'Y-m-d'.
     *
     * For comparing wall-clock dates - a last day, a departure date, an effective date.
     * now()->toDateString() is the UTC date, which from 8pm Toronto is already tomorrow;
     * every off-boarding path used it, so for the last four hours of each day a departure
     * booked for tomorrow was executed on the spot. (2026-08-27)
     */
    public static function today(?int $agencyId = null): string
    {
        return Carbon::now(self::tz($agencyId))->toDateString();
    }

    /** The agency's current date, resolved from a centre id. */
    public static function todayForCentre(?int $centreId): string
    {
        return Carbon::now(self::tzForCentre($centreId))->toDateString();
    }

    /**
     * The UTC instants bounding an agency-local day: [start, endExclusive).
     *
     * For querying anything stored as an INSTANT — check_events.occurred_at,
     * daily_events.occurred_at, time_punches — where "today" means the agency's day,
     * not UTC's.
     *
     * Why not whereDate(). whereDate compiles to DATE(col) = ?, which buckets by the
     * UTC date of the stored value, so it cannot express an agency day at all: passing
     * even the CORRECT agency date still selects Toronto 8:00pm the previous evening
     * through 7:59pm. Every evening pickup after 8pm landed on the following day.
     *
     * HALF-OPEN on purpose. `whereBetween` is inclusive at both ends, so an event at
     * exactly the next midnight would belong to two days at once. Use it as:
     *
     *     [$from, $to] = AgencyTime::dayRange($agencyId);
     *     ->where('occurred_at', '>=', $from)->where('occurred_at', '<', $to)
     *
     * DST needs no special handling: startOfDay() in the agency zone converted to UTC
     * is 04:00Z in summer and 05:00Z in winter on its own.
     *
     * @return array{0:string,1:string} UTC 'Y-m-d H:i:s' bounds
     */
    /** A calendar date as 'Y-m-d', from a string, a Carbon/DateTime, or null.
     *
     *  These helpers are handed `$today` (a Carbon) by the console commands and a
     *  'Y-m-d' request parameter by the controllers, and used to accept only the
     *  second — a TypeError that killed the caller outright.
     *
     *  Reducing to a bare date is what makes this safe, not just permissive: a Carbon
     *  carries its own timezone, and Carbon::parse() honours it, so passing one through
     *  would resolve a UTC instant in UTC and return the wrong day's bounds for the
     *  agency. Stripping to 'Y-m-d' leaves the agency zone as the only zone in play. */
    private static function dateOnly($date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        return substr((string) $date, 0, 10);
    }

    public static function dayRange(?int $agencyId = null, $date = null): array
    {
        $tz = self::tz($agencyId);
        $date = self::dateOnly($date);
        $start = $date
            ? Carbon::parse($date, $tz)->startOfDay()
            : Carbon::now($tz)->startOfDay();

        return [
            $start->copy()->setTimezone('UTC')->format('Y-m-d H:i:s'),
            $start->copy()->addDay()->setTimezone('UTC')->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The UTC instants bounding a RANGE of agency days: [start of $from, start of the
     * day after $to). For reports and digests that take an inclusive from..to.
     *
     * The end is the day AFTER $to precisely so that an inclusive range stays inclusive
     * while the comparison stays half-open — the alternative, '<=' on the last day's
     * midnight, would drop everything after midnight on the final day.
     */
    public static function spanRange(?int $agencyId, $from, $to): array
    {
        $from = self::dateOnly($from) ?? $from;
        $to   = self::dateOnly($to) ?? $to;
        return [
            self::dayRange($agencyId, $from)[0],
            self::dayRange($agencyId, $to)[1],
        ];
    }

    /** A from..to span, resolved in a centre's own agency. */
    public static function spanRangeForCentre(?int $centreId, $from, $to): array
    {
        $agencyId = $centreId
            ? DB::table('centres')->where('id', $centreId)->value('agency_id')
            : null;

        return self::spanRange($agencyId ? (int) $agencyId : null, $from, $to);
    }

    /** A day's bounds in the agency that the CHILD belongs to. */
    public static function dayRangeForChild(?int $childId, $date = null): array
    {
        $centreId = $childId
            ? DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                ->where('ch.id', $childId)->value('f.centre_id')
            : null;

        return self::dayRangeForCentre($centreId ? (int) $centreId : null, $date);
    }

    /** The same bounds, resolved from a room id (room → centre → agency). */
    public static function dayRangeForRoom(?int $roomId, $date = null): array
    {
        $centreId = $roomId
            ? DB::table('rooms')->where('id', $roomId)->value('centre_id')
            : null;

        return self::dayRangeForCentre($centreId ? (int) $centreId : null, $date);
    }

    /** The same bounds, resolved from a centre id. */
    public static function dayRangeForCentre(?int $centreId, $date = null): array
    {
        $agencyId = $centreId
            ? DB::table('centres')->where('id', $centreId)->value('agency_id')
            : null;

        return self::dayRange($agencyId ? (int) $agencyId : null, $date);
    }

    /**
     * Apply the agency-day bound to a query on an instant column.
     *
     * A named helper so the half-open comparison is written once. Callers that reach
     * for whereDate() on an instant are the bug this class exists to stop.
     */
    public static function scopeDay($query, string $column, ?int $agencyId = null, $date = null)
    {
        [$from, $to] = self::dayRange($agencyId, $date);

        return $query->where($column, '>=', $from)->where($column, '<', $to);
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
