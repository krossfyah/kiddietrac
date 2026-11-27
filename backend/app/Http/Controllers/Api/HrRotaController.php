<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrOpenShifts;
use App\Support\HrRota;
use App\Support\Live;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 8: rota drafts + publish, copy tools, shift presets, weekly labour budget, shift acceptance
 * (rules in App\Support\HrRota). Directors: their own open centres; admins: the agency; staff: their own shifts to accept.
 * Pay figures (budget, projected cost) only for people who may see pay (a manager, with the payroll feature on).
 */
class HrRotaController extends Controller
{
    use HrAccess;

    private function rotaAgency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(HrRota::ready(), 503, 'The rota tools are being set up. Please try again in a minute.');

        return $aid;
    }

    private function canSeePay(Request $request, int $aid): bool
    {
        return $this->hrIsManager($request) && Features::on($aid, 'payroll');
    }

    /** Open centres this manager runs the rota for. */
    private function centres(Request $request, int $aid): array
    {
        $this->hrNeedManager($request);
        $open = Live::centreIds($aid);
        if ($this->hrIsAdmin($request)) return $open;

        return array_values(array_intersect($open, CentreScope::ids($request, $aid) ?? []));
    }

    private function needCentre(Request $request, int $aid, $cid): int
    {
        $cid = (int) $cid;
        abort_unless($cid > 0 && in_array($cid, $this->centres($request, $aid), true), 404, 'That centre was not found.');

        return $cid;
    }

    private function draft(Request $request, int $aid, int $id): object
    {
        $d = DB::table('hr_shift_drafts')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($d, 404, 'That draft was not found. It may have been published already.');
        $this->needCentre($request, $aid, $d->centre_id);

        return $d;
    }

    /** GET /hr/rota/week?centre_id=&from=&to= — what the schedule grid lays over the published shifts. */
    public function week(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $cid = $this->needCentre($request, $aid, $request->query('centre_id'));
        $from = (string) $request->query('from', AgencyTime::today($aid));
        $to = (string) $request->query('to', $from);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $to >= $from, 422, 'Choose a date range.');
        if ((strtotime($to) - strtotime($from)) / 86400 > 45) $to = date('Y-m-d', strtotime($from . ' +45 days'));
        $s = HrRota::settings($aid);
        $roomIds = DB::table('rooms')->where('centre_id', $cid)->pluck('id')->all();
        $shiftIds = DB::table('shifts')->whereIn('room_id', $roomIds ?: [0])->where('starts_at', '>=', $from . ' 00:00:00')->where('starts_at', '<=', $to . ' 23:59:59')->pluck('id')->all();
        $pay = $this->canSeePay($request, $aid);
        $budget = null;
        if ($pay) {
            $budget = HrRota::projected($cid, $from, $to);
            $budget['days'] = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        }

        return response()->json(['centre_id' => $cid, 'from' => $from, 'to' => $to, 'draft_mode' => HrRota::draftMode($aid), 'require_accept' => $s['require_accept'],
            'drafts' => HrRota::drafts($cid, $from, $to), 'pending' => HrRota::pendingCounts($cid), 'acceptance' => (object) HrRota::acceptanceMap($shiftIds),
            'presets' => HrRota::presetsFor($aid, $cid), 'can_see_pay' => $pay, 'budget' => $budget, 'is_admin' => $this->hrIsAdmin($request)]);
    }

    /** PATCH /hr/rota/drafts/{id} — change a draft (a new shift, or the pending change of a published one). */
    public function updateDraft(Request $request, int $id): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $d = $this->draft($request, $aid, $id);
        abort_if($d->kind === 'cancel', 422, 'This shift is set to be removed. Undo that first to change it.');
        $in = $request->validate(['user_id' => 'nullable|integer', 'room_id' => 'nullable|integer', 'starts_at' => 'nullable|date', 'ends_at' => 'nullable|date',
            'role' => 'nullable|in:lead,support,floater,volunteer', 'preset_id' => 'nullable|integer']);
        $room = (int) ($in['room_id'] ?? $d->room_id);
        $cid = (int) DB::table('rooms')->where('id', $room)->value('centre_id');
        $this->needCentre($request, $aid, $cid);
        $uid = (int) ($in['user_id'] ?? $d->user_id);
        abort_unless(HrRota::mayRoster($uid, $cid), 422, 'That person is not staff at this centre.');
        $start = HrRota::wall((string) ($in['starts_at'] ?? $d->starts_at));
        $end = HrRota::wall((string) ($in['ends_at'] ?? $d->ends_at));
        abort_unless($end > $start, 422, 'The shift must end after it starts.');
        $row = ['user_id' => $uid, 'room_id' => $room, 'centre_id' => $cid, 'starts_at' => $start, 'ends_at' => $end, 'role' => $in['role'] ?? $d->role,
            'preset_id' => array_key_exists('preset_id', $in) ? HrRota::presetOk($aid, $cid, $in['preset_id']) : $d->preset_id,
            'updated_by_id' => $request->user()->id, 'updated_at' => now()];
        DB::table('hr_shift_drafts')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.rota_draft_changed', 'hr_shift_draft', $id, ['kind' => $d->kind, 'user_id' => $uid, 'starts_at' => $start, 'ends_at' => $end]);

        return response()->json(['ok' => true]);
    }

    /** DELETE /hr/rota/drafts/{id} — throw a draft away (a new shift is dropped; a pending change / removal is undone). */
    public function discard(Request $request, int $id): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $d = $this->draft($request, $aid, $id);
        DB::table('hr_shift_drafts')->where('id', $id)->delete();
        $this->hrNote($aid, 'hr.rota_draft_discarded', 'hr_shift_draft', $id, ['kind' => $d->kind, 'shift_id' => $d->shift_id, 'user_id' => (int) $d->user_id]);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/rota/discard-all {centre_id, from?, to?} — discard every draft of a centre (in the range). */
    public function discardAll(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $in = $request->validate(['centre_id' => 'required|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $cid = $this->needCentre($request, $aid, $in['centre_id']);
        $ids = HrRota::draftsQuery($cid)->where('d.agency_id', $aid)
            ->when($in['from'] ?? null, fn ($q, $v) => $q->where('d.starts_at', '>=', $v . ' 00:00:00'))
            ->when($in['to'] ?? null, fn ($q, $v) => $q->where('d.starts_at', '<=', $v . ' 23:59:59'))->pluck('d.id')->all();
        $n = DB::table('hr_shift_drafts')->whereIn('id', $ids ?: [0])->delete();
        $this->hrNote($aid, 'hr.rota_drafts_discarded', 'centre', $cid, ['count' => $n]);

        return response()->json(['ok' => true, 'discarded' => $n]);
    }

    /** POST /hr/rota/publish {centre_id, from?, to?} — apply the drafts; staff hear once per person (shift notices). */
    public function publish(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $in = $request->validate(['centre_id' => 'required|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $cid = $this->needCentre($request, $aid, $in['centre_id']);
        $r = HrRota::publish($aid, $cid, $in['from'] ?? null, $in['to'] ?? null, (int) $request->user()->id);
        $this->hrNote($aid, 'hr.rota_published', 'centre', $cid, ['added' => $r['added'], 'changed' => $r['changed'], 'removed' => $r['removed'],
            'kept' => count($r['kept']), 'from' => $in['from'] ?? null, 'to' => $in['to'] ?? null]);
        $n = $r['added'] + $r['changed'] + $r['removed'];
        $msg = $n ? 'Published: ' . $r['added'] . ' new, ' . $r['changed'] . ' changed, ' . $r['removed'] . ' removed. Staff hear about their own shifts in one notice each.' : 'Nothing was published.';
        if ($r['kept']) $msg .= ' ' . count($r['kept']) . ' draft' . (count($r['kept']) === 1 ? ' was' : 's were') . ' kept back (see why below).';

        return response()->json($r + ['message' => $msg]);
    }

    /**
     * POST /hr/rota/copy {centre_id, mode: week|person|day, source, target, user_id?, targets?[]}
     *  week   = the 7 days from `source` onto the 7 days from `target` (everyone, or user_id for "a person's week");
     *  day    = the day `source` onto each date in `targets` (same week, other days).
     */
    public function copy(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $in = $request->validate(['centre_id' => 'required|integer', 'mode' => 'required|in:week,person,day', 'source' => 'required|date_format:Y-m-d',
            'target' => 'nullable|date_format:Y-m-d', 'user_id' => 'nullable|integer', 'targets' => 'nullable|array|max:' . HrRota::MAX_COPY_DAYS,
            'targets.*' => 'date_format:Y-m-d']);
        $cid = $this->needCentre($request, $aid, $in['centre_id']);
        $pairs = [];
        if ($in['mode'] === 'day') {
            foreach (array_unique($in['targets'] ?? []) as $t) if ($t !== $in['source']) $pairs[] = [$in['source'], $t];
            abort_unless($pairs, 422, 'Choose at least one day to copy onto.');
        } else {
            abort_unless(! empty($in['target']) && $in['target'] !== $in['source'], 422, 'Choose the week to copy into.');
            for ($i = 0; $i < 7; $i++) $pairs[] = [date('Y-m-d', strtotime($in['source'] . " +$i day")), date('Y-m-d', strtotime($in['target'] . " +$i day"))];
        }
        $uid = null;
        if ($in['mode'] === 'person') {
            $uid = (int) ($in['user_id'] ?? 0);
            abort_unless($uid > 0 && HrRota::mayRoster($uid, $cid), 422, 'Choose a person on this centre\'s staff.');
        }
        $r = HrRota::copy($aid, $cid, $pairs, $uid, (int) $request->user()->id);
        $this->hrNote($aid, 'hr.rota_copied', 'centre', $cid, ['mode' => $in['mode'], 'source' => $in['source'], 'target' => $in['target'] ?? null,
            'targets' => $in['targets'] ?? null, 'user_id' => $uid, 'added' => $r['added'], 'skipped' => count($r['skipped']), 'drafted' => $r['drafted']]);
        $msg = $r['added'] ? $r['added'] . ' shift' . ($r['added'] === 1 ? '' : 's') . ' copied' . ($r['drafted'] ? ' as drafts. Publish when you are ready.' : '.') : 'Nothing was copied.';
        if ($r['skipped']) $msg .= ' ' . count($r['skipped']) . ' left out (see why).';

        return response()->json($r + ['message' => $msg]);
    }

    // ── settings, presets, budgets ────────────────────────────────────────────────────────

    /** GET /hr/rota/settings — the rota rules, presets (all centres the caller runs) and budgets (pay viewers). */
    public function settings(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $mine = $this->centres($request, $aid);
        $centres = DB::table('centres')->whereIn('id', $mine ?: [0])->orderBy('name')->get(['id', 'name']);
        $cmap = $centres->pluck('name', 'id')->all();
        $presets = DB::table('hr_shift_presets')->where('agency_id', $aid)->where(fn ($q) => $q->whereNull('centre_id')->orWhereIn('centre_id', $mine ?: [0]))
            ->orderByRaw('archived_at IS NOT NULL')->orderBy('sort')->orderBy('start_time')->get()->map(fn ($p) => HrRota::presetShape($p, $cmap))->all();
        $pay = $this->canSeePay($request, $aid);
        $budgets = [];
        if ($pay) {
            $wk = Carbon::parse(AgencyTime::today($aid))->startOfWeek(Carbon::SUNDAY);
            $b = DB::table('hr_labour_budgets')->whereIn('centre_id', $mine ?: [0])->pluck('weekly_amount', 'centre_id')->all();
            foreach ($centres as $c) {
                $p = HrRota::projected((int) $c->id, $wk->toDateString(), $wk->copy()->addDays(6)->toDateString());
                $budgets[] = ['centre_id' => (int) $c->id, 'centre' => $c->name, 'weekly_amount' => isset($b[$c->id]) ? (float) $b[$c->id] : null,
                    'this_week' => $p, 'week' => $wk->toDateString()];
            }
        }
        $pendingAccept = DB::table('hr_shift_acceptances as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->whereColumn('a.user_id', 's.user_id')
            ->where('a.agency_id', $aid)->whereIn('a.centre_id', $mine ?: [0])->whereIn('a.status', ['pending', 'declined'])
            ->where('s.starts_at', '>=', AgencyTime::today($aid) . ' 00:00:00')->orderBy('s.starts_at')->limit(200)
            ->get(['a.shift_id', 'a.status', 'a.note', 'a.user_id', 'a.centre_id', 's.starts_at', 's.ends_at']);
        $names = $this->hrNames($pendingAccept->pluck('user_id')->map(fn ($v) => (int) $v)->unique()->all());

        return response()->json(['settings' => HrRota::settings($aid), 'is_admin' => $this->hrIsAdmin($request), 'centres' => $centres, 'presets' => $presets,
            'colours' => HrRota::COLOURS, 'roles' => HrRota::ROLES, 'can_see_pay' => $pay, 'budgets' => $budgets,
            'acceptance' => $pendingAccept->map(fn ($a) => ['shift_id' => (int) $a->shift_id, 'status' => $a->status, 'note' => $a->note, 'person' => $names[(int) $a->user_id] ?? '',
                'centre' => $cmap[(int) $a->centre_id] ?? '', 'date' => substr((string) $a->starts_at, 0, 10), 'start' => substr((string) $a->starts_at, 11, 5),
                'end' => substr((string) $a->ends_at, 11, 5)])->all(),
            'pending' => collect($mine)->mapWithKeys(fn ($c) => [$c => HrRota::pendingCounts((int) $c)['total']])->filter()->all()]);
    }

    /** PUT /hr/rota/settings {draft_mode, require_accept} (admins). Switching the publish step off needs no drafts waiting. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $this->hrNeedAdmin($request);
        $in = $request->validate(['draft_mode' => 'required|boolean', 'require_accept' => 'required|boolean']);
        $before = HrRota::settings($aid);
        if ($before['draft_mode'] && ! $in['draft_mode']) {
            $n = DB::table('hr_shift_drafts')->where('agency_id', $aid)->count();
            abort_if($n > 0, 422, $n . ' rota draft' . ($n === 1 ? ' is' : 's are') . ' waiting. Publish or discard ' . ($n === 1 ? 'it' : 'them') . ' on the Schedule first, then switch the publish step off.');
        }
        $s = HrRota::saveSettings($aid, ['draft_mode' => (bool) $in['draft_mode'], 'require_accept' => (bool) $in['draft_mode'] && (bool) $in['require_accept']]);
        $this->hrNote($aid, 'hr.rota_settings_saved', 'agency', $aid, ['before' => $before, 'after' => $s]);

        return response()->json(['settings' => $s]);
    }

    private function presetInput(Request $request, int $aid): array
    {
        $in = $request->validate(['name' => 'required|string|max:60', 'start' => 'required|date_format:H:i', 'end' => 'required|date_format:H:i',
            'role' => 'nullable|in:lead,support,floater,volunteer', 'colour' => 'nullable|regex:/^#[0-9A-Fa-f]{6}$/', 'centre_id' => 'nullable|integer'],
            ['name.required' => 'Give the shift a name, for example "Early".']);
        abort_if($in['start'] === $in['end'], 422, 'The shift must end at a different time from when it starts.');
        $cid = ! empty($in['centre_id']) ? $this->needCentre($request, $aid, $in['centre_id']) : null;
        if (! $cid) abort_unless($this->hrIsAdmin($request), 403, 'Only an agency admin can add a shift for every centre. Choose your centre.');

        return ['name' => trim($in['name']), 'start_time' => $in['start'] . ':00', 'end_time' => $in['end'] . ':00', 'role' => $in['role'] ?? 'support',
            'colour' => strtoupper($in['colour'] ?? '#0E7490'), 'centre_id' => $cid];
    }

    private function preset(Request $request, int $aid, int $id): object
    {
        $p = DB::table('hr_shift_presets')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($p, 404, 'That shift preset was not found.');
        if ($p->centre_id) $this->needCentre($request, $aid, $p->centre_id);
        else abort_unless($this->hrIsAdmin($request), 403, 'Only an agency admin can change a shift used by every centre.');

        return $p;
    }

    /** POST /hr/rota/presets */
    public function createPreset(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $this->hrNeedManager($request);
        $row = $this->presetInput($request, $aid);
        $id = (int) DB::table('hr_shift_presets')->insertGetId($row + ['agency_id' => $aid, 'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.shift_preset_added', 'hr_shift_preset', $id, $row);

        return response()->json(['id' => $id], 201);
    }

    /** PUT /hr/rota/presets/{id} */
    public function updatePreset(Request $request, int $id): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $this->preset($request, $aid, $id);
        $row = $this->presetInput($request, $aid);
        DB::table('hr_shift_presets')->where('id', $id)->update($row + ['updated_at' => now()]);
        $this->hrNote($aid, 'hr.shift_preset_changed', 'hr_shift_preset', $id, $row);

        return response()->json(['ok' => true]);
    }

    /** POST /hr/rota/presets/{id}/archive {archived} — shifts made from it keep their colour. */
    public function archivePreset(Request $request, int $id): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $this->preset($request, $aid, $id);
        $arch = (bool) $request->input('archived', true);
        DB::table('hr_shift_presets')->where('id', $id)->update(['archived_at' => $arch ? now() : null, 'updated_at' => now()]);
        $this->hrNote($aid, $arch ? 'hr.shift_preset_archived' : 'hr.shift_preset_restored', 'hr_shift_preset', $id);

        return response()->json(['ok' => true]);
    }

    /** PUT /hr/rota/budgets/{centreId} {weekly_amount|null} — admins (pay viewers) only. */
    public function saveBudget(Request $request, int $centreId): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless($this->canSeePay($request, $aid), 403, 'Labour budgets are for people who can see pay (the payroll feature).');
        $cid = $this->needCentre($request, $aid, $centreId);
        $in = $request->validate(['weekly_amount' => 'nullable|numeric|min:0|max:10000000']);
        $before = DB::table('hr_labour_budgets')->where('centre_id', $cid)->value('weekly_amount');
        if (($in['weekly_amount'] ?? null) === null || $in['weekly_amount'] === '') {
            DB::table('hr_labour_budgets')->where('centre_id', $cid)->delete();
        } else {
            DB::table('hr_labour_budgets')->updateOrInsert(['centre_id' => $cid], ['agency_id' => $aid, 'weekly_amount' => round((float) $in['weekly_amount'], 2),
                'updated_by_id' => $request->user()->id, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->hrNote($aid, 'hr.labour_budget_saved', 'centre', $cid, ['before' => $before !== null ? (float) $before : null, 'after' => $in['weekly_amount'] ?? null]);

        return response()->json(['ok' => true]);
    }

    // ── staff: shifts to accept ───────────────────────────────────────────────────────────

    /** GET /hr/me/shifts-to-accept */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $me = (int) $request->user()->id;
        $staff = in_array($me, HrLeave::staffIds($aid), true);

        return response()->json(['is_staff' => $staff, 'shifts' => $staff ? HrRota::toAccept($aid, $me) : []]);
    }

    /** POST /hr/me/shifts/{shiftId}/respond {decision: accept|decline, note} — only the person the shift was published to. */
    public function respond(Request $request, int $shiftId): JsonResponse
    {
        $aid = $this->rotaAgency($request);
        $me = (int) $request->user()->id;
        $in = $request->validate(['decision' => 'required|in:accept,decline', 'note' => 'required_if:decision,decline|nullable|string|max:500'],
            ['note.required_if' => 'Say why you cannot do this shift, so your director can sort it out.']);
        $a = DB::table('hr_shift_acceptances as a')->join('shifts as s', 's.id', '=', 'a.shift_id')->join('rooms as r', 'r.id', '=', 's.room_id')
            ->where('a.shift_id', $shiftId)->where('a.agency_id', $aid)->where('a.user_id', $me)->whereColumn('a.user_id', 's.user_id')
            ->first(['a.id', 'a.status', 'a.centre_id', 's.starts_at', 's.ends_at', 'r.name as room', 'r.centre_id as shift_centre']);
        abort_unless($a, 404, 'That shift was not found.');
        abort_unless($a->status === 'pending', 422, 'You have already answered for this shift.');
        $st = $in['decision'] === 'accept' ? 'accepted' : 'declined';
        DB::table('hr_shift_acceptances')->where('id', $a->id)->where('status', 'pending')->update(['status' => $st, 'note' => $in['note'] ?? null,
            'responded_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.shift_' . $st, 'shift', $shiftId, ['note' => $in['note'] ?? null]);
        if ($st === 'declined') {
            $u = DB::table('users')->where('id', $me)->first(['first_name', 'preferred_name']);
            $first = trim((string) (($u->preferred_name ?? '') ?: ($u->first_name ?? ''))) ?: 'A team member';
            HrOpenShifts::bellManagers($aid, (int) $a->shift_centre, [$me], $first . ' can\'t do a shift',
                Carbon::parse($a->starts_at)->format('D, M j, g:i A') . ' – ' . Carbon::parse($a->ends_at)->format('g:i A') . ' (' . $a->room . '): ' . ($in['note'] ?? ''),
                '#schedule?tab=rotatools');
        }

        return response()->json(['ok' => true, 'status' => $st]);
    }
}
