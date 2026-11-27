<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrTime;
use App\Support\Live;
use App\Support\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 2: overtime requests (pay or time in lieu) and timesheet approval + lock (rules in
 * App\Support\HrTime). Always inside the active agency (HrAccess), fails closed.
 *  - Overtime: staff log their own (when the agency allows it); a director / admin may log it for someone they manage.
 *    A director decides for their own centres' people, an admin for the agency; nobody decides their own.
 *  - Timesheets: a director sees and approves their own centres, an admin every centre; only an admin unlocks.
 */
class HrTimeController extends Controller
{
    use HrAccess;

    private function hrTimeAgency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(HrTime::ready(), 503, 'Overtime and timesheets are being set up. Please try again in a minute.');

        return $aid;
    }

    // ── Overtime ──────────────────────────────────────────────────────────────────────

    private function managedQuery(Request $request, int $aid)
    {
        $q = DB::table('hr_overtime_requests')->where('agency_id', $aid);
        if (! $this->hrIsAdmin($request)) {
            $scope = CentreScope::ids($request, $aid) ?? [];
            $people = $this->hrPeople($request, $aid);
            $q->where(fn ($w) => $w->whereIn('centre_id', $scope ?: [0])->orWhereIn('user_id', $people ?: [0]));
        }

        return $q;
    }

    private function canManage(Request $request, int $aid, object $o): bool
    {
        if (! $this->hrIsManager($request) || (int) $o->user_id === (int) $request->user()->id) return false;
        if ($this->hrIsAdmin($request)) return true;
        $scope = CentreScope::ids($request, $aid) ?? [];

        return ($o->centre_id && in_array((int) $o->centre_id, $scope, true)) || in_array((int) $o->user_id, $this->hrPeople($request, $aid), true);
    }

    private function overtime(Request $request, int $aid, int $id): object
    {
        $o = DB::table('hr_overtime_requests')->where('id', $id)->where('agency_id', $aid)->first(HrTime::OT_COLS);
        abort_unless($o && ((int) $o->user_id === (int) $request->user()->id || $this->canManage($request, $aid, $o)), 404, 'That overtime was not found.');

        return $o;
    }

    private function shapeAll(int $aid, $rows): array
    {
        $rows = collect($rows);
        $names = $this->hrNames($rows->pluck('user_id')->merge($rows->pluck('decided_by_id'))->merge($rows->pluck('logged_by_id'))
            ->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $pay = DB::table('payroll_documents')->whereIn('id', $rows->pluck('payroll_document_id')->filter()->all() ?: [0])->pluck('reference', 'id')->all();

        return $rows->map(function ($o) use ($names, $centres, $pay) {
            $s = HrTime::shape($o, $names, $centres);
            $s['payslip'] = $o->payroll_document_id ? ($pay[(int) $o->payroll_document_id] ?? null) : null;

            return $s;
        })->values()->all();
    }

    /** The centre a person's overtime is filed at: their HR file's primary centre, else their first open staff centre. */
    private function centreOf(int $aid, int $uid): ?int
    {
        $open = Live::centreIds($aid);
        $c = (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->value('primary_centre_id');
        if ($c && in_array($c, $open, true)) return $c;
        $c = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
            ->whereIn('centre_id', $open ?: [0])->value('centre_id');

        return $c ? (int) $c : null;
    }

    /** GET /hr/me/overtime — the person's own overtime, their Overtime bank balance and what they may log. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR record was not found.');
        $s = HrTime::settings($aid);
        $rows = DB::table('hr_overtime_requests')->where('agency_id', $aid)->where('user_id', $me)->orderByDesc('work_date')->orderByDesc('id')->limit(300)->get(HrTime::OT_COLS);
        $bank = HrTime::bankType($aid);

        return response()->json(['overtime' => $this->shapeAll($aid, $rows), 'settings' => $s['overtime'],
            'can_log' => $s['overtime']['staff_can_log'] || $this->hrIsManager($request),
            'bank' => $bank ? ['leave_type_id' => (int) $bank->id, 'name' => $bank->name, 'balance' => HrLeave::balance($me, (int) $bank->id), 'archived' => (bool) $bank->archived_at] : null,
            'today' => AgencyTime::today($aid), 'statuses' => HrTime::STATUSES]);
    }

    /** GET /hr/overtime — a director's or admin's list (?status=submitted|approved|paid|declined|all). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedManager($request);
        $st = (string) $request->query('status', 'submitted');
        $q = $this->managedQuery($request, $aid);
        if ($st !== 'all' && isset(HrTime::STATUSES[$st])) $q->where('status', $st);
        $rows = $q->orderByRaw("status = 'submitted' DESC")->orderByDesc('work_date')->orderByDesc('id')->limit(500)->get(HrTime::OT_COLS);
        $counts = $this->managedQuery($request, $aid)->selectRaw('status, COUNT(*) n')->groupBy('status')->pluck('n', 'status')->map(fn ($v) => (int) $v)->all();
        $me = (int) $request->user()->id;
        $people = array_values(array_diff($this->hrPeople($request, $aid), [$me]));
        $names = $this->hrNames($people);
        asort($names);

        return response()->json(['overtime' => $this->shapeAll($aid, $rows), 'counts' => $counts, 'settings' => HrTime::settings($aid)['overtime'],
            'is_admin' => $this->hrIsAdmin($request), 'me' => $me, 'statuses' => HrTime::STATUSES, 'today' => AgencyTime::today($aid),
            'people' => collect($names)->map(fn ($n, $id) => ['id' => (int) $id, 'name' => $n])->values()->all()]);
    }

    /** POST /hr/overtime — log extra hours: your own, or (a director / admin) for someone you manage. */
    public function store(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $me = (int) $request->user()->id;
        $today = AgencyTime::today($aid);
        $d = $request->validate([
            'user_id' => 'nullable|integer',
            'work_date' => 'required|date_format:Y-m-d|before_or_equal:' . $today . '|after_or_equal:' . date('Y-m-d', strtotime($today . ' -1 year')),
            'hours' => 'required|numeric|min:0.25|max:16',
            'reason' => 'required|string|min:3|max:500',
            'preference' => 'nullable|in:pay,bank',
        ], ['work_date.before_or_equal' => 'The date cannot be in the future.', 'work_date.after_or_equal' => 'That date is more than a year ago.',
            'reason.required' => 'Say what the extra hours were for.', 'hours.min' => 'Enter at least a quarter of an hour.', 'hours.max' => 'Enter 16 hours or less for one day.']);
        $uid = (int) ($d['user_id'] ?? 0) ?: $me;
        $s = HrTime::settings($aid)['overtime'];
        if ($uid !== $me) {
            $this->hrNeedManager($request);
            abort_unless(in_array($uid, $this->hrPeople($request, $aid), true), 404, 'That person was not found.');
        } else {
            abort_unless(in_array($me, HrLeave::staffIds($aid), true), 403, 'Only the agency\'s staff can log overtime here.');
            abort_unless($s['staff_can_log'] || $this->hrIsManager($request), 403, 'Your agency asks your director to log overtime for you.');
        }
        $pref = $d['preference'] ?? null;
        if ($pref === 'pay' && ! $s['allow_pay']) $pref = 'bank';
        if ($pref === 'bank' && ! $s['allow_bank']) $pref = 'pay';
        $minutes = (int) round((float) $d['hours'] * 60);
        $centre = $this->centreOf($aid, $uid);
        $id = (int) DB::table('hr_overtime_requests')->insertGetId(['agency_id' => $aid, 'centre_id' => $centre, 'user_id' => $uid, 'work_date' => $d['work_date'],
            'minutes' => $minutes, 'reason' => trim($d['reason']), 'preference' => $pref, 'logged_by_id' => $me, 'status' => 'submitted',
            'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.overtime_logged', 'hr_overtime', $id, ['user_id' => $uid, 'minutes' => $minutes, 'work_date' => $d['work_date']]);
        try {
            $person = DB::table('users')->where('id', $uid)->first(['first_name', 'preferred_name']);
            $first = trim((string) (($person->preferred_name ?? '') ?: ($person->first_name ?? ''))) ?: 'A team member';
            $o = (object) ['work_date' => $d['work_date'], 'minutes' => $minutes, 'rate' => 1];
            if ($uid === $me) {
                // Waiting for a decision: the centre's directors, else the agency admins (never the person, never an outsider).
                $mgrs = CentreScope::managers($aid, $centre ? [$centre] : [])->where('ra.role', 'centre_director')->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
                if (! $mgrs) $mgrs = CentreScope::managers($aid, [])->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
                $rows = [];
                foreach (array_diff(array_unique($mgrs), [$uid]) as $m) {
                    $rows[] = ['user_id' => $m, 'agency_id' => $aid, 'type' => 'hr', 'title' => $first . ' logged overtime to approve',
                        'body' => HrTime::label($o), 'data' => json_encode(['link' => '#hr?tab=overtime&ot=' . $id, 'kind' => 'team']), 'created_at' => now()];
                }
                if ($rows) Notify::write($rows);
            } else {
                Notify::write(['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr', 'title' => 'Overtime logged for you',
                    'body' => HrTime::label($o) . '. Waiting for approval.', 'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['id' => $id], 201);
    }

    /** POST /hr/overtime/{id}/withdraw — the person (or whoever logged it) takes it back while it is waiting. */
    public function withdraw(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $o = $this->overtime($request, $aid, $id);
        $me = (int) $request->user()->id;
        abort_unless((int) $o->user_id === $me || (int) $o->logged_by_id === $me, 403, 'Only the person, or whoever logged it, can withdraw it.');
        abort_unless($o->status === 'submitted', 422, 'Only overtime still waiting for approval can be withdrawn.');
        DB::table('hr_overtime_requests')->where('id', $id)->where('status', 'submitted')->update(['status' => 'withdrawn', 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.overtime_withdrawn', 'hr_overtime', $id);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/overtime/{id}/decide — approve as pay or bank, or decline (with a reason). Never your own. */
    public function decide(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $o = $this->overtime($request, $aid, $id);
        if ((int) $o->user_id === (int) $request->user()->id) {
            return response()->json(['message' => 'Someone else approves your own overtime.'], 403);
        }
        abort_unless($this->canManage($request, $aid, $o), 403, 'A director or admin decides overtime (never the person who worked it).');
        $d = $request->validate(['decision' => 'required|in:approve,decline', 'outcome' => 'required_if:decision,approve|nullable|in:pay,bank',
            'comment' => 'required_if:decision,decline|nullable|string|max:1000'],
            ['comment.required_if' => 'Say why the overtime is declined.', 'outcome.required_if' => 'Choose pay or bank as time in lieu.']);
        if ($d['decision'] === 'approve') {
            abort_unless(in_array($o->status, ['submitted', 'declined'], true), 422, 'This overtime has already been decided.');
            if ($no = HrTime::approve($o, $d['outcome'], (int) $request->user()->id, $d['comment'] ?? null)) {
                return response()->json(['message' => $no], 422);
            }
            $st = 'approved';
        } else {
            abort_if($o->status === 'approved' && $o->outcome === 'bank', 422, 'These hours are already in the Overtime bank. To take them back, adjust the person\'s balance in HR > Balances.');
            abort_unless($o->status === 'submitted' || ($o->status === 'approved' && $o->outcome === 'pay' && ! $o->payroll_document_id), 422, 'This overtime has already been decided.');
            DB::table('hr_overtime_requests')->where('id', $id)->whereNull('payroll_document_id')->update(['status' => 'declined', 'outcome' => null,
                'decided_by_id' => $request->user()->id, 'decided_at' => now(), 'decision_comment' => $d['comment'] ?? null, 'updated_at' => now()]);
            $st = 'declined';
        }
        $n = DB::table('hr_overtime_requests')->where('id', $id)->first(HrTime::OT_COLS);
        $this->hrNote($aid, 'hr.overtime_' . $st, 'hr_overtime', $id, ['user_id' => (int) $o->user_id, 'minutes' => (int) $o->minutes, 'outcome' => $n->outcome,
            'rate' => $n->rate !== null ? (float) $n->rate : null, 'bank_hours' => $n->bank_hours !== null ? (float) $n->bank_hours : null]);
        try {
            $body = $st === 'declined' ? (string) ($d['comment'] ?? '')
                : ($n->outcome === 'bank' ? HrTime::hoursText((float) $n->bank_hours) . ' added to your Overtime bank, to book as time off.' : 'It will be added to your next pay.');
            Notify::write(['user_id' => (int) $o->user_id, 'agency_id' => $aid, 'type' => 'hr',
                'title' => ($st === 'approved' ? 'Overtime approved: ' : 'Overtime declined: ') . HrTime::label($o, 1.0),
                'body' => $body, 'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json(['ok' => true, 'status' => $st]);
    }

    // ── Settings ──────────────────────────────────────────────────────────────────────

    /** GET /hr/time/settings — overtime and timesheet rules (directors read, admins change). */
    public function settings(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedManager($request);
        $s = HrTime::settings($aid);
        $bank = HrTime::bankType($aid);

        return response()->json(['settings' => ['overtime' => $s['overtime'], 'timesheet' => $s['timesheet']], 'can_manage' => $this->hrIsAdmin($request),
            'bank' => $bank ? ['id' => (int) $bank->id, 'name' => $bank->name, 'archived' => (bool) $bank->archived_at] : null, 'rounding' => HrTime::ROUNDING]);
    }

    /** PUT /hr/time/settings — agency admins. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate([
            'overtime.rate' => 'required|numeric|min:1|max:3',
            'overtime.staff_can_log' => 'required|boolean',
            'overtime.allow_pay' => 'required|boolean',
            'overtime.allow_bank' => 'required|boolean',
            'timesheet.rounding' => 'required|integer|in:' . implode(',', HrTime::ROUNDING),
            'timesheet.clip_in' => 'required|boolean',
            'timesheet.clip_out' => 'required|boolean',
            'timesheet.export_rule' => 'required|in:' . implode(',', HrTime::EXPORT_RULES),
        ], ['overtime.rate.min' => 'The overtime rate is at least 1 (straight time).', 'overtime.rate.max' => 'The overtime rate is at most 3.']);
        abort_unless($d['overtime']['allow_pay'] || $d['overtime']['allow_bank'], 422, 'Allow paying overtime, banking it, or both.');
        $before = HrTime::settings($aid);
        $s = HrTime::saveSettings($aid, ['overtime' => ['rate' => round((float) $d['overtime']['rate'], 2)] + array_map('boolval', array_intersect_key($d['overtime'], array_flip(['staff_can_log', 'allow_pay', 'allow_bank']))),
            'timesheet' => ['rounding' => (int) $d['timesheet']['rounding'], 'clip_in' => (bool) $d['timesheet']['clip_in'], 'clip_out' => (bool) $d['timesheet']['clip_out'],
                'export_rule' => $d['timesheet']['export_rule']]]);
        $this->hrNote($aid, 'hr.time_settings_saved', 'agency', $aid, ['before' => ['overtime' => $before['overtime'], 'timesheet' => $before['timesheet']],
            'after' => ['overtime' => $s['overtime'], 'timesheet' => $s['timesheet']]]);

        return response()->json(['settings' => ['overtime' => $s['overtime'], 'timesheet' => $s['timesheet']]]);
    }

    // ── Timesheets ────────────────────────────────────────────────────────────────────

    /** The open centres this caller may see, [id => name]. */
    private function centreChoices(Request $request, int $aid): array
    {
        $scope = CentreScope::ids($request, $aid);

        return Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
            ->when($scope !== null, fn ($q) => $q->whereIn('id', $scope ?: [0]))->orderBy('name')->pluck('name', 'id')
            ->mapWithKeys(fn ($n, $id) => [(int) $id => $n])->all();
    }

    private function period(Request $request, int $aid): array
    {
        $d = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $today = AgencyTime::today($aid);
        $lastMon = date('Y-m-d', strtotime($today . ' monday this week -7 days'));
        $from = $d['from'] ?? $lastMon;
        $to = $d['to'] ?? date('Y-m-d', strtotime($lastMon . ' +6 days'));
        if ($from > $to) [$from, $to] = [$to, $from];
        abort_if((strtotime($to) - strtotime($from)) / 86400 + 1 > HrTime::MAX_SPAN_DAYS, 422, 'Choose a period of ' . HrTime::MAX_SPAN_DAYS . ' days or less.');

        return [$from, $to, $today];
    }

    private function needCentre(Request $request, int $aid, $centreId): int
    {
        $choices = $this->centreChoices($request, $aid);
        $cid = (int) $centreId ?: (int) array_key_first($choices);
        abort_unless($cid && isset($choices[$cid]), 404, 'That centre was not found.');

        return $cid;
    }

    /** GET /hr/timesheets?centre_id=&from=&to= — the centre's timesheet for the period, its lock and its history. */
    public function timesheets(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedManager($request);
        [$from, $to, $today] = $this->period($request, $aid);
        $choices = $this->centreChoices($request, $aid);
        if (! $choices) return response()->json(['centres' => [], 'rows' => [], 'from' => $from, 'to' => $to, 'today' => $today]);
        $cid = $this->needCentre($request, $aid, $request->query('centre_id'));
        $sheet = HrTime::sheet($aid, $cid, $from, $to);
        $locks = DB::table('hr_timesheet_locks')->where('centre_id', $cid)->orderByDesc('period_start')->orderByDesc('id')->limit(40)->get();
        $names = $this->hrNames($locks->pluck('approved_by_id')->merge($locks->pluck('unlocked_by_id'))->filter()->map(fn ($v) => (int) $v)->unique()->all());
        $overlap = HrTime::locksFor([$cid], $from, $to)->map(fn ($l) => HrTime::lockShape($l, $names))->values()->all();
        $me = (int) $request->user()->id;
        $mine = collect($sheet['rows'])->first(fn ($r) => $r['user_id'] === $me && $r['punches'] > 0);

        return response()->json(['centres' => collect($choices)->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->values()->all(), 'centre_id' => $cid,
            'from' => $from, 'to' => $to, 'today' => $today, 'rows' => $sheet['rows'], 'totals' => $sheet['totals'], 'rules' => $sheet['rules'], 'rules_on' => $sheet['rules_on'],
            'locks' => $overlap, 'history' => $locks->map(fn ($l) => HrTime::lockShape($l, $names))->values()->all(),
            'can_approve' => $to < $today && ! $overlap && ! $sheet['totals']['open'] && ! $mine,
            'own_hours' => (bool) $mine, 'ended' => $to < $today, 'is_admin' => $this->hrIsAdmin($request), 'me' => $me]);
    }

    /** GET /hr/timesheets/person?centre_id=&user_id=&from=&to= — one person's clock-ins, shifts and flags. */
    public function person(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedManager($request);
        [$from, $to] = $this->period($request, $aid);
        $cid = $this->needCentre($request, $aid, $request->query('centre_id'));
        $uid = (int) $request->query('user_id');
        abort_unless($uid > 0, 422, 'Choose a person.');
        $sheet = HrTime::sheet($aid, $cid, $from, $to, $uid);
        abort_unless($sheet['rows'], 404, 'Nothing for that person here.');

        return response()->json(['person' => $sheet['rows'][0], 'entries' => $sheet['detail'], 'rules' => $sheet['rules'], 'rules_on' => $sheet['rules_on'],
            'from' => $from, 'to' => $to, 'locked' => (bool) HrTime::locksFor([$cid], $from, $to)->count()]);
    }

    /** POST /hr/timesheets/approve {centre_id, from, to, note} — approve a centre's ended period and lock it. */
    public function approve(Request $request): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedManager($request);
        $request->validate(['centre_id' => 'required|integer', 'from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d', 'note' => 'nullable|string|max:500']);
        [$from, $to, $today] = $this->period($request, $aid);
        $cid = $this->needCentre($request, $aid, $request->input('centre_id'));
        if ($to >= $today) return response()->json(['message' => 'You can approve a period once it has ended (the last day is before today).'], 422);
        $me = (int) $request->user()->id;
        $result = null;
        DB::transaction(function () use ($aid, $cid, $from, $to, $me, $request, &$result) {
            DB::table('centres')->where('id', $cid)->lockForUpdate()->first(['id']);   // one approval per centre at a time
            if ($l = HrTime::locksFor([$cid], $from, $to)->first()) {
                $result = ['message' => 'Part of this period is already approved and locked (' . HrTime::periodText($l) . '). Choose dates after it, or ask an agency admin to unlock it.'];
                return;
            }
            $sheet = HrTime::sheet($aid, $cid, $from, $to);
            if ($sheet['totals']['open']) {
                $result = ['message' => $sheet['totals']['open'] . ' clock-in' . ($sheet['totals']['open'] === 1 ? ' has' : 's have') . ' no clock-out. Add the clock-out time first (Timesheets, the person\'s ⋮).'];
                return;
            }
            foreach ($sheet['rows'] as $r) {
                if ($r['user_id'] === $me && $r['punches'] > 0) {
                    $result = ['message' => 'Your own hours are on this timesheet. Another director or an agency admin approves it.'];
                    return;
                }
            }
            $summary = array_map(fn ($r) => array_intersect_key($r, array_flip(['user_id', 'name', 'scheduled_min', 'worked_min', 'paid_min', 'no_show', 'unscheduled', 'overtime_min'])), $sheet['rows']);
            $id = (int) DB::table('hr_timesheet_locks')->insertGetId(['agency_id' => $aid, 'centre_id' => $cid, 'period_start' => $from, 'period_end' => $to,
                'status' => 'locked', 'approved_by_id' => $me, 'approved_at' => now(), 'note' => trim((string) $request->input('note')) ?: null,
                'summary' => json_encode(['rules' => $sheet['rules'], 'totals' => $sheet['totals'], 'rows' => $summary]), 'created_at' => now(), 'updated_at' => now()]);
            $result = ['id' => $id, 'totals' => $sheet['totals']];
        });
        if (empty($result['id'])) return response()->json(['message' => $result['message'] ?? 'That did not work. Please try again.'], 422);
        $this->hrNote($aid, 'hr.timesheet_locked', 'hr_timesheet_lock', (int) $result['id'], ['centre_id' => $cid, 'from' => $from, 'to' => $to,
            'paid_min' => $result['totals']['paid_min'], 'people' => $result['totals']['people']]);

        return response()->json(['ok' => true, 'id' => $result['id']]);
    }

    /** POST /hr/timesheets/locks/{id}/unlock {reason} — agency admins only; the row stays as the record. */
    public function unlock(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrTimeAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['reason' => 'required|string|min:3|max:500'], ['reason.required' => 'Say why the timesheet is unlocked.']);
        $l = DB::table('hr_timesheet_locks')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($l, 404, 'That timesheet was not found.');
        abort_unless($l->status === 'locked', 422, 'That timesheet is already unlocked.');
        DB::table('hr_timesheet_locks')->where('id', $id)->where('status', 'locked')->update(['status' => 'unlocked', 'unlocked_by_id' => $request->user()->id,
            'unlocked_at' => now(), 'unlock_reason' => trim($d['reason']), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.timesheet_unlocked', 'hr_timesheet_lock', $id, ['centre_id' => (int) $l->centre_id, 'from' => substr((string) $l->period_start, 0, 10),
            'to' => substr((string) $l->period_end, 0, 10), 'reason' => trim($d['reason'])]);

        return response()->json(['ok' => true]);
    }
}
