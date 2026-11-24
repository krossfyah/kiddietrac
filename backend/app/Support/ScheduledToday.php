<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WHO IS SCHEDULED AT A HOME ON A DAY (2026-11-01).
 *
 * Anthony: "for educators and children not scheduled to work - mark them as not scheduled in
 * the your provider cards - full sweep". The overview card listed every enrolled child and
 * every team member and called anyone without a check-in "Not in" / "Absent", so on a
 * Sunday, a closure day or a part-time child's day off the whole home read as absent. The
 * Daily Overview had its own, slightly different idea. This class is the one rule both use.
 *
 * $date is the AGENCY's local date (Y-m-d). Shifts store local wall time and enrolment
 * schedules are weekday keys, so nothing here converts zones.
 *
 * CHILD - scheduled at centre C on $date when C is open that day (an operating weekday per
 * Closures::isOperatingDay, no closure) AND an enrolment that is current on $date covers
 * that weekday (CareSchedule::daysOf; an empty schedule = every day) in a room of C (or in
 * no room). A child whose only booking that day is with another provider is not scheduled
 * here. A child with NO current enrolment at all is left as scheduled: they are unplaced,
 * which is a different problem, and the Daily Overview already decided that calling them
 * "not due in" would be a guess.
 *
 * STAFF - scheduled when they hold a shift (status scheduled/active) at C that day. The
 * provider, whom the nightly fill rosters on the centre's own days, additionally counts as
 * scheduled on a day C is open when no shift rows exist for them around that date at all
 * (an agency that does not use the roster); once their shifts are being written, a day
 * without one is a day off.
 */
final class ScheduledToday
{
    /** @param int[] $centreIds  @return array<int,bool> centre id => open on $date */
    public static function centresOpen(array $centreIds, string $date): array
    {
        $centreIds = array_values(array_unique(array_map('intval', $centreIds)));
        $closed = $centreIds ? Closures::map($centreIds, $date, $date) : [];
        $out = [];
        foreach ($centreIds as $cid) {
            $out[$cid] = Closures::isOperatingDay($cid, $date) && empty($closed[$cid][$date]);
        }

        return $out;
    }

    /**
     * @param  array<int,int[]>  $childIdsByCentre  centre id => child ids listed on that centre
     * @param  array<int,bool>   $open              from centresOpen()
     * @return array<int,array<int,bool>>           centre id => child id => scheduled
     */
    public static function children(array $childIdsByCentre, string $date, array $open): array
    {
        $all = [];
        foreach ($childIdsByCentre as $ids) {
            foreach ($ids as $id) { $all[(int) $id] = true; }
        }
        $byChild = [];
        if ($all) {
            foreach (DB::table('enrollments as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
                ->whereIn('e.child_id', array_keys($all))
                ->where(function ($q) use ($date) { $q->whereNull('e.end_date')->orWhere('e.end_date', '>=', $date); })
                ->where(function ($q) use ($date) { $q->whereNull('e.start_date')->orWhere('e.start_date', '<=', $date); })
                ->get(['e.child_id', 'e.schedule', 'r.centre_id']) as $e) {
                $byChild[(int) $e->child_id][] = $e;
            }
        }
        $day = strtolower(Carbon::parse($date)->format('D'));   // mon..sun

        $out = [];
        foreach ($childIdsByCentre as $cid => $ids) {
            $cid = (int) $cid;
            foreach ($ids as $id) {
                $id = (int) $id;
                if (empty($open[$cid])) { $out[$cid][$id] = false; continue; }
                $rows = $byChild[$id] ?? [];
                if (! $rows) { $out[$cid][$id] = true; continue; }   // unplaced: not a guess either way
                $here = false;
                foreach ($rows as $e) {
                    if (($e->centre_id === null || (int) $e->centre_id === $cid)
                        && in_array($day, CareSchedule::daysOf($e->schedule), true)) { $here = true; break; }
                }
                $out[$cid][$id] = $here;
            }
        }

        return $out;
    }

    /**
     * The provider of $centreId on $date.
     *
     * @param  bool  $hasShift  whether they hold a shift there that day (callers usually know)
     */
    public static function provider(int $centreId, int $userId, string $date, bool $open, ?bool $hasShift = null): bool
    {
        $rooms = fn ($q) => $q->select('id')->from('rooms')->where('centre_id', $centreId);
        if ($hasShift === null) {
            $hasShift = DB::table('shifts')->whereIn('room_id', $rooms)->where('user_id', $userId)
                ->whereIn('status', ['scheduled', 'active'])->whereRaw('DATE(starts_at) = ?', [$date])->exists();
        }
        if ($hasShift) {
            return true;
        }
        $d = Carbon::parse($date);
        $rostered = DB::table('shifts')->whereIn('room_id', $rooms)->where('user_id', $userId)
            ->where('starts_at', '>=', $d->copy()->subDays(14)->toDateString())
            ->where('starts_at', '<', $d->copy()->addDays(15)->toDateString())
            ->exists();

        return $rostered ? false : $open;
    }
}
