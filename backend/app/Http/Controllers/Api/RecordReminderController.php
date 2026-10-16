<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CentreScope;
use App\Support\RecordReminders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Room Controls → Daily records (2026-10-15). Admins edit; directors read
 * the settings and see the reminders for their own centres.
 */
final class RecordReminderController extends Controller
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
        abort_unless($id, 403, 'No agency access.');

        return $id;
    }

    private function canEdit(Request $request, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(fn ($w) => $w->where('role', 'platform_admin')->orWhere(fn ($x) => $x->where('role', 'agency_admin')->where('agency_id', $agencyId)))
            ->exists();
    }

    /** GET /admin/record-reminders */
    public function show(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('record_reminders as r')->join('users as u', 'u.id', '=', 'r.user_id')->leftJoin('centres as c', 'c.id', '=', 'r.centre_id')
            ->where('r.agency_id', $agencyId)->where('r.for_date', '>=', now()->subDays(14)->toDateString())
            ->whereRaw("JSON_LENGTH(COALESCE(r.missing, '[]')) > 0");
        if ($scope !== null) $q->whereIn('r.centre_id', $scope ?: [0]);
        $rows = $q->orderByDesc('r.for_date')->orderBy('u.first_name')->limit(300)
            ->get(['r.for_date', 'r.missing', 'r.days_in_window', 'r.escalated', 'r.emailed', 'c.name as centre',
                DB::raw("TRIM(CONCAT(u.first_name,' ',COALESCE(u.last_name,''))) as educator")])
            ->map(fn ($r) => ['date' => $r->for_date, 'educator' => $r->educator, 'centre' => $r->centre,
                'missing' => array_map(fn ($k) => RecordReminders::ITEMS[$k][0] ?? $k, json_decode((string) $r->missing, true) ?: []),
                'days' => (int) $r->days_in_window, 'escalated' => (bool) $r->escalated, 'emailed' => (bool) $r->emailed]);

        return response()->json(['settings' => RecordReminders::settings($agencyId), 'can_edit' => $this->canEdit($request, $agencyId), 'recent' => $rows]);
    }

    /** POST /admin/record-reminders */
    public function update(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canEdit($request, $agencyId), 403, 'Only an agency administrator can change this.');
        $s = RecordReminders::save($agencyId, $request->all());
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => 'settings.record_reminders',
                'entity_type' => 'agency', 'entity_id' => $agencyId, 'payload' => json_encode($s), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'settings' => $s]);
    }
}
