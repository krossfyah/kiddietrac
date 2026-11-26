<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\AiBundle;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Notify;
use App\Support\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * KIDDIETRAC HR, PHASE 2 — PERFORMANCE (2026-11-28): review cycles (probation / annual / custom) with a self review
 * and a manager review on the agency's own template (sections + rating scale + comments), goals with check-ins,
 * one-to-one notes, and sign-off by both people.
 *
 * WHO: the employee, their reviewer / file manager, the agency admins and the directors of their own centres
 * (HrAccess::hrCanSee). Never other staff. Nobody reviews themselves; the employee never edits the manager's part and
 * the manager never edits the employee's. The employee sees the manager's part once it is submitted.
 * AI drafting (goals, review wording) only with the AI Bundle (ai_tools), from the person's own records, metered to the
 * agency (ai_usage) and fresh (AiFresh).
 */
final class HrPerformanceController extends Controller
{
    use HrAccess;

    // ── list + templates ──────────────────────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        $ids = $this->hrPeople($request, $aid);
        $rows = DB::table('hr_reviews')->where('agency_id', $aid)->where('status', '!=', 'cancelled')
            ->where(fn ($q) => $q->whereIn('user_id', $ids ?: [0])->orWhere('manager_user_id', $me))
            ->where('user_id', '!=', $me)->orderByDesc('created_at')->limit(500)->get();
        $names = $this->hrNames(array_merge($rows->pluck('user_id')->all(), $rows->pluck('manager_user_id')->all()));
        $goals = DB::table('hr_goals')->where('agency_id', $aid)->whereIn('review_id', $rows->pluck('id')->all() ?: [0])->groupBy('review_id')
            ->selectRaw('review_id, COUNT(*) n')->pluck('n', 'review_id');

