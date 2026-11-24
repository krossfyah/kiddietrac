<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * THE NEXT FIVE DAYS AT A CENTRE, AND WHAT TO DRESS FOR (2026-11-02, Anthony: "add 5 day forecast in the parent
 * emails and let AI predict if they need to dress their kids appropriately going forward (wire this up)").
 *
 * forCentre(): Open-Meteo daily forecast (free, no key) at the centre's saved latitude/longitude, the five days
 * after $from in the agency's zone. One call per centre per day (cached 3 h), shared by every family there.
 * dressNote(): the AI Bundle writes 2-3 practical sentences from those five days (AiSummaries 'dress'), once per
 * centre per day and with no child or family data in it; without the bundle, or when the AI does not answer, a plain
 * rule-based line is used instead (dressRules). Both return null when there is nothing to say: a missing forecast
 * never costs anyone their summary.
 */
final class WeatherForecast
{
    /** ['unit' => 'C'|'F', 'area' => city, 'days' => [[date, dow, emoji, summary, hi, lo, rain_pct, precip_mm, wind_kmh, uv, code], ...]] */
    public static function forCentre(int $centreId, Carbon $from): ?array
    {
        if ($centreId <= 0) return null;
        $start = $from->copy()->addDay()->toDateString();
        $key = 'kt.forecast.v1.' . $centreId . '.' . $start;
        $hit = Cache::get($key);
        if (is_array($hit)) return $hit;

        try {
            $c = DB::table('centres')->where('id', $centreId)->first(['agency_id', 'city', 'country', 'latitude', 'longitude']);
            if (! $c || $c->latitude === null || $c->longitude === null || (float) $c->latitude === 0.0) return null;
            $us = in_array(strtoupper(trim((string) $c->country)), ['US', 'USA', 'UNITED STATES', 'UNITED STATES OF AMERICA'], true);
            $tz = AgencyTime::tz((int) $c->agency_id) ?: 'America/Toronto';

            $res = Http::timeout(5)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude' => round((float) $c->latitude, 3), 'longitude' => round((float) $c->longitude, 3),
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,precipitation_probability_max,precipitation_sum,wind_speed_10m_max,uv_index_max',
                'start_date' => $start, 'end_date' => $from->copy()->addDays(5)->toDateString(),
                'timezone' => $tz, 'wind_speed_unit' => 'kmh',
                'temperature_unit' => $us ? 'fahrenheit' : 'celsius',
            ]);
            if (! $res->ok()) return null;
            $d = $res->json('daily') ?: [];
            $days = [];
            foreach (($d['time'] ?? []) as $i => $date) {
                if (! isset($d['temperature_2m_max'][$i], $d['temperature_2m_min'][$i])) continue;
                $code = (int) ($d['weather_code'][$i] ?? -1);
                [$emoji, $words] = WalkWeather::describe($code);
                $days[] = [
                    'date' => $date, 'dow' => Carbon::parse($date, $tz)->format('D'),
                    'emoji' => $emoji, 'summary' => $words, 'code' => $code,
                    'hi' => (int) round((float) $d['temperature_2m_max'][$i]), 'lo' => (int) round((float) $d['temperature_2m_min'][$i]),
                    'rain_pct' => isset($d['precipitation_probability_max'][$i]) ? (int) $d['precipitation_probability_max'][$i] : null,
                    'precip_mm' => isset($d['precipitation_sum'][$i]) ? round((float) $d['precipitation_sum'][$i], 1) : null,
                    'wind_kmh' => isset($d['wind_speed_10m_max'][$i]) ? (int) round((float) $d['wind_speed_10m_max'][$i]) : null,
                    'uv' => isset($d['uv_index_max'][$i]) ? round((float) $d['uv_index_max'][$i], 1) : null,
                ];
            }
            if (count($days) < 3) return null;
            $out = ['unit' => $us ? 'F' : 'C', 'area' => trim((string) $c->city) ?: null, 'days' => array_slice($days, 0, 5)];
            Cache::put($key, $out, now()->addHours(3));
            return $out;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Forecast unavailable', ['centre' => $centreId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** ['text' => ..., 'ai' => bool] or null. Same answer for every family at the centre that day. */
    public static function dressNote(int $agencyId, int $centreId, array $fc): ?array
    {
        $key = 'kt.dress.v1.' . $centreId . '.' . ($fc['days'][0]['date'] ?? '');
        $hit = Cache::get($key);
        if (is_array($hit)) return $hit;

        $facts = ['temperature_unit' => '°' . $fc['unit'], 'wind_unit' => 'km/h', 'area' => $fc['area'],
            'days' => array_map(fn ($d) => ['day' => $d['dow'] . ' ' . $d['date'], 'conditions' => $d['summary'],
                'high' => $d['hi'], 'low' => $d['lo'], 'chance_of_rain_or_snow_pct' => $d['rain_pct'],
                'rain_or_snow_mm' => $d['precip_mm'], 'max_wind' => $d['wind_kmh'], 'uv_index' => $d['uv']], $fc['days'])];
        $ai = AiSummaries::write($agencyId, 'dress', $facts);
        $out = $ai ? ['text' => $ai, 'ai' => true] : (($r = self::dressRules($fc)) ? ['text' => $r, 'ai' => false] : null);
        // Keep an AI answer all day; a rule line only briefly, so the AI is asked again once it can answer.
        if ($out) Cache::put($key, $out, $out['ai'] ? now()->addHours(20) : now()->addMinutes(30));
        return $out;
    }

    /** Plain advice when the AI is not available: the days that need something extra. */
    public static function dressRules(array $fc): ?string
    {
        $c = fn (int $t) => $fc['unit'] === 'F' ? ($t - 32) * 5 / 9 : $t;
        $wet = []; $snow = []; $cold = []; $sun = [];
        foreach ($fc['days'] as $d) {
            $isSnow = in_array($d['code'], [71, 73, 75, 77, 85, 86], true);
            if ($isSnow) $snow[] = $d['dow'];
            elseif (($d['rain_pct'] ?? 0) >= 50 || ($d['precip_mm'] ?? 0) >= 2) $wet[] = $d['dow'];
            if ($c($d['lo']) <= 2) $cold[] = $d['dow'];
            if (($d['uv'] ?? 0) >= 6 && $c($d['hi']) >= 20) $sun[] = $d['dow'];
        }
        $join = function (array $a) { return count($a) > 1 ? implode(', ', array_slice($a, 0, -1)) . ' and ' . end($a) : ($a[0] ?? ''); };
        $out = [];
        if ($snow) $out[] = 'Snow is likely on ' . $join($snow) . ': snow pants, boots, mitts and a warm hat.';
        if ($wet) $out[] = 'Rain is likely on ' . $join($wet) . ': a rain jacket, rain boots and a spare set of clothes.';
        if ($cold && ! $snow) $out[] = 'Cold mornings on ' . $join($cold) . ': a warm jacket, hat and mitts.';
        if ($sun) $out[] = 'Strong sun on ' . $join($sun) . ': a sun hat, and sunscreen before drop-off.';
        return $out ? implode(' ', $out) : null;
    }
}
