<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrLeaving;
use App\Support\HrReports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR PHASE 4, BATCH 5 (2026-11-27): the HR Dashboard (setup checklist + to-dos), the Reports pack,
 * probation and the missing-info check (App\Support\HrReports). Directors and admins only (route group), always the
 * ACTIVE agency, directors their own centres (CentreScope), fail closed. Money (leave liability, pay below the
 * minimum wage, missing pay details) only for callers who may see pay (managers with payroll on, as HrController).
 */
final class HrReportsController extends Controller
{
    use HrAccess;

    private const KINDS = ['headcount', 'movement', 'retention', 'tenure', 'liability', 'overtime', 'lateness', 'schedule', 'leave', 'absence', 'missing', 'invites', 'probation'];

    private function ctx(Request $request): array
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);

        return [$aid, CentreScope::ids($request, $aid), AgencyTime::today($aid)];
    }

    private function canSeePay(Request $request, int $aid): bool
    {
        return $this->hrIsManager($request) && Features::on($aid, 'payroll');
    }

    private function range(Request $request, string $today, int $maxDays = 731): array
    {
        $from = (string) $request->query('from', Carbon::parse($today)->subYear()->addDay()->toDateString());
        $to = (string) $request->query('to', $today);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to, 422, 'Choose a valid date range.');
        abort_if(Carbon::parse($from)->diffInDays(Carbon::parse($to)) > $maxDays, 422, $maxDays > 400 ? 'Choose two years or less.' : 'Choose ' . $maxDays . ' days or less.');

        return [$from, $to];
    }

    /** GET /hr/dashboard — the setup checklist, probation coming up, missing info and sign-in counts. */
    public function dashboard(Request $request): JsonResponse
    {
        [$aid, $scope, $today] = $this->ctx($request);
        $pay = $this->canSeePay($request, $aid);
        $prob = HrReports::probation($aid, $scope, $today);
        $miss = HrReports::missing($aid, $scope, $pay, $this->hrIsAdmin($request), $today);
        $inv = HrReports::invites($aid, $scope, $today);

        return response()->json(['checklist' => HrReports::checklist($aid, $scope, $pay, $this->hrIsAdmin($request), $today),
            'probation' => array_values(array_filter($prob['rows'], fn ($r) => in_array($r['state'], ['overdue', 'due'], true))),
            'missing' => ['people' => $miss['people'], 'complete' => $miss['complete'], 'counts' => $miss['counts']],
            'invites' => ['not_signed_in' => count($inv['rows']), 'expired' => $inv['expired'], 'people' => $inv['people']],
            'can' => ['admin' => $this->hrIsAdmin($request), 'pay' => $pay], 'today' => $today]);
    }

    /** GET /hr/reports/{kind} — one report, scoped. */
    public function report(Request $request, string $kind): JsonResponse
    {
        abort_unless(in_array($kind, self::KINDS, true), 404, 'That report was not found.');
        [$aid, $scope, $today] = $this->ctx($request);
        $pay = $this->canSeePay($request, $aid);
        $admin = $this->hrIsAdmin($request);
        $d = match ($kind) {
            'headcount' => HrReports::headcount($aid, $scope),
            'movement' => HrReports::movement($aid, $scope, ...$this->range($request, $today)),
            'retention' => HrReports::retention($aid, $scope, $today),
            'tenure' => HrReports::tenure($aid, $scope, $today),
            'liability' => $pay ? HrReports::liability($aid, $scope) : abort(403, 'Leave liability shows pay: only people who can see pay can open it.'),
            'overtime' => HrReports::overtime($aid, $scope, ...$this->range($request, $today)),
            'lateness' => HrReports::lateness($aid, $scope, ...$this->range($request, $today)),
            'schedule' => HrReports::schedule($aid, $scope, ...$this->range($request, $today, 93)),
            'leave' => HrReports::leaveSummary($aid, $scope, $today),
            'absence' => HrReports::teamAbsence($aid, $scope, ...$this->range($request, $today, 366)),
            'missing' => HrReports::missing($aid, $scope, $pay, $admin, $today),
            'invites' => HrReports::invites($aid, $scope, $today),
            'probation' => HrReports::probation($aid, $scope, $today, $request->boolean('all')),
        };
        if (in_array($kind, ['movement', 'overtime', 'lateness', 'schedule', 'absence'], true)) {
            [$d['from'], $d['to']] = $this->range($request, $today, $kind === 'schedule' ? 93 : ($kind === 'absence' ? 366 : 731));
        }

        return response()->json(['kind' => $kind, 'today' => $today, 'can' => ['pay' => $pay, 'admin' => $admin]] + $d);
    }

    /** GET /hr/people/{id}/holdings — everything we hold about one person (counts). Current staff and leavers in scope. */
    public function holdings(Request $request, int $id): JsonResponse
    {
        [$aid, $scope] = $this->ctx($request);
        abort_unless(in_array($id, array_merge(HrLeave::staffIds($aid, $scope), HrLeaving::leaverIds($aid, $scope)), true), 404, 'That person was not found.');
        $u = DB::table('users')->where('id', $id)->first(['first_name', 'last_name']);

        return response()->json(['name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
            'can_export' => $this->hrIsAdmin($request)] + HrReports::holdings($aid, $id, $this->canSeePay($request, $aid), $this->hrIsAdmin($request)));
    }

    /** POST /hr/people/{id}/probation — passed | extend (new end date) | reset (back to the agency length). Never your own. */
    public function probation(Request $request, int $id): JsonResponse
    {
        [$aid] = $this->ctx($request);
        $this->hrNeedPerson($request, $aid, $id);
        abort_if($id === (int) $request->user()->id, 403, 'Someone else confirms your own probation.');
        $d = $request->validate(['action' => 'required|in:passed,extend,reset', 'end_on' => 'required_if:action,extend|nullable|date_format:Y-m-d',
            'passed_on' => 'nullable|date_format:Y-m-d', 'note' => 'nullable|string|max:255']);
        $today = AgencyTime::today($aid);
        $vals = match ($d['action']) {
            'passed' => ['probation_passed_on' => $d['passed_on'] ?? $today],
            'extend' => ['probation_end_on' => $d['end_on'], 'probation_passed_on' => null],
            'reset' => ['probation_end_on' => null, 'probation_passed_on' => null],
        };
        $file = HrLeave::file($aid, $id);
        if ($file) {
            DB::table('hr_employee_files')->where('id', $file->id)->update($vals + ['updated_by_id' => $request->user()->id, 'updated_at' => now()]);
        } else {
            DB::table('hr_employee_files')->insert($vals + ['agency_id' => $aid, 'user_id' => $id, 'status' => 'active', 'updated_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        try {
            Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'hr.probation_' . $d['action'], 'entity_type' => 'user', 'entity_id' => $id,
                'payload' => json_encode(array_filter(['end_on' => $d['end_on'] ?? null, 'passed_on' => $vals['probation_passed_on'] ?? null, 'note' => $d['note'] ?? null]))]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true]);
    }

    /** GET /hr/compliance/settings  ·  PUT (agency admins): probation length, reminder days, invite expiry. */
    public function settings(Request $request): JsonResponse
    {
        [$aid] = $this->ctx($request);

        return response()->json(['settings' => HrReports::settings($aid), 'min_wages' => HrReports::minWageTable(), 'can_manage' => $this->hrIsAdmin($request)]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        [$aid] = $this->ctx($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['probation_days' => 'required|integer|min:0|max:730', 'remind_days' => 'required|integer|min:0|max:90', 'invite_days' => 'required|integer|min:0|max:365']);
        $out = HrReports::saveSettings($aid, $d);
        try {
            Audit::write(['user_id' => $request->user()->id, 'agency_id' => $aid, 'action' => 'hr.compliance_settings_updated', 'entity_type' => 'agency', 'entity_id' => $aid, 'payload' => json_encode($out)]);
        } catch (\Throwable $e) {
        }

        return response()->json(['settings' => $out]);
    }
}
