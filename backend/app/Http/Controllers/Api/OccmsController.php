<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Live;
use App\Support\OccmsRoa;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * OCCMS MONTHLY REPORTING (2026-10-13) — the fee subsidy Record of Attendance workspace and
 * the CWELCC monthly summary, for Ontario operators who enter both in OCCMS by hand (there is
 * no OCCMS API). See App\Support\OccmsRoa for how each day's code is worked out.
 *
 * Who: agency admins and platform admins (every open centre of the agency) and centre
 * directors (their own centres). Educators never — this is money. Ontario agencies only:
 * everything here answers 404 for any other jurisdiction.
 *
 * Workflow per centre and month: draft → reviewed (person 1) → signed off (person 2, never the
 * reviewer) → submitted (the date it was entered in OCCMS). Editing a reviewed month sends it
 * back to draft; a signed-off month is reopened with a reason; a submitted month is locked and
 * amended with a reason. Every cell change and step is in roa_events and the audit log.
 */
final class OccmsController extends Controller
{
    /** [agencyId, centres [id => name], isWide] — fails closed. */
    private function ctx(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->get(['role', 'agency_id', 'centre_id']);
        $isPlatform = $roles->contains(fn ($r) => $r->role === 'platform_admin');
        if ($isPlatform) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            $agencyId = $active;
        } else {
            $centreAgency = fn ($cid) => (int) DB::table('centres')->where('id', $cid)->value('agency_id');
            $mine = [];
            foreach ($roles as $r) {
                if ($r->role === 'agency_admin' && $r->agency_id) $mine[(int) $r->agency_id] = true;
                if ($r->role === 'centre_director' && $r->centre_id) $mine[$centreAgency($r->centre_id)] = true;
            }
            unset($mine[0]);
            $agencyId = ($active && isset($mine[$active])) ? $active : (int) (array_key_first($mine) ?? 0);
            abort_unless($agencyId, 403, 'OCCMS reporting is for directors and agency admins.');
        }
        abort_unless(OccmsRoa::applies($agencyId), 404, 'OCCMS reporting is for Ontario agencies.');

