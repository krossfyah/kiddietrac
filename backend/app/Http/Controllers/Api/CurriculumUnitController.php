<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CurriculumUnits;
use App\Support\LessonImages;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Ontario curriculum library (2026-11-03, Anthony chose it from the Brightwheel comparison).
 *
 *   GET    /curriculum/units                  the library + this agency's own copies (filters: age, foundation, season, theme, q)
 *   GET    /curriculum/units/{id}             one unit, in full (preview page)
 *   POST   /curriculum/units/{id}/use         draft weekly lesson plans for a room / centre from a start week
 *   POST   /curriculum/units/{id}/copy        directors + admins: copy into the agency, to edit
 *   PUT    /curriculum/units/{id}             directors + admins: edit the agency's copy
 *   DELETE /curriculum/units/{id}             directors + admins: delete the agency's copy
 *
 * Library units are platform content (agency_id 0, visibility 'platform') and are never
 * written from here. Every read is limited to the library plus the ACTIVE agency's rows.
 * "Use" follows the planner's own permission (LessonPlanController::hasRoomAccess /
 * hasCentreAccess): anyone who can save a lesson plan for that room can use a unit, and what
 * it writes is always a DRAFT - families never see it until a staff member publishes.
 */
final class CurriculumUnitController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agency($request);
        $q = $this->scoped($agencyId);
        if (in_array($a = (string) $request->query('age', ''), CurriculumUnits::AGES, true)) $q->where('age_band', $a);
        if (in_array($s = (string) $request->query('season', ''), CurriculumUnits::SEASONS, true)) $q->where('season', $s);
        if (in_array($f = (string) $request->query('foundation', ''), CurriculumUnits::FOUNDATIONS, true)) $q->whereRaw('FIND_IN_SET(?, foundations)', [$f]);
        if (($t = trim((string) $request->query('theme', ''))) !== '') $q->where('theme', mb_substr($t, 0, 60));
        if (($term = trim((string) $request->query('q', ''))) !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($term, 0, 80)) . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)->orWhere('description', 'like', $like)
                ->orWhere('theme', 'like', $like)->orWhere('unit_data', 'like', $like));
        }
        $rows = $q->orderByRaw("FIELD(age_band,'infant','toddler','preschool','school_age')")
            ->orderByRaw('agency_id = 0')->orderBy('title')
            ->get(['id', 'agency_id', 'slug', 'title', 'description', 'age_band', 'theme', 'season', 'weeks', 'foundations', 'unit_data', 'source_id', 'use_count', 'updated_at']);

        $themes = $this->scoped($agencyId)->whereNotNull('theme')->distinct()->orderBy('theme')->pluck('theme');

        return response()->json([
            'units' => $rows->map(fn ($r) => $this->summary($r))->values(),
            'themes' => $themes,
            'can_manage' => $this->canManage($request, $agencyId),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        $row = $this->scoped($agencyId)->where('id', $id)->first();
        abort_unless($row, 404, 'That unit was not found.');
        $unit = json_decode((string) $row->unit_data, true) ?: [];
        $copies = $row->agency_id ? collect() : DB::table('curriculum_templates')->where('kind', 'unit')
            ->where('agency_id', $agencyId)->where('source_id', $row->id)->orderBy('id')->get(['id', 'title', 'updated_at']);

        return response()->json([
            'unit' => $this->summary($row) + ['data' => $unit],
            'copies' => $copies,
            'can_manage' => $this->canManage($request, $agencyId),
        ]);
    }

    public function copy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        abort_unless($this->canManage($request, $agencyId), 403, 'Only a director or admin can copy a unit.');
        $row = $this->scoped($agencyId)->where('id', $id)->first();
        abort_unless($row, 404, 'That unit was not found.');
        $unit = CurriculumUnits::clean(json_decode((string) $row->unit_data, true) ?: []);
        $unit['slug'] = '';                         // a copy has no library slug: the loader never touches it
        $newId = DB::table('curriculum_templates')->insertGetId(CurriculumUnits::columns($unit) + [
            'agency_id' => $agencyId,
            'centre_id' => null,
            'visibility' => 'agency',
            'is_featured' => 0,
            'use_count' => 0,
            'source_id' => $row->agency_id ? ($row->source_id ?: $row->id) : $row->id,
            'created_by_user_id' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'curriculum.unit_copied',
            'entity_type' => 'curriculum_unit', 'entity_id' => $newId,
            'payload' => json_encode(['summary' => 'Copied the curriculum unit "' . $unit['title'] . '"', 'from_id' => $row->id, 'title' => $unit['title']]),
            'created_at' => now()]);

        return response()->json(['id' => $newId], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        abort_unless($this->canManage($request, $agencyId), 403, 'Only a director or admin can edit a unit.');
        $row = DB::table('curriculum_templates')->where('kind', 'unit')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'Only your own copy of a unit can be edited. Copy the unit first.');
        $request->validate(['unit' => 'required|array', 'unit.title' => 'required|string|max:120', 'unit.activities' => 'required|array|min:1']);
        $unit = CurriculumUnits::clean($request->input('unit'));
        $unit['slug'] = '';
        if (! $unit['activities']) {
            return response()->json(['message' => 'A unit needs at least one activity with a title.'], 422);
        }
        DB::table('curriculum_templates')->where('id', $id)->update(CurriculumUnits::columns($unit) + [
            'updated_by_user_id' => $request->user()->id, 'updated_at' => now()]);
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'curriculum.unit_updated',
            'entity_type' => 'curriculum_unit', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Edited the curriculum unit "' . $unit['title'] . '"', 'title' => $unit['title'], 'activities' => count($unit['activities']), 'weeks' => $unit['weeks']]),
            'created_at' => now()]);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->agency($request);
        abort_unless($this->canManage($request, $agencyId), 403, 'Only a director or admin can delete a unit.');
        $row = DB::table('curriculum_templates')->where('kind', 'unit')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($row, 404, 'Only your own copy of a unit can be deleted.');
        DB::table('curriculum_templates')->where('id', $id)->delete();
        Audit::write(['user_id' => $request->user()->id, 'agency_id' => $agencyId, 'action' => 'curriculum.unit_deleted',
            'entity_type' => 'curriculum_unit', 'entity_id' => $id,
            'payload' => json_encode(['summary' => 'Deleted the curriculum unit "' . $row->title . '"', 'title' => $row->title]), 'created_at' => now()]);

        return response()->json(['ok' => true]);
    }

    /** Draft lesson plans, one per unit week, from the start week on. Weeks that already have activities are left alone. */
    public function use(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'room_id' => ['nullable', 'integer', 'required_without:centre_id'],
            'centre_id' => ['nullable', 'integer', 'required_without:room_id'],
            'week_starting' => ['required', 'date'],
            'weeks' => ['nullable', 'array'],
            'weeks.*' => ['integer', 'min:1', 'max:6'],
        ]);
        $agencyId = $this->agency($request);
        $row = $this->scoped($agencyId)->where('id', $id)->first();
        abort_unless($row, 404, 'That unit was not found.');
        $uid = (int) $request->user()->id;
        $isCentre = ! empty($data['centre_id']) && empty($data['room_id']);
        $roomId = $isCentre ? null : (int) $data['room_id'];
        $centreId = $isCentre ? (int) $data['centre_id'] : (int) DB::table('rooms')->where('id', $roomId)->value('centre_id');
        abort_unless($isCentre ? $this->hasCentreAccess($uid, $centreId) : $this->hasRoomAccess($uid, $roomId), 403, 'You cannot plan for that room.');
        // The destination must be in the agency being worked in (never another tenant's room).
        $targetAgency = (int) DB::table('centres')->where('id', $centreId)->value('agency_id');
        abort_unless($targetAgency === $agencyId, 403, 'That room is not in this agency.');
        abort_unless(\App\Support\Live::centreOpen($centreId), 422, 'That centre is closed.');

        $unit = CurriculumUnits::clean(json_decode((string) $row->unit_data, true) ?: []);
        $wanted = array_values(array_unique(array_filter(array_map('intval', $data['weeks'] ?? range(1, $unit['weeks'])), fn ($w) => $w >= 1 && $w <= $unit['weeks'])));
        sort($wanted);
        abort_unless($wanted, 422, 'Choose at least one week of the unit.');
        $start = Carbon::parse($data['week_starting'])->startOfWeek(Carbon::MONDAY);

        $created = [];
        $skipped = [];
        foreach ($wanted as $i => $w) {
            $week = $start->copy()->addWeeks($w - $wanted[0])->toDateString();
            $q = DB::table('lesson_plans')->where('week_starting', $week);
            $isCentre ? $q->where('centre_id', $centreId)->whereNull('room_id') : $q->where('room_id', $roomId);
            $existing = $q->first();
            if ($existing) {
                $old = json_decode((string) $existing->plan_data, true) ?: [];
                $n = 0;
                foreach (CurriculumUnits::DAYS as $d) $n += count((array) ($old['days'][$d] ?? []));
                if ($n > 0) {
                    $skipped[] = ['week_starting' => $week, 'unit_week' => $w, 'status' => $existing->status ?: 'published',
                        'reason' => ($existing->status === 'draft' ? 'already has a draft' : 'already has a published plan') . " ($n activities)"];
                    continue;
                }
            }
            $plan = ['days' => CurriculumUnits::weekDays($unit, $w), 'unit' => CurriculumUnits::planMeta($unit, (int) $row->id, $w)];
            $fields = [
                'theme' => mb_substr($unit['title'] . ($unit['weeks'] > 1 ? ' — week ' . $w : ''), 0, 160),
                'plan_data' => json_encode($plan, JSON_UNESCAPED_UNICODE),
                'status' => 'draft',
                'published_at' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('lesson_plans')->where('id', $existing->id)->update($fields);
                $planId = (int) $existing->id;
            } else {
                $planId = (int) DB::table('lesson_plans')->insertGetId($fields + [
                    'room_id' => $roomId, 'centre_id' => $isCentre ? $centreId : null, 'week_starting' => $week,
                    'created_by_id' => $uid, 'created_at' => now()]);
            }
            $created[] = ['week_starting' => $week, 'unit_week' => $w, 'lesson_plan_id' => $planId,
                'activities' => array_sum(array_map('count', $plan['days']))];
        }
        if ($created) {
            DB::table('curriculum_templates')->where('id', $row->id)->update(['use_count' => DB::raw('COALESCE(use_count,0) + 1')]);
        }
        Audit::write(['user_id' => $uid, 'agency_id' => $agencyId, 'action' => 'curriculum.unit_used',
            'entity_type' => 'curriculum_unit', 'entity_id' => (int) $row->id,
            'payload' => json_encode(['summary' => 'Used the curriculum unit "' . $unit['title'] . '" for ' . count($created) . ' draft week(s)',
                'title' => $unit['title'], 'room_id' => $roomId, 'centre_id' => $isCentre ? $centreId : null,
                'weeks' => array_column($created, 'week_starting'), 'skipped' => array_column($skipped, 'week_starting'),
                'lesson_plan_ids' => array_column($created, 'lesson_plan_id')]),
            'created_at' => now()]);

        return response()->json(['created' => $created, 'skipped' => $skipped,
            'scope' => $isCentre ? 'centre' : 'room', 'room_id' => $roomId, 'centre_id' => $centreId,
            'pictures' => ['auto' => LessonImages::autoOn($agencyId)]]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** The library plus the active agency's own units. Nothing else is ever selected. */
    private function scoped(int $agencyId)
    {
        return DB::table('curriculum_templates')->where('kind', 'unit')
            ->where(fn ($w) => $w->where(fn ($l) => $l->where('agency_id', 0)->where('visibility', 'platform'))
                ->orWhere('agency_id', $agencyId));
    }

    private function summary(object $r): array
    {
        $u = json_decode((string) $r->unit_data, true) ?: [];
        $acts = (array) ($u['activities'] ?? []);

        return [
            'id' => (int) $r->id,
            'slug' => $r->slug,
            'title' => $r->title,
            'summary' => $r->description,
            'age_group' => $r->age_band,
            'age_label' => $u['age_label'] ?? null,
            'theme' => $r->theme,
            'season' => $r->season,
            'weeks' => (int) $r->weeks,
            'foundations' => $r->foundations ? explode(',', $r->foundations) : [],
            'activity_count' => count($acts),
            'source' => (int) $r->agency_id === 0 ? 'library' : 'agency',
            'source_id' => $r->source_id ? (int) $r->source_id : null,
            'use_count' => (int) $r->use_count,
            'updated_at' => $r->updated_at,
        ];
    }

    /** The agency being worked in: the X-Active-Agency-Id header when the person belongs to it, else their only agency. Fails closed. */
    private function agency(Request $request): int
    {
        $uid = (int) $request->user()->id;
        $header = (int) $request->header('X-Active-Agency-Id');
        $isPlatform = DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists();
        if ($header > 0) {
            if ($isPlatform || \App\Support\AgencyRole::activeAgency($request) === $header) return $header;
        }
        abort_if($isPlatform, 400, 'Select an agency first.');
        // A role row carries its agency directly, or through its centre (educators often have only a centre).
        $ids = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $uid)->where('ra.active', true)
            ->whereIn('ra.role', ['educator', 'home_visitor', 'centre_director', 'agency_admin'])
            ->selectRaw('DISTINCT COALESCE(ra.agency_id, c.agency_id) as a')->pluck('a')->filter()->unique()->values()->all();
        abort_unless(count($ids) === 1, 400, 'Select an agency first.');

        return (int) $ids[0];
    }

    private function canManage(Request $request, int $agencyId): bool
    {
        $uid = (int) $request->user()->id;

        return DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where(fn ($x) => $x->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director']))
                  ->orWhere('role', 'platform_admin');
            })->exists();
    }

    /* The planner's own access rules (LessonPlanController), so "Use" is exactly as permissive as Save. */
    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) return false;
        $has = DB::table('role_assignments')->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])->where('active', true)
            ->where(fn ($q) => $q->where('centre_id', $centreId)->orWhere('agency_id', $centre->agency_id))->exists();
        if ($has) return true;
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
        $has = DB::table('role_assignments')->where('user_id', $userId)
            ->whereIn('role', ['educator', 'centre_director', 'agency_admin'])->where('active', true)
            ->where(fn ($q) => $q->where('centre_id', $room->centre_id)->orWhere('agency_id', $centre->agency_id))->exists();
        if ($has) return true;
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();

        return $isPlatform && (int) $centre->agency_id === (int) request()->header('X-Active-Agency-Id');
    }
}
