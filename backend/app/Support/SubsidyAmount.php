<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * What a child's subsidy takes off a month's invoice (2026-09-29).
 *
 * Anthony: a provincial subsidy "should have option to add daily amount or monthly
 * amount", and chose "Scheduled care days" for how a daily amount becomes money:
 *
 *   monthly  -> monthly_amount
 *   daily    -> daily_amount x the child's SCHEDULED care days in the month, where a day
 *               counts if it is one of the child's booked weekdays on an enrolment that
 *               has started, the centre operates that weekday (Closures::isOperatingDay),
 *               the centre is not closed that day (Closures::map), and the subsidy is in
 *               effect that day.
 *
 * Invoices are issued at the start of the month for that month, so the calculation is
 * made from the schedule when the invoice is generated -- attendance is not known yet.
 *
 * ONE place for the maths: the invoice run, the estimated current invoice, and the
 * Subsidies report all call this, so they can never disagree.
 */
class SubsidyAmount
{
    /**
     * The active subsidy for a child on $onDate, and what it takes off the month
     * [$monthStart .. end of month]. Returns null when there is none.
     *
     * @return array{subsidy_id:int, basis:string, amount:float, days:?int, rate:float, label:string}|null
     */
    public static function forChildMonth(int $childId, string $onDate, ?string $monthStart = null): ?array
    {
        $s = DB::table('subsidies')
            ->where('child_id', $childId)
            ->where('active', true)
            ->where('valid_from', '<=', $onDate)
            ->where(function ($q) use ($onDate) {
                $q->whereNull('valid_to')->orWhere('valid_to', '>=', $onDate);
            })
            ->orderByDesc('valid_from')
            ->first();

        return $s ? self::forSubsidyMonth($s, $monthStart ?: Carbon::parse($onDate)->startOfMonth()->toDateString()) : null;
    }

    /** What one subsidy row takes off the month starting $monthStart. */
    public static function forSubsidyMonth(object $s, string $monthStart): array
    {
        $basis = ($s->amount_basis ?? 'monthly') === 'daily' ? 'daily' : 'monthly';
        if ($basis === 'monthly') {
            $amt = round((float) $s->monthly_amount, 2);

            return ['subsidy_id' => (int) $s->id, 'basis' => 'monthly', 'amount' => $amt, 'days' => null,
                'rate' => $amt, 'label' => 'Subsidy'];
        }

        $rate = round((float) ($s->daily_amount ?? 0), 2);
        $start = Carbon::parse($monthStart)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        // Only the days the subsidy is in effect.
        $from = Carbon::parse(max($start->toDateString(), (string) $s->valid_from));
        $to = Carbon::parse(min($end->toDateString(), (string) ($s->valid_to ?: $end->toDateString())));
        $days = $from->lte($to) ? self::scheduledDays((int) $s->child_id, $from->toDateString(), $to->toDateString()) : 0;

        return ['subsidy_id' => (int) $s->id, 'basis' => 'daily', 'amount' => round($rate * $days, 2), 'days' => $days,
            'rate' => $rate, 'label' => 'Subsidy (' . $days . ' scheduled day' . ($days === 1 ? '' : 's') . ' x $' . number_format($rate, 2) . ')'];
    }

    /** The child's scheduled care days between two dates (inclusive). */
    public static function scheduledDays(int $childId, string $from, string $to): int
    {
        $enrols = DB::table('enrollments as e')
            ->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('e.child_id', $childId)
            ->where(function ($q) use ($from) { $q->whereNull('e.end_date')->orWhere('e.end_date', '>=', $from); })
            ->where(function ($q) use ($to) { $q->whereNull('e.start_date')->orWhere('e.start_date', '<=', $to); })
            ->get(['e.schedule', 'e.start_date', 'e.end_date', 'r.centre_id']);
        if ($enrols->isEmpty()) {
            return 0;
        }
        $centreIds = $enrols->pluck('centre_id')->filter()->map(fn ($c) => (int) $c)->unique()->values()->all();
        $closed = Closures::map($centreIds, $from, $to);
        $opCache = [];
        $keys = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];

        $n = 0;
        for ($d = Carbon::parse($from), $end = Carbon::parse($to), $g = 0; $d->lte($end) && $g < 40; $d->addDay(), $g++) {
            $date = $d->toDateString();
            $dow = $d->isoWeekday();
            foreach ($enrols as $e) {
                if (($e->start_date && $e->start_date > $date) || ($e->end_date && $e->end_date < $date)) {
                    continue;
                }
                if (! in_array($keys[$dow], CareSchedule::daysOf($e->schedule), true)) {
                    continue;
                }
                $cid = $e->centre_id ? (int) $e->centre_id : null;
                $ck = ($cid ?? 0) . '-' . $dow;
                if (! array_key_exists($ck, $opCache)) {
                    $opCache[$ck] = Closures::isOperatingDay($cid, $date);
                }
                if (! $opCache[$ck] || ($cid && isset($closed[$cid][$date]))) {
                    continue;
                }
                $n++;
                break;   // one care day, however many enrolments cover it
            }
        }

        return $n;
    }
}
