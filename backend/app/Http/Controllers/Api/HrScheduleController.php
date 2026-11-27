<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrAvailability;
use App\Support\HrLeave;
use App\Support\Live;
use App\Support\Notify;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 3, item 10: availability, shift swaps / giveaways and the planning view, on the EXISTING shifts
 * table (rules in App\Support\HrAvailability). Staff: their own availability and shifts. Directors: their own centres.
 * Admins: the agency. Cost per shift only for someone who can see pay today (manager + payroll feature).
 */
class HrScheduleController extends Controller
{
    use HrAccess;

    private function canSeePay(Request $request, int $aid): bool
    {
        return $this->hrIsManager($request) && Features::on($aid, 'payroll');
    }

    private function me(Request $request, int $aid): int
    {
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR record was not found.');

        return $me;
    }

    /** A shift of this agency with its centre: [shift, centre_id] or 404. */
    private function shift(int $aid, int $id): array
    {
        $s = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('s.id', $id)->where('c.agency_id', $aid)->first(['s.*', 'r.centre_id', 'r.name as room']);
        abort_unless($s, 404, 'That shift was not found.');

        return [$s, (int) $s->centre_id];
    }

    private function localNow(int $centreId): string
    {
        return Carbon::now(AgencyTime::tzForCentre($centreId))->format('Y-m-d H:i:s');
    }

    /** The centres this manager plans for (open ones only). */
    private function managedCentres(Request $request, int $aid): array
    {
        $open = Live::centreIds($aid);
        if ($this->hrIsAdmin($request)) return $open;

        return array_values(array_intersect($open, CentreScope::ids($request, $aid) ?? []));
    }

    private function shiftRow(object $s, array $names): array
    {
        return ['id' => (int) $s->id, 'user_id' => (int) $s->user_id, 'person' => $names[(int) $s->user_id] ?? '', 'room' => $s->room ?? null,
            'centre_id' => isset($s->centre_id) ? (int) $s->centre_id : null, 'date' => substr((string) $s->starts_at, 0, 10),
            'start' => substr((string) $s->starts_at, 11, 5), 'end' => substr((string) $s->ends_at, 11, 5), 'status' => $s->status,
            'hours' => HrAvailability::hours((string) $s->starts_at, (string) $s->ends_at)];
    }

    private function swapRows(int $aid, $swaps): array
    {
        $swaps = collect($swaps);
        $shiftIds = $swaps->pluck('shift_id')->merge($swaps->pluck('swap_shift_id'))->filter()->all();
        $shifts = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->whereIn('s.id', $shiftIds ?: [0])
            ->get(['s.*', 'r.centre_id', 'r.name as room'])->keyBy('id');
        $names = $this->hrNames($swaps->pluck('from_user_id')->merge($swaps->pluck('to_user_id'))->merge($swaps->pluck('accepted_by_id'))
            ->merge($shifts->pluck('user_id'))->filter()->map(fn ($v) => (int) $v)->unique()->all());
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();

        return $swaps->map(function ($w) use ($shifts, $names, $centres, $aid) {
            $s = $shifts[$w->shift_id] ?? null;
            $o = $w->swap_shift_id ? ($shifts[$w->swap_shift_id] ?? null) : null;
            $taker = (int) ($w->accepted_by_id ?: $w->to_user_id);
            $conflict = null;
            if ($s && $taker) {
                $conflict = HrAvailability::blocks($aid, $taker, substr((string) $s->starts_at, 0, 10), substr((string) $s->starts_at, 11, 5), substr((string) $s->ends_at, 11, 5));
            }

            return ['id' => (int) $w->id, 'kind' => $w->kind, 'status' => $w->status, 'note' => $w->note, 'from_user_id' => (int) $w->from_user_id,
                'from' => $names[(int) $w->from_user_id] ?? '', 'to_user_id' => $w->to_user_id ? (int) $w->to_user_id : null,
                'to' => $w->to_user_id ? ($names[(int) $w->to_user_id] ?? '') : null, 'accepted_by_id' => $w->accepted_by_id ? (int) $w->accepted_by_id : null, 'accepted_by' => $w->accepted_by_id ? ($names[(int) $w->accepted_by_id] ?? '') : null,
                'centre' => $w->centre_id ? ($centres[(int) $w->centre_id] ?? null) : null, 'shift' => $s ? $this->shiftRow($s, $names) : null,
                'swap_shift' => $o ? $this->shiftRow($o, $names) : null, 'conflict' => $conflict, 'decision_comment' => $w->decision_comment,
                'created_at' => (string) $w->created_at];
        })->values()->all();
    }

