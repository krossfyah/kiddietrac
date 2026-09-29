<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Weekly lesson plan templates (2026-09-29).
 *
 * Anthony: "add lesson plan templates that educators can choose from optionally for each
 * week (allow them to review it first before choosing)".
 *
 * Two kinds, one list:
 *   - KiddieTrac's library (agency_id NULL), seeded by the migration;
 *   - an agency's own, saved from a week in the planner ("Save as template").
 * A template is the same shape as lesson_plans.plan_data, so using one is just filling
 * the planner -- the educator reviews it there and nothing is saved until they press Save.
 */
class LessonPlanTemplateController extends Controller
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
    private const DOMAINS = ['social_emotional', 'physical', 'language_literacy', 'cognitive', 'creative_arts', 'self_care', 'outdoor'];
    private const AGES = ['infant', 'toddler', 'preschool', 'school_age', 'mixed'];

    /** GET /provider/lesson-plan-templates */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $uid = (int) $request->user()->id;
        $canManage = $this->canManage($uid, $agencyId);
        $rows = DB::table('lesson_plan_templates as t')
            ->leftJoin('users as u', 'u.id', '=', 't.created_by_id')
            ->where('t.active', 1)
            ->where(function ($q) use ($agencyId) {
                $q->whereNull('t.agency_id')->orWhere('t.agency_id', $agencyId);
            })
            ->orderByRaw('t.agency_id IS NULL')        // the agency's own first
            ->orderBy('t.title')
            ->get(['t.*', DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as created_by")]);

        return response()->json(['templates' => $rows->map(function ($t) use ($uid, $canManage) {
            $plan = json_decode((string) $t->plan_data, true) ?: ['days' => []];
            $n = 0;
            foreach (self::DAYS as $d) { $n += count($plan['days'][$d] ?? []); }
            return [
                'id' => (int) $t->id,
                'title' => $t->title,
                'theme' => $t->theme,
                'age_group' => $t->age_group,
                'description' => $t->description,
                'source' => $t->agency_id ? 'agency' : 'kiddietrac',
                'created_by' => $t->agency_id ? ($t->created_by ?: null) : null,
                'activity_count' => $n,
                'use_count' => (int) $t->use_count,
                'plan' => $plan,
                'can_delete' => $t->agency_id && ((int) $t->created_by_id === $uid || $canManage),
            ];
        })->values()]);
    }

    /** POST /provider/lesson-plan-templates -- save a week as one of the agency's templates. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:80'],
            'theme' => ['nullable', 'string', 'max:160'],
            'age_group' => ['required', 'in:' . implode(',', self::AGES)],
            'description' => ['nullable', 'string', 'max:300'],
            'plan' => ['required', 'array'],
            'plan.days' => ['required', 'array'],
        ]);
        $days = self::cleanDays($data['plan']['days']);
        $count = array_sum(array_map('count', $days));
        if ($count === 0) {
            return response()->json(['message' => 'This week has no activities yet, so there is nothing to save as a template.'], 422);
        }
        $id = DB::table('lesson_plan_templates')->insertGetId([
            'agency_id' => $agencyId,
            'title' => trim($data['title']),
            'theme' => trim((string) ($data['theme'] ?? '')) ?: trim($data['title']),
            'age_group' => $data['age_group'],
            'description' => trim((string) ($data['description'] ?? '')) ?: null,
            'plan_data' => json_encode(['days' => $days]),
            'active' => 1,
            'use_count' => 0,
            'created_by_id' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, $agencyId, 'lesson_template.created', $id, 'Saved lesson plan template "' . trim($data['title']) . '" (' . $count . ' activities)');

        return response()->json(['id' => $id], 201);
    }

    /** DELETE /provider/lesson-plan-templates/{id} -- the agency's own only; hidden, not erased. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $t = DB::table('lesson_plan_templates')->where('id', $id)->where('agency_id', $agencyId)->where('active', 1)->first();
        abort_unless($t, 404, 'That template was not found.');
        $uid = (int) $request->user()->id;
        abort_unless((int) $t->created_by_id === $uid || $this->canManage($uid, $agencyId), 403,
            'Only the person who saved it, a director or an agency admin can remove this template.');
        DB::table('lesson_plan_templates')->where('id', $id)->update(['active' => 0, 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'lesson_template.removed', $id, 'Removed lesson plan template "' . $t->title . '"');

        return response()->json(['removed' => true]);
    }

    /** POST /provider/lesson-plan-templates/{id}/used -- counted when a template fills a week. */
    public function used(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        DB::table('lesson_plan_templates')->where('id', $id)
            ->where(function ($q) use ($agencyId) { $q->whereNull('agency_id')->orWhere('agency_id', $agencyId); })
            ->increment('use_count');

        return response()->json(['ok' => true]);
    }

    /** Same sanitising as LessonPlanController::upsert, so a template can always be saved as a plan. */
    public static function cleanDays(array $in): array
    {
        $out = [];
        foreach (self::DAYS as $day) {
            $out[$day] = [];
            foreach ((is_array($in[$day] ?? null) ? $in[$day] : []) as $a) {
                if (! is_array($a) || trim((string) ($a['title'] ?? '')) === '') continue;
                $out[$day][] = [
                    'time' => substr((string) ($a['time'] ?? ''), 0, 10),
                    'title' => substr((string) $a['title'], 0, 200),
                    'domain' => in_array($a['domain'] ?? '', self::DOMAINS, true) ? $a['domain'] : null,
                    'notes' => substr((string) ($a['notes'] ?? ''), 0, 500),
                ];
            }
        }
        return $out;
    }

    private function canManage(int $uid, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $uid)->where('active', 1)
            ->where(function ($q) use ($agencyId) {
                $q->where('role', 'platform_admin')
                  ->orWhere(fn ($w) => $w->whereIn('role', ['agency_admin', 'centre_director'])->where('agency_id', $agencyId));
            })->exists();
    }

    private function audit(Request $request, int $agencyId, string $action, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action,
                'entity_type' => 'lesson_plan_template', 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
    }

    private function agencyId(Request $request): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
                ->where(function ($q) use ($activeId) { $q->where('role', 'platform_admin')->orWhere('agency_id', $activeId); })->exists()) {
            return $activeId;
        }
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->value('agency_id');
        abort_unless($first, 400);

        return (int) $first;
    }
}
