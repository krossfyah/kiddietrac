<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * MONTHLY ATTENDANCE & FEES (2026-11-13, Anthony: a prospect's "Sample attendance.xlsx" - "enhance our current report to
 * add the missing items"). The sheet Ontario centres keep by hand for CWELCC: one row per child, the hours they were in
 * care each day of the month, the month's hours, parent fees, government fees, total fees, the space type, and a line for
 * the parent's signature.
 *
 * It sits beside the Record of Attendance (App\Support\OccmsRoa) on OCCMS reporting, and differs from it on purpose:
 *   - EVERY child enrolled in the centre's rooms during the month, not only those with a fee subsidy.
 *   - HOURS, not OCCMS codes: each day sums that day's sign-in -> sign-out visits (agency days, rounded to the quarter
 *     hour, like a hand-kept sheet). A day with no hours says why: A absent (reported, or booked and never signed in),
 *     X closed (any closure), x not booked that day, IN signed in and never signed out. Before the child started, after
 *     they left, and days still to come are blank.
 *   - FEES from the family's invoices for the month (period starting in the month; an invoice with no period, by its
 *     issue date; never void or draft). A charge line that names the child is theirs; one that names nobody is shared
 *     evenly between the family's children on the sheet. Government fees: the subsidy on the invoice when it carries
 *     one, otherwise the family's CWELCC rate on the month's fees - the same sum the CWELCC monthly report claims, so the
 *     two never disagree; with neither, the child's own government fee subsidy for the month (Compliance -> Subsidies:
 *     its amount for the month exactly as the Subsidies screen works it out), never more than the fees (2026-11-13).
 *     Parent fees = total - government.
 *   - SPACE TYPE: the room's age group (IN TO PS KG SA) / FT when booked five or more days a week, PT otherwise.
 *
 * Derived at the point of asking, like the ROA: nothing is stored. Read-only, money: OccmsController scopes who sees it.
 */
final class MonthlyAttendance
{
    public const SHORT = ['infant' => 'IN', 'toddler' => 'TO', 'preschool' => 'PS', 'kindergarten' => 'KG', 'school_age' => 'SA'];

    public const MARKS = [
        'A' => 'Absent (reported, or booked and not signed in)',
        'X' => 'Centre closed',
        'x' => 'Not booked that day',
        'IN' => 'Signed in, never signed out',
    ];

