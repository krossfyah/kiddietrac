<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p51 — Payroll-ready time-clock export.
 * Aggregates time_punches table into a per-staff total hours table for
 * a given pay-period range; supports CSV download.
 */
final class PayrollController extends Controller
{
    public function summary(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);
        $from = Carbon::parse($request->query('from', Carbon::now()->startOfMonth()->toDateString()))->startOfDay();
        $to = Carbon::parse($request->query('to', Carbon::now()->endOfMonth()->toDateString()))->endOfDay();

        $rows = DB::table('time_punches as tp')
            ->join('users as u', 'u.id', '=', 'tp.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'tp.centre_id')
            ->where('c.agency_id', $agencyId)
            ->whereNotNull('tp.punched_out_at')
            ->whereBetween('tp.punched_in_at', [$from, $to])
            ->select(
                'tp.user_id', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email',
                'c.id as centre_id', 'c.name as centre_name',
                DB::raw('SUM(TIMESTAMPDIFF(MINUTE, tp.punched_in_at, tp.punched_out_at)) as total_minutes'),
                DB::raw('COUNT(tp.id) as punch_count')
            )
            ->groupBy('tp.user_id', 'u.first_name', 'u.last_name', 'u.email', 'c.id', 'c.name')
            ->orderBy('u.first_name')
            ->get();

        $rows->transform(function ($r) {
            $mins = (int) $r->total_minutes;
            $r->total_hours = round($mins / 60, 2);
            $r->total_minutes = $mins;
            return $r;
        });

        if ($request->query('format') === 'csv') return $this->csv($rows, $from, $to);
        return response()->json([
            'data' => $rows,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'total_hours' => round($rows->sum('total_hours'), 2),
        ]);
    }

    private function csv($rows, Carbon $from, Carbon $to): StreamedResponse
    {
        $name = 'payroll-' . $from->format('Y-m-d') . '-to-' . $to->format('Y-m-d') . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Staff name', 'Email', 'Centre', 'Punch count', 'Total minutes', 'Total hours']);
            foreach ($rows as $r) {
                fputcsv($h, [
                    $r->user_name, $r->user_email, $r->centre_name ?? '',
                    $r->punch_count, $r->total_minutes, $r->total_hours,
                ]);
            }
            fclose($h);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
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

    private function assertAgencyAccess(Request $request, int $agencyId): void
    {
        $u = $request->user();
        $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) return;
        $hasRole = DB::table('role_assignments')->where('user_id', $u->id)
            ->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director'])
            ->where('active', true)->exists();
        abort_unless($hasRole, 403);
    }
}
