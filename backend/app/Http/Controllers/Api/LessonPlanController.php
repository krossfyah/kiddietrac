<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiBundle;
use App\Support\LessonImages;
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
            $week = \App\Support\AgencyTime::todayDate()->startOfWeek(Carbon::MONDAY)->toDateString();
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
        // Pictures (2026-11-02): credit lines for looked-up pictures, the theme's cover, and the agency's settings.
        $data['days'] = LessonImages::decorate($data['days']);
        $agencyId = $this->scopeAgency($roomId, ($centreId && !$roomId) ? $centreId : null);

        return response()->json([
            'scope' => ($centreId && !$roomId) ? 'centre' : 'room',
            'room_id' => $roomId,
            'centre_id' => $centreId,
            'week_starting' => $week,
            'theme' => $plan->theme ?? null,
            'status' => $plan->status ?? 'published',
            'published_at' => $plan->published_at ?? null,
            'plan' => $data,
            'cover' => LessonImages::cover($plan),
            'images' => [
                'auto' => LessonImages::autoOn($agencyId),
                'ai' => AiBundle::on($agencyId),
                'can_manage' => $agencyId ? $this->canManageAgency($request->user()->id, $agencyId) : false,
            ],
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
            /* Absent means published, so existing clients that know nothing about
               draft mode keep behaving exactly as they did. */
            'status' => ['nullable', 'string', 'in:draft,published'],
            'plan' => ['required', 'array'],
            'plan.days' => ['required', 'array'],
            // The theme's cover picture; absent = keep whatever the saved plan has.
            'cover' => ['nullable', 'array'],
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

        $existingQuery = DB::table('lesson_plans')->where('week_starting', $data['week_starting']);
        if ($isCentre) {
            $existingQuery->where('centre_id', (int) $data['centre_id'])->whereNull('room_id');
        } else {
            $existingQuery->where('room_id', (int) $data['room_id']);
        }
        $existing = $existingQuery->first();

        /* SAVING KEEPS THE PICTURES (2026-11-02). The weekly menu used to wipe every dish photo on Save because the
           save rebuilt its rows without them; this rebuilds plan_data the same way, so the picture fields are carried
           explicitly: what the planner sent (checked: our library, this agency's uploads, or a URL the plan already
           had), else — for a client that sent none (templates, the AI draft, an older screen) — the saved activity's
           picture, matched by day and title. */
        $agencyId = (int) ($this->scopeAgency($isCentre ? null : (int) $data['room_id'], $isCentre ? (int) $data['centre_id'] : null) ?? 0);
        $oldData = $existing ? (json_decode((string) $existing->plan_data, true) ?: []) : [];
        $oldDays = is_array($oldData['days'] ?? null) ? $oldData['days'] : [];
        $keep = LessonImages::urlsIn($oldDays, $existing);
        $oldPic = function (string $day, string $title) use ($oldDays): ?array {
            foreach ((array) ($oldDays[$day] ?? []) as $o) {
                if (is_array($o) && mb_strtolower(trim((string) ($o['title'] ?? ''))) === mb_strtolower(trim($title))) {
                    if (! empty($o['image_url']) || ($o['image_source'] ?? null) === 'none') {
                        return array_intersect_key($o, array_flip(LessonImages::FIELDS));
                    }
                    if (! empty($o['image'])) {
                        return ['image_url' => $o['image'], 'thumb_url' => $o['image'], 'image_source' => 'library', 'lesson_image_id' => null];
                    }
                }
            }
            return null;
        };

        // Sanitize: ensure each day is an array of activity objects
        $cleanDays = [];
        foreach (self::DAYS as $day) {
            $cleanDays[$day] = [];
            if (isset($data['plan']['days'][$day]) && is_array($data['plan']['days'][$day])) {
                foreach ($data['plan']['days'][$day] as $act) {
                    if (!is_array($act)) continue;
                    $row = [
                        'time' => substr((string)($act['time'] ?? ''), 0, 10),
                        'title' => substr((string)($act['title'] ?? ''), 0, 200),
                        'domain' => in_array($act['domain'] ?? '', [
                            'social_emotional', 'physical', 'language_literacy',
                            'cognitive', 'creative_arts', 'self_care', 'outdoor'
                        ]) ? $act['domain'] : null,
                        // 1500 (was 500): a curriculum unit's activity carries its materials and steps here.
                        'notes' => mb_substr((string)($act['notes'] ?? ''), 0, 1500),
                    ];
                    /* HDLH foundations (2026-11-03, curriculum library): the Framework gaps report counts
                       planned activities by these, alongside observations. Only the four keys are kept. */
                    $fnd = array_values(array_unique(array_intersect(
                        array_map('strval', (array) ($act['foundations'] ?? [])), \App\Support\CurriculumUnits::FOUNDATIONS)));
                    if ($fnd) $row['foundations'] = $fnd;
                    if (! empty($act['unit_activity']) && is_string($act['unit_activity'])) {
                        $row['unit_activity'] = mb_substr(preg_replace('/[^a-z0-9_-]/', '', strtolower($act['unit_activity'])), 0, 20);
                    }
                    $pic = $agencyId ? LessonImages::clean($act, $agencyId, $keep) : null;
                    if ($pic === null) $pic = $oldPic($day, $row['title']);
                    if ($pic && (! empty($pic['image_url']) || ($pic['image_source'] ?? null) === 'none')) {
                        $row += array_merge(array_fill_keys(LessonImages::FIELDS, null), $pic);
                    }
                    $cleanDays[$day][] = $row;
                }
            }
        }

        /* The curriculum unit a week came from (banner, observation prompts, family ideas). Sent = use it
           (null clears it: the planner sends null after filling the week from a template or the AI);
           not sent at all = an older client, keep what the plan had. */
        $unitMeta = array_key_exists('unit', $data['plan'])
            ? \App\Support\CurriculumUnits::cleanPlanMeta($data['plan']['unit'])
            : \App\Support\CurriculumUnits::cleanPlanMeta($oldData['unit'] ?? null);
        $planJson = json_encode(['days' => $cleanDays] + ($unitMeta ? ['unit' => $unitMeta] : []), JSON_UNESCAPED_UNICODE);

        // The cover: only when the planner sent one; otherwise the saved cover stays as it is.
        $coverUpd = [];
        if (array_key_exists('cover', $data) && is_array($data['cover']) && $agencyId) {
            $c = LessonImages::clean($data['cover'], $agencyId, $keep);
            if ($c !== null) {
                $coverUpd = ['image_url' => $c['image_url'], 'image_source' => $c['image_source'], 'lesson_image_id' => $c['lesson_image_id']];
            }
        }
        // A new theme makes an automatic cover stale; the next fill finds one for the new theme.
        if (! $coverUpd && $existing && ($existing->image_source ?? null) === 'auto'
            && LessonImages::norm($existing->theme ?? '') !== LessonImages::norm($data['theme'] ?? '')) {
            $coverUpd = ['image_url' => null, 'image_source' => null, 'lesson_image_id' => null];
        }

        if ($existing) {
            DB::table('lesson_plans')->where('id', $existing->id)->update([
                'status' => $data['status'] ?? 'published',
                /* Stamp the FIRST time it goes live and leave it alone after that,
                   so re-saving a published plan does not keep moving the date. */
                'published_at' => ($data['status'] ?? 'published') === 'published'
                    ? ($existing->published_at ?? now())
                    : null,
                'theme' => $data['theme'] ?? null,
                'plan_data' => $planJson,
                'updated_at' => now(),
            ] + $coverUpd);
            $planId = $existing->id;
        } else {
            $planId = DB::table('lesson_plans')->insertGetId([
                'room_id' => $isCentre ? null : (int) $data['room_id'],
                'centre_id' => $isCentre ? (int) $data['centre_id'] : null,
                'week_starting' => $data['week_starting'],
                'theme' => $data['theme'] ?? null,
                'plan_data' => $planJson,
                'status' => $data['status'] ?? 'published',
                'published_at' => ($data['status'] ?? 'published') === 'published' ? now() : null,
                'created_by_id' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ] + $coverUpd);
        }

        \App\Support\Audit::write([
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

        // How many activities still want a picture, so the planner knows whether to ask for them (no network here).
        $auto = $agencyId ? LessonImages::autoOn($agencyId) : false;
        $missing = LessonImages::fillPlan((int) $planId, false)['missing'];

        return response()->json(['success' => true, 'lesson_plan_id' => $planId,
            'pictures' => ['auto' => $auto, 'missing' => $missing]]);
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
            ->where('start_date', '<=', \App\Support\AgencyTime::today())
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', \App\Support\AgencyTime::today());
            })
            ->orderByDesc('start_date')
            ->first();

        if (! $enrollment) return response()->json(['message' => 'No active enrollment'], 404);

        $week = \App\Support\AgencyTime::todayDate()->startOfWeek(Carbon::MONDAY)->toDateString();
        $plan = DB::table('lesson_plans')
            /* Families see PUBLISHED plans only. Older rows predate the column and are
                   treated as published, because they have been visible all along. */
                ->where(function ($q) { $q->where('status', 'published')->orWhereNull('status'); })
                ->where('room_id', $enrollment->room_id)
            ->where('week_starting', $week)
            ->first();

        // v22p84: fall back to the centre-wide plan if the room has none this week.
        if (! $plan) {
            $room = DB::table('rooms')->where('id', $enrollment->room_id)->first();
            if ($room) {
                $plan = DB::table('lesson_plans')
                    // Published only, as for the room plan above (the centre fallback let drafts through).
                    ->where(function ($q) { $q->where('status', 'published')->orWhereNull('status'); })
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

        $pd = json_decode($plan->plan_data, true) ?: [];
        $pd['days'] = LessonImages::decorate(is_array($pd['days'] ?? null) ? $pd['days'] : array_fill_keys(self::DAYS, []));
        /* Families get the unit's name and its "try at home" ideas, never the staff-only observation
           prompts; and no internal unit ids on the activities. */
        if (! empty($pd['unit']) && is_array($pd['unit'])) {
            $pd['unit'] = array_intersect_key($pd['unit'], array_flip(['title', 'week', 'of', 'focus', 'family', 'french']));
        }
        foreach ($pd['days'] as $dk => $acts) {
            foreach ((array) $acts as $ai => $act) {
                if (is_array($act)) unset($pd['days'][$dk][$ai]['unit_activity']);
            }
        }

        return response()->json([
            'room_id' => $plan->room_id,
            'week_starting' => $plan->week_starting,
            'theme' => $plan->theme,
            'plan' => $pd,
            'cover' => LessonImages::cover($plan),
        ]);
    }

    // ── Pictures (2026-11-02, App\Support\LessonImages) ─────────────────────────────────────────
    //   POST /provider/lesson-plans/image/search   {room_id|centre_id, title, notes?, theme?, q?} -> candidates (no AI)
    //   POST /provider/lesson-plans/image/pick     {room_id|centre_id, key} -> the chosen file, stored
    //   POST /provider/lesson-plans/image/upload   multipart {room_id|centre_id, photo} -> /storage/photos/lesson/<agency>/
    //   PUT  /provider/lesson-plans/image-settings {room_id|centre_id, auto} - directors / admins
    //   POST /provider/lesson-plans/fill-images    {room_id|centre_id, week_starting, force?} -> fills the SAVED plan
    //   POST /ai/assist/lesson-image               {room_id|centre_id, title, notes?, theme?} -> the AI finds one (AI Bundle)

    public function imageSearch(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => 'nullable|string|max:200', 'notes' => 'nullable|string|max:500',
            'theme' => 'nullable|string|max:160', 'q' => 'nullable|string|max:80']);
        $this->requestAgency($request);
        $q = trim((string) ($d['q'] ?? ''));
        $terms = $q !== '' ? [LessonImages::norm($q)] : LessonImages::keywords((string) ($d['title'] ?? ''), $d['notes'] ?? null, $d['theme'] ?? null);
        $out = []; $seen = [];
        foreach ($terms as $t) {
            $lead = LessonImages::wikiLead($t);   // the encyclopedia's own picture of the thing, first
            foreach (array_merge($lead ? [$lead] : [], LessonImages::candidates($t, 12)) as $c) {
                if (isset($seen[$c['key']])) continue;
                $seen[$c['key']] = 1;
                $out[] = ['key' => $c['key'], 'title' => $c['title'], 'thumb' => $c['thumb'], 'credit' => trim(($c['creator'] ? $c['creator'] . ' · ' : '') . $c['licence']),
                    'page' => $c['page']];
            }
            if (count($out) >= 12) break;
        }

        return response()->json(['terms' => $terms, 'candidates' => array_slice($out, 0, 12)]);
    }

    public function imagePick(Request $request): JsonResponse
    {
        $d = $request->validate(['key' => 'required|string|max:260']);
        $this->requestAgency($request);
        $img = LessonImages::storeKey($d['key']);
        if (! $img) {
            return response()->json(['message' => 'That picture cannot be used (its licence or subject does not fit). Please choose another.'], 422);
        }

        return response()->json(['image' => $this->imageOut($img, 'library')]);
    }

    public function imageAi(Request $request): JsonResponse
    {
        $d = $request->validate(['title' => 'required|string|max:200', 'notes' => 'nullable|string|max:500', 'theme' => 'nullable|string|max:160']);
        $agencyId = $this->requestAgency($request);
        abort_unless(AiBundle::on($agencyId), 403, 'Finding pictures with AI is part of the AI Bundle.');
        $terms = LessonImages::aiTerms([['title' => $d['title'], 'notes' => $d['notes'] ?? '']], $d['theme'] ?? null, $agencyId);
        if ($terms === null) {
            return response()->json(['message' => 'The AI did not answer just now. Please try again in a moment.'], 503);
        }
        $list = $terms[0] ?? [];
        // A picture this agency's plans have not used lately (LessonImages::$avoid).
        LessonImages::avoidFor($agencyId);
        try {
            $img = $list ? LessonImages::forTerms($list, $agencyId, $d['title'] . (! empty($d['notes']) ? ' — ' . $d['notes'] : '')) : null;
        } finally {
            LessonImages::$avoid = null;
        }

        return response()->json(['terms' => $list, 'image' => $img ? $this->imageOut($img, 'auto') : null]);
    }

    public function imageUpload(Request $request): JsonResponse
    {
        $agencyId = $this->requestAgency($request);
        $request->validate(['photo' => 'required|file|max:12288|mimes:jpg,jpeg,png,webp,heic,heif']);
        $raw = @file_get_contents($request->file('photo')->getRealPath());
        $files = $raw ? LessonImages::writeWebp($raw, 'photos/lesson/' . $agencyId, \Illuminate\Support\Str::uuid()->toString()) : null;
        if (! $files) {
            return response()->json(['message' => 'That photo could not be read. Try a JPEG or PNG.'], 422);
        }

        // Signed on the way out by SignProtectedMedia; the stored form goes back on save (FoodPhotos::stored).
        return response()->json(['image' => ['image_url' => $files[0], 'thumb_url' => $files[1], 'image_source' => 'upload',
            'lesson_image_id' => null, 'image_credit' => null, 'image_credit_url' => null, 'image_credit_required' => false]]);
    }

    public function imageSettings(Request $request): JsonResponse
    {
        $agencyId = $this->requestAgency($request);
        abort_unless($this->canManageAgency($request->user()->id, $agencyId), 403, 'Only a director or admin can change this.');
        $d = $request->validate(['auto' => 'required|boolean']);
        LessonImages::setAuto($agencyId, (bool) $d['auto']);
        \App\Support\Audit::write([
            'user_id' => $request->user()->id, 'action' => 'lesson_plan.picture_settings', 'entity_type' => 'agency', 'entity_id' => $agencyId,
            'payload' => json_encode(['auto' => (bool) $d['auto']]), 'created_at' => now(),
        ]);

        return response()->json(['auto' => (bool) $d['auto']]);
    }

    public function fillImages(Request $request): JsonResponse
    {
        $d = $request->validate(['week_starting' => 'required|date', 'force' => 'nullable|boolean']);
        $agencyId = $this->requestAgency($request);
        if (empty($d['force']) && ! LessonImages::autoOn($agencyId)) {
            return response()->json(['missing' => 0, 'filled' => 0, 'left' => 0, 'done' => true, 'auto' => false]);
        }
        $roomId = $request->filled('room_id') ? (int) $request->input('room_id') : null;
        $q = DB::table('lesson_plans')->where('week_starting', $d['week_starting']);
        $roomId ? $q->where('room_id', $roomId) : $q->where('centre_id', (int) $request->input('centre_id'))->whereNull('room_id');
        $planId = $q->value('id');
        if (! $planId) {
            return response()->json(['missing' => 0, 'filled' => 0, 'left' => 0, 'done' => true]);
        }
        @set_time_limit(60);

        return response()->json(LessonImages::fillPlan((int) $planId, true, 20));
    }

    /** A stored lesson_images row as the planner wants it. */
    private function imageOut(array $img, string $source): array
    {
        return ['image_url' => $img['url'], 'thumb_url' => $img['thumb_url'], 'image_source' => $source,
            'lesson_image_id' => (int) $img['id'], 'image_credit' => LessonImages::credit($img), 'image_credit_url' => $img['source_url'] ?? null,
            'image_credit_required' => (bool) ($img['attribution_required'] ?? false),
            'title' => $img['title'] ?? null];
    }

    /** The agency of the room / centre named in the request, after the same access check the planner uses. */
    private function requestAgency(Request $request): int
    {
        $uid = $request->user()->id;
        $roomId = $request->filled('room_id') ? (int) $request->input('room_id') : null;
        $centreId = ! $roomId && $request->filled('centre_id') ? (int) $request->input('centre_id') : null;
        if ($roomId) {
            abort_unless($this->hasRoomAccess($uid, $roomId), 403, 'Forbidden');
        } elseif ($centreId) {
            abort_unless($this->hasCentreAccess($uid, $centreId), 403, 'Forbidden');
        } else {
            abort(422, 'Choose a room or centre first.');
        }
        $agencyId = $this->scopeAgency($roomId, $centreId);
        abort_unless($agencyId, 404);

        return $agencyId;
    }

    private function scopeAgency(?int $roomId, ?int $centreId): ?int
    {
        if ($roomId) {
            $centreId = (int) DB::table('rooms')->where('id', $roomId)->value('centre_id');
        }
        $a = $centreId ? DB::table('centres')->where('id', $centreId)->value('agency_id') : null;

        return $a ? (int) $a : null;
    }

    private function canManageAgency(int $userId, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where(fn ($x) => $x->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director']))
                  ->orWhere('role', 'platform_admin');
            })->exists();
    }

    private function hasCentreAccess(int $userId, int $centreId): bool
    {
        $centre = DB::table('centres')->where('id', $centreId)->first();
        if (! $centre) return false;
        /* Educators and directors only at their own centre (2026-11-05 leak sweep): the agency_id branch let ANY
           educator or director of the agency read and overwrite every centre's plan. Agency-wide is for admins. */
        $has = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('active', true)
            ->where(function ($q) use ($centreId, $centre) {
                $q->where(fn ($c) => $c->where('centre_id', $centreId)->whereIn('role', ['educator', 'centre_director']))
                  ->orWhere(fn ($a) => $a->where('agency_id', $centre->agency_id)->where('role', 'agency_admin'));
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
        /* Educators and directors only for rooms at their own centre (2026-11-05 leak sweep): the agency_id branch
           let any educator of the agency overwrite any room's plan. Agency-wide is for admins. */
        $has = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('active', true)
            ->where(function ($q) use ($room, $centre) {
                $q->where(fn ($c) => $c->where('centre_id', $room->centre_id)->whereIn('role', ['educator', 'centre_director']))
                  ->orWhere(fn ($a) => $a->where('agency_id', $centre->agency_id)->where('role', 'agency_admin'));
            })
            ->exists();
        if ($has) return true;
        // 2026-09-29 (Anthony approved): the room check never got v22p98's platform_admin
        // clause, so a platform admin switched into an agency could read its centre-wide
        // plans but got 403 on every room plan. Same rule as hasCentreAccess.
        $isPlatform = DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', true)->exists();
        return $isPlatform && (int) $centre->agency_id === (int) request()->header('X-Active-Agency-Id');
    }
}
