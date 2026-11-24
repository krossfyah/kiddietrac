<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * HOW FAST THE PORTAL IS WHERE PEOPLE ACTUALLY USE IT (2026-10-29).
 *
 * Anthony: "on poor or slow internet connections things don't load or very slow to load ... a
 * middleware or something to monitor". The server only ever saw its own side of a request (it
 * answers in ~100ms); what a parent on a weak signal waits for - the round trip, the bytes over
 * a slow link - was invisible. kt-net-quality.js measures the portal's API calls in the browser
 * (Resource Timing) plus the phone's own connection estimate where it gives one, and posts one
 * summary per session (and again if the connection turns slow). Platform → Server health shows
 * the last 24 hours: how many sessions were slow, the typical wait, and which agencies suffer.
 *
 * Diagnostic only: numbers, the connection type and the platform. No URLs, no content.
 */
final class NetQuality
{
    public const KEEP_DAYS = 30;

    public static function record(Request $r): void
    {
        $user = null;
        try { $user = auth('sanctum')->user(); } catch (\Throwable $e) {}
        $agencyId = null;
        $hdr = (int) $r->header('X-Active-Agency-Id');
        if ($user && $hdr > 0) {
            $member = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $user->id)->where('ra.active', true)
                ->where(function ($w) use ($hdr) { $w->where('ra.agency_id', $hdr)->orWhere('c.agency_id', $hdr)->orWhere('ra.role', 'platform_admin'); })
                ->exists();
            if ($member) $agencyId = $hdr;
        }
        $int = fn ($v, $max) => is_numeric($v) ? max(0, min($max, (int) round((float) $v))) : null;
        $et = (string) $r->input('effective_type', '');
        DB::table('client_net_samples')->insert([
            'user_id' => $user->id ?? null,
            'agency_id' => $agencyId,
            'effective_type' => preg_match('/^(slow-2g|2g|3g|4g|5g)$/', $et) ? $et : null,
            'rtt_ms' => $int($r->input('rtt'), 60000),
            'downlink_kbps' => is_numeric($r->input('downlink')) ? $int(((float) $r->input('downlink')) * 1000, 10000000) : null,
            'api_median_ms' => $int($r->input('api_median'), 600000),
            'api_p90_ms' => $int($r->input('api_p90'), 600000),
            'api_samples' => $int($r->input('samples'), 65000) ?? 0,
            'failures' => $int($r->input('failures'), 65000) ?? 0,
            'slow' => $r->boolean('slow'),
            'platform' => mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $r->input('platform', ''))), 0, 16) ?: null,
            'created_at' => now(),
        ]);
        if (random_int(1, 200) === 1) {
            DB::table('client_net_samples')->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        }
    }

    /** The last $hours: overall, by connection type, and the five slowest agencies. */
    public static function summary(int $hours = 24): array
    {
        $since = now()->subHours($hours);
        $rows = DB::table('client_net_samples')->where('created_at', '>=', $since)
            ->get(['agency_id', 'effective_type', 'api_median_ms', 'slow', 'failures']);
        $n = $rows->count();
        $med = function ($c) {
            $v = $c->pluck('api_median_ms')->filter(fn ($x) => $x !== null)->sort()->values();
            return $v->count() ? (int) $v[intdiv($v->count(), 2)] : null;
        };
        $p90 = function ($c) {
            $v = $c->pluck('api_median_ms')->filter(fn ($x) => $x !== null)->sort()->values();
            return $v->count() ? (int) $v[min($v->count() - 1, (int) floor($v->count() * 0.9))] : null;
        };
        $names = DB::table('agencies')->whereIn('id', $rows->pluck('agency_id')->filter()->unique()->all())->pluck('name', 'id');
        $byAgency = $rows->whereNotNull('agency_id')->groupBy('agency_id')->map(function ($c, $id) use ($med, $names) {
            return ['agency_id' => (int) $id, 'agency' => $names[$id] ?? ('Agency ' . $id), 'sessions' => $c->count(),
                'slow' => $c->where('slow', true)->count(), 'median_ms' => $med($c)];
        })->sortByDesc(fn ($a) => [$a['slow'] / max(1, $a['sessions']), $a['median_ms']])->values()->take(5)->all();
        $byType = $rows->groupBy(fn ($r) => $r->effective_type ?: 'unknown')->map(fn ($c, $t) => ['type' => $t, 'sessions' => $c->count(), 'slow' => $c->where('slow', true)->count()])->values()->all();

        return [
            'hours' => $hours,
            'sessions' => $n,
            'slow' => $rows->where('slow', true)->count(),
            'failures' => (int) $rows->sum('failures'),
            'median_ms' => $med($rows),
            'p90_ms' => $p90($rows),
            'by_type' => $byType,
            'worst_agencies' => $byAgency,
        ];
    }
}
