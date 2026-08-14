<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\XlsxExportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Custom report builder. Saved-report objects with filters,
 * columns, sort order, favorites, run count tracking.
 *
 * Report types map to the existing list-data sources (users, families,
 * children, invoices, payments, observations, attendance, etc).
 */
final class ReportsController extends Controller
{
    private const REPORT_TYPES = [
        'users' => 'Users',
        'families' => 'Families',
        'children' => 'Children',
        'invoices' => 'Invoices',
        'payments' => 'Payments',
        'observations' => 'Observations',
        'background_checks' => 'Background checks',
        'time_punches' => 'Time clock punches',
        'tour_bookings' => 'Tour bookings',
    ];

    public function listMine(Request $request): JsonResponse
    {
        $u = $request->user();
        $agencyId = $this->resolveAgencyId($request);
        $rows = DB::table('saved_reports')
            ->where(function ($q) use ($u, $agencyId) {
                $q->where('user_id', $u->id)
                  ->orWhere(function ($q) use ($agencyId) {
                      $q->where('agency_id', $agencyId)->where('is_shared', 1);
                  });
            })
            ->orderByDesc('is_favorite')
            ->orderByDesc('last_run_at')
            ->orderByDesc('created_at')
            ->get();
        $favourites = $rows->where('is_favorite', 1)->take(10)->values();
        $recent = $rows->whereNotNull('last_run_at')->sortByDesc('last_run_at')->take(10)->values();
        return response()->json([
            'data' => $rows,
            'favorites' => $favourites,
            'recent' => $recent,
            'report_types' => self::REPORT_TYPES,
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_type' => 'required|string|in:' . implode(',', array_keys(self::REPORT_TYPES)),
            'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:1000',
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'sort_column' => 'nullable|string|max:60',
            'sort_direction' => 'nullable|in:asc,desc',
            'is_shared' => 'nullable|boolean',
        ]);
        $u = $request->user();
        $agencyId = $this->resolveAgencyId($request);
        $id = DB::table('saved_reports')->insertGetId([
            'agency_id' => $agencyId,
            'user_id' => $u->id,
            'report_type' => $data['report_type'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'filters' => isset($data['filters']) ? json_encode($data['filters']) : null,
            'columns' => isset($data['columns']) ? json_encode($data['columns']) : null,
            'sort_column' => $data['sort_column'] ?? null,
            'sort_direction' => $data['sort_direction'] ?? 'asc',
            'is_shared' => !empty($data['is_shared']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $row = DB::table('saved_reports')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        $data = $request->validate([
            'name' => 'nullable|string|max:120',
            'description' => 'nullable|string|max:1000',
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'sort_column' => 'nullable|string|max:60',
            'sort_direction' => 'nullable|in:asc,desc',
            'is_favorite' => 'nullable|boolean',
            'is_shared' => 'nullable|boolean',
        ]);
        $payload = ['updated_at' => now()];
        foreach (['name', 'description', 'sort_column', 'sort_direction'] as $k) {
            if (array_key_exists($k, $data)) $payload[$k] = $data[$k];
        }
        if (isset($data['filters'])) $payload['filters'] = json_encode($data['filters']);
        if (isset($data['columns'])) $payload['columns'] = json_encode($data['columns']);
        if (isset($data['is_favorite'])) $payload['is_favorite'] = $data['is_favorite'] ? 1 : 0;
        if (isset($data['is_shared'])) $payload['is_shared'] = $data['is_shared'] ? 1 : 0;
        DB::table('saved_reports')->where('id', $id)->update($payload);
        return response()->json(['status' => 'updated']);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('saved_reports')->where('id', $id)->where('user_id', $request->user()->id)->first();
        abort_unless($row, 404);
        DB::table('saved_reports')->where('id', $id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    public function run(Request $request, int $id): JsonResponse
    {
        $row = DB::table('saved_reports')->where('id', $id)
            ->where(function ($q) use ($request) {
                $q->where('user_id', $request->user()->id)
                  ->orWhere('is_shared', 1);
            })->first();
        abort_unless($row, 404);
        DB::table('saved_reports')->where('id', $id)->update([
            'last_run_at' => now(),
            'run_count' => DB::raw('run_count + 1'),
        ]);
        $filters = json_decode($row->filters ?? '{}', true) ?: [];
        $columns = json_decode($row->columns ?? '[]', true) ?: [];
        $data = $this->execute($row->report_type, $filters, $columns, $row->sort_column, $row->sort_direction, $this->resolveAgencyId($request));
        return response()->json([
            'name' => $row->name,
            'report_type' => $row->report_type,
            'columns' => $columns,
            'rows' => $data,
            'count' => $data->count(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'report_type' => 'required|string|in:' . implode(',', array_keys(self::REPORT_TYPES)),
            'filters' => 'nullable|array',
            'columns' => 'nullable|array',
            'sort_column' => 'nullable|string',
            'sort_direction' => 'nullable|in:asc,desc',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $rows = $this->execute(
            $data['report_type'],
            $data['filters'] ?? [],
            $data['columns'] ?? [],
            $data['sort_column'] ?? null,
            $data['sort_direction'] ?? 'asc',
            $agencyId,
            $data['limit'] ?? 100
        );
        return response()->json(['rows' => $rows, 'count' => $rows->count()]);
    }

    private function execute(string $type, array $filters, array $columns, ?string $sortColumn, ?string $sortDir, int $agencyId, int $limit = 5000)
    {
        $q = match ($type) {
            'users' => DB::table('users as u')
                ->join('role_assignments as ra', 'ra.user_id', '=', 'u.id')
                ->where('ra.agency_id', $agencyId)->where('ra.active', 1)
                ->whereNull('u.deleted_at')
                ->select('u.id', 'u.first_name', 'u.last_name', 'u.email', 'u.phone', 'ra.role', 'u.last_login_at', 'u.status'),
            'families' => DB::table('families as f')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)->whereNull('f.deleted_at')
                ->select('f.id', 'f.family_name', 'f.primary_email', 'f.primary_phone',
                    'f.address_line1', 'f.city', 'f.province', 'c.name as centre_name',
                    'f.autopay_enabled', 'f.cwelcc_enrolled', 'f.created_at'),
            'children' => DB::table('children as ch')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)->whereNull('ch.deleted_at')
                ->select('ch.id', 'ch.first_name', 'ch.last_name', 'ch.date_of_birth',
                    'ch.enrollment_status', 'ch.enrolled_at', 'f.family_name', 'c.name as centre_name'),
            'invoices' => DB::table('invoices as i')
                ->join('families as f', 'f.id', '=', 'i.family_id')
                ->join('centres as c', 'c.id', '=', 'i.centre_id')
                ->where('c.agency_id', $agencyId)
                ->select('i.id', 'i.invoice_number', 'i.issued_at', 'i.due_at',
                    'i.total', 'i.amount_paid', 'i.balance_due', 'i.status',
                    'f.family_name', 'c.name as centre_name'),
            'payments' => DB::table('payments as p')
                ->join('families as f', 'f.id', '=', 'p.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)
                ->select('p.id', 'p.paid_at', 'p.amount', 'p.method', 'p.reference_number',
                    'p.status', 'p.stripe_payment_id', 'f.family_name'),
            'observations' => DB::table('observations as o')
                ->join('children as ch', 'ch.id', '=', 'o.child_id')
                ->join('families as f', 'f.id', '=', 'ch.family_id')
                ->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('c.agency_id', $agencyId)
                ->select('o.id', 'o.observed_at', 'o.framework', 'o.domain', 'o.title',
                    DB::raw("CONCAT(ch.first_name,' ',ch.last_name) as child_name")),
            'background_checks' => DB::table('background_checks as bc')
                ->join('users as u', 'u.id', '=', 'bc.user_id')
                ->where('bc.agency_id', $agencyId)
                ->select('bc.id', 'bc.check_type', 'bc.reference', 'bc.issued_at', 'bc.expires_at',
                    DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name")),
            'time_punches' => DB::table('time_punches as tp')
                ->join('users as u', 'u.id', '=', 'tp.user_id')
                ->join('centres as c', 'c.id', '=', 'tp.centre_id')
                ->where('c.agency_id', $agencyId)
                ->select('tp.id', 'tp.punched_in_at', 'tp.punched_out_at',
                    DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"),
                    'c.name as centre_name'),
            'tour_bookings' => DB::table('tour_bookings')
                ->where('agency_id', $agencyId)
                ->select('id', 'parent_name', 'parent_email', 'parent_phone',
                    'tour_at', 'status', 'created_at'),
            default => throw new \RuntimeException('unknown report type'),
        };

        foreach ($filters as $col => $val) {
            if ($val === null || $val === '') continue;
            if (is_array($val) && isset($val['op'])) {
                $op = $val['op'];
                $v = $val['value'] ?? null;
                if ($op === 'between' && is_array($v) && count($v) === 2) {
                    $q->whereBetween($col, $v);
                } elseif ($op === 'in' && is_array($v)) {
                    $q->whereIn($col, $v);
                } elseif (in_array($op, ['=', '!=', '<', '<=', '>', '>=', 'like'], true)) {
                    $q->where($col, $op, $op === 'like' ? "%{$v}%" : $v);
                }
            } else {
                $q->where($col, $val);
            }
        }
        if ($sortColumn) $q->orderBy($sortColumn, $sortDir ?: 'asc');
        return $q->limit($limit)->get();
    }

    // SECURITY (v22p94): the X-Active-Agency-Id header is user-controlled. Only
    // honour it for platform_admin (any agency) or when the user actually holds
    // an active role for that agency — otherwise an admin of one agency could
    // export another agency's full PII/financials by forging the header.
    /* ============================ Canned reports ============================
       Ready-to-run reports with date-range + centre filtering, returned with
       agency + centre branding so the UI can render a branded, zebra-striped,
       printable document. */

    private static function cannedDefs(): array
    {
        return [
            'attendance'  => ['title' => 'Attendance log',        'icon' => '🕘', 'desc' => 'Daily child check-in / check-out for the period.',  'dated' => true],
            'provider_daily' => ['title' => 'Daily overview', 'icon' => '🗓️', 'desc' => 'Per-child daily summary: attendance + meals, naps, diapers, activities.', 'dated' => true],
            'enrollment'  => ['title' => 'Enrollment roster',     'icon' => '👶', 'desc' => 'Every child with status, family and centre.',       'dated' => false],
            'payments'    => ['title' => 'Payments received',     'icon' => '💳', 'desc' => 'Payments collected in the period.',                 'dated' => true],
            'invoices'    => ['title' => 'Invoices & balances',   'icon' => '🧾', 'desc' => 'Invoices issued, amounts paid and owing.',          'dated' => true],
            'families'    => ['title' => 'Family directory',      'icon' => '👪', 'desc' => 'Families with contact and billing details.',        'dated' => false],
            'staff_hours' => ['title' => 'Staff hours',           'icon' => '⏱️', 'desc' => 'Educator clock-in / clock-out hours worked.',       'dated' => true],
            'waitlist'    => ['title' => 'Waitlist',              'icon' => '📝', 'desc' => 'Children waiting for a spot, oldest first.',         'dated' => false],
            'incidents'   => ['title' => 'Incidents & injuries',  'icon' => '🩹', 'desc' => 'Logged incidents / injuries in the period.',        'dated' => true],
            'observations'=> ['title' => 'Observations',          'icon' => '🔭', 'desc' => 'Learning-story observations recorded.',            'dated' => true],
            'tours'       => ['title' => 'Tour bookings',         'icon' => '🚪', 'desc' => 'Prospective-family tour requests.',                 'dated' => true],
        ];
    }

    public function cannedList(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $centres = DB::table('centres')->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->orderBy('name')->get(['id', 'name', 'logo_url', 'brand_color']);
        $reports = [];
        foreach (self::cannedDefs() as $k => $d) {
            $reports[] = ['type' => $k] + $d;
        }
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        return response()->json([
            'reports' => $reports,
            'centres' => $centres,
            'agency'  => $agency ? ['name' => $agency->name, 'logo' => $agency->brand_logo_url ?: $agency->logo_url, 'color' => $agency->brand_primary_color ?: '#1F6080'] : null,
        ]);
    }

    public function canned(Request $request): JsonResponse
    {
        $type = (string) $request->query('type', '');
        $defs = self::cannedDefs();
        abort_unless(isset($defs[$type]), 404, 'Unknown report type.');
        $from = $request->query('from') ?: null;
        $to   = $request->query('to') ?: null;
        $agencyId = $this->resolveAgencyId($request);
        $centreId = $request->query('centre_id') ? (int) $request->query('centre_id') : null;
        if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists()) {
            $centreId = null;
        }

        [$columns, $rows] = $this->cannedRows($type, $agencyId, $centreId, $from, $to);

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;

        return response()->json([
            'type'      => $type,
            'title'     => $defs[$type]['title'],
            'icon'      => $defs[$type]['icon'],
            'date_from' => $from,
            'date_to'   => $to,
            'agency'    => $agency ? ['name' => $agency->name, 'logo' => $agency->brand_logo_url ?: $agency->logo_url, 'color' => $agency->brand_primary_color ?: '#1F6080'] : null,
            'centre'    => $centre ? ['name' => $centre->name, 'logo' => $centre->logo_url, 'color' => $centre->brand_color ?: '#1F6080'] : null,
            'columns'   => $columns,
            'rows'      => $rows,
            'count'     => count($rows),
        ]);
    }

    private function cannedRows(string $type, int $agencyId, ?int $centreId, ?string $from, ?string $to): array
    {
        switch ($type) {
            case 'attendance':
                $q = DB::table('check_events as ce')
                    ->join('rooms as r', 'r.id', '=', 'ce.room_id')
                    ->join('centres as c', 'c.id', '=', 'r.centre_id')
                    ->join('children as ch', 'ch.id', '=', 'ce.child_id')
                    ->leftJoin('users as bu', 'bu.id', '=', 'ce.by_user_id')
                    ->leftJoin('authorized_pickups as ap', 'ap.id', '=', 'ce.by_pickup_id')
                    ->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('ce.occurred_at', '>=', $from);
                if ($to) $q->whereDate('ce.occurred_at', '<=', $to);
                $events = $q->select('ce.child_id', 'ce.event_type', 'ce.occurred_at', 'r.name as room', 'c.name as centre', 'ce.kiosk_source',
                        DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as child"),
                        DB::raw("TRIM(CONCAT(COALESCE(bu.first_name,''),' ',COALESCE(bu.last_name,''))) as by_user"),
                        'ap.full_name as by_pickup')
                    ->orderBy('ce.occurred_at')->limit(4000)->get();
                $byDay = [];
                foreach ($events as $e) {
                    $d = substr((string) $e->occurred_at, 0, 10);
                    $key = $e->child_id . '|' . $d;
                    if (! isset($byDay[$key])) $byDay[$key] = ['Date' => $d, 'Child' => $e->child, 'Room' => $e->room, 'Centre' => $e->centre, 'Check in' => '—', 'Checked in by' => '—', 'Check out' => '—', 'Checked out by' => '—'];
                    $t = date('g:i A', strtotime((string) $e->occurred_at));
                    $who = trim((string) $e->by_user) ?: (trim((string) $e->by_pickup) ?: ($e->kiosk_source ? 'Kiosk' : '—'));
                    if ($e->event_type === 'check_in') {
                        if ($byDay[$key]['Check in'] === '—') { $byDay[$key]['Check in'] = $t; $byDay[$key]['Checked in by'] = $who; }
                    } else {
                        $byDay[$key]['Check out'] = $t; $byDay[$key]['Checked out by'] = $who;
                    }
                }
                $rows = array_values($byDay);
                usort($rows, fn ($a, $b) => [$b['Date'], $a['Child']] <=> [$a['Date'], $b['Child']]);
                return [['Date', 'Child', 'Room', 'Centre', 'Check in', 'Checked in by', 'Check out', 'Checked out by'], $rows];

            case 'enrollment':
                $q = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('c.agency_id', $agencyId)->whereNull('ch.deleted_at');
                if ($centreId) $q->where('c.id', $centreId);
                $data = $q->select('ch.first_name', 'ch.last_name', 'ch.date_of_birth', 'ch.enrollment_status', 'ch.enrolled_at', 'f.family_name', 'c.name as centre')
                    ->orderBy('c.name')->orderBy('ch.first_name')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Child' => trim($r->first_name . ' ' . ($r->last_name ?? '')), 'DOB' => $r->date_of_birth ?: '—',
                    'Status' => ucfirst((string) $r->enrollment_status), 'Family' => $r->family_name, 'Centre' => $r->centre,
                    'Enrolled' => $r->enrolled_at ?: '—',
                ], $data->all());
                return [['Child', 'DOB', 'Status', 'Family', 'Centre', 'Enrolled'], $rows];

            case 'payments':
                $q = DB::table('payments as p')->join('families as f', 'f.id', '=', 'p.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('p.paid_at', '>=', $from);
                if ($to) $q->whereDate('p.paid_at', '<=', $to);
                $data = $q->select('p.paid_at', 'p.amount', 'p.method', 'p.reference_number', 'f.family_name', 'c.name as centre')
                    ->orderByDesc('p.paid_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Date' => $r->paid_at ? substr((string) $r->paid_at, 0, 10) : '—', 'Family' => $r->family_name, 'Centre' => $r->centre,
                    'Amount' => '$' . number_format((float) $r->amount, 2), 'Method' => ucfirst((string) $r->method), 'Reference' => $r->reference_number ?: '—',
                ], $data->all());
                return [['Date', 'Family', 'Centre', 'Amount', 'Method', 'Reference'], $rows];

            case 'invoices':
                $q = DB::table('invoices as i')->join('families as f', 'f.id', '=', 'i.family_id')
                    ->join('centres as c', 'c.id', '=', 'i.centre_id')->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('i.issued_at', '>=', $from);
                if ($to) $q->whereDate('i.issued_at', '<=', $to);
                $data = $q->select('i.invoice_number', 'i.issued_at', 'i.due_at', 'i.total', 'i.amount_paid', 'i.balance_due', 'i.status', 'f.family_name', 'c.name as centre')
                    ->orderByDesc('i.issued_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Invoice' => $r->invoice_number, 'Family' => $r->family_name, 'Centre' => $r->centre,
                    'Issued' => $r->issued_at ? substr((string) $r->issued_at, 0, 10) : '—', 'Due' => $r->due_at ? substr((string) $r->due_at, 0, 10) : '—',
                    'Total' => '$' . number_format((float) $r->total, 2), 'Paid' => '$' . number_format((float) $r->amount_paid, 2),
                    'Balance' => '$' . number_format((float) $r->balance_due, 2), 'Status' => ucfirst((string) $r->status),
                ], $data->all());
                return [['Invoice', 'Family', 'Centre', 'Issued', 'Due', 'Total', 'Paid', 'Balance', 'Status'], $rows];

            case 'families':
                $q = DB::table('families as f')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('c.agency_id', $agencyId)->whereNull('f.deleted_at');
                if ($centreId) $q->where('c.id', $centreId);
                $data = $q->select('f.family_name', 'f.primary_email', 'f.primary_phone', 'f.city', 'c.name as centre', 'f.autopay_enabled')
                    ->orderBy('f.family_name')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Family' => $r->family_name, 'Email' => $r->primary_email ?: '—', 'Phone' => $r->primary_phone ?: '—',
                    'City' => $r->city ?: '—', 'Centre' => $r->centre, 'Autopay' => $r->autopay_enabled ? 'Yes' : 'No',
                ], $data->all());
                return [['Family', 'Email', 'Phone', 'City', 'Centre', 'Autopay'], $rows];

            case 'staff_hours':
                // Educator clock-in/out lives in `time_punches` (the live clock
                // system) — NOT the legacy `time_entries` table, which is why this
                // report was empty. Columns: punched_in_at / punched_out_at.
                $q = DB::table('time_punches as t')->join('users as u', 'u.id', '=', 't.user_id')
                    ->join('centres as c', 'c.id', '=', 't.centre_id')->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('t.punched_in_at', '>=', $from);
                if ($to) $q->whereDate('t.punched_in_at', '<=', $to);
                $data = $q->select('t.punched_in_at', 't.punched_out_at', 'c.name as centre',
                        DB::raw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as staff"))
                    ->orderByDesc('t.punched_in_at')->limit(5000)->get();
                $rows = array_map(function ($r) {
                    if ($r->punched_out_at) {
                        $mins = (strtotime((string) $r->punched_out_at) - strtotime((string) $r->punched_in_at)) / 60;
                        $hrs = number_format(max(0, $mins) / 60, 2) . ' h';
                    } else {
                        $hrs = 'On floor';
                    }
                    return [
                        'Staff' => $r->staff, 'Centre' => $r->centre, 'Date' => substr((string) $r->punched_in_at, 0, 10),
                        'Clock in' => date('g:i A', strtotime((string) $r->punched_in_at)),
                        'Clock out' => $r->punched_out_at ? date('g:i A', strtotime((string) $r->punched_out_at)) : '—',
                        'Hours' => $hrs,
                    ];
                }, $data->all());
                return [['Staff', 'Centre', 'Date', 'Clock in', 'Clock out', 'Hours'], $rows];

            case 'provider_daily':
                // Provider Daily Overview report — per child per day: attendance
                // window plus a tally of care logged (meals, naps, diapers, activities).
                $ceq = DB::table('check_events as ce')
                    ->join('rooms as r', 'r.id', '=', 'ce.room_id')
                    ->join('centres as c', 'c.id', '=', 'r.centre_id')
                    ->join('children as ch', 'ch.id', '=', 'ce.child_id')
                    ->where('c.agency_id', $agencyId);
                if ($centreId) $ceq->where('c.id', $centreId);
                if ($from) $ceq->whereDate('ce.occurred_at', '>=', $from);
                if ($to) $ceq->whereDate('ce.occurred_at', '<=', $to);
                $ceRows = $ceq->select('ce.child_id', 'ce.event_type', 'ce.occurred_at', 'c.name as centre',
                        DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as child"))
                    ->orderBy('ce.occurred_at')->limit(6000)->get();

                $days = [];
                foreach ($ceRows as $e) {
                    $d = substr((string) $e->occurred_at, 0, 10);
                    $key = $e->child_id . '|' . $d;
                    if (! isset($days[$key])) {
                        $days[$key] = ['Date' => $d, 'Child' => $e->child, 'Centre' => $e->centre, '_cid' => $e->child_id,
                            'Check in' => '—', 'Check out' => '—', 'Meals' => 0, 'Naps' => 0, 'Diapers' => 0, 'Activities' => 0];
                    }
                    $t = date('g:i A', strtotime((string) $e->occurred_at));
                    if ($e->event_type === 'check_in') { if ($days[$key]['Check in'] === '—') $days[$key]['Check in'] = $t; }
                    else { $days[$key]['Check out'] = $t; }
                }
                // Tally care events (daily_events) for the same child-days.
                $deq = DB::table('daily_events as de')
                    ->join('children as ch', 'ch.id', '=', 'de.child_id')
                    ->join('families as f', 'f.id', '=', 'ch.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('c.agency_id', $agencyId)
                    ->whereNull('de.deleted_at');
                if ($centreId) $deq->where('c.id', $centreId);
                if ($from) $deq->whereDate('de.occurred_at', '>=', $from);
                if ($to) $deq->whereDate('de.occurred_at', '<=', $to);
                $deRows = $deq->select('de.child_id', 'de.event_type', 'de.occurred_at')->limit(20000)->get();
                foreach ($deRows as $e) {
                    $key = $e->child_id . '|' . substr((string) $e->occurred_at, 0, 10);
                    if (! isset($days[$key])) continue; // only annotate days the child was present
                    switch ($e->event_type) {
                        case 'meal': case 'snack': $days[$key]['Meals']++; break;
                        case 'nap_start': $days[$key]['Naps']++; break;
                        case 'diaper': $days[$key]['Diapers']++; break;
                        case 'activity': $days[$key]['Activities']++; break;
                    }
                }
                // The OTHER care table. The care screen writes daily_care_logs while the
                // roster quick-log writes daily_events; counting only the second has been
                // under-stating every one of these columns. Report cards had the same
                // fault and the same fix.
                if (\Illuminate\Support\Facades\Schema::hasTable('daily_care_logs')) {
                    $clq = DB::table('daily_care_logs as cl')
                        ->join('children as ch', 'ch.id', '=', 'cl.child_id')
                        ->join('families as f', 'f.id', '=', 'ch.family_id')
                        ->join('centres as c', 'c.id', '=', 'f.centre_id')
                        ->where('c.agency_id', $agencyId);
                    if ($centreId) $clq->where('c.id', $centreId);
                    if ($from) $clq->whereDate('cl.occurred_at', '>=', $from);
                    if ($to) $clq->whereDate('cl.occurred_at', '<=', $to);
                    $clRows = $clq->select('cl.child_id', 'cl.log_type', 'cl.occurred_at')->limit(20000)->get();
                    foreach ($clRows as $e) {
                        $key = $e->child_id . '|' . substr((string) $e->occurred_at, 0, 10);
                        if (! isset($days[$key])) continue;   // same rule: only days the child was present
                        switch ($e->log_type) {
                            case 'meal': case 'snack': case 'bottle': $days[$key]['Meals']++; break;
                            case 'nap': $days[$key]['Naps']++; break;
                            case 'diaper': case 'bathroom': $days[$key]['Diapers']++; break;
                            case 'sunscreen': $days[$key]['Activities']++; break;
                        }
                    }
                }

                $rows = array_map(function ($r) {
                    unset($r['_cid']);
                    return $r;
                }, array_values($days));
                usort($rows, fn ($a, $b) => [$b['Date'], $a['Child']] <=> [$a['Date'], $b['Child']]);
                return [['Date', 'Child', 'Centre', 'Check in', 'Check out', 'Meals', 'Naps', 'Diapers', 'Activities'], $rows];

            case 'waitlist':
                $q = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
                    ->join('centres as c', 'c.id', '=', 'f.centre_id')->where('c.agency_id', $agencyId)
                    ->where('ch.enrollment_status', 'waitlist')->whereNull('ch.deleted_at');
                if ($centreId) $q->where('c.id', $centreId);
                $data = $q->select('ch.first_name', 'ch.last_name', 'ch.date_of_birth', 'ch.applied_at', 'ch.expected_start_date', 'f.family_name', 'c.name as centre')
                    ->orderBy('ch.applied_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Child' => trim($r->first_name . ' ' . ($r->last_name ?? '')), 'DOB' => $r->date_of_birth ?: '—',
                    'Family' => $r->family_name, 'Centre' => $r->centre,
                    'Applied' => $r->applied_at ?: '—', 'Preferred start' => $r->expected_start_date ?: '—',
                ], $data->all());
                return [['Child', 'DOB', 'Family', 'Centre', 'Applied', 'Preferred start'], $rows];

            case 'incidents':
                $q = DB::table('incidents as inc')->join('children as ch', 'ch.id', '=', 'inc.child_id')
                    ->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('inc.occurred_at', '>=', $from);
                if ($to) $q->whereDate('inc.occurred_at', '<=', $to);
                $data = $q->select('inc.occurred_at', 'inc.incident_type', 'inc.severity', 'inc.location', 'inc.status',
                        DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as child"), 'c.name as centre')
                    ->orderByDesc('inc.occurred_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Date' => $r->occurred_at ? substr((string) $r->occurred_at, 0, 10) : '—', 'Child' => $r->child, 'Centre' => $r->centre,
                    'Type' => ucfirst(str_replace('_', ' ', (string) $r->incident_type)), 'Severity' => ucfirst((string) $r->severity),
                    'Location' => $r->location ?: '—', 'Status' => ucfirst((string) ($r->status ?: 'open')),
                ], $data->all());
                return [['Date', 'Child', 'Centre', 'Type', 'Severity', 'Location', 'Status'], $rows];

            case 'observations':
                $q = DB::table('observations as o')->join('children as ch', 'ch.id', '=', 'o.child_id')
                    ->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                    ->leftJoin('users as u', 'u.id', '=', 'o.recorded_by_id')->where('c.agency_id', $agencyId);
                if ($centreId) $q->where('c.id', $centreId);
                if ($from) $q->whereDate('o.observed_at', '>=', $from);
                if ($to) $q->whereDate('o.observed_at', '<=', $to);
                $data = $q->select('o.observed_at', 'o.domain', 'o.title', 'c.name as centre',
                        DB::raw("TRIM(CONCAT(ch.first_name,' ',COALESCE(ch.last_name,''))) as child"),
                        DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as educator"))
                    ->orderByDesc('o.observed_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Date' => $r->observed_at ? substr((string) $r->observed_at, 0, 10) : '—', 'Child' => $r->child,
                    'Domain' => ucfirst(str_replace('_', ' ', (string) $r->domain)), 'Title' => $r->title,
                    'Centre' => $r->centre, 'Educator' => trim((string) $r->educator) ?: '—',
                ], $data->all());
                return [['Date', 'Child', 'Domain', 'Title', 'Centre', 'Educator'], $rows];

            case 'tours':
                $q = DB::table('tour_bookings as t')->leftJoin('centres as c', 'c.id', '=', 't.centre_id')
                    ->where('t.agency_id', $agencyId);
                if ($centreId) $q->where('t.centre_id', $centreId);
                if ($from) $q->whereDate('t.tour_at', '>=', $from);
                if ($to) $q->whereDate('t.tour_at', '<=', $to);
                $data = $q->select('t.tour_at', 't.parent_name', 't.parent_email', 't.parent_phone', 't.child_age_months', 't.status', 'c.name as centre')
                    ->orderByDesc('t.tour_at')->limit(5000)->get();
                $rows = array_map(fn ($r) => [
                    'Tour date' => $r->tour_at ? substr((string) $r->tour_at, 0, 10) : '—', 'Parent' => $r->parent_name ?: '—',
                    'Email' => $r->parent_email ?: '—', 'Phone' => $r->parent_phone ?: '—',
                    'Child age (mo)' => $r->child_age_months ?: '—', 'Centre' => $r->centre ?: '—',
                    'Status' => ucfirst((string) ($r->status ?: 'requested')),
                ], $data->all());
                return [['Tour date', 'Parent', 'Email', 'Phone', 'Child age (mo)', 'Centre', 'Status'], $rows];
        }
        return [[], []];
    }

    public function cannedPdf(Request $request)
    {
        $type = (string) $request->query('type', '');
        $defs = self::cannedDefs();
        abort_unless(isset($defs[$type]), 404, 'Unknown report type.');
        $from = $request->query('from') ?: null;
        $to   = $request->query('to') ?: null;
        $agencyId = $this->resolveAgencyId($request);
        $centreId = $request->query('centre_id') ? (int) $request->query('centre_id') : null;
        if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists()) {
            $centreId = null;
        }
        [$columns, $rows] = $this->cannedRows($type, $agencyId, $centreId, $from, $to);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;
        $html = $this->cannedHtml($defs[$type], $columns, $rows, $agency, $centre, $from, $to, $this->producedBy($request));

        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $type . '-' . date('Y-m-d') . '.pdf"',
        ]);
    }

    public function cannedXlsx(Request $request, XlsxExportService $xlsx)
    {
        $type = (string) $request->query('type', '');
        $defs = self::cannedDefs();
        abort_unless(isset($defs[$type]), 404, 'Unknown report type.');
        $from = $request->query('from') ?: null;
        $to   = $request->query('to') ?: null;
        $agencyId = $this->resolveAgencyId($request);
        $centreId = $request->query('centre_id') ? (int) $request->query('centre_id') : null;
        if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists()) {
            $centreId = null;
        }
        [$columns, $rows] = $this->cannedRows($type, $agencyId, $centreId, $from, $to);
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;

        // Column headers double as row keys (cannedRows keys rows by display header).
        $cols = array_map(fn ($c) => ['header' => $c, 'key' => $c], $columns);
        $title = $defs[$type]['title']
            . ($centre ? ' — ' . $centre->name : '')
            . (($from || $to) ? ' (' . ($from ?: '…') . ' to ' . ($to ?: '…') . ')' : '');
        $filename = $type . '-' . date('Y-m-d') . '.xlsx';

        return $xlsx->download($agencyId, $title, $filename, $cols, $rows, ['generated_by' => $this->producedBy($request)]);
    }

    /**
     * Generic table export used by the bottom CSV/PDF/Excel bar on any data
     * table. The frontend posts the table's headers + rows; we render a branded,
     * confidential .xlsx (PhpSpreadsheet) or .pdf (dompdf) — same look as the
     * canned reports.
     */
    public function tableExport(Request $request, XlsxExportService $xlsx)
    {
        $data = $request->validate([
            'title'   => 'nullable|string|max:140',
            'format'  => 'required|in:xlsx,pdf',
            'columns' => 'required|array|min:1|max:40',
            'rows'    => 'nullable|array|max:5000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $columns  = array_values(array_map(fn ($h) => (string) $h, $data['columns']));
        $title    = trim($data['title'] ?? '') ?: 'Report';
        $keyed = [];
        foreach (($data['rows'] ?? []) as $row) {
            $row = array_values((array) $row);
            $r = [];
            foreach ($columns as $i => $h) { $r[$h] = isset($row[$i]) ? (string) $row[$i] : ''; }
            $keyed[] = $r;
        }
        $base = trim(preg_replace('/[^a-z0-9]+/i', '-', strtolower($title)), '-') ?: 'report';

        if ($data['format'] === 'xlsx') {
            $cols = array_map(fn ($h) => ['header' => $h, 'key' => $h], $columns);
            return $xlsx->download($agencyId, $title, $base . '-' . date('Y-m-d') . '.xlsx', $cols, $keyed, ['generated_by' => $this->producedBy($request)]);
        }

        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $html = $this->cannedHtml(['title' => $title, 'icon' => '📄'], $columns, $keyed, $agency, null, null, null, $this->producedBy($request));
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();
        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $base . '-' . date('Y-m-d') . '.pdf"',
        ]);
    }

    /**
     * Build a canned report as PDF and/or CSV bytes for out-of-request use
     * (scheduled email delivery). Reuses the exact same data + branded layout
     * as the interactive canned reports.
     *
     * @return array{ok:bool,title:string,count:int,pdf:?string,csv:?string,filename_base:string}
     */
    public function buildScheduledReport(int $agencyId, string $type, ?int $centreId, ?string $from, ?string $to, string $by = 'Scheduled report'): array
    {
        $defs = self::cannedDefs();
        if (! isset($defs[$type])) {
            return ['ok' => false, 'title' => $type, 'count' => 0, 'pdf' => null, 'csv' => null, 'filename_base' => $type];
        }
        if ($centreId && ! DB::table('centres')->where('id', $centreId)->where('agency_id', $agencyId)->exists()) {
            $centreId = null;
        }
        [$columns, $rows] = $this->cannedRows($type, $agencyId, $centreId, $from, $to);
        $agency = DB::table('agencies')->where('id', $agencyId)->first();
        $centre = $centreId ? DB::table('centres')->where('id', $centreId)->first() : null;

        // PDF (same HTML + dompdf as cannedPdf)
        $html = $this->cannedHtml($defs[$type], $columns, $rows, $agency, $centre, $from, $to, $by);
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true, 'defaultFont' => 'DejaVu Sans']);
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->loadHtml($html);
        $dompdf->render();
        $pdf = $dompdf->output();

