<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\HelpService;
use App\Support\TrainingMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Training: narrated tutorial videos, one per help article (2026-10-01), and training
 * assigned to people (2026-10-02).
 *
 * Library access is Help's access: the list is built FROM the articles this person's
 * role may read (HelpService::listForRole, same role resolution incl. View-as), so a
 * video never reaches somebody its article would not. The one widening is an
 * ASSIGNMENT: a published video assigned to you is yours to watch, whatever library it
 * sits in — that is the point of assigning it.
 *
 * Drafts are for platform admins only, until reviewed and published.
 *
 * Assigning: agency admins assign to anyone in their agency, centre directors to people
 * at their own centres (staff and guardians), platform admins within the agency they
 * have switched into. Every assignee is checked against that scope — fail closed.
 */
final class TrainingController extends Controller
{
    use ResolvesCentreContext;

    private const STAFF_ROLES = ['educator', 'centre_director', 'home_visitor', 'agency_admin', 'auditor', 'sales_rep'];

    public function __construct(private readonly HelpService $help = new HelpService()) {}

    /** GET /api/v1/training */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = (new HelpController())->roleFor($request);
        $admin = self::isPlatformAdmin((int) $user->id);

        $articles = $this->help->listForRole($role);
        $videos = DB::table('training_videos')
            ->when(! $admin, fn ($q) => $q->where('status', 'published'))
            ->get()->keyBy(fn ($v) => $v->audience . '/' . $v->slug);
        $progress = DB::table('training_progress')->where('user_id', $user->id)->get()->keyBy('video_id');

        $courses = [];
        foreach ($articles as $a) {
            $v = $videos[$a['audience'] . '/' . $a['slug']] ?? null;
            if (! $v) {
                continue;
            }
            $courses[$a['category']][] = self::shape($v, $progress[$v->id] ?? null);
        }
        $out = [];
        foreach ($courses as $cat => $list) {
            $out[] = ['category' => $cat, 'videos' => $list];
        }

        // Assigned to me: published videos, whichever library they are in.
        $mine = [];
        $rows = DB::table('training_assignments as ta')->join('training_videos as v', 'v.id', '=', 'ta.video_id')
            ->where('ta.user_id', $user->id)->whereNull('ta.cancelled_at')->where('v.status', 'published')
            ->orderByRaw('ta.due_on IS NULL, ta.due_on')->get(['v.*', 'ta.id as assignment_id', 'ta.due_on', 'ta.note', 'ta.created_at as assigned_at']);
        foreach ($rows as $r) {
            $s = self::shape($r, $progress[$r->id] ?? null);
            $s['assignment_id'] = (int) $r->assignment_id;
            $s['due_on'] = $r->due_on;
            $s['note'] = $r->note;
            $s['overdue'] = $r->due_on && ! $s['completed'] && $r->due_on < now()->toDateString();
            $mine[] = $s;
        }

        $review = [];
        if ($admin) {
            foreach (DB::table('training_videos')->where('status', 'draft')->orderBy('audience')->orderBy('title')->get() as $v) {
                $review[] = self::shape($v, $progress[$v->id] ?? null);
            }
        }

