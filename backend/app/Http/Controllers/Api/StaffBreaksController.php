<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StaffBreaks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Payroll settings → Lunch and breaks (2026-11-05): whether an educator's lunch and short
 * breaks are paid. See App\Support\StaffBreaks. Agency admins of the active agency and
 * platform admins - the same gate as SummaryTimingController.
 */
class StaffBreaksController extends Controller
{
    private function agencyId(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
                ->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere(fn ($w) => $w->where('agency_id', $header)->where('role', 'agency_admin')))->exists()) {
            return $header;
        }
        $own = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'agency_admin')->value('agency_id');
        abort_unless($own, 403, 'Agency administrators only.');

        return (int) $own;
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['settings' => StaffBreaks::read($this->agencyId($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $data = $request->validate([
            'lunch_paid' => ['sometimes', 'boolean'],
            'break_paid' => ['sometimes', 'boolean'],
            'overrun_enabled' => ['sometimes', 'boolean'],
            'lunch_limit' => ['sometimes', 'integer', 'min:5', 'max:240'],
            'break_limit' => ['sometimes', 'integer', 'min:5', 'max:120'],
            'overrun_escalate_after' => ['sometimes', 'integer', 'min:0', 'max:120'],
            'overrun_notify_directors' => ['sometimes', 'boolean'],
            'overrun_notify_admins' => ['sometimes', 'boolean'],
        ]);
        $before = StaffBreaks::read($a);
        $s = StaffBreaks::write($a, array_replace($before, $data));
        $say = fn (bool $paid) => $paid ? 'paid' : 'unpaid';
        $over = fn (array $x) => $x['overrun_enabled']
            ? 'overrun alert after ' . $x['lunch_limit'] . ' min lunch / ' . $x['break_limit'] . ' min break, managers ' . $x['overrun_escalate_after'] . ' min later'
                . ' (' . implode(' + ', array_filter([$x['overrun_notify_directors'] ? 'directors' : null, $x['overrun_notify_admins'] ? 'admins' : null]) ?: ['nobody']) . ')'
            : 'overrun alerts off';
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'staff.breaks_settings', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Lunch and breaks: lunch ' . $say($s['lunch_paid']) . ', breaks ' . $say($s['break_paid']) . '; ' . $over($s)
                    . ' (was lunch ' . $say($before['lunch_paid']) . ', breaks ' . $say($before['break_paid']) . '; ' . $over($before) . ')',
                    'before' => $before, 'after' => $s]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['settings' => $s]);
    }
}
