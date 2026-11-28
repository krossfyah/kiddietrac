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
        $start = \App\Support\AgencyTime::boundaryUtc(null, 'day', -$days);

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
            ->where('checkin_date', '>=', \App\Support\AgencyTime::todayDate()->subDays($days)->toDateString())
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

        return response()->json($this->gapsFor($childId));
    }

    /**
     * GET /analytics/hdlh-gaps?child_ids=1,2,3 — the same answer for up to 50 children in one call
     * (2026-11-28, help-writer bug batch). The Framework gaps screen asked once per child, one after
     * another, so it stopped at the first 30 children to stay usable; an agency with more never saw
     * the rest. Children the caller may not see are left out, never an error for the whole page.
     */
    public function hdlhGapsMany(Request $request): JsonResponse
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $request->query('child_ids', ''))))));
        abort_if(count($ids) > 50, 422, 'Ask for 50 children or fewer at a time.');
        $user = $request->user();
        $out = [];
        foreach ($ids as $id) {
            if ($this->canAccessChildId($user, $id)) $out[(string) $id] = $this->gapsFor($id);
        }

        return response()->json(['data' => $out]);
    }

    private function gapsFor(int $childId): array
    {
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
        $planned = $this->plannedAreas($childId, $fw, $agencyId ? (int) $agencyId : null);
        $rows = collect($fw['areas'])->map(function ($a) use ($counts, $planned) {
            $c = (int) ($counts[$a['key']] ?? 0);
            return ['key' => $a['key'], 'domain' => $a['label'], 'count' => $c, 'status' => $c >= 5 ? 'strong' : ($c >= 2 ? 'moderate' : 'gap'),
                // Planned activities tagged with this area in the child's room (2026-11-03). Shown beside the
                // observation count; the gap status itself stays about what was OBSERVED.
                'planned' => (int) ($planned['published'][$a['key']] ?? 0),
                'planned_draft' => (int) ($planned['draft'][$a['key']] ?? 0)];
        })->values();
        $gaps = $rows->where('status', 'gap')->count();
        $milestones = DB::table('milestone_records')
            ->where('child_id', $childId)
            ->select('domain', 'status', DB::raw('COUNT(*) as count'))
            ->groupBy('domain', 'status')
            ->get()->groupBy('domain');
        return [
            'data' => $rows,
            'framework' => ['key' => $fw['key'], 'name' => $fw['name'], 'short' => $fw['short']],
            'gaps_count' => $gaps,
            'milestones_by_domain' => $milestones,
            'planned_window' => $planned['window'],
        ];
    }

    /**
     * Planned activities per framework area for the child's CURRENT room, over the last 8 weeks
     * and the next 4 (agency weeks). A room's own plan wins over its centre-wide plan for the
     * same week, the way the planner shows it. An activity counts toward the areas its HDLH
     * foundations name (curriculum units tag every activity), else toward its domain's area.
     * Drafts are counted apart: they have not happened, and families cannot see them yet.
     */
    private function plannedAreas(int $childId, array $fw, ?int $agencyId): array
    {
        $out = ['published' => [], 'draft' => [], 'window' => null];
        $enr = \App\Support\Live::enrolments(DB::table('enrollments'))->where('child_id', $childId)
            ->where('start_date', '<=', \App\Support\AgencyTime::today())->orderByDesc('start_date')->first();
        $roomId = $enr->room_id ?? null;
        if (! $roomId) return $out;
        $centreId = DB::table('rooms')->where('id', $roomId)->value('centre_id');
        $monday = \App\Support\AgencyTime::todayDate()->startOfWeek(Carbon::MONDAY);
        $from = $monday->copy()->subWeeks(8)->toDateString();
        $to = $monday->copy()->addWeeks(4)->toDateString();
        $out['window'] = ['from' => $from, 'to' => $to];
        $plans = DB::table('lesson_plans')->whereBetween('week_starting', [$from, $to])
            ->where(fn ($w) => $w->where('room_id', $roomId)
                ->orWhere(fn ($c) => $c->where('centre_id', $centreId ?: 0)->whereNull('room_id')))
            ->get(['week_starting', 'room_id', 'status', 'plan_data']);
        $byWeek = [];
        foreach ($plans as $p) {
            $k = (string) $p->week_starting;
            if (! isset($byWeek[$k]) || $p->room_id) $byWeek[$k] = $p;     // the room's own plan wins
        }
        foreach ($byWeek as $p) {
            $bucket = ($p->status === 'draft') ? 'draft' : 'published';
            $pd = json_decode((string) $p->plan_data, true) ?: [];
            foreach ((array) ($pd['days'] ?? []) as $acts) {
                foreach ((array) $acts as $a) {
                    if (! is_array($a) || trim((string) ($a['title'] ?? '')) === '') continue;
                    $links = array_map(fn ($k) => ['foundation' => (string) $k], (array) ($a['foundations'] ?? []));
                    foreach (\App\Support\LearningFrameworks::areasOf($fw, (string) ($a['domain'] ?? ''), $links) as $k) {
                        $out[$bucket][$k] = ($out[$bucket][$k] ?? 0) + 1;
                    }
                }
            }
        }

        return $out;
    }
}
