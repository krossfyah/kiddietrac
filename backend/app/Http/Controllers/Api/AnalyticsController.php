<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v22p57 — Analytics over daily_care_logs + observations.
 *   - Sleep / diaper / mood trends per child
 *   - HDLH framework gap detection per child
 */
final class AnalyticsController extends Controller
{
    use ResolvesCentreContext;

    public function childTrends(Request $request, int $childId): JsonResponse
    {
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        $days = (int) $request->query('days', 30);
        $start = Carbon::now()->subDays($days)->startOfDay();

        $logs = DB::table('daily_care_logs')
            ->where('child_id', $childId)
            ->where('occurred_at', '>=', $start)
            ->select('log_type', 'occurred_at', 'details', 'ended_at')
            ->orderBy('occurred_at')
            ->get();

        $byType = $logs->groupBy('log_type');
        $byDay = $logs->groupBy(fn ($l) => Carbon::parse($l->occurred_at)->toDateString());

        // Trends
        $sleepEvents = $byType->get('nap', collect());
        $diaperEvents = $byType->get('diaper', collect());

        $sleepAvgMinutes = $sleepEvents->filter(fn ($e) => $e->ended_at)
            ->avg(fn ($e) => Carbon::parse($e->occurred_at)->diffInMinutes($e->ended_at));
        $diaperAvgPerDay = $diaperEvents->count() / max(1, $byDay->count());

        $checkins = DB::table('daily_checkins')
            ->where('child_id', $childId)
            ->where('checkin_date', '>=', Carbon::now()->subDays($days)->toDateString())
            ->orderBy('checkin_date')
            ->get();
        $moodCounts = $checkins->countBy('mood');

        return response()->json([
            'window_days' => $days,
            'logs_total' => $logs->count(),
            'sleep_avg_minutes' => $sleepAvgMinutes ? (int) round((float) $sleepAvgMinutes) : null,
            'diaper_avg_per_day' => round($diaperAvgPerDay, 2),
            'mood_distribution' => $moodCounts,
            'log_counts_by_type' => $byType->map(fn ($g) => $g->count()),
            'log_counts_by_day' => $byDay->map(fn ($g) => $g->count())->sortKeys()->toArray(),
        ]);
    }

    public function hdlhGaps(Request $request, int $childId): JsonResponse
    {
        abort_unless($this->canAccessChildId($request->user(), $childId), 403);
        /* Framework-aware (2026-09-29). This used to count observations whose domain was
           literally "Belonging" etc -- but domains are stored as social_emotional,
           physical, ... so every child showed four gaps. Now each observation counts toward
           the agency framework's areas it is linked to (or its domain maps to). */
        $agencyId = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->join('centres as ce', 'ce.id', '=', 'f.centre_id')->where('c.id', $childId)->value('ce.agency_id');
        $fw = \App\Support\LearningFrameworks::forAgency($agencyId ? (int) $agencyId : null);
        $counts = array_fill_keys(array_column($fw['areas'], 'key'), 0);
        DB::table('observations')->where('child_id', $childId)
            ->select('domain', 'hdlh_milestones')->orderBy('id')
            ->chunk(500, function ($obs) use (&$counts, $fw) {
                foreach ($obs as $o) {
                    foreach (\App\Support\LearningFrameworks::areasOf($fw, (string) $o->domain, $o->hdlh_milestones) as $k) $counts[$k]++;
                }
            });
        $rows = collect($fw['areas'])->map(function ($a) use ($counts) {
            $c = (int) ($counts[$a['key']] ?? 0);
            return ['key' => $a['key'], 'domain' => $a['label'], 'count' => $c, 'status' => $c >= 5 ? 'strong' : ($c >= 2 ? 'moderate' : 'gap')];
        })->values();
        $gaps = $rows->where('status', 'gap')->count();
        $milestones = DB::table('milestone_records')
            ->where('child_id', $childId)
            ->select('domain', 'status', DB::raw('COUNT(*) as count'))
            ->groupBy('domain', 'status')
            ->get()->groupBy('domain');
        return response()->json([
            'data' => $rows,
            'framework' => ['key' => $fw['key'], 'name' => $fw['name'], 'short' => $fw['short']],
            'gaps_count' => $gaps,
            'milestones_by_domain' => $milestones,
        ]);
    }
}
