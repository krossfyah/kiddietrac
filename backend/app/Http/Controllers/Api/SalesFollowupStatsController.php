<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Sales → Follow-ups numbers (2026-10-23, Anthony: "create some graphics on average it takes
 * to followup and other stats in this section").
 *
 * Built from sales_activities on live leads, in Eastern Time like the rest of Sales:
 *   - the open follow-up tasks (overdue / today / this week), with their lead;
 *   - how long a follow-up takes to get done, and how many were done on time;
 *   - first response: from a lead arriving to the first thing a person did with it
 *     (a call, email, meeting, note or completed follow-up - not the automatic entries);
 *   - an 8-week trend of follow-ups set vs done, the next 14 days of what is due, and the
 *     same figures per salesperson.
 * Older completed follow-ups carry no done_at; their last update stands in for it.
 */
class SalesFollowupStatsController extends Controller
{
    private const TZ = 'America/Toronto';
    private const TOUCH = ['call', 'email', 'meeting', 'note', 'followup'];

    public function index(): JsonResponse
    {
        $now = Carbon::now(self::TZ);
        $today = $now->toDateString();
        $utc = fn ($v) => $v ? Carbon::parse($v, 'UTC') : null;

        $leads = DB::table('sales_leads')->whereNull('deleted_at')->get(['id', 'name', 'company', 'email', 'stage', 'status', 'owner_id', 'created_at'])->keyBy('id');
        $acts = DB::table('sales_activities')->whereIn('lead_id', $leads->keys()->all() ?: [0])
            ->get(['id', 'lead_id', 'user_id', 'type', 'body', 'due_date', 'done', 'done_at', 'created_at', 'updated_at']);
        $users = DB::table('users')->whereIn('id', $acts->pluck('user_id')->merge($leads->pluck('owner_id'))->filter()->unique()->all() ?: [0])
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');
        $name = fn ($id) => $id && isset($users[$id]) ? trim($users[$id]->first_name . ' ' . mb_substr((string) $users[$id]->last_name, 0, 1) . '.') : 'Unassigned';

        $fu = $acts->where('type', 'followup');
        $open = $fu->filter(fn ($a) => ! $a->done && isset($leads[$a->lead_id]) && $leads[$a->lead_id]->status === 'open');
        $doneAt = fn ($a) => $utc($a->done_at ?: $a->updated_at);

        // Open tasks, soonest first.
        $items = $open->sortBy(fn ($a) => ($a->due_date ?: '9999') . sprintf('%09d', $a->id))->values()->map(function ($a) use ($leads, $today, $name) {
            $l = $leads[$a->lead_id];
            $due = $a->due_date ? substr((string) $a->due_date, 0, 10) : null;
            return ['id' => (int) $a->id, 'lead_id' => (int) $a->lead_id, 'lead' => $l->company ?: $l->name, 'contact' => $l->company ? $l->name : null,
                'stage' => $l->stage, 'owner' => $name($l->owner_id ?: $a->user_id), 'body' => $a->body, 'due' => $due,
                'days_overdue' => $due && $due < $today ? (int) round(abs(Carbon::parse($due, self::TZ)->diffInDays(Carbon::parse($today, self::TZ)))) : 0];
        });

        // Completed follow-ups over the last 180 days: time taken and on-time share.
        $done = $fu->filter(fn ($a) => $a->done && $doneAt($a) && $doneAt($a)->gt($now->copy()->subDays(180)));
        $closeDays = $done->map(fn ($a) => max(0, $utc($a->created_at)->diffInHours($doneAt($a)) / 24))->values();
        $onTime = $done->filter(fn ($a) => $a->due_date && $doneAt($a)->tz(self::TZ)->toDateString() <= substr((string) $a->due_date, 0, 10))->count();
        $withDue = $done->filter(fn ($a) => $a->due_date)->count();

        // First response per lead (leads from the last 180 days).
        $firstTouch = [];
        foreach ($leads as $l) {
            if (! $l->created_at || $utc($l->created_at)->lt($now->copy()->subDays(180))) continue;
            $t = $acts->where('lead_id', $l->id)->filter(fn ($a) => $a->user_id && in_array($a->type, self::TOUCH, true) && ($a->type !== 'followup' || $a->done))
                ->map(fn ($a) => $a->type === 'followup' ? $doneAt($a) : $utc($a->created_at))->filter()->sort()->first();
            $firstTouch[$l->id] = $t ? max(0, $utc($l->created_at)->diffInMinutes($t) / 60) : null;
        }
        $touched = array_values(array_filter($firstTouch, fn ($v) => $v !== null));
        sort($touched);
        $median = $touched ? $touched[intdiv(count($touched), 2)] : null;

        // 8 weeks: set vs done.
        $weeks = [];
        $wk0 = $now->copy()->startOfWeek(Carbon::MONDAY)->subWeeks(7);
        for ($i = 0; $i < 8; $i++) {
            $a = $wk0->copy()->addWeeks($i); $b = $a->copy()->addWeek();
            $weeks[] = ['week' => $a->toDateString(),
                'set' => $fu->filter(fn ($x) => ($c = $utc($x->created_at)) && $c->gte($a) && $c->lt($b))->count(),
                'done' => $fu->filter(fn ($x) => $x->done && ($d = $doneAt($x)) && $d->gte($a) && $d->lt($b))->count()];
        }
        // Next 14 days (overdue rolled into today).
        $next = [];
        for ($i = 0; $i < 14; $i++) {
            $d = $now->copy()->addDays($i)->toDateString();
            $next[] = ['date' => $d, 'due' => $open->filter(fn ($a) => $a->due_date && ($i === 0 ? substr((string) $a->due_date, 0, 10) <= $d : substr((string) $a->due_date, 0, 10) === $d))->count()];
        }

        // Per salesperson.
        $people = [];
        foreach ($leads->where('status', 'open') as $l) $people[$l->owner_id ?: 0] = true;
        foreach ($fu as $a) if ($a->user_id) $people[$a->user_id] = true;
        $byOwner = [];
        foreach (array_keys($people) as $uid) {
            $mine = fn ($a) => (int) (($leads[$a->lead_id]->owner_id ?? null) ?: $a->user_id) === (int) $uid;
            $o = $open->filter($mine); $dn = $done->filter($mine);
            $ft = [];
            foreach ($firstTouch as $lid => $h) if ($h !== null && (int) ($leads[$lid]->owner_id ?? 0) === (int) $uid) $ft[] = $h;
            $byOwner[] = ['name' => $name($uid), 'open' => $o->count(), 'overdue' => $o->filter(fn ($a) => $a->due_date && substr((string) $a->due_date, 0, 10) < $today)->count(),
                'done_30' => $dn->filter(fn ($a) => $doneAt($a)->gt($now->copy()->subDays(30)))->count(),
                'avg_close_days' => $dn->count() ? round($dn->map(fn ($a) => $utc($a->created_at)->diffInHours($doneAt($a)) / 24)->avg(), 1) : null,
                'avg_first_touch_hours' => $ft ? round(array_sum($ft) / count($ft), 1) : null];
        }
        usort($byOwner, fn ($a, $b) => $b['open'] <=> $a['open']);

        return response()->json([
            'summary' => [
                'open' => $open->count(),
                'overdue' => $items->where('days_overdue', '>', 0)->count(),
                'due_today' => $items->where('due', $today)->count(),
                'due_week' => $items->filter(fn ($i) => $i['due'] && $i['due'] >= $today && $i['due'] <= $now->copy()->addDays(6)->toDateString())->count(),
                'done_30' => $done->filter(fn ($a) => $doneAt($a)->gt($now->copy()->subDays(30)))->count(),
                'avg_close_days' => $closeDays->count() ? round($closeDays->avg(), 1) : null,
                'on_time_pct' => $withDue ? (int) round($onTime * 100 / $withDue) : null,
                'avg_overdue_days' => $items->where('days_overdue', '>', 0)->count() ? round($items->where('days_overdue', '>', 0)->avg('days_overdue'), 1) : null,
                'avg_first_touch_hours' => $touched ? round(array_sum($touched) / count($touched), 1) : null,
                'median_first_touch_hours' => $median !== null ? round($median, 1) : null,
                'leads_untouched' => count(array_filter($firstTouch, fn ($v) => $v === null)),
                'leads_measured' => count($firstTouch),
            ],
            'weeks' => $weeks, 'next' => $next, 'by_owner' => $byOwner, 'items' => $items, 'today' => $today,
        ]);
    }
}
