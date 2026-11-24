<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * EACH EDUCATOR'S FIRE DRILL SCHEDULE (2026-11-09, Anthony: "the fire drill schedule needs to be configurable to set a
 * specific time/day for each educator ... settings should include notifications to be sent to admin staff (optional
 * with settings) including educators (optional with settings)").
 *
 * Stored on drill_staff_dates (one row per agency + person): sched_repeat, sched_weekday (1 Mon .. 7 Sun),
 * sched_week (1-4, 5 = last; monthly repeats), sched_date (a one-off date, or where an every-2/3-months repeat starts),
 * sched_time ("HH:MM", agency time). Who is told and when lives in agencies.settings.drill_schedule_notify; whether a
 * role hears about drills at all is still StaffTopicNotify (one switch per role, shared with Agency notifications).
 * Reminders go out from drills:educator-schedule (one send per person + occurrence + kind: drill_schedule_sends).
 */
final class DrillSchedule
{
    public const REPEATS = [
        'weekly' => 'Every week',
        'monthly' => 'Every month',
        'every_2_months' => 'Every 2 months',
        'quarterly' => 'Every 3 months',
        'once' => 'Once (a single date)',
    ];
    public const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
    public const WEEKS = [1 => '1st', 2 => '2nd', 3 => '3rd', 4 => '4th', 5 => 'Last'];
    public const LEADS = ['30' => '30 minutes before', '60' => '1 hour before', 'morning' => 'The morning of the drill (7:30)', 'day_before' => 'The afternoon before (4:00 pm)'];

    /** Who is told, and when. Defaults: educators reminded the morning of; admin staff told about missed drills only. */
    public static function notifySettings(int $agencyId): array
    {
        $raw = DB::table('agencies')->where('id', $agencyId)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $s = (array) ($all['drill_schedule_notify'] ?? []);

        return [
            'educator_reminder' => (bool) ($s['educator_reminder'] ?? true),
            'educator_lead' => isset(self::LEADS[(string) ($s['educator_lead'] ?? '')]) ? (string) $s['educator_lead'] : 'morning',
            'admin_upcoming' => (bool) ($s['admin_upcoming'] ?? false),
            'admin_missed' => (bool) ($s['admin_missed'] ?? true),
        ];
    }

    public static function saveNotifySettings(int $agencyId, array $in): array
    {
        DB::transaction(function () use ($agencyId, $in) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
            $cur = self::notifySettings($agencyId);
            foreach (['educator_reminder', 'admin_upcoming', 'admin_missed'] as $k) if (array_key_exists($k, $in)) $cur[$k] = (bool) $in[$k];
            if (isset($in['educator_lead']) && isset(self::LEADS[(string) $in['educator_lead']])) $cur['educator_lead'] = (string) $in['educator_lead'];
            $all['drill_schedule_notify'] = $cur;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all), 'updated_at' => now()]);
        });

        return self::notifySettings($agencyId);
    }

    /** Is there a usable schedule on this row? */
    public static function has(?object $r): bool
    {
        if (! $r || empty($r->sched_repeat) || ! isset(self::REPEATS[$r->sched_repeat]) || ! preg_match('/^\d{2}:\d{2}$/', (string) ($r->sched_time ?? ''))) return false;
        if ($r->sched_repeat === 'once') return ! empty($r->sched_date);
        return ! empty($r->sched_weekday);
    }

    /** "Every month · 2nd Tuesday · 10:00 AM" */
    public static function label(?object $r): ?string
    {
        if (! self::has($r)) return null;
        $t = Carbon::createFromFormat('H:i', (string) $r->sched_time)->format('g:i A');
        if ($r->sched_repeat === 'once') return 'Once · ' . Carbon::parse((string) $r->sched_date)->format('D, M j, Y') . ' · ' . $t;
        $wd = self::WEEKDAYS[(int) $r->sched_weekday] ?? '';
        if ($r->sched_repeat === 'weekly') return 'Every ' . $wd . ' · ' . $t;

        return self::REPEATS[$r->sched_repeat] . ' · ' . (self::WEEKS[(int) ($r->sched_week ?: 1)] ?? '1st') . ' ' . $wd . ' · ' . $t;
    }

    /**
     * The first occurrence at or after $from (agency-local instants), or null. Monthly kinds land on the Nth (or last)
     * weekday of the month; every 2 / 3 months count from sched_date's month (or from the month the schedule was set).
     */
    public static function next(?object $r, Carbon $from, string $tz): ?Carbon
    {
        if (! self::has($r)) return null;
        $from = $from->copy()->setTimezone($tz);
        [$h, $m] = array_map('intval', explode(':', (string) $r->sched_time));
        if ($r->sched_repeat === 'once') {
            $at = Carbon::parse((string) $r->sched_date, $tz)->setTime($h, $m);
            return $at->gte($from) ? $at : null;
        }
        $wd = (int) $r->sched_weekday;
        if ($r->sched_repeat === 'weekly') {
            $at = $from->copy()->startOfDay()->setTime($h, $m);
            for ($i = 0; $i < 8; $i++) {
                if ($at->dayOfWeekIso === $wd && $at->gte($from)) return $at;
                $at->addDay();
            }
            return null;
        }
        $step = ['monthly' => 1, 'every_2_months' => 2, 'quarterly' => 3][$r->sched_repeat] ?? 1;
        $anchor = ! empty($r->sched_date) ? Carbon::parse((string) $r->sched_date, $tz) : Carbon::parse((string) ($r->updated_at ?? 'now'), 'UTC')->setTimezone($tz);
        $anchorIdx = $anchor->year * 12 + $anchor->month - 1;
        $month = $from->copy()->startOfMonth();
        for ($i = 0; $i < 15; $i++) {
            $idx = $month->year * 12 + $month->month - 1;
            if ($idx >= $anchorIdx && ($idx - $anchorIdx) % $step === 0) {
                $at = self::nthWeekday($month, $wd, (int) ($r->sched_week ?: 1))->setTime($h, $m);
                if ($at->gte($from) && ($r->sched_repeat === 'monthly' || $at->gte($anchor->copy()->startOfDay()))) return $at;
            }
            $month->addMonthNoOverflow()->startOfMonth();
        }
        return null;
    }

    /** The Nth (5 = last) given ISO weekday of $month. */
    public static function nthWeekday(Carbon $month, int $wd, int $n): Carbon
    {
        if ($n >= 5) {
            $d = $month->copy()->endOfMonth()->startOfDay();
            while ($d->dayOfWeekIso !== $wd) $d->subDay();
            return $d;
        }
        $d = $month->copy()->startOfMonth()->startOfDay();
        while ($d->dayOfWeekIso !== $wd) $d->addDay();

        return $d->addWeeks($n - 1);
    }

    /** When the educator's reminder for an occurrence goes out. */
    public static function remindAt(Carbon $at, string $lead): Carbon
    {
        return match ($lead) {
            '30' => $at->copy()->subMinutes(30),
            '60' => $at->copy()->subHour(),
            'day_before' => $at->copy()->subDay()->setTime(16, 0),
            default => $at->copy()->setTime(7, 30)->lt($at->copy()->subMinutes(30)) ? $at->copy()->setTime(7, 30) : $at->copy()->subHour(),
        };
    }
}
