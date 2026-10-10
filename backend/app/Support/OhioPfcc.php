<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * OHIO PUBLICLY FUNDED CHILD CARE (PFCC) — the rules in one place (2026-10-10).
 *
 * Source: OAC 5180:2-16 and the Ohio TAP FAQ (gap analysis, 2026-10-09). What is VERIFIED:
 * weeks run Sunday–Saturday; units of care are hourly (under 10 h/week), part-time (10–33) and
 * full-time (33+); the family pays a weekly copay under a signed agreement and non-payment past
 * 2 weeks is reported within 3; attendance is matched in TAP (no API) and unsubmitted matched
 * attendance is swept after 4 weeks. What is NOT verified, so it is a setting: the absent-day
 * cap per half-year (sources say 10 or 20; default 10) and every provider rate (agency-entered).
 *
 * Active only for agencies whose jurisdiction is US-OH, or that switch it on in the PFCC
 * settings. Ontario agencies, CWELCC and the existing subsidies are never read or changed here.
 */
final class OhioPfcc
{
    public const KEY = 'ohio_pfcc';
    public const UNITS = ['hourly' => 'Hourly (under 10 h/week)', 'part_time' => 'Part-time (10–33 h/week)', 'full_time' => 'Full-time (33+ h/week)'];
    public const DEFAULTS = [
        'pfcc_enabled' => false,      // force on for a non-Ohio agency
        'sutq_enabled' => false,      // force on for a non-Ohio agency
        'absent_cap' => 10,           // per child per half-year (unverified: 10 vs 20)
        'alert_unpaid_weeks' => 2,
        'sutq_level' => 'bronze',     // bronze | silver | gold — assessments twice a year from silver
    ];

    public static function settings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        $mine = is_array($s[self::KEY] ?? null) ? $s[self::KEY] : [];

        return array_merge(self::DEFAULTS, array_intersect_key($mine, self::DEFAULTS));
    }

    public static function saveSettings(int $agencyId, array $next): array
    {
        $clean = array_merge(self::settings($agencyId), array_intersect_key($next, self::DEFAULTS));
        $clean['absent_cap'] = max(1, min(60, (int) $clean['absent_cap']));
        $clean['alert_unpaid_weeks'] = max(1, min(8, (int) $clean['alert_unpaid_weeks']));
        $clean['pfcc_enabled'] = (bool) $clean['pfcc_enabled'];
        $clean['sutq_enabled'] = (bool) $clean['sutq_enabled'];
        $clean['sutq_level'] = in_array($clean['sutq_level'], ['bronze', 'silver', 'gold'], true) ? $clean['sutq_level'] : 'bronze';
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });

        return $clean;
    }

    public static function isOhio(int $agencyId): bool
    {
        return Jurisdictions::codeFor($agencyId) === 'US-OH';
    }

    public static function pfccOn(int $agencyId): bool
    {
        return self::isOhio($agencyId) || self::settings($agencyId)['pfcc_enabled'];
    }

    public static function sutqOn(int $agencyId): bool
    {
        return self::isOhio($agencyId) || self::settings($agencyId)['sutq_enabled'];
    }

    /** The Sunday that starts the TAP week containing $date (agency-local date string). */
    public static function weekStart(string $date): string
    {
        $d = Carbon::parse($date);

        return $d->copy()->subDays($d->dayOfWeek)->toDateString();   // dayOfWeek: 0 = Sunday
    }

    /** [from, to, label] of the half-year containing $date (Jan–Jun, Jul–Dec). */
    public static function halfYear(string $date): array
    {
        $d = Carbon::parse($date);
        $first = $d->month <= 6;
        $from = $d->copy()->setDate($d->year, $first ? 1 : 7, 1)->toDateString();
        $to = $d->copy()->setDate($d->year, $first ? 6 : 12, $first ? 30 : 31)->toDateString();

        return [$from, $to, $d->year . ($first ? '-H1' : '-H2')];
    }

    /** Active authorization for a child on a date. */
    public static function authorizationFor(int $childId, string $date): ?object
    {
        return DB::table('pfcc_authorizations')->where('child_id', $childId)->whereNull('ended_at')
            ->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->orderByDesc('starts_on')->first();
    }

    /** The provider's weekly amount for an authorization, and what the state pays after copay. */
    public static function weekly(object $a): array
    {
        $gross = round((float) $a->units_per_week * (float) $a->rate_per_unit, 2);
        $copay = round((float) $a->weekly_copay, 2);

        return ['gross' => $gross, 'copay' => min($copay, $gross), 'state' => max(0, round($gross - $copay, 2))];
    }

    /** Most hours a week a unit allows (null = no upper bound). */
    public static function unitMaxHours(object $a): ?float
    {
        return match ($a->care_unit) {
            'hourly' => min(9.99, (float) $a->units_per_week ?: 9.99),
            'part_time' => 33.0,
            default => null,
        };
    }

    /** Make sure a weekly copay row exists for every finished or current week of an authorization. */
    public static function ensureCopayWeeks(object $a, string $today): void
    {
        if ((float) $a->weekly_copay <= 0) return;
        $start = Carbon::parse(self::weekStart((string) $a->starts_on));
        $last = Carbon::parse(self::weekStart($a->ends_on && $a->ends_on < $today ? (string) $a->ends_on : $today));
        $n = 0;
        for ($w = $start->copy(); $w->lte($last) && $n < 260; $w->addWeek(), $n++) {
            DB::table('pfcc_copays')->insertOrIgnore(['agency_id' => (int) $a->agency_id, 'authorization_id' => (int) $a->id, 'week_start' => $w->toDateString(),
                'amount_due' => (float) $a->weekly_copay, 'amount_paid' => 0, 'status' => 'due', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Consecutive most-recent FINISHED weeks with copay still owing. */
    public static function unpaidWeeks(object $a, string $today): int
    {
        $thisWeek = self::weekStart($today);
        $rows = DB::table('pfcc_copays')->where('authorization_id', $a->id)->where('week_start', '<', $thisWeek)->orderByDesc('week_start')->get(['status']);
        $n = 0;
        foreach ($rows as $r) { if (in_array($r->status, ['due', 'partial'], true)) $n++; else break; }

        return $n;
    }

    /** Absent days for a child in the half-year containing $date. */
    public static function absentDays(int $childId, string $date): int
    {
        [$from, $to] = self::halfYear($date);

        return (int) DB::table('child_absences')->where('child_id', $childId)->whereBetween('absent_on', [$from, $to])->distinct()->count('absent_on');
    }
}