        return response()->json([
            'reviews' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'name' => $names[(int) $r->user_id] ?? '',
                'manager' => $names[(int) $r->manager_user_id] ?? '', 'manager_user_id' => (int) $r->manager_user_id, 'title' => $r->title, 'cycle' => $r->cycle,
                'due_date' => $r->due_date ? substr((string) $r->due_date, 0, 10) : null, 'status' => HrTalent::reviewStatus($r), 'goals' => (int) ($goals[$r->id] ?? 0)])->values(),
            'templates' => $this->templates($aid), 'cycles' => HrTalent::CYCLES, 'goal_status' => HrTalent::GOAL_STATUS,
            'people' => DB::table('users')->whereIn('id', $ids ?: [0])->where('id', '!=', $me)->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u),
                    'manager_user_id' => (int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $u->id)->value('manager_user_id') ?: null])->values(),
            'managers' => $this->hrManagerChoices($aid, 0), 'can_templates' => $this->hrIsAdmin($request), 'ai' => self::aiOn($aid),
            'default_scale' => HrTalent::DEFAULT_SCALE, 'default_sections' => HrTalent::DEFAULT_SECTIONS,
        ]);
    }

    private function templates(int $aid): array
    {
        return DB::table('hr_review_templates')->where('agency_id', $aid)->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'archived' => (bool) $t->archived_at,
                'scale' => json_decode((string) $t->scale, true) ?: [], 'sections' => json_decode((string) $t->sections, true) ?: []])->all();
    }

    public function saveTemplate(Request $request, ?int $id = null): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['name' => 'required|string|max:120', 'scale' => 'required|array|min:2|max:10', 'scale.*.label' => 'required|string|max:60',
            'sections' => 'required|array|min:1|max:20', 'sections.*.title' => 'required|string|max:120', 'sections.*.prompt' => 'nullable|string|max:600']);
        if ($id) abort_unless(DB::table('hr_review_templates')->where('id', $id)->where('agency_id', $aid)->exists(), 404, 'That template was not found.');
        $scale = array_values(array_map(fn ($s, $i) => ['value' => $i + 1, 'label' => trim($s['label'])], $d['scale'], array_keys($d['scale'])));
        $sections = array_values(array_map(fn ($s) => ['title' => trim($s['title']), 'prompt' => trim((string) ($s['prompt'] ?? ''))], $d['sections']));
        $row = ['name' => trim($d['name']), 'scale' => json_encode($scale, JSON_UNESCAPED_UNICODE), 'sections' => json_encode($sections, JSON_UNESCAPED_UNICODE), 'updated_at' => now()];
        if ($id) {
            DB::table('hr_review_templates')->where('id', $id)->update($row);
        } else {
            $id = (int) DB::table('hr_review_templates')->insertGetId($row + ['agency_id' => $aid, 'created_by_id' => $request->user()->id, 'created_at' => now()]);
        }
        $this->hrNote($aid, 'hr.review_template_saved', 'hr_review_template', $id, ['name' => $row['name']]);

        return response()->json(['id' => $id], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        return $this->saveTemplate($request, $id);
    }

    public function archiveTemplate(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(DB::table('hr_review_templates')->where('id', $id)->where('agency_id', $aid)->exists(), 404, 'That template was not found.');
        $restore = str_ends_with($request->path(), '/restore');
        DB::table('hr_review_templates')->where('id', $id)->update(['archived_at' => $restore ? null : now(), 'updated_at' => now()]);
        $this->hrNote($aid, $restore ? 'hr.review_template_restored' : 'hr.review_template_archived', 'hr_review_template', $id);

        return response()->json(['ok' => true]);
    }

    // ── reviews ───────────────────────────────────────────────────────────────────────────

    public function create(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate(['user_id' => 'required|integer', 'manager_user_id' => 'required|integer', 'template_id' => 'nullable|integer',
            'cycle' => 'required|in:' . implode(',', array_keys(HrTalent::CYCLES)), 'title' => 'nullable|string|max:160',
            'period_start' => 'nullable|date_format:Y-m-d', 'period_end' => 'nullable|date_format:Y-m-d', 'due_date' => 'nullable|date_format:Y-m-d']);
        $uid = (int) $d['user_id'];
        $this->hrNeedPerson($request, $aid, $uid);
        abort_if($uid === (int) $request->user()->id, 403, 'Someone else has to review you.');
        abort_if((int) $d['manager_user_id'] === $uid, 422, 'Nobody reviews themselves. Choose someone else as the reviewer.');
        abort_unless(in_array((int) $d['manager_user_id'], HrTalent::managerIds($aid), true), 422, 'Choose a reviewer from your agency\'s directors and admins.');
        if (! empty($d['template_id'])) abort_unless(DB::table('hr_review_templates')->where('id', $d['template_id'])->where('agency_id', $aid)->whereNull('archived_at')->exists(), 422, 'Choose one of your templates.');
        if (! empty($d['period_start']) && ! empty($d['period_end'])) abort_if($d['period_end'] < $d['period_start'], 422, 'The period ends before it starts.');
        $tpl = HrTalent::template($aid, $d['template_id'] ?? null);
        $title = trim((string) ($d['title'] ?? '')) ?: (HrTalent::CYCLES[$d['cycle']] . ' review ' . substr(AgencyTime::today($aid), 0, 4));
        $id = (int) DB::table('hr_reviews')->insertGetId(['agency_id' => $aid, 'user_id' => $uid, 'manager_user_id' => (int) $d['manager_user_id'],
            'template_id' => $d['template_id'] ?? null, 'cycle' => $d['cycle'], 'title' => $title, 'period_start' => $d['period_start'] ?? null,
            'period_end' => $d['period_end'] ?? null, 'due_date' => $d['due_date'] ?? null, 'scale' => json_encode($tpl['scale'], JSON_UNESCAPED_UNICODE),
            'sections' => json_encode($tpl['sections'], JSON_UNESCAPED_UNICODE), 'status' => 'open', 'created_by_id' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now()]);
        $this->bell($aid, [$uid], 'Your ' . strtolower(HrTalent::CYCLES[$d['cycle']]) . ' review has started', 'Add your self review under My HR.' . ($d['due_date'] ?? null ? ' Due ' . $this->day($d['due_date']) . '.' : ''), '#my-hr');
        if ((int) $d['manager_user_id'] !== (int) $request->user()->id) {
            $this->bell($aid, [(int) $d['manager_user_id']], 'You are reviewing ' . ($this->hrNames([$uid])[$uid] ?? 'a team member'), $title, '#hr?tab=performance');
        }
        $this->hrNote($aid, 'hr.review_created', 'hr_review', $id, ['user_id' => $uid, 'cycle' => $d['cycle']]);

        return response()->json(['id' => $id], 201);
    }

    private function day(?string $ymd): string
    {
        return $ymd ? \Illuminate\Support\Carbon::parse($ymd)->format('M j') : '';
    }

    private function bell(int $aid, array $to, string $title, string $body, string $link): void
    {
        $rows = [];
        foreach (array_unique($to) as $u) {
            if ($u > 0) $rows[] = ['user_id' => $u, 'agency_id' => $aid, 'type' => 'hr', 'title' => $title, 'body' => $body, 'data' => json_encode(['link' => $link, 'kind' => str_starts_with($link, '#hr?') ? 'team' : 'own']), 'created_at' => now()];
        }
        if ($rows) {
            try { Notify::write($rows); } catch (\Throwable $e) { report($e); }
        }
    }

    private function ownReview(Request $request, int $aid, int $id): object
    {
        $r = DB::table('hr_reviews')->where('id', $id)->where('agency_id', $aid)->first();
        $me = (int) $request->user()->id;
        abort_unless($r && ($me === (int) $r->manager_user_id || $this->hrCanSee($request, $aid, (int) $r->user_id)), 404, 'That review was not found.');

        return $r;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->ownReview($request, $aid, $id);
        $me = (int) $request->user()->id;
        $isEmp = $me === (int) $r->user_id;
        $isMgr = $me === (int) $r->manager_user_id;
        $names = $this->hrNames([(int) $r->user_id, (int) $r->manager_user_id]);
        // The employee reads the manager's part once it is submitted; the reviewer reads the self review once it is submitted.
        $self = ($isEmp || $r->self_submitted_at || (! $isMgr && ! $isEmp)) ? json_decode((string) $r->self_answers, true) : null;
        $mgr = (! $isEmp || $r->manager_submitted_at) ? json_decode((string) $r->manager_answers, true) : null;
        $goals = DB::table('hr_goals')->where('agency_id', $aid)->where('review_id', $id)->orderBy('id')->get();

        return response()->json(['review' => ['id' => (int) $r->id, 'user_id' => (int) $r->user_id, 'name' => $names[(int) $r->user_id] ?? '',
            'manager_user_id' => (int) $r->manager_user_id, 'manager' => $names[(int) $r->manager_user_id] ?? '', 'title' => $r->title, 'cycle' => $r->cycle,
            'period_start' => $r->period_start, 'period_end' => $r->period_end, 'due_date' => $r->due_date ? substr((string) $r->due_date, 0, 10) : null,
            'scale' => json_decode((string) $r->scale, true) ?: [], 'sections' => json_decode((string) $r->sections, true) ?: [],
            'self' => $self, 'manager_part' => $mgr, 'self_submitted_at' => $r->self_submitted_at, 'manager_submitted_at' => $r->manager_submitted_at,
            'employee_signed_at' => $r->employee_signed_at, 'employee_signed_name' => $r->employee_signed_name,
            'manager_signed_at' => $r->manager_signed_at, 'manager_signed_name' => $r->manager_signed_name, 'status' => HrTalent::reviewStatus($r)],
            'goals' => $this->shapeGoals($aid, $goals),
            'can' => ['self' => $isEmp && ! $r->self_submitted_at && $r->status !== 'cancelled', 'manager' => $isMgr && ! $isEmp && ! $r->manager_submitted_at && $r->status !== 'cancelled',
                'sign' => ($isEmp || $isMgr) && $r->self_submitted_at && $r->manager_submitted_at && ! ($isEmp ? $r->employee_signed_at : $r->manager_signed_at),
                'cancel' => ! $isEmp && in_array((int) $r->user_id, $this->hrPeople($request, $aid), true) && ! ($r->employee_signed_at && $r->manager_signed_at),
                'goals' => true, 'ai' => self::aiOn($aid)],
            'goal_status' => HrTalent::GOAL_STATUS, 'me' => $me]);
    }

    private function answers(Request $request, array $sections): array
    {
        $d = $request->validate(['answers' => 'present|array', 'answers.sections' => 'nullable|array', 'answers.sections.*.rating' => 'nullable|integer|min:1|max:10',
            'answers.sections.*.comment' => 'nullable|string|max:4000', 'answers.overall' => 'nullable|string|max:6000', 'submit' => 'nullable|boolean']);
        $out = ['sections' => [], 'overall' => trim((string) ($d['answers']['overall'] ?? ''))];
        foreach ($sections as $i => $s) {
            $a = $d['answers']['sections'][$i] ?? [];
            $out['sections'][] = ['rating' => isset($a['rating']) ? (int) $a['rating'] : null, 'comment' => trim((string) ($a['comment'] ?? ''))];
        }

        return [$out, (bool) ($d['submit'] ?? false)];
    }

    /** PUT hr/reviews/{id}/self — the employee's own part (draft or submit). */
    public function saveSelf(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->ownReview($request, $aid, $id);
        abort_unless((int) $r->user_id === (int) $request->user()->id, 403, 'Only the person being reviewed writes the self review.');
        abort_if($r->self_submitted_at || $r->status === 'cancelled', 422, 'Your self review has already been submitted.');
        [$ans, $submit] = $this->answers($request, json_decode((string) $r->sections, true) ?: []);
        DB::table('hr_reviews')->where('id', $id)->update(['self_answers' => json_encode($ans, JSON_UNESCAPED_UNICODE), 'self_submitted_at' => $submit ? now() : null,
            'updated_at' => now()]);
        $this->syncStatus($id);
        if ($submit) $this->bell($aid, [(int) $r->manager_user_id], 'Self review submitted', ($this->hrNames([(int) $r->user_id])[(int) $r->user_id] ?? '') . ' · ' . $r->title, '#hr?tab=performance');
        $this->hrNote($aid, $submit ? 'hr.review_self_submitted' : 'hr.review_self_saved', 'hr_review', $id);

        return response()->json(['ok' => true]);
    }

    /** PUT hr/reviews/{id}/manager — the reviewer's part (draft or submit). Never the employee. */
    public function saveManager(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->ownReview($request, $aid, $id);
        $me = (int) $request->user()->id;
        abort_unless($me === (int) $r->manager_user_id && $me !== (int) $r->user_id, 403, 'Only the reviewer writes the manager review.');
        abort_if($r->manager_submitted_at || $r->status === 'cancelled', 422, 'The manager review has already been submitted.');
        [$ans, $submit] = $this->answers($request, json_decode((string) $r->sections, true) ?: []);
        DB::table('hr_reviews')->where('id', $id)->update(['manager_answers' => json_encode($ans, JSON_UNESCAPED_UNICODE), 'manager_submitted_at' => $submit ? now() : null,
            'updated_at' => now()]);
        $this->syncStatus($id);
        if ($submit) $this->bell($aid, [(int) $r->user_id], 'Your review is ready to read', $r->title . ': read it and sign it under My HR.', '#my-hr');
        $this->hrNote($aid, $submit ? 'hr.review_manager_submitted' : 'hr.review_manager_saved', 'hr_review', $id);

        return response()->json(['ok' => true]);
    }

    /** POST hr/reviews/{id}/sign {name} — both people sign once both parts are in. */
    public function sign(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->ownReview($request, $aid, $id);
        $me = (int) $request->user()->id;
        $isEmp = $me === (int) $r->user_id;
        abort_unless($isEmp || $me === (int) $r->manager_user_id, 403, 'Only the employee and the reviewer sign a review.');
        abort_unless($r->self_submitted_at && $r->manager_submitted_at, 422, 'Both parts have to be submitted before signing.');
        $name = trim((string) $request->validate(['name' => 'required|string|min:2|max:120'])['name']);
        $col = $isEmp ? 'employee' : 'manager';
        abort_if($r->{$col . '_signed_at'}, 422, 'You have already signed this review.');
        DB::table('hr_reviews')->where('id', $id)->update([$col . '_signed_at' => now(), $col . '_signed_name' => $name, 'updated_at' => now()]);
        $this->syncStatus($id);
        $fresh = DB::table('hr_reviews')->where('id', $id)->first();
        $this->bell($aid, [$isEmp ? (int) $r->manager_user_id : (int) $r->user_id], $fresh->employee_signed_at && $fresh->manager_signed_at ? 'Review signed by both of you' : 'Review signed: your signature is next',
            $r->title, $isEmp ? '#hr?tab=performance' : '#my-hr');
        $this->hrNote($aid, 'hr.review_signed', 'hr_review', $id, ['by' => $col]);

        return response()->json(['ok' => true, 'status' => HrTalent::reviewStatus($fresh)]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $r = $this->ownReview($request, $aid, $id);
        $this->hrNeedPerson($request, $aid, (int) $r->user_id);
        abort_if((int) $r->user_id === (int) $request->user()->id, 403, 'Someone else manages your own review.');
        DB::table('hr_reviews')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.review_cancelled', 'hr_review', $id);

        return response()->json(['ok' => true]);
    }

    private function syncStatus(int $id): void
    {
        $r = DB::table('hr_reviews')->where('id', $id)->first();
        DB::table('hr_reviews')->where('id', $id)->update(['status' => HrTalent::reviewStatus($r)]);
    }

    // ── goals + check-ins ─────────────────────────────────────────────────────────────────

    private function shapeGoals(int $aid, $goals): array
    {
        $checks = DB::table('hr_goal_checkins')->where('agency_id', $aid)->whereIn('goal_id', collect($goals)->pluck('id')->all() ?: [0])->orderByDesc('created_at')->get()->groupBy('goal_id');
        $by = $this->hrNames($checks->flatten()->pluck('author_id')->map(fn ($v) => (int) $v)->all());

        return collect($goals)->map(fn ($g) => ['id' => (int) $g->id, 'user_id' => (int) $g->user_id, 'review_id' => $g->review_id ? (int) $g->review_id : null,
            'title' => $g->title, 'detail' => $g->detail, 'due_date' => $g->due_date ? substr((string) $g->due_date, 0, 10) : null, 'status' => $g->status,
            'progress' => (int) $g->progress, 'checkins' => ($checks[$g->id] ?? collect())->map(fn ($c) => ['note' => $c->note, 'status' => $c->status,
                'progress' => (int) $c->progress, 'at' => $c->created_at, 'by' => $by[(int) $c->author_id] ?? null])->values()->all()])->values()->all();
    }

    /** GET hr/goals?user_id= */
    public function goals(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $uid = (int) $request->query('user_id', $request->user()->id);
        $this->hrNeedSee($request, $aid, $uid);

        return response()->json(['goals' => $this->shapeGoals($aid, DB::table('hr_goals')->where('agency_id', $aid)->where('user_id', $uid)
            ->orderByRaw("status IN ('done','dropped')")->orderBy('due_date')->get()), 'goal_status' => HrTalent::GOAL_STATUS]);
    }

    public function createGoal(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate(['user_id' => 'required|integer', 'title' => 'required|string|max:200', 'detail' => 'nullable|string|max:3000',
            'due_date' => 'nullable|date_format:Y-m-d', 'review_id' => 'nullable|integer']);
        $uid = (int) $d['user_id'];
        $this->hrNeedSee($request, $aid, $uid);
        if (! empty($d['review_id'])) abort_unless(DB::table('hr_reviews')->where('id', $d['review_id'])->where('agency_id', $aid)->where('user_id', $uid)->exists(), 422, 'That review was not found.');
        $id = (int) DB::table('hr_goals')->insertGetId(['agency_id' => $aid, 'user_id' => $uid, 'review_id' => $d['review_id'] ?? null, 'title' => trim($d['title']),
            'detail' => $d['detail'] ?? null, 'due_date' => $d['due_date'] ?? null, 'status' => 'not_started', 'progress' => 0,
            'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.goal_created', 'hr_goal', $id, ['user_id' => $uid]);

        return response()->json(['id' => $id], 201);
    }

    private function ownGoal(Request $request, int $aid, int $id): object
    {
        $g = DB::table('hr_goals')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($g && $this->hrCanSee($request, $aid, (int) $g->user_id), 404, 'That goal was not found.');

        return $g;
    }

    public function updateGoal(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->ownGoal($request, $aid, $id);
        $d = $request->validate(['title' => 'required|string|max:200', 'detail' => 'nullable|string|max:3000', 'due_date' => 'nullable|date_format:Y-m-d',
            'status' => 'required|in:' . implode(',', array_keys(HrTalent::GOAL_STATUS))]);
        DB::table('hr_goals')->where('id', $id)->update(['title' => trim($d['title']), 'detail' => $d['detail'] ?? null, 'due_date' => $d['due_date'] ?? null,
            'status' => $d['status'], 'progress' => $d['status'] === 'done' ? 100 : DB::raw('progress'), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.goal_updated', 'hr_goal', $id);

        return response()->json(['ok' => true]);
    }

    public function checkin(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $g = $this->ownGoal($request, $aid, $id);
        $d = $request->validate(['note' => 'required|string|min:2|max:3000', 'status' => 'required|in:' . implode(',', array_keys(HrTalent::GOAL_STATUS)),
            'progress' => 'required|integer|min:0|max:100']);
        DB::table('hr_goal_checkins')->insert(['agency_id' => $aid, 'goal_id' => $id, 'author_id' => $request->user()->id, 'note' => trim($d['note']),
            'status' => $d['status'], 'progress' => (int) $d['progress'], 'created_at' => now()]);
        DB::table('hr_goals')->where('id', $id)->update(['status' => $d['status'], 'progress' => $d['status'] === 'done' ? 100 : (int) $d['progress'], 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.goal_checkin', 'hr_goal', $id, ['status' => $d['status']]);

        return response()->json(['ok' => true]);
    }

    // ── one-to-ones (private to the two people, their admins and their centre's directors) ─────

    public function oneOnOnes(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $uid = (int) $request->query('user_id', $request->user()->id);
        $this->hrNeedSee($request, $aid, $uid);
        $rows = DB::table('hr_one_on_ones')->where('agency_id', $aid)->where('user_id', $uid)->orderByDesc('met_on')->orderByDesc('id')->limit(100)->get();
        $names = $this->hrNames(array_merge($rows->pluck('author_id')->all(), $rows->pluck('manager_user_id')->all()));

        return response()->json(['notes' => $rows->map(fn ($n) => ['id' => (int) $n->id, 'met_on' => substr((string) $n->met_on, 0, 10), 'notes' => $n->notes,
            'next_steps' => $n->next_steps, 'author' => $names[(int) $n->author_id] ?? null, 'with' => $names[(int) $n->manager_user_id] ?? null,
            'mine' => (int) $n->author_id === (int) $request->user()->id])->values()]);
    }

    public function saveOneOnOne(Request $request, ?int $id = null): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate(['user_id' => 'required|integer', 'met_on' => 'required|date_format:Y-m-d', 'notes' => 'required|string|min:2|max:8000',
            'next_steps' => 'nullable|string|max:3000']);
        $uid = (int) $d['user_id'];
        $me = (int) $request->user()->id;
        $this->hrNeedSee($request, $aid, $uid);
        if ($id) {
            $n = DB::table('hr_one_on_ones')->where('id', $id)->where('agency_id', $aid)->where('user_id', $uid)->first();
            abort_unless($n, 404, 'That note was not found.');
            abort_unless((int) $n->author_id === $me, 403, 'Only the person who wrote a note can change it.');
            DB::table('hr_one_on_ones')->where('id', $id)->update(['met_on' => $d['met_on'], 'notes' => trim($d['notes']), 'next_steps' => $d['next_steps'] ?? null, 'updated_at' => now()]);
        } else {
            $mgr = $uid === $me ? ((int) DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->value('manager_user_id') ?: $me) : $me;
            $id = (int) DB::table('hr_one_on_ones')->insertGetId(['agency_id' => $aid, 'user_id' => $uid, 'manager_user_id' => $mgr, 'met_on' => $d['met_on'],
                'notes' => trim($d['notes']), 'next_steps' => $d['next_steps'] ?? null, 'author_id' => $me, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->hrNote($aid, 'hr.one_on_one_saved', 'hr_one_on_one', $id, ['user_id' => $uid]);

        return response()->json(['id' => $id], 201);
    }

    public function updateOneOnOne(Request $request, int $id): JsonResponse
    {
        return $this->saveOneOnOne($request, $id);
    }

    // ── AI drafting (AI Bundle only) ──────────────────────────────────────────────────────

    /** AI drafting is offered: the AI Bundle is on and AI is not switched off for the agency (test agencies never use AI). */
    private static function aiOn(int $aid): bool
    {
        return AiBundle::on($aid) && ! \App\Support\AiConfig::offFor($aid);
    }

    /** POST hr/ai/draft {user_id, kind: goals|review, review_id?, part?: self|manager} — a draft to edit, never saved by itself. */
    public function draft(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        abort_unless(AiBundle::on($aid), 403, 'AI drafting is part of the AI Bundle.');
        abort_if(\App\Support\AiConfig::offFor($aid), 403, 'AI is switched off for this agency.');
        $d = $request->validate(['user_id' => 'required|integer', 'kind' => 'required|in:goals,review', 'review_id' => 'nullable|integer', 'part' => 'nullable|in:self,manager']);
        $uid = (int) $d['user_id'];
        $me = (int) $request->user()->id;
        $this->hrNeedSee($request, $aid, $uid);
        $review = null;
        if ($d['kind'] === 'review') {
            $review = DB::table('hr_reviews')->where('id', (int) ($d['review_id'] ?? 0))->where('agency_id', $aid)->where('user_id', $uid)->first();
            abort_unless($review, 404, 'That review was not found.');
            $part = $d['part'] ?? ($me === $uid ? 'self' : 'manager');
            abort_unless(($part === 'self' && $me === $uid) || ($part === 'manager' && $me === (int) $review->manager_user_id && $me !== $uid), 403, 'You can only draft your own part.');
        }
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        abort_unless($key, 503, 'AI drafting is not available right now.');
        $facts = $this->facts($aid, $uid);
        $first = (string) DB::table('users')->where('id', $uid)->value('first_name');
        if ($d['kind'] === 'goals') {
            $task = 'Suggest 3 professional development goals for ' . $first . ', an employee of a licensed childcare centre, based ONLY on the records below. '
                . 'Each goal on its own line, starting with "- ", specific and achievable in 3 to 6 months, in plain kind language. No preamble.';
            $kind = 'hr_goals';
        } else {
            $sections = json_decode((string) $review->sections, true) ?: [];
            $who = ($part ?? 'manager') === 'self' ? 'the employee, in the first person ("I...")' : 'their manager, speaking to ' . $first . ' ("You...")';
            $task = 'Draft short review comments for a childcare employee\'s ' . strtolower(HrTalent::CYCLES[$review->cycle] ?? '') . ' review, written by ' . $who . ', '
                . 'one paragraph of 2 to 3 sentences for each section below, each starting with the section title and a colon. Use ONLY the records below; '
                . 'where the records say nothing about a section, say so briefly and invite the writer to add their own observation. Never invent incidents, numbers or praise.'
                . "\nSections:\n" . implode("\n", array_map(fn ($s) => '- ' . $s['title'] . ($s['prompt'] ? ' (' . $s['prompt'] . ')' : ''), $sections));
            $kind = 'hr_review';
        }
        $rules = 'You help a childcare agency\'s HR. Use only the facts given. Never invent facts, names, numbers or events; never add placeholders like [Name]. '
            . 'Never mention health, family status, age or anything personal beyond work. Reply with ONLY the text.';
        UsageMeter::forAgency($aid);
        try {
            \App\Support\AiFresh::as($kind, $aid);
            $r = Http::timeout(40)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', ['model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001', 'max_tokens' => 900,
                    'system' => $rules, 'messages' => [['role' => 'user', 'content' => $task . "\n\n---\nRecords:\n" . $facts]]]);
        } catch (\Throwable $e) {
            Log::warning('HR AI draft: no answer', ['agency' => $aid, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'The AI did not answer just now. Please try again in a moment.'], 503);
        }
        if (! $r->successful()) {
            return response()->json(['message' => 'AI drafting is not available right now. Please try again later.'], 503);
        }
        $text = trim((string) (\App\Support\AiConfig::text($r->json()) ?? ''));
        abort_if($text === '', 422, 'The AI did not suggest anything.');
        $this->hrNote($aid, 'hr.ai_draft', 'user', $uid, ['kind' => $d['kind']]);

        return response()->json(['text' => $text, 'kind' => $d['kind']]);
    }

    /** The person's own work records, as plain lines (no SIN, no pay, no health, no leave reasons). */
    private function facts(int $aid, int $uid): string
    {
        $today = AgencyTime::today($aid);
        $f = HrLeave::file($aid, $uid);
        $u = DB::table('users')->where('id', $uid)->first(['first_name', 'hire_date']);
        $lines = ['Role: ' . ($f->job_title ?? 'staff member') . (! empty($f->employment_type) ? ' (' . (HrLeave::EMPLOYMENT[$f->employment_type] ?? '') . ')' : '')];
        if ($u && $u->hire_date) $lines[] = 'Years of service: ' . HrLeave::serviceYears(substr((string) $u->hire_date, 0, 10), $today);
        foreach (DB::table('staff_certifications')->where('user_id', $uid)->where('active', 1)->get(['title', 'cert_type', 'expires_at']) as $c) {
            $lines[] = 'Certificate: ' . ($c->title ?: $c->cert_type) . ($c->expires_at ? (substr((string) $c->expires_at, 0, 10) < $today ? ' (expired)' : ' (current)') : '');
        }
        $pd = (float) DB::table('pd_hours')->where('agency_id', $aid)->where('user_id', $uid)->whereNull('deleted_at')->where('status', 'approved')
            ->where('taken_on', '>=', substr($today, 0, 4) . '-01-01')->sum('hours');
        $lines[] = 'Professional development hours this year: ' . round($pd, 1);
        foreach (DB::table('hr_course_assignments as a')->join('hr_courses as c', 'c.id', '=', 'a.course_id')->where('a.agency_id', $aid)->where('a.user_id', $uid)->get(['c.title', 'a.completed_at', 'a.due_date']) as $c) {
            $lines[] = 'Course: ' . $c->title . ($c->completed_at ? ' (completed)' : ($c->due_date && substr((string) $c->due_date, 0, 10) < $today ? ' (overdue)' : ' (in progress)'));
        }
        foreach (DB::table('hr_goals')->where('agency_id', $aid)->where('user_id', $uid)->orderByDesc('created_at')->limit(8)->get(['title', 'status']) as $g) {
            $lines[] = 'Goal: ' . $g->title . ' (' . (HrTalent::GOAL_STATUS[$g->status] ?? $g->status) . ')';
        }
        $acks = DB::table('policy_acknowledgements')->where('agency_id', $aid)->where('user_id', $uid)->distinct()->count('policy_id');
        $lines[] = 'Policies acknowledged: ' . $acks;
        $col = collect(['educator_id', 'author_id', 'created_by_id', 'user_id'])->first(fn ($c) => \Illuminate\Support\Facades\Schema::hasColumn('observations', $c));
        $obs = $col ? (int) rescue(fn () => DB::table('observations')->where($col, $uid)->where('created_at', '>=', now()->subMonths(6))->count(), 0, false) : 0;
        if ($obs) $lines[] = 'Learning observations written in the last 6 months: ' . $obs;

        return implode("\n", $lines);
    }
}
