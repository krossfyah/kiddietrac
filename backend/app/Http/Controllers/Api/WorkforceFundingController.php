<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Audit;
use App\Support\WorkforceFunding as WF;
use Dompdf\Dompdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONTARIO ECE WORKFORCE FUNDING — #workforce-funding and Settings → Payroll (2026-10-13).
 *
 * WEG + CWELCC Workforce Compensation: per-employee wage components, pay-period lines,
 * funding receipts with the 32-day check, and the year-end reconciliation (CSV + PDF).
 * The rules live in App\Support\WorkforceFunding.
 *
 * Admin only (agency_admin of the ACTIVE agency, or a platform admin who selected it):
 * this is everybody's pay. Ontario agencies only — anywhere else every route answers 404
 * except status, which answers {on:false}. Records only: nothing here moves money.
 */
final class WorkforceFundingController extends Controller
{
    /** The active agency; the caller must administer it. */
    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', 1);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');

            return $active;
        }
        $admin = (clone $roles)->where('role', 'agency_admin')->whereNotNull('agency_id')->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
        if ($active && in_array($active, $admin, true)) return $active;
        abort_if($active, 403, 'Payroll funding is for this agency\'s administrators.');
        abort_unless($admin, 403, 'Payroll funding is for agency administrators.');

        return $admin[0];
    }

    private function ontarioAgency(Request $request): int
    {
        $a = $this->agency($request);
        abort_unless(WF::ontario($a), 404, 'Ontario workforce funding applies to Ontario agencies only.');
        abort_unless(Schema::hasTable('workforce_wage_components'), 503, 'Not installed yet.');

        return $a;
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, ?int $id, array $payload): void
    {
        try {
            Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => $type, 'entity_id' => $id, 'payload' => json_encode($payload),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    private function userInAgency(int $agencyId, int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('agency_id', $agencyId)
            ->whereIn('role', WF::STAFF_ROLES)->exists();
    }

    private static function money($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    // ───────────────────────────── status + settings ─────────────────────────────

    /** GET /workforce-funding/status — menu gate. Never 404s. */
    public function status(Request $request): JsonResponse
    {
        try {
            $a = $this->agency($request);
        } catch (\Throwable $e) {
            return response()->json(['on' => false, 'ontario' => false]);
        }
        $ont = WF::ontario($a);

        return response()->json(['ontario' => $ont, 'on' => $ont && WF::settings($a)['enabled']]);
    }

    /** GET /workforce-funding/settings */
    public function settings(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $s = WF::settings($a);
        $policy = null;
        if (Schema::hasTable('policies')) {
            $policy = DB::table('policies')->where('agency_id', $a)->where('key', WF::POLICY_KEY)->where('archived', 0)
                ->first(['id', 'title', 'current_version_id', 'last_reviewed_at', 'next_review_due']);
        }
        $year = (int) substr(AgencyTime::today($a), 0, 4);

        return response()->json(['settings' => $s, 'current_year' => $year, 'params' => WF::params($s, $year),
            'defaults' => WF::DEFAULT_YEARS, 'policy_library' => Schema::hasTable('policies'), 'policy' => $policy]);
    }

    /** POST /workforce-funding/settings */
    public function saveSettings(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $d = $request->validate([
            'enabled' => 'nullable|boolean',
            'fte_hours' => 'nullable|numeric|min:500|max:3000',
            'year' => 'nullable|integer|min:2022|max:2100',
            'params' => 'nullable|array',
            'params.*' => 'nullable|numeric|min:0|max:200',
            'policy_text' => 'nullable|string|max:20000',
            'policy_approved_by' => 'nullable|string|max:160',
            'policy_approved_on' => 'nullable|date_format:Y-m-d',
        ]);
        $before = WF::settings($a);
        $s = $before;
        if ($request->has('enabled')) $s['enabled'] = $request->boolean('enabled');
        if (isset($d['fte_hours'])) $s['fte_hours'] = round((float) $d['fte_hours'], 1);
        foreach (['policy_text', 'policy_approved_by', 'policy_approved_on'] as $k) {
            if ($request->has($k)) $s[$k] = $d[$k] ?? null;
        }
        if (! empty($d['year']) && ! empty($d['params'])) {
            $row = $s['years'][(string) $d['year']] ?? [];
            foreach (WF::PARAM_KEYS as $k) {
                if (isset($d['params'][$k]) && $d['params'][$k] !== null && $d['params'][$k] !== '') $row[$k] = round((float) $d['params'][$k], 2);
            }
            if (count($row) !== count(WF::PARAM_KEYS)) {
                return response()->json(['message' => 'Give every value for ' . $d['year'] . ' (floors, ceilings and maximums).'], 422);
            }
            if ($row['floor_supervisor'] < $row['floor_program'] || $row['wcf_ceiling_supervisor'] < $row['wcf_ceiling_program']) {
                return response()->json(['message' => 'Supervisor floor and ceiling should not be lower than program staff\'s.'], 422);
            }
            $s['years'][(string) $d['year']] = $row;
        }
        WF::saveSettings($a, $s);

        $changed = [];
        foreach (['enabled', 'fte_hours', 'policy_approved_by', 'policy_approved_on'] as $k) {
            if (($before[$k] ?? null) != ($s[$k] ?? null)) $changed[$k] = ['from' => $before[$k] ?? null, 'to' => $s[$k] ?? null];
        }
        if (($before['policy_text'] ?? '') !== ($s['policy_text'] ?? '')) $changed['policy_text'] = 'edited';
        if (! empty($d['year'])) $changed['year_' . $d['year']] = ['from' => $before['years'][(string) $d['year']] ?? null, 'to' => $s['years'][(string) $d['year']] ?? null];
        $this->audit($request, $a, 'workforce_funding.settings_changed', 'agency', $a, [
            'summary' => 'Ontario workforce funding settings changed' . ($changed ? ' (' . implode(', ', array_keys($changed)) . ')' : ''), 'changes' => $changed]);

        return response()->json(['ok' => true, 'settings' => WF::settings($a)]);
    }

    /** POST /workforce-funding/policy — put the distribution policy in the Policy library. */
    public function policy(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        abort_unless(Schema::hasTable('policies'), 404, 'The policy library is not available.');
        $existing = DB::table('policies')->where('agency_id', $a)->where('key', WF::POLICY_KEY)->where('archived', 0)->value('id');
        if ($existing) return response()->json(['ok' => true, 'id' => (int) $existing, 'existed' => true]);
        $id = DB::table('policies')->insertGetId([
            'agency_id' => $a, 'centre_id' => null, 'key' => WF::POLICY_KEY,
            'title' => 'WEG and workforce compensation distribution policy',
            'purpose' => 'How Wage Enhancement Grant and CWELCC workforce compensation funding is distributed to staff: eligibility, distribution method (on every pay cheque, within 32 days of receiving funding), information given to staff, and the internal complaint process. Reviewed every year.',
            'category' => 'Staffing', 'required' => 1, 'reg_ref' => 'CWELCC / WEG funding guidelines (2026)',
            'review_frequency_months' => 12, 'created_by' => (int) $request->user()->id,
            'archived' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $s = WF::settings($a);
        $s['policy_id'] = $id;
        WF::saveSettings($a, $s);
        $this->audit($request, $a, 'workforce_funding.policy_created', 'policy', $id, ['summary' => 'Added the WEG / workforce compensation distribution policy to the Policy library']);

        return response()->json(['ok' => true, 'id' => $id, 'existed' => false]);
    }

    // ───────────────────────────── staff components ─────────────────────────────

    /** GET /workforce-funding/staff?date=YYYY-MM-DD — everyone payable, with today's components. */
    public function staff(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $date = (string) ($request->query('date') ?: AgencyTime::today($a));
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date), 422, 'Bad date.');
        $s = WF::settings($a);
        $centres = DB::table('centres')->where('agency_id', $a)->pluck('name', 'id');
        $out = [];
        foreach (WF::staff($a) as $p) {
            $c = WF::compute($a, $p['id'], $date, $date, $s, null, $p['role']);
            $out[] = $p + ['centre' => $p['centre_id'] ? ($centres[$p['centre_id']] ?? null) : null, 'c' => $c];
        }

        return response()->json(['date' => $date, 'enabled' => $s['enabled'], 'params' => WF::params($s, (int) substr($date, 0, 4)),
            'categories' => WF::CATEGORIES, 'staff' => $out]);
    }

    /** GET /workforce-funding/staff/{user} — one person: every component row, oldest last. */
    public function person(Request $request, int $user): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        abort_unless($this->userInAgency($a, $user), 404, 'Not found.');
        $u = DB::table('users')->where('id', $user)->first(['id', 'first_name', 'last_name', 'hire_date', 'registry_number']);
        abort_unless($u, 404);
        $today = AgencyTime::today($a);
        $rows = DB::table('workforce_wage_components')->where('agency_id', $a)->where('user_id', $user)
            ->orderByDesc('effective_from')->orderByDesc('id')->get();
        $names = DB::table('users')->whereIn('id', $rows->pluck('created_by')->merge($rows->pluck('updated_by'))->filter()->unique())
            ->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn ($x) => [$x->id => trim($x->first_name . ' ' . $x->last_name)]);
        $hist = $rows->map(function ($r) use ($a, $user, $names) {
            $at = $r->effective_from;
            $c = WF::compute($a, $user, $at, $at, null, $r);

            return (array) $r + ['c' => $c, 'created_by_name' => $names[$r->created_by] ?? null, 'updated_by_name' => $names[$r->updated_by] ?? null];
        })->values();
        $rece = Schema::hasTable('staff_certifications') ? DB::table('staff_certifications')->where('user_id', $user)
            ->where('cert_type', 'RECE')->orderByDesc('issued_at')->first(['issued_at', 'expires_at', 'active', 'certifier']) : null;

        return response()->json([
            'user' => ['id' => (int) $u->id, 'name' => trim($u->first_name . ' ' . $u->last_name), 'hire_date' => $u->hire_date ?? null,
                'registry_number' => $u->registry_number ?? null],
            'role' => WF::roleIn($a, $user), 'rece' => $rece,
            'current' => WF::compute($a, $user, $today, $today),
            'history' => $hist, 'categories' => WF::CATEGORIES,
        ]);
    }

    private function componentRules(): array
    {
        return [
            'effective_from' => 'required|date_format:Y-m-d',
            'effective_to' => 'nullable|date_format:Y-m-d|after_or_equal:effective_from',
            'base_wage' => 'required|numeric|min:0|max:500',
            'gof' => 'nullable|numeric|min:0|max:50',
            'eligibility_override' => 'nullable|in:' . implode(',', array_keys(WF::CATEGORIES)),
            'weg_override' => 'nullable|numeric|min:0|max:50',
            'wcf_increase_override' => 'nullable|numeric|min:0|max:50',
            'floor_override' => 'nullable|numeric|min:0|max:50',
            'stability' => 'nullable|numeric|min:0|max:50',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    private function clean(array $d): array
    {
        $out = [];
        foreach (['effective_from', 'effective_to', 'base_wage', 'eligibility_override', 'weg_override', 'wcf_increase_override', 'floor_override', 'notes'] as $k) {
            $v = $d[$k] ?? null;
            $out[$k] = ($v === '' ? null : $v);
        }
        $out['gof'] = round((float) ($d['gof'] ?? 0), 2);
        $out['stability'] = round((float) ($d['stability'] ?? 0), 2);

        return $out;
    }

    /** POST /workforce-funding/staff/{user}/components — a new effective-dated row. */
    public function addComponent(Request $request, int $user): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        abort_unless($this->userInAgency($a, $user), 404, 'Not found.');
        $d = $this->clean($request->validate($this->componentRules()));
        $uid = (int) $request->user()->id;

        $id = DB::transaction(function () use ($a, $user, $d, $uid) {
            // The open row before this one ends the day before, so two rows never both apply.
            $prev = DB::table('workforce_wage_components')->where('agency_id', $a)->where('user_id', $user)
                ->where('effective_from', '<', $d['effective_from'])->whereNull('effective_to')
                ->orderByDesc('effective_from')->first();
            if ($prev) {
                DB::table('workforce_wage_components')->where('id', $prev->id)->update([
                    'effective_to' => Carbon::parse($d['effective_from'])->subDay()->toDateString(), 'updated_by' => $uid, 'updated_at' => now()]);
            }

            return DB::table('workforce_wage_components')->insertGetId($d + ['agency_id' => $a, 'user_id' => $user,
                'created_by' => $uid, 'updated_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        });
        $name = trim((string) DB::table('users')->where('id', $user)->selectRaw("CONCAT(first_name,' ',last_name) n")->value('n'));
        $c = WF::compute($a, $user, $d['effective_from'], $d['effective_from']);
        $this->audit($request, $a, 'workforce_funding.component_added', 'workforce_wage_component', $id, [
            'summary' => 'Wage components for ' . $name . ' from ' . $d['effective_from'] . ': base ' . self::money($d['base_wage'])
                . ', WEG ' . self::money($c['weg']) . ', WCF increase ' . self::money($c['wcf_increase']) . ', floor top-up ' . self::money($c['wcf_floor'])
                . ' (' . $c['category_label'] . ')',
            'user_id' => $user, 'values' => $d, 'computed' => array_intersect_key($c, array_flip(['category', 'weg', 'wcf_increase', 'wcf_floor', 'stability', 'total']))]);

        return response()->json(['ok' => true, 'id' => $id, 'c' => $c], 201);
    }

    /** PATCH /workforce-funding/components/{id} — correct a row (audited with before/after). */
    public function updateComponent(Request $request, int $id): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $row = DB::table('workforce_wage_components')->where('id', $id)->where('agency_id', $a)->first();
        abort_unless($row, 404, 'Not found.');
        $d = $this->clean($request->validate($this->componentRules()));
        DB::table('workforce_wage_components')->where('id', $id)->update($d + ['updated_by' => (int) $request->user()->id, 'updated_at' => now()]);
        $changes = [];
        foreach ($d as $k => $v) {
            if ((string) ($row->{$k} ?? '') !== (string) ($v ?? '') && ! (is_numeric($row->{$k} ?? null) && is_numeric($v) && (float) $row->{$k} == (float) $v)) {
                $changes[$k] = ['from' => $row->{$k}, 'to' => $v];
            }
        }
        $name = trim((string) DB::table('users')->where('id', $row->user_id)->selectRaw("CONCAT(first_name,' ',last_name) n")->value('n'));
        $this->audit($request, $a, 'workforce_funding.component_changed', 'workforce_wage_component', $id, [
            'summary' => 'Wage components for ' . $name . ' (from ' . $row->effective_from . ') corrected: '
                . ($changes ? implode(', ', array_map(fn ($k, $v) => $k . ' ' . ($v['from'] ?? '—') . ' → ' . ($v['to'] ?? '—'), array_keys($changes), $changes)) : 'no change'),
            'user_id' => (int) $row->user_id, 'changes' => $changes]);

        return response()->json(['ok' => true, 'c' => WF::compute($a, (int) $row->user_id, $d['effective_from'], $d['effective_from'])]);
    }

    // ───────────────────────────── pay period ─────────────────────────────

    /**
     * GET /workforce-funding/period?from=&to=&format=json|csv — per employee: hours from the
     * clock × each component, and the component lines already on payslips for the period.
     */
    public function period(Request $request)
    {
        $a = $this->ontarioAgency($request);
        $d = $request->validate(['from' => 'required|date_format:Y-m-d', 'to' => 'required|date_format:Y-m-d|after_or_equal:from']);
        abort_if(Carbon::parse($d['from'])->diffInDays(Carbon::parse($d['to'])) > 62, 422, 'Pick a pay period of two months or less.');
        $s = WF::settings($a);
        $staff = WF::staff($a);
        $hours = WF::hours($a, array_keys($staff), $d['from'], $d['to']);

        $docs = DB::table('payroll_documents')->where('agency_id', $a)->whereNotNull('user_id')
            ->where('period_start', '<=', $d['to'])->where('period_end', '>=', $d['from'])
            ->where(fn ($w) => $w->whereNull('status')->orWhere('status', '!=', 'void'))
            ->get(['id', 'user_id', 'reference', 'period_start', 'period_end', 'gross', 'status', 'source', 'issued_at'])->groupBy('user_id');
        $paid = DB::table('workforce_pay_lines')->where('agency_id', $a)
            ->whereIn('payroll_document_id', $docs->flatten()->pluck('id')->all() ?: [0])
            ->get()->groupBy('user_id');

        $rows = [];
        foreach ($staff as $uid => $p) {
            $h = (float) ($hours[$uid] ?? 0);
            $c = WF::compute($a, $uid, $d['from'], $d['to'], $s, null, $p['role']);
            if ($h <= 0 && ! isset($docs[$uid])) continue;
            $lines = WF::lines($c, $h);
            $rows[] = [
                'user_id' => $uid, 'name' => $p['name'], 'category' => $c['category'], 'category_label' => $c['category_label'],
                'hours' => $h, 'base' => $c['base'], 'total_rate' => $c['total'], 'lines' => $lines,
                'gross' => round(array_sum(array_column($lines, 'amount')), 2),
                'funded' => round(array_sum(array_column(array_filter($lines, fn ($l) => $l['component'] !== 'base'), 'amount')), 2),
                'warnings' => $c['warnings'],
                'payslips' => ($docs[$uid] ?? collect())->map(fn ($doc) => [
                    'id' => (int) $doc->id, 'reference' => $doc->reference, 'period' => $doc->period_start . ' to ' . $doc->period_end,
                    'gross' => (float) $doc->gross, 'status' => $doc->status, 'source' => $doc->source,
                    'lines' => ($paid[$uid] ?? collect())->where('payroll_document_id', $doc->id)->map(fn ($l) => [
                        'component' => $l->component, 'label' => WF::COMPONENTS[$l->component] ?? $l->component,
                        'hours' => $l->hours !== null ? (float) $l->hours : null, 'rate' => $l->rate !== null ? (float) $l->rate : null, 'amount' => (float) $l->amount])->values(),
                ])->values(),
            ];
        }

        if ($request->query('format') === 'csv') {
            $this->audit($request, $a, 'workforce_funding.period_exported', 'payroll_period', null, ['summary' => 'Exported workforce funding pay-period lines ' . $d['from'] . ' to ' . $d['to'] . ' (CSV)']);
            $name = 'workforce-funding-lines-' . $d['from'] . '-to-' . $d['to'] . '.csv';

            return response()->streamDownload(function () use ($rows, $d) {
                $h = fopen('php://output', 'w');
                fwrite($h, "\xEF\xBB\xBF");
                fputcsv($h, ['Period start', 'Period end', 'Employee', 'Position (funding category)', 'Line', 'Hours', 'Rate', 'Amount']);
                foreach ($rows as $r) {
                    if (! $r['lines']) fputcsv($h, [$d['from'], $d['to'], $r['name'], $r['category_label'], 'No base wage recorded', $r['hours'], '', '']);
                    foreach ($r['lines'] as $l) fputcsv($h, [$d['from'], $d['to'], $r['name'], $r['category_label'], $l['label'], $l['hours'], number_format($l['rate'], 2, '.', ''), number_format($l['amount'], 2, '.', '')]);
                }
                fclose($h);
            }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        return response()->json(['from' => $d['from'], 'to' => $d['to'], 'enabled' => $s['enabled'],
            'params' => WF::params($s, (int) substr($d['to'], 0, 4)), 'rows' => $rows]);
    }

    // ───────────────────────────── receipts ─────────────────────────────

    /** GET /workforce-funding/receipts?year= */
    public function receipts(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $year = $request->query('year') ? (int) $request->query('year') : null;
        $list = WF::receiptChecks($a, $year);

        return response()->json(['receipts' => $list, 'today' => AgencyTime::today($a),
            'centres' => DB::table('centres')->whereIn('id', \App\Support\Live::centreIds($a) ?: [0])->orderBy('name')->get(['id', 'name']),
            'summary' => ['missed' => count(array_filter($list, fn ($r) => $r['state'] === 'missed')),
                'pending' => count(array_filter($list, fn ($r) => $r['state'] === 'pending'))]]);
    }

    /** POST /workforce-funding/receipts */
    public function addReceipt(Request $request): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $d = $request->validate([
            'received_on' => 'required|date_format:Y-m-d',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'stream' => 'required|in:weg,wcf,both',
            'period_from' => 'nullable|date_format:Y-m-d',
            'period_to' => 'nullable|date_format:Y-m-d|after_or_equal:period_from',
            'centre_id' => 'nullable|integer',
            'reference' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:1000',
        ]);
        if (! empty($d['centre_id'])) {
            abort_unless(DB::table('centres')->where('id', $d['centre_id'])->where('agency_id', $a)->exists(), 422, 'That centre is not in this agency.');
        }
        abort_if($d['received_on'] > AgencyTime::today($a), 422, 'The date received cannot be in the future.');
        $id = DB::table('workforce_funding_receipts')->insertGetId($d + ['agency_id' => $a, 'created_by' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $label = ['weg' => 'WEG', 'wcf' => 'Workforce compensation', 'both' => 'WEG + workforce compensation'][$d['stream']];
        $this->audit($request, $a, 'workforce_funding.receipt_recorded', 'workforce_funding_receipt', $id, [
            'summary' => $label . ' funding of ' . self::money($d['amount']) . ' recorded as received on ' . $d['received_on']
                . (! empty($d['period_from']) ? ' (covering ' . $d['period_from'] . ' to ' . ($d['period_to'] ?? '…') . ')' : '')
                . ' — must reach staff pay by ' . Carbon::parse($d['received_on'])->addDays(32)->toDateString(),
            'values' => $d]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** POST /workforce-funding/receipts/{id}/void {reason} — kept, marked void (7-year record). */
    public function voidReceipt(Request $request, int $id): JsonResponse
    {
        $a = $this->ontarioAgency($request);
        $d = $request->validate(['reason' => 'required|string|min:3|max:500']);
        $r = DB::table('workforce_funding_receipts')->where('id', $id)->where('agency_id', $a)->first();
        abort_unless($r, 404, 'Not found.');
        abort_if($r->voided_at, 422, 'Already void.');
        DB::table('workforce_funding_receipts')->where('id', $id)->update(['voided_at' => now(), 'void_reason' => $d['reason'], 'updated_at' => now()]);
        $this->audit($request, $a, 'workforce_funding.receipt_voided', 'workforce_funding_receipt', $id, [
            'summary' => 'Funding receipt of ' . self::money($r->amount) . ' (received ' . $r->received_on . ') voided: ' . $d['reason']]);

        return response()->json(['ok' => true]);
    }

    // ───────────────────────────── year-end ─────────────────────────────

    /** GET /workforce-funding/year-end?year=&format=json|csv|pdf */
    public function yearEnd(Request $request)
    {
        $a = $this->ontarioAgency($request);
        $year = (int) ($request->query('year') ?: substr(AgencyTime::today($a), 0, 4));
        abort_unless($year >= 2022 && $year <= 2100, 422, 'Bad year.');
        $r = WF::yearEnd($a, $year);
        $r['receipts'] = WF::receiptChecks($a, $year);
        $fmt = (string) $request->query('format');
        if ($fmt === 'csv' || $fmt === 'pdf') {
            $this->audit($request, $a, 'workforce_funding.year_end_exported', 'workforce_funding_report', null, [
                'summary' => 'Exported the ' . $year . ' workforce funding year-end reconciliation (' . strtoupper($fmt) . ')',
                'year' => $year, 'staff' => count($r['rece']) + count($r['non_rece']) + count($r['other']),
                'paid_total' => $r['funding']['paid']['total'], 'received_total' => $r['funding']['received']['total']]);

            return $fmt === 'csv' ? $this->yearCsv($r, $a) : $this->yearPdf($r, $a);
        }

        return response()->json($r);
    }

    private const COLS = ['hours' => 'Hours', 'fte' => 'FTE', 'base' => 'Base wages', 'gof' => 'GOF', 'weg' => 'WEG paid',
        'wcf_increase' => 'WCF increase paid', 'wcf_floor' => 'WCF wage floor paid', 'stability' => 'Wage stability paid',
        'benefit_allocation' => 'Benefit allocation 17.5%', 'vacation_pay' => 'Vacation pay recorded'];

    private function yearCsv(array $r, int $a)
    {
        $agency = (string) DB::table('agencies')->where('id', $a)->value('name');
        $name = 'workforce-funding-year-end-' . $r['year'] . '.csv';

        return response()->streamDownload(function () use ($r, $agency) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Ontario ECE workforce funding — year-end reconciliation', $agency, (string) $r['year']]);
            fputcsv($h, ['FTE basis: ' . $r['fte_hours'] . ' hours a year. Benefit allocation = 17.5% of WEG + WCF paid (the guideline allocation, not the employer\'s actual benefit cost).']);
            fputcsv($h, []);
            $head = array_merge(['Section', 'Employee', 'Position (funding category)', 'Payslips'], array_values(self::COLS));
            foreach (['rece' => 'RECE staff', 'non_rece' => 'Non-RECE director-approved staff (reported separately)', 'other' => 'Not eligible (paid, no funding)'] as $k => $label) {
                fputcsv($h, [$label]);
                fputcsv($h, $head);
                foreach ($r[$k] as $p) {
                    $line = [$label, $p['name'], $p['category_label'], $p['payslips']];
                    foreach (array_keys(self::COLS) as $c) $line[] = $c === 'fte' ? number_format($p[$c], 3, '.', '') : number_format($p[$c], 2, '.', '');
                    fputcsv($h, $line);
                }
                $t = $r['totals'][$k] ?? null;
                if ($t) {
                    $line = [$label . ' — total', '', '', ''];
                    foreach (array_keys(self::COLS) as $c) $line[] = isset($t[$c]) ? ($c === 'fte' ? number_format($t[$c], 3, '.', '') : number_format($t[$c], 2, '.', '')) : '';
                    fputcsv($h, $line);
                }
                fputcsv($h, []);
            }
            fputcsv($h, ['By position']);
            fputcsv($h, ['Position', 'Staff', 'Hours', 'FTE', 'Base wages', 'GOF', 'WEG paid', 'WCF increase paid', 'WCF floor paid', 'Wage stability paid', 'Benefit allocation 17.5%', 'Staff with WEG', 'Staff with WCF increase', 'Staff with wage floor']);
            foreach ($r['positions'] as $x) {
                fputcsv($h, [$x['label'], $x['staff'], $x['hours'], $x['fte'], $x['base'], $x['gof'], $x['weg'], $x['wcf_increase'], $x['wcf_floor'], $x['stability'], $x['benefit_allocation'], $x['staff_weg'], $x['staff_wcf_increase'], $x['staff_wcf_floor']]);
            }
            fputcsv($h, []);
            $f = $r['funding'];
            fputcsv($h, ['Funding received vs paid out']);
            fputcsv($h, ['Received — WEG', $f['received']['weg']]);
            fputcsv($h, ['Received — workforce compensation', $f['received']['wcf']]);
            fputcsv($h, ['Received — combined (not split)', $f['received']['combined']]);
            fputcsv($h, ['Received — total', $f['received']['total']]);
            fputcsv($h, ['Paid to staff — WEG', $f['paid']['weg']]);
            fputcsv($h, ['Paid to staff — workforce compensation', $f['paid']['wcf']]);
            fputcsv($h, ['Benefit allocation (17.5%)', $f['benefit_allocation']]);
            fputcsv($h, ['Received less paid and allocation (surplus + / shortfall -)', $f['difference']]);
            if ($r['unsplit_payslips']) fputcsv($h, ['Payslips this year with no component breakdown (not counted above)', $r['unsplit_payslips']]);
            fputcsv($h, []);
            fputcsv($h, ['Funding receipts and the 32-day rule']);
            fputcsv($h, ['Received on', 'Amount', 'Stream', 'Covers', 'Reference', 'Pay by', 'First pay with funding', 'Status']);
            foreach ($r['receipts'] as $x) {
                fputcsv($h, [$x['received_on'], $x['amount'], $x['stream'], trim(($x['period_from'] ?? '') . ' to ' . ($x['period_to'] ?? ''), ' to'), $x['reference'], $x['deadline'], $x['first_paid_on'], strtoupper($x['state'])]);
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function yearPdf(array $r, int $a)
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $m = fn ($v) => '$' . number_format((float) $v, 2);
        $agency = (string) DB::table('agencies')->where('id', $a)->value('name');
        $tz = AgencyTime::tz($a);
        $h = '<html><head><meta charset="utf-8"><style>body{font-family:DejaVu Sans,sans-serif;font-size:8.5px;color:#0F172A;}h1{font-size:15px;margin:0;}h2{font-size:11px;margin:12px 0 3px;}'
            . '.m{color:#475569;margin:2px 0 8px;}table{width:100%;border-collapse:collapse;margin:2px 0 8px;}th,td{border:1px solid #CBD5E1;padding:2px 4px;text-align:left;}'
            . 'th{background:#F1F5F9;font-size:7.5px;text-transform:uppercase;}td.n,th.n{text-align:right;}tr.t td{font-weight:bold;background:#F8FAFC;}.bad{color:#B91C1C;font-weight:bold;}.ok{color:#047857;}</style></head><body>'
            . '<h1>Ontario ECE workforce funding — year-end reconciliation ' . (int) $r['year'] . '</h1>'
            . '<div class="m">' . $e($agency) . ' · Wage Enhancement Grant (WEG) and CWELCC workforce compensation (WCF) · generated ' . $e(Carbon::now($tz)->format('M j, Y g:i A')) . '<br>'
            . 'Floors ' . $m($r['params']['floor_program']) . ' program staff / ' . $m($r['params']['floor_supervisor']) . ' supervisors and home visitors · WCF ceilings '
            . $m($r['params']['wcf_ceiling_program']) . ' / ' . $m($r['params']['wcf_ceiling_supervisor']) . ' · WEG up to ' . $m($r['params']['weg_max']) . '/h to ' . $m($r['params']['weg_ceiling'])
            . ' · FTE = ' . $e($r['fte_hours']) . ' h/year · From the component lines on each payslip (pay periods ending in ' . (int) $r['year'] . ').</div>';

        $table = function (array $list, ?array $tot) use ($e, $m) {
            $t = '<table><tr><th>Employee</th><th>Position</th><th class="n">Payslips</th><th class="n">Hours</th><th class="n">FTE</th><th class="n">Base wages</th><th class="n">GOF</th><th class="n">WEG</th><th class="n">WCF increase</th><th class="n">WCF floor</th><th class="n">Stability</th><th class="n">Benefit alloc. 17.5%</th><th class="n">Vacation pay</th></tr>';
            if (! $list) $t .= '<tr><td colspan="13">None.</td></tr>';
            foreach ($list as $p) {
                $t .= '<tr><td>' . $e($p['name']) . '</td><td>' . $e($p['category_label']) . '</td><td class="n">' . (int) $p['payslips'] . '</td><td class="n">' . number_format($p['hours'], 2) . '</td><td class="n">' . number_format($p['fte'], 3)
                    . '</td><td class="n">' . $m($p['base']) . '</td><td class="n">' . $m($p['gof']) . '</td><td class="n">' . $m($p['weg']) . '</td><td class="n">' . $m($p['wcf_increase']) . '</td><td class="n">' . $m($p['wcf_floor'])
                    . '</td><td class="n">' . $m($p['stability']) . '</td><td class="n">' . $m($p['benefit_allocation']) . '</td><td class="n">' . $m($p['vacation_pay']) . '</td></tr>';
            }
            if ($tot) {
                $t .= '<tr class="t"><td colspan="3">Total (' . (int) ($tot['staff'] ?? 0) . ')</td><td class="n">' . number_format($tot['hours'] ?? 0, 2) . '</td><td class="n">' . number_format($tot['fte'] ?? 0, 3) . '</td><td class="n">' . $m($tot['base'] ?? 0)
                    . '</td><td class="n">' . $m($tot['gof'] ?? 0) . '</td><td class="n">' . $m($tot['weg'] ?? 0) . '</td><td class="n">' . $m($tot['wcf_increase'] ?? 0) . '</td><td class="n">' . $m($tot['wcf_floor'] ?? 0)
                    . '</td><td class="n">' . $m($tot['stability'] ?? 0) . '</td><td class="n">' . $m($tot['benefit_allocation'] ?? 0) . '</td><td class="n">' . $m($tot['vacation_pay'] ?? 0) . '</td></tr>';
            }

            return $t . '</table>';
        };
        $h .= '<h2>RECE staff</h2>' . $table($r['rece'], $r['totals']['rece'] ?: null);
        $h .= '<h2>Non-RECE director-approved staff and supervisors (reported separately — WEG only, no WCF)</h2>' . $table($r['non_rece'], $r['totals']['non_rece'] ?: null);

        $h .= '<h2>By position</h2><table><tr><th>Position</th><th class="n">Staff</th><th class="n">Hours</th><th class="n">FTE</th><th class="n">Base wages</th><th class="n">WEG</th><th class="n">WCF increase</th><th class="n">WCF floor</th><th class="n">Stability</th><th class="n">Staff with WEG</th><th class="n">with WCF increase</th><th class="n">with wage floor</th></tr>';
        foreach ($r['positions'] as $x) {
            $h .= '<tr><td>' . $e($x['label']) . '</td><td class="n">' . (int) $x['staff'] . '</td><td class="n">' . number_format($x['hours'], 2) . '</td><td class="n">' . number_format($x['fte'], 3) . '</td><td class="n">' . $m($x['base'])
                . '</td><td class="n">' . $m($x['weg']) . '</td><td class="n">' . $m($x['wcf_increase']) . '</td><td class="n">' . $m($x['wcf_floor']) . '</td><td class="n">' . $m($x['stability'])
                . '</td><td class="n">' . (int) $x['staff_weg'] . '</td><td class="n">' . (int) $x['staff_wcf_increase'] . '</td><td class="n">' . (int) $x['staff_wcf_floor'] . '</td></tr>';
        }
        if (! $r['positions']) $h .= '<tr><td colspan="12">No payslips with funding lines this year.</td></tr>';
        $h .= '</table>';

        $f = $r['funding'];
        $h .= '<h2>Funding received vs paid out</h2><table style="width:60%;">'
            . '<tr><td>Received — WEG</td><td class="n">' . $m($f['received']['weg']) . '</td></tr>'
            . '<tr><td>Received — workforce compensation</td><td class="n">' . $m($f['received']['wcf']) . '</td></tr>'
            . '<tr><td>Received — combined (not split)</td><td class="n">' . $m($f['received']['combined']) . '</td></tr>'
            . '<tr class="t"><td>Total received</td><td class="n">' . $m($f['received']['total']) . '</td></tr>'
            . '<tr><td>Paid to staff — WEG</td><td class="n">' . $m($f['paid']['weg']) . '</td></tr>'
            . '<tr><td>Paid to staff — workforce compensation (increase, floor, stability)</td><td class="n">' . $m($f['paid']['wcf']) . '</td></tr>'
            . '<tr><td>Benefit allocation (17.5% of the above)</td><td class="n">' . $m($f['benefit_allocation']) . '</td></tr>'
            . '<tr class="t"><td>Received less paid and allocation (+ surplus to return / − shortfall)</td><td class="n">' . $m($f['difference']) . '</td></tr></table>';
        if ($r['unsplit_payslips']) {
            $h .= '<p class="bad">' . (int) $r['unsplit_payslips'] . ' payslip(s) in ' . (int) $r['year'] . ' carry no WEG/WCF breakdown (for example, synced from another payroll system) and are not counted above.</p>';
        }
        $h .= '<h2>Funding receipts and the 32-day rule</h2><table><tr><th>Received</th><th class="n">Amount</th><th>Stream</th><th>Covers</th><th>Pay by</th><th>First pay with funding</th><th>Status</th></tr>';
        if (! $r['receipts']) $h .= '<tr><td colspan="7">No receipts recorded.</td></tr>';
        foreach ($r['receipts'] as $x) {
            $cls = $x['state'] === 'missed' ? 'bad' : ($x['state'] === 'met' ? 'ok' : '');
            $h .= '<tr><td>' . $e($x['received_on']) . '</td><td class="n">' . $m($x['amount']) . '</td><td>' . $e(strtoupper($x['stream'])) . '</td><td>' . $e(($x['period_from'] ?? '') . ($x['period_to'] ? ' to ' . $x['period_to'] : ''))
                . '</td><td>' . $e($x['deadline']) . '</td><td>' . $e($x['first_paid_on'] ?? '—') . '</td><td class="' . $cls . '">' . $e(strtoupper($x['state'])) . '</td></tr>';
        }
        $h .= '</table><div class="m">Keep this report with the payroll records for seven years. Pay stubs show WEG and workforce compensation as separate lines.</div></body></html>';

        $pdf = new Dompdf();
        $pdf->loadHtml($h);
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();

        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="workforce-funding-year-end-' . (int) $r['year'] . '.pdf"']);
    }
}
