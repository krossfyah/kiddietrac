<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Which provider has a child on a given day of the week.
 *
 * Until now the portal assumed one child = one provider. That is not how families
 * actually buy care: Mylah Rappit is with Cassandra Monday to Thursday and with Amna on
 * Friday, and there was nowhere to say so — so she sat in one room all week, the other
 * provider never saw her, and one room ran 8 children against a capacity of 6.
 *
 * The shape for this already existed and was never used: `enrollments` carries both a
 * `room_id` and a `schedule` (["mon","tue",…]), so a child can simply hold MORE THAN ONE
 * open enrolment — one per provider — with the days split between them. Nothing read
 * `schedule`, which is the only reason this did not already work.
 *
 * This class is the single place that knows the rule, so the reads and the writes cannot
 * drift apart.
 */
class CareSchedule
{
    /** Monday-first, matching how a rota is read rather than how PHP numbers days. */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const LABELS = [
        'mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday',
        'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday',
    ];

    /** The three-letter key for a date, in the agency's own timezone. */
    public static function dayKey(?string $tz = null, $date = null): string
    {
        $d = $date ? Carbon::parse($date, $tz ?: 'America/Toronto') : Carbon::now($tz ?: 'America/Toronto');

        return strtolower($d->format('D'));   // Mon → mon
    }

    /**
     * Constrain a query on `enrollments` to the row that applies on $day.
     *
     * A NULL or empty schedule means "every day". That is deliberate: every enrolment
     * written before this existed has either an explicit Mon–Fri or nothing at all, and
     * treating "unspecified" as "never" would empty every roster in the portal the moment
     * this shipped.
     */
    public static function constrain($query, string $alias = 'enrollments', ?string $day = null)
    {
        $day = $day ?: self::dayKey();
        $col = $alias ? $alias.'.schedule' : 'schedule';

        return $query->where(function ($q) use ($col, $day) {
            $q->whereNull($col)
                ->orWhere($col, '')
                ->orWhere($col, '[]')
                // JSON_CONTAINS is exact; LIKE is the fallback for a column that is not
                // valid JSON (a hand-edited row should not make a child disappear).
                ->orWhereRaw("JSON_VALID($col) AND JSON_CONTAINS($col, ?)", ['"'.$day.'"'])
                ->orWhereRaw("NOT JSON_VALID($col) AND $col LIKE ?", ['%'.$day.'%']);
        });
    }

    /** Every open enrolment for a child, with its room and centre. */
    public static function forChild(int $childId)
    {
        return DB::table('enrollments as e')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('e.child_id', $childId)
            ->whereNull('e.end_date')
            ->orderBy('e.id')
            ->get(['e.id', 'e.room_id', 'e.schedule', 'e.start_date', 'e.monthly_fee',
                   'e.cwelcc_eligible', 'r.name as room_name', 'c.id as centre_id', 'c.name as centre_name']);
    }

    /** day => ['room_id'=>…, 'room_name'=>…, 'centre_id'=>…, 'centre_name'=>…] or null. */
    public static function week(int $childId): array
    {
        $out = array_fill_keys(self::DAYS, null);
        foreach (self::forChild($childId) as $e) {
            foreach (self::daysOf($e->schedule) as $d) {
                if (! array_key_exists($d, $out)) {
                    continue;
                }
                // First writer wins, so a child double-booked in the data still renders.
                $out[$d] = $out[$d] ?: [
                    'enrolment_id' => (int) $e->id,
                    'room_id' => (int) $e->room_id,
                    'room_name' => $e->room_name,
                    'centre_id' => $e->centre_id ? (int) $e->centre_id : null,
                    'centre_name' => $e->centre_name,
                ];
            }
        }

        return $out;
    }

    /** The day keys an enrolment covers. Unspecified means the whole week. */
    public static function daysOf($schedule): array
    {
        if (is_array($schedule)) {
            $arr = $schedule;
        } else {
            $raw = trim((string) $schedule);
            if ($raw === '' || $raw === '[]') {
                return self::DAYS;
            }
            $arr = json_decode($raw, true);
            if (! is_array($arr)) {
                // Not JSON — recover whatever day names are in there rather than lose the row.
                $arr = array_values(array_filter(self::DAYS, fn ($d) => str_contains(strtolower($raw), $d)));
            }
        }
        $arr = array_values(array_filter(array_map(
            fn ($v) => strtolower(trim((string) $v)), $arr
        ), fn ($v) => in_array($v, self::DAYS, true)));

        return $arr ?: self::DAYS;
    }

    /** Where is this child today? Null when they are not booked in at all. */
    public static function roomToday(int $childId, ?string $tz = null): ?int
    {
        $day = self::dayKey($tz);
        $row = self::week($childId)[$day] ?? null;

        return $row['room_id'] ?? null;
    }

    /**
     * Point `children.primary_room_id` at TODAY's room.
     *
     * Half the portal reads that column — the educator's own room list, ratios, the day
     * brief — and it can only hold one value. Rather than rewrite those, it is kept
     * meaning "where this child is right now", refreshed each morning by
     * `care:sync-rooms` and immediately after any schedule change.
     *
     * Returns true when something actually changed.
     */
    public static function syncPrimaryRoom(int $childId, ?string $tz = null): bool
    {
        try {
            $child = DB::table('children')->find($childId);
            if (! $child) {
                return false;
            }
            $tz = $tz ?: self::tzForChild($childId);
            $roomId = self::roomToday($childId, $tz);

            /* Not booked today is NOT a reason to blank the column. A child who only
               attends Fridays would otherwise vanish from every screen Monday to
               Thursday, including the ones an admin uses to find them. Their last known
               room stands until a day they are actually booked. */
            if (! $roomId || (int) $child->primary_room_id === (int) $roomId) {
                return false;
            }

            DB::table('children')->where('id', $childId)
                ->update(['primary_room_id' => $roomId, 'updated_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('CareSchedule::syncPrimaryRoom failed', ['child' => $childId, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public static function tzForChild(int $childId): string
    {
        /* Via the portal's own resolver. My first version read `centres.timezone`, which
           does not exist - the column is on `agencies`, and AgencyTime already knows how
           to walk from a centre to it. */
        $centreId = DB::table('children as c')
            ->leftJoin('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', $childId)
            ->value('f.centre_id');

        return \App\Support\AgencyTime::tzForCentre($centreId ? (int) $centreId : null)
            ?: 'America/Toronto';
    }

    /** A short human summary: "Cassandra Mon–Thu · Amna Fri". */
    public static function summary(int $childId): string
    {
        $week = self::week($childId);
        $byCentre = [];
        foreach (self::DAYS as $d) {
            $cell = $week[$d] ?? null;
            if (! $cell) {
                continue;
            }
            $key = $cell['centre_name'] ?: ($cell['room_name'] ?: 'Unassigned');
            $byCentre[$key][] = ucfirst($d);
        }
        $parts = [];
        foreach ($byCentre as $name => $days) {
            $parts[] = $name.' '.implode(', ', $days);
        }

        return $parts ? implode(' · ', $parts) : 'No days booked';
    }
}
