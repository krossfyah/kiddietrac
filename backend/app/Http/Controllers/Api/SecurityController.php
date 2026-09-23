<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Security monitoring surface (SOC 2 — CC7). Platform-admin only; routes live
 * in the role:platform_admin group. Reads the security_alerts table populated
 * by the security:alerts scheduled command.
 */
class SecurityController extends Controller
{
    public function alerts(Request $request)
    {
        $rows = DB::table('security_alerts')
            ->orderByRaw('resolved_at IS NOT NULL ASC')   // open first
            ->orderByDesc('created_at')
            ->limit(200)->get();

        return response()->json([
            'open'   => DB::table('security_alerts')->whereNull('resolved_at')->count(),
            'total'  => DB::table('security_alerts')->count(),
            'alerts' => $rows->map(function ($r) {
                return [
                    'id'         => (int) $r->id,
                    'type'       => $r->type,
                    'severity'   => $r->severity,
                    'subject'    => $r->subject,
                    'details'    => $r->details,
                    'resolved'   => $r->resolved_at !== null,
                    'created_at' => $r->created_at,
                ];
            })->all(),
        ]);
    }

    public function resolveAlert(Request $request, int $id)
    {
        DB::table('security_alerts')->where('id', $id)->update([
            'resolved_at' => now(),
            'updated_at'  => now(),
        ]);
        return response()->json(['status' => 'resolved']);
    }

    /**
     * Acknowledge every open alert at once.
     *
     * Reviewing a burst of eight brute-force rows one button at a time is how a list
     * stops being read at all. This marks them seen; it does not remove them.
     */
    public function resolveAllAlerts(Request $request)
    {
        $open = DB::table('security_alerts')->whereNull('resolved_at')->count();
        if ($open > 0) {
            DB::table('security_alerts')->whereNull('resolved_at')
                ->update(['resolved_at' => now(), 'updated_at' => now()]);
        }

        $this->auditAlerts($request, 'security.alerts_acknowledged',
            'Acknowledged ' . $open . ' open security alert(s). Nothing was deleted.');

        return response()->json(['status' => 'ok', 'acknowledged' => $open]);
    }

    /**
     * Clear the log — RESOLVED ROWS ONLY, and deliberately so.
     *
     * These rows are the SOC 2 CC7 monitoring trail: brute force, MFA hammering and
     * credential stuffing, written automatically every fifteen minutes. A "clear
     * everything" button would let one click erase warnings nobody had read yet, which is
     * precisely the evidence an incident review needs and precisely what an attacker
     * would press. So an alert has to be acknowledged FIRST, and only then can it be
     * cleared: two deliberate acts, not one.
     *
     * The clearing itself is recorded in the audit log with the counts and types removed,
     * so emptying the list cannot itself be done quietly.
     */
    public function clearAlerts(Request $request)
    {
        $resolved = DB::table('security_alerts')->whereNotNull('resolved_at');
        $count = (clone $resolved)->count();

        if ($count === 0) {
            $open = DB::table('security_alerts')->whereNull('resolved_at')->count();

            return response()->json([
                'status' => 'nothing_to_clear',
                'cleared' => 0,
                'open' => $open,
                'message' => $open > 0
                    ? 'Nothing has been acknowledged yet. Mark alerts resolved first — open alerts are never cleared.'
                    : 'The log is already empty.',
            ], 200);
        }

        /* Named before they go, so the audit row says WHAT was cleared rather than only
           how many - a count alone tells an incident review nothing. */
        $byType = (clone $resolved)->select('type', DB::raw('COUNT(*) n'))
            ->groupBy('type')->pluck('n', 'type')->toArray();
        $summary = [];
        foreach ($byType as $t => $n) {
            $summary[] = $n . ' × ' . $t;
        }

        DB::table('security_alerts')->whereNotNull('resolved_at')->delete();

        $this->auditAlerts($request, 'security.alerts_cleared',
            'Cleared ' . $count . ' acknowledged security alert(s) from the log ('
            . implode(', ', $summary) . '). Open alerts were left in place.');

        return response()->json(['status' => 'ok', 'cleared' => $count]);
    }

    private function auditAlerts(Request $request, string $action, string $summary): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => optional($request->user())->id,
                /* Platform-level: security_alerts has no agency column, because the
                   activity it watches is authentication against the whole platform. */
                'agency_id' => null,
                'action' => $action,
                'entity_type' => 'platform',
                'entity_id' => null,
                'payload' => json_encode(['summary' => $summary]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }
    }
}
