<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrLeaveMaths;
use App\Support\Live;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 4, batch 9 (2026-11-27): Time off > Closures & checks (App\Support\HrLeaveMaths).
 *  - Shutdowns: per closure, an agency admin chooses Deduct (from a leave type, after a preview, people can be left out)
 *    or Don't deduct. Never automatic. Reverse gives it all back. Directors see their centres' list read-only.
 *  - Leave to review: booked amounts a closure change may have made wrong. A manager of the person (never the person)
 *    corrects it (one new ledger row for the difference) or keeps it. Shutdown re-checks: admins.
 *  - Part-time pro-rata settings + preview (admins).
 * Every balance change is a NEW hr_leave_ledger row with a reason; nothing is edited.
 */
class HrLeaveMathsController extends Controller
{
    use HrAccess;

    private function lmAgency(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(HrLeaveMaths::ready(), 503, 'This is being set up. Please try again in a minute.');
        $this->hrNeedManager($request);

        return $aid;
    }

    private function fmt(float $v): string
    {
        return rtrim(rtrim(number_format($v, 2), '0'), '.');
    }

    /** Closures the caller may see: admins every centre of the agency, directors their own (open centres only). */
    private function centreIds(Request $request, int $aid): array
    {
        $open = Live::centreIds($aid);
        $scope = CentreScope::ids($request, $aid);

        return $scope === null ? $open : array_values(array_intersect($open, $scope));
    }

    private function typeOf(int $aid, int $id): object
    {
        $t = DB::table('hr_leave_types')->where('id', $id)->where('agency_id', $aid)->whereNull('archived_at')->first();
        abort_unless($t, 422, 'Choose a leave type.');

        return $t;
    }

    // ── shutdowns ──────────────────────────────────────────────────────────────────

