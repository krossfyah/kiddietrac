<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR PHASE 3, ITEM 11: CLOCK-IN UPGRADES (2026-11-26). HR add-on only; agencies without HR are untouched.
 *
 * GEOFENCE. Optional, per centre or home: centres.settings.geofence = {radius_m, lat, lng} (lat / lng picked on a map, or
 * the centre's own latitude / longitude from its address). Agency rule agencies.settings.hr_clock.geofence:
 *   off   = never asked;
 *   flag  = clock-ins outside the fence (or without a location) are allowed and flagged for managers;
 *   block = clock-ins outside the fence (or without a location) are refused with a plain reason.
 * The location is asked ONLY when clocking in at a fenced centre, after the consent text, and is NEVER stored: the
 * punch keeps only time_punches.geo_status (inside | outside | no_location) and geo_distance_m. Kiosk and automatic
 * punches are not checked (the kiosk is at the centre). Clocking out is never blocked.
 *
 * OVERTIME. hr_clock.overtime_hours (default 44, the Ontario ESA weekly threshold) and warn_at_hours (default 40).
 * hr:overtime-warn (hourly) tells the person and their centre's directors once a week at each level: worked hours this
 * week (agency week, closed punches + the open one) reaching warn_at, then reaching the threshold. Contractors
 * (users.is_contractor or HR type contract) are skipped unless the agency turns skip_contractors off.
 */
final class HrClock
{
    public const MODES = ['off', 'flag', 'block'];
    public const CONSENT = 'Your centre checks that you are at work when you clock in. KiddieTrac asks your phone or computer for its location once, for this clock-in only, checks it against your centre, and keeps only "at the centre" or "not at the centre" with the distance. Your location is never tracked or stored.';

    public static function settings(int $agencyId): array
    {
        $s = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true);
        $c = is_array($s['hr_clock'] ?? null) ? $s['hr_clock'] : [];
        $mode = in_array($c['geofence'] ?? null, self::MODES, true) ? $c['geofence'] : 'off';
        $ot = isset($c['overtime_hours']) && is_numeric($c['overtime_hours']) ? (float) $c['overtime_hours'] : 44.0;
        $warn = isset($c['warn_at_hours']) && is_numeric($c['warn_at_hours']) ? (float) $c['warn_at_hours'] : 40.0;

        return ['geofence' => $mode, 'overtime_on' => ($c['overtime_on'] ?? true) !== false, 'overtime_hours' => $ot,
            'warn_at_hours' => min($warn, $ot), 'skip_contractors' => ($c['skip_contractors'] ?? true) !== false];
    }

    public static function saveSettings(int $agencyId, array $in): void
    {
        DB::transaction(function () use ($agencyId, $in) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $s = json_decode((string) ($row->settings ?? ''), true);
            $s = is_array($s) ? $s : [];
            $s['hr_clock'] = ['geofence' => in_array($in['geofence'] ?? 'off', self::MODES, true) ? $in['geofence'] : 'off',
                'overtime_on' => (bool) ($in['overtime_on'] ?? true), 'overtime_hours' => round((float) $in['overtime_hours'], 2),
                'warn_at_hours' => round(min((float) $in['warn_at_hours'], (float) $in['overtime_hours']), 2), 'skip_contractors' => (bool) ($in['skip_contractors'] ?? true)];
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s)]);
        });
    }

    /** A centre's fence: {radius_m, lat, lng, from_address} or null (no fence). */
    public static function fence(object $centre): ?array
    {
        $s = json_decode((string) ($centre->settings ?? ''), true);
        $g = is_array($s['geofence'] ?? null) ? $s['geofence'] : null;
        if (! $g || empty($g['radius_m'])) return null;
        $lat = $g['lat'] ?? null;
        $lng = $g['lng'] ?? null;
        $fromAddress = $lat === null || $lng === null;
        if ($fromAddress) { $lat = $centre->latitude ?? null; $lng = $centre->longitude ?? null; }
        if ($lat === null || $lng === null) return null;

        return ['radius_m' => (int) $g['radius_m'], 'lat' => (float) $lat, 'lng' => (float) $lng, 'from_address' => $fromAddress];
    }

    public static function saveFence(int $centreId, ?array $fence): void
    {
        DB::transaction(function () use ($centreId, $fence) {
            $row = DB::table('centres')->where('id', $centreId)->lockForUpdate()->first(['settings']);
            $s = json_decode((string) ($row->settings ?? ''), true);
            $s = is_array($s) ? $s : [];
            if ($fence) $s['geofence'] = $fence; else unset($s['geofence']);
            DB::table('centres')->where('id', $centreId)->update(['settings' => json_encode($s)]);
        });
    }

    public static function metres(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return (int) round($r * 2 * atan2(sqrt($a), sqrt(1 - $a)));
    }

    /**
     * The clock-in check (CareController::punch, clocking IN only). Null = nothing to do. Otherwise either
     * ['refuse' => body, 'status' => http] or ['row' => columns to set on the new punch].
     */
    public static function check(Request $request, int $centreId): ?array
    {
        if (in_array((string) $request->input('source'), ['kiosk', 'auto'], true)) return null;
        if (! Schema::hasColumn('time_punches', 'geo_status')) return null;
        $centre = DB::table('centres')->where('id', $centreId)->first(['id', 'agency_id', 'name', 'settings', 'latitude', 'longitude']);
        if (! $centre || ! Features::on((int) $centre->agency_id, 'hr')) return null;
        $mode = self::settings((int) $centre->agency_id)['geofence'];
        if ($mode === 'off' || ! ($f = self::fence($centre))) return null;

        $lat = $request->input('geo_lat');
        $lng = $request->input('geo_lng');
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            if (! $request->boolean('geo_declined')) {
                return ['refuse' => ['message' => 'Share your location to clock in at ' . $centre->name . '.', 'location_required' => true,
                    'consent' => self::CONSENT, 'mode' => $mode, 'centre' => $centre->name], 'status' => 428];
            }
            if ($mode === 'block') {
                return ['refuse' => ['message' => $centre->name . ' checks your location when you clock in. Allow location for KiddieTrac and try again, or ask your director to clock you in.',
                    'geofence' => true], 'status' => 422];
            }

            return ['row' => ['geo_status' => 'no_location', 'geo_distance_m' => null]];
        }
        $d = self::metres((float) $lat, (float) $lng, $f['lat'], $f['lng']);
        $acc = is_numeric($request->input('geo_accuracy')) ? min(150, max(0, (int) $request->input('geo_accuracy'))) : 0;
        if ($d <= $f['radius_m'] + $acc) return ['row' => ['geo_status' => 'inside', 'geo_distance_m' => $d]];
        if ($mode === 'block') {
            return ['refuse' => ['message' => 'You seem to be about ' . self::far($d) . ' from ' . $centre->name . '. Clock in when you get there, or ask your director to clock you in.',
                'geofence' => true, 'distance_m' => $d], 'status' => 422];
        }

        return ['row' => ['geo_status' => 'outside', 'geo_distance_m' => $d]];
    }

    public static function far(int $m): string
    {
        return $m >= 1000 ? round($m / 1000, 1) . ' km' : $m . ' m';
    }

    /** Contractors for the overtime skip: users.is_contractor or the HR employment type. */
    public static function contractorIds(int $agencyId, array $userIds): array
    {
        $a = Schema::hasColumn('users', 'is_contractor') ? DB::table('users')->whereIn('id', $userIds ?: [0])->where('is_contractor', 1)->pluck('id')->all() : [];
        $b = Schema::hasTable('hr_employee_files') ? DB::table('hr_employee_files')->where('agency_id', $agencyId)->whereIn('user_id', $userIds ?: [0])
            ->where('employment_type', 'contract')->pluck('user_id')->all() : [];

        return array_values(array_unique(array_map('intval', array_merge($a, $b))));
    }

    /** Worked hours this agency week per person: closed punches + the open one up to now. [uid => hours] */
    public static function weekHours(int $agencyId, ?array $userIds = null): array
    {
        $from = AgencyTime::boundaryUtc($agencyId, 'week');
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $q = DB::table('time_punches')->whereIn('centre_id', $centres ?: [0])->where('punched_in_at', '>=', $from->format('Y-m-d H:i:s'));
        if ($userIds !== null) $q->whereIn('user_id', $userIds ?: [0]);
        $now = Carbon::now('UTC');
        $out = [];
        foreach ($q->get(['user_id', 'punched_in_at', 'punched_out_at']) as $p) {
            $in = Carbon::parse($p->punched_in_at, 'UTC');
            $end = $p->punched_out_at ? Carbon::parse($p->punched_out_at, 'UTC') : $now;
            $out[(int) $p->user_id] = ($out[(int) $p->user_id] ?? 0) + max(0, $in->diffInMinutes($end, false));
        }

        return array_map(fn ($m) => round($m / 60, 2), $out);
    }

    /**
     * Hours still SCHEDULED this agency week, from now on (Anthony 2026-11-26: overtime counts worked + scheduled).
     * shifts hold agency wall-clock time; only the part of a shift after "now" counts, so an open punch (counted up to
     * now by weekHours) and the rest of the same shift never overlap. Cancelled shifts do not count.
     */
    public static function scheduledLeft(int $agencyId, ?array $userIds = null): array
    {
        $tz = AgencyTime::tz($agencyId);
        $now = Carbon::now($tz);
        $weekEnd = $now->copy()->startOfWeek()->addWeek();
        $centres = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $q = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->whereIn('r.centre_id', $centres ?: [0])
            ->whereNotIn('s.status', ['cancelled', 'canceled', 'declined'])
            ->where('s.ends_at', '>', $now->format('Y-m-d H:i:s'))->where('s.starts_at', '<', $weekEnd->format('Y-m-d H:i:s'));
        if ($userIds !== null) $q->whereIn('s.user_id', $userIds ?: [0]);
        $out = [];
        foreach ($q->get(['s.user_id', 's.starts_at', 's.ends_at']) as $s) {
            $a = Carbon::parse($s->starts_at, $tz)->max($now);
            $b = Carbon::parse($s->ends_at, $tz)->min($weekEnd);
            if ($b->gt($a)) $out[(int) $s->user_id] = ($out[(int) $s->user_id] ?? 0) + $a->diffInMinutes($b);
        }

        return array_map(fn ($m) => round($m / 60, 2), $out);
    }
}
