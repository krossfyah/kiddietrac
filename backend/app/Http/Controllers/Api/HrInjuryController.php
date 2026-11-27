<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrInjuries;
use App\Support\HrLeave;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 3, item 8: staff injury and near-miss records (App\Support\HrInjuries has the rules).
 * Health details never leave in a list or summary; POST .../reveal returns them and is audited every time.
 */
class HrInjuryController extends Controller
{
    use HrAccess;

    /** May this caller see this record? Own; a director's centres / people; an admin: all. Fails closed. */
    private function canSee(Request $request, int $aid, object $r): bool
    {
        if ((int) $r->agency_id !== $aid) return false;
        if ((int) $r->user_id === (int) $request->user()->id) return true;
        if ($this->hrIsAdmin($request)) return true;
        if (! $this->hrIsManager($request)) return false;
        $scope = CentreScope::ids($request, $aid) ?? [];
        return ($r->centre_id && in_array((int) $r->centre_id, $scope, true)) || in_array((int) $r->user_id, $this->hrPeople($request, $aid), true);
    }

    private function row(Request $request, int $aid, int $id, bool $manage = false): object
    {
        $r = DB::table('hr_injuries')->where('id', $id)->first(HrInjuries::LIST_COLS);
        abort_unless($r && $this->canSee($request, $aid, $r), 404, 'That record was not found.');
        if ($manage) abort_unless($this->hrIsAdmin($request) || ($this->hrIsManager($request) && (int) $r->user_id !== (int) $request->user()->id), 403, 'A director or admin updates this record.');
        return $r;
    }

    private function shape(int $aid, $rows): array
    {
        $rows = collect($rows);
        $names = $this->hrNames($rows->pluck('user_id')->merge($rows->pluck('reported_by_id'))->filter()->map(fn ($v) => (int) $v)->unique()->values()->all());
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->all();
        $on = HrInjuries::isOntario($aid);
        return $rows->map(fn ($r) => HrInjuries::summary($r, $names, $centres, $on))->values()->all();
    }

    /** GET /hr/injuries — a director's or admin's list (no health details). */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $q = DB::table('hr_injuries')->where('agency_id', $aid);
        if (! $this->hrIsAdmin($request)) {
            $scope = CentreScope::ids($request, $aid) ?? [];
            $people = $this->hrPeople($request, $aid);
            $q->where(fn ($w) => $w->whereIn('centre_id', $scope ?: [0])->orWhereIn('user_id', $people ?: [0]));
        }
        $rows = $q->orderByDesc('occurred_at')->limit(500)->get(HrInjuries::LIST_COLS);

