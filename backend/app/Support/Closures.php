<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Is this centre closed on this day? (2026-08-14)
 *
 * centre_closures was written by one screen and read by exactly one command. Nothing else
 * in the platform knew a closure existed, so a closed day still nagged parents to sign
 * their child in, still counted as 0% attendance, and still let staff clock in.
 *
 * DERIVED, NOT MATERIALISED. The tempting fix is to write an attendance row for every
 * child on a closed day. It is the wrong one: the rows would have to be unwound if the
 * closure is deleted or its dates edited, they would sit in child_absences claiming
 * children were absent when the centre was shut, and any child enrolled after the closure
 * was entered would be missed. A closure is a fact about the CENTRE and a day; everything
 * else reads it at the point of asking.
 *
 * THE DATE IS THE AGENCY'S DATE. "Closed today" has to mean today where the centre is, not
 * where the server is — the server runs UTC, so between 8pm and midnight Eastern its idea
 * of "today" is already tomorrow's date. See [[kiddietrac-agency-timezone-rule]].
 */
final class Closures
{
    /** The closure covering this centre on this date, or null. */
    public static function forDate(?int $centreId, ?string $date = null): ?object
    {
        if (! $centreId) {
            return null;
        }
        $d = $date ?: self::todayFor($centreId);

        return DB::table('centre_closures')
            ->where('centre_id', $centreId)
            ->where(function ($q) use ($d) {
                // A null end_date means ONE day, so it has to match that day exactly.
                // Written as "closure_date <= d AND (end_date IS NULL OR end_date >= d)"
                // — the shape ParentDailySummaryCommand still uses — a single-day closure
                // matches every date after it FOREVER, because the null branch never
                // stops being true. Centre 16 has read as closed every day since its
                // 13 July holiday.
                $q->where(function ($one) use ($d) {
                    $one->whereNull('end_date')->whereDate('closure_date', '=', $d);
                })->orWhere(function ($range) use ($d) {
                    $range->whereNotNull('end_date')
                        ->whereDate('closure_date', '<=', $d)
                        ->whereDate('end_date', '>=', $d);
                });
            })
            ->orderByDesc('closure_date')
            ->first();
    }

    public static function isClosed(?int $centreId, ?string $date = null): bool
    {
        return self::forDate($centreId, $date) !== null;
    }

