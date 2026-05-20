<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId) return $activeId;
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)->where('active', true)
            ->value('agency_id');
        abort_unless($first, 400);
        return (int) $first;
    }
}
