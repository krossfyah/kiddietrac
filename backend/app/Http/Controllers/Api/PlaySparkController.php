<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Support\AgencyRole;
use App\Support\AiBundle;
use App\Support\LearningFrameworks;
use App\Support\PlaySpark;
use App\Support\PlaySparkPicture;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * PLAY SPARK (2026-11-24) - see App\Support\PlaySpark. AI Bundle only (ai_tools): EnforcePlanFeatures gates the
 * provider/play-spark and parent/play-spark paths; gate() repeats it here so the message is in plain words.
 * Every query is scoped to the ACTIVE agency and to rooms the person may see (Visibility::roomIds).
 */
class PlaySparkController extends Controller
{
    use ResolvesCentreContext;

    private const OFF = 'Play Spark is part of the AI Bundle add-on. Ask your administrator to add it in Settings, then Licence.';

    private function agency(Request $request): int
    {
        $a = (int) $this->resolveAgencyId($request);
        abort_if($a <= 0, 422, 'Choose an agency first.');

        return $a;
    }

    private function gate(int $agencyId): ?JsonResponse
    {
        return AiBundle::on($agencyId) ? null : response()->json(['message' => self::OFF, 'code' => 'ai_bundle'], 403);
    }

    private function room(Request $request, int $agencyId, int $roomId): object
    {
        $rooms = Visibility::roomIds($agencyId, $request->user()) ?: [0];
        abort_unless(in_array($roomId, array_map('intval', $rooms), true), 403, 'That room is not one of yours.');

        return DB::table('rooms')->where('id', $roomId)->first(['id', 'name', 'centre_id']);
    }

    /** GET provider/play-spark/context?room_id= */
    public function context(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $roomIds = Visibility::roomIds($a, $request->user()) ?: [0];
        $rooms = DB::table('rooms as r')->join('centres as c', 'c.id', '=', 'r.centre_id')->whereIn('r.id', $roomIds)
            ->where('c.agency_id', $a)->orderBy('c.name')->orderBy('r.name')->get(['r.id', 'r.name', 'c.name as centre']);
        $roomId = (int) $request->query('room_id', 0);
        if (! $roomId || ! $rooms->contains('id', $roomId)) {
            // the room with the most children signed in now, else the first
            $best = 0;
            foreach ($rooms as $rm) {
                $n = count(PlaySpark::presentChildIds((int) $rm->id));
                if ($n > $best) {
                    $best = $n;
                    $roomId = (int) $rm->id;
                }
            }
            $roomId = $roomId ?: (int) ($rooms->first()->id ?? 0);
        }

        // The children for the "We did it" picker: everyone placed in this room, signed-in first. Staff screen only.
        $children = [];
        if ($roomId) {
            $present = PlaySpark::presentChildIds($roomId);
            $ids = array_values(array_unique(array_merge($present, DB::table('enrollments')->where('room_id', $roomId)
                ->whereNull('end_date')->pluck('child_id')->map(fn ($v) => (int) $v)->all())));
            $today = (string) \App\Support\AgencyTime::today($a);
            foreach (DB::table('children')->whereIn('id', $ids ?: [0])->whereNull('deleted_at')
                ->whereIn('enrollment_status', ['enrolled', 'active'])->get(['id', 'first_name', 'preferred_name', 'last_name', 'date_of_birth']) as $k) {
                $children[] = ['id' => (int) $k->id, 'name' => trim(($k->preferred_name ?: $k->first_name) . ' ' . mb_substr((string) $k->last_name, 0, 1)),
                    'band' => PlaySpark::bandOf($k->date_of_birth, $today), 'here' => in_array((int) $k->id, $present, true)];
            }
            usort($children, fn ($x, $y) => [$y['here'], $x['name']] <=> [$x['here'], $y['name']]);
        }

        return response()->json([
            'rooms' => $rooms,
            'context' => $roomId ? PlaySpark::roomContext($a, $roomId) : null,
            'children' => $children,
            'bands' => PlaySpark::BANDS,
        ]);
    }

