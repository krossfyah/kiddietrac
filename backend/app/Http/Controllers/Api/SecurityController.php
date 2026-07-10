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
}
