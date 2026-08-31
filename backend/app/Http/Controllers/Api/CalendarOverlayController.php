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

        // Which layers this agency wants. Filtering here rather than in the browser:
        // a layer that is switched off should never be sent, not merely not drawn.
        $cal = \App\Http\Controllers\Api\CalendarSettingsController::read($agencyId);

        $events = array_merge(
            $cal['show_birthdays'] ? $this->birthdays($centreIds, $agencyId, $from, $to, $cal) : [],
            $cal['show_absences'] ? $this->childAbsences($centreIds, $from, $to) : [],
            $cal['show_timeoff'] ? $this->staffTimeOff($agencyId, $centreIds, $from, $to) : [],
            $cal['show_vacations'] ? $this->vacationHolds($centreIds, $from, $to) : [],
            $cal['show_closures'] ? $this->closures($centreIds, $from, $to) : [],
            ($cal['show_departures'] ?? true) ? $this->departures($agencyId, $centreIds, $from, $to) : [],
        );

        if (! $cal['show_pending']) {
            $events = array_values(array_filter($events, fn ($e) => $e['tone'] !== 'pending'));
        }

        usort($events, fn ($a, $b) => [$a['date'], $a['kind']] <=> [$b['date'], $b['kind']]);

        return response()->json([
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'events' => $events,
        ]);
    }

    /**
     * Families leaving, and staff whose last day falls in this range.
     *
     * Three sources because a departure is recorded in three places and always has been:
     * a family's agreed last day, a child withdrawal, and a staff member's exit. They are
     * one thing to the person reading a calendar, so they are drawn as one kind.
     *
     * A date still ahead is marked pending — it is a plan, and plans change; a date past
     * is stated plainly, because by then it is simply what happened.
     */
    private function departures(int $agencyId, array $centreIds, Carbon $from, Carbon $to): array
    {
        $out = [];
        $today = now()->toDateString();
        $f = $from->toDateString();
        $t = $to->toDateString();

        // ── Families ────────────────────────────────────────────────────────
        try {
            $fams = DB::table('families as fam')
                ->join('centres as c', 'c.id', '=', 'fam.centre_id')
                ->whereIn('fam.centre_id', $centreIds)
                ->whereNotNull('fam.departure_date')
                ->whereBetween('fam.departure_date', [$f, $t])
                ->get(['fam.id', 'fam.family_name', 'fam.departure_date', 'fam.departure_applied_at', 'c.name as centre_name']);

            foreach ($fams as $r) {
                $ahead = $r->departure_date > $today;
                $out[] = [
                    'date' => (string) $r->departure_date,
                    'kind' => 'departure',
                    'icon' => '👋',
                    'title' => 'Last day — '.$r->family_name,
                    'detail' => $ahead
                        ? 'Family leaving '.$r->centre_name.'. They keep access until this day.'
                        : 'Family left '.$r->centre_name.'.',
                    'who' => 'family',
                    'tone' => $ahead ? 'pending' : 'closed',
                    'family_id' => (int) $r->id,
                ];
            }
        } catch (\Throwable $e) {
            // A missing column must not empty the whole calendar.
        }

        // ── Individual children ─────────────────────────────────────────────
        try {
            $kids = DB::table('children as ch')
                ->join('families as fam', 'fam.id', '=', 'ch.family_id')
                ->whereIn('fam.centre_id', $centreIds)
                ->whereNotNull('ch.withdrawn_at')
                ->whereBetween('ch.withdrawn_at', [$f, $t])
                // A family departure already draws the whole family; drawing each child
                // as well would bury the day under one event per sibling.
                ->whereNull('fam.departure_date')
                ->get(['ch.id', 'ch.first_name', 'ch.preferred_name', 'ch.last_name', 'ch.withdrawn_at']);

            foreach ($kids as $r) {
                $name = trim(($r->preferred_name ?: $r->first_name).' '.($r->last_name ?? ''));
                $ahead = $r->withdrawn_at > $today;
                $out[] = [
                    'date' => substr((string) $r->withdrawn_at, 0, 10),
                    'kind' => 'departure',
                    'icon' => '👋',
                    'title' => 'Last day — '.$name,
                    'detail' => $ahead ? 'Child withdrawing.' : 'Child withdrawn.',
                    'who' => 'child',
                    'tone' => $ahead ? 'pending' : 'closed',
                    'child_id' => (int) $r->id,
                ];
            }
        } catch (\Throwable $e) {
        }

        // ── Staff and providers ─────────────────────────────────────────────
        try {
            $staff = DB::table('audit_logs as a')
                ->leftJoin('users as u', 'u.id', '=', 'a.entity_id')
                ->where('a.entity_type', 'user')
                ->whereIn('a.action', ['staff.offboarded', 'centre.offboarded', 'provider.offboarded'])
                ->where('a.agency_id', $agencyId)
                ->get(['a.entity_id', 'a.payload', 'u.first_name', 'u.last_name']);

            foreach ($staff as $r) {
                $payload = json_decode((string) $r->payload, true) ?: [];
                $day = $payload['last_day'] ?? $payload['effective_date'] ?? null;
                if (! $day) {
                    continue;
                }
                $day = substr((string) $day, 0, 10);
                if ($day < $f || $day > $t) {
                    continue;
                }
                $name = trim(($r->first_name ?? '').' '.($r->last_name ?? '')) ?: 'A team member';
                $ahead = $day > $today;
                $out[] = [
                    'date' => $day,
                    'kind' => 'departure',
                    'icon' => '👋',
                    'title' => 'Last day — '.$name,
                    'detail' => $ahead ? 'Leaving the team.' : 'Left the team.',
                    'who' => 'staff',
                    'tone' => $ahead ? 'pending' : 'closed',
                    'user_id' => (int) $r->entity_id,
                ];
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** Birthdays recur, so they are matched on month-day across the range, not on the stored year. */
    private function birthdays(array $centreIds, int $agencyId, Carbon $from, Carbon $to, array $cal = []): array
    {
        $out = [];
        $wantChild = $cal['show_child_birthdays'] ?? true;
        $wantStaff = $cal['show_staff_birthdays'] ?? true;

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

        foreach ([['child', $wantChild ? $children : collect()], ['staff', $wantStaff ? $staff : collect()]] as [$who, $rows]) {
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
            ->get(['t.user_id', 't.start_at', 't.end_at', 't.request_type', 't.status', 't.all_day',
                   't.start_time', 't.end_time', 'u.first_name', 'u.last_name']);

        $out = [];
        foreach ($rows as $r) {
            foreach ($this->eachDay($r->start_at, $r->end_at, $from, $to) as $d) {
                $out[] = [
                    'date' => $d,
                    'kind' => 'timeoff',
                    'icon' => $r->status === 'pending' ? '🕗' : '🌴',
                    'title' => trim($r->first_name . ' ' . $r->last_name),
                    /* WHOSE time off, as an id. The calendar can filter to one staff
                       member, and matching on the displayed name is not safe here — the
                       same person legitimately holds several accounts and different
                       people share names. (2026-08-30) */
                    'user_id' => (int) $r->user_id,
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

                // Everything the row already knew and the grid never showed. "Closed"
                // alone cannot be read: it does not say which centre, why, for how long,
                // whether fees still apply, or who decided it.
                $meta = $this->closureMeta($row, (int) $cid);

                $out[] = [
                    'date' => $date,
                    'kind' => 'closure',
                    'icon' => '🚫',
                    // The title names the centre, so several closures on one day are
                    // told apart at a glance.
                    'title' => 'Closed — ' . $meta['centre_name'],
                    'detail' => (string) $reason,
                    'who' => 'centre',
                    'tone' => 'closed',
                    'closure_id' => (int) $row->id,
                    'centre_id' => (int) $cid,
                    'centre_name' => $meta['centre_name'],
                    'closure_type' => $row->closure_type,
                    'type_label' => $meta['type_label'],
                    'reason' => $row->reason,
                    'starts_on' => $row->closure_date ? substr((string) $row->closure_date, 0, 10) : null,
                    'ends_on' => $row->end_date ? substr((string) $row->end_date, 0, 10) : null,
                    'date_label' => $meta['date_label'],
                    'affects_billing' => (bool) $row->affects_billing,
                    'added_by' => $meta['added_by'],
                    'added_at' => $row->created_at,
                ];
                // No break: a second centre closing the same day is a separate fact, and
                // collapsing them hid it entirely.
            }
        }
        return $out;
    }

    /** Centre name, readable type, range and who entered it — cached per closure row. */
    private array $closureMetaCache = [];

    private function closureMeta(object $row, int $centreId): array
    {
        $key = (int) $row->id;
        if (isset($this->closureMetaCache[$key])) {
            return $this->closureMetaCache[$key];
        }

        $labels = [
            'holiday' => 'Holiday', 'pd_day' => 'PD day', 'emergency' => 'Emergency',
            'renovation' => 'Renovation', 'other' => 'Closure',
        ];

        $addedBy = null;
        if (! empty($row->created_by_id)) {
            $u = DB::table('users')->where('id', $row->created_by_id)->first(['first_name', 'last_name']);
            if ($u) {
                $addedBy = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: null;
            }
        }

        return $this->closureMetaCache[$key] = [
            'centre_name' => (string) (DB::table('centres')->where('id', $centreId)->value('name') ?: 'Centre'),
            'type_label' => $labels[$row->closure_type] ?? 'Closure',
            'date_label' => \App\Support\Closures::dateLabel($row),
            'added_by' => $addedBy,
        ];
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