    // ── staff: my shifts, availability, swaps ─────────────────────────────────────────────────

    /** GET /hr/me/schedule */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $today = AgencyTime::today($aid);
        $shifts = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->join('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('c.agency_id', $aid)->where('s.user_id', $me)->where('s.status', 'scheduled')
            ->whereBetween('s.starts_at', [$today . ' 00:00:00', Carbon::parse($today)->addDays(28)->toDateString() . ' 23:59:59'])
            ->orderBy('s.starts_at')->limit(60)->get(['s.*', 'r.centre_id', 'r.name as room']);
        $names = $this->hrNames([$me]);
        $myCentres = DB::table('role_assignments')->where('user_id', $me)->where('active', 1)->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        $swaps = DB::table('hr_shift_swaps')->where('agency_id', $aid)->whereIn('status', ['open', 'accepted'])
            ->where(fn ($w) => $w->where('from_user_id', $me)->orWhere('to_user_id', $me)->orWhere('accepted_by_id', $me)
                ->orWhere(fn ($g) => $g->whereNull('to_user_id')->where('kind', 'giveaway')->whereIn('centre_id', $myCentres ?: [0])))
            ->orderByDesc('id')->limit(60)->get();
        $unav = DB::table('hr_unavailability')->where('agency_id', $aid)->where('user_id', $me)->where('date_to', '>=', $today)->orderBy('date_from')->get();
        $weekly = [];
        foreach (HrAvailability::weekly($aid, $me) as $dow => $wins) foreach ($wins as [$a, $b]) $weekly[] = ['dow' => $dow, 'start' => $a, 'end' => $b];

        return response()->json(['me' => $me, 'today' => $today, 'shifts' => $shifts->map(fn ($s) => $this->shiftRow($s, $names))->all(),
            'swaps' => $this->swapRows($aid, $swaps), 'weekly' => $weekly, 'days' => HrAvailability::DAYS,
            'unavailable' => $unav->map(fn ($u) => ['id' => (int) $u->id, 'date_from' => (string) $u->date_from, 'date_to' => (string) $u->date_to,
                'start' => $u->start_time ? substr((string) $u->start_time, 0, 5) : null, 'end' => $u->end_time ? substr((string) $u->end_time, 0, 5) : null, 'reason' => $u->reason])->all()]);
    }

