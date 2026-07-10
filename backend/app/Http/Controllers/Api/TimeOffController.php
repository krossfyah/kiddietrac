<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgencyMailer;
use App\Services\EmailTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p51 — Time-off request workflow.
 * Staff submit; centre director / agency_admin approve / decline.
 * Approval auto-creates a "blocked" calendar marker so shifts can't be
 * scheduled over approved time off (handled in SchedulingController).
 */
final class TimeOffController extends Controller
{
    public function mine(Request $request): JsonResponse
    {
        $rows = DB::table('time_off_requests')
            ->where('user_id', $request->user()->id)
            ->orderByDesc('start_at')
            ->get();
        return response()->json(['data' => $rows]);
    }

    public function listAgency(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        $status = $request->query('status');
        $q = DB::table('time_off_requests as tor')
            ->join('users as u', 'u.id', '=', 'tor.user_id')
            ->where('tor.agency_id', $agencyId)
            ->orderByDesc('tor.start_at')
            ->select(
                'tor.*', DB::raw("CONCAT(u.first_name, ' ', u.last_name) as user_name"), 'u.email as user_email'
            );
        if ($status) $q->where('tor.status', $status);
        return response()->json(['data' => $q->get()]);
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_type'  => 'required|string|in:vacation,sick,personal,bereavement,jury,other',
            'start_at'      => 'required|date',
            'end_at'        => 'required|date|after_or_equal:start_at',
            'reason'        => 'nullable|string|max:2000',
            'centre_id'     => 'nullable|integer',
        ]);
        $user = $request->user();
        $agencyId = $this->resolveAgencyId($request);

        $id = DB::table('time_off_requests')->insertGetId([
            'agency_id'    => $agencyId,
            'user_id'      => $user->id,
            'centre_id'    => $data['centre_id'] ?? null,
            'request_type' => $data['request_type'],
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'reason'       => $data['reason'] ?? null,
            'status'       => 'pending',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        // notify centre director + agency admins
        $approverIds = DB::table('role_assignments')
            ->where('agency_id', $agencyId)
            ->whereIn('role', ['centre_director', 'agency_admin'])
            ->where('active', true)
            ->pluck('user_id')->unique()->all();
        foreach ($approverIds as $aid) {
            DB::table('notifications')->insert([
                'user_id'    => $aid,
                'type'       => 'time_off',
                'title'      => 'New time-off request from ' . $user->name,
                'body'       => $data['request_type'] . ' · '
                                . Carbon::parse($data['start_at'])->format('M j') . ' – '
                                . Carbon::parse($data['end_at'])->format('M j'),
                'data' => json_encode(['link' => '#time-off']),
                'created_at' => now(),
            ]);
        }

        return response()->json(['id' => $id, 'status' => 'pending'], 201);
    }

    public function decide(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => 'required|string|in:approved,denied',
            'decision_notes' => 'nullable|string|max:1000',
        ]);
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        $this->assertAgencyAccess($request, (int) $row->agency_id);

        DB::table('time_off_requests')->where('id', $id)->update([
            'status'        => $data['status'],
            'decided_by_id' => $request->user()->id,
            'decided_at'    => now(),
            'decision_notes'=> $data['decision_notes'] ?? null,
            'updated_at'    => now(),
        ]);

        DB::table('notifications')->insert([
            'user_id'    => $row->user_id,
            'type'       => 'time_off',
            'title'      => 'Your time-off request was ' . $data['status'],
            'body'       => Carbon::parse($row->start_at)->format('M j') . ' – '
                            . Carbon::parse($row->end_at)->format('M j')
                            . (!empty($data['decision_notes']) ? ' · ' . $data['decision_notes'] : ''),
            'data' => json_encode(['link' => '#time-off']),
            'created_at' => now(),
        ]);

        return response()->json(['status' => $data['status']]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $row = DB::table('time_off_requests')->where('id', $id)->first();
        abort_unless($row, 404);
        abort_unless((int) $row->user_id === (int) $request->user()->id, 403, 'Only the requester can cancel');
        abort_unless($row->status === 'pending', 422, 'Cannot cancel a decided request');
        DB::table('time_off_requests')->where('id', $id)->update([
            'status' => 'cancelled', 'updated_at' => now(),
        ]);
        return response()->json(['status' => 'cancelled']);
    }

    private function resolveAgencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
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
