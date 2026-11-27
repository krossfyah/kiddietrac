<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\Payroll\PayrollEmployer;
use App\Support\Payroll\PayrollTax;
use App\Support\Payroll\PayRuns;
use App\Support\Payroll\Payslip;
use App\Support\Payroll\Remittance;
use App\Support\Payroll\TaxTables;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): the HR settings switch + employer details, pay runs, payslips, opening
 * year-to-date balances and the remittance report. Routes under hr/payroll (the `hr` add-on MAP covers them) and hr/me.
 *
 * Who: the switch and employer details, opening balances = agency admins (never their own balance). Pay runs, payslips,
 * remittances = whoever can see pay today (directors and admins with the payroll feature): admins the whole agency,
 * directors only runs for their own centres (CentreScope); agency-wide runs are admin-only. Nobody approves a run that
 * pays them. My payslips = the person, own documents only.
 * Engine off = every route here answers {enabled:false} (reads) or 403 (writes), except the switch itself.
 */
class PayrollRunController extends Controller
{
    use HrAccess;

    private function on(Request $request): int
    {
        $aid = $this->hrAgency($request);
        abort_unless(PayrollTax::engineOn($aid), 403, 'Payroll is not switched on for your agency. An agency admin can switch it on in HR settings.');

        return $aid;
    }

    private function needPay(Request $request, int $aid): void
    {
        $this->hrNeedManager($request);
        abort_unless(Features::on($aid, 'payroll'), 403, 'Payroll is not included in your agency\'s plan.');
    }

    private function needAck(int $aid): void
    {
        abort_unless(PayrollEmployer::acknowledged($aid), 422, 'An agency admin has to read and accept the "check with your accountant" notice in HR settings first.');
    }

    private function me(Request $request): int
    {
        return (int) $request->user()->id;
    }

    private function centreNames(int $aid): array
    {
        return DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id')->mapWithKeys(fn ($v, $k) => [(int) $k => (string) $v])->all();
    }

    /** The run, if this caller may see it (404 otherwise). */
    private function run(Request $request, int $aid, int $id): object
    {
        $r = DB::table('payroll_runs')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($r, 404, 'Not found.');
        $scope = CentreScope::ids($request, $aid);
        abort_unless($scope === null || ($r->centre_id && in_array((int) $r->centre_id, $scope, true)), 404, 'Not found.');

        return $r;
    }

    private function shapeFull(Request $request, int $aid, object $r): array
    {
        $lines = DB::table('payroll_run_lines')->where('run_id', $r->id)->get();
        $ids = array_merge($lines->pluck('user_id')->map(fn ($v) => (int) $v)->all(), array_filter([(int) $r->created_by_id, (int) $r->submitted_by_id, (int) $r->approved_by_id, (int) $r->finalized_by_id]));
        $names = $this->hrNames($ids);
        $out = PayRuns::shapeRun($r, $names, $this->centreNames($aid));
        $out['lines'] = $lines->map(fn ($l) => PayRuns::shapeLine($l, $names))->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all();
        $me = $this->me($request);
        $paidHere = in_array($me, PayRuns::paidIds((int) $r->id), true);
        $out['can'] = ['edit' => $r->status === 'draft', 'submit' => $r->status === 'draft', 'send_back' => in_array($r->status, ['review', 'approved'], true),
            'approve' => $r->status === 'review' && ! $paidHere, 'finalize' => $r->status === 'approved', 'discard' => in_array($r->status, ['draft', 'review', 'approved'], true),
            'adjust' => $r->status === 'finalized' && $r->kind === 'regular'];
        $out['paid_here'] = $paidHere;
        if ($r->kind === 'adjustment' && $r->adjusts_run_id) {
            $out['adjusts'] = DB::table('payroll_runs')->where('id', $r->adjusts_run_id)->value('reference');
        }
        $out['adjustments'] = DB::table('payroll_runs')->where('adjusts_run_id', $r->id)->where('status', '!=', 'discarded')->get(['id', 'reference', 'status'])
            ->map(fn ($x) => ['id' => (int) $x->id, 'reference' => $x->reference, 'status' => $x->status])->all();

        return $out;
    }

    // ---- The switch + employer details ----------------------------------------------------------------------------