    /** POST provider/play-spark/generate */
    public function generate(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $d = $request->validate([
            'room_id' => 'required|integer',
            'bands' => 'nullable|array', 'bands.*' => 'integer|min:0|max:60',
            'setting' => 'nullable|in:indoor,outdoor,either',
            'minutes' => 'nullable|integer|min:5|max:90',
            'involvement' => 'nullable|in:low,guided,hands_on',
            'materials' => 'nullable|array|max:40', 'materials.*' => 'string|max:80',
            'theme' => 'nullable|string|max:120',
            'use_gaps' => 'nullable|boolean',
        ]);
        $room = $this->room($request, $a, (int) $d['room_id']);
        $ctx = PlaySpark::roomContext($a, (int) $room->id);
        $bands = array_intersect_key($d['bands'] ?? [], PlaySpark::BANDS) ?: $ctx['bands'];

        $avoid = DB::table('play_spark_ideas')->where('agency_id', $a)->where('room_id', $room->id)
            ->where('created_at', '>=', now()->subDays(30))->orderByDesc('id')->limit(12)->pluck('title')->all();
        $in = [
            'bands' => $bands, 'setting' => $d['setting'] ?? 'either', 'minutes' => $d['minutes'] ?? 20,
            'involvement' => $d['involvement'] ?? 'guided', 'materials' => $d['materials'] ?? [],
            'theme' => trim((string) ($d['theme'] ?? '')) ?: $ctx['theme'],
            'gaps' => ($d['use_gaps'] ?? true) ? array_map(fn ($g) => $g['label'], $ctx['gaps']) : [],
            'framework' => $ctx['framework']['name'], 'avoid' => $avoid,
        ];
        try {
            $idea = PlaySpark::generate($a, $in);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Play Spark could not put that idea together. Please try again.'], 502);
        }
        $fw = LearningFrameworks::forAgency($a);
        $idea['areas'] = array_values(array_unique(array_filter(array_map(fn ($dm) => PlaySpark::areaLabel($fw, $dm), $idea['domains']))));
        $idea['domain_labels'] = array_map(fn ($dm) => PlaySpark::domainLabel($dm), $idea['domains']);
        $idea['framework'] = $fw['short'] ?? $fw['key'];
        unset($in['avoid']);
        $id = DB::table('play_spark_ideas')->insertGetId([
            'agency_id' => $a, 'centre_id' => $room->centre_id, 'room_id' => $room->id, 'user_id' => $request->user()->id,
            'title' => mb_substr($idea['title'], 0, 160), 'idea' => json_encode($idea), 'inputs' => json_encode($in),
            'safety_level' => $idea['safety_level'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        // The end-result picture is drawn in the queue: the idea is on screen now, the picture follows (PlaySparkPicture).
        PlaySparkPicture::queue($a, 'idea', (string) $id, $idea);

        return response()->json(['idea' => $this->row(DB::table('play_spark_ideas')->find($id), $request)]);
    }

    /* ── the end-result picture (2026-11-24, App\Support\PlaySparkPicture) ─────────────────────────────── */

    /** GET provider/play-spark/ideas/{id}/picture -> {status: ready|drawing|failed|off|none, svg?} */
    public function picture(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        $idea = $this->find($request, $a, $id, true);
        $body = json_decode((string) $idea->idea, true) ?: [];

        return response()->json(PlaySparkPicture::status($a, 'idea', (string) $idea->id, $body + ['title' => $idea->title]));
    }

    /**
     * POST provider/play-spark/lesson-picture {title, notes?, theme?, play_spark_id?} - the picture for a weekly
     * lesson-plan activity. Keyed by its words (PlaySparkPicture::lessonKey), so the same activity in any room or week
     * shares one picture; an activity added from a Play Spark idea uses that idea's picture.
     */
    public function lessonPicture(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        $d = $request->validate(['title' => 'required|string|max:200', 'notes' => 'nullable|string|max:1500',
            'theme' => 'nullable|string|max:160', 'play_spark_id' => 'nullable|integer']);
        if (! empty($d['play_spark_id'])) {
            $idea = DB::table('play_spark_ideas')->where('id', (int) $d['play_spark_id'])->where('agency_id', $a)->whereNull('deleted_at')->first();
            if ($idea) {
                return response()->json(PlaySparkPicture::status($a, 'idea', (string) $idea->id, (json_decode((string) $idea->idea, true) ?: []) + ['title' => $idea->title]));
            }
        }
        $key = PlaySparkPicture::lessonKey($d['title'], (string) ($d['notes'] ?? ''));
        // Drawing costs: a new one only within a fair hourly allowance per person (a saved plan queues its own on save).
        $limiter = 'ps-pic:' . $request->user()->id;
        $may = ! \Illuminate\Support\Facades\RateLimiter::tooManyAttempts($limiter, 80);
        $before = PlaySparkPicture::find($a, 'lesson', $key);
        $out = PlaySparkPicture::status($a, 'lesson', $key, ['title' => $d['title'], 'notes' => $d['notes'] ?? '', 'theme' => $d['theme'] ?? ''], true, $may);
        if ($may && ! $before && ($out['status'] ?? '') === 'drawing') {
            \Illuminate\Support\Facades\RateLimiter::hit($limiter, 3600);
        }

        return response()->json($out);
    }

    /** GET parent/play-spark/picture/{id} - a family sees the picture of an idea their own child did (never queues one). */
    public function parentPicture(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        $kids = $this->myChildren($request, $a);
        $did = $kids && DB::table('daily_events')->whereIn('child_id', $kids)->where('event_type', 'activity')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(payload, '$.play_spark_id')) = ?", [(string) $id])->exists();
        abort_unless($did, 404, 'That picture is not available.');
        abort_unless(DB::table('play_spark_ideas')->where('id', $id)->where('agency_id', $a)->exists(), 404, 'That picture is not available.');

        return response()->json(PlaySparkPicture::status($a, 'idea', (string) $id, [], true, false));
    }

    /** POST parent/play-spark/lesson-picture {title, notes?, play_spark_id?} - the picture of an activity on my child's plan (never queues one). */
    public function parentLessonPicture(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        $d = $request->validate(['title' => 'required|string|max:200', 'notes' => 'nullable|string|max:1500', 'play_spark_id' => 'nullable|integer']);
        abort_unless($this->myChildren($request, $a), 404, 'That picture is not available.');
        if (! empty($d['play_spark_id']) && DB::table('play_spark_ideas')->where('id', (int) $d['play_spark_id'])->where('agency_id', $a)->exists()) {
            return response()->json(PlaySparkPicture::status($a, 'idea', (string) (int) $d['play_spark_id'], [], true, false));
        }

        return response()->json(PlaySparkPicture::status($a, 'lesson', PlaySparkPicture::lessonKey($d['title'], (string) ($d['notes'] ?? '')), [], true, false));
    }

    /** GET play-spark/picture/{id} (signed link, no sign-in) - the PNG copy for email. Holds no personal data. */
    public function pngFile(int $id)
    {
        $row = DB::table('play_spark_pictures')->where('id', $id)->where('status', 'ready')->first(['png_path']);
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        abort_unless($row && $row->png_path && $disk->exists($row->png_path), 404);

        return response($disk->get($row->png_path), 200, [
            'Content-Type' => 'image/png', 'Cache-Control' => 'public, max-age=2592000, immutable', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function myChildren(Request $request, int $a): array
    {
        return DB::table('children as c')->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('g.user_id', $request->user()->id)->where('ce.agency_id', $a)->whereNull('c.deleted_at')
            ->distinct()->pluck('c.id')->map(fn ($v) => (int) $v)->all();
    }

    /** POST provider/play-spark/scan  {image: data URL}  - the materials in a photo; never stored. */
    public function scan(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $d = $request->validate(['image' => 'required|string|max:7000000']);
        if (! preg_match('#^data:(image/(?:jpeg|png|webp));base64,([A-Za-z0-9+/=]+)$#', $d['image'], $m)) {
            return response()->json(['message' => 'Use a JPG, PNG or WebP photo.'], 422);
        }
        try {
            $res = PlaySpark::scanMaterials($a, $m[1], $m[2]);
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage() ?: 'Could not read that photo. Please try again.'], 502);
        }
        if ($res['people']) {
            return response()->json(['message' => 'That photo has a person in it. Take one of just the materials, with no children or adults in view.', 'code' => 'people'], 422);
        }

        return response()->json(['materials' => $res['materials']]);
    }

    /** POST provider/play-spark/{id}/done - "We did it": the daily log, the family feed, draft observations. */
    public function done(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $d = $request->validate([
            'room_id' => 'required|integer',
            'child_ids' => 'required|array|min:1|max:60', 'child_ids.*' => 'integer',
            'note' => 'nullable|string|max:1000',
            'safety_ack' => 'accepted',
            'observations' => 'nullable|boolean',
        ], ['safety_ack.accepted' => 'Tick the safety check first.']);
        $idea = $this->find($request, $a, $id, false);
        $room = $this->room($request, $a, (int) $d['room_id']);
        $user = $request->user();
        $childIds = array_values(array_unique(array_map('intval', $d['child_ids'])));
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($user, $cid) && $this->ktStaffForChild($user, $cid), 403, 'One of those children is not in your care.');
            if (! $this->ktRoomFitsChild($user, (int) $room->id, $cid)) {
                return response()->json(['message' => 'One of those children is not in this room.'], 422);
            }
        }
        if (! $request->boolean('confirm_absent')) {
            $absent = \App\Support\SignedInNow::absentNames($childIds);
            if ($absent) {
                return \App\Support\SignedInNow::refusal($absent);
            }
        }

        $body = json_decode((string) $idea->idea, true) ?: [];
        $note = trim((string) ($d['note'] ?? ''));
        $fw = LearningFrameworks::forAgency($a);
        $domain = $body['domains'][0] ?? 'cognitive';
        $now = now();
        $events = [];
        $obs = [];
        $items = [];
        DB::transaction(function () use ($childIds, $room, $idea, $body, $note, $fw, $domain, $now, $user, $d, &$events, &$obs) {
            foreach ($childIds as $cid) {
                $events[] = DB::table('daily_events')->insertGetId([
                    'child_id' => $cid, 'room_id' => $room->id, 'event_type' => 'activity', 'occurred_at' => $now,
                    'payload' => json_encode(['play_spark_id' => (int) $idea->id, 'title' => $idea->title,
                        'home_idea' => $body['home_idea'] ?? null, 'domains' => $body['domains'] ?? []]),
                    'notes' => mb_substr('✨ ' . $idea->title . ($note !== '' ? ' - ' . $note : ''), 0, 1000),
                    'recorded_by_id' => $user->id, 'voice_logged' => false, 'synced_at' => $now, 'created_at' => $now, 'updated_at' => $now,
                ]);
                if ($d['observations'] ?? true) {
                    // A DRAFT for the educator to finish: not shared with the family until they review it.
                    $text = 'Took part in "' . $idea->title . '".' . ($note !== '' ? ' ' . $note : '')
                        . ($body['watch_for'] ?? [] ? "\nLook for: " . implode(' ', $body['watch_for']) : '');
                    $obs[] = DB::table('observations')->insertGetId([
                        'child_id' => $cid, 'framework' => $fw['key'], 'domain' => $domain, 'title' => mb_substr('Play Spark: ' . $idea->title, 0, 190),
                        'body' => $text, 'raw_text' => $note !== '' ? $note : null, 'observed_at' => $now, 'recorded_by_id' => $user->id,
                        'media_ids' => json_encode([]), 'hdlh_milestones' => json_encode([]), 'shared_with_family' => 0,
                        'ai_generated' => 0, 'created_at' => $now,
                    ]);
                }
            }
            DB::table('play_spark_runs')->insert([
                'idea_id' => $idea->id, 'agency_id' => $idea->agency_id, 'centre_id' => $room->centre_id, 'room_id' => $room->id,
                'user_id' => $user->id, 'child_ids' => json_encode($childIds), 'event_ids' => json_encode($events),
                'observation_ids' => json_encode($obs), 'note' => $note !== '' ? $note : null, 'done_at' => $now, 'created_at' => $now,
            ]);
            DB::table('play_spark_ideas')->where('id', $idea->id)->update(['times_done' => DB::raw('times_done + 1'), 'last_done_at' => $now, 'updated_at' => $now]);
        });

        // Families see it in their feed (ChildMomentNotify obeys every family setting and the test-agency block).
        try {
            foreach ($childIds as $cid) {
                $it = \App\Support\ChildMomentNotify::momentItem($cid, 'activity', '✨ ' . $idea->title, ['play_spark_id' => (int) $idea->id]);
                if ($it) {
                    $items[] = $it;
                }
            }
            if ($items) {
                \App\Support\ChildMomentNotify::after($items);
            }
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'logged' => count($events), 'observations' => count($obs)]);
    }

