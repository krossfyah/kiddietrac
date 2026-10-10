<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Live;
use App\Support\OhioPfcc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Ohio PFCC — authorizations, weekly copays, absent days, the TAP reconciliation report and
 * the state/family invoice split (2026-10-10). Directors (their centres) and admins; only for
 * agencies where OhioPfcc::pfccOn(). Nothing here reads or writes subsidies, CWELCC or any
 * invoice except one a director explicitly asks to split, and only while it is a draft.
 */
final class PfccController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            return $active;
        }
        if ($active && (clone $roles)->where(function ($w) use ($active) {
                $w->where('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id'));
            })->exists()) return $active;
        $id = (int) ((clone $roles)->whereNotNull('agency_id')->value('agency_id')
            ?: DB::table('centres')->whereIn('id', (clone $roles)->whereNotNull('centre_id')->pluck('centre_id'))->value('agency_id'));
        abort_unless($id, 403);

        return $id;
    }

    /** [agencyId, scope (null = all), centres id=>name] — refuses when PFCC is off. */
    private function ctx(Request $request, bool $mustBeOn = true): array
    {
        $agencyId = $this->agencyId($request);
        if ($mustBeOn) abort_unless(OhioPfcc::pfccOn($agencyId), 404, 'Ohio publicly funded child care is not switched on for this agency.');
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return [$agencyId, $scope, $q->orderBy('name')->pluck('name', 'id')->all()];
    }

    private function isAdmin(Request $request, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', (int) $request->user()->id)->where('active', true)
            ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere(fn ($a) => $a->where('role', 'agency_admin')->where('agency_id', $agencyId)))->exists();
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function auth(Request $request, int $id): array
    {
        [$agencyId, $scope, $centres] = $this->ctx($request);
        $a = DB::table('pfcc_authorizations')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($a && isset($centres[$a->centre_id]), 404);

        return [$a, $agencyId, $centres];
    }

    private function childName(object $c): string
    {
        return trim(($c->preferred_name ?: $c->first_name) . ' ' . ($c->last_name ?? ''));
    }

    /** GET /pfcc */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $on = OhioPfcc::pfccOn($agencyId);
        $settings = OhioPfcc::settings($agencyId);
        if (! $on) return response()->json(['on' => false, 'ohio' => OhioPfcc::isOhio($agencyId), 'settings' => $settings, 'is_admin' => $this->isAdmin($request, $agencyId)]);
        [, , $centres] = $this->ctx($request);
        $today = AgencyTime::today($agencyId);
        [$hFrom, $hTo, $hLabel] = OhioPfcc::halfYear($today);
        $rows = DB::table('pfcc_authorizations as a')->join('children as c', 'c.id', '=', 'a.child_id')->leftJoin('families as f', 'f.id', '=', 'a.family_id')
            ->where('a.agency_id', $agencyId)->whereIn('a.centre_id', array_keys($centres) ?: [0])
            ->orderByRaw('a.ended_at IS NOT NULL')->orderBy('c.first_name')
            ->get(['a.*', 'c.first_name', 'c.last_name', 'c.preferred_name', 'f.family_name']);
        $out = [];
        foreach ($rows as $a) {
            $active = ! $a->ended_at && $a->starts_on <= $today && (! $a->ends_on || $a->ends_on >= $today);
            if ($active) OhioPfcc::ensureCopayWeeks($a, $today);
            $absent = OhioPfcc::absentDays((int) $a->child_id, $today);
            $out[] = ['id' => (int) $a->id, 'child_id' => (int) $a->child_id, 'child' => $this->childName($a), 'family' => $a->family_name, 'centre_id' => (int) $a->centre_id,
                'centre' => $centres[$a->centre_id] ?? '', 'case_number' => $a->case_number, 'starts_on' => $a->starts_on, 'ends_on' => $a->ends_on,
                'care_unit' => $a->care_unit, 'units_per_week' => (float) $a->units_per_week, 'rate_per_unit' => (float) $a->rate_per_unit,
                'weekly_copay' => (float) $a->weekly_copay, 'weekly' => OhioPfcc::weekly($a), 'active' => $active, 'ended' => (bool) $a->ended_at,
                'has_agreement' => (bool) $a->agreement_path, 'agreement_signed_on' => $a->agreement_signed_on, 'notes' => $a->notes,
                'unpaid_weeks' => $active ? OhioPfcc::unpaidWeeks($a, $today) : 0, 'absent_days' => $absent];
        }

        return response()->json(['on' => true, 'ohio' => OhioPfcc::isOhio($agencyId), 'settings' => $settings, 'is_admin' => $this->isAdmin($request, $agencyId),
            'units' => OhioPfcc::UNITS, 'centres' => $centres, 'today' => $today, 'half_year' => ['from' => $hFrom, 'to' => $hTo, 'label' => $hLabel],
            'authorizations' => $out]);
    }

    /** GET /pfcc/children — enrolled children in reach, for the authorization picker. */
    public function children(Request $request): JsonResponse
    {
        [$agencyId, , $centres] = $this->ctx($request);
        $q = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->whereIn('f.centre_id', array_keys($centres) ?: [0]);
        Live::children($q, 'c');

        return response()->json(['children' => $q->orderBy('c.first_name')->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'f.id as family_id', 'f.family_name', 'f.centre_id'])
            ->map(fn ($c) => ['id' => (int) $c->id, 'name' => $this->childName($c), 'family_id' => (int) $c->family_id, 'family' => $c->family_name, 'centre_id' => (int) $c->centre_id])]);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $r = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'child_id' => [$partial ? 'prohibited' : 'required', 'integer'],
            'case_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'starts_on' => [$r, 'date'],
            'ends_on' => ['sometimes', 'nullable', 'date'],
            'care_unit' => [$r, 'in:' . implode(',', array_keys(OhioPfcc::UNITS))],
            'units_per_week' => [$r, 'numeric', 'min:0.25', 'max:80'],
            'rate_per_unit' => [$r, 'numeric', 'min:0', 'max:100000'],
            'weekly_copay' => [$r, 'numeric', 'min:0', 'max:100000'],
            'agreement_signed_on' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);
    }

    /** POST /pfcc/authorizations */
    public function store(Request $request): JsonResponse
    {
        [$agencyId, , $centres] = $this->ctx($request);
        $d = $this->validated($request);
        $child = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->where('c.id', (int) $d['child_id'])->whereNull('c.deleted_at')
            ->first(['c.*', 'f.centre_id', 'f.id as fam_id']);
        abort_unless($child && isset($centres[$child->centre_id]), 422, 'That child is not at one of your centres.');
        if (! empty($d['ends_on'])) abort_if($d['ends_on'] < $d['starts_on'], 422, 'The end date is before the start date.');
        $id = DB::table('pfcc_authorizations')->insertGetId(['agency_id' => $agencyId, 'centre_id' => (int) $child->centre_id, 'child_id' => (int) $child->id,
            'family_id' => (int) $child->fam_id, 'case_number' => $d['case_number'] ?? null, 'starts_on' => $d['starts_on'], 'ends_on' => $d['ends_on'] ?? null,
            'care_unit' => $d['care_unit'], 'units_per_week' => $d['units_per_week'], 'rate_per_unit' => $d['rate_per_unit'], 'weekly_copay' => $d['weekly_copay'],
            'agreement_signed_on' => $d['agreement_signed_on'] ?? null, 'notes' => $d['notes'] ?? null, 'created_by' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'pfcc.authorization_added', 'child', (int) $child->id, 'PFCC authorization added for ' . $this->childName($child)
            . ' (' . OhioPfcc::UNITS[$d['care_unit']] . ', case ' . ($d['case_number'] ?? '—') . ')');

        return response()->json(['id' => $id]);
    }

    /** PATCH /pfcc/authorizations/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        [$a, $agencyId] = $this->auth($request, $id);
        $d = $this->validated($request, true);
        $start = $d['starts_on'] ?? $a->starts_on;
        $end = array_key_exists('ends_on', $d) ? $d['ends_on'] : $a->ends_on;
        if ($end) abort_if($end < $start, 422, 'The end date is before the start date.');
        DB::table('pfcc_authorizations')->where('id', $id)->update($d + ['updated_at' => now()]);
        if (array_key_exists('weekly_copay', $d)) {
            // Weeks not yet paid follow the new copay; paid or partly paid weeks keep their history.
            DB::table('pfcc_copays')->where('authorization_id', $id)->where('status', 'due')->where('amount_paid', 0)->update(['amount_due' => $d['weekly_copay'], 'updated_at' => now()]);
        }
        $this->audit($request, $agencyId, 'pfcc.authorization_changed', 'child', (int) $a->child_id, 'PFCC authorization changed: ' . implode(', ', array_keys($d)));

        return response()->json(['ok' => true]);
    }

    /** POST /pfcc/authorizations/{id}/end {ends_on} */
    public function end(Request $request, int $id): JsonResponse
    {
        [$a, $agencyId] = $this->auth($request, $id);
        $d = $request->validate(['ends_on' => ['required', 'date']]);
        DB::table('pfcc_authorizations')->where('id', $id)->update(['ends_on' => $d['ends_on'], 'ended_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'pfcc.authorization_ended', 'child', (int) $a->child_id, 'PFCC authorization ended on ' . $d['ends_on']);

        return response()->json(['ok' => true]);
    }

    /** POST /pfcc/authorizations/{id}/agreement (file) — the signed copay agreement. */
    public function uploadAgreement(Request $request, int $id): JsonResponse
    {
        [$a, $agencyId] = $this->auth($request, $id);
        $request->validate(['file' => ['required', 'file', 'max:20480', 'mimes:pdf,jpg,jpeg,png,heic,doc,docx']]);
        $f = $request->file('file');
        $path = $f->store('pfcc-agreements/' . $agencyId, 'local');
        if ($a->agreement_path) { try { Storage::disk('local')->delete($a->agreement_path); } catch (\Throwable $e) {} }
        DB::table('pfcc_authorizations')->where('id', $id)->update(['agreement_path' => $path, 'agreement_name' => mb_substr($f->getClientOriginalName(), 0, 190),
            'agreement_signed_on' => $request->input('signed_on') ?: ($a->agreement_signed_on ?: AgencyTime::today($agencyId)), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'pfcc.agreement_uploaded', 'child', (int) $a->child_id, 'Signed PFCC copay agreement uploaded');

        return response()->json(['ok' => true]);
    }

    /** GET /pfcc/authorizations/{id}/agreement */
    public function agreement(Request $request, int $id)
    {
        [$a, $agencyId] = $this->auth($request, $id);
        abort_unless($a->agreement_path && Storage::disk('local')->exists($a->agreement_path), 404);
        $this->audit($request, $agencyId, 'pfcc.agreement_viewed', 'child', (int) $a->child_id, 'Opened a PFCC copay agreement');

        return Storage::disk('local')->response($a->agreement_path, $a->agreement_name ?: 'copay-agreement', ['Content-Disposition' => 'inline; filename="' . addslashes($a->agreement_name ?: 'copay-agreement') . '"']);
    }

    /** GET /pfcc/authorizations/{id}/copays */
    public function copays(Request $request, int $id): JsonResponse
    {
        [$a, $agencyId] = $this->auth($request, $id);
        $today = AgencyTime::today($agencyId);
        OhioPfcc::ensureCopayWeeks($a, $today);

        return response()->json(['copays' => DB::table('pfcc_copays')->where('authorization_id', $id)->orderByDesc('week_start')->limit(104)->get(),
            'unpaid_weeks' => OhioPfcc::unpaidWeeks($a, $today), 'alert_after' => OhioPfcc::settings($agencyId)['alert_unpaid_weeks']]);
    }

    /** PATCH /pfcc/copays/{id} {amount_paid, paid_on?, status?, note?} */
    public function payCopay(Request $request, int $id): JsonResponse
    {
        $row = DB::table('pfcc_copays')->where('id', $id)->first();
        abort_unless($row, 404);
        [$a, $agencyId] = $this->auth($request, (int) $row->authorization_id);
        $d = $request->validate(['amount_paid' => ['required', 'numeric', 'min:0', 'max:100000'], 'paid_on' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'in:due,partial,paid,waived'], 'note' => ['sometimes', 'nullable', 'string', 'max:255']]);
        $paid = round((float) $d['amount_paid'], 2);
        $status = $d['status'] ?? ($paid >= (float) $row->amount_due ? 'paid' : ($paid > 0 ? 'partial' : 'due'));
        DB::table('pfcc_copays')->where('id', $id)->update(['amount_paid' => $paid, 'paid_on' => $d['paid_on'] ?? ($paid > 0 ? AgencyTime::today($agencyId) : null),
            'status' => $status, 'note' => $d['note'] ?? $row->note, 'recorded_by' => (int) $request->user()->id, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'pfcc.copay_recorded', 'child', (int) $a->child_id, 'PFCC copay for week of ' . $row->week_start . ': ' . number_format($paid, 2) . ' (' . $status . ')');

        return response()->json(['ok' => true, 'status' => $status]);
    }

    /**
     * GET /pfcc/reconciliation?week=YYYY-MM-DD&centre_id=&format=csv
     * One TAP week (Sunday–Saturday) of KiddieTrac attendance, beside what each authorization
     * allows, so the director can match it in TAP by hand (TAP has no API).
     */
    public function reconciliation(Request $request)
    {
        [$agencyId, , $centres] = $this->ctx($request);
        $tz = AgencyTime::tz($agencyId);
        $today = AgencyTime::today($agencyId);
        $week = OhioPfcc::weekStart((string) ($request->query('week') ?: $today));
        $centreIds = array_keys($centres);
        if ($cid = (int) $request->query('centre_id')) $centreIds = array_values(array_intersect($centreIds, [$cid]));
        $from = Carbon::parse($week, $tz)->startOfDay();
        $to = $from->copy()->addDays(7);
        $days = [];
        for ($i = 0; $i < 7; $i++) $days[] = $from->copy()->addDays($i)->toDateString();

        $kids = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->whereIn('f.centre_id', $centreIds ?: [0])->whereNull('c.deleted_at')
            ->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'f.family_name', 'f.centre_id'])->keyBy('id');
        $events = DB::table('check_events')->whereIn('child_id', $kids->keys()->all() ?: [0])->whereIn('event_type', ['check_in', 'check_out'])
            ->where('occurred_at', '>=', $from->copy()->utc())->where('occurred_at', '<', $to->copy()->utc())->orderBy('occurred_at')
            ->get(['child_id', 'event_type', 'occurred_at']);
        $auths = DB::table('pfcc_authorizations')->where('agency_id', $agencyId)->whereIn('centre_id', $centreIds ?: [0])->whereNull('ended_at')
            ->where('starts_on', '<=', $days[6])->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $days[0]))->get()->keyBy('child_id');
        $absences = DB::table('child_absences')->whereIn('child_id', $kids->keys()->all() ?: [0])->whereBetween('absent_on', [$days[0], $days[6]])
            ->get(['child_id', 'absent_on'])->groupBy('child_id');

        $byChild = [];
        foreach ($events as $e) {
            $local = Carbon::parse($e->occurred_at, 'UTC')->setTimezone($tz);
            $byChild[$e->child_id][$local->toDateString()][] = [$e->event_type, $local];
        }
        $rows = [];
        foreach (array_unique(array_merge(array_keys($byChild), $auths->keys()->all())) as $childId) {
            $k = $kids[$childId] ?? null;
            if (! $k) continue;
            $a = $auths[$childId] ?? null;
            $hours = 0.0; $unmatched = []; $attended = [];
            foreach ($byChild[$childId] ?? [] as $day => $list) {
                $attended[] = $day;
                $open = null; $bad = false;
                foreach ($list as [$type, $at]) {
                    if ($type === 'check_in') { if ($open) $bad = true; $open = $at; }
                    else { if (! $open) { $bad = true; continue; } $hours += $open->diffInMinutes($at) / 60; $open = null; }
                }
                if ($open) $bad = true;
                if ($bad) $unmatched[] = $day;
            }
            $flags = [];
            if ($attended && ! $a) $flags[] = 'No authorization — care given this week';
            if ($unmatched) $flags[] = 'In/out not matched: ' . implode(', ', array_map(fn ($d) => Carbon::parse($d)->format('D M j'), $unmatched));
            $max = $a ? OhioPfcc::unitMaxHours($a) : null;
            if ($a && $max !== null && $hours > $max + 0.01) $flags[] = 'Hours (' . round($hours, 1) . ') exceed the authorized unit (' . OhioPfcc::UNITS[$a->care_unit] . ')';
            if ($a && ! $attended) $flags[] = 'Authorized but no attendance this week';
            $abs = isset($absences[$childId]) ? $absences[$childId]->pluck('absent_on')->unique()->count() : 0;
            $rows[] = ['child_id' => (int) $childId, 'child' => $this->childName($k), 'family' => $k->family_name, 'centre' => $centres[$k->centre_id] ?? '',
                'case_number' => $a->case_number ?? null, 'care_unit' => $a ? OhioPfcc::UNITS[$a->care_unit] : null, 'units_per_week' => $a ? (float) $a->units_per_week : null,
                'days_attended' => count($attended), 'hours' => round($hours, 2), 'absent_days' => $abs, 'flags' => $flags];
        }
        usort($rows, fn ($x, $y) => [count($y['flags']) > 0, $x['child']] <=> [count($x['flags']) > 0, $y['child']]);
        $weekEnd = $days[6];
        $sweepBy = Carbon::parse($weekEnd)->addWeeks(4)->toDateString();
        $sweep = $today > Carbon::parse($weekEnd)->addWeeks(3)->toDateString()
            ? 'This week is more than 3 weeks old. TAP sweeps unsubmitted matched attendance after 4 weeks (around ' . Carbon::parse($sweepBy)->format('M j') . '). Submit anything outstanding now.'
            : null;

        if ($request->query('format') === 'csv') {
            $csv = "Child,Family,Centre,Case number,Care unit,Units/week,Days attended,Hours,Absent days,Issues\r\n";
            $q = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
            foreach ($rows as $r) {
                $csv .= implode(',', array_map($q, [$r['child'], $r['family'], $r['centre'], $r['case_number'], $r['care_unit'], $r['units_per_week'], $r['days_attended'], $r['hours'], $r['absent_days'], implode('; ', $r['flags'])])) . "\r\n";
            }
            $this->audit($request, $agencyId, 'pfcc.reconciliation_exported', 'agency', $agencyId, 'TAP reconciliation CSV for the week of ' . $week);

            return response($csv, 200, ['Content-Type' => 'text/csv; charset=utf-8', 'Content-Disposition' => 'attachment; filename="tap-reconciliation-' . $week . '.csv"',
                'Access-Control-Expose-Headers' => 'Content-Disposition']);
        }

        return response()->json(['week_start' => $week, 'week_end' => $weekEnd, 'sweep_warning' => $sweep, 'rows' => $rows,
            'issues' => count(array_filter($rows, fn ($r) => $r['flags']))]);
    }

    /** The state / family split for a draft invoice (preview). */
    private function split(object $inv): array
    {
        $lines = DB::table('invoice_lines')->where('invoice_id', $inv->id)->whereNotNull('child_id')->pluck('child_id')->unique();
        $start = (string) $inv->period_start; $end = (string) $inv->period_end;
        $detail = []; $state = 0.0; $copay = 0.0;
        foreach ($lines as $childId) {
            $a = DB::table('pfcc_authorizations')->where('child_id', $childId)->whereNull('ended_at')->where('starts_on', '<=', $end)
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $start))->orderByDesc('starts_on')->first();
            if (! $a || ! $start || ! $end) continue;
            $from = max($start, (string) $a->starts_on);
            $to = $a->ends_on ? min($end, (string) $a->ends_on) : $end;
            $days = (int) round(abs(Carbon::parse($from)->diffInDays(Carbon::parse($to), false))) + 1;
            $weeks = round($days / 7, 4);
            $w = OhioPfcc::weekly($a);
            $s = round($w['state'] * $weeks, 2); $c = round($w['copay'] * $weeks, 2);
            $state += $s; $copay += $c;
            $name = DB::table('children')->where('id', $childId)->value(DB::raw("CONCAT_WS(' ', first_name, last_name)"));
            $detail[] = ['child_id' => (int) $childId, 'child' => $name, 'case_number' => $a->case_number, 'from' => $from, 'to' => $to, 'weeks' => $weeks,
                'weekly' => $w, 'state' => $s, 'copay' => $c];
        }
        $cap = max(0, (float) $inv->subtotal - (float) $inv->discount_amount);
        $stateCapped = round(min($state, $cap), 2);
        /* Family billing (agency setting). copay_only: whatever the state does not pay beyond the
           copay is written off on the invoice, so the family owes the copay alone. */
        $mode = OhioPfcc::settings((int) (DB::table('centres')->where('id', $inv->centre_id)->value('agency_id') ?? 0))['family_billing'] ?? 'copay_only';
        $writeOff = $mode === 'copay_only' && $detail ? max(0, round($cap - $stateCapped - $copay, 2)) : 0.0;

        return ['state' => $stateCapped, 'copay' => round($copay, 2), 'capped' => $state > $cap, 'children' => $detail,
            'family_billing' => $mode, 'write_off' => $writeOff, 'family_owes' => round(max(0, $cap - $stateCapped - $writeOff) + (float) $inv->tax_amount, 2)];
    }

    private function invoice(Request $request, int $id): array
    {
        [$agencyId, , $centres] = $this->ctx($request);
        $inv = DB::table('invoices')->where('id', $id)->first();
        abort_unless($inv && isset($centres[$inv->centre_id]), 404);

        return [$inv, $agencyId];
    }

    /** GET /pfcc/invoices/{id}/split */
    public function splitPreview(Request $request, int $id): JsonResponse
    {
        [$inv] = $this->invoice($request, $id);
        $applied = DB::table('pfcc_invoice_splits')->where('invoice_id', $id)->first();

        return response()->json(['invoice' => ['id' => (int) $inv->id, 'number' => $inv->invoice_number, 'status' => $inv->status, 'subtotal' => (float) $inv->subtotal,
            'subsidy_amount' => (float) $inv->subsidy_amount, 'total' => (float) $inv->total], 'split' => $this->split($inv), 'applied' => $applied]);
    }

    /**
     * POST /pfcc/invoices/{id}/split — apply to a DRAFT invoice only: the state portion becomes
     * its subsidy line and the family owes the copay. Refused when the invoice already carries
     * another subsidy, so nothing is counted twice. DELETE undoes it.
     */
    public function splitApply(Request $request, int $id): JsonResponse
    {
        [$inv, $agencyId] = $this->invoice($request, $id);
        abort_unless($inv->status === 'draft', 422, 'Only a draft invoice can be split. Issued invoices are left as they are.');
        abort_if(DB::table('pfcc_invoice_splits')->where('invoice_id', $id)->exists(), 422, 'This invoice is already split.');
        abort_if((float) $inv->subsidy_amount > 0, 422, 'This invoice already has a subsidy on it, so it is not split again.');
        $sp = $this->split($inv);
        abort_unless($sp['children'], 422, 'No child on this invoice has a PFCC authorization for its period.');
        DB::transaction(function () use ($inv, $sp, $agencyId, $request) {
            $disc = round((float) $inv->discount_amount + (float) ($sp['write_off'] ?? 0), 2);
            $total = round((float) $inv->subtotal - $sp['state'] - $disc + (float) $inv->tax_amount, 2);
            DB::table('invoices')->where('id', $inv->id)->update(['subsidy_amount' => $sp['state'], 'discount_amount' => $disc, 'total' => $total,
                'balance_due' => round($total - (float) $inv->amount_paid, 2), 'updated_at' => now()]);
            DB::table('pfcc_invoice_splits')->insert(['agency_id' => $agencyId, 'invoice_id' => (int) $inv->id, 'state_amount' => $sp['state'], 'copay_amount' => $sp['copay'],
                'detail' => json_encode($sp), 'applied_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        });
        $this->audit($request, $agencyId, 'pfcc.invoice_split', 'invoice', (int) $inv->id, 'Ohio PFCC split applied to draft invoice ' . $inv->invoice_number
            . ': state ' . number_format($sp['state'], 2) . ', family copay ' . number_format($sp['copay'], 2));

        return response()->json(['ok' => true, 'split' => $sp]);
    }

    /** DELETE /pfcc/invoices/{id}/split — undo, while still a draft. */
    public function splitUndo(Request $request, int $id): JsonResponse
    {
        [$inv, $agencyId] = $this->invoice($request, $id);
        abort_unless($inv->status === 'draft', 422, 'Only a draft invoice can be changed.');
        $s = DB::table('pfcc_invoice_splits')->where('invoice_id', $id)->first();
        abort_unless($s, 404);
        DB::transaction(function () use ($inv, $s) {
            $sub = max(0, round((float) $inv->subsidy_amount - (float) $s->state_amount, 2));
            $wo = (float) ((json_decode((string) $s->detail, true) ?: [])['write_off'] ?? 0);
            $disc = max(0, round((float) $inv->discount_amount - $wo, 2));
            $total = round((float) $inv->subtotal - $sub - $disc + (float) $inv->tax_amount, 2);
            DB::table('invoices')->where('id', $inv->id)->update(['subsidy_amount' => $sub, 'discount_amount' => $disc, 'total' => $total, 'balance_due' => round($total - (float) $inv->amount_paid, 2), 'updated_at' => now()]);
            DB::table('pfcc_invoice_splits')->where('id', $s->id)->delete();
        });
        $this->audit($request, $agencyId, 'pfcc.invoice_split_removed', 'invoice', (int) $inv->id, 'Ohio PFCC split removed from draft invoice ' . $inv->invoice_number);

        return response()->json(['ok' => true]);
    }

    /** GET /pfcc/invoices — this agency's draft invoices with a PFCC child, for the split screen. */
    public function invoices(Request $request): JsonResponse
    {
        [$agencyId, , $centres] = $this->ctx($request);
        $childIds = DB::table('pfcc_authorizations')->where('agency_id', $agencyId)->whereNull('ended_at')->pluck('child_id')->all();
        $rows = DB::table('invoices as i')->join('families as f', 'f.id', '=', 'i.family_id')->whereIn('i.centre_id', array_keys($centres) ?: [0])
            ->whereIn('i.id', DB::table('invoice_lines')->whereIn('child_id', $childIds ?: [0])->pluck('invoice_id'))
            ->orderByDesc('i.period_start')->limit(100)
            ->get(['i.id', 'i.invoice_number', 'i.status', 'i.period_start', 'i.period_end', 'i.subtotal', 'i.subsidy_amount', 'i.total', 'f.family_name']);
        $split = DB::table('pfcc_invoice_splits')->whereIn('invoice_id', $rows->pluck('id')->all() ?: [0])->pluck('state_amount', 'invoice_id');

        return response()->json(['invoices' => $rows->map(fn ($r) => (array) $r + ['split_applied' => isset($split[$r->id]), 'state_amount' => (float) ($split[$r->id] ?? 0)])]);
    }

    /** GET/PATCH /pfcc/settings — PATCH: agency/platform admins. */
    public function settings(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        if ($request->isMethod('patch')) {
            abort_unless($this->isAdmin($request, $agencyId), 403, 'Only agency admins can change these settings.');
            $d = $request->validate(['pfcc_enabled' => 'sometimes|boolean', 'sutq_enabled' => 'sometimes|boolean', 'absent_cap' => 'sometimes|integer|min:1|max:60',
                'alert_unpaid_weeks' => 'sometimes|integer|min:1|max:8', 'sutq_level' => 'sometimes|in:bronze,silver,gold',
                'family_billing' => 'sometimes|in:copay_only,copay_plus_difference']);
            $s = OhioPfcc::saveSettings($agencyId, $d);
            $this->audit($request, $agencyId, 'pfcc.settings_changed', 'agency', $agencyId, 'Ohio PFCC / SUTQ settings changed: ' . json_encode($d));

            return response()->json(['settings' => $s]);
        }

        return response()->json(['settings' => OhioPfcc::settings($agencyId), 'ohio' => OhioPfcc::isOhio($agencyId),
            'pfcc_on' => OhioPfcc::pfccOn($agencyId), 'sutq_on' => OhioPfcc::sutqOn($agencyId)]);
    }
}
