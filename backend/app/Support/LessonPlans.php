<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * "What is planned for this room today?" — one answer, used everywhere.
 *
 * The plan is stored a week at a time (lesson_plans.week_starting is a Monday, plan_data
 * holds days.monday … days.friday), so every screen that wants today's activities has to
 * find the right week, pick the right day, and honour the centre-wide fallback. Doing
 * that in three places invites three subtly different answers — which is how an educator
 * ends up seeing an empty day that the admin can see perfectly well.
 *
 * The fallback matters: a plan can target a single room OR a whole centre, and a room
 * with no plan of its own inherits its centre's. That is what "applies to every room in
 * the centre" already promises in the planner UI.
 */
final class LessonPlans
{
    /**
     * @return array{theme:?string, items:array<int,array{time:?string,title:string,domain:?string,notes:?string}>, inherited:bool}
     */
    public static function forDate(?int $roomId, ?int $centreId, string $date): array
    {
        $empty = ['theme' => null, 'items' => [], 'inherited' => false];

        $day = Carbon::parse($date);
        // Monday of the plan's week. Carbon's startOfWeek is explicit about the day here:
        // left to the locale default this drifts, which has bitten the planner before.
        $week = $day->copy()->startOfWeek(Carbon::MONDAY)->toDateString();
        $key = strtolower($day->format('l'));
        // Plans are Monday–Friday by design; a weekend has nothing to show rather than
        // falling back to some other day's activities.
        if (! in_array($key, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true)) {
            return $empty;
        }

        $plan = null;
        $inherited = false;

        if ($roomId) {
            $plan = DB::table('lesson_plans')->where('week_starting', $week)->where('room_id', $roomId)->first();
        }
        if (! $plan && $centreId) {
            // Centre-wide plan: room_id null, centre_id set.
            $plan = DB::table('lesson_plans')->where('week_starting', $week)
                ->where('centre_id', $centreId)->whereNull('room_id')->first();
            $inherited = (bool) $plan;
        }
        if (! $plan) {
            return $empty;
        }

        $data = json_decode((string) $plan->plan_data, true);
        $items = $data['days'][$key] ?? [];
        if (! is_array($items)) {
            $items = [];
        }

        $clean = [];
        foreach ($items as $i) {
            if (! is_array($i)) continue;
            $title = trim((string) ($i['title'] ?? ''));
            if ($title === '') continue;   // a blank row is not an activity
            $clean[] = [
                'time' => trim((string) ($i['time'] ?? '')) ?: null,
                'title' => $title,
                'domain' => trim((string) ($i['domain'] ?? '')) ?: null,
                'notes' => trim((string) ($i['notes'] ?? '')) ?: null,
            ];
        }

        return ['theme' => trim((string) ($plan->theme ?? '')) ?: null, 'items' => $clean, 'inherited' => $inherited];
    }

    /** The room a child sits in today, for callers that only know the child. */
    public static function roomForChild(int $childId): ?int
    {
        $primary = DB::table('children')->where('id', $childId)->value('primary_room_id');
        if ($primary) {
            return (int) $primary;
        }
        // Fall back to a current enrolment — primary_room_id is not always set.
        return DB::table('enrollments')->where('child_id', $childId)
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('id')->value('room_id');
    }

    private static function domainLabel(?string $d): string
    {
        return [
            'social_emotional' => 'Social & emotional',
            'physical' => 'Physical',
            'language_literacy' => 'Language & literacy',
            'cognitive' => 'Cognitive',
            'creative_arts' => 'Creative arts',
            'self_care' => 'Self-care',
            'outdoor' => 'Outdoor',
        ][(string) $d] ?? '';
    }

    /**
     * An email block. Returns '' when there is nothing planned — a heading over an empty
     * list reads as a fault in the software rather than as an unplanned day.
     *
     * $audience: 'parent' phrases it for families, 'educator' for staff.
     */
    public static function emailBlock(array $plan, string $audience = 'parent'): string
    {
        if (empty($plan['items'])) {
            return '';
        }

        $heading = $audience === 'parent' ? "Today's learning" : "Today's lesson plan";
        $html = '<div style="margin:18px 0 6px;font-size:11px;font-weight:800;letter-spacing:1px;color:#64748B;text-transform:uppercase;">'
            . e($heading) . '</div>';

        if (! empty($plan['theme'])) {
            $html .= '<div style="font-size:14px;font-weight:800;color:#0F172A;margin:0 0 8px;">'
                . e($plan['theme']) . '</div>';
        }

        $html .= '<div class="kt-panel" style="background:#F8FAFC;border:1px solid #E2E8F0;border-radius:10px;padding:12px 14px;">';
        foreach ($plan['items'] as $i) {
            $meta = array_filter([$i['time'], self::domainLabel($i['domain'])]);
            $html .= '<div style="padding:6px 0;border-top:1px solid #EEF2F7;">'
                . '<div style="font-size:14px;font-weight:700;color:#0F172A;">' . e($i['title']) . '</div>'
                . ($meta ? '<div class="kt-muted" style="font-size:12px;color:#64748B;margin-top:1px;">' . e(implode(' · ', $meta)) . '</div>' : '')
                . ($i['notes'] ? '<div style="font-size:13px;color:#475569;margin-top:3px;line-height:1.5;">' . e($i['notes']) . '</div>' : '')
                . '</div>';
        }
        $html .= '</div>';

        return $html;
    }
}