        // CSV (rows are keyed by display header)
        $enc = fn ($v) => '"' . str_replace('"', '""', (string) $v) . '"';
        $csv = implode(',', array_map($enc, $columns)) . "\r\n";
        foreach ($rows as $r) {
            $csv .= implode(',', array_map(fn ($c) => $enc($r[$c] ?? ''), $columns)) . "\r\n";
        }

        return [
            'ok' => true,
            'title' => $defs[$type]['title'],
            'count' => count($rows),
            'pdf' => $pdf,
            'csv' => $csv,
            'filename_base' => $type . '-' . date('Y-m-d'),
        ];
    }

    private function producedBy(Request $request): string
    {
        $u = $request->user();
        if (! $u) return 'a signed-in user';
        $name = trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? ''));
        return $name !== '' ? $name : ($u->email ?? 'a signed-in user');
    }

    private function cannedHtml(array $def, array $columns, array $rows, $agency, $centre, ?string $from, ?string $to, string $by = ''): string
    {
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES);
        $color = ($agency && $agency->brand_primary_color) ? $agency->brand_primary_color : '#1F6080';
        $range = ($from || $to) ? (($from ?: '…') . ' to ' . ($to ?: '…')) : 'All dates';
        // Embed the agency's white-label logo as a base64 data URI read from the
        // local file — dompdf can't reliably fetch the relative /storage/... URL,
        // which is why logos were missing from the PDF. Falls back to an absolute
        // URL (dompdf has isRemoteEnabled) if the local file can't be located.
        $logoHtml = '';
        if ($agency) {
            $logo = $agency->brand_logo_url ?: $agency->logo_url;
            if ($logo) {
                $src = null;
                $rel = ltrim(parse_url($logo, PHP_URL_PATH) ?: $logo, '/');
                foreach ([public_path($rel), storage_path('app/public/' . preg_replace('#^storage/#', '', $rel))] as $fp) {
                    if (@is_file($fp) && ($bin = @file_get_contents($fp)) !== false) {
                        $mime = function_exists('mime_content_type') ? (@mime_content_type($fp) ?: 'image/png') : 'image/png';
                        $src = 'data:' . $mime . ';base64,' . base64_encode($bin);
                        break;
                    }
                }
                if (! $src) {
                    $src = (strpos($logo, 'http') === 0) ? $logo : ('https://app.kiddietrac.com' . ($logo[0] === '/' ? '' : '/') . $logo);
                }
                $logoHtml = '<img src="' . $e($src) . '" style="height:38px;vertical-align:middle;margin-right:8px;">';
            }
        }
        $th = '';
        foreach ($columns as $c) $th .= '<th>' . $e($c) . '</th>';
        $tr = '';
        if ($rows) {
            $i = 0;
            foreach ($rows as $row) {
                $bg = ($i % 2) ? '#F5F8FB' : '#FFFFFF';
                $i++;
                $tds = '';
                foreach ($columns as $c) $tds .= '<td>' . $e($row[$c] ?? '') . '</td>';
                $tr .= '<tr style="background:' . $bg . ';">' . $tds . '</tr>';
            }
        } else {
            $tr = '<tr><td colspan="' . count($columns) . '" style="text-align:center;padding:18px;color:#888;">No records for this selection.</td></tr>';
        }
        return '<html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:10px;color:#1E293B;}'
            . '.hd{border-bottom:3px solid ' . $color . ';padding-bottom:6px;margin-bottom:8px;}'
            . '.name{font-size:15px;font-weight:bold;color:#0D1B2A;}'
            . '.sub{font-size:10px;color:#64748B;}'
            . 'table.data{width:100%;border-collapse:collapse;}'
            . 'table.data th{background:' . $color . ';color:#fff;text-align:left;padding:5px 7px;font-size:8.5px;text-transform:uppercase;}'
            . 'table.data td{padding:4px 7px;border-bottom:1px solid #E9EEF3;font-size:9px;}'
            . '</style></head><body>'
            . '<table style="width:100%;border:0;" class="hd"><tr>'
            . '<td style="border:0;">' . $logoHtml . '<span class="name">' . $e($agency->name ?? 'Agency') . '</span>'
            . '<div class="sub">' . $e($def['icon'] . ' ' . $def['title']) . '</div></td>'
            . '<td style="border:0;text-align:right;" class="sub">' . $e($centre ? $centre->name : 'All centres')
            . '<br>' . $e($range) . '<br>Generated ' . date('Y-m-d') . ' &middot; ' . count($rows) . ' rows</td>'
            . '</tr></table>'
            . '<table class="data"><thead><tr>' . $th . '</tr></thead><tbody>' . $tr . '</tbody></table>'
            . '<div style="margin-top:10px;border-top:1px solid #E5E9F0;padding-top:6px;font-size:8.5px;color:#64748B;">'
            . 'Generated ' . date('F j, Y \\a\\t g:i A') . ($by ? ' by ' . $e($by) : '') . ' &middot; ' . $e($agency->name ?? '')
            . '<br><span style="color:#B91C1C;font-weight:bold;">PRIVATE &amp; CONFIDENTIAL</span> — Contains sensitive information. Handle securely and do not distribute without authorisation.'
            . '</div>'
            . '</body></html>';
    }

    private function resolveAgencyId(Request $request): int
    {
        $user = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform) {
            if ($activeId && DB::table('agencies')->where('id', $activeId)->whereNull('deleted_at')->exists()) return $activeId;
            $f = (int) DB::table('agencies')->whereNull('deleted_at')->orderBy('id')->value('id');
            abort_unless($f, 400);
            return $f;
        }
        if ($activeId) {
            $belongs = DB::table('role_assignments')->where('user_id', $user->id)->where('active', true)->where('agency_id', $activeId)->exists()
                || DB::table('role_assignments')->where('role_assignments.user_id', $user->id)->where('role_assignments.active', true)
                    ->join('centres', 'centres.id', '=', 'role_assignments.centre_id')->where('centres.agency_id', $activeId)->exists();
            if ($belongs) return $activeId;
        }
        $first = DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
