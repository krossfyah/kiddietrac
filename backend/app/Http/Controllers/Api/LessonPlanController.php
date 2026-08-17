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
     * v22p84: a plan can target a single room (room_id) OR a whole centre
     * (centre_id, room_id null). Pass centre_id to read/write the centre-wide plan.
     */
    public function show(Request $request): JsonResponse
    {
        $roomId   = $request->filled('room_id') ? (int) $request->input('room_id') : null;
        $centreId = $request->filled('centre_id') ? (int) $request->input('centre_id') : null;
        $week = $request->input('week_starting');
        if (!$week) {
            // Default to this week's Monday
            $week = Carbon::now()->startOfWeek(Carbon::MONDAY)->toDateString();
        }

        $query = DB::table('lesson_plans')->where('week_starting', $week);
        if ($centreId && !$roomId) {
            if (! $this->hasCentreAccess($request->user()->id, $centreId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('centre_id', $centreId)->whereNull('room_id');
        } else {
            if (! $this->hasRoomAccess($request->user()->id, $roomId)) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
            $query->where('room_id', $roomId);
        }
        $plan = $query->first();

        // Centre-wide fallback: a room with no plan of its OWN inherits its
        // centre's centre-wide plan for the week. This is the behaviour the UI
        // already promises ("applies to every room in the centre") and the reason
        // an educator saw an empty week after an admin used "Use template → whole
        // centre" — the room-scoped read never looked at the centre plan.
        $inheritedFromCentre = false;
        if (!$plan && $roomId) {
            $roomCentreId = DB::table('rooms')->where('id', $roomId)->value('centre_id');
            if ($roomCentreId) {
                $plan = DB::table('lesson_plans')
                    ->where('week_starting', $week)
                    ->where('centre_id', $roomCentreId)
                    ->whereNull('room_id')
                    ->first();
                if ($plan) {
                    $inheritedFromCentre = true;
                }
            }
        }

        $data = $plan ? json_decode($plan->plan_data, true) : null;
        if (!$data || !isset($data['days'])) {
            $data = ['days' => array_fill_keys(self::DAYS, [])];
        }

        return response()->json([
            'scope' => ($centreId && !$roomId) ? 'centre' : 'room',
            'room_id' => $roomId,
            'centre_id' => $centreId,
            'week_starting' => $week,
            'theme' => $plan->theme ?? null,
            'plan' => $data,
            'inherited_from_centre' => $inheritedFromCentre,
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
            'room_id' => ['nullable', 'integer', 'required_without:centre_id'],
            'centre_id' => ['nullable', 'integer', 'required_without:room_id'],
            'week_starting' => ['required', 'date'],
            'theme' => ['nullable', 'string', 'max:160'],
            'plan' => ['required', 'array'],
            'plan.days' => ['required', 'array'],
        ]);

        $user = $request->user();
        // v22p84: room OR centre scope. Centre-wide plans store centre_id with a
        // null room_id; room plans store room_id (centre_id null).
        $isCentre = !empty($data['centre_id']) && empty($data['room_id']);
        if ($isCentre) {
            if (! $this->hasCentreAccess($user->id, (int) $data['centre_id'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
        } else {
            if (! $this->hasRoomAccess($user->id, (int) $data['room_id'])) {
                return response()->json(['message' => 'Forbidden'], 403);
            }
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

        $existingQuery = DB::table('lesson_plans')->where('week_starting', $data['week_starting']);
        if ($isCentre) {
            $existingQuery->where('centre_id', (int) $data['centre_id'])->whereNull('room_id');
        } else {
            $existingQuery->where('room_id', (int) $data['room_id']);
        }
        $existing = $existingQuery->first();

        if ($existing) {
            DB::table('lesson_plans')->where('id', $existing->id)->update([
                'theme' => $data['theme'] ?? null,
                'plan_data' => $planJson,
                'updated_at' => now(),
            ]);
            $planId = $existing->id;
        } else {
            $planId = DB::table('lesson_plans')->insertGetId([
                'room_id' => $isCentre ? null : (int) $data['room_id'],
                'centre_id' => $isCentre ? (int) $data['centre_id'] : null,
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
                'scope' => $isCentre ? 'centre' : 'room',
                'room_id' => $data['room_id'] ?? null,
                'centre_id' => $data['centre_id'] ?? null,
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

        // v22p84: fall back to the centre-wide plan if the room has none this week.
        if (! $plan) {
            $room = DB::table('rooms')->where('id', $enrollment->room_id)->first();
            if ($room) {
                $plan = DB::table('lesson_plans')
                    ->where('centre_id', $room->centre_id)
                    ->whereNull('room_id')
                    ->where('week_starting', $week)
                    ->first();
            }
        }

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

    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) return false;
        $has = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])
            ->where('active', true)
            ->where(function ($q) use ($centreId, $centre) {
                $q->where('centre_id', $centreId)
                  ->orWhere('agency_id', $centre->agency_id);
            })
            ->exists();
        if ($has) return true;
        // v22p98: platform_admin scoped to the agency they've switched into.
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        return $isPlatform && (int) $centre->agency_id === (int) request()->header('X-Active-Agency-Id');
    }

    private function hasRoomAccess(int $userId, ?int $roomId): bool
    {
        if (! $roomId) return false;
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