    /** GET provider/play-spark/ideas?scope=mine|shared|favourites|recent */
    public function ideas(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $scope = (string) $request->query('scope', 'mine');
        $q = DB::table('play_spark_ideas')->where('agency_id', $a)->whereNull('deleted_at');
        $uid = (int) $request->user()->id;
        if ($scope === 'team') {
            // TEAM IDEAS (2026-11-26, Anthony): every idea made at the centres this person runs - agency admins (and a
            // super admin in the agency) the whole agency, directors their own centres. Read-only. Fails closed.
            $t = $this->team($request, $a);
            abort_unless($t, 403, 'Team ideas are for directors and agency admins.');
            if (! $t['wide']) {
                $q->whereIn('centre_id', $t['centres'] ?: [0]);
            }
        } elseif ($scope === 'shared') {
            $q->where('shared', 1);
        } elseif ($scope === 'favourites') {
            $q->where('user_id', $uid)->where('favourite', 1);
        } else {
            $q->where('user_id', $uid);
        }
        $rows = $q->orderByDesc('id')->limit(100)->get();
        // Each idea's picture status in one read; ideas made before pictures get theirs drawn, a few per visit.
        $pics = DB::table('play_spark_pictures')->where('agency_id', $a)->where('kind', 'idea')
            ->whereIn('ref', $rows->pluck('id')->map(fn ($v) => (string) $v)->all() ?: ['0'])->pluck('status', 'ref')->all();
        $fill = 0;
        foreach ($rows as $r) {
            if (! isset($pics[(string) $r->id]) && $fill < 4) {
                PlaySparkPicture::queue($a, 'idea', (string) $r->id, (json_decode((string) $r->idea, true) ?: []) + ['title' => $r->title]);
                $pics[(string) $r->id] = 'drawing';
                $fill++;
            }
        }
        $this->picStatus = $pics;
        $this->roomNames = DB::table('rooms')->whereIn('id', $rows->pluck('room_id')->filter()->unique()->values()->all() ?: [0])
            ->pluck('name', 'id')->all();

        return response()->json(['ideas' => $rows->map(fn ($r) => $this->row($r, $request))->values()]);
    }

