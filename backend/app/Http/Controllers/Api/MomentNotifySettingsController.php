<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ChildMomentNotify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Agency notifications → Instant moments (2026-10-31). agencies.settings.instant_moments:
 * whether families are told the moment a meal, nap, diaper, mood, activity, medication dose, milestone or shared
 * learning observation is logged for their child (App\Support\ChildMomentNotify). Default ON (Anthony).
 */
final class MomentNotifySettingsController extends Controller
{
    private function agencyId(Request $request): int
    {
        $header = (int) $request->header('X-Active-Agency-Id');
        $roles = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('active', 1);
        if ($header && (clone $roles)->where(fn ($q) => $q->where('role', 'platform_admin')->orWhere('agency_id', $header))->exists()) {
            return $header;
        }
        return (int) (clone $roles)->whereIn('role', ['agency_admin', 'platform_admin', 'centre_director'])->value('agency_id');
    }

    private function assertAdmin(Request $request): void
    {
        $ok = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Only agency administrators can change this.');
    }

    public function show(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        return response()->json(['settings' => ChildMomentNotify::settings($aid)]);
    }

    public function save(Request $request): JsonResponse
    {
        $this->assertAdmin($request);
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);
        $data = $request->validate([
            'enabled' => 'required|boolean', 'moments' => 'required|boolean', 'observations' => 'required|boolean',
            'medication' => 'required|boolean', 'milestones' => 'required|boolean',
        ]);
        $before = ChildMomentNotify::settings($aid);
        $raw = DB::table('agencies')->where('id', $aid)->value('settings');
        $all = is_string($raw) ? (json_decode($raw, true) ?: []) : (array) $raw;
        $all['instant_moments'] = array_map('boolval', $data);
        DB::table('agencies')->where('id', $aid)->update(['settings' => json_encode($all), 'updated_at' => now()]);

        $changes = [];
        $names = ['enabled' => 'instant moment notifications', 'moments' => 'daily moments', 'observations' => 'learning observations', 'medication' => 'medication doses', 'milestones' => 'milestones'];
        foreach ($names as $k => $label) {
            if ((bool) $before[$k] !== (bool) $data[$k]) $changes[] = $label . ': ' . ($before[$k] ? 'on' : 'off') . ' → ' . ($data[$k] ? 'on' : 'off');
        }
        try {
            \App\Support\Audit::write([
                'action' => 'settings.instant_moments_updated', 'agency_id' => $aid, 'user_id' => $request->user()->id,
                'summary' => 'Changed instant moment notifications to families' . ($changes ? ' — ' . implode('; ', $changes) : ' (no change)'),
                'payload' => json_encode(['before' => $before, 'after' => $data]),
            ]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'settings' => ChildMomentNotify::settings($aid)]);
    }
}