    /** @return array{days: array, children: array, totals: array, today: string} */
    public static function build(int $agencyId, int $centreId, string $month): array
    {
        $tz = AgencyTime::tz($agencyId);
        $today = AgencyTime::today($agencyId);
        $mStart = Carbon::createFromFormat('Y-m-d', $month . '-01')->startOfDay();
        $mEnd = $mStart->copy()->endOfMonth();
        $ms = $mStart->toDateString();
        $me = $mEnd->toDateString();
        $closures = Closures::map([$centreId], $ms, $me)[$centreId] ?? [];

        // The centre's operating days of the month (a hand-kept sheet has no weekend columns).
        $days = [];
        for ($d = $mStart->copy(); $d->lte($mEnd); $d->addDay()) {
            $date = $d->toDateString();
            if (! Closures::isOperatingDay($centreId, $date)) continue;
            $cl = $closures[$date] ?? null;
            $days[] = ['date' => $date, 'd' => (int) $d->day, 'dow' => substr($d->format('D'), 0, 2),
                'closure' => $cl ? Closures::reason($cl) : null, 'future' => $date > $today];
        }

        // Children in this centre's rooms at any point of the month (archived and withdrawn ones keep the days they were here).
        $enrols = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')->join('children as ch', 'ch.id', '=', 'e.child_id')
            ->where('r.centre_id', $centreId)
            ->where('e.start_date', '<=', $me)->where(fn ($w) => $w->whereNull('e.end_date')->orWhere('e.end_date', '>=', $ms))
            ->where(fn ($w) => $w->whereNull('ch.deleted_at')->orWhere('ch.deleted_at', '>=', $ms . ' 00:00:00'))
            ->where(fn ($w) => $w->whereNull('ch.withdrawn_at')->orWhere('ch.withdrawn_at', '>=', $ms))
            ->where('ch.enrollment_status', '!=', 'waitlist')
            ->orderByDesc('e.start_date')
            ->get(['e.child_id', 'e.start_date', 'e.end_date', 'e.schedule', 'r.age_group', 'ch.first_name', 'ch.last_name', 'ch.preferred_name',
                'ch.family_id', 'ch.date_of_birth', 'ch.withdrawn_at', 'ch.deleted_at']);
        $byChild = [];
        foreach ($enrols as $e) { $e->days = CareSchedule::daysOf($e->schedule); $byChild[(int) $e->child_id][] = $e; }
        $ids = array_keys($byChild);
        if (! $ids) return ['days' => $days, 'children' => [], 'totals' => ['hours' => 0, 'parent' => 0, 'gov' => 0, 'total' => 0], 'today' => $today];

        // Visits: each sign-in to the next sign-out, in the agency's own days.
        [$fromUtc, $toUtc] = AgencyTime::spanRange($agencyId, $ms, $me);
        $mins = []; $open = []; $last = [];
        foreach (DB::table('check_events')->whereIn('child_id', $ids)->whereIn('event_type', ['check_in', 'check_out'])
            ->where('occurred_at', '>=', $fromUtc)->where('occurred_at', '<', $toUtc)->orderBy('occurred_at')->orderBy('id')
            ->get(['child_id', 'event_type', 'occurred_at']) as $ev) {
            $cid = (int) $ev->child_id;
            $at = Carbon::parse($ev->occurred_at, 'UTC')->setTimezone($tz);
            $date = $at->toDateString();
            if ($ev->event_type === 'check_in') { $last[$cid] = $at; $open[$cid][$date] = true; continue; }
            if (isset($last[$cid]) && $last[$cid]->toDateString() === $date) {
                $mins[$cid][$date] = ($mins[$cid][$date] ?? 0) + max(0, $last[$cid]->diffInMinutes($at, false));
                unset($open[$cid][$date], $last[$cid]);
            }
        }
        $absent = [];
        foreach (DB::table('child_absences')->whereIn('child_id', $ids)->whereBetween('absent_on', [$ms, $me])->get(['child_id', 'absent_on']) as $a) {
            $absent[(int) $a->child_id][substr((string) $a->absent_on, 0, 10)] = true;
        }

        $fees = self::fees($agencyId, $byChild, $ms, $me);
        $keys = [1 => 'mon', 2 => 'tue', 3 => 'wed', 4 => 'thu', 5 => 'fri', 6 => 'sat', 7 => 'sun'];
        $out = [];
        $tot = ['hours' => 0.0, 'parent' => 0.0, 'gov' => 0.0, 'total' => 0.0];
        foreach ($byChild as $cid => $list) {
            $first = $list[0];
            $left = $first->withdrawn_at ? substr((string) $first->withdrawn_at, 0, 10) : null;
            $gone = $first->deleted_at ? substr((string) $first->deleted_at, 0, 10) : null;
            $cells = [];
            $hours = 0.0;
            foreach ($days as $x) {
                $date = $x['date'];
                $on = null;   // the enrolment covering the day
                foreach ($list as $e) {
                    if ((string) $e->start_date <= $date && (! $e->end_date || (string) $e->end_date >= $date)) { $on = $e; break; }
                }
                if (! $on || ($left && $date > $left) || ($gone && $date > $gone)) { $cells[] = ''; continue; }
                if (isset($mins[$cid][$date])) {
                    $h = round($mins[$cid][$date] / 15) * 0.25;
                    $hours += $h;
                    $cells[] = $h;
                    continue;
                }
                if (isset($open[$cid][$date])) { $cells[] = 'IN'; continue; }
                if ($x['future'] || $date === $today) { $cells[] = ''; continue; }   // today is not over: no A yet
                $booked = in_array($keys[Carbon::parse($date)->isoWeekday()], $on->days, true);
                if ($x['closure']) $cells[] = 'X';
                elseif (! $booked) $cells[] = 'x';
                else $cells[] = 'A';   // reported or not: the sheet's A
            }
            // Space type: the room they are in at the end of the month (or when they left).
            $ref = min($me, $left ?: $me);
            $now = $list[0];
            foreach ($list as $e) { if ((string) $e->start_date <= $ref && (! $e->end_date || (string) $e->end_date >= $ref)) { $now = $e; break; } }
            $group = $now->age_group ?: OccmsRoa::ageGroupFromDob($now->date_of_birth ? (string) $now->date_of_birth : null, $ref);
            $week = count(array_intersect($now->days, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun']));
            $f = $fees[$cid] ?? ['total' => 0.0, 'gov' => 0.0];
            $row = [
                'child_id' => $cid,
                'family_id' => (int) $first->family_id,
                'last_name' => (string) $first->last_name,
                'first_name' => (string) ($first->preferred_name ?: $first->first_name),
                'age_group' => $group,
                'space_type' => trim((self::SHORT[$group] ?? '') . '/' . ($week >= 5 ? 'FT' : 'PT'), '/'),
                'cells' => $cells,
                'hours' => round($hours, 2),
                'parent_fees' => round($f['total'] - $f['gov'], 2),
                'gov_fees' => round($f['gov'], 2),
                'total_fees' => round($f['total'], 2),
                'left_on' => $left && $left <= $me ? $left : null,
            ];
            $tot['hours'] += $row['hours']; $tot['parent'] += $row['parent_fees']; $tot['gov'] += $row['gov_fees']; $tot['total'] += $row['total_fees'];
            $out[] = $row;
        }
        usort($out, fn ($a, $b) => strcasecmp($a['last_name'] . ' ' . $a['first_name'], $b['last_name'] . ' ' . $b['first_name']));

        return ['days' => $days, 'children' => $out, 'totals' => array_map(fn ($v) => round($v, 2), $tot), 'today' => $today];
    }

    /** [child_id => ['total' => gross fees, 'gov' => government share]] for the month, from the families' invoices. */
    private static function fees(int $agencyId, array $byChild, string $ms, string $me): array
    {
        $famKids = [];
        foreach ($byChild as $cid => $list) $famKids[(int) $list[0]->family_id][] = (int) $cid;
        $famIds = array_keys($famKids);
        $invoices = DB::table('invoices')->whereIn('family_id', $famIds ?: [0])->whereNotIn('status', ['void', 'draft'])
            ->where(fn ($w) => $w->whereBetween('period_start', [$ms, $me])
                ->orWhere(fn ($q) => $q->whereNull('period_start')->whereBetween('issued_at', [$ms . ' 00:00:00', $me . ' 23:59:59'])))
            ->get(['id', 'family_id', 'subtotal', 'subsidy_amount', 'total']);
        if ($invoices->isEmpty()) return [];
        $lines = DB::table('invoice_lines')->whereIn('invoice_id', $invoices->pluck('id'))->get(['invoice_id', 'child_id', 'amount'])->groupBy('invoice_id');
        // CWELCC rate for families with no subsidy on the invoice (as the CWELCC monthly report works it out).
        // Not in Ontario: since 2025 the base fee is what the family is charged, so there is no % reduction to
        // count as a government fee (2026-11-13) - only a fee subsidy on the invoice or the child's subsidy is.
        $rate = CwelccFees::applies($agencyId) ? [] : DB::table('cwelcc_enrolments')->whereIn('family_id', $famIds)->where('enrolled_from', '<=', $me)
            ->where(fn ($w) => $w->whereNull('enrolled_to')->orWhere('enrolled_to', '>=', $ms))->orderByDesc('enrolled_from')
            ->get(['family_id', 'subsidy_rate'])->unique('family_id')->mapWithKeys(fn ($r) => [(int) $r->family_id => (float) ($r->subsidy_rate ?? 50)])->all();

        $out = [];
        foreach ($invoices as $inv) {
            $fam = (int) $inv->family_id;
            $kids = $famKids[$fam] ?? [];
            if (! $kids) continue;
            $share = array_fill_keys($kids, 0.0);
            $shared = 0.0;
            foreach ($lines[$inv->id] ?? [] as $l) {
                $c = (int) ($l->child_id ?? 0);
                if ($c && isset($share[$c])) $share[$c] += (float) $l->amount;
                elseif (! $c) $shared += (float) $l->amount;
                // A line for a child of the family who is not on this centre's sheet stays with them.
            }
            if (! ($lines[$inv->id] ?? null)) $shared = (float) ($inv->subtotal ?: $inv->total);
            foreach ($kids as $c) $share[$c] += $shared / count($kids);
            $sum = array_sum($share);
            $invSub = (float) ($inv->subsidy_amount ?? 0);
            foreach ($share as $c => $gross) {
                if ($invSub > 0) $gov = $sum > 0 ? $invSub * ($gross / max(0.01, (float) ($inv->subtotal ?: $sum))) : 0.0;
                else $gov = isset($rate[$fam]) ? $gross * ($rate[$fam] / 100) : 0.0;
                $out[$c]['total'] = ($out[$c]['total'] ?? 0) + $gross;
                $out[$c]['gov'] = ($out[$c]['gov'] ?? 0) + $gov;
            }
        }

        // No subsidy on the invoices and no CWELCC: the child's own fee subsidy for the month.
        $need = array_keys(array_filter($out, fn ($f) => $f['gov'] <= 0 && $f['total'] > 0));
        if ($need) {
            foreach (DB::table('subsidies')->whereIn('child_id', $need)->where('active', 1)->whereNull('removed_at')
                ->where('valid_from', '<=', $me)->where(fn ($w) => $w->whereNull('valid_to')->orWhere('valid_to', '>=', $ms))
                ->get() as $sub) {
                $c = (int) $sub->child_id;
                $amt = (float) (SubsidyAmount::forSubsidyMonth($sub, $ms)['amount'] ?? 0);   // as the Subsidies screen works it out
                $out[$c]['gov'] = min($out[$c]['total'], $out[$c]['gov'] + max(0.0, $amt));
            }
        }

        return $out;
    }
}
