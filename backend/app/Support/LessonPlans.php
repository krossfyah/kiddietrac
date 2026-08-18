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
                'time_label' => self::formatTime($i['time'] ?? null),
                'title' => $title,
                'domain' => trim((string) ($i['domain'] ?? '')) ?: null,
                'notes' => trim((string) ($i['notes'] ?? '')) ?: null,
            ];
        }

        return ['theme' => trim((string) ($plan->theme ?? '')) ?: null, 'items' => $clean, 'inherited' => $inherited];
    }

    /**
     * Any plan written for a room in these centres, for this date.
     *
     * The fallback of last resort: a plan exists and somebody wrote it for today, but the
     * person looking is not assigned to that room — often because nobody is. Withholding
     * it on that technicality helps nobody; the room it belongs to is named so the reader
     * can see whose it is.
     *
     * @return array{theme:?string, items:array, inherited:bool, room_name:?string}
     */
    public static function forDateInCentres(array $centreIds, string $date): array
    {
        $empty = ['theme' => null, 'items' => [], 'inherited' => false, 'room_name' => null];
        if (! $centreIds) {
            return $empty;
        }

        $roomIds = DB::table('rooms')->whereIn('centre_id', $centreIds)->pluck('id')->all();
        if (! $roomIds) {
            return $empty;
        }

        $week = Carbon::parse($date)->startOfWeek(Carbon::MONDAY)->toDateString();
        $plan = DB::table('lesson_plans')->where('week_starting', $week)
            ->whereIn('room_id', $roomIds)->orderByDesc('id')->first();
        if (! $plan) {
            return $empty;
        }

        $out = self::forDate((int) $plan->room_id, null, $date);
        $out['room_name'] = DB::table('rooms')->where('id', $plan->room_id)->value('name');
        return $out;
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

    /**
     * The room an educator is assigned to. The join column on educator_rooms has been
     * spelled more than one way across migrations, so it is resolved rather than assumed
     * - guessing wrong here returns "no plan today", which is indistinguishable from an
     * unplanned day and would be silently wrong.
     */
    public static function roomForEducator(int $userId): ?int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('educator_rooms')) {
            return null;
        }
        foreach (['user_id', 'educator_id', 'staff_id'] as $col) {
            if (! \Illuminate\Support\Facades\Schema::hasColumn('educator_rooms', $col)) {
                continue;
            }
            $room = DB::table('educator_rooms')->where($col, $userId)->value('room_id');
            if ($room) {
                return (int) $room;
            }
        }
        return null;
    }

    /**
     * Planner times are free text, typed by whoever wrote the plan: "10am", "1030am",
     * "10:00", "1:30 p.m." all appear in real plans. Rendering that raw gives "1030am",
     * which is what was reported. Anything recognisable is normalised to "10:30 AM";
     * anything that is not is returned exactly as typed rather than mangled or dropped -
     * "after lunch" is a legitimate thing to have written in that box.
     */
    public static function formatTime(?string $raw): ?string
    {
        $t = strtolower(trim((string) $raw));
        if ($t === '') {
            return null;
        }

        $mer = null;
        if (preg_match('/(a\.?m\.?|p\.?m\.?)\s*$/', $t, $m)) {
            $mer = str_starts_with(trim($m[1]), 'a') ? 'AM' : 'PM';
            $t = trim(preg_replace('/(a\.?m\.?|p\.?m\.?)\s*$/', '', $t));
        }
        $t = str_replace([' ', '.'], '', $t);

        if (preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) {
            $h = (int) $m[1]; $min = (int) $m[2];
        } elseif (preg_match('/^(\d{3,4})$/', $t, $m)) {
            $h = (int) substr($m[1], 0, -2); $min = (int) substr($m[1], -2);
        } elseif (preg_match('/^(\d{1,2})$/', $t, $m)) {
            $h = (int) $m[1]; $min = 0;
        } else {
            return (string) $raw;
        }

        if ($h > 23 || $min > 59) {
            return (string) $raw;
        }

        if ($mer === null) {
            // No am/pm given. A childcare day runs roughly 7am–6pm, so 7–11 is morning,
            // 12 is midday and 1–6 is the afternoon. 13+ is already a 24-hour clock.
            if ($h >= 13) { $mer = 'PM'; $h -= 12; }
            elseif ($h === 12) { $mer = 'PM'; }
            elseif ($h >= 7) { $mer = 'AM'; }
            else { $mer = 'PM'; }
        } elseif ($h > 12) {
            $h -= 12;
        }
        if ($h === 0) {
            $h = 12;
        }

        return sprintf('%d:%02d %s', $h, $min, $mer);
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
            $meta = array_filter([self::formatTime($i['time']), self::domainLabel($i['domain'])]);
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
