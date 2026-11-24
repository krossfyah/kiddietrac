<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\SummaryTiming;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Notifications → Daily summaries (2026-10-29): how long after check-out / clock-out
 * the parent and educator daily summaries go, and the fallback time. See App\Support\SummaryTiming.
 * Agency admins of the active agency and platform admins.
 */
class SummaryTimingController extends Controller
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
        $a = $this->agencyId($request);
        return response()->json(['settings' => SummaryTiming::read($a), 'delays' => SummaryTiming::DELAYS,
            'timezone' => DB::table('agencies')->where('id', $a)->value('timezone')]);
    }

    public function update(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $data = $request->validate([
            'parent_delay_min' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'educator_delay_min' => ['sometimes', 'integer', 'min:0', 'max:480'],
            'parent_fallback' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'educator_fallback' => ['sometimes', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
        ]);
        $before = SummaryTiming::read($a);
        $s = SummaryTiming::write($a, array_replace($before, $data));
        $changed = [];
        foreach ($s as $k => $v) {
            if (($before[$k] ?? null) !== $v) $changed[] = str_replace('_', ' ', $k) . ': ' . $before[$k] . ' → ' . $v;
        }
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'summaries.timing_changed', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Daily summary timing changed' . ($changed ? ': ' . implode('; ', $changed) : ' (no changes)')]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['settings' => $s]);
    }
}
