<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * v22p54 — Branded XLSX export endpoints (replaces v22p45-era CSV).
 * Every export gets agency logo/colour, frozen header, alternating rows,
 * auto-width columns, totals row where applicable, and a footer signature.
 */
final class ExportsController extends Controller
{
    public function __construct(private XlsxExportService $xlsx) {}

    public function users(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('users as u')
            ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
            ->where('ra.agency_id', $agencyId)
            ->where('ra.active', 1)
            ->whereNull('u.deleted_at')
            ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone',
                'ra.role', 'u.last_login_at', 'u.status', 'u.created_at')
            ->orderBy('u.last_name')->orderBy('u.first_name')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->first_name . ' ' . $r->last_name,
                'email' => $r->email,
                'phone' => $r->phone,
                'role' => $r->role,
                'status' => $r->status,
                'last_login' => $r->last_login_at,
                'created' => $r->created_at,
            ]);
        return $this->xlsx->download($agencyId, 'Users', 'users-' . now()->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'ID', 'key' => 'id', 'width' => 8],
                ['header' => 'Name', 'key' => 'name', 'width' => 26],
                ['header' => 'Email', 'key' => 'email', 'width' => 32],
                ['header' => 'Phone', 'key' => 'phone', 'width' => 18],
                ['header' => 'Role', 'key' => 'role', 'width' => 18],
                ['header' => 'Status', 'key' => 'status', 'width' => 12],
                ['header' => 'Last login', 'key' => 'last_login', 'width' => 20, 'format' => 'datetime'],
                ['header' => 'Created', 'key' => 'created', 'width' => 20, 'format' => 'datetime'],
            ], $rows);
    }

    public function families(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('families as f')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('f.deleted_at')
            ->leftJoin('children as ch', function ($j) { $j->on('ch.family_id', '=', 'f.id')->whereNull('ch.deleted_at'); })
            ->groupBy('f.id', 'f.family_name', 'f.primary_email', 'f.primary_phone', 'f.address_line1', 'f.city', 'f.province', 'c.name', 'f.autopay_enabled', 'f.cwelcc_enrolled')
            ->select('f.id', 'f.family_name', 'f.primary_email', 'f.primary_phone',
                'f.address_line1', 'f.city', 'f.province', 'c.name as centre_name',
                'f.autopay_enabled', 'f.cwelcc_enrolled',
                DB::raw('COUNT(ch.id) as children_count'))
            ->orderBy('f.family_name')
            ->get();
        $balances = DB::table('invoices')
            ->whereIn('family_id', $rows->pluck('id'))
            ->whereIn('status', ['sent', 'partial', 'overdue'])
            ->groupBy('family_id')
            ->select('family_id', DB::raw('SUM(balance_due) as bal'))
            ->pluck('bal', 'family_id');
        $rows = $rows->map(fn ($r) => [
            'id' => $r->id, 'family_name' => $r->family_name, 'email' => $r->primary_email,
            'phone' => $r->primary_phone, 'address' => $r->address_line1, 'city' => $r->city,
            'province' => $r->province, 'centre' => $r->centre_name,
            'children' => $r->children_count, 'autopay' => $r->autopay_enabled ? 'Yes' : 'No',
            'cwelcc' => $r->cwelcc_enrolled ? 'Yes' : 'No',
            'balance' => (float) ($balances[$r->id] ?? 0),
        ]);
        return $this->xlsx->download($agencyId, 'Families', 'families-' . now()->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'ID', 'key' => 'id', 'width' => 8],
                ['header' => 'Family name', 'key' => 'family_name', 'width' => 26],
                ['header' => 'Email', 'key' => 'email', 'width' => 30],
                ['header' => 'Phone', 'key' => 'phone', 'width' => 16],
                ['header' => 'Address', 'key' => 'address', 'width' => 26],
                ['header' => 'City', 'key' => 'city', 'width' => 16],
                ['header' => 'Province', 'key' => 'province', 'width' => 10],
                ['header' => 'Centre', 'key' => 'centre', 'width' => 22],
                ['header' => 'Children', 'key' => 'children', 'width' => 10],
                ['header' => 'Auto-pay', 'key' => 'autopay', 'width' => 10],
                ['header' => 'CWELCC', 'key' => 'cwelcc', 'width' => 10],
                ['header' => 'Outstanding balance', 'key' => 'balance', 'width' => 18, 'format' => 'money'],
            ], $rows,
            ['totals_row' => ['family_name' => 'TOTAL (' . $rows->count() . ' families)', 'balance' => $rows->sum('balance')]]);
    }

    public function auditLogs(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('audit_logs as al')
            ->leftJoin('users as u', 'u.id', '=', 'al.user_id')
            ->whereExists(function ($q) use ($agencyId) {
                $q->select(DB::raw(1))->from('role_assignments as ra')
                  ->whereColumn('ra.user_id', 'al.user_id')->where('ra.agency_id', $agencyId);
            })
            ->orderByDesc('al.created_at')
            ->limit(5000)
            ->select('al.created_at', 'al.action', 'al.entity_type', 'al.entity_id',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as actor"),
                'u.email', 'al.ip_address', 'al.payload')
            ->get();
        return $this->xlsx->download($agencyId, 'Audit log', 'audit-log-' . now()->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'When', 'key' => 'created_at', 'width' => 20, 'format' => 'datetime'],
                ['header' => 'Action', 'key' => 'action', 'width' => 28],
                ['header' => 'Entity', 'key' => 'entity_type', 'width' => 16],
                ['header' => 'Entity ID', 'key' => 'entity_id', 'width' => 10],
                ['header' => 'Actor', 'key' => 'actor', 'width' => 22],
                ['header' => 'Email', 'key' => 'email', 'width' => 28],
                ['header' => 'IP', 'key' => 'ip_address', 'width' => 16],
                ['header' => 'Payload', 'key' => 'payload', 'width' => 60],
            ], $rows->map(fn ($r) => (array) $r));
    }

    public function children(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('children as ch')
            ->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNull('ch.deleted_at')->whereNull('f.deleted_at')
            ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                'ch.enrollment_status', 'ch.enrolled_at', 'f.family_name', 'c.name as centre_name',
                'ch.allergies', 'ch.dietary_restrictions')
            ->orderBy('ch.last_name')->orderBy('ch.first_name')
            ->get();
        $stripJson = fn ($v) => is_string($v) && (str_starts_with($v, '[') || str_starts_with($v, '{'))
            ? collect(json_decode($v, true) ?: [])->map(fn ($e) => is_array($e) ? ($e['allergen'] ?? $e['restriction'] ?? $e['alert'] ?? '') . (isset($e['severity']) ? ' (' . $e['severity'] . ')' : '') : $e)->implode(', ')
            : (string) $v;
        $mapped = $rows->map(fn ($r) => [
            'id' => $r->id, 'name' => $r->first_name . ' ' . $r->last_name,
            'dob' => $r->date_of_birth, 'family' => $r->family_name,
            'centre' => $r->centre_name, 'status' => $r->enrollment_status,
            'enrolled_at' => $r->enrolled_at,
            'allergies' => $stripJson($r->allergies),
            'dietary' => $stripJson($r->dietary_restrictions),
        ]);
        return $this->xlsx->download($agencyId, 'Children', 'children-' . now()->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'ID', 'key' => 'id', 'width' => 8],
                ['header' => 'Name', 'key' => 'name', 'width' => 24],
                ['header' => 'Date of birth', 'key' => 'dob', 'width' => 14, 'format' => 'date'],
                ['header' => 'Family', 'key' => 'family', 'width' => 24],
                ['header' => 'Centre', 'key' => 'centre', 'width' => 22],
                ['header' => 'Status', 'key' => 'status', 'width' => 14],
                ['header' => 'Enrolled at', 'key' => 'enrolled_at', 'width' => 14, 'format' => 'date'],
                ['header' => 'Allergies', 'key' => 'allergies', 'width' => 36],
                ['header' => 'Dietary', 'key' => 'dietary', 'width' => 24],
            ], $mapped);
    }

    public function payroll(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $from = Carbon::parse($request->query('from', Carbon::now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', Carbon::now()->endOfMonth()->toDateString()))->endOfDay();
        $rows = DB::table('time_punches as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'tp.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNotNull('tp.punched_out_at')
            ->whereBetween('tp.punched_in_at', [$from, $to])
            ->select('tp.user_id',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"),
                'u.email', 'c.id as centre_id', 'c.name as centre_name',
                DB::raw('SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) as total_minutes'),
                DB::raw('COUNT(tp.id) as punch_count'))
            ->groupBy('tp.user_id', 'u.first_name', 'u.last_name', 'u.email', 'c.id', 'c.name')
            ->orderBy('u.last_name')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->user_name, 'email' => $r->email,
                'centre' => $r->centre_name, 'punches' => $r->punch_count,
                'minutes' => $r->total_minutes, 'hours' => round($r->total_minutes / 60, 2),
            ]);
        return $this->xlsx->download($agencyId, 'Payroll', 'payroll-' . $from->format('Y-m-d') . '-' . $to->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'Staff', 'key' => 'name', 'width' => 26],
                ['header' => 'Email', 'key' => 'email', 'width' => 28],
                ['header' => 'Centre', 'key' => 'centre', 'width' => 22],
                ['header' => 'Punches', 'key' => 'punches', 'width' => 10],
                ['header' => 'Total minutes', 'key' => 'minutes', 'width' => 14],
                ['header' => 'Total hours', 'key' => 'hours', 'width' => 14],
            ], $rows,
            ['period' => $from->format('M j') . ' – ' . $to->format('M j, Y'),
             'totals_row' => ['name' => 'TOTAL', 'hours' => $rows->sum('hours'), 'minutes' => $rows->sum('minutes'), 'punches' => $rows->sum('punches')]]);
    }

    public function backgroundChecks(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('background_checks as bgc')
            ->join('users as u', 'u.id', '=', 'bgc.user_id')
            ->where('bgc.agency_id', $agencyId)
            ->orderBy('bgc.expires_at')
            ->select(DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"),
                'u.email', 'bgc.check_type', 'bgc.reference', 'bgc.issued_at', 'bgc.expires_at')
            ->get()
            ->map(fn ($r) => [
                'name' => $r->user_name, 'email' => $r->email, 'type' => $r->check_type,
                'reference' => $r->reference, 'issued' => $r->issued_at, 'expires' => $r->expires_at,
                'status' => $r->expires_at && Carbon::parse($r->expires_at)->isPast() ? 'EXPIRED' : 'valid',
            ]);
        return $this->xlsx->download($agencyId, 'Background checks', 'background-checks-' . now()->format('Y-m-d') . '.xlsx',
            [
                ['header' => 'Staff', 'key' => 'name', 'width' => 26],
                ['header' => 'Email', 'key' => 'email', 'width' => 28],
                ['header' => 'Type', 'key' => 'type', 'width' => 14],
                ['header' => 'Reference', 'key' => 'reference', 'width' => 20],
                ['header' => 'Issued', 'key' => 'issued', 'width' => 14, 'format' => 'date'],
                ['header' => 'Expires', 'key' => 'expires', 'width' => 14, 'format' => 'date'],
                ['header' => 'Status', 'key' => 'status', 'width' => 12],
            ], $rows);
    }

    public function cwelccMonthly(Request $request): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $month = $request->query('month', Carbon::now()->format('Y-m'));
        // re-use ComplianceController logic by direct call
        $controller = app(\App\Http\Controllers\Api\ComplianceController::class);
        $reportJson = $controller->cwelccMonthlyReport($request);
        $body = json_decode($reportJson->getContent(), true);
        return $this->xlsx->download($agencyId, 'CWELCC Subsidy', 'CWELCC-' . $month . '.xlsx',
            [
                ['header' => 'Child', 'key' => 'child_name', 'width' => 24],
                ['header' => 'Family', 'key' => 'family_name', 'width' => 24],
                ['header' => 'Centre', 'key' => 'centre_name', 'width' => 22],
                ['header' => 'Gross fee', 'key' => 'gross_fee', 'width' => 14, 'format' => 'money'],
                ['header' => 'Rate %', 'key' => 'subsidy_rate', 'width' => 10],
                ['header' => 'Subsidy', 'key' => 'subsidy_amount', 'width' => 14, 'format' => 'money'],
                ['header' => 'Parent', 'key' => 'parent_portion', 'width' => 14, 'format' => 'money'],
            ], $body['data'],
            ['period' => $month,
             'totals_row' => [
                'child_name' => 'TOTALS (' . $body['totals']['child_count'] . ' children)',
                'gross_fee' => $body['totals']['gross'],
                'subsidy_amount' => $body['totals']['subsidy'],
                'parent_portion' => $body['totals']['parent'],
             ]]);
    }

    public function formResponses(Request $request, int $formId): Response
    {
        $agencyId = $this->resolveAgencyId($request);
        $form = DB::table('custom_forms')->where('id', $formId)->where('agency_id', $agencyId)->first();
        abort_unless($form, 404);
        $schema = json_decode($form->schema, true);
        $responses = DB::table('custom_form_responses as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.submitted_by_id')
            ->where('r.form_id', $formId)
            ->orderByDesc('r.created_at')
            ->select('r.id', 'r.created_at', 'r.answers',
                DB::raw("CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,'')) as submitter"),
                'u.email')
            ->get();
        $fields = collect($schema['fields'] ?? []);
        $columns = [
            ['header' => 'Submitted at', 'key' => 'created_at', 'width' => 20, 'format' => 'datetime'],
            ['header' => 'Submitter', 'key' => 'submitter', 'width' => 22],
            ['header' => 'Email', 'key' => 'email', 'width' => 28],
        ];
        foreach ($fields as $f) {
            $columns[] = ['header' => $f['label'], 'key' => 'f_' . $f['key'], 'width' => 24];
        }
        $rows = $responses->map(function ($r) use ($fields) {
            $answers = json_decode($r->answers, true) ?: [];
            $out = ['created_at' => $r->created_at, 'submitter' => $r->submitter, 'email' => $r->email];
            foreach ($fields as $f) {
                $v = $answers[$f['key']] ?? '';
                if (is_array($v)) $v = implode(', ', $v);
                $out['f_' . $f['key']] = (string) $v;
            }
            return $out;
        });
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($form->title));
        return $this->xlsx->download($agencyId, $form->title . ' — Responses',
            $slug . '-responses-' . now()->format('Y-m-d') . '.xlsx', $columns, $rows);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
