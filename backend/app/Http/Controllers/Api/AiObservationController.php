<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Models\Observation;
use App\Services\AiObservationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * AiObservationController v21.
 *
 * Endpoints:
 *   POST /provider/observations/structure       - call AI to structure raw text
 *   POST /provider/observations/save            - save (with optional educator edits)
 *   GET  /provider/observations                 - list (for educator's children)
 */
class AiObservationController extends Controller
{
    use ResolvesCentreContext;

    public function __construct(protected AiObservationService $ai)
    {
    }

    /**
     * POST /provider/observations/structure
     * Body: { child_id, raw_text }
     * Returns the AI-structured output WITHOUT saving. Educator can review/edit before save.
     */
    public function structure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => 'required|integer|exists:children,id',
            'raw_text' => 'required|string|min:10|max:3000',
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403); // v22p94

        $child = Child::find($data['child_id']);
        if (! $child) return response()->json(['error' => 'Child not found'], 404);

        try {
            $result = $this->ai->structure($child, $data['raw_text']);
        } catch (\Throwable $e) {
            $result = ['success' => false, 'error' => $e->getMessage()];
        }

        if (! ($result['success'] ?? false)) {
            // The model call failed (outbound HTTPS to the AI provider is blocked on
            // this host — same issue as report cards). NEVER lose the educator's
            // observation: fall back to a data-grounded structure using their exact
            // words so they can still review, edit and save it.
            return response()->json([
                'success'    => true,
                'structured' => $this->fallbackStructure($child, $data['raw_text']),
                'meta'       => ['model' => null, 'tokens_used' => null, 'fallback' => true],
            ]);
        }

        return response()->json([
            'success'    => true,
            'structured' => $result['structured'],
            'meta'       => [
                'model'       => $result['model'] ?? null,
                'tokens_used' => $result['tokens_used'] ?? null,
            ],
        ]);
    }

    /**
     * Data-grounded fallback when the AI is unavailable: keep the educator's raw
     * words as the parent summary and pick an HDLH domain from simple keywords so
     * the observation is never blocked or lost.
     */
    private function fallbackStructure(Child $child, string $raw): array
    {
        $summary = trim($raw);
        if ($summary !== '' && ! preg_match('/[.!?]$/', $summary)) {
            $summary .= '.';
        }
        $lc = mb_strtolower($raw);
        // Keyed by the HDLH foundation the words suggest — that is the pedagogy — but
        // the VALUE is the domain the rest of the platform stores and groups by. Emitting
        // "Belonging" here put 7 observations outside every other screen's vocabulary.
        $map = [
            'physical'          => ['nap', 'sleep', 'ate', 'eat', 'food', 'rest', 'wash', 'clean', 'hurt', 'sick', 'tired', 'diaper', 'toilet', 'potty', 'run', 'climb', 'jump', 'physical'],
            'cognitive'         => ['built', 'build', 'puzzle', 'block', 'explore', 'curious', 'experiment', 'count', 'sort', 'discover', 'figure', 'problem', 'focus', 'concentrat'],
            'language_literacy' => ['said', 'say', 'story', 'talk', 'word', 'book', 'read', 'name', 'ask', 'answer', 'question'],
            'creative_arts'     => ['sang', 'sing', 'drew', 'draw', 'paint', 'danc', 'music', 'pretend', 'role', 'craft', 'colour', 'color'],
            'social_emotional'  => ['friend', 'share', 'help', 'together', 'group', 'kind', 'care', 'comfort', 'hug', 'turn', 'gentle', 'include'],
        ];
        $domain = 'social_emotional';
        foreach ($map as $d => $kw) {
            foreach ($kw as $k) {
                if (str_contains($lc, $k)) { $domain = $d; break 2; }
            }
        }

        return [
            'domain'          => $domain,
            'hdlh_milestones' => [],
            'parent_summary'  => ucfirst($summary),
        ];
    }

    /**
     * POST /provider/observations/save
     * Body: { child_id, raw_text, structured: {domain, hdlh_milestones, parent_summary},
     *         shared_with_family: bool, ai_generated: bool }
     */
    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id'                       => 'required|integer|exists:children,id',
            'raw_text'                       => 'required|string|min:10|max:3000',
            'structured'                     => 'required|array',
            'structured.domain'              => 'required|string|max:60',
            'structured.hdlh_milestones'     => 'nullable|array',
            'structured.parent_summary'      => 'required|string|max:2000',
            // Defaults to NOT shared when absent: publishing to a family by accident is
            // the worse mistake. The observation screen ticks its box by default and
            // always sends this explicitly, so the two only differ for other callers.
            'shared_with_family'             => 'nullable|boolean',
            'ai_generated'                   => 'nullable|boolean',
            'ai_model_used'                  => 'nullable|string|max:80',
            'ai_tokens_used'                 => 'nullable|integer',
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403); // v22p94

        $observation = Observation::create([
            'child_id'             => $data['child_id'],
            'recorded_by_id'       => $request->user()->id,
            'observed_at'          => now(),
            'domain'               => $data['structured']['domain'],
            'body'                 => $data['structured']['parent_summary'],
            'raw_text'             => $data['raw_text'],
            'hdlh_milestones'      => $data['structured']['hdlh_milestones'] ?? [],
            'family_summary'       => $data['structured']['parent_summary'],
            'ai_generated'         => $data['ai_generated'] ?? true,
            'ai_model_used'        => $data['ai_model_used'] ?? null,
            'ai_tokens_used'       => $data['ai_tokens_used'] ?? null,
            'ai_processed_at'      => now(),
            'educator_reviewed_at' => now(),
            'shared_with_family'   => $data['shared_with_family'] ?? false,
        ]);

        return response()->json(['data' => $observation], 201);
    }

    /**
     * GET /provider/observations - list recent for this educator's scope
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = min(100, max(5, (int) $request->query('limit', 30)));

        // Joined so the ORDER BY can run in the database, before the limit. Sorting a
        // page that has already been cut to thirty would arrange the newest thirty by
        // child and present it as "sorted by child" — the wrong rows, tidily ordered.
        $q = Observation::query()
            ->leftJoin('children as ch', 'ch.id', '=', 'observations.child_id')
            ->leftJoin('users as ru', 'ru.id', '=', 'observations.recorded_by_id')
            ->leftJoin('families as fam', 'fam.id', '=', 'ch.family_id')
            ->leftJoin('centres as ctr', 'ctr.id', '=', 'fam.centre_id')
            ->select('observations.*',
                DB::raw("TRIM(CONCAT(COALESCE(ch.first_name,''),' ',COALESCE(ch.last_name,''))) as _child"),
                DB::raw("TRIM(CONCAT(COALESCE(ru.first_name,''),' ',COALESCE(ru.last_name,''))) as _educator"),
                DB::raw('ctr.name as _centre'),
                DB::raw('ctr.id as _centre_id'))
            ->limit($limit);

        // Sort by what somebody is actually looking for. Date descending stays the
        // default: an oversight screen opens on what just happened.
        $dir = strtolower((string) $request->query('dir', '')) === 'asc' ? 'asc' : 'desc';
        switch (strtolower((string) $request->query('sort', 'date'))) {
            case 'child':    $q->orderBy('_child', $dir === 'desc' ? 'desc' : 'asc')->orderByDesc('observations.observed_at'); break;
            case 'educator': $q->orderBy('_educator', $dir === 'desc' ? 'desc' : 'asc')->orderByDesc('observations.observed_at'); break;
            case 'centre':
            case 'provider': $q->orderBy('_centre', $dir === 'desc' ? 'desc' : 'asc')->orderByDesc('observations.observed_at'); break;
            case 'domain':   $q->orderBy('observations.domain', $dir === 'desc' ? 'desc' : 'asc')->orderByDesc('observations.observed_at'); break;
            default:         $q->orderBy('observations.observed_at', $dir); break;
        }

        // v22p98: educators see their OWN observations; an agency_admin /
        // centre_director / platform_admin gets a centre-wide oversight view
        // (scoped to the active agency's centres), so demo data is visible.
        $isAdminView = DB::table('role_assignments')->where('user_id', $user->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'platform_admin'])
            ->where('active', true)->exists();
        if ($isAdminView) {
            // EVERY centre in the active agency, not one of them. This resolved a single
            // centre — which the comment above already says it should not — and on an
            // agency whose centres ARE its providers, one centre means one provider. For
            // iLearn, with nine, an admin saw at most a ninth of what was written and
            // usually nothing, because the resolved centre rarely held the children whose
            // observations existed.
            $agencyId = $this->resolveAgencyId($request);
            $centreIds = $agencyId
                ? DB::table('centres')->where('agency_id', $agencyId)->pluck('id')->all()
                : array_filter([$this->resolveCentreId($user)]);

            // A director oversees their own centre rather than the whole agency; an
            // agency admin or platform admin oversees all of it.
            $isAgencyWide = DB::table('role_assignments')->where('user_id', $user->id)
                ->whereIn('role', ['agency_admin', 'platform_admin'])
                ->where('active', true)->exists();
            if (! $isAgencyWide) {
                $own = DB::table('role_assignments')->where('user_id', $user->id)
                    ->where('active', true)->whereNotNull('centre_id')->pluck('centre_id')->all();
                if ($own) {
                    $centreIds = array_values(array_intersect($centreIds, $own)) ?: $own;
                }
            }

            $childIds = $centreIds
                ? DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
                    ->whereIn('f.centre_id', $centreIds)->pluck('c.id')->all()
                : [];
            $q->whereIn('observations.child_id', $childIds ?: [0]);
        } else {
            $q->where('observations.recorded_by_id', $user->id);
        }

        // Qualified: child_id is now ambiguous across the joined tables.
        if ($childId = $request->query('child_id')) {
            $q->where('observations.child_id', (int) $childId);
        }
        if ($eduId = $request->query('educator_id')) {
            $q->where('observations.recorded_by_id', (int) $eduId);
        }
        if ($ctrId = $request->query('centre_id')) {
            $q->where('ctr.id', (int) $ctrId);
        }

        $rows = $q->get();

        // The room, per child, from the enrolment that is current. NOT joined into the
        // query above: a child has many enrolments over time, and joining rooms would turn
        // one observation into one row per enrolment that child has ever had.
        $childIds = $rows->pluck('child_id')->unique()->filter()->all();
        $rooms = [];
        if ($childIds) {
            foreach (DB::table('enrollments as e')->leftJoin('rooms as r', 'r.id', '=', 'e.room_id')
                ->whereIn('e.child_id', $childIds)
                ->orderByRaw('e.end_date IS NULL DESC')     // the open enrolment first
                ->orderByDesc('e.start_date')
                ->get(['e.child_id', 'r.name']) as $r) {
                // First row per child wins, which the ordering above makes the current one.
                if (! isset($rooms[$r->child_id]) && $r->name) {
                    $rooms[$r->child_id] = $r->name;
                }
            }
        }

        $out = $rows->map(function ($o) use ($rooms) {
            return [
                'id'                 => $o->id,
                'child_id'           => $o->child_id,
                'child_name'         => $o->_child ?: '—',
                // Who wrote it. On an agency whose centres are its providers this is most
                // of the point of an oversight list, and it was not being returned at all.
                'educator_id'        => $o->recorded_by_id,
                'educator_name'      => $o->_educator ?: '—',
                'centre_id'          => $o->_centre_id,
                'centre_name'        => $o->_centre ?: '—',
                'provider_name'      => $o->_centre ?: '—',   // a home-childcare centre IS the provider
                'room_name'          => $rooms[$o->child_id] ?? '—',
                'title'              => $o->title,            // was omitted, so rows had no headline
                'framework'          => $o->framework,
                'observed_at'        => $o->observed_at,
                'domain'             => $o->domain,
                'body'               => $o->body,
                'family_summary'     => $o->family_summary,
                'hdlh_milestones'    => $o->hdlh_milestones,
                'ai_generated'       => (bool) $o->ai_generated,
                'shared_with_family' => (bool) $o->shared_with_family,
            ];
        });

        return response()->json([
            'observations' => $out,
            // Echoed so the screen can show which ordering is active without tracking it.
            'sort' => strtolower((string) $request->query('sort', 'date')),
            'dir' => $dir,
        ]);
    }
}
