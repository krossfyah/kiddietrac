<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\LearningFrameworks;
use App\Support\Live;
use App\Support\OhioPfcc;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * STEP UP TO QUALITY (Ohio's rating system, OAC 5180:2-17-01, Appendix A) — 2026-10-10.
 *
 * Per child: developmental screening within 60 days of enrolment then yearly, results shared
 * with the family within 30 days; family needs assessment within 30 days then yearly; child
 * assessments twice a year (Silver and Gold). Per centre: referrals with follow-up, a
 * continuous improvement plan, and an evidence binder of what is done and missing. Shown only
 * for US-OH agencies or where an admin switches it on (OhioPfcc settings).
 */
final class SutqController extends Controller
{
    /** [agencyId, centres id=>name, today] */
    private function ctx(Request $request): array
    {
        $uid = (int) $request->user()->id;
        $active = (int) $request->header('X-Active-Agency-Id');
        $roles = DB::table('role_assignments')->where('user_id', $uid)->where('active', true);
        if ((clone $roles)->where('role', 'platform_admin')->exists()) {
            abort_unless($active && DB::table('agencies')->where('id', $active)->whereNull('deleted_at')->exists(), 400, 'Select an agency first.');
            $agencyId = $active;
        } elseif ($active && (clone $roles)->where(fn ($w) => $w->where('agency_id', $active)->orWhereIn('centre_id', DB::table('centres')->where('agency_id', $active)->pluck('id')))->exists()) {
            $agencyId = $active;
        } else {
            $agencyId = (int) ((clone $roles)->whereNotNull('agency_id')->value('agency_id')
                ?: DB::table('centres')->whereIn('id', (clone $roles)->whereNotNull('centre_id')->pluck('centre_id'))->value('agency_id'));
            abort_unless($agencyId, 403);
        }
        abort_unless(OhioPfcc::sutqOn($agencyId), 404, 'Step Up To Quality is not switched on for this agency.');
        $scope = CentreScope::ids($request, $agencyId);
        $q = DB::table('centres')->whereIn('id', Live::centreIds($agencyId) ?: [0]);
        if ($scope !== null) $q->whereIn('id', $scope ?: [0]);

        return [$agencyId, $q->orderBy('name')->pluck('name', 'id')->all(), AgencyTime::today($agencyId)];
    }

    private function audit(Request $request, int $agencyId, string $action, string $type, int $id, string $summary): void
    {
        try {
            \App\Support\Audit::write(['user_id' => (int) $request->user()->id, 'agency_id' => $agencyId, 'action' => $action, 'entity_type' => $type, 'entity_id' => $id,
                'payload' => json_encode(['summary' => $summary]), 'ip_address' => substr((string) $request->ip(), 0, 45), 'created_at' => now()]);
        } catch (\Throwable $e) {}
    }

    private function centreFilter(Request $request, array $centres): array
    {
        $ids = array_keys($centres);
        if ($cid = (int) $request->query('centre_id', $request->input('centre_id'))) $ids = array_values(array_intersect($ids, [$cid]));

        return $ids;
    }

    /** Enrolled children in reach with their start date. */
    private function children(array $centreIds)
    {
        $q = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->whereIn('f.centre_id', $centreIds ?: [0]);
        Live::children($q, 'c');
        $kids = $q->orderBy('c.first_name')->get(['c.id', 'c.first_name', 'c.last_name', 'c.preferred_name', 'c.date_of_birth', 'c.enrolled_at', 'f.centre_id']);
        $starts = DB::table('enrollments')->whereIn('child_id', $kids->pluck('id')->all() ?: [0])->select('child_id', DB::raw('MIN(start_date) as s'))->groupBy('child_id')->pluck('s', 'child_id');
        foreach ($kids as $k) $k->start = $starts[$k->id] ?? ($k->enrolled_at ? substr((string) $k->enrolled_at, 0, 10) : null);

        return $kids;
    }

    /** One timer: {last, due, status, share_due?}. */
    private static function timer(?object $last, ?string $start, int $firstDays, int $everyDays, string $today, bool $share = false): array
    {
        $due = $last ? Carbon::parse($last->completed_on)->addDays($everyDays)->toDateString()
            : ($start ? Carbon::parse($start)->addDays($firstDays)->toDateString() : null);
        $status = ! $due ? 'unknown' : ($due < $today ? 'overdue' : (Carbon::parse($today)->addDays(14)->toDateString() >= $due ? 'due_soon' : 'ok'));
        $out = ['last' => $last->completed_on ?? null, 'due' => $due, 'status' => $last && $status === 'ok' ? 'done' : $status, 'id' => $last->id ?? null, 'tool' => $last->tool ?? null];
        if ($share && $last) {
            $out['shared_on'] = $last->shared_with_family_on;
            $out['share_due'] = Carbon::parse($last->completed_on)->addDays(30)->toDateString();
            $out['share_status'] = $last->shared_with_family_on ? 'done' : ($out['share_due'] < $today ? 'overdue' : 'due');
        }

        return $out;
    }

    private function snapshot(int $agencyId, array $centreIds, string $today): array
    {
        $level = OhioPfcc::settings($agencyId)['sutq_level'];
        $kids = $this->children($centreIds);
        $events = DB::table('sutq_child_events')->whereIn('child_id', $kids->pluck('id')->all() ?: [0])->orderByDesc('completed_on')->orderByDesc('id')->get()->groupBy(fn ($e) => $e->child_id . ':' . $e->kind);
        $rows = [];
        foreach ($kids as $k) {
            $last = fn ($kind) => ($events[$k->id . ':' . $kind] ?? collect())->first();
            $rows[] = ['id' => (int) $k->id, 'name' => trim(($k->preferred_name ?: $k->first_name) . ' ' . $k->last_name), 'centre_id' => (int) $k->centre_id, 'start' => $k->start,
                'screening' => self::timer($last('screening'), $k->start, 60, 365, $today, true),
                'family_needs' => self::timer($last('family_needs'), $k->start, 30, 365, $today),
                'assessment' => in_array($level, ['silver', 'gold'], true) ? self::timer($last('assessment'), $k->start, 182, 182, $today) : null];
        }

        return [$level, $rows];
    }

    /** GET /sutq?centre_id= */
    public function index(Request $request): JsonResponse
    {
        [$agencyId, $centres, $today] = $this->ctx($request);
        $ids = $this->centreFilter($request, $centres);
        [$level, $rows] = $this->snapshot($agencyId, $ids, $today);
        $refs = DB::table('sutq_referrals as r')->join('children as c', 'c.id', '=', 'r.child_id')->whereIn('r.centre_id', $ids ?: [0])->orderByDesc('r.referred_on')->limit(200)
            ->get(['r.*', DB::raw("CONCAT_WS(' ', c.first_name, c.last_name) as child")]);
        $cip = DB::table('sutq_cip_items')->whereIn('centre_id', $ids ?: [0])->orderByRaw("FIELD(status,'in_progress','planned','done')")->orderBy('due_on')->get();
        $fw = LearningFrameworks::forAgency($agencyId);

        return response()->json(['level' => $level, 'today' => $today, 'centres' => $centres, 'children' => $rows, 'referrals' => $refs, 'cip' => $cip,
            'framework' => ['key' => $fw['key'], 'name' => $fw['name'], 'chosen' => $fw['chosen']]]);
    }

    private function childInReach(int $childId, array $centres): object
    {
        $c = DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')->where('c.id', $childId)->first(['c.id', 'f.centre_id']);
        abort_unless($c && isset($centres[$c->centre_id]), 404);

        return $c;
    }

    /** POST /sutq/events {child_id, kind, completed_on, shared_with_family_on?, tool?, notes?} */
    public function storeEvent(Request $request): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $d = $request->validate(['child_id' => 'required|integer', 'kind' => 'required|in:screening,family_needs,assessment', 'completed_on' => 'required|date|before_or_equal:today',
            'shared_with_family_on' => 'nullable|date', 'tool' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000']);
        $c = $this->childInReach((int) $d['child_id'], $centres);
        $id = DB::table('sutq_child_events')->insertGetId($d + ['agency_id' => $agencyId, 'centre_id' => (int) $c->centre_id, 'recorded_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'sutq.' . $d['kind'] . '_recorded', 'child', (int) $c->id, 'Step Up To Quality: ' . str_replace('_', ' ', $d['kind']) . ' recorded for ' . $d['completed_on']);

        return response()->json(['id' => $id]);
    }

    /** PATCH /sutq/events/{id} {shared_with_family_on?, tool?, notes?, completed_on?} */
    public function updateEvent(Request $request, int $id): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        $e = DB::table('sutq_child_events')->where('id', $id)->where('agency_id', $agencyId)->first();
        abort_unless($e && isset($centres[$e->centre_id]), 404);
        $d = $request->validate(['shared_with_family_on' => 'sometimes|nullable|date', 'tool' => 'sometimes|nullable|string|max:120', 'notes' => 'sometimes|nullable|string|max:2000',
            'completed_on' => 'sometimes|date|before_or_equal:today']);
        DB::table('sutq_child_events')->where('id', $id)->update($d + ['updated_at' => now()]);
        $this->audit($request, $agencyId, 'sutq.event_changed', 'child', (int) $e->child_id, 'Step Up To Quality record changed: ' . implode(', ', array_keys($d)));

        return response()->json(['ok' => true]);
    }

    /** GET /sutq/children/{id}/events */
    public function childEvents(Request $request, int $childId): JsonResponse
    {
        [, $centres] = $this->ctx($request);
        $this->childInReach($childId, $centres);

        return response()->json(['events' => DB::table('sutq_child_events')->where('child_id', $childId)->orderByDesc('completed_on')->get(),
            'referrals' => DB::table('sutq_referrals')->where('child_id', $childId)->orderByDesc('referred_on')->get()]);
    }

    /** POST /sutq/referrals ; PATCH /sutq/referrals/{id} */
    public function saveReferral(Request $request, ?int $id = null): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        if ($id) {
            $r = DB::table('sutq_referrals')->where('id', $id)->where('agency_id', $agencyId)->first();
            abort_unless($r && isset($centres[$r->centre_id]), 404);
            $d = $request->validate(['referred_to' => 'sometimes|string|max:190', 'reason' => 'sometimes|nullable|string|max:255', 'follow_up_due' => 'sometimes|nullable|date',
                'followed_up_on' => 'sometimes|nullable|date', 'outcome' => 'sometimes|nullable|string|max:2000']);
            DB::table('sutq_referrals')->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->audit($request, $agencyId, 'sutq.referral_changed', 'child', (int) $r->child_id, 'Referral updated: ' . implode(', ', array_keys($d)));

            return response()->json(['ok' => true]);
        }
        $d = $request->validate(['child_id' => 'required|integer', 'referred_on' => 'required|date|before_or_equal:today', 'referred_to' => 'required|string|max:190',
            'reason' => 'nullable|string|max:255', 'follow_up_due' => 'nullable|date', 'outcome' => 'nullable|string|max:2000']);
        $c = $this->childInReach((int) $d['child_id'], $centres);
        $d['follow_up_due'] = $d['follow_up_due'] ?? Carbon::parse($d['referred_on'])->addDays(30)->toDateString();
        $newId = DB::table('sutq_referrals')->insertGetId($d + ['agency_id' => $agencyId, 'centre_id' => (int) $c->centre_id, 'recorded_by' => (int) $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'sutq.referral_added', 'child', (int) $c->id, 'Referral to ' . $d['referred_to']);

        return response()->json(['id' => $newId]);
    }

    /** POST /sutq/cip ; PATCH /sutq/cip/{id} ; DELETE /sutq/cip/{id} */
    public function saveCip(Request $request, ?int $id = null): JsonResponse
    {
        [$agencyId, $centres] = $this->ctx($request);
        if ($id) {
            $r = DB::table('sutq_cip_items')->where('id', $id)->where('agency_id', $agencyId)->first();
            abort_unless($r && isset($centres[$r->centre_id]), 404);
            if ($request->isMethod('delete')) {
                DB::table('sutq_cip_items')->where('id', $id)->delete();
                $this->audit($request, $agencyId, 'sutq.cip_removed', 'centre', (int) $r->centre_id, 'Improvement plan item removed: ' . $r->goal);
                return response()->json(['ok' => true]);
            }
            $d = $request->validate(['goal' => 'sometimes|string|max:255', 'action' => 'sometimes|nullable|string|max:2000', 'owner' => 'sometimes|nullable|string|max:120',
                'due_on' => 'sometimes|nullable|date', 'status' => 'sometimes|in:planned,in_progress,done', 'notes' => 'sometimes|nullable|string|max:2000']);
            DB::table('sutq_cip_items')->where('id', $id)->update($d + ['updated_at' => now()]);
            $this->audit($request, $agencyId, 'sutq.cip_changed', 'centre', (int) $r->centre_id, 'Improvement plan item changed: ' . implode(', ', array_keys($d)));

            return response()->json(['ok' => true]);
        }
        $d = $request->validate(['centre_id' => 'required|integer', 'goal' => 'required|string|max:255', 'action' => 'nullable|string|max:2000', 'owner' => 'nullable|string|max:120',
            'due_on' => 'nullable|date', 'status' => 'nullable|in:planned,in_progress,done', 'notes' => 'nullable|string|max:2000']);
        abort_unless(isset($centres[(int) $d['centre_id']]), 404);
        $d['status'] = $d['status'] ?? 'planned';
        $newId = DB::table('sutq_cip_items')->insertGetId($d + ['agency_id' => $agencyId, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit($request, $agencyId, 'sutq.cip_added', 'centre', (int) $d['centre_id'], 'Improvement plan item: ' . $d['goal']);

        return response()->json(['id' => $newId]);
    }

    /**
     * GET /sutq/binder?centre_id= — the rating evidence: each standard with done / partly /
     * missing and the numbers behind it, so a director sees what to fix before a visit.
     */
    public function binder(Request $request): JsonResponse
    {
        [$agencyId, $centres, $today] = $this->ctx($request);
        $ids = $this->centreFilter($request, $centres);
        [$level, $rows] = $this->snapshot($agencyId, $ids, $today);
        $n = count($rows);
        $pct = function (callable $ok, callable $applies = null) use ($rows) {
            $in = array_values(array_filter($rows, $applies ?? fn () => true));
            if (! $in) return [null, 0, 0];
            $good = count(array_filter($in, $ok));
            return [(int) round($good / count($in) * 100), $good, count($in)];
        };
        $item = function (string $std, string $label, ?int $p, int $good, int $of, string $hint) {
            $status = $p === null ? 'n/a' : ($p >= 100 ? 'done' : ($p >= 50 ? 'partly' : 'missing'));
            return ['standard' => $std, 'label' => $label, 'status' => $status, 'percent' => $p, 'detail' => $p === null ? 'No children to measure.' : "$good of $of", 'hint' => $hint];
        };
        $items = [];
        $fw = LearningFrameworks::forAgency($agencyId);
        $items[] = ['standard' => 'Curriculum', 'label' => 'Curriculum aligned to Ohio\'s Early Learning and Development Standards',
            'status' => $fw['key'] === 'OH_ELDS' ? 'done' : 'missing', 'percent' => null, 'detail' => 'Learning framework: ' . $fw['name'],
            'hint' => 'Settings → Learning framework → "Ohio Early Learning and Development Standards".'];
        $plans = DB::table('lesson_plans')->whereIn('centre_id', $ids ?: [0])->where('week_starting', '>=', Carbon::parse($today)->subDays(35)->toDateString())->count()
            + DB::table('ai_lesson_plans')->whereIn('centre_id', $ids ?: [0])->where('week_starting', '>=', Carbon::parse($today)->subDays(35)->toDateString())->count();
        $items[] = ['standard' => 'Curriculum', 'label' => 'Written lesson plans in use', 'status' => $plans ? 'done' : 'missing', 'percent' => null,
            'detail' => $plans . ' plan(s) in the last 5 weeks', 'hint' => 'Lesson plans, planned weekly for each group.'];
        [$p, $g, $o] = $pct(fn ($r) => $r['screening']['last'] !== null && $r['screening']['status'] !== 'overdue');
        $items[] = $item('Screening', 'Developmental screening within 60 days of enrolment, then yearly', $p, $g, $o, 'Record each screening on the child\'s row.');
        [$p, $g, $o] = $pct(fn ($r) => ($r['screening']['share_status'] ?? '') === 'done', fn ($r) => $r['screening']['last'] !== null);
        $items[] = $item('Screening', 'Screening results shared with the family within 30 days', $p, $g, $o, 'Mark "shared with family" on the screening.');
        [$p, $g, $o] = $pct(fn ($r) => $r['family_needs']['last'] !== null && $r['family_needs']['status'] !== 'overdue');
        $items[] = $item('Family partnerships', 'Family needs assessment within 30 days of enrolment, then yearly', $p, $g, $o, 'Record each family needs assessment.');
        if (in_array($level, ['silver', 'gold'], true)) {
            [$p, $g, $o] = $pct(fn ($r) => $r['assessment'] && $r['assessment']['last'] !== null && $r['assessment']['status'] !== 'overdue');
            $items[] = $item('Assessment', 'Child assessment at least twice a year', $p, $g, $o, 'Record each assessment checkpoint.');
        }
        $openRefs = DB::table('sutq_referrals')->whereIn('centre_id', $ids ?: [0])->whereNull('followed_up_on')->where('follow_up_due', '<', $today)->count();
        $allRefs = DB::table('sutq_referrals')->whereIn('centre_id', $ids ?: [0])->count();
        $items[] = ['standard' => 'Screening', 'label' => 'Referrals followed up on time', 'status' => $openRefs ? 'missing' : 'done', 'percent' => null,
            'detail' => $allRefs ? ($openRefs . ' overdue of ' . $allRefs) : 'No referrals recorded', 'hint' => 'Record the follow-up date on each referral.'];
        $cip = DB::table('sutq_cip_items')->whereIn('centre_id', $ids ?: [0]);
        $cipN = (clone $cip)->count(); $cipLate = (clone $cip)->where('status', '!=', 'done')->where('due_on', '<', $today)->count();
        $items[] = ['standard' => 'Administrative practices', 'label' => 'Continuous improvement plan in place and on track', 'status' => ! $cipN ? 'missing' : ($cipLate ? 'partly' : 'done'),
            'percent' => null, 'detail' => $cipN ? ($cipN . ' item(s), ' . $cipLate . ' overdue') : 'No plan yet', 'hint' => 'Add goals and actions on the Improvement plan tab.'];
        $items[] = ['standard' => 'Staff qualifications', 'label' => 'Professional development hours (10 a year; 12 or 15 for administrators)', 'status' => 'info', 'percent' => null,
            'detail' => 'Tracked under Compliance → Staff training & checks.', 'hint' => 'Each staff member logs hours under My training.'];

        return response()->json(['level' => $level, 'children' => $n, 'items' => $items]);
    }
}
