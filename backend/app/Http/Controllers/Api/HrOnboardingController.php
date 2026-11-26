<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Jurisdictions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KIDDIETRAC HR, PHASE 2 — ONBOARDING CHECKLISTS (2026-11-28).
 * Templates per role (agency admins): tasks of five kinds, each due N days after the start date. Starting onboarding
 * copies the template into the person's own checklist (later template edits never change a started one) and
 * assigns its courses. Policy, certificate and course tasks tick themselves off from the real record (HrTalent::sync);
 * a document task by its upload; a custom task by the person or a manager. Managers may mark any task done.
 * The person sees their own checklist under My HR; managers see the people they manage (HrAccess).
 */
final class HrOnboardingController extends Controller
{
    use HrAccess;

    private const FILE_RULE = ['required', 'file', 'mimes:pdf,doc,docx,png,jpg,jpeg,heic,webp', 'max:12288'];

    private function choices(int $aid): array
    {
        $certs = [];
        foreach ((array) (Jurisdictions::forAgency($aid)['certifications'] ?? []) as $c) {
            $certs[] = ['code' => $c['code'], 'label' => $c['label'] ?? ($c['name'] ?? $c['code'])];
        }
        if (! $certs) {
            foreach (Jurisdictions::certificationCodes($aid) as $c) $certs[] = ['code' => $c, 'label' => str_replace('_', ' ', $c)];
        }

        return [
            'kinds' => HrTalent::KINDS, 'roles' => HrTalent::ROLES, 'certificates' => $certs,
            'policies' => DB::table('policies')->where('agency_id', $aid)->where(fn ($q) => $q->where('archived', 0)->orWhereNull('archived'))
                ->orderBy('title')->get(['id', 'title'])->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title])->all(),
            'courses' => DB::table('hr_courses')->where('agency_id', $aid)->whereNull('archived_at')->orderBy('title')->get(['id', 'title'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'title' => $c->title])->all(),
        ];
    }

    private function templates(int $aid): array
    {
        $tasks = DB::table('hr_onboarding_template_tasks')->where('agency_id', $aid)->orderBy('sort')->orderBy('id')->get()->groupBy('template_id');

        return DB::table('hr_onboarding_templates')->where('agency_id', $aid)->orderByRaw('archived_at IS NOT NULL')->orderBy('name')->get()
            ->map(fn ($t) => ['id' => (int) $t->id, 'name' => $t->name, 'role' => $t->role, 'archived' => (bool) $t->archived_at,
                'tasks' => ($tasks[$t->id] ?? collect())->map(fn ($x) => ['kind' => $x->kind, 'title' => $x->title, 'detail' => $x->detail,
                    'ref_id' => $x->ref_id ? (int) $x->ref_id : null, 'ref_key' => $x->ref_key, 'due_days' => (int) $x->due_days])->values()->all()])->all();
    }

    /** GET hr/onboarding — the checklists of the people this caller manages, the templates and the task choices. */
    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $ids = $this->hrPeople($request, $aid);
        foreach (DB::table('hr_onboardings')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->where('status', 'active')->distinct()->pluck('user_id') as $u) {
            HrTalent::sync($aid, (int) $u);
        }
        $rows = DB::table('hr_onboardings')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->where('status', '!=', 'cancelled')
            ->orderByRaw("status = 'active' DESC")->orderByDesc('start_date')->limit(300)->get();
        $names = $this->hrNames($rows->pluck('user_id')->map(fn ($v) => (int) $v)->all());

        return response()->json([
            'onboardings' => $rows->map(fn ($o) => HrTalent::shapeOnboarding($o) + ['name' => $names[(int) $o->user_id] ?? ''])->values(),
            'templates' => $this->templates($aid), 'choices' => $this->choices($aid), 'can_templates' => $this->hrIsAdmin($request),
            'people' => DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'hire_date'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u), 'start_date' => $u->hire_date ? substr((string) $u->hire_date, 0, 10) : null])->values(),
        ]);
    }

    private function taskRules(): array
    {
        return ['tasks' => 'present|array|max:60', 'tasks.*.kind' => 'required|in:' . implode(',', array_keys(HrTalent::KINDS)),
            'tasks.*.title' => 'required|string|max:160', 'tasks.*.detail' => 'nullable|string|max:2000', 'tasks.*.ref_id' => 'nullable|integer',
            'tasks.*.ref_key' => 'nullable|string|max:60', 'tasks.*.due_days' => 'required|integer|min:-30|max:365'];
    }

    /** A task's link must be this agency's own policy / course, or a known certificate type. */
    private function cleanTask(int $aid, array $t): array
    {
        $t['ref_id'] = $t['ref_id'] ?? null;
        $t['ref_key'] = $t['ref_key'] ?? null;
        if ($t['kind'] === 'policy') {
            abort_unless($t['ref_id'] && DB::table('policies')->where('id', $t['ref_id'])->where('agency_id', $aid)->exists(), 422, 'Choose the policy for "' . $t['title'] . '".');
            $t['ref_key'] = null;
        } elseif ($t['kind'] === 'course') {
            abort_unless($t['ref_id'] && DB::table('hr_courses')->where('id', $t['ref_id'])->where('agency_id', $aid)->exists(), 422, 'Choose the course for "' . $t['title'] . '".');
            $t['ref_key'] = null;
        } elseif ($t['kind'] === 'certificate') {
            abort_unless($t['ref_key'] && in_array($t['ref_key'], array_column($this->choices($aid)['certificates'], 'code'), true), 422, 'Choose the certificate for "' . $t['title'] . '".');
            $t['ref_id'] = null;
        } else {
            $t['ref_id'] = null;
            $t['ref_key'] = null;
        }

        return $t;
    }

    public function saveTemplate(Request $request, ?int $id = null): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['name' => 'required|string|max:120', 'role' => 'nullable|in:' . implode(',', array_keys(HrTalent::ROLES))] + $this->taskRules());
        if ($id) abort_unless(DB::table('hr_onboarding_templates')->where('id', $id)->where('agency_id', $aid)->exists(), 404, 'That checklist was not found.');
        $tasks = array_map(fn ($t) => $this->cleanTask($aid, $t), $d['tasks']);
        $id = (int) DB::transaction(function () use ($aid, $id, $d, $tasks, $request) {
            if ($id) {
                DB::table('hr_onboarding_templates')->where('id', $id)->update(['name' => trim($d['name']), 'role' => $d['role'] ?? null, 'updated_at' => now()]);
                DB::table('hr_onboarding_template_tasks')->where('template_id', $id)->delete();
            } else {
                $id = (int) DB::table('hr_onboarding_templates')->insertGetId(['agency_id' => $aid, 'name' => trim($d['name']), 'role' => $d['role'] ?? null,
                    'created_by_id' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach ($tasks as $i => $t) {
                DB::table('hr_onboarding_template_tasks')->insert(['agency_id' => $aid, 'template_id' => $id, 'kind' => $t['kind'], 'title' => trim($t['title']),
                    'detail' => $t['detail'] ?? null, 'ref_id' => $t['ref_id'], 'ref_key' => $t['ref_key'], 'due_days' => (int) $t['due_days'], 'sort' => $i,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            return $id;
        });
        $this->hrNote($aid, 'hr.onboarding_template_saved', 'hr_onboarding_template', $id, ['name' => $d['name'], 'tasks' => count($tasks)]);

        return response()->json(['id' => $id], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        return $this->saveTemplate($request, $id);
    }

    /** POST hr/onboarding-templates/{id}/archive and /restore */
    public function archiveTemplate(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        abort_unless(DB::table('hr_onboarding_templates')->where('id', $id)->where('agency_id', $aid)->exists(), 404, 'That checklist was not found.');
        $restore = str_ends_with($request->path(), '/restore');
        DB::table('hr_onboarding_templates')->where('id', $id)->update(['archived_at' => $restore ? null : now(), 'updated_at' => now()]);
        $this->hrNote($aid, $restore ? 'hr.onboarding_template_restored' : 'hr.onboarding_template_archived', 'hr_onboarding_template', $id);

        return response()->json(['ok' => true]);
    }

    /** POST hr/onboarding {user_id, template_id?, start_date} */
    public function start(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate(['user_id' => 'required|integer', 'template_id' => 'nullable|integer', 'start_date' => 'required|date_format:Y-m-d']);
        $uid = (int) $d['user_id'];
        $this->hrNeedPerson($request, $aid, $uid);
        abort_if($uid === (int) $request->user()->id, 403, 'Someone else has to start your own onboarding.');
        if (! empty($d['template_id'])) abort_unless(DB::table('hr_onboarding_templates')->where('id', $d['template_id'])->where('agency_id', $aid)->whereNull('archived_at')->exists(), 422, 'Choose one of your checklists.');
        abort_if(DB::table('hr_onboardings')->where('agency_id', $aid)->where('user_id', $uid)->where('status', 'active')->exists(), 422, 'This person already has an onboarding checklist in progress.');
        $oid = HrTalent::startOnboarding($aid, $uid, $d['template_id'] ?? null, $d['start_date'], (int) $request->user()->id);
        HrTalent::sync($aid, $uid);
        $this->hrNote($aid, 'hr.onboarding_started', 'user', $uid, ['onboarding_id' => $oid]);

        return response()->json(['id' => $oid], 201);
    }

    private function ownOnboarding(Request $request, int $aid, int $id): object
    {
        $o = DB::table('hr_onboardings')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($o && $this->hrCanSee($request, $aid, (int) $o->user_id), 404, 'That checklist was not found.');

        return $o;
    }

    private function isOwnerOnly(Request $request, int $aid, int $userId): bool
    {
        return $userId === (int) $request->user()->id;
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $o = $this->ownOnboarding($request, $aid, $id);
        HrTalent::sync($aid, (int) $o->user_id);
        $o = DB::table('hr_onboardings')->where('id', $id)->first();

        return response()->json(['onboarding' => HrTalent::shapeOnboarding($o) + ['name' => $this->hrNames([(int) $o->user_id])[(int) $o->user_id] ?? ''],
            'can_manage' => ! $this->isOwnerOnly($request, $aid, (int) $o->user_id) && in_array((int) $o->user_id, $this->hrPeople($request, $aid), true),
            'choices' => $this->choices($aid)]);
    }

    /** POST hr/onboarding/{id}/tasks — one more task on a started checklist (managers). */
    public function addTask(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $o = $this->ownOnboarding($request, $aid, $id);
        $this->hrNeedPerson($request, $aid, (int) $o->user_id);
        abort_if((int) $o->user_id === (int) $request->user()->id, 403, 'Someone else manages your own checklist.');
        $d = $request->validate(['kind' => 'required|in:' . implode(',', array_keys(HrTalent::KINDS)), 'title' => 'required|string|max:160',
            'detail' => 'nullable|string|max:2000', 'ref_id' => 'nullable|integer', 'ref_key' => 'nullable|string|max:60', 'due_date' => 'required|date_format:Y-m-d']);
        $t = $this->cleanTask($aid, $d);
        $t['due_days'] = (int) \Illuminate\Support\Carbon::parse(substr((string) $o->start_date, 0, 10))->diffInDays(\Illuminate\Support\Carbon::parse($d['due_date']), false);
        $sort = (int) DB::table('hr_onboarding_tasks')->where('onboarding_id', $id)->max('sort') + 1;
        $tid = HrTalent::addTask($aid, $id, (int) $o->user_id, $t, substr((string) $o->start_date, 0, 10), $sort, (int) $request->user()->id);
        if ($o->status === 'complete') DB::table('hr_onboardings')->where('id', $id)->update(['status' => 'active', 'completed_at' => null, 'updated_at' => now()]);
        HrTalent::sync($aid, (int) $o->user_id);
        $this->hrNote($aid, 'hr.onboarding_task_added', 'hr_onboarding', $id, ['title' => $d['title']]);

        return response()->json(['id' => $tid], 201);
    }

    private function ownTask(Request $request, int $aid, int $id): array
    {
        $t = DB::table('hr_onboarding_tasks')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($t, 404, 'That task was not found.');
        $o = $this->ownOnboarding($request, $aid, (int) $t->onboarding_id);
        $self = (int) $t->user_id === (int) $request->user()->id;
        $manager = ! $self && in_array((int) $t->user_id, $this->hrPeople($request, $aid), true);

        return [$t, $o, $self, $manager];
    }

    /** POST hr/onboarding-tasks/{id}/done — custom tasks by the person or a manager; any task by a manager. */
    public function done(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        [$t, $o, $self, $manager] = $this->ownTask($request, $aid, $id);
        abort_unless($o->status === 'active', 422, 'This checklist is closed.');
        abort_unless($manager || ($self && $t->kind === 'custom'), 403, $self ? 'This one ticks itself off when you finish it.' : 'Only a manager can tick this off.');
        DB::table('hr_onboarding_tasks')->where('id', $id)->whereNull('done_at')->update(['done_at' => now(), 'done_by_id' => $request->user()->id,
            'done_how' => $manager ? 'manager' : 'self', 'updated_at' => now()]);
        HrTalent::closeFinished($aid, (int) $t->user_id);
        $this->hrNote($aid, 'hr.onboarding_task_done', 'hr_onboarding_task', $id, ['title' => $t->title]);

        return response()->json(['ok' => true]);
    }

    /** POST hr/onboarding-tasks/{id}/undo — managers. */
    public function undo(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        [$t, $o, $self, $manager] = $this->ownTask($request, $aid, $id);
        abort_unless($manager, 403, 'Only a manager can do this.');
        DB::table('hr_onboarding_tasks')->where('id', $id)->update(['done_at' => null, 'done_by_id' => null, 'done_how' => null, 'updated_at' => now()]);
        if ($o->status === 'complete') DB::table('hr_onboardings')->where('id', $o->id)->update(['status' => 'active', 'completed_at' => null, 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.onboarding_task_reopened', 'hr_onboarding_task', $id, ['title' => $t->title]);

        return response()->json(['ok' => true]);
    }

    /** POST hr/onboarding-tasks/{id}/upload — the document for a document task (private storage). */
    public function upload(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        [$t, $o, $self, $manager] = $this->ownTask($request, $aid, $id);
        abort_unless($self || $manager, 403, 'Only this person or their manager can add the document.');
        abort_unless($t->kind === 'document', 422, 'This task does not take a document.');
        $request->validate(['file' => self::FILE_RULE], ['file.mimes' => 'Upload a PDF, Word document or photo.', 'file.max' => 'The file is larger than 12 MB.']);
        $f = $request->file('file');
        $ext = strtolower($f->getClientOriginalExtension() ?: 'pdf');
        $name = mb_substr(preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string) $f->getClientOriginalName()) ?: ('document.' . $ext), 0, 150);
        $path = Storage::disk('local')->putFileAs('hr-onboarding/' . $aid . '/' . $id, $f, 'document-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext);
        if ($t->file_path && $t->file_path !== $path) Storage::disk('local')->delete($t->file_path);
        DB::table('hr_onboarding_tasks')->where('id', $id)->update(['file_path' => $path, 'file_name' => $name, 'done_at' => $t->done_at ?: now(),
            'done_by_id' => $t->done_by_id ?: $request->user()->id, 'done_how' => $t->done_how ?: 'upload', 'updated_at' => now()]);
        HrTalent::closeFinished($aid, (int) $t->user_id);
        $this->hrNote($aid, 'hr.onboarding_document_uploaded', 'hr_onboarding_task', $id, ['title' => $t->title]);

        return response()->json(['ok' => true, 'file_name' => $name]);
    }

    public function file(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        [$t, $o, $self, $manager] = $this->ownTask($request, $aid, $id);
        abort_unless($self || $manager, 404, 'File not found.');
        abort_unless($t->file_path && Storage::disk('local')->exists($t->file_path), 404, 'File not found.');

        return \App\Support\FileResponse::download($t->file_path, $t->file_name ?: basename($t->file_path), [], 'local', (bool) $request->boolean('inline'));
    }

    /** POST hr/onboarding/{id}/cancel — managers. */
    public function cancel(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $o = $this->ownOnboarding($request, $aid, $id);
        $this->hrNeedPerson($request, $aid, (int) $o->user_id);
        abort_if((int) $o->user_id === (int) $request->user()->id, 403, 'Someone else manages your own checklist.');
        DB::table('hr_onboardings')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.onboarding_cancelled', 'user', (int) $o->user_id, ['onboarding_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** GET hr/people/{id}/talent — the Phase 2 cards on the HR profile (onboarding, reviews, goals, courses). */
    public function profileCards(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedPerson($request, $aid, $id);

        return response()->json(self::summaryFor($aid, $id) + ['can_manage' => $id !== (int) $request->user()->id]);
    }

    /** GET hr/me/talent — My HR: my onboarding, reviews, goals, one-to-ones and courses. */
    public function mine(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $me = (int) $request->user()->id;
        abort_unless(in_array($me, HrLeave::staffIds($aid), true), 404, 'Your HR records are kept by your agency.');

        return response()->json(self::summaryFor($aid, $me) + ['me' => $me]);
    }

    public static function summaryFor(int $aid, int $uid): array
    {
        HrTalent::sync($aid, $uid);
        $onb = DB::table('hr_onboardings')->where('agency_id', $aid)->where('user_id', $uid)->where('status', '!=', 'cancelled')->orderByDesc('start_date')->get();
        $reviews = DB::table('hr_reviews')->where('agency_id', $aid)->where('user_id', $uid)->where('status', '!=', 'cancelled')->orderByDesc('created_at')->get();
        $mgrNames = DB::table('users')->whereIn('id', $reviews->pluck('manager_user_id')->all() ?: [0])->pluck('first_name', 'id');
        $goals = DB::table('hr_goals')->where('agency_id', $aid)->where('user_id', $uid)->orderByRaw("status IN ('done','dropped')")->orderBy('due_date')->get();
        $courses = DB::table('hr_course_assignments as a')->join('hr_courses as c', 'c.id', '=', 'a.course_id')->where('a.agency_id', $aid)->where('a.user_id', $uid)
            ->orderByRaw('a.completed_at IS NOT NULL')->orderBy('a.due_date')->get(['a.*', 'c.title', 'c.content_kind', 'c.pd_hours', 'c.counts_pd', 'c.needs_ack']);
        $today = AgencyTime::today($aid);

        return [
            'onboarding' => $onb->map(fn ($o) => HrTalent::shapeOnboarding($o))->values(),
            'reviews' => $reviews->map(fn ($r) => ['id' => (int) $r->id, 'title' => $r->title, 'cycle' => $r->cycle, 'due_date' => $r->due_date ? substr((string) $r->due_date, 0, 10) : null,
                'status' => HrTalent::reviewStatus($r), 'manager' => $mgrNames[$r->manager_user_id] ?? null, 'manager_user_id' => (int) $r->manager_user_id])->values(),
            'goals' => $goals->map(fn ($g) => ['id' => (int) $g->id, 'title' => $g->title, 'status' => $g->status, 'progress' => (int) $g->progress,
                'due_date' => $g->due_date ? substr((string) $g->due_date, 0, 10) : null, 'review_id' => $g->review_id ? (int) $g->review_id : null])->values(),
            'courses' => $courses->map(fn ($c) => ['id' => (int) $c->id, 'course_id' => (int) $c->course_id, 'title' => $c->title, 'kind' => $c->content_kind,
                'due_date' => $c->due_date ? substr((string) $c->due_date, 0, 10) : null, 'completed_at' => $c->completed_at, 'certificate_no' => $c->certificate_no,
                'overdue' => ! $c->completed_at && $c->due_date && substr((string) $c->due_date, 0, 10) < $today,
                'pd_hours' => $c->counts_pd ? (float) $c->pd_hours : null, 'needs_ack' => (bool) $c->needs_ack])->values(),
            'stages' => ['goal_status' => HrTalent::GOAL_STATUS, 'cycles' => HrTalent::CYCLES],
        ];
    }
}
