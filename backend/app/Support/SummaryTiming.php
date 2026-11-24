<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHEN THE DAILY SUMMARIES GO OUT (2026-10-29).
 *
 * Anthony: "wire up a setting to control the daily summary for parents to send it out after 1
 * hour after the child has clocked out or can be changed via a setting this includes the
 * educator summary 1 hour after clocked out".
 *
 * They used to go at fixed times - parents 18:30, educators 19:00, both on TORONTO's clock, so a
 * California agency's parents got theirs at 15:30 local, mid-afternoon. Now the two commands run
 * every 10 minutes with --due and each person's summary goes when it is due:
 *   parent    {parent_delay_min} after the child's last check-out of the day (default 60);
 *   educator  {educator_delay_min} after their last clock-out (default 60);
 *             - only for a check-out / clock-out at or after EARLY_SEND_FROM (14:00 local);
 *   either    at the fallback time ON THE AGENCY'S CLOCK (18:30 / 19:00) when nobody checked
 *             them out or they never clocked out - the old behaviour, now in local time.
 * Settings → Notifications → Daily summaries; agencies.settings->summary_timing.
 *
 * summary_sends is the one-per-day guarantee: a summary is CLAIMED (unique kind + person + day)
 * before it is built, so a run that overlaps, repeats or crashes can never send it twice.
 */
final class SummaryTiming
{
    public const KEY = 'summary_timing';
    public const DEFAULTS = ['parent_delay_min' => 60, 'educator_delay_min' => 60, 'parent_fallback' => '18:30', 'educator_fallback' => '19:00'];
    public const DELAYS = [0, 15, 30, 45, 60, 90, 120, 180, 240];

    /* A check-out / clock-out before this (agency clock) never sends early (2026-11-05).
       A child out for a 10 AM appointment and back at 1, or an educator clocking out
       for lunch, had the day's ONE summary claimed an hour later and the afternoon was
       never sent. Before 2 PM the summary waits for a later check-out or the fallback. */
    public const EARLY_SEND_FROM = '14:00';

    private static array $memo = [];

    public static function read(int $agencyId): array
    {
        if (isset(self::$memo[$agencyId])) return self::$memo[$agencyId];
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
        return self::$memo[$agencyId] = self::clean(is_array($s[self::KEY] ?? null) ? $s[self::KEY] : []);
    }

    public static function clean(array $in): array
    {
        $out = self::DEFAULTS;
        foreach (['parent_delay_min', 'educator_delay_min'] as $k) {
            if (isset($in[$k]) && is_numeric($in[$k])) $out[$k] = max(0, min(480, (int) $in[$k]));
        }
        foreach (['parent_fallback', 'educator_fallback'] as $k) {
            if (isset($in[$k]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $in[$k])) $out[$k] = (string) $in[$k];
        }
        return $out;
    }

    public static function write(int $agencyId, array $next): array
    {
        $clean = self::clean($next);
        DB::transaction(function () use ($agencyId, $clean) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s[self::KEY] = $clean;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        unset(self::$memo[$agencyId]);
        return $clean;
    }

    /** The agency's day as the UTC window the database stores. */
    private static function window(string $tz, Carbon $date): array
    {
        return [Carbon::parse($date->toDateString() . ' 00:00:00', $tz)->utc(), Carbon::parse($date->toDateString() . ' 23:59:59', $tz)->utc()];
    }

    private static function fallbackPassed(string $hm, string $tz, Carbon $date): bool
    {
        return Carbon::now('UTC')->gte(Carbon::parse($date->toDateString() . ' ' . $hm . ':00', $tz)->utc());
    }

    /** Did this check-out / clock-out (UTC) happen at or after EARLY_SEND_FROM on the agency's clock? */
    private static function lateEnough(string $utc, string $tz): bool
    {
        return Carbon::parse($utc, 'UTC')->setTimezone($tz)->format('H:i') >= self::EARLY_SEND_FROM;
    }

    /** Is this child's summary due now? */
    public static function parentDue(int $childId, int $agencyId, string $tz, Carbon $date): bool
    {
        $t = self::read($agencyId);
        [$a, $b] = self::window($tz, $date);
        $last = DB::table('check_events')->where('child_id', $childId)
            ->whereIn('event_type', ['check_in', 'check_out'])
            ->whereBetween('occurred_at', [$a, $b])
            ->orderByDesc('occurred_at')->orderByDesc('id')->first(['event_type', 'occurred_at']);
        if ($last && $last->event_type === 'check_out'
            && self::lateEnough((string) $last->occurred_at, $tz)
            && Carbon::now('UTC')->gte(Carbon::parse((string) $last->occurred_at, 'UTC')->addMinutes($t['parent_delay_min']))) {
            return true;
        }
        return self::fallbackPassed($t['parent_fallback'], $tz, $date);
    }

    /** Is this educator's summary due now? */
    public static function educatorDue(int $userId, int $agencyId, string $tz, Carbon $date): bool
    {
        $t = self::read($agencyId);
        [$a, $b] = self::window($tz, $date);
        $punches = DB::table('time_punches')->where('user_id', $userId)
            ->whereBetween('punched_in_at', [$a, $b])->get(['punched_in_at', 'punched_out_at']);
        // On lunch or a break the shift rows are closed but the day is not over (StaffBreaks).
        if ($punches->count() && $punches->every(fn ($p) => $p->punched_out_at !== null) && ! StaffBreaks::current($userId)) {
            $out = $punches->max('punched_out_at');
            if (self::lateEnough((string) $out, $tz) && Carbon::now('UTC')->gte(Carbon::parse((string) $out, 'UTC')->addMinutes($t['educator_delay_min']))) return true;
        }
        return self::fallbackPassed($t['educator_fallback'], $tz, $date);
    }

    public static function sent(string $kind, int $id, string $ymd): bool
    {
        return DB::table('summary_sends')->where('kind', $kind)->where('subject_id', $id)->where('for_date', $ymd)->exists();
    }

    /** Take the one slot for this person and day. False = already taken: do not send. */
    public static function claim(string $kind, int $id, string $ymd, string $how = 'due'): bool
    {
        return DB::table('summary_sends')->insertOrIgnore([
            'kind' => $kind, 'subject_id' => $id, 'for_date' => $ymd, 'how' => mb_substr($how, 0, 16), 'created_at' => now(),
        ]) === 1;
    }
}