    /** GET provider/play-spark/ideas/{id} (2026-11-25): one idea, for a link from the top search. The same rule as the
        Library: your own idea, or one shared to the agency library from a centre you see (Visibility::centreIds; an
        agency-wide idea only for agency admins). Fails closed. */
    public function show(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $idea = DB::table('play_spark_ideas')->where('id', $id)->where('agency_id', $a)->whereNull('deleted_at')->first();
        abort_unless($idea, 404, 'That idea is no longer there.');
        if ((int) $idea->user_id !== (int) $request->user()->id) {
            $centres = array_map('intval', Visibility::centreIds($a, $request->user()) ?: []);
            $ok = (int) $idea->shared === 1 && ($idea->centre_id
                ? in_array((int) $idea->centre_id, $centres, true)
                : DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
                    ->where(fn ($q) => $q->where(fn ($q2) => $q2->where('agency_id', $a)->where('role', 'agency_admin'))->orWhere('role', 'platform_admin'))->exists());
            abort_unless($ok || $this->teamSees($this->team($request, $a), $idea), 404, 'That idea is no longer there.');
        }

        return response()->json(['idea' => $this->row($idea, $request)]);
    }

    /** PATCH provider/play-spark/ideas/{id}  {favourite?, rating?, shared?} */
    public function update(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $d = $request->validate(['favourite' => 'nullable|boolean', 'rating' => 'nullable|integer|min:1|max:5', 'shared' => 'nullable|boolean']);
        $idea = $this->find($request, $a, $id, true);
        $mine = (int) $idea->user_id === (int) $request->user()->id;
        $set = [];
        if ($mine) {
            foreach (['favourite', 'rating', 'shared'] as $k) {
                if ($request->has($k)) {
                    $set[$k] = $d[$k];
                }
            }
        } elseif ($request->has('shared') && ! $d['shared'] && $this->teamSees($this->team($request, $a), $idea)) {
            $set['shared'] = 0;   // a director or admin may take an idea out of the library
        }
        abort_if(! $set, 403, 'Only the person who made this idea can change it.');
        $set['updated_at'] = now();
        DB::table('play_spark_ideas')->where('id', $idea->id)->update($set);

        return response()->json(['idea' => $this->row(DB::table('play_spark_ideas')->find($idea->id), $request)]);
    }

