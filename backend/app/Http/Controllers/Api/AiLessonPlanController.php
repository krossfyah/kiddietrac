<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AiLessonPlanService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AiLessonPlanController v21
 *
 *   POST /director/lesson-plans-ai/generate   - call AI, return draft (NOT saved)
 *   POST /director/lesson-plans-ai/save       - save (with edits)
 *   GET  /director/lesson-plans-ai            - list saved plans
 *   POST /director/lesson-plans-ai/{id}/publish - mark published
 */
class AiLessonPlanController extends Controller
{
    public function __construct(protected AiLessonPlanService $ai)
    {
    }

    public function generate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id'      => 'required|integer|exists:centres,id',
            'room_id'        => 'nullable|integer|exists:rooms,id',
            'age_group'      => 'required|in:infant,toddler,preschool,kindergarten',
            'theme'          => 'required|string|min:3|max:160',
            'week_starting'  => 'required|date_format:Y-m-d',
            'starter_notes'  => 'nullable|string|max:1000',
            'room_name'      => 'nullable|string|max:80',
        ]);

        if (! in_array((int) $data['centre_id'], $this->callerCentreIds($request), true)) {
            return response()->json(['error' => 'That centre is not in your agency.'], 403);
        }

        $result = $this->ai->generate($data);

        if (! ($result['success'] ?? false)) {
            return response()->json([
                'error'  => 'AI generation failed',
                'detail' => $result['error'] ?? 'Unknown error',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'plan'    => $result['plan'],
            'meta'    => [
                'model'       => $result['model'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
            ],
        ]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'centre_id'      => 'required|integer|exists:centres,id',
            'room_id'        => 'nullable|integer|exists:rooms,id',
            'age_group'      => 'required|string|max:60',
            'theme'          => 'required|string|max:160',
            'week_starting'  => 'required|date_format:Y-m-d',
            'plan_body'      => 'required|array',
            'source_prompt'  => 'nullable|string|max:2000',
            'model_used'     => 'nullable|string|max:80',
            'tokens_used'    => 'nullable|integer',
            'published'      => 'nullable|boolean',
        ]);

        if (! in_array((int) $data['centre_id'], $this->callerCentreIds($request), true)) {
            return response()->json(['error' => 'That centre is not in your agency.'], 403);
        }

        $row = [
            'centre_id'     => $data['centre_id'],
            'room_id'       => $data['room_id'] ?? null,
            'created_by_id' => $request->user()->id,
            'week_starting' => $data['week_starting'],
            'theme'         => $data['theme'],
            'age_group'     => $data['age_group'],
            'plan_body'     => json_encode($data['plan_body']),
            'source_prompt' => $data['source_prompt'] ?? null,
            'model_used'    => $data['model_used'] ?? null,
            'tokens_used'   => $data['tokens_used'] ?? null,
            'published'     => $data['published'] ?? false,
            'generated_at'  => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ];
        $id = DB::table('ai_lesson_plans')->insertGetId($row);

        return response()->json([
            'success' => true,
            'id'      => $id,
        ], 201);
    }

    /**
     * Centres the caller may see AI lesson plans for. TENANT ISOLATION: without
     * this the list returned EVERY agency's plans (so an agency saw another
     * agency's demo/published plans it never created). platform_admin → the
     * switched-into agency; agency_admin → all their agency's centres; others →
     * the centres they're assigned to.
     */
    private function callerCentreIds(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($isPlatform) {
            $aid = (int) $request->header('X-Active-Agency-Id');
            return ($aid && DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists())
                ? DB::table('centres')->where('agency_id', $aid)->pluck('id')->all() : [];
        }
        $centreIds = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->all();
        $agencyIds = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)->where('role', 'agency_admin')->whereNotNull('agency_id')->pluck('agency_id')->all();
        if ($agencyIds) {
            $centreIds = array_merge($centreIds, DB::table('centres')->whereIn('agency_id', $agencyIds)->pluck('id')->all());
        }
        return array_values(array_unique(array_map('intval', $centreIds)));
    }

    public function index(Request $request): JsonResponse
    {
        $allowed = $this->callerCentreIds($request);
        $q = DB::table('ai_lesson_plans')
            ->whereIn('centre_id', $allowed ?: [0])   // agency-scoped — no cross-tenant plans
            ->orderByDesc('week_starting')
            ->limit(50);

        if ($centreId = $request->query('centre_id')) {
            $q->where('centre_id', (int) $centreId);
        }
        if ($roomId = $request->query('room_id')) {
            $q->where('room_id', (int) $roomId);
        }

        $rows = $q->get()->map(function ($r) {
            $body = $r->plan_body;
            $decoded = is_string($body) ? json_decode($body, true) : $body;
            return [
                'id'             => $r->id,
                'centre_id'      => $r->centre_id,
                'room_id'        => $r->room_id,
                'week_starting'  => $r->week_starting,
                'theme'          => $r->theme,
                'age_group'      => $r->age_group,
                'plan_body'      => $decoded,
                'published'      => (bool) $r->published,
                'generated_at'   => $r->generated_at,
            ];
        });

        return response()->json(['plans' => $rows]);
    }

    public function publish(Request $request, int $id): JsonResponse
    {
        // Only publish a plan in the caller's own agency (was an IDOR — any user
        // could publish any agency's plan by id).
        $updated = DB::table('ai_lesson_plans')
            ->where('id', $id)
            ->whereIn('centre_id', $this->callerCentreIds($request) ?: [0])
            ->update(['published' => true, 'updated_at' => now()]);

        if ($updated === 0) {
            return response()->json(['error' => 'Not found'], 404);
        }
        return response()->json(['success' => true]);
    }
}
