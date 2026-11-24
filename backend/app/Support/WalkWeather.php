<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * THE WEATHER ON A WALK (2026-11-02, Anthony: "add some details about the weather in that area as well and include
 * this in the daily summary to parents").
 *
 * Where: the walk's first GPS ping (Walk Watch), else the centre's saved latitude/longitude. When: the middle of the
 * walk, in the agency's timezone. Source: Open-Meteo (free, no key, HTTPS), hourly values for that day. The answer is
 * kept per trip for 60 days, so every family on the walk reads the same weather and a re-send makes no new call.
 * Returns null when there is no place, no answer, or the call fails: a missing forecast never costs anyone their summary.
 */
final class WalkWeather
{
    /** WMO weather codes (Open-Meteo) -> [emoji, words]. */
    private const CODES = [
        0 => ['☀️', 'Clear skies'], 1 => ['🌤️', 'Mostly sunny'], 2 => ['⛅', 'Partly cloudy'], 3 => ['☁️', 'Cloudy'],
        45 => ['🌫️', 'Foggy'], 48 => ['🌫️', 'Freezing fog'],
        51 => ['🌦️', 'Light drizzle'], 53 => ['🌦️', 'Drizzle'], 55 => ['🌧️', 'Heavy drizzle'],
        56 => ['🌧️', 'Freezing drizzle'], 57 => ['🌧️', 'Freezing drizzle'],
        61 => ['🌦️', 'Light rain'], 63 => ['🌧️', 'Rain'], 65 => ['🌧️', 'Heavy rain'],
        66 => ['🌧️', 'Freezing rain'], 67 => ['🌧️', 'Freezing rain'],
        71 => ['🌨️', 'Light snow'], 73 => ['🌨️', 'Snow'], 75 => ['❄️', 'Heavy snow'], 77 => ['🌨️', 'Snow grains'],
        80 => ['🌦️', 'Rain showers'], 81 => ['🌧️', 'Rain showers'], 82 => ['⛈️', 'Heavy showers'],
        85 => ['🌨️', 'Snow showers'], 86 => ['❄️', 'Heavy snow showers'],
        95 => ['⛈️', 'Thunderstorms'], 96 => ['⛈️', 'Thunderstorms with hail'], 99 => ['⛈️', 'Thunderstorms with hail'],
    ];

    /** [emoji, words] for a WMO weather code (also used by WeatherForecast). */
    public static function describe(int $code): array
    {
        return self::CODES[$code] ?? ['🌡️', 'Weather'];
    }

    /** ['emoji','summary','temp_c','feels_c','wind_kmh','precip_mm','area'] or null. */
    public static function forTrip(int $tripId): ?array
    {
        if ($tripId <= 0) return null;
        $key = 'kt.walk.weather.v1.' . $tripId;
        $hit = Cache::get($key);
        if (is_array($hit)) return $hit;

        try {
            $trip = DB::table('field_trips as t')->leftJoin('centres as ce', 'ce.id', '=', 't.centre_id')
                ->where('t.id', $tripId)
                ->first(['t.id', 't.agency_id', 't.trip_date', 't.depart_time', 't.return_time',
                    'ce.city', 'ce.latitude as c_lat', 'ce.longitude as c_lon']);
            if (! $trip || ! $trip->trip_date) return null;

            $ping = DB::table('field_trip_pings')->where('field_trip_id', $tripId)->orderBy('id')->first(['lat', 'lon']);
            $lat = $ping->lat ?? $trip->c_lat;
            $lon = $ping->lon ?? $trip->c_lon;
            if ($lat === null || $lon === null || (float) $lat === 0.0) return null;

            $tz = AgencyTime::tz((int) $trip->agency_id) ?: 'America/Toronto';
            $date = Carbon::parse((string) $trip->trip_date, $tz)->toDateString();
            // depart/return are wall-clock times in the agency's zone; the middle of the walk picks the hour.
            $from = $trip->depart_time ? Carbon::parse($date . ' ' . $trip->depart_time, $tz) : null;
            $to = $trip->return_time ? Carbon::parse($date . ' ' . $trip->return_time, $tz) : null;
            $mid = $from && $to && $to->gt($from) ? $from->copy()->addSeconds(intdiv((int) $from->diffInSeconds($to), 2)) : ($from ?: Carbon::parse($date . ' 12:00', $tz));

            $res = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => round((float) $lat, 3), 'longitude' => round((float) $lon, 3),
                'hourly' => 'temperature_2m,apparent_temperature,weather_code,wind_speed_10m,precipitation',
                'start_date' => $date, 'end_date' => $date, 'timezone' => $tz, 'wind_speed_unit' => 'kmh',
            ]);
            if (! $res->ok()) return null;
            $h = $res->json('hourly') ?: [];
            $i = (int) $mid->format('G');
            if (! isset($h['time'][$i], $h['temperature_2m'][$i])) return null;

            $code = (int) ($h['weather_code'][$i] ?? -1);
            [$emoji, $words] = self::CODES[$code] ?? ['🌡️', 'Weather'];
            $out = [
                'emoji' => $emoji, 'summary' => $words,
                'temp_c' => (int) round((float) $h['temperature_2m'][$i]),
                'feels_c' => isset($h['apparent_temperature'][$i]) ? (int) round((float) $h['apparent_temperature'][$i]) : null,
                'wind_kmh' => isset($h['wind_speed_10m'][$i]) ? (int) round((float) $h['wind_speed_10m'][$i]) : null,
                'precip_mm' => isset($h['precipitation'][$i]) ? round((float) $h['precipitation'][$i], 1) : null,
                'area' => trim((string) ($trip->city ?? '')) ?: null,
            ];
            Cache::put($key, $out, now()->addDays(60));
            return $out;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Walk weather unavailable', ['trip' => $tripId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** One line for an email: "☀️ 14°C (feels like 12°C) · Clear skies · light wind · no rain · near Mono". */
    public static function line(array $w): string
    {
        $bits = [$w['emoji'] . ' ' . $w['temp_c'] . '°C'
            . ($w['feels_c'] !== null && abs($w['feels_c'] - $w['temp_c']) >= 2 ? ' (feels like ' . $w['feels_c'] . '°C)' : '')];
        $bits[] = $w['summary'];
        if ($w['wind_kmh'] !== null) {
            $k = $w['wind_kmh'];
            $bits[] = $k < 6 ? 'calm' : ($k < 20 ? 'light wind' : ($k < 40 ? 'breezy, ' . $k . ' km/h' : 'windy, ' . $k . ' km/h'));
        }
        if ($w['precip_mm'] !== null) $bits[] = $w['precip_mm'] > 0 ? $w['precip_mm'] . ' mm of rain/snow' : 'dry';
        if (! empty($w['area'])) $bits[] = 'in ' . $w['area'];
        return implode(' · ', $bits);
    }
}