    /** PUT /hr/me/availability {days:[{dow,start,end}]} — replaces the weekly set; [] = available any time. */
    public function saveAvailability(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $d = $request->validate(['days' => 'present|array|max:28', 'days.*.dow' => 'required|integer|between:1,7',
            'days.*.start' => 'required|date_format:H:i', 'days.*.end' => 'required|date_format:H:i']);
        foreach ($d['days'] as $w) abort_unless($w['start'] < $w['end'], 422, 'Each time ends after it starts.');
        DB::transaction(function () use ($aid, $me, $d) {
            DB::table('hr_availability')->where('agency_id', $aid)->where('user_id', $me)->delete();
            foreach ($d['days'] as $w) {
                DB::table('hr_availability')->insert(['agency_id' => $aid, 'user_id' => $me, 'dow' => (int) $w['dow'], 'start_time' => $w['start'] . ':00',
                    'end_time' => $w['end'] . ':00', 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->hrNote($aid, 'hr.availability_saved', 'user', $me, ['windows' => count($d['days'])]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/me/unavailability — a one-off "can't work" date or range. */
    public function addUnavailable(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $d = $request->validate(['date_from' => 'required|date|after_or_equal:' . AgencyTime::today($aid), 'date_to' => 'nullable|date|after_or_equal:date_from',
            'start' => 'nullable|date_format:H:i|required_with:end', 'end' => 'nullable|date_format:H:i|required_with:start', 'reason' => 'nullable|string|max:200'],
            ['date_from.after_or_equal' => 'Choose today or a later date.']);
        if (! empty($d['start'])) abort_unless($d['start'] < $d['end'], 422, 'The time ends after it starts.');
        $id = (int) DB::table('hr_unavailability')->insertGetId(['agency_id' => $aid, 'user_id' => $me, 'date_from' => $d['date_from'], 'date_to' => $d['date_to'] ?? $d['date_from'],
            'start_time' => ! empty($d['start']) ? $d['start'] . ':00' : null, 'end_time' => ! empty($d['end']) ? $d['end'] . ':00' : null,
            'reason' => $d['reason'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.unavailability_added', 'hr_unavailability', $id, ['from' => $d['date_from'], 'to' => $d['date_to'] ?? $d['date_from']]);

        return response()->json(['id' => $id], 201);
    }

    /** DELETE /hr/me/unavailability/{id} */
    public function removeUnavailable(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $n = DB::table('hr_unavailability')->where('id', $id)->where('agency_id', $aid)->where('user_id', $me)->delete();
        abort_unless($n, 404, 'That date was not found.');
        $this->hrNote($aid, 'hr.unavailability_removed', 'hr_unavailability', $id);

        return response()->json(['ok' => true]);
    }

    /** GET /hr/swaps/options?shift_id= — colleagues at the shift's centre and their upcoming shifts (for a swap). */
    public function swapOptions(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        [$s, $centre] = $this->shift($aid, (int) $request->query('shift_id'));
        abort_unless((int) $s->user_id === $me, 404, 'That shift was not found.');
        $people = array_values(array_diff(HrLeave::staffIds($aid, [$centre]), [$me]));
        $names = $this->hrNames($people);
        $now = $this->localNow($centre);
        $theirs = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->where('r.centre_id', $centre)->whereIn('s.user_id', $people ?: [0])
            ->where('s.status', 'scheduled')->where('s.starts_at', '>', $now)->where('s.starts_at', '<', Carbon::parse($now)->addDays(28)->toDateTimeString())
            ->orderBy('s.starts_at')->limit(300)->get(['s.*', 'r.centre_id', 'r.name as room']);

        return response()->json(['people' => collect($names)->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->sortBy('name')->values()->all(),
            'shifts' => $theirs->map(fn ($x) => $this->shiftRow($x, $names))->all()]);
    }

    /** POST /hr/swaps — give away (to anyone at the centre, or a named colleague) or swap one of my upcoming shifts. */
    public function offer(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $d = $request->validate(['shift_id' => 'required|integer', 'kind' => 'required|in:giveaway,swap', 'to_user_id' => 'required_if:kind,swap|nullable|integer',
            'swap_shift_id' => 'required_if:kind,swap|nullable|integer', 'note' => 'nullable|string|max:300'],
            ['to_user_id.required_if' => 'Choose who to swap with.', 'swap_shift_id.required_if' => 'Choose their shift.']);
        [$s, $centre] = $this->shift($aid, (int) $d['shift_id']);
        abort_unless((int) $s->user_id === $me && $s->status === 'scheduled', 404, 'That shift was not found.');
        abort_unless((string) $s->starts_at > $this->localNow($centre), 422, 'That shift has already started.');
        abort_if(DB::table('hr_shift_swaps')->where('shift_id', $s->id)->whereIn('status', ['open', 'accepted'])->exists(), 422, 'This shift is already offered.');
        $to = ! empty($d['to_user_id']) ? (int) $d['to_user_id'] : null;
        if ($to) abort_unless($to !== $me && in_array($to, HrLeave::staffIds($aid, [$centre]), true), 422, 'Choose a colleague at this centre.');
        $other = null;
        if ($d['kind'] === 'swap') {
            [$other, $oc] = $this->shift($aid, (int) $d['swap_shift_id']);
            abort_unless((int) $other->user_id === $to && $other->status === 'scheduled' && $oc === $centre && (string) $other->starts_at > $this->localNow($centre), 422, 'Choose one of their upcoming shifts.');
        }
        $id = (int) DB::table('hr_shift_swaps')->insertGetId(['agency_id' => $aid, 'centre_id' => $centre, 'shift_id' => $s->id, 'from_user_id' => $me, 'kind' => $d['kind'],
            'to_user_id' => $to, 'swap_shift_id' => $other ? $other->id : null, 'status' => 'open', 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.shift_offered', 'hr_shift_swap', $id, ['kind' => $d['kind'], 'shift_id' => (int) $s->id, 'to' => $to]);
        $when = Carbon::parse($s->starts_at)->format('D M j, g:i A');
        $first = (string) $request->user()->first_name;
        $this->bell($to ? [$to] : array_values(array_diff(HrLeave::staffIds($aid, [$centre]), [$me])), $aid,
            $d['kind'] === 'swap' ? $first . ' wants to swap shifts with you' : $first . ' is giving away a shift', $when . ' (' . ($s->room ?? '') . ')');

        return response()->json(['id' => $id], 201);
    }

    private function swap(int $aid, int $id): object
    {
        $w = DB::table('hr_shift_swaps')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($w, 404, 'That shift offer was not found.');

        return $w;
    }

    /** POST /hr/swaps/{id}/accept — the named colleague, or (open giveaway) anyone at that centre. */
    public function accept(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request, $aid);
        $w = $this->swap($aid, $id);
        abort_if((int) $w->from_user_id === $me, 422, 'You offered this shift. A colleague accepts it.');
        abort_unless($w->status === 'open', 422, 'This offer is no longer open.');
        abort_unless($w->to_user_id ? (int) $w->to_user_id === $me : in_array($me, HrLeave::staffIds($aid, [(int) $w->centre_id]), true), 404, 'That shift offer was not found.');
        DB::table('hr_shift_swaps')->where('id', $id)->where('status', 'open')->update(['status' => 'accepted', 'accepted_by_id' => $me, 'accepted_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.shift_offer_accepted', 'hr_shift_swap', $id);
        $this->bell([(int) $w->from_user_id], $aid, $request->user()->first_name . ' accepted your shift offer', 'It now waits for a director to approve it.');

        return response()->json(['ok' => true]);
    }

    /** POST /hr/swaps/{id}/cancel — the person who offered it, before it is approved. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $w = $this->swap($aid, $id);
        abort_unless((int) $w->from_user_id === $me || ((int) $w->accepted_by_id === $me && $w->status === 'accepted'), 404, 'That shift offer was not found.');
        abort_unless(in_array($w->status, ['open', 'accepted'], true), 422, 'This offer is already closed.');
        $upd = (int) $w->from_user_id === $me ? ['status' => 'cancelled'] : ['status' => 'open', 'accepted_by_id' => null, 'accepted_at' => null];
        DB::table('hr_shift_swaps')->where('id', $id)->update($upd + ['updated_at' => now()]);
        $this->hrNote($aid, (int) $w->from_user_id === $me ? 'hr.shift_offer_cancelled' : 'hr.shift_offer_released', 'hr_shift_swap', $id);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/swaps/{id}/decide — a director (own centres) or admin approves (the shifts change hands) or declines. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $w = $this->swap($aid, $id);
        abort_unless(in_array((int) $w->centre_id, $this->managedCentres($request, $aid), true), 404, 'That shift offer was not found.');
        $me = (int) $request->user()->id;
        abort_if(in_array($me, [(int) $w->from_user_id, (int) $w->accepted_by_id], true), 403, 'Another director or an admin approves a swap you are part of.');
        $d = $request->validate(['decision' => 'required|in:approve,decline', 'comment' => 'nullable|string|max:500']);
        abort_unless($w->status === 'accepted' || ($w->status === 'open' && $d['decision'] === 'decline'), 422, $w->status === 'open' ? 'Nobody has accepted this offer yet.' : 'This offer is already closed.');
        if ($d['decision'] === 'approve') {
            DB::transaction(function () use ($w, $aid, $me, $d) {
                $s = DB::table('shifts')->where('id', $w->shift_id)->lockForUpdate()->first();
                abort_unless($s && (int) $s->user_id === (int) $w->from_user_id && $s->status === 'scheduled', 422, 'The shift has changed since it was offered.');
                if ($w->swap_shift_id) {
                    $o = DB::table('shifts')->where('id', $w->swap_shift_id)->lockForUpdate()->first();
                    abort_unless($o && (int) $o->user_id === (int) $w->accepted_by_id && $o->status === 'scheduled', 422, 'The other shift has changed since it was offered.');
                    DB::table('shifts')->where('id', $o->id)->update(['user_id' => (int) $w->from_user_id]);
                }
                DB::table('shifts')->where('id', $s->id)->update(['user_id' => (int) $w->accepted_by_id]);
                DB::table('hr_shift_swaps')->where('id', $w->id)->update(['status' => 'approved', 'decided_by_id' => $me, 'decided_at' => now(),
                    'decision_comment' => $d['comment'] ?? null, 'updated_at' => now()]);
            });
        } else {
            DB::table('hr_shift_swaps')->where('id', $id)->update(['status' => 'declined', 'decided_by_id' => $me, 'decided_at' => now(),
                'decision_comment' => $d['comment'] ?? null, 'updated_at' => now()]);
        }
        $st = $d['decision'] === 'approve' ? 'approved' : 'declined';
        $this->hrNote($aid, 'hr.shift_offer_' . $st, 'hr_shift_swap', $id, ['shift_id' => (int) $w->shift_id, 'swap_shift_id' => $w->swap_shift_id ? (int) $w->swap_shift_id : null]);
        $this->bell(array_values(array_filter([(int) $w->from_user_id, (int) $w->accepted_by_id])), $aid,
            $st === 'approved' ? 'Shift change approved' : 'Shift change declined', $st === 'approved' ? 'Your rota is updated.' : (string) ($d['comment'] ?? ''));

        return response()->json(['ok' => true, 'status' => $st]);
    }

    // ── directors / admins: the planning view ───────────────────────────────────────────────

    /** GET /hr/schedule/plan?centre_id=&week=Y-m-d */
    public function plan(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $mine = $this->managedCentres($request, $aid);
        abort_unless($mine, 404, 'You have no open centres to plan.');
        $cid = (int) $request->query('centre_id', $mine[0]);
        abort_unless(in_array($cid, $mine, true), 404, 'That centre was not found.');
        $centre = DB::table('centres')->where('id', $cid)->first();
        $week = Carbon::parse((string) ($request->query('week') ?: AgencyTime::today($aid)))->startOfWeek(Carbon::MONDAY);
        $end = $week->copy()->addDays(6);
        $rooms = DB::table('rooms')->where('centre_id', $cid)->orderBy('name')->get()->all();
        $shifts = DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->where('r.centre_id', $cid)->where('s.status', '!=', 'cancelled')
            ->whereBetween('s.starts_at', [$week->toDateString() . ' 00:00:00', $end->toDateString() . ' 23:59:59'])->orderBy('s.starts_at')
            ->get(['s.*', 'r.centre_id', 'r.name as room']);
        $people = $shifts->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $names = $this->hrNames($people);
        $pay = $this->canSeePay($request, $aid);
        $rates = $pay ? HrAvailability::rates($people) : [];
        $rows = [];
        $tot = ['hours' => 0.0, 'cost' => 0.0, 'salaried' => 0, 'no_rate' => 0];
        $byPerson = [];
        foreach ($shifts as $s) {
            $r = $this->shiftRow($s, $names);
            $r['conflict'] = HrAvailability::blocks($aid, (int) $s->user_id, $r['date'], $r['start'], $r['end']);
            $tot['hours'] += $r['hours'];
            $bp = &$byPerson[(int) $s->user_id];
            $bp = ($bp ?? ['name' => $r['person'], 'hours' => 0.0, 'cost' => null, 'shifts' => 0]);
            $bp['hours'] += $r['hours']; $bp['shifts']++;
            if ($pay) {
                [$rate, $type] = $rates[(int) $s->user_id] ?? [null, 'hourly'];
                if ($type === 'hourly' && $rate !== null) {
                    $r['cost'] = round($r['hours'] * $rate, 2);
                    $tot['cost'] += $r['cost'];
                    $bp['cost'] = round(($bp['cost'] ?? 0) + $r['cost'], 2);
                } elseif ($type !== 'hourly') {
                    $r['cost'] = null; $r['cost_note'] = 'Salaried'; $tot['salaried']++;
                } else {
                    $r['cost'] = null; $r['cost_note'] = 'No rate'; $tot['no_rate']++;
                }
            }
            unset($bp);
            $rows[] = $r;
        }
        $byRoom = [];
        foreach ($shifts as $s) $byRoom[(int) $s->room_id][] = $s;
        $gaps = $rooms ? HrAvailability::ratioGaps($centre, $rooms, $byRoom, $week) : [];
        $swaps = DB::table('hr_shift_swaps')->where('agency_id', $aid)->where('centre_id', $cid)->whereIn('status', ['open', 'accepted'])->orderByDesc('id')->limit(100)->get();
        $tot['hours'] = round($tot['hours'], 2);
        $tot['cost'] = round($tot['cost'], 2);
        if (! $pay) unset($tot['cost'], $tot['salaried'], $tot['no_rate']);

        return response()->json(['centres' => DB::table('centres')->whereIn('id', $mine)->orderBy('name')->get(['id', 'name']),
            'centre_id' => $cid, 'week' => $week->toDateString(), 'week_end' => $end->toDateString(), 'can_see_pay' => $pay,
            'shifts' => $rows, 'totals' => $tot, 'by_person' => array_values($byPerson), 'ratio_gaps' => $gaps,
            'swaps' => $this->swapRows($aid, $swaps), 'me' => (int) $request->user()->id,
            'rooms' => collect($rooms)->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name])->all()]);
    }

    private function bell(array $userIds, int $aid, string $title, string $body): void
    {
        $rows = [];
        foreach (array_unique(array_filter($userIds)) as $u) {
            $rows[] = ['user_id' => (int) $u, 'agency_id' => $aid, 'type' => 'hr', 'title' => $title, 'body' => $body,
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()];
        }
        try {
            if ($rows) Notify::write($rows);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