        $wide = $isPlatform || $roles->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->agency_id === $agencyId);
        $open = Live::centreIds($agencyId);
        $ids = $wide ? $open : array_values(array_intersect($open, $roles->where('role', 'centre_director')->pluck('centre_id')->filter()->map(fn ($v) => (int) $v)->all()));
        abort_unless($ids, 403, 'OCCMS reporting is for directors and agency admins.');
        $centres = DB::table('centres')->whereIn('id', $ids)->orderBy('name')->pluck('name', 'id')->all();

        return [$agencyId, $centres, $wide];
    }

    private function month(Request $request, int $agencyId): string
    {
        $m = (string) $request->input('month', '');
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m)) {
            // Default: last month — the one whose ROA is due.
            $m = Carbon::parse(AgencyTime::today($agencyId))->startOfMonth()->subMonthNoOverflow()->format('Y-m');
        }
        abort_if($m > Carbon::parse(AgencyTime::today($agencyId))->format('Y-m'), 422, 'That month has not started yet.');

        return $m;
    }

    private function centre(Request $request, array $centres): int
    {
        $cid = (int) $request->input('centre_id');
        if (! $cid) $cid = (int) array_key_first($centres);
        abort_unless(isset($centres[$cid]), 403, 'That centre is not one of yours.');

        return $cid;
    }

    /** GET /occms/status — for the menu: is OCCMS reporting on for this person? Never errors. */
    public function status(Request $request): JsonResponse
    {
        try {
            [$agencyId] = $this->ctx($request);

            return response()->json(['on' => true, 'agency_id' => $agencyId]);
        } catch (\Throwable $e) {
            return response()->json(['on' => false]);
        }
    }

    /** GET /occms/roa?centre_id=&month=YYYY-MM */
    public function show(Request $request): JsonResponse
    {
        [$agencyId, $centres, $wide] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $b = OccmsRoa::build($agencyId, $cid, $month);
        $set = $b['settings'];
        $roa = $b['roa'];
        $tz = $b['tz'];
        $names = $this->names(array_filter([$roa->reviewed_by_id ?? null, $roa->signed_off_by_id ?? null, $roa->submitted_by_id ?? null]));
        $fmt = fn ($ts) => $ts ? Carbon::parse($ts, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : null;
        $events = DB::table('roa_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')->leftJoin('children as ch', 'ch.id', '=', 'e.child_id')
            ->where('e.centre_id', $cid)->where('e.month', $month)->orderByDesc('e.id')->limit(80)
            ->get(['e.action', 'e.day', 'e.old_code', 'e.new_code', 'e.note', 'e.created_at', 'u.first_name', 'u.last_name', 'ch.first_name as cf', 'ch.last_name as cl'])
            ->map(fn ($e) => ['action' => $e->action, 'day' => $e->day, 'old' => $e->old_code, 'new' => $e->new_code, 'note' => $e->note,
                'child' => $e->cf ? trim($e->cf . ' ' . $e->cl) : null, 'by' => trim(($e->first_name ?? '') . ' ' . ($e->last_name ?? '')), 'at' => $fmt($e->created_at)]);
        $status = $roa->status ?? 'draft';
        $monthOver = Carbon::createFromFormat('Y-m-d', $month . '-01')->endOfMonth()->toDateString() < $b['today'];
        $centre = DB::table('centres')->where('id', $cid)->first(['id', 'name', 'license_number', 'license_capacity']);

        return response()->json([
            'month' => $month, 'today' => $b['today'], 'timezone' => $tz, 'month_over' => $monthOver,
            'centre' => ['id' => $cid, 'name' => $centre->name, 'license_number' => $centre->license_number, 'license_capacity' => (int) $centre->license_capacity],
            'centres' => collect($centres)->map(fn ($n, $i) => ['id' => $i, 'name' => $n])->values(),
            'settings' => $set, 'presets' => collect(OccmsRoa::PRESETS)->map(fn ($p, $k) => ['key' => $k] + $p)->values(),
            'codes' => OccmsRoa::CODES, 'absence_codes' => OccmsRoa::ABSENCE_CODES, 'age_groups' => OccmsRoa::AGE_GROUPS,
            'due_on' => OccmsRoa::dueOn($month, $set['due_day']),
            'days' => $b['days'], 'children' => $b['children'], 'capacity' => $b['capacity'],
            'roa' => [
                'status' => $status, 'locked' => in_array($status, ['signed_off', 'submitted'], true), 'frozen' => $b['frozen'], 'live_diff' => $b['live_diff'],
                'reviewed_by' => $roa && $roa->reviewed_by_id ? ($names[(int) $roa->reviewed_by_id] ?? '') : null, 'reviewed_by_id' => $roa->reviewed_by_id ?? null, 'reviewed_at' => $fmt($roa->reviewed_at ?? null),
                'signed_off_by' => $roa && $roa->signed_off_by_id ? ($names[(int) $roa->signed_off_by_id] ?? '') : null, 'signed_off_at' => $fmt($roa->signed_off_at ?? null),
                'submitted_by' => $roa && $roa->submitted_by_id ? ($names[(int) $roa->submitted_by_id] ?? '') : null, 'submitted_at' => $fmt($roa->submitted_at ?? null),
                'occms_entered_on' => $roa->occms_entered_on ?? null, 'occms_reference' => $roa->occms_reference ?? null, 'amendments' => (int) ($roa->amendments ?? 0),
            ],
            'events' => $events,
            'me' => (int) $request->user()->id,
            'can_settings' => $wide,
        ]);
    }

    /** PATCH /occms/roa/cell {centre_id, month, child_id, day, code, note?} */
    public function cell(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $d = $request->validate([
            'child_id' => ['required', 'integer'],
            'day' => ['required', 'date_format:Y-m-d'],
            'code' => ['present', 'nullable', 'string', 'in:' . implode(',', array_keys(OccmsRoa::CODES)) . ','],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $code = (string) ($d['code'] ?? '');
        abort_unless(substr($d['day'], 0, 7) === $month, 422, 'That day is not in this month.');
        abort_if($d['day'] > AgencyTime::today($agencyId), 422, 'A day that has not happened yet cannot be recorded.');
        $set = OccmsRoa::settings($agencyId);
        $kids = OccmsRoa::liveChildren($agencyId, $cid, $month, $set, AgencyTime::today($agencyId));
        $kid = collect($kids)->firstWhere('child_id', (int) $d['child_id']);
        abort_unless($kid, 404, 'That child has no fee subsidy at this centre this month.');
        $i = (int) substr($d['day'], 8, 2) - 1;
        abort_unless(! empty($kid['sub'][$i]), 422, 'The child had no fee subsidy here that day.');
        $old = (string) ($kid['codes'][$i] ?? '');
        $uid = (int) $request->user()->id;

        $result = DB::transaction(function () use ($agencyId, $cid, $month, $d, $code, $old, $uid) {
            $roa = $this->lockRow($agencyId, $cid, $month);
            if (in_array($roa->status, ['signed_off', 'submitted'], true)) {
                abort(423, $roa->status === 'submitted'
                    ? 'This month was submitted to OCCMS and is locked. Use Amend (with a reason) to change it.'
                    : 'This month is signed off. Reopen it (with a reason) to change a day.');
            }
            DB::table('roa_cells')->updateOrInsert(['centre_id' => $cid, 'child_id' => (int) $d['child_id'], 'day' => $d['day']],
                ['agency_id' => $agencyId, 'code' => $code, 'note' => $d['note'] ?? null, 'set_by_id' => $uid, 'updated_at' => now(), 'created_at' => now()]);
            $this->event($agencyId, $cid, $month, 'cell', $uid, (int) $d['child_id'], $d['day'], $old, $code, $d['note'] ?? null);
            $cleared = false;
            if ($roa->status === 'reviewed') {   // a review covers the grid as it was; a change needs a fresh one
                DB::table('roa_months')->where('id', $roa->id)->update(['status' => 'draft', 'reviewed_by_id' => null, 'reviewed_at' => null, 'updated_at' => now()]);
                $this->event($agencyId, $cid, $month, 'review_cleared', $uid, null, null, null, null, 'A day was changed after review');
                $cleared = true;
            }

            return $cleared;
        });
        $this->audit($request, $agencyId, 'occms.roa_cell', $cid, ($kid['name'] ?? 'child') . ' ' . $d['day'] . ': ' . ($old ?: '—') . ' → ' . ($code ?: '—') . ' (' . $centres[$cid] . ', ' . $month . ')');

        $fresh = collect(OccmsRoa::liveChildren($agencyId, $cid, $month, $set, AgencyTime::today($agencyId)))->firstWhere('child_id', (int) $d['child_id']);

        return response()->json(['ok' => true, 'child' => $fresh, 'review_cleared' => $result]);
    }

    /** POST /occms/roa/capacity {centre_id, month, operating: {age_group: n}} */
    public function capacity(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $d = $request->validate(['operating' => ['required', 'array'], 'operating.*' => ['nullable', 'integer', 'min:0', 'max:999']]);
        $op = [];
        foreach ($d['operating'] as $g => $n) {
            if (isset(OccmsRoa::AGE_GROUPS[$g]) && $n !== null && $n !== '') $op[$g] = (int) $n;
        }
        $licensed = DB::table('rooms')->where('centre_id', $cid)->where('active', 1)->selectRaw('age_group, SUM(capacity) as cap')->groupBy('age_group')->pluck('cap', 'age_group')->all();
        foreach ($op as $g => $n) {
            if (isset($licensed[$g]) && $n > (int) $licensed[$g]) {
                return response()->json(['message' => 'Operating capacity cannot be more than the licensed capacity (' . OccmsRoa::AGE_GROUPS[$g] . ': ' . (int) $licensed[$g] . ').'], 422);
            }
        }
        $uid = (int) $request->user()->id;
        DB::transaction(function () use ($agencyId, $cid, $month, $op, $uid) {
            $roa = $this->lockRow($agencyId, $cid, $month);
            abort_if(in_array($roa->status, ['signed_off', 'submitted'], true), 423, 'This month is locked. Reopen or amend it to change the capacity.');
            DB::table('roa_months')->where('id', $roa->id)->update(['capacity' => json_encode($op), 'updated_at' => now()]);
            $this->event($agencyId, $cid, $month, 'capacity', $uid, null, null, null, null,
                'Operating capacity: ' . implode(', ', array_map(fn ($g, $n) => OccmsRoa::AGE_GROUPS[$g] . ' ' . $n, array_keys($op), $op)));
        });
        $this->audit($request, $agencyId, 'occms.roa_capacity', $cid, 'Operating capacity set for ' . $centres[$cid] . ', ' . $month);

        return response()->json(['ok' => true]);
    }

    /** POST /occms/roa/status {centre_id, month, action: review|signoff|submit|reopen|amend, occms_date?, reference?, reason?} */
    public function transition(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $d = $request->validate([
            'action' => ['required', 'in:review,signoff,submit,reopen,amend'],
            'occms_date' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $today = AgencyTime::today($agencyId);
        $monthEnd = Carbon::createFromFormat('Y-m-d', $month . '-01')->endOfMonth()->toDateString();
        $uid = (int) $request->user()->id;
        $a = $d['action'];
        if (in_array($a, ['review', 'signoff', 'submit'], true)) abort_unless($monthEnd < $today, 422, 'The month is not over yet. Review and sign-off open on the 1st of next month.');
        if (in_array($a, ['reopen', 'amend'], true)) abort_unless(trim((string) ($d['reason'] ?? '')) !== '', 422, 'Give a reason.');
        if ($a === 'submit') {
            abort_unless(! empty($d['occms_date']), 422, 'Enter the date it was entered in OCCMS.');
            abort_if($d['occms_date'] > $today, 422, 'The OCCMS date cannot be in the future.');
            abort_if($d['occms_date'] <= $monthEnd, 422, 'The ROA is entered in OCCMS after the month ends.');
        }

        $summary = DB::transaction(function () use ($agencyId, $cid, $month, $d, $a, $uid) {
            $roa = $this->lockRow($agencyId, $cid, $month);
            $st = $roa->status;
            $upd = ['updated_at' => now()];
            $note = null;
            switch ($a) {
                case 'review':
                    abort_unless($st === 'draft', 422, 'Only a draft can be marked reviewed.');
                    $upd += ['status' => 'reviewed', 'reviewed_by_id' => $uid, 'reviewed_at' => now()];
                    $msg = 'Reviewed';
                    break;
                case 'signoff':
                    abort_unless($st === 'reviewed', 422, 'Mark it reviewed first.');
                    abort_if((int) $roa->reviewed_by_id === $uid, 422, 'A different person must sign off. You reviewed this month.');
                    $b = OccmsRoa::build($agencyId, $cid, $month);
                    $upd += ['status' => 'signed_off', 'signed_off_by_id' => $uid, 'signed_off_at' => now(),
                        'snapshot' => json_encode(['children' => $b['children'], 'capacity' => $b['capacity'], 'frozen_at' => now()->toIso8601String()])];
                    $msg = 'Signed off';
                    break;
                case 'submit':
                    abort_unless($st === 'signed_off', 422, 'Sign it off before recording the OCCMS submission.');
                    $upd += ['status' => 'submitted', 'submitted_by_id' => $uid, 'submitted_at' => now(), 'occms_entered_on' => $d['occms_date'], 'occms_reference' => $d['reference'] ?? null];
                    $note = 'Entered in OCCMS on ' . $d['occms_date'] . (! empty($d['reference']) ? ' (ref ' . $d['reference'] . ')' : '');
                    $msg = 'Submitted';
                    break;
                case 'reopen':
                    abort_unless(in_array($st, ['reviewed', 'signed_off'], true), 422, $st === 'submitted' ? 'A submitted month is amended, not reopened.' : 'This month is already open.');
                    $upd += ['status' => 'draft', 'reviewed_by_id' => null, 'reviewed_at' => null, 'signed_off_by_id' => null, 'signed_off_at' => null, 'snapshot' => null];
                    $note = trim((string) $d['reason']);
                    $msg = 'Reopened';
                    break;
                default:   // amend
                    abort_unless($st === 'submitted', 422, 'Only a submitted month is amended.');
                    $upd += ['status' => 'draft', 'reviewed_by_id' => null, 'reviewed_at' => null, 'signed_off_by_id' => null, 'signed_off_at' => null, 'snapshot' => null,
                        'submitted_by_id' => null, 'submitted_at' => null, 'occms_entered_on' => null, 'occms_reference' => null, 'amendments' => (int) $roa->amendments + 1];
                    $note = trim((string) $d['reason']) . ' (was entered in OCCMS on ' . $roa->occms_entered_on . ')';
                    $msg = 'Amendment started';
            }
            DB::table('roa_months')->where('id', $roa->id)->update($upd);
            $this->event($agencyId, $cid, $month, $a, $uid, null, null, null, null, $note);

            return $msg . ($note ? ': ' . $note : '');
        });
        $this->audit($request, $agencyId, 'occms.roa_' . $a, $cid, 'Record of Attendance ' . $centres[$cid] . ' ' . $month . ' — ' . $summary);

        return response()->json(['ok' => true]);
    }

    /** GET /occms/roa/csv?centre_id=&month= */
    public function csv(Request $request): StreamedResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $b = OccmsRoa::build($agencyId, $cid, $month);
        $info = $this->header($agencyId, $cid, $month, $b);
        $this->audit($request, $agencyId, 'occms.roa_export', $cid, 'Record of Attendance CSV exported: ' . $centres[$cid] . ', ' . $month);

        $cref = \App\Support\RefCode::children(array_column($b['children'] ?? [], 'child_id'));   // KiddieTrac child IDs, 2026-10-14
        return response()->streamDownload(function () use ($b, $info, $cref) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            foreach ($info as $k => $v) fputcsv($h, [$k, $v]);
            fputcsv($h, []);
            $head = ['Child', 'Child ID', 'Case / subsidy number', 'Date of birth', 'Age group'];
            foreach ($b['days'] as $day) $head[] = (string) $day['d'];
            $head = array_merge($head, array_keys(OccmsRoa::CODES), ['Absences this month', 'Absences year to date', 'Allowance', 'Remaining']);
            fputcsv($h, $head);
            foreach ($b['children'] as $c) {
                $row = [$c['name'], $cref[(int) ($c['child_id'] ?? 0)] ?? '', $c['case_number'], $c['dob'], OccmsRoa::AGE_GROUPS[$c['age_group']] ?? ''];
                foreach ($c['codes'] as $code) $row[] = $code ?? '';
                foreach (array_keys(OccmsRoa::CODES) as $k) $row[] = $c['totals'][$k] ?? 0;
                $row = array_merge($row, [$c['absences_month'], $c['ytd_used'], $c['allowance'] ?? '', $c['remaining'] ?? '']);
                fputcsv($h, $row);
            }
            fputcsv($h, []);
            fputcsv($h, ['Capacity as of ' . $b['capacity']['as_of']]);
            fputcsv($h, ['Age group', 'Licensed capacity', 'Operating capacity', 'Enrolled', 'Fee subsidy', 'Vacancies']);
            foreach ($b['capacity']['rows'] as $r) fputcsv($h, [$r['label'], $r['licensed'], $r['operating'], $r['enrolled'], $r['subsidized'], $r['vacancies']]);
            fputcsv($h, []);
            fputcsv($h, ['Codes']);
            foreach (OccmsRoa::CODES as $k => $v) fputcsv($h, [$k, $v]);
            fclose($h);
        }, $this->fileName($centres[$cid], $month, 'csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** GET /occms/roa/pdf?centre_id=&month= — printable, laid out like the OCCMS ROA. */
    public function pdf(Request $request): Response
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $b = OccmsRoa::build($agencyId, $cid, $month);
        $info = $this->header($agencyId, $cid, $month, $b);
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $n = count($b['days']);

        $html = '<html><head><meta charset="utf-8"><style>'
            . '@page{margin:22px 20px;}body{font-family:DejaVu Sans,sans-serif;font-size:8px;color:#0F172A;}'
            . 'h1{font-size:14px;margin:0 0 2px;}table{border-collapse:collapse;width:100%;}'
            . 'th,td{border:1px solid #94A3B8;padding:2px 2px;text-align:center;}th{background:#E2E8F0;font-size:7px;}'
            . 'td.l,th.l{text-align:left;}.we{background:#F1F5F9;}.info td{border:0;text-align:left;padding:1px 6px 1px 0;font-size:8.5px;}'
            . '.A{color:#B91C1C;font-weight:bold;}.S{color:#B45309;font-weight:bold;}.V{color:#1D4ED8;font-weight:bold;}.H{color:#6D28D9;}.N{color:#475569;}.W{font-weight:bold;}'
            . '</style></head><body>';
        $html .= '<h1>Record of Attendance — Child Care Fee Subsidy</h1><table class="info"><tr>';
        $i = 0;
        foreach ($info as $k => $v) {
            $html .= '<td><b>' . $e($k) . ':</b> ' . $e($v) . '</td>';
            if (++$i % 4 === 0) $html .= '</tr><tr>';
        }
        $html .= '</tr></table><br>';
        $html .= '<table><thead><tr><th class="l" style="width:110px">Child</th><th style="width:52px">Case #</th><th style="width:58px">DOB</th><th style="width:34px">Age group</th>';
        foreach ($b['days'] as $day) $html .= '<th class="' . ($day['operating'] ? '' : 'we') . '">' . $day['d'] . '<br>' . $e(substr($day['dow'], 0, 1)) . '</th>';
        foreach (array_keys(OccmsRoa::CODES) as $k) $html .= '<th>' . $k . '</th>';
        $html .= '<th>YTD abs.</th><th>Left</th></tr></thead><tbody>';
        $refs = \App\Support\RefCode::children(array_map(fn ($x) => (int) ($x['child_id'] ?? 0), $b['children']));
        foreach ($b['children'] as $c) {
            $html .= '<tr><td class="l">' . $e($c['name'])
                . (! empty($refs[(int) ($c['child_id'] ?? 0)]) ? '<br><span style="font-size:7px;color:#64748B;">' . $e($refs[(int) $c['child_id']]) . '</span>' : '')
                . '</td><td>' . $e($c['case_number']) . '</td><td>' . $e($c['dob']) . '</td><td>' . $e(OccmsRoa::AGE_GROUPS[$c['age_group']] ?? '') . '</td>';
            foreach ($c['codes'] as $j => $code) $html .= '<td class="' . ($b['days'][$j]['operating'] ? '' : 'we ') . $e($code ?? '') . '">' . $e($code ?? '') . '</td>';
            foreach (array_keys(OccmsRoa::CODES) as $k) $html .= '<td>' . (int) ($c['totals'][$k] ?? 0) . '</td>';
            $html .= '<td>' . (int) $c['ytd_used'] . ($c['allowance'] !== null ? '/' . (int) $c['allowance'] : '') . '</td><td>' . ($c['remaining'] ?? '') . '</td></tr>';
        }
        if (! $b['children']) $html .= '<tr><td colspan="' . ($n + 14) . '">No children with a fee subsidy at this centre this month.</td></tr>';
        $html .= '</tbody></table><br>';
        $html .= '<table style="width:60%"><thead><tr><th class="l">Age group</th><th>Licensed capacity</th><th>Operating capacity</th><th>Enrolled</th><th>Fee subsidy</th><th>Vacancies</th></tr></thead><tbody>';
        foreach ($b['capacity']['rows'] as $r) $html .= '<tr><td class="l">' . $e($r['label']) . '</td><td>' . $r['licensed'] . '</td><td>' . $r['operating'] . '</td><td>' . $r['enrolled'] . '</td><td>' . $r['subsidized'] . '</td><td>' . $r['vacancies'] . '</td></tr>';
        $html .= '</tbody></table><div style="margin-top:3px;color:#475569">Enrolment and capacity as of ' . $e($b['capacity']['as_of']) . '.</div><br>';
        $legend = [];
        foreach (OccmsRoa::CODES as $k => $v) $legend[] = '<b>' . $k . '</b> ' . $e($v);
        $html .= '<div><b>Codes:</b> ' . implode(' &nbsp; ', $legend) . '. A blank day is no care that day (weekend, not scheduled, or no subsidy).</div><br>';
        $roa = $b['roa'];
        $names = $this->names(array_filter([$roa->reviewed_by_id ?? null, $roa->signed_off_by_id ?? null]));
        $tz = $b['tz'];
        $fmt = fn ($ts) => $ts ? Carbon::parse($ts, 'UTC')->setTimezone($tz)->format('M j, Y g:i A') : '';
        $html .= '<table style="width:80%"><tr><td class="l" style="height:26px;width:33%"><b>Reviewed by:</b> ' . $e($roa && $roa->reviewed_by_id ? ($names[(int) $roa->reviewed_by_id] ?? '') : '') . '<br>' . $e($fmt($roa->reviewed_at ?? null)) . '</td>'
            . '<td class="l" style="width:33%"><b>Authorized (signed off) by:</b> ' . $e($roa && $roa->signed_off_by_id ? ($names[(int) $roa->signed_off_by_id] ?? '') : '') . '<br>' . $e($fmt($roa->signed_off_at ?? null)) . '</td>'
            . '<td class="l"><b>Entered in OCCMS on:</b> ' . $e($roa->occms_entered_on ?? '') . ($roa && $roa->occms_reference ? '<br>Ref ' . $e($roa->occms_reference) : '') . '</td></tr></table>';
        $html .= '</body></html>';

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('legal', 'landscape');
        $dompdf->render();
        $this->audit($request, $agencyId, 'occms.roa_export', $cid, 'Record of Attendance PDF exported: ' . $centres[$cid] . ', ' . $month);

        return new Response($dompdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $this->fileName($centres[$cid], $month, 'pdf') . '"']);
    }

    /** GET /occms/cwelcc?centre_id=&month= */
    public function cwelcc(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);

        return response()->json(['month' => $month, 'centre' => ['id' => $cid, 'name' => $centres[$cid]]] + OccmsRoa::cwelcc($agencyId, $cid, $month));
    }

    /** GET /occms/cwelcc/csv?centre_id=&month= */
    public function cwelccCsv(Request $request): StreamedResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $month = $this->month($request, $agencyId);
        $cid = $this->centre($request, $centres);
        $c = OccmsRoa::cwelcc($agencyId, $cid, $month);
        $this->audit($request, $agencyId, 'occms.cwelcc_export', $cid, 'CWELCC monthly summary CSV exported: ' . $centres[$cid] . ', ' . $month);

        return response()->streamDownload(function () use ($c, $centres, $cid, $month) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['CWELCC monthly summary']);
            fputcsv($h, ['Centre', $centres[$cid]]);
            fputcsv($h, ['Month', $month]);
            fputcsv($h, ['Enrolment as of', $c['as_of']]);
            fputcsv($h, ['Centre enrolled in CWELCC', $c['centre_cwelcc'] ? 'Yes' : 'No']);
            fputcsv($h, []);
            fputcsv($h, ['Age group', 'Enrolled', 'CWELCC eligible', 'Not eligible', 'Date of birth missing', 'Family enrolled in CWELCC']);
            foreach ($c['rows'] as $r) fputcsv($h, [$r['label'], $r['enrolled'], $r['eligible'], $r['not_eligible'], $r['unknown'], $r['family_enrolled']]);
            $t = $c['totals'];
            fputcsv($h, ['Total', $t['enrolled'], $t['eligible'], $t['not_eligible'], $t['unknown'], $t['family_enrolled']]);
            fputcsv($h, []);
            fputcsv($h, ['Age group', 'Licensed capacity', 'Operating capacity', 'Enrolled', 'Vacancies']);
            foreach ($c['capacity']['rows'] as $r) fputcsv($h, [$r['label'], $r['licensed'], $r['operating'], $r['enrolled'], $r['vacancies']]);
            if ($c['ageing_out']) {
                fputcsv($h, []);
                fputcsv($h, ['Leaving CWELCC soon (tell families 2 months ahead)', 'Age group', 'Eligible until']);
                foreach ($c['ageing_out'] as $r) fputcsv($h, [$r['name'], $r['group'], $r['until']]);
            }
            fclose($h);
        }, 'CWELCC-summary-' . preg_replace('/[^A-Za-z0-9]+/', '-', $centres[$cid]) . '-' . $month . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST /occms/settings {preset, allowance?, prorate_step?, max_consecutive?, unexplained_run?, due_day?, reminders?} — agency admins. */
    public function settings(Request $request): JsonResponse
    {
        [$agencyId, , $wide] = $this->ctx($request);
        abort_unless($wide, 403, 'Agency admins only.');
        $d = $request->validate([
            'preset' => ['required', 'in:' . implode(',', array_keys(OccmsRoa::PRESETS))],
            'allowance' => ['nullable', 'integer', 'min:0', 'max:366'],
            'prorate_step' => ['nullable', 'integer', 'min:0', 'max:31'],
            'max_consecutive' => ['nullable', 'integer', 'min:1', 'max:366'],
            'unexplained_run' => ['nullable', 'integer', 'min:2', 'max:31'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:28'],
            'reminders' => ['nullable', 'boolean'],
            'use_preset' => ['nullable', 'boolean'],
        ]);
        $new = ['preset' => $d['preset'], 'due_day' => (int) ($d['due_day'] ?? OccmsRoa::DEFAULT_DUE_DAY), 'reminders' => (bool) ($d['reminders'] ?? true)];
        if (empty($d['use_preset'])) {
            // Saved only where they differ from the preset, so a preset change carries the rest.
            $p = OccmsRoa::PRESETS[$d['preset']];
            foreach (['allowance', 'prorate_step', 'max_consecutive', 'unexplained_run'] as $k) {
                if (! array_key_exists($k, $d)) continue;
                $v = $d[$k] === null ? null : (int) $d[$k];
                if ($v !== $p[$k]) $new[$k] = $v;
            }
        }
        DB::transaction(function () use ($agencyId, $new) {
            $raw = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->value('settings');
            $s = $raw ? (json_decode((string) $raw, true) ?: []) : [];
            $s['occms_roa'] = $new;
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($s), 'updated_at' => now()]);
        });
        $set = OccmsRoa::settings($agencyId);
        $this->audit($request, $agencyId, 'occms.settings', null, 'OCCMS reporting settings: ' . $set['label'] . ', allowance ' . ($set['allowance'] ?? 'not set')
            . ', due day ' . $set['due_day'] . ', reminders ' . ($set['reminders'] ? 'on' : 'off'));

        return response()->json(['ok' => true, 'settings' => $set]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────────

    /** The month's row, created as a draft if new, locked for this transaction. */
    private function lockRow(int $agencyId, int $cid, string $month): object
    {
        DB::table('roa_months')->insertOrIgnore(['agency_id' => $agencyId, 'centre_id' => $cid, 'month' => $month, 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('roa_months')->where('centre_id', $cid)->where('month', $month)->lockForUpdate()->first();
    }

    private function event(int $agencyId, int $cid, string $month, string $action, int $uid, ?int $childId, ?string $day, ?string $old, ?string $new, ?string $note): void
    {
        DB::table('roa_events')->insert(['agency_id' => $agencyId, 'centre_id' => $cid, 'month' => $month, 'action' => $action, 'child_id' => $childId, 'day' => $day,
            'old_code' => $old, 'new_code' => $new, 'note' => $note !== null ? mb_substr($note, 0, 500) : null, 'user_id' => $uid, 'created_at' => now()]);
    }

    private function names(array $ids): array
    {
        return DB::table('users')->whereIn('id', $ids ?: [0])->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [(int) $u->id => trim($u->first_name . ' ' . $u->last_name)])->all();
    }

    private function header(int $agencyId, int $cid, string $month, array $b): array
    {
        $c = DB::table('centres')->where('id', $cid)->first(['name', 'license_number']);
        $roa = $b['roa'];
        $set = $b['settings'];

        return [
            'Operator' => (string) DB::table('agencies')->where('id', $agencyId)->value('name'),
            'Site' => $c->name,
            'Licence number' => (string) ($c->license_number ?? ''),
            'Month' => Carbon::createFromFormat('Y-m-d', $month . '-01')->format('F Y'),
            'Municipality' => $set['label'],
            'Due in OCCMS by' => Carbon::parse(OccmsRoa::dueOn($month, $set['due_day']))->format('M j, Y'),
            'Status' => ['draft' => 'Draft', 'reviewed' => 'Reviewed', 'signed_off' => 'Signed off', 'submitted' => 'Submitted to OCCMS'][$roa->status ?? 'draft'] ?? 'Draft',
            'Absence allowance' => $set['allowance'] === null ? 'not set' : $set['allowance'] . ' days/year' . ($set['prorate_step'] ? ' (prorated ' . $set['prorate_step'] . '/month)' : ''),
        ];
    }

    private function fileName(string $centre, string $month, string $ext): string
    {
        return 'ROA-' . trim(preg_replace('/[^A-Za-z0-9]+/', '-', $centre), '-') . '-' . $month . '.' . $ext;
    }

    private function audit(Request $request, int $agencyId, string $action, ?int $cid, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => 'centre', 'entity_id' => $cid,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {
            // Auditing must never be the reason a save fails.
        }
    }
}
