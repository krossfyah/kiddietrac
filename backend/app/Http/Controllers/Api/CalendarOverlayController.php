<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Closures;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Everything that happens on a day but isn't a shift.
 *
 * The staff calendar drew shifts and time-off blocks, so a director looking at it could
 * not see that Tuesday was a closure, that two children were away, or that somebody had
 * a birthday. Those live in four unrelated tables plus a helper, and asking the calendar
 * to learn all five would put that knowledge in the one place least able to keep it.
 *
 * Everything is flattened into one shape — date, kind, title, detail — because the
 * calendar only ever needs to know what to draw on a square, not which table it came
 * from. Multi-day things are expanded to one entry per day for the same reason: a grid
 * renders days, and every caller would otherwise have to re-implement overlap.
 */
final class CalendarOverlayController extends Controller
{
    /** Guards a pathological range from expanding into a million rows. */
    private const MAX_DAYS = 400;

    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        abort_unless($agencyId, 403, 'No agency');

        $from = Carbon::parse($request->query('from', now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', now()->endOfMonth()->toDateString()))->startOfDay();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $to = $from->copy()->addDays(self::MAX_DAYS);
        }

        $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        if (! $centreIds) {
            return response()->json(['events' => []]);
        }

        $events = array_merge(
            $this->birthdays($centreIds, $agencyId, $from, $to),
            $this->childAbsences($centreIds, $from, $to),
            $this->staffTimeOff($agencyId, $centreIds, $from, $to),
            $this->vacationHolds($centreIds, $from, $to),
            $this->closures($centreIds, $from, $to),
        );

        usort($events, fn ($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => $events,
        ]);
    }

