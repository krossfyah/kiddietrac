<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * THE ADMIN BELL SHOWS YOUR ALERTS (2026-11-26, Anthony: "the safe arrival alerts should show up in the bell as well").
 *
 * An admin or director's bell panel lists the agency activity feed (AgencyController::activityFeed), with only a
 * "N alerts for you" summary row for their own notifications (memory kiddietrac-admin-bell-is-activity-feed). A Safe
 * Arrival alert - written, pushed and emailed - therefore never appeared in the bell itself, and the summary row
 * vanished once the bell had been opened. This lists the person's own SAFETY alerts (the kinds below, none of which
 * are in the activity feed) for the agency on screen, newest first, so the bell shows them by name with their link
 * and read state. Always the caller's own rows; a row of another agency never shows.
 */
class BellAlertsController extends Controller
{
    /** Alerts that matter on sight. Incidents are left out: the activity feed already lists every incident. */
    public const TYPES = [
        'safe_arrival', 'running_late', 'ratio_breach', 'children_still_in', 'pickup_alarm', 'late_pickup',
        'fever_alert', 'lockdown', 'fire', 'shelter', 'hazard', 'hazard_notice', 'weather', 'disaster',
        'headcount_missing', 'headcount_strike', 'compliance', 'alert_director', 'vehicle_exit',
    ];

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $uid = (int) $user->id;
        $agency = AgencyRole::activeAgency($request);
        if (! $agency) {
            // A platform admin working inside one agency: the header names it (activeAgency leaves platform admins unset).
            $h = (int) $request->header('X-Active-Agency-Id');
            $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', 1)->exists();
            if ($h > 0 && $isPlatform) $agency = $h;
            // Fail closed: only a platform admin's "All agencies" view lists rows without choosing an agency.
            if (! $agency && ! $isPlatform) return response()->json(['alerts' => []]);
        }

        $q = DB::table('notifications')->where('user_id', $uid)->whereIn('type', self::TYPES)
            ->where('created_at', '>=', now()->subHours(48));
        /* ONLY THE AGENCY ON SCREEN. Every one of these alert kinds belongs to an agency; a row with none is from before
           Notify stamped one (2026-11-24) and cannot be placed, so it never shows - listing NULL rows here put iLearn's
           Safe Arrival alerts in a super admin's bell while they were working in another agency. No agency chosen
           (a platform admin's "All agencies" view): their own rows, whichever agency. */
        if ($agency) {
            $q->where('agency_id', $agency);
        }
        $rows = $q->orderByDesc('created_at')->orderByDesc('id')->limit(15)->get(['id', 'type', 'title', 'body', 'data', 'read_at', 'created_at']);

        $out = [];
        foreach ($rows as $r) {
            $d = json_decode((string) $r->data, true) ?: [];
            $link = (string) ($d['link'] ?? $d['url'] ?? $d['href'] ?? $d['hash'] ?? '');
            if ($link !== '' && $link[0] !== '#' && $link[0] !== '/' && ! str_starts_with($link, 'http')) $link = '#' . $link;
            if ($link === '' && $r->type === 'safe_arrival') $link = '#safe-arrival';
            $out[] = ['id' => (int) $r->id, 'type' => $r->type, 'title' => (string) $r->title, 'body' => (string) $r->body,
                'link' => $link, 'read' => $r->read_at !== null, 'when' => $r->created_at];
        }

        return response()->json(['alerts' => $out]);
    }
}