    /** GET /hr/shutdowns — closures that may deduct leave (last 12 months and later) with the decision on each. */
    public function shutdowns(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $today = AgencyTime::today($aid);
        $since = \Illuminate\Support\Carbon::parse($today)->subYear()->toDateString();
        $cids = $this->centreIds($request, $aid);
        $rows = DB::table('centre_closures as cc')->join('centres as c', 'c.id', '=', 'cc.centre_id')
            ->whereIn('cc.centre_id', $cids ?: [0])->whereIn('cc.closure_type', HrLeaveMaths::SHUTDOWN_TYPES)
            ->whereRaw('COALESCE(cc.end_date, cc.closure_date) >= ?', [$since])
            ->orderBy('cc.closure_date')->get(['cc.id', 'cc.centre_id', 'cc.closure_date', 'cc.end_date', 'cc.closure_type', 'cc.reason', 'c.name as centre']);
        $dec = DB::table('hr_shutdowns')->where('agency_id', $aid)->get()->keyBy('closure_id');
        $flags = DB::table('hr_leave_rechecks')->where('agency_id', $aid)->where('status', 'open')->whereNotNull('shutdown_id')->pluck('id', 'shutdown_id')->all();
        $types = HrLeave::types($aid)->keyBy('id');
        $names = $this->hrNames($dec->pluck('decided_by_id')->filter()->map(fn ($v) => (int) $v)->all());
        $out = $rows->map(function ($c) use ($dec, $today, $types, $names, $flags) {
            [$a, $b] = HrLeaveMaths::closureRange($c);
            $d = $dec[$c->id] ?? null;
            $t = $d && $d->leave_type_id ? ($types[$d->leave_type_id] ?? null) : null;

            return ['closure_id' => (int) $c->id, 'centre_id' => (int) $c->centre_id, 'centre' => $c->centre, 'from' => $a, 'to' => $b,
                'when' => HrLeaveMaths::rangeLabel($a, $b), 'type' => $c->closure_type, 'reason' => $c->reason, 'past' => $b < $today, 'started' => $a <= $today,
                'decision' => $d ? ['id' => (int) $d->id, 'choice' => $d->choice, 'status' => $d->status, 'leave_type' => $t->name ?? null, 'unit' => $t->unit ?? null,
                    'leave_type_id' => $d->leave_type_id ? (int) $d->leave_type_id : null, 'total' => (float) $d->amount_total, 'people' => (int) $d->people_count,
                    'by' => $names[(int) $d->decided_by_id] ?? '', 'at' => $d->decided_at ? (string) $d->decided_at : null, 'recheck_id' => $flags[$d->id] ?? null] : null];
        })->values();
        // Decisions whose closure was removed: still listed, so a deduction can be reviewed or reversed.
        $gone = $dec->filter(fn ($d) => ! $rows->firstWhere('id', $d->closure_id) && $d->status === 'applied' && in_array((int) $d->centre_id, $cids, true))
            ->map(function ($d) use ($types, $names, $flags, $today) {
                $t = $types[$d->leave_type_id] ?? null;

                return ['closure_id' => (int) $d->closure_id, 'centre_id' => (int) $d->centre_id, 'centre' => (string) DB::table('centres')->where('id', $d->centre_id)->value('name'),
                    'from' => (string) $d->closure_from, 'to' => (string) $d->closure_to, 'when' => HrLeaveMaths::rangeLabel((string) $d->closure_from, (string) $d->closure_to),
                    'type' => $d->closure_type, 'reason' => $d->closure_reason, 'past' => (string) $d->closure_to < $today, 'started' => true, 'removed' => true,
                    'decision' => ['id' => (int) $d->id, 'choice' => $d->choice, 'status' => $d->status, 'leave_type' => $t->name ?? null, 'unit' => $t->unit ?? null,
                        'leave_type_id' => (int) $d->leave_type_id, 'total' => (float) $d->amount_total, 'people' => (int) $d->people_count,
                        'by' => $names[(int) $d->decided_by_id] ?? '', 'at' => (string) $d->decided_at, 'recheck_id' => $flags[$d->id] ?? null]];
            })->values();
        $def = HrLeaveMaths::defaultType($aid);

        return response()->json(['rows' => $out->merge($gone)->values(), 'can_decide' => $this->hrIsAdmin($request), 'today' => $today,
            'types' => HrLeave::types($aid)->filter(fn ($t) => $t->accrual !== 'none' || DB::table('hr_leave_ledger')->where('leave_type_id', $t->id)->exists())
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'unit' => $t->unit])->values(),
            'default_type_id' => $def ? (int) $def->id : null]);
    }

    private function closureFor(Request $request, int $aid, int $closureId): object
    {
        $c = DB::table('centre_closures')->where('id', $closureId)->first();
        abort_unless($c && in_array((int) $c->centre_id, $this->centreIds($request, $aid), true), 404, 'That closure was not found.');
        abort_unless(in_array($c->closure_type, HrLeaveMaths::SHUTDOWN_TYPES, true), 422, 'Only a holiday, emergency or other closure can take leave off. A statutory holiday never does.');

        return $c;
    }

    /** GET /hr/shutdowns/preview?closure_id=&leave_type_id=&exclude[]= — who would lose how much. Reads only. */
    public function preview(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $c = $this->closureFor($request, $aid, (int) $request->query('closure_id'));
        $t = $this->typeOf($aid, (int) ($request->query('leave_type_id') ?: (HrLeaveMaths::defaultType($aid)->id ?? 0)));
        $sd = DB::table('hr_shutdowns')->where('agency_id', $aid)->where('closure_id', $c->id)->first();
        $ex = array_map('intval', (array) $request->query('exclude', $sd ? (json_decode((string) $sd->excluded_user_ids, true) ?: []) : []));
        $p = HrLeaveMaths::preview($aid, $c, $t, $ex, $sd ? (int) $sd->id : null);
        $p['leave_type'] = ['id' => (int) $t->id, 'name' => $t->name, 'unit' => $t->unit];
        $p['allow_negative'] = HrLeave::settings($aid)['allow_negative'];
        $p['me'] = (int) $request->user()->id;

        return response()->json($p);
    }

    /** POST /hr/shutdowns/decide {closure_id, choice: deduct|none, leave_type_id, exclude_user_ids[], confirm_past} — admins. */
    public function decide(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['closure_id' => 'required|integer', 'choice' => 'required|in:deduct,none', 'leave_type_id' => 'nullable|integer',
            'exclude_user_ids' => 'nullable|array|max:500', 'exclude_user_ids.*' => 'integer', 'confirm_past' => 'nullable|boolean']);
        $c = $this->closureFor($request, $aid, (int) $d['closure_id']);
        [$a, $b] = HrLeaveMaths::closureRange($c);
        $today = AgencyTime::today($aid);
        $me = (int) $request->user()->id;
        $t = $d['choice'] === 'deduct' ? $this->typeOf($aid, (int) ($d['leave_type_id'] ?? 0)) : null;
        if ($t && $b < $today && empty($d['confirm_past'])) {
            return response()->json(['message' => 'This closure is over. Tick that you want to take the leave off anyway.', 'needs_confirm' => true], 422);
        }
        $ex = array_values(array_unique(array_map('intval', $d['exclude_user_ids'] ?? [])));
        $res = DB::transaction(function () use ($aid, $c, $a, $b, $t, $d, $ex, $me) {
            $sd = DB::table('hr_shutdowns')->where('agency_id', $aid)->where('closure_id', $c->id)->lockForUpdate()->first();
            if ($sd && $sd->status === 'applied') {
                abort(422, 'Leave was already taken off for this closure. Reverse it first to change the choice.');
            }
            $snap = ['centre_id' => (int) $c->centre_id, 'closure_from' => $a, 'closure_to' => $b, 'closure_type' => $c->closure_type,
                'closure_reason' => $c->reason, 'choice' => $d['choice'], 'leave_type_id' => $t ? (int) $t->id : null, 'excluded_user_ids' => json_encode($ex),
                'decided_by_id' => $me, 'decided_at' => now(), 'updated_at' => now()];
            if ($sd) {
                DB::table('hr_shutdowns')->where('id', $sd->id)->update($snap + ['status' => $t ? 'applied' : 'none']);
                $id = (int) $sd->id;
            } else {
                $id = (int) DB::table('hr_shutdowns')->insertGetId($snap + ['agency_id' => $aid, 'closure_id' => (int) $c->id, 'status' => $t ? 'applied' : 'none',
                    'round' => 0, 'amount_total' => 0, 'people_count' => 0, 'created_at' => now()]);
            }
            if (! $t) {
                return ['id' => $id, 'people' => 0, 'total' => 0.0];
            }
            $sd = DB::table('hr_shutdowns')->where('id', $id)->first();
            $p = HrLeaveMaths::preview($aid, $c, $t, $ex, $id);
            $reason = 'Centre closed ' . HrLeaveMaths::rangeLabel($a, $b) . ($c->reason ? ' (' . $c->reason . ')' : '') . ': taken from ' . $t->name;

            return ['id' => $id] + HrLeaveMaths::writeLines($sd, $p, $t, $me, $reason);
        });
        DB::table('hr_leave_rechecks')->where('shutdown_id', $res['id'])->where('status', 'open')->update(['status' => 'cleared', 'resolved_at' => now(), 'updated_at' => now(), 'note' => 'Decided again']);
        $this->hrNote($aid, $t ? 'hr.shutdown_deducted' : 'hr.shutdown_not_deducted', 'centre_closure', (int) $c->id,
            ['leave_type' => $t->name ?? null, 'people' => $res['people'], 'total' => $res['total'], 'left_out' => $ex]);

        return response()->json($res + ['ok' => true]);
    }

    /** POST /hr/shutdowns/{id}/reverse {reason} — admins: give back everything this shutdown took (new ledger rows). */
    public function reverse(Request $request, int $id): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['reason' => 'required|string|min:3|max:300'], ['reason.required' => 'Say why, so the balance history explains it.']);
        $me = (int) $request->user()->id;
        $res = DB::transaction(function () use ($aid, $id, $d, $me) {
            $sd = DB::table('hr_shutdowns')->where('id', $id)->where('agency_id', $aid)->lockForUpdate()->first();
            abort_unless($sd, 404, 'That was not found.');
            abort_unless(in_array((int) $sd->centre_id, $this->centreIds(request(), $aid), true) || ! DB::table('centres')->where('id', $sd->centre_id)->exists(), 404, 'That was not found.');
            abort_unless($sd->status === 'applied', 422, 'Nothing was taken off for this closure.');
            $t = DB::table('hr_leave_types')->where('id', $sd->leave_type_id)->first();
            $round = (int) $sd->round + 1;
            $n = 0;
            $sum = 0.0;
            $bells = [];
            foreach (HrLeaveMaths::netByUser((int) $sd->id) as $uid => $net) {
                if (abs($net) < 0.005) continue;
                DB::table('hr_leave_ledger')->insert(['agency_id' => $aid, 'user_id' => (int) $uid, 'leave_type_id' => (int) $sd->leave_type_id,
                    'entry_date' => AgencyTime::today($aid), 'amount' => round($net, 2), 'kind' => HrLeaveMaths::KIND, 'source_key' => 'shut-undo:' . $sd->id . ':' . $round,
                    'reason' => mb_substr('Closure deduction reversed: ' . trim($d['reason']), 0, 500), 'created_by_id' => $me, 'created_at' => now()]);
                $n++;
                $sum += $net;
                if ($t && $t->visible_to_staff && (int) $uid !== $me) {
                    $bells[] = ['user_id' => (int) $uid, 'agency_id' => $aid, 'type' => 'time_off',
                        'title' => 'Your ' . $t->name . ' balance was increased by ' . $this->fmt($net) . ' ' . $t->unit,
                        'body' => 'Closure deduction reversed: ' . trim($d['reason']), 'data' => json_encode(['link' => '#time-off']), 'created_at' => now()];
                }
            }
            DB::table('hr_shutdown_lines')->where('shutdown_id', $sd->id)->update(['current' => 0]);
            DB::table('hr_shutdowns')->where('id', $sd->id)->update(['status' => 'reversed', 'round' => $round, 'reversed_by_id' => $me, 'reversed_at' => now(),
                'reverse_reason' => mb_substr(trim($d['reason']), 0, 300), 'updated_at' => now()]);
            DB::table('hr_leave_rechecks')->where('shutdown_id', $sd->id)->where('status', 'open')->update(['status' => 'cleared', 'resolved_at' => now(), 'updated_at' => now(), 'note' => 'Reversed']);
            try { if ($bells) \App\Support\Notify::write($bells); } catch (\Throwable $e) { report($e); }

            return ['people' => $n, 'total' => round($sum, 2), 'closure_id' => (int) $sd->closure_id];
        });
        $this->hrNote($aid, 'hr.shutdown_reversed', 'centre_closure', $res['closure_id'], ['people' => $res['people'], 'total' => $res['total'], 'reason' => trim($d['reason'])]);

        return response()->json($res + ['ok' => true]);
    }

    // ── leave to review ────────────────────────────────────────────────────────────

    /** GET /hr/leave-rechecks?status=open|done — requests: the caller's people; shutdowns: admins. */
    public function rechecks(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $people = $this->hrPeople($request, $aid);
        $admin = $this->hrIsAdmin($request);
        $done = $request->query('status') === 'done';
        $q = DB::table('hr_leave_rechecks')->where('agency_id', $aid)
            ->when($done, fn ($w) => $w->where('status', '!=', 'open'), fn ($w) => $w->where('status', 'open'))
            ->where(function ($w) use ($people, $admin) {
                $w->whereIn('user_id', $people ?: [0]);
                if ($admin) $w->orWhereNotNull('shutdown_id');
            })->orderByDesc('updated_at')->limit($done ? 100 : 400)->get();
        // A flagged request that was since declined or withdrawn has nothing left to review.
        if (! $done) {
            $live = DB::table('time_off_requests')->whereIn('id', $q->pluck('time_off_request_id')->filter()->all() ?: [0])->whereIn('status', ['approved', 'pending'])->pluck('id')->flip();
            foreach ($q as $k => $r) {
                if ($r->time_off_request_id && ! isset($live[$r->time_off_request_id])) {
                    DB::table('hr_leave_rechecks')->where('id', $r->id)->update(['status' => 'cleared', 'resolved_at' => now(), 'updated_at' => now(), 'note' => 'No longer approved or pending']);
                    unset($q[$k]);
                }
            }
        }
        $names = $this->hrNames($q->pluck('user_id')->merge($q->pluck('resolved_by_id'))->filter()->map(fn ($v) => (int) $v)->unique()->all());
        $reqs = DB::table('time_off_requests')->whereIn('id', $q->pluck('time_off_request_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $sds = DB::table('hr_shutdowns')->whereIn('id', $q->pluck('shutdown_id')->filter()->all() ?: [0])->get()->keyBy('id');
        $types = DB::table('hr_leave_types')->where('agency_id', $aid)->pluck('name', 'id');
        $me = (int) $request->user()->id;
        $rows = $q->map(function ($r) use ($names, $reqs, $sds, $types, $me) {
            $req = $r->time_off_request_id ? ($reqs[$r->time_off_request_id] ?? null) : null;
            $sd = $r->shutdown_id ? ($sds[$r->shutdown_id] ?? null) : null;
            $when = $req ? \App\Http\Controllers\Api\TimeOffController::whenPhrase($req)
                : ($sd ? HrLeaveMaths::rangeLabel((string) $sd->closure_from, (string) $sd->closure_to) : '');

            return ['id' => (int) $r->id, 'kind' => $r->shutdown_id ? 'shutdown' : 'request', 'request_id' => $r->time_off_request_id ? (int) $r->time_off_request_id : null,
                'shutdown_id' => $r->shutdown_id ? (int) $r->shutdown_id : null, 'user_id' => $r->user_id ? (int) $r->user_id : null,
                'person' => $r->user_id ? ($names[(int) $r->user_id] ?? '') : 'Everyone this closure took leave from',
                'status_of_request' => $req->status ?? null, 'when' => $when, 'leave_type' => $types[$r->leave_type_id] ?? '', 'unit' => $r->unit,
                'booked' => (float) $r->booked, 'fresh' => (float) $r->fresh, 'cause' => $r->cause, 'status' => $r->status, 'note' => $r->note,
                'own' => $r->user_id && (int) $r->user_id === $me, 'resolved_by' => $names[(int) $r->resolved_by_id] ?? '', 'resolved_at' => $r->resolved_at ? (string) $r->resolved_at : null,
                'updated_at' => (string) $r->updated_at];
        })->values();

        return response()->json(['rows' => $rows, 'can_scan' => $this->hrIsAdmin($request)]);
    }

    /** POST /hr/leave-rechecks/{id}/resolve {outcome: correct|keep, note?} — never your own request. */
    public function resolve(Request $request, int $id): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $d = $request->validate(['outcome' => 'required|in:correct,keep', 'note' => 'nullable|string|max:300']);
        $me = (int) $request->user()->id;
        $rc = DB::table('hr_leave_rechecks')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($rc, 404, 'That was not found.');
        abort_unless($rc->status === 'open', 422, 'This has already been reviewed.');
        if ($rc->shutdown_id) {
            $this->hrNeedAdmin($request);

            return $this->resolveShutdown($request, $aid, $rc, $d, $me);
        }
        abort_unless(in_array((int) $rc->user_id, $this->hrPeople($request, $aid), true), 404, 'That was not found.');
        abort_if((int) $rc->user_id === $me, 403, 'Someone else has to review your own time off.');
        $out = DB::transaction(function () use ($rc, $d, $me) {
            $r = DB::table('time_off_requests')->where('id', $rc->time_off_request_id)->lockForUpdate()->first();
            abort_unless($r && in_array($r->status, ['approved', 'pending'], true), 422, 'This request is no longer approved or waiting.');
            $t = HrLeave::typeForRequest($r);
            abort_unless($t, 422, 'This request has no leave type any more.');
            $fresh = HrLeaveMaths::freshAmount($r, $t);
            $booked = HrLeaveMaths::bookedAmount($r, $t);
            if ($d['outcome'] === 'correct') {
                if (! empty($r->leave_type_id) && (int) $r->leave_type_id === (int) $t->id) {
                    DB::table('time_off_requests')->where('id', $r->id)->update(['leave_amount' => $fresh, 'updated_at' => now()]);
                }
                if ($r->status === 'approved') {
                    HrLeave::syncRequest(DB::table('time_off_requests')->where('id', $r->id)->first(), $me,
                        'Amount corrected after a closure change' . (! empty($d['note']) ? ': ' . trim($d['note']) : ''));
                }
            }
            DB::table('hr_leave_rechecks')->where('id', $rc->id)->update(['status' => $d['outcome'] === 'correct' ? 'corrected' : 'kept', 'booked' => $booked ?? $rc->booked,
                'fresh' => $fresh, 'note' => $d['note'] ?? null, 'resolved_by_id' => $me, 'resolved_at' => now(), 'updated_at' => now()]);

            return ['request_id' => (int) $r->id, 'from' => $booked, 'to' => $d['outcome'] === 'correct' ? $fresh : $booked, 'balance' => HrLeave::balance((int) $r->user_id, (int) $t->id)];
        });
        $this->hrNote($aid, $d['outcome'] === 'correct' ? 'hr.leave_amount_corrected' : 'hr.leave_amount_kept', 'time_off_request', $out['request_id'],
            ['from' => $out['from'], 'to' => $out['to'], 'note' => $d['note'] ?? null]);

        return response()->json($out + ['ok' => true]);
    }

    private function resolveShutdown(Request $request, int $aid, object $rc, array $d, int $me): JsonResponse
    {
        $out = DB::transaction(function () use ($aid, $rc, $d, $me) {
            $sd = DB::table('hr_shutdowns')->where('id', $rc->shutdown_id)->where('agency_id', $aid)->lockForUpdate()->first();
            abort_unless($sd && $sd->status === 'applied', 422, 'This closure deduction is no longer applied.');
            $res = ['people' => 0, 'total' => 0.0];
            if ($d['outcome'] === 'correct') {
                $t = DB::table('hr_leave_types')->where('id', $sd->leave_type_id)->first();
                $c = $sd->closure_id ? DB::table('centre_closures')->where('id', $sd->closure_id)->first() : null;
                $p = HrLeaveMaths::preview($aid, $c, $t, json_decode((string) $sd->excluded_user_ids, true) ?: [], (int) $sd->id);
                if ($c) {
                    [$a, $b] = HrLeaveMaths::closureRange($c);
                    DB::table('hr_shutdowns')->where('id', $sd->id)->update(['closure_from' => $a, 'closure_to' => $b, 'closure_reason' => $c->reason]);
                }
                $why = $c ? 'Closure dates changed to ' . HrLeaveMaths::rangeLabel(...HrLeaveMaths::closureRange($c)) : 'The closure was removed';
                $res = HrLeaveMaths::writeLines($sd, $p, $t, $me, $why . (! empty($d['note']) ? ': ' . trim($d['note']) : ''));
            }
            DB::table('hr_leave_rechecks')->where('id', $rc->id)->update(['status' => $d['outcome'] === 'correct' ? 'corrected' : 'kept', 'note' => $d['note'] ?? null,
                'resolved_by_id' => $me, 'resolved_at' => now(), 'updated_at' => now()]);

            return $res + ['closure_id' => (int) $sd->closure_id];
        });
        $this->hrNote($aid, $d['outcome'] === 'correct' ? 'hr.shutdown_corrected' : 'hr.shutdown_kept', 'centre_closure', $out['closure_id'], $out);

        return response()->json($out + ['ok' => true]);
    }

    /** POST /hr/leave-rechecks/scan — admins: check every booked request of the last year against today's closures. */
    public function scan(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $this->hrNeedAdmin($request);
        $r = HrLeaveMaths::scan($aid);
        $this->hrNote($aid, 'hr.leave_recheck_scan', 'agency', $aid, $r);

        return response()->json($r);
    }

    // ── part-time pro-rata ─────────────────────────────────────────────────────────

    /** GET /hr/leave-maths/settings */
    public function settings(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);

        return response()->json(['settings' => HrLeaveMaths::settings($aid, true), 'can_edit' => $this->hrIsAdmin($request),
            'employment_types' => array_diff_key(HrLeave::EMPLOYMENT, ['full_time' => 1, 'contract' => 1]),
            'types' => HrLeave::types($aid)->filter(fn ($t) => in_array($t->accrual, ['lump', 'pay_period'], true))
                ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'unit' => $t->unit, 'accrual' => $t->accrual])->values()]);
    }

    private function settingsInput(Request $request, int $aid): array
    {
        $d = $request->validate(['pro_rata' => 'required|boolean', 'full_time_hours' => 'required|numeric|min:1|max:80',
            'type_ids' => 'nullable|array|max:50', 'type_ids.*' => 'integer', 'employment_types' => 'nullable|array', 'employment_types.*' => 'in:part_time,casual,student']);
        $own = HrLeave::types($aid)->filter(fn ($t) => in_array($t->accrual, ['lump', 'pay_period'], true))->pluck('id')->map(fn ($v) => (int) $v)->all();
        $d['type_ids'] = array_values(array_intersect(array_map('intval', $d['type_ids'] ?? []), $own));
        $d['employment_types'] = array_values(array_unique($d['employment_types'] ?? HrLeaveMaths::DEFAULTS['employment_types']));
        abort_if($d['pro_rata'] && ! $d['type_ids'], 422, 'Choose at least one leave type for pro-rata.');
        abort_if($d['pro_rata'] && ! $d['employment_types'], 422, 'Choose who counts as part-time.');

        return $d;
    }

    /** GET /hr/leave-maths/pro-rata-preview?... (same fields as the save) — who would get what on their next grant. Reads only. */
    public function proRataPreview(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $this->hrNeedAdmin($request);
        $d = $this->settingsInput($request, $aid);
        $d['pro_rata'] = true;

        return response()->json(HrLeaveMaths::proRataPreview($aid, $d));
    }

    /** PUT /hr/leave-maths/settings — admins. */
    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->lmAgency($request);
        $this->hrNeedAdmin($request);
        $before = HrLeaveMaths::settings($aid, true);
        $s = HrLeaveMaths::saveSettings($aid, $this->settingsInput($request, $aid));
        $this->hrNote($aid, 'hr.leave_maths_settings', 'agency', $aid, ['before' => $before, 'after' => $s]);

        return response()->json(['settings' => $s]);
    }
}