    /** Birthdays recur, so they are matched on month-day across the range, not on the stored year. */
    private function birthdays(array $centreIds, int $agencyId, Carbon $from, Carbon $to): array
    {
        $out = [];

        $children = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->where('ch.enrollment_status', 'enrolled')
            ->whereNull('ch.deleted_at')->whereNotNull('ch.date_of_birth')
            ->get(['ch.first_name', 'ch.last_name', 'ch.date_of_birth']);

        $staff = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.active', 1)->where('ra.role', '!=', 'guardian')
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('ra.agency_id', $agencyId)->orWhereIn('ra.centre_id', $centreIds);
            })
            ->whereNull('u.deleted_at')->whereNotNull('u.date_of_birth')
            ->distinct()->get(['u.first_name', 'u.last_name', 'u.date_of_birth']);

        foreach ([['child', $children], ['staff', $staff]] as [$who, $rows]) {
            foreach ($rows as $r) {
                $dob = Carbon::parse($r->date_of_birth);
                // Check each year the range touches — a range can straddle New Year.
                for ($y = (int) $from->format('Y'); $y <= (int) $to->format('Y'); $y++) {
                    try {
                        $d = Carbon::create($y, (int) $dob->format('n'), (int) $dob->format('j'));
                    } catch (\Throwable $e) {
                        continue;   // 29 February in a non-leap year
                    }
                    if ($d->lt($from) || $d->gt($to)) {
                        continue;
                    }
                    $age = $y - (int) $dob->format('Y');
                    $out[] = [
                        'date' => $d->toDateString(),
                        'kind' => 'birthday',
                        'icon' => '🎂',
                        'title' => trim($r->first_name . ' ' . $r->last_name),
                        'detail' => $age > 0 ? ('turns ' . $age) : '',
                        'who' => $who,
                        'tone' => 'celebrate',
                    ];
                }
            }
        }

        return $out;
    }

    private function childAbsences(array $centreIds, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('child_absences as a')
            ->join('children as ch', 'ch.id', '=', 'a.child_id')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereNull('ch.deleted_at')
            ->whereBetween('a.absent_on', [$from->toDateString(), $to->toDateString()])
            ->get(['a.absent_on', 'a.reason', 'a.note', 'ch.first_name', 'ch.last_name']);

        return $rows->map(fn ($r) => [
            'date' => Carbon::parse($r->absent_on)->toDateString(),
            'kind' => 'absence',
            'icon' => '🏠',
            'title' => trim($r->first_name . ' ' . $r->last_name),
            // The reason is the whole point of showing an absence — "away" alone tells
            // nobody whether to expect them tomorrow.
            // Joined from whichever parts exist. Concatenating unconditionally left a
            // dangling "— " on absences recorded without a reason.
            'detail' => implode(' — ', array_filter([
                trim((string) ($r->reason ?? '')),
                trim((string) ($r->note ?? '')),
            ])),
            'who' => 'child',
            'tone' => 'away',
        ])->all();
    }

    /** Staff time off and vacation, expanded to one entry per day it covers. */
    private function staffTimeOff(int $agencyId, array $centreIds, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('time_off_requests as t')
            ->join('users as u', 'u.id', '=', 't.user_id')
            ->where(function ($q) use ($agencyId, $centreIds) {
                $q->where('t.agency_id', $agencyId)->orWhereIn('t.centre_id', $centreIds);
            })
            // Declined time off is not time off; pending is shown, because a director
            // planning cover needs to see what might be about to happen.
            ->whereIn('t.status', ['approved', 'pending'])
            ->whereDate('t.start_at', '<=', $to->toDateString())
            ->whereDate('t.end_at', '>=', $from->toDateString())
            ->get(['t.start_at', 't.end_at', 't.request_type', 't.status', 'u.first_name', 'u.last_name']);

        $out = [];
        foreach ($rows as $r) {
            foreach ($this->eachDay($r->start_at, $r->end_at, $from, $to) as $d) {
                $out[] = [
                    'date' => $d,
                    'kind' => 'timeoff',
                    'icon' => $r->status === 'pending' ? '🕗' : '🌴',
                    'title' => trim($r->first_name . ' ' . $r->last_name),
                    'detail' => trim(str_replace('_', ' ', (string) $r->request_type))
                        . ($r->status === 'pending' ? ' (pending)' : ''),
                    'who' => 'staff',
                    'tone' => $r->status === 'pending' ? 'pending' : 'away',
                ];
            }
        }
        return $out;
    }

    /** A family's booked holiday — a child-side absence known in advance. */
    private function vacationHolds(array $centreIds, Carbon $from, Carbon $to): array
    {
        $rows = DB::table('vacation_holds as v')
            ->join('families as f', 'f.id', '=', 'v.family_id')
            ->whereIn('f.centre_id', $centreIds)
            ->whereIn('v.status', ['approved', 'pending'])
            ->whereDate('v.start_date', '<=', $to->toDateString())
            ->whereDate('v.end_date', '>=', $from->toDateString())
            ->get(['v.start_date', 'v.end_date', 'v.status', 'f.family_name']);

        $out = [];
        foreach ($rows as $r) {
            foreach ($this->eachDay($r->start_date, $r->end_date, $from, $to) as $d) {
                $out[] = [
                    'date' => $d,
                    'kind' => 'vacation',
                    'icon' => $r->status === 'pending' ? '🕗' : '✈️',
                    'title' => (string) ($r->family_name ?: 'Family'),
                    'detail' => 'vacation' . ($r->status === 'pending' ? ' (pending)' : ''),
                    'who' => 'family',
                    'tone' => $r->status === 'pending' ? 'pending' : 'away',
                ];
            }
        }
        return $out;
    }

    /** Closed days, from the same helper the rest of the platform reads. */
    private function closures(array $centreIds, Carbon $from, Carbon $to): array
    {
        $out = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $date = $d->toDateString();
            foreach ($centreIds as $cid) {
                // forDate returns the closure ROW (or null); reason() turns that row into
                // words. Passing ids straight to reason() is a type error, not a miss.
                $row = Closures::forDate((int) $cid, $date);
                if (! $row) {
                    continue;
                }
                $reason = Closures::reason($row) ?: 'Closed';
                $out[] = [
                    'date' => $date,
                    'kind' => 'closure',
                    'icon' => '🚫',
                    'title' => 'Closed',
                    'detail' => (string) $reason,
                    'who' => 'centre',
                    'tone' => 'closed',
                ];
                break;   // one "Closed" marker per day is enough for the grid
            }
        }
        return $out;
    }

    /** @return string[] the dates a range covers, clipped to the window being drawn. */
    private function eachDay($start, $end, Carbon $from, Carbon $to): array
    {
        $s = Carbon::parse($start)->startOfDay();
        $e = Carbon::parse($end)->startOfDay();
        if ($s->lt($from)) { $s = $from->copy(); }
        if ($e->gt($to)) { $e = $to->copy(); }

        $days = [];
        for ($d = $s->copy(); $d->lte($e); $d->addDay()) {
            $days[] = $d->toDateString();
        }
        return $days;
    }

    private function resolveAgencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($header) {
                    $q->where('role', 'platform_admin')->orWhere('agency_id', $header);
                })->exists()) {
            return $header;
        }
        return (int) DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director', 'educator'])
            ->value('agency_id');
    }
}