    /** GET /hr/payroll/engine: the switch, the acknowledgement and the employer details (managers read; admins edit). */
    public function engine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $s = PayrollEmployer::settings($aid);
        $y = (int) AgencyTime::now($aid)->format('Y');
        $by = $s['acknowledged_by'] ? ($this->hrNames([$s['acknowledged_by']])[$s['acknowledged_by']] ?? null) : null;

        return response()->json(['enabled' => $s['enabled'], 'settings' => $s + ['acknowledged_by_name' => $by], 'acknowledged' => (bool) $s['acknowledged_at'],
            'ack_text' => PayrollEmployer::ACK_TEXT, 'remitters' => PayrollEmployer::REMITTERS, 'can_edit' => $this->hrIsAdmin($request),
            'payroll_feature' => Features::on($aid, 'payroll'),
            'tables' => ['this_year' => ['year' => $y, 'loaded' => TaxTables::hasYear('CA', $y)], 'next_year' => ['year' => $y + 1, 'loaded' => TaxTables::hasYear('CA', $y + 1)]],
            'pay_frequency' => PayrollTax::agencyPeriods($aid)]);
    }

    /** PUT /hr/payroll/engine (agency admins): switch on/off, accept the notice once, employer numbers + remitter type. */
    public function saveEngine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate([
            'enabled' => 'sometimes|boolean', 'acknowledge' => 'sometimes|boolean',
            'rp_number' => 'sometimes|nullable|string|max:24', 'rq_number' => 'sometimes|nullable|string|max:24',
            'remitter_type' => 'sometimes|string|in:' . implode(',', array_keys(PayrollEmployer::REMITTERS)),
            'wsib_rate' => 'sometimes|nullable|numeric|min:0|max:20',
        ]);
        $cur = PayrollEmployer::settings($aid);
        $in = [];
        $what = [];
        if (! empty($d['acknowledge']) && ! $cur['acknowledged_at']) {
            $in['acknowledged_at'] = now()->toIso8601String();
            $in['acknowledged_by'] = $this->me($request);
            $what[] = 'accepted the "check with your accountant" notice';
        }
        if (array_key_exists('enabled', $d) && (bool) $d['enabled'] !== $cur['enabled']) {
            if ($d['enabled'] && ! $cur['acknowledged_at'] && empty($in['acknowledged_at'])) {
                return response()->json(['message' => 'Read and accept the notice first: check with your accountant before your first live pay run.'], 422);
            }
            $in['enabled'] = (bool) $d['enabled'];
            $what[] = $d['enabled'] ? 'switched payroll on' : 'switched payroll off';
        }
        foreach (['rp_number' => 'cleanRp', 'rq_number' => 'cleanRq'] as $k => $fn) {
            if (array_key_exists($k, $d)) {
                $raw = trim((string) $d[$k]);
                $v = $raw === '' ? null : PayrollEmployer::$fn($raw);
                if ($raw !== '' && $v === null) {
                    return response()->json(['message' => $k === 'rp_number' ? 'The CRA payroll account is 9 digits, RP and 4 digits, for example 123456789RP0001.'
                        : 'The Revenu Québec number is 10 digits, RS and 4 digits, for example 1234567890RS0001.'], 422);
                }
                if ($v !== $cur[$k]) {
                    $in[$k] = $v;
                    $what[] = $k === 'rp_number' ? 'changed the CRA payroll account' : 'changed the Revenu Québec number';
                }
            }
        }
        if (array_key_exists('remitter_type', $d) && $d['remitter_type'] !== $cur['remitter_type']) {
            $in['remitter_type'] = $d['remitter_type'];
            $what[] = 'set the remitter type to ' . $d['remitter_type'];
        }
        if (array_key_exists('wsib_rate', $d) && (string) ($d['wsib_rate'] ?? '') !== (string) ($cur['wsib_rate'] ?? '')) {
            $in['wsib_rate'] = $d['wsib_rate'] !== null ? round((float) $d['wsib_rate'], 4) : null;
            $what[] = 'set the workers\' compensation rate';
        }
        if ($in) {
            PayrollEmployer::save($aid, $in);
            $this->hrNote($aid, isset($in['enabled']) ? ($in['enabled'] ? 'payroll.engine_on' : 'payroll.engine_off') : 'payroll.engine_settings', 'agency', $aid,
                ['summary' => ucfirst(implode(', ', $what)) . '.']);
        }

        return $this->engine($request);
    }

    // ---- Pay runs --------------------------------------------------------------------------------------------------

    /** GET /hr/payroll/runs?year= */
    public function runs(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        if (! PayrollTax::engineOn($aid)) {
            return response()->json(['enabled' => false]);
        }
        $this->needPay($request, $aid);
        $scope = CentreScope::ids($request, $aid);
        $y = (int) ($request->query('year') ?: AgencyTime::now($aid)->format('Y'));
        $rows = DB::table('payroll_runs')->where('agency_id', $aid)->where('status', '!=', 'discarded')
            ->where(fn ($w) => $w->whereRaw('YEAR(pay_date) = ?', [$y])->orWhereIn('status', ['draft', 'review', 'approved']))
            ->when($scope !== null, fn ($q) => $q->whereIn('centre_id', $scope ?: [0]))
            ->orderByDesc('pay_date')->orderByDesc('id')->get();
        $names = $this->hrNames($rows->flatMap(fn ($r) => [(int) $r->created_by_id, (int) $r->approved_by_id, (int) $r->finalized_by_id])->filter()->unique()->values()->all());
        $centres = $this->centreNames($aid);
        $periods = PayrollTax::agencyPeriods($aid);
        $next = \App\Http\Controllers\Api\PayrollSettingsController::nextPayday($aid);

        return response()->json(['enabled' => true, 'acknowledged' => PayrollEmployer::acknowledged($aid), 'year' => $y,
            'runs' => $rows->map(fn ($r) => PayRuns::shapeRun($r, $names, $centres))->values(),
            'centres' => $scope === null ? $centres : array_intersect_key($centres, array_flip($scope)), 'agency_wide' => $scope === null,
            'periods' => $periods, 'frequencies' => PayrollTax::FREQUENCIES, 'next_payday' => $next, 'suggest' => $this->suggest($aid, $periods),
            'today' => AgencyTime::today($aid)]);
    }

    /** The next period after the last regular run (or ending last Saturday), as a starting point for the "New run" form. */
    private function suggest(int $aid, ?int $periods): array
    {
        $days = ['52' => 7, '26' => 14][(string) $periods] ?? 14;
        $last = DB::table('payroll_runs')->where('agency_id', $aid)->where('kind', 'regular')->where('status', '!=', 'discarded')->orderByDesc('period_end')->first();
        if ($last) {
            $s = \Carbon\Carbon::parse($last->period_end)->addDay();
        } else {
            $s = AgencyTime::now($aid)->startOfDay()->previous(\Carbon\Carbon::SATURDAY)->subDays($days - 1);
        }
        $e = $periods === 12 ? $s->copy()->endOfMonth() : ($periods === 24 ? ($s->day <= 15 ? $s->copy()->day(15) : $s->copy()->endOfMonth()) : $s->copy()->addDays($days - 1));

        return ['period_start' => $s->toDateString(), 'period_end' => $e->toDateString(), 'pay_date' => $e->copy()->addDays(6)->toDateString()];
    }

    /** POST /hr/payroll/runs: a new draft (regular, or an adjustment to a finalized run). */
    public function create(Request $request): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $d = $request->validate([
            'kind' => 'required|string|in:regular,adjustment', 'adjusts_run_id' => 'required_if:kind,adjustment|nullable|integer',
            'centre_id' => 'nullable|integer', 'period_start' => 'required_if:kind,regular|nullable|date_format:Y-m-d',
            'period_end' => 'required_if:kind,regular|nullable|date_format:Y-m-d', 'pay_date' => 'required|date_format:Y-m-d',
            'periods' => 'nullable|integer|in:52,26,24,12', 'notes' => 'nullable|string|max:1000',
        ]);
        $scope = CentreScope::ids($request, $aid);
        $centre = ! empty($d['centre_id']) ? (int) $d['centre_id'] : null;
        $orig = null;
        if ($d['kind'] === 'adjustment') {
            $orig = $this->run($request, $aid, (int) $d['adjusts_run_id']);
            abort_unless($orig->status === 'finalized' && $orig->kind === 'regular', 422, 'An adjustment corrects a finalized regular run.');
            $centre = $orig->centre_id ? (int) $orig->centre_id : null;
            $d['period_start'] = (string) $orig->period_start;
            $d['period_end'] = (string) $orig->period_end;
            $d['periods'] = (int) $orig->periods;
        }
        if ($centre !== null) {
            abort_unless(DB::table('centres')->where('id', $centre)->where('agency_id', $aid)->exists(), 422, 'That centre is not in this agency.');
        }
        abort_unless(CentreScope::canWrite($scope, $centre), 403, $centre === null ? 'Only an agency admin can run payroll for every centre.' : 'That centre is not one of yours.');
        abort_if($d['period_end'] < $d['period_start'], 422, 'The period ends before it starts.');
        abort_if($d['pay_date'] < $d['period_end'] && $d['kind'] === 'regular', 422, 'The pay date is before the end of the period.');
        $periods = (int) ($d['periods'] ?? 0) ?: (int) PayrollTax::agencyPeriods($aid);
        abort_unless($periods > 0, 422, 'Choose how many pay periods a year (or set the pay schedule in Payroll settings).');
        abort_unless(TaxTables::forDate('CA', $d['pay_date']), 422, 'No CRA payroll tables are loaded for ' . substr($d['pay_date'], 0, 4) . ' yet, so nothing can be worked out for that pay date.');
        $id = (int) DB::table('payroll_runs')->insertGetId(['agency_id' => $aid, 'centre_id' => $centre, 'country' => 'CA',
            'reference' => PayRuns::reference($aid, $d['pay_date']), 'kind' => $d['kind'], 'adjusts_run_id' => $orig ? (int) $orig->id : null,
            'period_start' => $d['period_start'], 'period_end' => $d['period_end'], 'pay_date' => $d['pay_date'], 'periods' => $periods,
            'status' => 'draft', 'notes' => $d['notes'] ?? null, 'created_by_id' => $this->me($request), 'created_at' => now(), 'updated_at' => now()]);
        $run = DB::table('payroll_runs')->where('id', $id)->first();
        PayRuns::build($run);
        $run = DB::table('payroll_runs')->where('id', $id)->first();
        PayRuns::note($aid, $this->me($request), 'payroll.run_created', $id, 'Started pay run ' . $run->reference . ($orig ? ' (adjustment to ' . $orig->reference . ')' : '')
            . ' for ' . PayRuns::periodText((string) $run->period_start, (string) $run->period_end) . ', pay date ' . $run->pay_date . '.');

        return response()->json($this->shapeFull($request, $aid, $run), 201);
    }

    /** GET /hr/payroll/runs/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);

        return response()->json($this->shapeFull($request, $aid, $this->run($request, $aid, $id)));
    }

    /** POST /hr/payroll/runs/{id}/refresh: read the clock again and recalculate (draft). */
    public function refresh(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_unless($r->status === 'draft', 422, 'Only a draft can be recalculated. Send it back to draft first.');
        PayRuns::build($r);

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** POST /hr/payroll/runs/{id}/people: add someone to an adjustment run. */
    public function addPerson(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_unless($r->status === 'draft' && $r->kind === 'adjustment', 422, 'People are added to an adjustment run while it is a draft.');
        $uid = (int) $request->validate(['user_id' => 'required|integer'])['user_id'];
        $scope = CentreScope::ids($request, $aid);
        abort_unless(in_array($uid, HrLeave::staffIds($aid, $scope), true), 404, 'Not found.');
        DB::table('payroll_run_lines')->insertOrIgnore(['run_id' => $id, 'agency_id' => $aid, 'user_id' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        PayRuns::build($r);

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** PUT /hr/payroll/runs/{id}/lines/{lineId}: what the preparer can change in a draft. */
    public function saveLine(Request $request, int $id, int $lineId): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_unless($r->status === 'draft', 422, 'Only a draft can be changed. Send it back to draft first.');
        $l = DB::table('payroll_run_lines')->where('id', $lineId)->where('run_id', $id)->first();
        abort_unless($l, 404, 'Not found.');
        abort_if((int) $l->user_id === $this->me($request) && ! $this->hrIsAdmin($request), 403, 'Another manager has to change your own pay.');
        $d = $request->validate(['included' => 'sometimes|boolean', 'bonus' => 'sometimes|numeric|min:0|max:1000000', 'benefits_cash' => 'sometimes|numeric|min:0|max:1000000',
            'benefits_noncash' => 'sometimes|numeric|min:0|max:1000000', 'other_deduction' => 'sometimes|numeric|min:0|max:1000000',
            'other_label' => 'sometimes|nullable|string|max:120', 'note' => 'sometimes|nullable|string|max:500']);
        abort_if((int) $l->user_id === $this->me($request) && $d, 403, 'Another admin has to change your own pay.');
        $upd = [];
        foreach (PayRuns::EDITABLE as $k) {
            if (array_key_exists($k, $d)) {
                $upd[$k] = in_array($k, ['other_label', 'note'], true) ? (trim((string) $d[$k]) ?: null) : ($k === 'included' ? (bool) $d[$k] : round((float) $d[$k], 2));
            }
        }
        if ($upd) {
            DB::table('payroll_run_lines')->where('id', $lineId)->update($upd + ['updated_at' => now()]);
            PayRuns::calcLine($lineId, $r);
            DB::table('payroll_runs')->where('id', $id)->update(['totals' => json_encode(PayRuns::totals($id) + ['skipped' => (json_decode((string) $r->totals, true)['skipped'] ?? [])]), 'updated_at' => now()]);
        }

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** POST /hr/payroll/runs/{id}/submit: draft -> review (recalculated first, refused while a line has a problem). */
    public function submit(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_unless($r->status === 'draft', 422, 'This run is not a draft.');
        $t = PayRuns::build($r);
        abort_if($t['errors'] > 0, 422, $t['errors'] . ' line(s) still have a problem. Fix them or leave those people out, then submit.');
        abort_if($t['people'] === 0, 422, 'Nobody is being paid in this run.');
        DB::table('payroll_runs')->where('id', $id)->update(['status' => 'review', 'submitted_by_id' => $this->me($request), 'submitted_at' => now(), 'return_reason' => null, 'updated_at' => now()]);
        PayRuns::note($aid, $this->me($request), 'payroll.run_submitted', $id, 'Sent pay run ' . $r->reference . ' for review: ' . $t['people'] . ' people, $' . number_format($t['net'], 2) . ' net.');

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** POST /hr/payroll/runs/{id}/return: review / approved -> draft, with a reason. */
    public function sendBack(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $r = $this->run($request, $aid, $id);
        abort_unless(in_array($r->status, ['review', 'approved'], true), 422, 'Only a run in review or approved can be sent back.');
        $why = trim((string) $request->validate(['reason' => 'nullable|string|max:500'])['reason'] ?? '');
        DB::table('payroll_runs')->where('id', $id)->update(['status' => 'draft', 'approved_by_id' => null, 'approved_at' => null, 'return_reason' => $why ?: null, 'updated_at' => now()]);
        PayRuns::note($aid, $this->me($request), 'payroll.run_returned', $id, 'Sent pay run ' . $r->reference . ' back to draft' . ($why ? ': ' . $why : '.'));

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** POST /hr/payroll/runs/{id}/approve: review -> approved, by someone the run does not pay. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_unless($r->status === 'review', 422, 'Only a run in review can be approved.');
        abort_if(in_array($this->me($request), PayRuns::paidIds($id), true), 403, 'This run pays you, so another manager has to approve it.');
        $n = DB::table('payroll_runs')->where('id', $id)->where('status', 'review')->update(['status' => 'approved', 'approved_by_id' => $this->me($request), 'approved_at' => now(), 'updated_at' => now()]);
        abort_unless($n === 1, 409, 'Someone else changed this run. Reload it.');
        PayRuns::note($aid, $this->me($request), 'payroll.run_approved', $id, 'Approved pay run ' . $r->reference . '.');

        return response()->json($this->shapeFull($request, $aid, DB::table('payroll_runs')->where('id', $id)->first()));
    }

    /** POST /hr/payroll/runs/{id}/finalize: approved -> finalized; writes the payslips. Never undone. */
    public function finalize(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $this->needAck($aid);
        $r = $this->run($request, $aid, $id);
        abort_if(in_array($this->me($request), PayRuns::paidIds($id), true), 403, 'This run pays you, so another manager has to finalize it.');
        $n = PayRuns::finalize($id, $this->me($request));
        $r = DB::table('payroll_runs')->where('id', $id)->first();
        $t = json_decode((string) $r->totals, true) ?: [];
        PayRuns::note($aid, $this->me($request), 'payroll.run_finalized', $id, 'Finalized pay run ' . $r->reference . ': ' . $n . ' payslip(s), $'
            . number_format((float) ($t['net'] ?? 0), 2) . ' net, $' . number_format((float) ($t['remit_cra'] ?? 0), 2) . ' to remit to CRA.');

        return response()->json($this->shapeFull($request, $aid, $r));
    }

    /** DELETE /hr/payroll/runs/{id}: discard a run that is not finalized (its lines go; the run row stays as discarded). */
    public function discard(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->needPay($request, $aid);
        $r = $this->run($request, $aid, $id);
        abort_unless(in_array($r->status, ['draft', 'review', 'approved'], true), 422, 'A finalized run is never deleted. Correct it with an adjustment run.');
        DB::transaction(function () use ($id) {
            DB::table('payroll_run_lines')->where('run_id', $id)->delete();
            DB::table('payroll_runs')->where('id', $id)->update(['status' => 'discarded', 'updated_at' => now()]);
        });
        PayRuns::note($aid, $this->me($request), 'payroll.run_discarded', $id, 'Discarded pay run ' . $r->reference . ' before it was finalized.');

        return response()->json(['ok' => true]);
    }

    // ---- Remittances -----------------------------------------------------------------------------------------------

    /** GET /hr/payroll/remittance?year=&type= */
    public function remittance(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        if (! PayrollTax::engineOn($aid)) {
            return response()->json(['enabled' => false]);
        }
        $this->needPay($request, $aid);
        $y = (int) ($request->query('year') ?: AgencyTime::now($aid)->format('Y'));

        return response()->json(['enabled' => true, 'agency_wide' => CentreScope::ids($request, $aid) === null]
            + Remittance::report($aid, $y, $request->query('type') ? (string) $request->query('type') : null, CentreScope::ids($request, $aid)));
    }

    // ---- Opening year-to-date balances (agency admins) --------------------------------------------------------------

    /** GET /hr/payroll/ytd-openings?year= */
    public function openings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        if (! PayrollTax::engineOn($aid)) {
            return response()->json(['enabled' => false]);
        }
        $this->hrNeedAdmin($request);
        $y = (int) ($request->query('year') ?: AgencyTime::now($aid)->format('Y'));
        $staff = HrLeave::staffIds($aid);
        $names = $this->hrNames($staff);
        $rows = DB::table('payroll_ytd_openings')->where('agency_id', $aid)->where('tax_year', $y)->get()->keyBy('user_id');
        $people = [];
        foreach ($staff as $uid) {
            $o = $rows[$uid] ?? null;
            $people[] = ['user_id' => $uid, 'name' => $names[$uid] ?? ('#' . $uid), 'own' => $uid === $this->me($request), 'opening' => $o ? [
                'id' => (int) $o->id, 'as_of' => (string) $o->as_of, 'gross' => (float) $o->gross, 'pensionable' => (float) $o->pensionable, 'insurable' => (float) $o->insurable,
                'cpp' => (float) $o->cpp, 'cpp2' => (float) $o->cpp2, 'ei' => (float) $o->ei, 'qpip' => (float) $o->qpip, 'income_tax' => (float) $o->income_tax,
                'bonus' => (float) $o->bonus, 'note' => $o->note, 'updated_at' => (string) $o->updated_at] : null];
        }
        usort($people, fn ($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return response()->json(['enabled' => true, 'year' => $y, 'people' => $people]);
    }

    private function openingRules(): array
    {
        return ['as_of' => 'required|date_format:Y-m-d', 'gross' => 'required|numeric|min:0|max:10000000', 'pensionable' => 'nullable|numeric|min:0|max:10000000',
            'insurable' => 'nullable|numeric|min:0|max:10000000', 'cpp' => 'nullable|numeric|min:0|max:100000', 'cpp2' => 'nullable|numeric|min:0|max:100000',
            'ei' => 'nullable|numeric|min:0|max:100000', 'qpip' => 'nullable|numeric|min:0|max:100000', 'income_tax' => 'nullable|numeric|min:0|max:10000000',
            'bonus' => 'nullable|numeric|min:0|max:10000000', 'note' => 'nullable|string|max:300'];
    }

    private function writeOpening(int $aid, int $uid, int $year, array $d, int $by): void
    {
        abort_if(substr($d['as_of'], 0, 4) !== (string) $year, 422, 'The as-of date has to be in ' . $year . '.');
        $row = ['as_of' => $d['as_of'], 'gross' => round((float) $d['gross'], 2), 'pensionable' => round((float) ($d['pensionable'] ?? $d['gross']), 2),
            'insurable' => round((float) ($d['insurable'] ?? $d['gross']), 2)];
        foreach (['cpp', 'cpp2', 'ei', 'qpip', 'income_tax', 'bonus'] as $k) {
            $row[$k] = round((float) ($d[$k] ?? 0), 2);
        }
        $row += ['note' => isset($d['note']) ? (trim((string) $d['note']) ?: null) : null, 'entered_by_id' => $by, 'updated_at' => now()];
        $was = DB::table('payroll_ytd_openings')->where('agency_id', $aid)->where('user_id', $uid)->where('tax_year', $year)->first();
        if ($was) {
            DB::table('payroll_ytd_openings')->where('id', $was->id)->update($row);
        } else {
            DB::table('payroll_ytd_openings')->insert($row + ['agency_id' => $aid, 'user_id' => $uid, 'tax_year' => $year, 'created_at' => now()]);
        }
    }

    /** PUT /hr/payroll/ytd-openings/{userId}: one person's opening balance for a year (never your own). */
    public function saveOpening(Request $request, int $userId): JsonResponse
    {
        $aid = $this->on($request);
        $this->hrNeedAdmin($request);
        abort_unless(in_array($userId, HrLeave::staffIds($aid), true), 404, 'Not found.');
        abort_if($userId === $this->me($request), 403, 'Another admin has to enter your own opening balance.');
        $d = $request->validate(['tax_year' => 'required|integer|min:2026|max:2100'] + $this->openingRules());
        $this->writeOpening($aid, $userId, (int) $d['tax_year'], $d, $this->me($request));
        $this->hrNote($aid, 'payroll.ytd_opening_saved', 'user', $userId, ['summary' => 'Entered an opening year-to-date balance for ' . ($this->hrNames([$userId])[$userId] ?? 'a staff member')
            . ' (' . $d['tax_year'] . ', paid elsewhere up to ' . $d['as_of'] . ').']);

        return $this->openings($request->merge(['year' => $d['tax_year']]));
    }

    /** DELETE /hr/payroll/ytd-openings/{id} */
    public function deleteOpening(Request $request, int $id): JsonResponse
    {
        $aid = $this->on($request);
        $this->hrNeedAdmin($request);
        $o = DB::table('payroll_ytd_openings')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($o, 404, 'Not found.');
        abort_if((int) $o->user_id === $this->me($request), 403, 'Another admin has to change your own opening balance.');
        DB::table('payroll_ytd_openings')->where('id', $id)->delete();
        $this->hrNote($aid, 'payroll.ytd_opening_removed', 'user', (int) $o->user_id, ['summary' => 'Removed the ' . $o->tax_year . ' opening year-to-date balance for '
            . ($this->hrNames([(int) $o->user_id])[(int) $o->user_id] ?? 'a staff member') . '.']);

        return $this->openings($request->merge(['year' => (int) $o->tax_year]));
    }

    /** POST /hr/payroll/ytd-openings/import: rows matched by email (or exact full name) to this agency's staff. */
    public function importOpenings(Request $request): JsonResponse
    {
        $aid = $this->on($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['tax_year' => 'required|integer|min:2026|max:2100', 'as_of' => 'required|date_format:Y-m-d',
            'rows' => 'required|array|min:1|max:500', 'rows.*.who' => 'required|string|max:190'] + array_combine(
                array_map(fn ($k) => 'rows.*.' . $k, array_keys(array_diff_key($this->openingRules(), ['as_of' => 1, 'note' => 1]))),
                array_values(array_diff_key($this->openingRules(), ['as_of' => 1, 'note' => 1]))));
        $staff = HrLeave::staffIds($aid);
        $us = DB::table('users')->whereIn('id', $staff ?: [0])->get(['id', 'email', 'first_name', 'last_name']);
        $byEmail = [];
        $byName = [];
        foreach ($us as $u) {
            if ($u->email) {
                $byEmail[mb_strtolower(trim((string) $u->email))] = (int) $u->id;
            }
            $byName[mb_strtolower(trim($u->first_name . ' ' . $u->last_name))][] = (int) $u->id;
        }
        $done = 0;
        $missed = [];
        foreach ($d['rows'] as $i => $row) {
            $w = mb_strtolower(trim((string) $row['who']));
            $uid = $byEmail[$w] ?? ((isset($byName[$w]) && count($byName[$w]) === 1) ? $byName[$w][0] : null);
            if (! $uid) {
                $missed[] = ['row' => $i + 1, 'who' => $row['who'], 'why' => isset($byName[$w]) ? 'more than one person has that name: use the email' : 'not found in your staff'];
                continue;
            }
            if ($uid === $this->me($request)) {
                $missed[] = ['row' => $i + 1, 'who' => $row['who'], 'why' => 'your own balance: another admin enters it'];
                continue;
            }
            $this->writeOpening($aid, $uid, (int) $d['tax_year'], $row + ['as_of' => $d['as_of']], $this->me($request));
            $done++;
        }
        if ($done) {
            $this->hrNote($aid, 'payroll.ytd_openings_imported', 'agency', $aid, ['summary' => 'Imported opening year-to-date balances for ' . $done . ' staff (' . $d['tax_year'] . ').']);
        }

        return response()->json(['imported' => $done, 'missed' => $missed]);
    }

    // ---- Payslips --------------------------------------------------------------------------------------------------

    /** GET /hr/payroll/payslips/{id}: an engine payslip, for someone who can see that pay. */
    public function payslip(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->needPay($request, $aid);
        $doc = DB::table('payroll_documents')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless(Payslip::isEngine($doc), 404, 'Not found.');
        $scope = CentreScope::ids($request, $aid);
        if ($scope !== null) {
            $runCentre = DB::table('payroll_runs')->where('id', $doc->payroll_run_id)->value('centre_id');
            abort_unless($runCentre && in_array((int) $runCentre, $scope, true), 404, 'Not found.');
        }

        return response()->json(Payslip::view($doc));
    }

    /** GET /hr/me/payslips: my own engine payslips (newest first), with this year's totals. */
    public function myPayslips(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = $this->me($request);
        $docs = DB::table('payroll_documents')->where('agency_id', $aid)->where('user_id', $me)->where('source', 'engine')->whereNotNull('breakdown')
            ->where('status', '!=', 'void')->orderByDesc('period_end')->limit(120)->get();
        if (! PayrollTax::engineOn($aid) && $docs->isEmpty()) {
            return response()->json(['enabled' => false]);
        }
        $list = $docs->map(function ($d) {
            $b = json_decode((string) $d->breakdown, true) ?: [];

            return ['id' => (int) $d->id, 'reference' => $d->reference, 'period_start' => (string) $d->period_start, 'period_end' => (string) $d->period_end,
                'pay_date' => $b['run']['pay_date'] ?? null, 'gross' => (float) $d->gross, 'net' => (float) $d->net, 'adjustment' => ($b['run']['kind'] ?? '') === 'adjustment',
                'ytd_gross' => (float) ($b['ytd']['gross'] ?? 0), 'ytd_net' => (float) ($b['ytd']['net'] ?? 0)];
        })->values();

        return response()->json(['enabled' => true, 'payslips' => $list, 'engine_on' => PayrollTax::engineOn($aid)]);
    }

    /** GET /hr/me/payslips/{id}: one of my own. */
    public function myPayslip(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $doc = DB::table('payroll_documents')->where('id', $id)->where('agency_id', $aid)->where('user_id', $this->me($request))->first();
        abort_unless(Payslip::isEngine($doc), 404, 'Not found.');

        return response()->json(Payslip::view($doc));
    }
}
