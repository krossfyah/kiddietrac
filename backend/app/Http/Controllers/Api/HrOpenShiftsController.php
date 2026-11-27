<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrOpenShifts;
use App\Support\HrShiftNotices;
use App\Support\Live;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 3 (2026-11-27): the open-shift board (App\Support\HrOpenShifts) and the shift-notice
 * settings (App\Support\HrShiftNotices). Directors: their own centres; admins: the agency; staff: open shifts at the
 * centres they work at, and their own claims. Nobody confirms their own claim.
 */
class HrOpenShiftsController extends Controller
{
    use HrAccess;

    private function agency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(HrOpenShifts::ready(), 503, 'Open shifts are being set up. Try again in a minute.');

        return $aid;
    }

    /** Open centres this manager runs. */
    private function managed(Request $request, int $aid): array
    {
        $open = Live::centreIds($aid);
        if ($this->hrIsAdmin($request)) return $open;

        return array_values(array_intersect($open, CentreScope::ids($request, $aid) ?? []));
    }

    private function find(int $aid, int $id): object
    {
        $o = DB::table('hr_open_shifts')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($o, 404, 'That open shift was not found.');

        return $o;
    }

    private function names(int $aid): array
    {
        return [DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')->where('c.agency_id', $aid)->pluck('r.name', 'r.id')->all(),
            DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all()];
    }

    // ── directors / admins ──────────────────────────────────────────────────────────────────

    /** GET /hr/open-shifts?centre_id=&week=Y-m-d — the board for one centre's week, with claims and ratio shortfalls. */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $mine = $this->managed($request, $aid);
        abort_unless($mine, 404, 'You have no open centres.');
        $cid = (int) $request->query('centre_id', $mine[0]);
        abort_unless(in_array($cid, $mine, true), 404, 'That centre was not found.');
        $centre = DB::table('centres')->where('id', $cid)->first();
        $week = Carbon::parse((string) ($request->query('week') ?: AgencyTime::today($aid)))->startOfWeek(Carbon::MONDAY);
        $end = $week->copy()->addDays(6);
        [$rooms, $centres] = $this->names($aid);
        $list = DB::table('hr_open_shifts')->where('agency_id', $aid)->where('centre_id', $cid)->where('status', '!=', 'cancelled')
            ->whereBetween('shift_date', [$week->toDateString(), $end->toDateString()])->orderBy('shift_date')->orderBy('start_time')->get();
        $claims = DB::table('hr_open_shift_claims')->whereIn('open_shift_id', $list->pluck('id')->all() ?: [0])->orderBy('id')->get()->groupBy('open_shift_id');
        $names = $this->hrNames($claims->flatten()->pluck('user_id')->merge($list->pluck('filled_user_id'))->filter()->map(fn ($v) => (int) $v)->unique()->all());
        // Shortfalls still ahead (today on): a past day cannot be covered any more.
        $todayC = Carbon::now(AgencyTime::tzForCentre($cid))->toDateString();
        $gaps = array_values(array_filter(HrOpenShifts::gaps($centre, $week), fn ($g) => $g['date'] >= $todayC));
        $staff = HrLeave::staffIds($aid, [$cid]);
        $me = (int) $request->user()->id;
        $rows = $list->map(function ($o) use ($rooms, $centres, $claims, $names, $gaps, $aid, $staff, $me) {
            $r = HrOpenShifts::shape($o, $rooms, $centres);
            $r['covers'] = HrOpenShifts::covers($o, $gaps);
            $r['filled_by'] = $o->filled_user_id ? ($names[(int) $o->filled_user_id] ?? '') : null;
            $r['claims'] = collect($claims[$o->id] ?? [])->map(fn ($c) => ['id' => (int) $c->id, 'user_id' => (int) $c->user_id, 'person' => $names[(int) $c->user_id] ?? '',
                'status' => $c->status, 'note' => $c->note, 'created_at' => (string) $c->created_at,
                'problem' => $c->status === 'pending' && $o->status === 'open' ? HrOpenShifts::refusal($aid, $o, (int) $c->user_id, $staff) : null,
                'mine' => (int) $c->user_id === $me])->values()->all();
            $r['eligible'] = $o->status === 'open' ? count(HrOpenShifts::eligible($aid, $o)) : null;

            return $r;
        })->values()->all();

        return response()->json(['centres' => DB::table('centres')->whereIn('id', $mine)->orderBy('name')->get(['id', 'name']),
            'centre_id' => $cid, 'week' => $week->toDateString(), 'week_end' => $end->toDateString(), 'today' => AgencyTime::today($aid),
            // The next open shift after this week, so an empty week can point to it.
            'next_open' => DB::table('hr_open_shifts')->where('agency_id', $aid)->where('centre_id', $cid)->where('status', 'open')->where('shift_date', '>', $end->toDateString())->min('shift_date'),
            'open_shifts' => $rows, 'ratio_gaps' => array_map(fn ($g) => $g + ['posted' => (bool) collect($rows)->first(fn ($r) => $r['status'] === 'open' && $r['covers'] && $r['covers'] == $g)], $gaps),
            'rooms' => DB::table('rooms')->where('centre_id', $cid)->orderBy('name')->get(['id', 'name']),
            'open_time' => $centre->open_time ? substr((string) $centre->open_time, 0, 5) : null, 'close_time' => $centre->close_time ? substr((string) $centre->close_time, 0, 5) : null,
            'roles' => HrOpenShifts::ROLES, 'certs' => HrOpenShifts::certChoices($aid), 'can_manage' => $this->hrIsAdmin($request),
            'notice_settings' => HrShiftNotices::settings($aid), 'quiet_choices' => HrShiftNotices::QUIET]);
    }

    /** POST /hr/open-shifts {centre_id, room_id, dates[], start, end, role, cert_type, note} — one open shift per date. */
    public function store(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $d = $request->validate(['centre_id' => 'required|integer', 'room_id' => 'required|integer', 'dates' => 'required|array|min:1|max:14',
            'dates.*' => 'required|date_format:Y-m-d', 'start' => 'required|date_format:H:i', 'end' => 'required|date_format:H:i',
            'role' => 'nullable|in:' . implode(',', array_keys(HrOpenShifts::ROLES)), 'cert_type' => 'nullable|string|max:40', 'note' => 'nullable|string|max:300'],
            ['dates.required' => 'Choose at least one day.', 'dates.max' => 'Post up to 14 days at a time.']);
        $cid = (int) $d['centre_id'];
        abort_unless(in_array($cid, $this->managed($request, $aid), true), 404, 'That centre was not found.');
        abort_unless(DB::table('rooms')->where('id', (int) $d['room_id'])->where('centre_id', $cid)->exists(), 422, 'Choose a room at this centre.');
        abort_unless($d['start'] < $d['end'], 422, 'The shift ends after it starts.');
        if (! empty($d['cert_type'])) abort_unless(isset(HrOpenShifts::certChoices($aid)[$d['cert_type']]), 422, 'Choose a certificate from the list.');
        $today = Carbon::now(AgencyTime::tzForCentre($cid))->toDateString();
        $dates = array_values(array_unique($d['dates']));
        foreach ($dates as $x) abort_if($x < $today, 422, 'Choose today or a later day.');
        abort_if(in_array($today, $dates, true) && $d['start'] <= Carbon::now(AgencyTime::tzForCentre($cid))->format('H:i'), 422,
            'That time today has already started. Choose a later start or another day.');
        $ids = [];
        DB::transaction(function () use ($d, $aid, $cid, $dates, $request, &$ids) {
            foreach ($dates as $x) {
                $ids[] = (int) DB::table('hr_open_shifts')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'room_id' => (int) $d['room_id'], 'shift_date' => $x,
                    'start_time' => $d['start'] . ':00', 'end_time' => $d['end'] . ':00', 'role' => $d['role'] ?? 'support', 'cert_type' => ($d['cert_type'] ?? null) ?: null,
                    'note' => $d['note'] ?? null, 'status' => 'open', 'created_by_id' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        $this->hrNote($aid, 'hr.open_shift_posted', 'hr_open_shift', $ids[0], ['ids' => $ids, 'centre_id' => $cid, 'dates' => $dates]);
        // ONE notice per eligible person for the whole post, however many days it covers.
        $me = (int) $request->user()->id;
        $who = [];
        $first = null;
        foreach (DB::table('hr_open_shifts')->whereIn('id', $ids)->orderBy('shift_date')->get() as $o) {
            $first = $first ?: $o;
            foreach (array_keys(HrOpenShifts::eligible($aid, $o, $me)) as $u) $who[$u] = ($who[$u] ?? 0) + 1;
        }
        $centreName = (string) DB::table('centres')->where('id', $cid)->value('name');
        foreach ($who as $u => $n) {
            HrOpenShifts::bell($aid, [$u], $n === 1 ? 'Open shift you can pick up' : $n . ' open shifts you can pick up',
                ($n === 1 && $first ? HrOpenShifts::when($first) . ' at ' : 'At ') . $centreName . '. Ask for it in My HR.');
        }

        return response()->json(['ids' => $ids, 'told' => count($who)], 201);
    }

    /** POST /hr/open-shifts/{id}/cancel — take an open shift off the board; anyone who asked is told. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $o = $this->find($aid, $id);
        abort_unless(in_array((int) $o->centre_id, $this->managed($request, $aid), true), 404, 'That open shift was not found.');
        abort_unless($o->status === 'open', 422, 'Only an open shift can be taken off the board.');
        $asked = DB::table('hr_open_shift_claims')->where('open_shift_id', $id)->where('status', 'pending')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        DB::transaction(function () use ($id, $request) {
            DB::table('hr_open_shifts')->where('id', $id)->where('status', 'open')->update(['status' => 'cancelled', 'decided_by_id' => (int) $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
            DB::table('hr_open_shift_claims')->where('open_shift_id', $id)->where('status', 'pending')->update(['status' => 'declined', 'decided_by_id' => (int) $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.open_shift_cancelled', 'hr_open_shift', $id);
        HrOpenShifts::bell($aid, $asked, 'Open shift no longer needed', HrOpenShifts::when($o) . '. It was taken off the board.');

        return response()->json(['ok' => true]);
    }

    /** POST /hr/open-shifts/{id}/confirm {claim_id} — the shift goes on the rota for that person. */
    public function confirm(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $o = $this->find($aid, $id);
        abort_unless(in_array((int) $o->centre_id, $this->managed($request, $aid), true), 404, 'That open shift was not found.');
        $d = $request->validate(['claim_id' => 'required|integer']);
        $me = (int) $request->user()->id;
        $c = DB::table('hr_open_shift_claims')->where('id', (int) $d['claim_id'])->where('open_shift_id', $id)->first();
        abort_unless($c, 404, 'That request was not found.');
        abort_if((int) $c->user_id === $me, 403, 'Another director or an admin confirms an open shift you asked for.');
        abort_unless($o->status === 'open' && $c->status === 'pending', 422, 'This open shift is no longer waiting for a decision.');
        if ($why = HrOpenShifts::refusal($aid, $o, (int) $c->user_id)) abort(422, 'They cannot take it now: ' . $why . '.');
        $shiftId = 0;
        $others = [];
        DB::transaction(function () use ($o, $c, $me, &$shiftId, &$others) {
            $fresh = DB::table('hr_open_shifts')->where('id', $o->id)->lockForUpdate()->first();
            abort_unless($fresh && $fresh->status === 'open', 422, 'This open shift was filled a moment ago.');
            $shiftId = (int) DB::table('shifts')->insertGetId(['user_id' => (int) $c->user_id, 'room_id' => (int) $o->room_id, 'starts_at' => HrOpenShifts::startsAt($o),
                'ends_at' => HrOpenShifts::endsAt($o), 'role' => $o->role ?: 'support', 'status' => 'scheduled', 'created_at' => now()]);
            DB::table('hr_open_shifts')->where('id', $o->id)->update(['status' => 'filled', 'shift_id' => $shiftId, 'filled_user_id' => (int) $c->user_id,
                'decided_by_id' => $me, 'decided_at' => now(), 'updated_at' => now()]);
            DB::table('hr_open_shift_claims')->where('id', $c->id)->update(['status' => 'approved', 'decided_by_id' => $me, 'decided_at' => now(), 'updated_at' => now()]);
            $others = DB::table('hr_open_shift_claims')->where('open_shift_id', $o->id)->where('status', 'pending')->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            DB::table('hr_open_shift_claims')->where('open_shift_id', $o->id)->where('status', 'pending')->update(['status' => 'declined', 'decided_by_id' => $me, 'decided_at' => now(), 'updated_at' => now()]);
        });
        $this->hrNote($aid, 'hr.open_shift_filled', 'hr_open_shift', $id, ['claim_id' => (int) $c->id, 'user_id' => (int) $c->user_id, 'shift_id' => $shiftId]);
        \App\Support\Audit::write(['user_id' => $me, 'agency_id' => $aid, 'action' => 'shift.created', 'entity_type' => 'shift', 'entity_id' => $shiftId,
            'payload' => json_encode(['source' => 'open shift #' . $id, 'user_id' => (int) $c->user_id])]);
        HrOpenShifts::bell($aid, [(int) $c->user_id], 'Open shift confirmed: ' . Carbon::parse(HrOpenShifts::startsAt($o))->format('D, M j'), HrOpenShifts::when($o) . '. It is on your schedule.');
        HrOpenShifts::bell($aid, $others, 'Open shift filled', HrOpenShifts::when($o) . ' went to a colleague. Thanks for offering.');

        return response()->json(['ok' => true, 'shift_id' => $shiftId]);
    }

    /** POST /hr/open-shift-claims/{id}/decline {comment} */
    public function decline(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $this->hrNeedManager($request);
        $c = DB::table('hr_open_shift_claims')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($c, 404, 'That request was not found.');
        $o = $this->find($aid, (int) $c->open_shift_id);
        abort_unless(in_array((int) $o->centre_id, $this->managed($request, $aid), true), 404, 'That request was not found.');
        abort_if((int) $c->user_id === (int) $request->user()->id, 403, 'Another director or an admin decides a request you made.');
        abort_unless($c->status === 'pending', 422, 'This request is already decided.');
        $d = $request->validate(['comment' => 'nullable|string|max:300']);
        DB::table('hr_open_shift_claims')->where('id', $id)->update(['status' => 'declined', 'decided_by_id' => (int) $request->user()->id, 'decided_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.open_shift_claim_declined', 'hr_open_shift_claim', $id);
        HrOpenShifts::bell($aid, [(int) $c->user_id], 'Open shift request declined', HrOpenShifts::when($o) . (! empty($d['comment']) ? '. ' . $d['comment'] : '.'));

        return response()->json(['ok' => true]);
    }

    /** GET /hr/shift-notices/settings  ·  PUT (admins) {enabled, email, nightly_fill, quiet_minutes} */
    public function noticeSettings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);

        return response()->json(['settings' => HrShiftNotices::settings($aid), 'can_manage' => $this->hrIsAdmin($request), 'quiet_choices' => HrShiftNotices::QUIET]);
    }

    public function saveNoticeSettings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['enabled' => 'required|boolean', 'email' => 'required|boolean', 'nightly_fill' => 'required|boolean',
            'quiet_minutes' => 'required|integer|in:' . implode(',', HrShiftNotices::QUIET)]);
        $was = HrShiftNotices::settings($aid);
        $now = HrShiftNotices::saveSettings($aid, $d);
        $this->hrNote($aid, 'hr.shift_notice_settings_saved', 'agency', $aid, ['was' => $was, 'now' => $now]);

        return response()->json(['settings' => $now]);
    }

    // ── staff ───────────────────────────────────────────────────────────────────────────────

    /** GET /hr/me/open-shifts — upcoming open shifts at my centres, whether I can take each, and my requests. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR record was not found.');
        $centres = array_values(array_intersect(Live::centreIds($aid), CentreScope::centresOfUser($me, $aid)));
        $today = AgencyTime::today($aid);
        [$rooms, $names] = $this->names($aid);
        $list = DB::table('hr_open_shifts')->where('agency_id', $aid)->whereIn('centre_id', $centres ?: [0])->where('status', 'open')
            ->whereBetween('shift_date', [$today, Carbon::parse($today)->addDays(42)->toDateString()])->orderBy('shift_date')->orderBy('start_time')->limit(80)->get();
        $claims = DB::table('hr_open_shift_claims')->where('agency_id', $aid)->where('user_id', $me)->orderByDesc('id')->limit(40)->get();
        $byShift = $claims->keyBy('open_shift_id');
        $rows = $list->map(function ($o) use ($rooms, $names, $byShift, $aid, $me) {
            $r = HrOpenShifts::shape($o, $rooms, $names);
            $c = $byShift[$o->id] ?? null;
            $r['my_claim'] = $c ? ['id' => (int) $c->id, 'status' => $c->status] : null;
            $r['problem'] = HrOpenShifts::refusal($aid, $o, $me);

            return $r;
        })->filter(fn ($r) => $r['my_claim'] || $r['problem'] !== 'Not on this centre\'s staff')->values()->all();
        // Decided requests of the last few weeks (filled by me, or not).
        $past = $claims->whereIn('status', ['approved', 'declined'])->take(10);
        $pastShifts = DB::table('hr_open_shifts')->whereIn('id', $past->pluck('open_shift_id')->all() ?: [0])->get()->keyBy('id');
        $history = $past->map(fn ($c) => isset($pastShifts[$c->open_shift_id]) ? HrOpenShifts::shape($pastShifts[$c->open_shift_id], $rooms, $names) + ['claim_status' => $c->status] : null)->filter()->values()->all();

        return response()->json(['open_shifts' => $rows, 'history' => $history]);
    }

    /** POST /hr/open-shifts/{id}/claim {note} */
    public function claim(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $o = $this->find($aid, $id);
        abort_unless($o->status === 'open', 422, 'This open shift is no longer available.');
        if ($why = HrOpenShifts::refusal($aid, $o, $me)) {
            abort($why === 'Not on this centre\'s staff' ? 404 : 422, $why === 'Not on this centre\'s staff' ? 'That open shift was not found.' : $why . '.');
        }
        $d = $request->validate(['note' => 'nullable|string|max:300']);
        $c = DB::table('hr_open_shift_claims')->where('open_shift_id', $id)->where('user_id', $me)->first();
        abort_if($c && $c->status === 'pending', 422, 'You already asked for this shift.');
        abort_if($c && $c->status === 'declined', 422, 'Your request for this shift was declined.');
        if ($c) {
            DB::table('hr_open_shift_claims')->where('id', $c->id)->update(['status' => 'pending', 'note' => $d['note'] ?? null, 'decided_by_id' => null, 'decided_at' => null, 'updated_at' => now()]);
            $cid = (int) $c->id;
        } else {
            $cid = (int) DB::table('hr_open_shift_claims')->insertGetId(['agency_id' => $aid, 'open_shift_id' => $id, 'user_id' => $me, 'status' => 'pending',
                'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->hrNote($aid, 'hr.open_shift_claimed', 'hr_open_shift_claim', $cid, ['open_shift_id' => $id]);
        HrOpenShifts::bellManagers($aid, (int) $o->centre_id, [$me], $request->user()->first_name . ' asked for an open shift',
            HrOpenShifts::when($o) . '. Confirm it in HR > Open shifts.', '#hr?tab=openshifts&open=' . $id);

        return response()->json(['id' => $cid], 201);
    }

    /** POST /hr/open-shifts/{id}/withdraw — take back my request before it is decided. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->agency($request);
        $me = (int) $request->user()->id;
        $n = DB::table('hr_open_shift_claims')->where('open_shift_id', $id)->where('agency_id', $aid)->where('user_id', $me)->where('status', 'pending')
            ->update(['status' => 'withdrawn', 'updated_at' => now()]);
        abort_unless($n, 404, 'You have no request waiting for this shift.');
        $this->hrNote($aid, 'hr.open_shift_claim_withdrawn', 'hr_open_shift', $id);

        return response()->json(['ok' => true]);
    }
}
