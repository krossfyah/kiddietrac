<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\CertExpiryAlerts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Settings → Notifications → 🎓 Staff certifications (2026-11-03) - see App\Support\CertExpiryAlerts.
 *   GET  /admin/cert-alerts            agency admins: the settings
 *   POST /admin/cert-alerts            agency admins: change them (audited)
 *   POST /admin/cert-alerts/preview    agency admins: what the next run would send with these settings (sends nothing)
 */
final class CertAlertSettingsController extends Controller
{
    private function agencyId(Request $request): int
    {
        $aid = \App\Support\AgencyRole::activeAgency($request);
        if ($aid) return $aid;
        $header = (int) $request->header('X-Active-Agency-Id');
        if ($header && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)->where('role', 'platform_admin')->exists()) return $header;

        return 0;
    }

    private function guard(Request $request): int
    {
        $ok = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))
            ->where('user_id', $request->user()->id)->where('active', 1)
            ->whereIn('role', ['agency_admin', 'platform_admin'])->exists();
        abort_unless($ok, 403, 'Only agency administrators can change this.');
        $aid = $this->agencyId($request);
        abort_unless($aid > 0, 404);

        return $aid;
    }

    private function input(Request $request): array
    {
        $d = $request->validate([
            'enabled' => 'required|boolean', 'on_day' => 'required|boolean', 'weekly_after' => 'required|boolean',
            'holder' => 'required|boolean', 'directors' => 'required|boolean', 'admins' => 'required|boolean', 'email' => 'required|boolean',
            'steps' => 'present|array|max:8', 'steps.*' => 'integer|min:1|max:365',
        ]);

        return $d;
    }

    public function show(Request $request): JsonResponse
    {
        $aid = $this->guard($request);

        return response()->json(['settings' => CertExpiryAlerts::settings($aid), 'defaults' => CertExpiryAlerts::DEFAULTS]);
    }

    public function save(Request $request): JsonResponse
    {
        $aid = $this->guard($request);
        $before = CertExpiryAlerts::settings($aid);
        $after = CertExpiryAlerts::save($aid, $this->input($request));
        $who = fn ($s) => implode(', ', array_keys(array_filter(['staff member' => $s['holder'], 'centre directors' => $s['directors'], 'agency admins' => $s['admins']]))) ?: 'nobody';
        $say = fn ($s) => $s['enabled'] ? ('on: ' . (implode('/', $s['steps']) ?: 'no') . ' days before' . ($s['on_day'] ? ', on the day' : '') . ($s['weekly_after'] ? ', weekly after' : '')
            . '; to ' . $who($s) . ($s['email'] ? ' (bell + email)' : ' (bell only)')) : 'off';
        try {
            \App\Support\Audit::write([
                'action' => 'settings.cert_alerts_updated', 'agency_id' => $aid, 'user_id' => $request->user()->id,
                'summary' => 'Changed staff certificate expiry alerts: ' . $say($before) . ' → ' . $say($after),
                'payload' => json_encode(['before' => $before, 'after' => $after]),
            ]);
        } catch (\Throwable $e) {}

        return response()->json(['ok' => true, 'settings' => $after]);
    }

    public function preview(Request $request): JsonResponse
    {
        $aid = $this->guard($request);
        $s = array_merge(CertExpiryAlerts::DEFAULTS, $this->input($request));
        $s['steps'] = array_values(array_unique(array_map('intval', $s['steps'])));
        rsort($s['steps']);
        $items = CertExpiryAlerts::plan($aid, $s);
        $people = [];
        foreach ($items as $it) {
            foreach (CertExpiryAlerts::managers($aid, $it['centre_ids'], $s) as $m) if ((int) $m->id !== $it['user_id']) $people[(int) $m->id] = true;
        }

        return response()->json([
            'items' => array_map(fn ($i) => ['name' => $i['name'], 'label' => $i['label'], 'phrase' => $i['phrase'], 'days' => $i['days']], $items),
            'holders' => $s['holder'] ? count(array_unique(array_column($items, 'user_id'))) : 0,
            'managers' => count($people),
            'email' => (bool) $s['email'],
        ]);
    }
}