    /**
     * Every closed date for these centres in a window, as ['centreId' => ['Y-m-d' => row]].
     *
     * One query for a whole report rather than one per day per centre.
     */
    public static function map(array $centreIds, string $start, string $end): array
    {
        if (! $centreIds) {
            return [];
        }
        // Same rule as forDate: a null end_date is a single day, not an open range.
        $rows = DB::table('centre_closures')
            ->whereIn('centre_id', $centreIds)
            ->whereDate('closure_date', '<=', $end)
            ->where(function ($q) use ($start) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $start);
            })
            ->get()
            ->filter(function ($r) use ($start, $end) {
                $a = $r->closure_date;
                $b = $r->end_date ?: $r->closure_date;
                return $b >= $start && $a <= $end;
            });

        $out = [];
        $from = Carbon::parse($start)->startOfDay();
        $to = Carbon::parse($end)->startOfDay();
        foreach ($rows as $r) {
            $a = Carbon::parse($r->closure_date)->startOfDay()->max($from);
            $b = Carbon::parse($r->end_date ?: $r->closure_date)->startOfDay()->min($to);
            // A guard, not decoration: a row with end_date before closure_date would
            // otherwise loop until the request timed out.
            for ($d = $a->copy(), $n = 0; $d->lte($b) && $n < 400; $d->addDay(), $n++) {
                $out[(int) $r->centre_id][$d->toDateString()] = $r;
            }
        }

        return $out;
    }

    /**
     * Does this centre run on this weekday at all?
     *
     * Separate from a closure: a closure is an exception on a day the centre normally
     * opens, this is the ordinary weekly pattern. A Sunday is not "closed for a holiday",
     * it is simply not a day the centre operates, and nothing should be reported for it.
     *
     * Mon–Fri unless the centre says otherwise in settings.operating_days, which accepts
     * either day numbers or names ('mon', 'Saturday') because both shapes exist in the
     * data. An empty or unparseable list falls back to the default rather than closing the
     * centre every day, which is the failure mode that would matter.
     */
    public static function isOperatingDay(?int $centreId, ?string $date = null): bool
    {
        $d = $date ?: self::todayFor($centreId);
        $dow = Carbon::parse($d)->isoWeekday();          // 1 = Monday … 7 = Sunday
        $open = $dow >= 1 && $dow <= 5;

        if (! $centreId) {
            return $open;
        }

        try {
            $settings = DB::table('centres')->where('id', $centreId)->value('settings');
            if ($settings) {
                $st = json_decode((string) $settings, true);
                if (is_array($st) && ! empty($st['operating_days']) && is_array($st['operating_days'])) {
                    $names = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];
                    $days = array_filter(array_map(function ($x) use ($names) {
                        return is_numeric($x) ? (int) $x : ($names[strtolower(substr((string) $x, 0, 3))] ?? 0);
                    }, $st['operating_days']));
                    if ($days) {
                        $open = in_array($dow, $days, true);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let a malformed settings blob stop a summary going out on a Tuesday.
        }

        return $open;
    }

    /** "Thanksgiving Monday" — what to call it, falling back to the type. */
    public static function reason(?object $row): string
    {
        if (! $row) {
            return '';
        }
        $reason = trim((string) ($row->reason ?? ''));
        if ($reason !== '') {
            return $reason;
        }

        return ucfirst(str_replace('_', ' ', (string) ($row->closure_type ?? 'closed')));
    }

    /** "Mon 13 Jul" for one day, "Mon 13 – Fri 17 Jul" for a range. */
    public static function dateLabel(?object $row): string
    {
        if (! $row) {
            return '';
        }
        $a = Carbon::parse($row->closure_date);
        if (empty($row->end_date) || Carbon::parse($row->end_date)->isSameDay($a)) {
            return $a->format('D j M');
        }

        return $a->format('D j M') . ' – ' . Carbon::parse($row->end_date)->format('D j M');
    }

    /** The centres a user is attached to — staff by assignment, guardians by enrolment. */
    public static function centreIdsForUser(int $userId): array
    {
        $staff = DB::table('role_assignments')->where('user_id', $userId)
            ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id');

        $family = DB::table('guardians as g')
            ->join('families as f', 'f.id', '=', 'g.family_id')
            ->where('g.user_id', $userId)->whereNull('f.deleted_at')
            ->whereNotNull('f.centre_id')->pluck('f.centre_id');

        return $staff->merge($family)->map(fn ($i) => (int) $i)->unique()->values()->all();
    }

    /**
     * What to tell this person when they open the app: a closure on today, or the next one
     * coming inside a fortnight.
     *
     * Returns null when there is nothing to say, so the caller can omit the key entirely
     * rather than making every client check an empty object.
     */
    public static function noticeForUser(int $userId): ?array
    {
        foreach (self::centreIdsForUser($userId) as $centreId) {
            $today = self::todayFor($centreId);

            if ($row = self::forDate($centreId, $today)) {
                return self::notice($row, $centreId, true);
            }

            $soon = DB::table('centre_closures')
                ->where('centre_id', $centreId)
                ->whereDate('closure_date', '>', $today)
                ->whereDate('closure_date', '<=', Carbon::parse($today)->addDays(14)->toDateString())
                ->orderBy('closure_date')->first();
            if ($soon) {
                return self::notice($soon, $centreId, false);
            }
        }

        return null;
    }

    private static function notice(object $row, int $centreId, bool $isToday): array
    {
        $centre = DB::table('centres')->where('id', $centreId)->value('name');
        $reason = self::reason($row);
        $dates = self::dateLabel($row);

        return [
            'id' => (int) $row->id,
            'centre_id' => $centreId,
            'centre_name' => (string) ($centre ?: 'Your centre'),
            'is_today' => $isToday,
            'dates' => $dates,
            'reason' => $reason,
            'closure_type' => (string) ($row->closure_type ?? ''),
            'title' => $isToday ? 'We are closed today' : 'A closure is coming up',
            // Warm rather than clinical — this is the first thing somebody sees when they
            // open the app, and "CENTRE CLOSED" reads like an error message.
            'message' => $isToday
                ? sprintf('%s is closed today for %s. Enjoy the day — there is nothing you need to do here, and we will see you when we reopen.',
                    $centre ?: 'Your centre', $reason)
                : sprintf('Just a reminder that %s will be closed %s for %s. Nothing to do now — we wanted you to have plenty of notice.',
                    $centre ?: 'your centre', $dates, $reason),
        ];
    }

    /** Today, where the centre is. */
    public static function todayFor(?int $centreId): string
    {
        $tz = 'America/Toronto';
        if ($centreId) {
            $tz = DB::table('centres as c')->join('agencies as a', 'a.id', '=', 'c.agency_id')
                ->where('c.id', $centreId)->value('a.timezone') ?: $tz;
        }

        return Carbon::now($tz)->toDateString();
    }
}
