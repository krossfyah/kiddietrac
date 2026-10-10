<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MessagingRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Notifications → Message Centre (2026-10-09): who educators and home visitors
 * may message. Agency admins of the active agency and platform admins. See MessagingRules.
 */
class MessagingRulesController extends Controller
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
        return response()->json(['settings' => MessagingRules::read($this->agencyId($request))]);
    }

    public function update(Request $request): JsonResponse
    {
        $a = $this->agencyId($request);
        $data = $request->validate([
            'educator' => ['sometimes', 'array'],
            'home_visitor' => ['sometimes', 'array'],
            'existing' => ['sometimes', 'in:read_only,hidden'],
        ]);
        $before = MessagingRules::read($a);
        $s = MessagingRules::write($a, array_replace_recursive($before, $data));
        $changed = [];
        foreach (['educator', 'home_visitor'] as $r) {
            if ($before[$r]['families'] !== $s[$r]['families']) $changed[] = $r . ' families: ' . $before[$r]['families'] . ' → ' . $s[$r]['families'];
            foreach ($s[$r]['staff'] as $k => $v) {
                if (($before[$r]['staff'][$k] ?? null) !== $v) $changed[] = $r . ' → ' . $k . ': ' . ($v ? 'allowed' : 'not allowed');
            }
        }
        if ($before['existing'] !== $s['existing']) $changed[] = 'existing conversations: ' . $before['existing'] . ' → ' . $s['existing'];
        try {
            \App\Support\Audit::write(['user_id' => $request->user()->id, 'agency_id' => $a, 'action' => 'messaging.rules_changed', 'entity_type' => 'agency', 'entity_id' => $a,
                'payload' => json_encode(['summary' => 'Message Centre rules changed' . ($changed ? ': ' . implode('; ', $changed) : ' (no changes)')]), 'created_at' => now()]);
        } catch (\Throwable $e) {}

        return response()->json(['settings' => $s]);
    }
}