    /** POST provider/play-spark/ideas/{id}/copy (2026-11-26, Team ideas): save a copy of an idea you can see to your own
        ideas - the one change a director or admin may make to someone else's idea. The copy is yours (favourite, share,
        We did it); the original is untouched. Its end-result picture is reused, not drawn again. */
    public function copy(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $idea = $this->find($request, $a, $id, true);
        $uid = (int) $request->user()->id;
        abort_if((int) $idea->user_id === $uid, 422, 'This idea is already one of yours.');
        $now = now();
        $newId = DB::table('play_spark_ideas')->insertGetId([
            'agency_id' => $a, 'centre_id' => $idea->centre_id, 'room_id' => $idea->room_id, 'user_id' => $uid,
            'title' => $idea->title, 'idea' => $idea->idea, 'inputs' => $idea->inputs, 'safety_level' => $idea->safety_level,
            'favourite' => 0, 'rating' => null, 'shared' => 0, 'times_done' => 0, 'last_done_at' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $pic = DB::table('play_spark_pictures')->where('agency_id', $a)->where('kind', 'idea')->where('ref', (string) $idea->id)->first();
        if ($pic && $pic->status === 'ready') {
            DB::table('play_spark_pictures')->insertOrIgnore([
                'agency_id' => $a, 'kind' => 'idea', 'ref' => (string) $newId, 'status' => 'ready', 'source' => $pic->source,
                'svg' => $pic->svg, 'png_path' => $pic->png_path, 'tries' => 0, 'error' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            PlaySparkPicture::queue($a, 'idea', (string) $newId, (json_decode((string) $idea->idea, true) ?: []) + ['title' => $idea->title]);
        }

        return response()->json(['idea' => $this->row(DB::table('play_spark_ideas')->find($newId), $request)]);
    }

    /** DELETE provider/play-spark/ideas/{id} */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        $idea = $this->find($request, $a, $id, true);
        abort_unless((int) $idea->user_id === (int) $request->user()->id, 403, 'Only the person who made this idea can remove it.');
        DB::table('play_spark_ideas')->where('id', $idea->id)->update(['deleted_at' => now(), 'shared' => 0]);

        return response()->json(['ok' => true]);
    }

    /** POST provider/play-spark/materials {room_id, names[], centre_wide?} */
    public function addMaterials(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $d = $request->validate(['room_id' => 'required|integer', 'names' => 'required|array|min:1|max:40', 'names.*' => 'string|max:80', 'centre_wide' => 'nullable|boolean']);
        $room = $this->room($request, $a, (int) $d['room_id']);
        $have = DB::table('play_spark_materials')->where('agency_id', $a)->where('centre_id', $room->centre_id)
            ->where(fn ($q) => $q->whereNull('room_id')->orWhere('room_id', $room->id))->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();
        $add = 0;
        foreach ($d['names'] as $n) {
            $n = mb_strtolower(trim(preg_replace('/\s+/', ' ', $n)));
            if ($n === '' || in_array($n, $have, true)) {
                continue;
            }
            $have[] = $n;
            DB::table('play_spark_materials')->insert(['agency_id' => $a, 'centre_id' => $room->centre_id,
                'room_id' => ! empty($d['centre_wide']) ? null : $room->id, 'name' => mb_substr($n, 0, 80),
                'created_by' => $request->user()->id, 'created_at' => now()]);
            $add++;
        }

        return response()->json(['added' => $add, 'context' => PlaySpark::roomContext($a, (int) $room->id)]);
    }

    /** DELETE provider/play-spark/materials/{id} */
    public function removeMaterial(Request $request, int $id): JsonResponse
    {
        $a = $this->agency($request);
        $m = DB::table('play_spark_materials')->where('id', $id)->where('agency_id', $a)->first();
        abort_unless($m, 404);
        $rooms = array_map('intval', Visibility::roomIds($a, $request->user()) ?: [0]);
        $ok = $m->room_id ? in_array((int) $m->room_id, $rooms, true)
            : DB::table('rooms')->whereIn('id', $rooms)->where('centre_id', $m->centre_id)->exists();
        abort_unless($ok, 403);
        DB::table('play_spark_materials')->where('id', $id)->delete();

        return response()->json(['ok' => true]);
    }

    /** GET provider/play-spark/report?room_id=&month=YYYY-MM - the monthly play report. */
    public function report(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        $room = $this->room($request, $a, (int) $request->query('room_id'));
        [$from, $to, $label] = $this->month($a, (string) $request->query('month', ''));
        $runs = DB::table('play_spark_runs as r')->join('play_spark_ideas as i', 'i.id', '=', 'r.idea_id')
            ->where('r.agency_id', $a)->where('r.room_id', $room->id)->whereBetween('r.done_at', [$from, $to])
            ->orderBy('r.done_at')->get(['r.done_at', 'r.child_ids', 'r.note', 'i.title', 'i.idea']);
        $fw = LearningFrameworks::forAgency($a);
        $tz = \App\Support\AgencyTime::tzForCentre((int) $room->centre_id);
        $byArea = [];
        $acts = [];
        $kids = [];
        foreach ($runs as $r) {
            $b = json_decode((string) $r->idea, true) ?: [];
            $ids = json_decode((string) $r->child_ids, true) ?: [];
            foreach ($ids as $c) {
                $kids[(int) $c] = true;
            }
            $areas = [];
            foreach ($b['domains'] ?? [] as $dm) {
                $lab = PlaySpark::areaLabel($fw, $dm) ?: PlaySpark::domainLabel($dm);
                $byArea[$lab] = ($byArea[$lab] ?? 0) + 1;
                $areas[] = $lab;
            }
            $acts[] = ['date' => Carbon::parse($r->done_at)->tz($tz)->toDateString(), 'title' => $r->title, 'children' => count($ids),
                'areas' => array_values(array_unique($areas)), 'summary' => $b['summary'] ?? '', 'home_idea' => $b['home_idea'] ?? '', 'note' => $r->note];
        }
        arsort($byArea);

        return response()->json(['room' => $room->name, 'month' => $label, 'framework' => $fw['name'],
            'totals' => ['activities' => count($acts), 'children' => count($kids)], 'areas' => $byArea, 'activities' => $acts]);
    }

    /** GET parent/play-spark?month=YYYY-MM - what my children played this month, with try-at-home ideas. */
    public function parent(Request $request): JsonResponse
    {
        $a = $this->agency($request);
        if ($r = $this->gate($a)) {
            return $r;
        }
        [$from, $to, $label] = $this->month($a, (string) $request->query('month', ''));
        $kids = DB::table('children as c')->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->join('families as f', 'f.id', '=', 'c.family_id')->join('centres as ce', 'ce.id', '=', 'f.centre_id')
            ->where('g.user_id', $request->user()->id)->where('ce.agency_id', $a)->whereNull('c.deleted_at')
            ->distinct()->pluck('c.id')->map(fn ($v) => (int) $v)->all();
        $rows = $kids ? DB::table('daily_events')->whereIn('child_id', $kids)->where('event_type', 'activity')
            ->whereBetween('occurred_at', [$from, $to])->where('payload', 'like', '%play_spark_id%')
            ->orderByDesc('occurred_at')->get(['child_id', 'occurred_at', 'payload']) : collect();
        $tz = \App\Support\AgencyTime::tz($a);
        $out = [];
        foreach ($rows as $r) {
            $p = json_decode((string) $r->payload, true) ?: [];
            $out[] = ['child_id' => (int) $r->child_id, 'play_spark_id' => (int) ($p['play_spark_id'] ?? 0), 'date' => Carbon::parse($r->occurred_at)->tz($tz)->toDateString(),
                'title' => $p['title'] ?? 'Play', 'home_idea' => $p['home_idea'] ?? '',
                'areas' => array_map(fn ($d) => PlaySpark::domainLabel($d), $p['domains'] ?? [])];
        }

        return response()->json(['month' => $label, 'activities' => $out]);
    }

    /* ── helpers ──────────────────────────────────────────────────────────── */

    private function month(int $agencyId, string $m): array
    {
        $tz = \App\Support\AgencyTime::tz($agencyId);
        $start = preg_match('/^\d{4}-\d{2}$/', $m) ? Carbon::createFromFormat('Y-m-d', $m . '-01', $tz)->startOfDay()
            : Carbon::parse((string) \App\Support\AgencyTime::today($agencyId), $tz)->startOfMonth();

        return [$start->copy()->utc(), $start->copy()->endOfMonth()->utc(), $start->format('F Y')];
    }

    /* Your own idea, one shared to the agency library, or ($team) one made at a centre you run (Team ideas). The old
       rule let ANY director of the agency read every idea through this door - directors are now held to their own
       centres (2026-11-26). We did it passes $team = false: a team idea is read-only until you save a copy. */
    private function find(Request $request, int $a, int $id, bool $team): object
    {
        $idea = DB::table('play_spark_ideas')->where('id', $id)->where('agency_id', $a)->whereNull('deleted_at')->first();
        abort_unless($idea, 404, 'That idea is no longer there.');
        $uid = (int) $request->user()->id;
        abort_unless((int) $idea->user_id === $uid || (int) $idea->shared === 1
            || ($team && $this->teamSees($this->team($request, $a), $idea)), 403);

        return $idea;
    }

    /**
     * TEAM IDEAS scope (2026-11-26): null = not a manager here. Roles counted in the ACTIVE agency only (AgencyRole).
     * Agency admin / super admin: the whole agency. Centre director: the centres they direct (their director roles'
     * centres, within the centres they can see; a director role with no centre falls back to Visibility::centreIds).
     *
     * @return array{wide: bool, centres: int[]}|null
     */
    private function team(Request $request, int $a): ?array
    {
        $roles = AgencyRole::names($request);
        if (array_intersect($roles, ['platform_admin', 'agency_admin'])) {
            return ['wide' => true, 'centres' => []];
        }
        if (! in_array('centre_director', $roles, true)) {
            return null;
        }
        $vis = array_map('intval', Visibility::centreIds($a, $request->user()) ?: []);
        $dir = DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', 1)
            ->where('role', 'centre_director')->whereNotNull('centre_id')->pluck('centre_id')->map(fn ($v) => (int) $v)->all();
        $c = $dir ? array_values(array_intersect($vis, $dir)) : $vis;

        return $c ? ['wide' => false, 'centres' => $c] : null;
    }

    private function teamSees(?array $t, object $idea): bool
    {
        if (! $t) {
            return false;
        }

        return $t['wide'] || ($idea->centre_id && in_array((int) $idea->centre_id, $t['centres'], true));
    }

    /** ref => picture status, filled by ideas() for the whole list; other callers read the one row. */
    private ?array $picStatus = null;

    /** room id => name, filled by ideas() for the whole list. */
    private ?array $roomNames = null;

    private function row(object $r, Request $request): array
    {
        $who = DB::table('users')->where('id', $r->user_id)->value('first_name');
        $pic = $this->picStatus !== null ? ($this->picStatus[(string) $r->id] ?? 'none')
            : (DB::table('play_spark_pictures')->where('agency_id', $r->agency_id)->where('kind', 'idea')->where('ref', (string) $r->id)->value('status') ?? 'none');

        return ['id' => (int) $r->id, 'title' => $r->title, 'idea' => json_decode((string) $r->idea, true),
            'room_id' => $r->room_id ? (int) $r->room_id : null, 'safety_level' => $r->safety_level,
            'favourite' => (bool) $r->favourite, 'rating' => $r->rating ? (int) $r->rating : null, 'shared' => (bool) $r->shared,
            'times_done' => (int) $r->times_done, 'last_done_at' => $r->last_done_at, 'created_at' => $r->created_at, 'by' => $who,
            'room' => $r->room_id ? ($this->roomNames !== null ? ($this->roomNames[$r->room_id] ?? null) : DB::table('rooms')->where('id', $r->room_id)->value('name')) : null,
            'mine' => (int) $r->user_id === (int) $request->user()->id,
            // ready | drawing | failed | off | none - the screen fetches the SVG itself (GET ideas/{id}/picture)
            'picture' => $pic];
    }

    /* Copied from DailyEventController (2026-11-05 leak sweep): staff, not only a parent; and the room is the child's. */
    private function ktRoomFitsChild($user, int $roomId, int $childId): bool
    {
        $roomCentre = (int) DB::table('rooms')->where('id', $roomId)->value('centre_id');
        if ($roomCentre <= 0 || ! $this->authorizeCentreAccess($user, $roomCentre)) {
            return false;
        }
        $child = DB::table('children')->where('id', $childId)->first(['family_id', 'primary_room_id']);
        if (! $child) {
            return false;
        }
        if ((int) DB::table('families')->where('id', $child->family_id)->value('centre_id') === $roomCentre) {
            return true;
        }
        if ((int) ($child->primary_room_id ?? 0) === $roomId) {
            return true;
        }

        return DB::table('enrollments')->where('child_id', $childId)->whereNull('end_date')->where('room_id', $roomId)->exists();
    }

    private function ktStaffForChild($user, int $childId): bool
    {
        $child = DB::table('children')->where('id', $childId)->first(['family_id']);
        if (! $child) {
            return false;
        }
        if (! DB::table('guardians')->where('user_id', $user->id)->where('family_id', $child->family_id)->exists()) {
            return true;
        }
        $centres = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
            ->where('e.child_id', $childId)->whereNull('e.end_date')->pluck('r.centre_id')->all();
        $centres[] = DB::table('families')->where('id', $child->family_id)->value('centre_id');
        foreach (array_unique(array_filter(array_map('intval', $centres))) as $cid) {
            if ($this->authorizeCentreAccess($user, $cid)) {
                return true;
            }
        }

        return false;
    }
}