        return response()->json(['injuries' => $this->shape($aid, $rows), 'people' => $this->peopleChoices($request, $aid),
            'kinds' => HrInjuries::KINDS, 'care' => HrInjuries::CARE, 'ontario' => HrInjuries::isOntario($aid), 'wsib_url' => HrInjuries::WSIB_URL,
            'today' => AgencyTime::today($aid)]);
    }

    /** GET /hr/me/injuries — the person's own records. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR record was not found.');
        $rows = DB::table('hr_injuries')->where('agency_id', $aid)->where('user_id', $me)->orderByDesc('occurred_at')->get(HrInjuries::LIST_COLS);

        return response()->json(['injuries' => $this->shape($aid, $rows), 'kinds' => HrInjuries::KINDS, 'care' => HrInjuries::CARE,
            'ontario' => HrInjuries::isOntario($aid), 'today' => AgencyTime::today($aid)]);
    }

    private function peopleChoices(Request $request, int $aid): array
    {
        return collect($this->hrNames($this->hrPeople($request, $aid)))->map(fn ($n, $id) => ['id' => $id, 'name' => $n])->sortBy('name')->values()->all();
    }

    private function rules(): array
    {
        return [
            'kind' => 'nullable|in:' . implode(',', array_keys(HrInjuries::KINDS)),
            'occurred_at' => 'required|date', 'learned_on' => 'nullable|date', 'location' => 'nullable|string|max:200',
            'witnesses' => 'nullable|string|max:2000', 'what_happened' => 'required|string|max:5000', 'body_part' => 'nullable|string|max:200',
            'injury_nature' => 'nullable|string|max:2000', 'first_aid' => 'nullable|string|max:2000',
            'medical_care' => 'nullable|in:' . implode(',', array_keys(HrInjuries::CARE)), 'medical_notes' => 'nullable|string|max:2000',
            'lost_time' => 'nullable|boolean', 'centre_id' => 'nullable|integer',
        ];
    }

    private function fields(array $d, int $aid): array
    {
        $care = $d['medical_care'] ?? 'none';
        $kind = $d['kind'] ?? 'injury';
        return ['kind' => $kind, 'occurred_at' => $d['occurred_at'], 'learned_on' => $d['learned_on'] ?? AgencyTime::today($aid),
            'location' => $d['location'] ?? null, 'witnesses' => $d['witnesses'] ?? null, 'what_happened' => $d['what_happened'],
            'body_part' => $d['body_part'] ?? null, 'injury_nature' => $d['injury_nature'] ?? null, 'first_aid' => $d['first_aid'] ?? null,
            'medical_care' => $care, 'medical_notes' => $d['medical_notes'] ?? null, 'lost_time' => ! empty($d['lost_time']),
            'form7_needed' => $kind === 'injury' && ($care !== 'none' || ! empty($d['lost_time']))];
    }

    /** POST /hr/injuries — a staff member reports their own; a director / admin may report for a person they manage. */
    public function store(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate($this->rules() + ['user_id' => 'nullable|integer']);
        $me = (int) $request->user()->id;
        $uid = (int) ($d['user_id'] ?? $me);
        if ($uid === $me) abort_unless(in_array($me, HrLeave::staffIds($aid), true), 403, 'Only the agency\'s staff can report a staff injury here.');
        else $this->hrNeedPerson($request, $aid, $uid);
        $open = Live::centreIds($aid);
        $centre = isset($d['centre_id']) && in_array((int) $d['centre_id'], $open, true) ? (int) $d['centre_id']
            : ((int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->value('primary_centre_id') ?: null);
        if ($centre && ! in_array($centre, $open, true)) $centre = null;
        if (! $centre) {
            $centre = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)
                ->whereIn('centre_id', $open ?: [0])->value('centre_id');
            $centre = $centre ? (int) $centre : null;
        }
        $id = (int) DB::table('hr_injuries')->insertGetId($this->fields($d, $aid) + ['agency_id' => $aid, 'centre_id' => $centre, 'user_id' => $uid,
            'reported_by_id' => $me, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.injury_reported', 'hr_injury', $id, ['user_id' => $uid, 'kind' => $d['kind'] ?? 'injury']);   // never the health details
        // The centre's managers are told (bell + email, who not what). A failed notice never loses the report.
        try { HrInjuries::notifyReported($aid, $id); } catch (\Throwable $e) { report($e); }

        return response()->json(['id' => $id], 201);
    }

    /** GET /hr/injuries/{id} — the record without its health details. */
    public function show(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->row($request, $aid, $id);
        $checks = DB::table('hr_injury_checkins')->where('injury_id', $id)->orderBy('on_date')->get(['id', 'on_date', 'by_id']);
        $names = $this->hrNames($checks->pluck('by_id')->filter()->map(fn ($v) => (int) $v)->all());

        return response()->json(['injury' => $this->shape($aid, [$r])[0], 'can_manage' => $this->hrIsAdmin($request) || ($this->hrIsManager($request) && (int) $r->user_id !== (int) $request->user()->id),
            'checkins' => $checks->map(fn ($c) => ['id' => (int) $c->id, 'on_date' => substr((string) $c->on_date, 0, 10), 'by' => $names[(int) $c->by_id] ?? ''])->all(),
            'health_hidden' => true, 'wsib_url' => HrInjuries::WSIB_URL]);
    }

    /** POST /hr/injuries/{id}/reveal — the health details; audited every time. */
    public function reveal(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->row($request, $aid, $id);
        $h = DB::table('hr_injuries')->where('id', $id)->first(HrInjuries::SEALED);
        $notes = DB::table('hr_injury_checkins')->where('injury_id', $id)->orderBy('on_date')->get(['id', 'note'])->mapWithKeys(fn ($c) => [(int) $c->id => $c->note]);
        $this->hrNote($aid, 'hr.injury_health_revealed', 'hr_injury', $id);

        return response()->json(['health' => (array) $h, 'checkin_notes' => $notes]);
    }

    /** PUT /hr/injuries/{id} — a director / admin corrects the report (health details included only when sent). */
    public function update(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->row($request, $aid, $id, true);
        $d = $request->validate($this->rules() + ['status' => 'nullable|in:open,closed']);
        $row = $this->fields($d, $aid) + ['updated_at' => now()];
        if (! $request->has('learned_on')) unset($row['learned_on']);
        if (! empty($d['status'])) $row['status'] = $d['status'];
        DB::table('hr_injuries')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.injury_updated', 'hr_injury', $id, ['fields' => array_keys(array_intersect_key($d, array_flip(['kind', 'occurred_at', 'learned_on', 'location', 'witnesses', 'lost_time', 'status'])))]);

        return response()->json(['ok' => true]);
    }

    /** PUT /hr/injuries/{id}/form7 — "Form 7 filed on / reference" (or the local board's report elsewhere). */
    public function form7(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->row($request, $aid, $id, true);
        $d = $request->validate(['form7_filed_on' => 'nullable|date', 'form7_reference' => 'nullable|string|max:80', 'form7_needed' => 'nullable|boolean']);
        $row = ['form7_filed_on' => $d['form7_filed_on'] ?? null, 'form7_reference' => $d['form7_reference'] ?? null, 'updated_at' => now()];
        if (array_key_exists('form7_needed', $d)) $row['form7_needed'] = (bool) $d['form7_needed'];
        DB::table('hr_injuries')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.injury_form7', 'hr_injury', $id, ['filed_on' => $row['form7_filed_on'], 'reference' => $row['form7_reference']]);

        return response()->json(['ok' => true]);
    }

    /** PUT /hr/injuries/{id}/return-to-work — modified duties (sealed) and dates. */
    public function returnToWork(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->row($request, $aid, $id, true);
        $d = $request->validate(['rtw_start' => 'nullable|date', 'rtw_end' => 'nullable|date|after_or_equal:rtw_start', 'rtw_duties' => 'nullable|string|max:5000']);
        DB::table('hr_injuries')->where('id', $id)->update(['rtw_start' => $d['rtw_start'] ?? null, 'rtw_end' => $d['rtw_end'] ?? null,
            'rtw_duties' => $d['rtw_duties'] ?? null, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.injury_rtw_saved', 'hr_injury', $id, ['start' => $d['rtw_start'] ?? null, 'end' => $d['rtw_end'] ?? null]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/injuries/{id}/checkins — a return-to-work check-in (note sealed). */
    public function checkin(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->row($request, $aid, $id, true);
        $d = $request->validate(['on_date' => 'required|date', 'note' => 'required|string|max:3000']);
        $cid = (int) DB::table('hr_injury_checkins')->insertGetId(['agency_id' => $aid, 'injury_id' => $id, 'on_date' => $d['on_date'], 'note' => $d['note'],
            'by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.injury_checkin', 'hr_injury', $id, ['checkin_id' => $cid, 'on_date' => $d['on_date']]);

        return response()->json(['id' => $cid], 201);
    }
}