        return response()->json([
            'role' => $role,
            'assigned' => $mine,
            'courses' => $out,
            'review' => $review,
            'can_publish' => $admin,
            'can_assign' => $this->assignerScope($request) !== null,
        ]);
    }

    /** POST /api/v1/training/{id}/progress  {position, duration} */
    public function progress(Request $request, int $id): JsonResponse
    {
        $d = $request->validate([
            'position' => ['required', 'numeric', 'min:0'],
            'duration' => ['nullable', 'numeric', 'min:0'],
        ]);
        $uid = (int) $request->user()->id;
        $v = DB::table('training_videos')->where('id', $id)->first();
        if (! $v || ($v->status !== 'published' && ! self::isPlatformAdmin($uid))) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        $dur = (float) ($d['duration'] ?? 0) ?: (float) $v->duration_sec;
        $pos = (int) floor((float) $d['position']);
        $done = $dur > 0 && $pos >= $dur * 0.9;

        $row = DB::table('training_progress')->where(['user_id' => $uid, 'video_id' => $id])->first();
        if ($row) {
            DB::table('training_progress')->where('id', $row->id)->update([
                'position_sec' => max($pos, (int) $row->position_sec),
                'completed_at' => $row->completed_at ?? ($done ? now() : null),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('training_progress')->insert([
                'user_id' => $uid, 'video_id' => $id, 'position_sec' => $pos,
                'completed_at' => $done ? now() : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return response()->json(['completed' => $done || ($row && $row->completed_at)]);
    }

    /** POST /api/v1/training/{id}/publish  {published: bool} — platform admins only */
    public function publish(Request $request, int $id): JsonResponse
    {
        if (! self::isPlatformAdmin((int) $request->user()->id)) {
            return response()->json(['message' => 'Only a platform admin can publish training videos.'], 403);
        }
        $on = (bool) $request->input('published', true);
        $n = DB::table('training_videos')->where('id', $id)->update([
            'status' => $on ? 'published' : 'draft',
            'published_at' => $on ? now() : null,
            'published_by' => $on ? $request->user()->id : null,
            'updated_at' => now(),
        ]);

        return $n ? response()->json(['status' => $on ? 'published' : 'draft']) : response()->json(['message' => 'Not found.'], 404);
    }

    /* ── assigning ─────────────────────────────────────────────────────────── */

    /** GET /api/v1/training/assignable — the people and the videos this caller may assign. */
    public function assignable(Request $request): JsonResponse
    {
        $scope = $this->assignerScope($request);
        if ($scope === null) {
            return response()->json(['message' => 'You cannot assign training.'], 403);
        }
        $videos = DB::table('training_videos')->where('status', 'published')->orderBy('audience')->orderBy('title')
            ->get(['id', 'audience', 'title', 'duration_sec']);

        return response()->json(['people' => array_values($this->people($scope)), 'videos' => $videos]);
    }

    /** POST /api/v1/training/assignments {user_ids[], video_ids[], due_on?, note?} */
    public function assign(Request $request): JsonResponse
    {
        $scope = $this->assignerScope($request);
        if ($scope === null) {
            return response()->json(['message' => 'You cannot assign training.'], 403);
        }
        $d = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer'],
            'video_ids' => ['required', 'array', 'min:1', 'max:100'],
            'video_ids.*' => ['integer'],
            'due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $people = $this->people($scope);
        $bad = array_values(array_diff(array_map('intval', $d['user_ids']), array_keys($people)));
        if ($bad) {
            return response()->json(['message' => 'Some of those people are outside what you can assign to.', 'user_ids' => $bad], 422);
        }
        $videos = DB::table('training_videos')->whereIn('id', $d['video_ids'])->where('status', 'published')->get(['id', 'title'])->keyBy('id');
        if ($videos->count() !== count(array_unique($d['video_ids']))) {
            return response()->json(['message' => 'Only published training videos can be assigned.'], 422);
        }

        $me = $request->user();
        $byName = trim(($me->first_name ?? '') . ' ' . ($me->last_name ?? ''));
        $created = 0; $already = 0; $emailed = 0;
        foreach (array_unique(array_map('intval', $d['user_ids'])) as $uid) {
            $newItems = [];
            foreach ($videos as $v) {
                $exists = DB::table('training_assignments')->where(['agency_id' => $scope['agency_id'], 'user_id' => $uid, 'video_id' => $v->id])
                    ->whereNull('cancelled_at')->first();
                if ($exists) {
                    // Re-assigning updates the due date / note rather than duplicating it.
                    DB::table('training_assignments')->where('id', $exists->id)->update([
                        'due_on' => $d['due_on'] ?? $exists->due_on, 'note' => $d['note'] ?? $exists->note,
                        'reminded_due_at' => null, 'reminded_overdue_at' => null, 'updated_at' => now(),
                    ]);
                    $already++;
                    continue;
                }
                DB::table('training_assignments')->insert([
                    'agency_id' => $scope['agency_id'], 'user_id' => $uid, 'video_id' => $v->id, 'assigned_by' => $me->id,
                    'due_on' => $d['due_on'] ?? null, 'note' => $d['note'] ?? null, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $created++;
                $newItems[] = ['title' => $v->title, 'due_on' => $d['due_on'] ?? null];
            }
            if ($newItems && TrainingMail::send($scope['agency_id'], $uid, 'assigned', $newItems, $d['note'] ?? null, $byName ?: null)) {
                DB::table('training_assignments')->where(['agency_id' => $scope['agency_id'], 'user_id' => $uid])
                    ->whereIn('video_id', $videos->keys())->whereNull('emailed_at')->update(['emailed_at' => now()]);
                $emailed++;
            }
        }

        return response()->json(['created' => $created, 'updated' => $already, 'people_emailed' => $emailed]);
    }

    /** GET /api/v1/training/assignments — progress of everything assigned in my scope. */
    public function report(Request $request): JsonResponse
    {
        $scope = $this->assignerScope($request);
        if ($scope === null) {
            return response()->json(['message' => 'You cannot view training progress.'], 403);
        }
        $people = $this->people($scope);
        $rows = DB::table('training_assignments as ta')
            ->join('training_videos as v', 'v.id', '=', 'ta.video_id')
            ->leftJoin('training_progress as p', function ($j) { $j->on('p.video_id', '=', 'ta.video_id')->on('p.user_id', '=', 'ta.user_id'); })
            ->leftJoin('users as b', 'b.id', '=', 'ta.assigned_by')
            ->where('ta.agency_id', $scope['agency_id'])->whereNull('ta.cancelled_at')
            ->whereIn('ta.user_id', array_keys($people) ?: [0])
            ->orderByDesc('ta.created_at')
            ->get(['ta.id', 'ta.user_id', 'ta.due_on', 'ta.note', 'ta.created_at', 'ta.emailed_at', 'v.title', 'v.duration_sec',
                   'p.position_sec', 'p.completed_at', 'p.updated_at as watched_at', DB::raw("CONCAT_WS(' ', b.first_name, b.last_name) as assigned_by_name")]);
        $today = now()->toDateString();
        $out = [];
        foreach ($rows as $r) {
            $pct = $r->completed_at ? 100 : ($r->duration_sec ? min(99, (int) round(100 * (int) $r->position_sec / max(1, (int) $r->duration_sec))) : 0);
            $out[] = [
                'id' => (int) $r->id, 'user_id' => (int) $r->user_id,
                'person' => $people[$r->user_id]['name'] ?? '', 'role' => $people[$r->user_id]['role'] ?? '',
                'title' => $r->title, 'due_on' => $r->due_on, 'note' => $r->note,
                'assigned_at' => $r->created_at, 'assigned_by' => trim((string) $r->assigned_by_name), 'emailed' => (bool) $r->emailed_at,
                'percent' => $pct, 'completed_at' => $r->completed_at, 'last_watched' => $r->watched_at,
                'status' => $r->completed_at ? 'completed' : (($r->due_on && $r->due_on < $today) ? 'overdue' : ($pct > 0 ? 'in_progress' : 'not_started')),
            ];
        }

        return response()->json(['assignments' => $out]);
    }

    /** DELETE /api/v1/training/assignments/{id} */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $scope = $this->assignerScope($request);
        if ($scope === null) {
            return response()->json(['message' => 'You cannot change training assignments.'], 403);
        }
        $a = DB::table('training_assignments')->where('id', $id)->where('agency_id', $scope['agency_id'])->whereNull('cancelled_at')->first();
        if (! $a || ! isset($this->people($scope)[(int) $a->user_id])) {
            return response()->json(['message' => 'Not found.'], 404);
        }
        DB::table('training_assignments')->where('id', $id)->update(['cancelled_at' => now(), 'cancelled_by' => $request->user()->id, 'updated_at' => now()]);

        return response()->json(['cancelled' => true]);
    }

    /**
     * Who may this caller assign to? null = not allowed at all.
     * ['agency_id' => int, 'centres' => int[]|null]  (null centres = the whole agency)
     */
    private function assignerScope(Request $request): ?array
    {
        $user = $request->user();
        $agencyId = (int) ($this->resolveAgencyId($request) ?: 0);
        if (! $user || ! $agencyId) {
            return null;
        }
        $roles = DB::table('role_assignments as ra')->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.user_id', $user->id)->where('ra.active', 1)
            ->get(['ra.role', DB::raw('COALESCE(ra.agency_id, c.agency_id) as aid'), 'ra.centre_id']);
        if ($this->isPlatformAdminUser($user) || $roles->contains(fn ($r) => $r->role === 'agency_admin' && (int) $r->aid === $agencyId)) {
            return ['agency_id' => $agencyId, 'centres' => null];
        }
        $centres = $roles->filter(fn ($r) => $r->role === 'centre_director' && (int) $r->aid === $agencyId && $r->centre_id)
            ->pluck('centre_id')->map(fn ($c) => (int) $c)->unique()->values()->all();

        return $centres ? ['agency_id' => $agencyId, 'centres' => $centres] : null;
    }

    /** @return array<int, array{id:int,name:string,email:?string,role:string}> keyed by user id */
    private function people(array $scope): array
    {
        $agencyId = $scope['agency_id'];
        $centreIds = $scope['centres'] ?? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all();
        $out = [];

        $staff = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->leftJoin('centres as c', 'c.id', '=', 'ra.centre_id')
            ->where('ra.active', 1)->whereIn('ra.role', self::STAFF_ROLES)->whereNull('u.deleted_at')
            ->where(function ($q) use ($scope, $agencyId, $centreIds) {
                $q->whereIn('ra.centre_id', $centreIds ?: [0]);
                if ($scope['centres'] === null) {
                    $q->orWhere('ra.agency_id', $agencyId);      // agency-level staff (home visitors, admins)
                }
            })
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email', 'ra.role']);
        foreach ($staff as $s) {
            $out[(int) $s->id] ??= ['id' => (int) $s->id, 'name' => trim($s->first_name . ' ' . $s->last_name), 'email' => $s->email, 'role' => self::roleLabel($s->role)];
        }

        $parents = DB::table('guardians as g')->join('users as u', 'u.id', '=', 'g.user_id')->join('families as f', 'f.id', '=', 'g.family_id')
            ->whereIn('f.centre_id', $centreIds ?: [0])->whereNull('u.deleted_at')
            ->get(['u.id', 'u.first_name', 'u.last_name', 'u.email']);
        foreach ($parents as $p) {
            $out[(int) $p->id] ??= ['id' => (int) $p->id, 'name' => trim($p->first_name . ' ' . $p->last_name), 'email' => $p->email, 'role' => 'Parent'];
        }
        uasort($out, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $out;
    }

    private static function roleLabel(string $r): string
    {
        return ['educator' => 'Educator', 'centre_director' => 'Director', 'home_visitor' => 'Home visitor', 'agency_admin' => 'Admin',
            'auditor' => 'Auditor', 'sales_rep' => 'Sales'][$r] ?? ucfirst(str_replace('_', ' ', $r));
    }

    /** The video for one article, for Help's "Watch the tutorial" — or null. */
    public static function videoForArticle(string $audience, string $slug, int $userId): ?array
    {
        try {
            $v = DB::table('training_videos')->where(['audience' => $audience, 'slug' => $slug])->first();
            if (! $v || ($v->status !== 'published' && ! self::isPlatformAdmin($userId))) {
                return null;
            }

            return self::shape($v, DB::table('training_progress')->where(['user_id' => $userId, 'video_id' => $v->id])->first());
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function shape(object $v, ?object $p): array
    {
        return [
            'id' => (int) $v->id,
            'slug' => $v->slug,
            'audience' => $v->audience,
            'title' => $v->title,
            'video_url' => $v->video_url,
            'captions_url' => $v->captions_url,
            'poster_url' => $v->poster_url,
            'duration_sec' => (int) $v->duration_sec,
            'chapters' => $v->chapters ? json_decode($v->chapters, true) : [],
            'status' => $v->status,
            'position_sec' => $p ? (int) $p->position_sec : 0,
            'completed' => (bool) ($p && $p->completed_at),
        ];
    }

    private static function isPlatformAdmin(int $userId): bool
    {
        return DB::table('role_assignments')->where('user_id', $userId)->where('role', 'platform_admin')->where('active', 1)->exists();
    }
}
