<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * v22p51 — Background-check / VSS expiry tracking.
 * Mirrors the certifications pattern: rows expire, dashboard surfaces
 * red/amber rows, daily-digest email warns about upcoming expiries.
 */
final class BackgroundCheckController extends Controller
{
    public function listAgency(Request $request)
    {
        $agencyId = $this->resolveAgencyId($request);
        if ($request->query('format') === 'csv') return $this->csv($agencyId);

        $rows = DB::table('background_checks as bgc')
            ->join('users as u', 'u.id', '=', 'bgc.user_id')
            ->where('bgc.agency_id', $agencyId)
            ->orderBy('bgc.expires_at')
            ->select('bgc.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email')
            ->get();

        $now = Carbon::now()->startOfDay();
        $rows->transform(function ($r) use ($now) {
            $exp = Carbon::parse($r->expires_at);
            $days = (int) $exp->diffInDays($now, false);
            $r->status_bucket = $exp->isPast()
                ? 'expired'
                : ($days >= -60 ? 'expiring' : 'valid');
            $r->days_until_expiry = $exp->isPast() ? -abs($days) : abs($days);
            return $r;
        });
        return response()->json(['data' => $rows]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'           => 'nullable|integer',
            'user_id'      => 'required|integer',
            'check_type'   => 'required|string|in:vss,criminal,driver,reference,other',
            'reference'    => 'nullable|string|max:120',
            'issued_at'    => 'nullable|date',
            'expires_at'   => 'required|date',
            'document_url' => 'nullable|string|max:500',
            'notes'        => 'nullable|string|max:2000',
        ]);
        $agencyId = $this->resolveAgencyId($request);
        $this->assertAgencyAccess($request, $agencyId);

        $payload = [
            'agency_id'    => $agencyId,
            'user_id'      => $data['user_id'],
            'check_type'   => $data['check_type'],
            'reference'    => $data['reference'] ?? null,
            'issued_at'    => $data['issued_at'] ?? null,
            'expires_at'   => $data['expires_at'],
            'document_url' => $data['document_url'] ?? null,
            'notes'        => $data['notes'] ?? null,
            'updated_at'   => now(),
        ];
        if (!empty($data['id'])) {
            DB::table('background_checks')->where('id', $data['id'])->update($payload);
            return response()->json(['id' => (int) $data['id'], 'status' => 'updated']);
        }
        $payload['created_at'] = now();
        $id = DB::table('background_checks')->insertGetId($payload);
        return response()->json(['id' => $id, 'status' => 'created'], 201);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $row = DB::table('background_checks')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);
        DB::table('background_checks')->where('id', $id)->delete();
        return response()->json(['status' => 'deleted']);
    }

    private function csv(int $agencyId): StreamedResponse
    {
        $name = 'background-checks-' . now()->format('Y-m-d') . '.csv';
        return response()->streamDownload(function () use ($agencyId) {
            $h = fopen('php://output', 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, ['Staff name', 'Email', 'Check type', 'Reference', 'Issued', 'Expires', 'Status']);
            DB::table('background_checks as bgc')
                ->join('users as u', 'u.id', '=', 'bgc.user_id')
                ->where('bgc.agency_id', $agencyId)
                ->orderBy('bgc.expires_at')
                ->select('bgc.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email')
                ->chunk(200, function ($rows) use ($h) {
                    foreach ($rows as $r) {
                        $status = Carbon::parse($r->expires_at)->isPast() ? 'expired' : 'valid';
                        fputcsv($h, [
                            $r->user_name, $r->user_email, $r->check_type, $r->reference,
                            $r->issued_at, $r->expires_at, $status,
                        ]);
                    }
                });
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
        abort_unless($first, 400, 'No agency context');
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
