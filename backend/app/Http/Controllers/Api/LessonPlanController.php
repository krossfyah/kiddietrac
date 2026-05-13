<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v14: Lesson planning.
 *
 * Schema:
 *   lesson_plans(id, room_id, week_starting (Monday), theme?, plan_data longtext JSON, created_by_id)
 *   plan_data structure (designed by this controller):
 *   {
 *     "days": {
 *       "monday":    [{"time":"09:00","title":"...","domain":"social_emotional","notes":"..."}],
 *       "tuesday":   [...],
 *       "wednesday": [...],
 *       "thursday":  [...],
 *       "friday":    [...]
 *     }
 *   }
 *   HDLH domains match the observations.domain enum:
 *   social_emotional, physical, language_literacy, cognitive,
 *   creative_arts, self_care, outdoor
 */
final class LessonPlanController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];

    /**
     * GET /api/v1/provider/lesson-plans?room_id=X&week_starting=YYYY-MM-DD
     */
    public function show(Request $request): JsonResponse
    {
        $roomId = (int) $request->input('room_id');
        $week = $request->input('week_starting');
        if (!$week) {
            // Default to this week's Monday
            $week = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        if (! $this->hasRoomAccess($request->user()->id, $roomId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $plan = DB::table('lesson_plans')
            ->where('room_id', $roomId)
            ->where('week_starting', $week)
            ->first();

        $data = $plan ? json_decode($plan->plan_data, true) : null;
        if (!$data || !isset($data['days'])) {
            $data = ['days' => array_fill_keys(self::DAYS, [])];
        }

        return response()->json([
            'room_id' => $roomId,
            'week_starting' => $week,
            'theme' => $plan->theme ?? null,
            'plan' => $data,
            'updated_at' => $plan->updated_at ?? null,
            'updated_by_id' => $plan->created_by_id ?? null,
        ]);
    }

    /**
     * PUT /api/v1/provider/lesson-plans
     * Upsert (create or replace) a lesson plan for a room+week.
     */
    public function upsert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['required', 'integer'],
            'week_starting' => ['required', 'date'],
            'theme' => ['nullable', 'string', 'max:160'],
            'plan' => ['required', 'array'],
            'plan.days' => ['required', 'array'],
        ]);

        $user = $request->user();
        if (! $this->hasRoomAccess($user->id, $data['room_id'])) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Sanitize: ensure each day is an array of activity objects
        $cleanDays = [];
        foreach (self::DAYS as $day) {
            $cleanDays[$day] = [];
            if (isset($data['plan']['days'][$day]) && is_array($data['plan']['days'][$day])) {
                foreach ($data['plan']['days'][$day] as $act) {
                    if (!is_array($act)) continue;
                    $cleanDays[$day][] = [
                        'time' => substr((string)($act['time'] ?? ''), 0, 10),
                        'title' => substr((string)($act['title'] ?? ''), 0, 200),
                        'domain' => in_array($act['domain'] ?? '', [
                            'social_emotional', 'physical', 'language_literacy',
                            'cognitive', 'creative_arts', 'self_care', 'outdoor'
                        ]) ? $act['domain'] : null,
                        'notes' => substr((string)($act['notes'] ?? ''), 0, 500),
                    ];
                }
            }
        }

        $planJson = json_encode(['days' => $cleanDays]);

        $existing = DB::table('lesson_plans')
            ->where('room_id', $data['room_id'])
            ->where('week_starting', $data['week_starting'])
            ->first();

        if ($existing) {
            DB::table('lesson_plans')->where('id', $existing->id)->update([
                'theme' => $data['theme'] ?? null,
                'plan_data' => $planJson,
                'updated_at' => now(),
            ]);
            $planId = $existing->id;
        } else {
            $planId = DB::table('lesson_plans')->insertGetId([
                'room_id' => $data['room_id'],
                'week_starting' => $data['week_starting'],
                'theme' => $data['theme'] ?? null,
                'plan_data' => $planJson,
                'created_by_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('audit_logs')->insert([
            'user_id' => $user->id,
            'action' => 'lesson_plan.saved',
            'entity_type' => 'lesson_plan',
            'entity_id' => $planId,
            'payload' => json_encode([
                'room_id' => $data['room_id'],
                'week_starting' => $data['week_starting'],
                'activity_count' => array_sum(array_map('count', $cleanDays)),
            ]),
            'created_at' => now(),
        ]);

        return response()->json(['success' => true, 'lesson_plan_id' => $planId]);
    }

    /**
     * GET /api/v1/provider/lesson-plans/list?room_id=X
     * History of past plans for a room.
     */
    public function listForRoom(Request $request): JsonResponse
    {
        $roomId = (int) $request->input('room_id');
        if (! $this->hasRoomAccess($request->user()->id, $roomId)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $plans = DB::table('lesson_plans')
            ->where('room_id', $roomId)
            ->orderByDesc('week_starting')
            ->limit(26) // ~6 months
            ->get(['id', 'week_starting', 'theme', 'updated_at']);

        return response()->json(['plans' => $plans]);
    }

    /**
     * GET /api/v1/parent/lesson-plan/{child}
     * Parent sees this week's lesson plan for their child's room.
     */
    public function parentShow(Request $request, int $childId): JsonResponse
    {
        $user = $request->user();
        $isGuardian = DB::table('guardians')
            ->join('children', 'children.family_id', '=', 'guardians.family_id')
            ->where('guardians.user_id', $user->id)
            ->where('children.id', $childId)
            ->exists();
        if (! $isGuardian) return response()->json(['message' => 'Forbidden'], 403);

        // Find current room via active enrollment
        $enrollment = DB::table('enrollments')
            ->where('child_id', $childId)
            ->where('start_date', '<=', now()->toDateString())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('start_date')
            ->first();

        if (! $enrollment) return response()->json(['message' => 'No active enrollment'], 404);

        $week = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $plan = DB::table('lesson_plans')
            ->where('room_id', $enrollment->room_id)
            ->where('week_starting', $week)
            ->first();

        if (! $plan) {
            return response()->json([
                'room_id' => $enrollment->room_id,
                'week_starting' => $week,
                'plan' => ['days' => array_fill_keys(self::DAYS, [])],
                'theme' => null,
                'message' => 'No plan posted yet for this week.',
            ]);
        }

        return response()->json([
            'room_id' => $plan->room_id,
            'week_starting' => $plan->week_starting,
            'theme' => $plan->theme,
            'plan' => json_decode($plan->plan_data, true),
        ]);
    }

    private function hasRoomAccess(int $userId, int $roomId): bool
    {
        $room = DB::table('rooms')->where('id', $roomId)->first();
        if (! $room) return false;
        $centre = DB::table('centres')->where('id', $room->centre_id)->first();
        if (! $centre) return false;
        return DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
            ->where('active', true)
            ->where(function ($q) use ($room, $centre) {
                $q->where('centre_id', $room->centre_id)
                  ->orWhere('agency_id', $centre->agency_id);
            })
            ->exists();
    }
}
