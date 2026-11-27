<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencySignatories;
use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Notify;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * KIDDIETRAC HR, PHASE 2 — COURSE ASSIGNMENTS (2026-11-28). Agency admins add courses (a link, a video link, an
 * uploaded file or one of KiddieTrac's training videos); admins and directors assign them to people or to a role
 * (the role's current staff in their scope) with a due date. The person opens the course, marks it complete and,
 * when the course asks for it, types their name to acknowledge it. Completion issues a certificate (PDF, the house
 * PDF style) and, when the course counts toward PD hours, an approved pd_hours row. Reminders: hr:talent-remind.
 *
 * iLEARN: an iLearn course would be a fifth content_kind ('ilearn', the iLearn course id in url) whose completion
 * arrives from iLearn instead of the self-mark; plug it in at COURSE_KINDS + open() + completeFromProvider(). Not built.
 */
final class HrCoursesController extends Controller
{
    use HrAccess;

    private function shape(object $c, array $counts = []): array
    {
        return ['id' => (int) $c->id, 'title' => $c->title, 'description' => $c->description, 'content_kind' => $c->content_kind, 'url' => $c->url,
            'file_name' => $c->file_name, 'training_video_id' => $c->training_video_id ? (int) $c->training_video_id : null,
            'pd_hours' => $c->pd_hours !== null ? (float) $c->pd_hours : null, 'counts_pd' => (bool) $c->counts_pd, 'needs_ack' => (bool) $c->needs_ack,
            'archived' => (bool) $c->archived_at, 'assigned' => (int) ($counts[$c->id]['n'] ?? 0), 'completed' => (int) ($counts[$c->id]['done'] ?? 0),
            'overdue' => (int) ($counts[$c->id]['late'] ?? 0),
            // HR Phase 4 batch 6: the course's quiz (HrQuiz) — questions, pass mark, tries.
            'quiz_questions' => (int) ($counts[$c->id]['quiz'] ?? 0), 'pass_mark' => isset($c->pass_mark) && $c->pass_mark !== null ? (int) $c->pass_mark : null,
            'max_attempts' => isset($c->max_attempts) && $c->max_attempts !== null ? (int) $c->max_attempts : null];
    }

    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $ids = $this->hrPeople($request, $aid);
        $today = AgencyTime::today($aid);
        $counts = [];
        foreach (DB::table('hr_course_assignments')->where('agency_id', $aid)->whereIn('user_id', $ids ?: [0])->get(['course_id', 'completed_at', 'due_date']) as $a) {
            $c = &$counts[$a->course_id];
            $c['n'] = ($c['n'] ?? 0) + 1;
            $c['done'] = ($c['done'] ?? 0) + ($a->completed_at ? 1 : 0);
            $c['late'] = ($c['late'] ?? 0) + (! $a->completed_at && $a->due_date && substr((string) $a->due_date, 0, 10) < $today ? 1 : 0);
            unset($c);
        }
        foreach (\App\Support\HrQuiz::counts(DB::table('hr_courses')->where('agency_id', $aid)->pluck('id')->map(fn ($v) => (int) $v)->all()) as $cid => $n) {
            $counts[$cid]['quiz'] = $n;
        }
        $roles =DB::table('role_assignments')->whereIn('user_id', $ids ?: [0])->where('active', 1)->whereIn('role', HrLeave::STAFF_ROLES)->get(['user_id', 'role'])->groupBy('user_id');

        return response()->json([
            'courses' => DB::table('hr_courses')->where('agency_id', $aid)->orderByRaw('archived_at IS NOT NULL')->orderBy('title')->get()->map(fn ($c) => $this->shape($c, $counts))->values(),
            'kinds' => HrTalent::COURSE_KINDS, 'roles' => HrTalent::ROLES, 'can_manage' => $this->hrIsAdmin($request),
            'videos' => \Illuminate\Support\Facades\Schema::hasTable('training_videos') ? DB::table('training_videos')->where('status', 'published')->orderBy('audience')->orderBy('title')
                ->get(['id', 'title', 'audience'])->map(fn ($v) => ['id' => (int) $v->id, 'title' => $v->title . ' (' . ucfirst((string) $v->audience) . ')'])->values() : [],
            'people' => DB::table('users')->whereIn('id', $ids ?: [0])->orderBy('first_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $this->hrName($u), 'roles' => ($roles[$u->id] ?? collect())->pluck('role')->unique()->values()])->values(),
        ]);
    }

    private function rules(): array
    {
        return ['title' => 'required|string|max:160', 'description' => 'nullable|string|max:6000', 'content_kind' => 'required|in:' . implode(',', array_keys(HrTalent::COURSE_KINDS)),
            'url' => 'nullable|required_if:content_kind,link,video|url|max:500', 'training_video_id' => 'nullable|required_if:content_kind,library|integer',
            'pd_hours' => 'nullable|numeric|min:0.25|max:100', 'counts_pd' => 'nullable|boolean', 'needs_ack' => 'nullable|boolean'];
    }

    private function row(array $d): array
    {
        if ($d['content_kind'] === 'library') {
            abort_unless(DB::table('training_videos')->where('id', $d['training_video_id'])->where('status', 'published')->exists(), 422, 'Choose one of the training videos.');
        }
        abort_if(! empty($d['counts_pd']) && empty($d['pd_hours']), 422, 'Add how many PD hours the course counts for.');

        return ['title' => trim($d['title']), 'description' => isset($d['description']) ? trim($d['description']) : null, 'content_kind' => $d['content_kind'],
            'url' => in_array($d['content_kind'], ['link', 'video'], true) ? $d['url'] : null,
            'training_video_id' => $d['content_kind'] === 'library' ? (int) $d['training_video_id'] : null,
            'pd_hours' => $d['pd_hours'] ?? null, 'counts_pd' => (bool) ($d['counts_pd'] ?? false), 'needs_ack' => (bool) ($d['needs_ack'] ?? true), 'updated_at' => now()];
    }

    private function ownCourse(int $aid, int $id): object
    {
        $c = DB::table('hr_courses')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($c, 404, 'That course was not found.');

        return $c;
    }

    public function create(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $row = $this->row($request->validate($this->rules()));
        $id = (int) DB::table('hr_courses')->insertGetId($row + ['agency_id' => $aid, 'created_by_id' => $request->user()->id, 'created_at' => now()]);
        $this->hrNote($aid, 'hr.course_created', 'hr_course', $id, ['title' => $row['title']]);

        return response()->json(['id' => $id], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $c = $this->ownCourse($aid, $id);
        $row = $this->row($request->validate($this->rules()));
        if ($row['content_kind'] !== 'file' && $c->file_path) {
            Storage::disk('local')->delete($c->file_path);
            $row += ['file_path' => null, 'file_name' => null];
        }
        DB::table('hr_courses')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.course_updated', 'hr_course', $id, ['title' => $row['title']]);

        return response()->json(['ok' => true]);
    }

    /** POST hr/courses/{id}/file — the course's own file (private). */
    public function uploadFile(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $c = $this->ownCourse($aid, $id);
        $request->validate(['file' => 'required|file|mimes:pdf,doc,docx,ppt,pptx,mp4,m4v,mov,png,jpg,jpeg|max:51200'],
            ['file.mimes' => 'Upload a PDF, Word, PowerPoint, video or picture.', 'file.max' => 'The file is larger than 50 MB.']);
        $f = $request->file('file');
        $ext = strtolower($f->getClientOriginalExtension() ?: 'pdf');
        $name = mb_substr(preg_replace('/[^A-Za-z0-9 ._-]+/', '', (string) $f->getClientOriginalName()) ?: ('course.' . $ext), 0, 150);
        $path = Storage::disk('local')->putFileAs('hr-courses/' . $aid, $f, 'course-' . $id . '-' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext);
        if ($c->file_path) Storage::disk('local')->delete($c->file_path);
        DB::table('hr_courses')->where('id', $id)->update(['file_path' => $path, 'file_name' => $name, 'content_kind' => 'file', 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.course_file_uploaded', 'hr_course', $id);

        return response()->json(['ok' => true, 'file_name' => $name]);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $this->ownCourse($aid, $id);
        $restore = str_ends_with($request->path(), '/restore');
        DB::table('hr_courses')->where('id', $id)->update(['archived_at' => $restore ? null : now(), 'updated_at' => now()]);
        $this->hrNote($aid, $restore ? 'hr.course_restored' : 'hr.course_archived', 'hr_course', $id);

        return response()->json(['ok' => true]);
    }

    /** GET hr/courses/{id}/assignments — who has it, in this caller's scope. */
    public function assignments(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $c = $this->ownCourse($aid, $id);
        $ids = $this->hrPeople($request, $aid);
        $today = AgencyTime::today($aid);
        $rows = DB::table('hr_course_assignments as a')->join('users as u', 'u.id', '=', 'a.user_id')->where('a.agency_id', $aid)->where('a.course_id', $id)
            ->whereIn('a.user_id', $ids ?: [0])->orderByRaw('a.completed_at IS NOT NULL')->orderBy('a.due_date')->orderBy('u.first_name')
            ->get(['a.*', 'u.first_name', 'u.last_name']);
        $quiz = \App\Support\HrQuiz::has($c);
        $tries = $quiz ? \App\Support\HrQuiz::triesFor($rows->pluck('id')->map(fn ($v) => (int) $v)->all()) : [];
        $me = (int) $request->user()->id;

        return response()->json(['course' => $this->shape($c, [$c->id => ['quiz' => $quiz ? 1 : 0]]), 'rules' => $this->roleRules($request, $aid, $id), 'quiz' => $quiz,
            'assignments' => $rows->map(function ($a) use ($today, $quiz, $tries, $c, $me) {
                $t = $tries[(int) $a->id] ?? ['n' => 0, 'best' => null, 'passed' => false];
                $allowed = $quiz ? \App\Support\HrQuiz::allowed($c, $a) : null;

                return ['id' => (int) $a->id, 'user_id' => (int) $a->user_id,
                    'name' => $this->hrName($a), 'role' => $a->assigned_role, 'due_date' => $a->due_date ? substr((string) $a->due_date, 0, 10) : null,
                    'completed_at' => $a->completed_at, 'certificate_no' => $a->certificate_no,
                    'overdue' => ! $a->completed_at && $a->due_date && substr((string) $a->due_date, 0, 10) < $today,
                    'tries' => $quiz ? $t['n'] : null, 'best' => $quiz && $t['n'] ? $t['best'] : null, 'tries_allowed' => $allowed,
                    'out_of_tries' => $quiz && ! $a->completed_at && $allowed !== null && $t['n'] >= $allowed,
                    'can_retry' => $quiz && ! $a->completed_at && $allowed !== null && $t['n'] >= $allowed && (int) $a->user_id !== $me];
            })->values()]);
    }

    /**
     * The "goes to everyone in this role" rules on a course, as this caller sees them: an admin every rule; a director the
     * rules for their own centres (can stop them) and the agency-wide ones (read-only, CentreScope::canRead shared).
     */
    private function roleRules(Request $request, int $aid, int $courseId): array
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_course_role_rules')) return [];
        $sc = CentreScope::ids($request, $aid);
        $names = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $out = [];
        foreach (DB::table('hr_course_role_rules')->where('agency_id', $aid)->where('course_id', $courseId)->whereNull('archived_at')->orderBy('id')->get() as $r) {
            $cs = $r->centre_ids !== null ? array_map('intval', json_decode((string) $r->centre_ids, true) ?: []) : null;
            $mine = $sc === null || ($cs !== null && $cs && ! array_diff($cs, $sc));
            if (! $mine && ! ($cs === null || array_intersect($cs, $sc))) continue;
            $out[] = ['id' => (int) $r->id, 'role' => $r->role, 'role_label' => HrTalent::ROLES[$r->role] ?? $r->role, 'due_days' => $r->due_days !== null ? (int) $r->due_days : null,
                'centres' => $cs === null ? null : array_values(array_filter(array_map(fn ($c) => $names[$c] ?? null, $cs))), 'can_stop' => $mine];
        }

        return $out;
    }

    /** POST hr/course-role-rules/{id}/stop — new people in the role no longer get it (who has it keeps it). */
    public function stopRule(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $r = DB::table('hr_course_role_rules')->where('id', $id)->where('agency_id', $aid)->whereNull('archived_at')->first();
        abort_unless($r, 404, 'That rule was not found.');
        $sc = CentreScope::ids($request, $aid);
        $cs = $r->centre_ids !== null ? array_map('intval', json_decode((string) $r->centre_ids, true) ?: []) : null;
        abort_unless($sc === null || ($cs !== null && $cs && ! array_diff($cs, $sc)), 403, 'Only an agency admin can change this.');
        DB::table('hr_course_role_rules')->where('id', $id)->update(['archived_at' => now(), 'updated_at' => now()]);
        $this->hrNote($aid, 'hr.course_role_rule_stopped', 'hr_course', (int) $r->course_id, ['role' => $r->role, 'rule_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /**
     * POST hr/courses/{id}/assign {user_ids[], roles[], due_date} — roles = the role's current staff in this caller's scope,
     * AND a kept rule so anyone who joins the role later gets it too (HrTalent::syncRoleCourses), due as many days after
     * they get it as this due date is from today.
     */
    public function assign(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $c = $this->ownCourse($aid, $id);
        abort_if($c->archived_at, 422, 'This course is archived.');
        $d = $request->validate(['user_ids' => 'nullable|array|max:500', 'user_ids.*' => 'integer', 'roles' => 'nullable|array', 'roles.*' => 'in:' . implode(',', array_keys(HrTalent::ROLES)),
            'due_date' => 'nullable|date_format:Y-m-d']);
        $people = $this->hrPeople($request, $aid);
        $want = [];
        foreach ($d['user_ids'] ?? [] as $u) {
            abort_unless(in_array((int) $u, $people, true), 404, 'That person was not found.');
            $want[(int) $u] = null;
        }
        if (! empty($d['roles'])) {
            $sc = CentreScope::ids($request, $aid);
            foreach (DB::table('role_assignments')->whereIn('user_id', $people ?: [0])->where('active', 1)->whereIn('role', $d['roles'])
                ->when($sc !== null, fn ($q) => $q->whereIn('centre_id', $sc ?: [0]))->get(['user_id', 'role']) as $r) {
                $want[(int) $r->user_id] = $want[(int) $r->user_id] ?? $r->role;
            }
        }
        $want = array_diff_key($want, array_flip(AgencySignatories::outsiderIds()));
        if (! empty($d['roles'])) {
            $days = ! empty($d['due_date']) ? max(0, (int) \Illuminate\Support\Carbon::parse(AgencyTime::today($aid))->diffInDays(\Illuminate\Support\Carbon::parse($d['due_date']), false)) : null;
            foreach (array_unique($d['roles']) as $role) {
                HrTalent::saveRoleRule($aid, $id, $role, CentreScope::ids($request, $aid), $days, (int) $request->user()->id);
            }
        }
        abort_unless($want || ! empty($d['roles']), 422, 'Choose who should take this course.');
        $new = 0;
        foreach ($want as $u => $role) {
            if (HrTalent::assignCourse($aid, $id, $u, $d['due_date'] ?? null, (int) $request->user()->id, $role)) {
                $new++;
                if ($u !== (int) $request->user()->id) {
                    $this->bell($aid, $u, 'New course: ' . $c->title, ($d['due_date'] ?? null) ? 'Due ' . \Illuminate\Support\Carbon::parse($d['due_date'])->format('M j') . '. Open it under My HR.' : 'Open it under My HR.');
                }
            }
        }
        $this->hrNote($aid, 'hr.course_assigned', 'hr_course', $id, ['people' => count($want), 'new' => $new]);

        return response()->json(['assigned' => $new, 'already' => count($want) - $new]);
    }

    private function bell(int $aid, int $uid, string $title, string $body): void
    {
        try {
            Notify::write(['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr', 'title' => $title, 'body' => $body, 'data' => json_encode(['link' => '#my-hr']), 'created_at' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function ownAssignment(Request $request, int $aid, int $id): object
    {
        $a = DB::table('hr_course_assignments')->where('id', $id)->where('agency_id', $aid)->first();
        abort_unless($a && $this->hrCanSee($request, $aid, (int) $a->user_id), 404, 'That course assignment was not found.');

        return $a;
    }

    /** POST hr/course-assignments/remind {ids[]} — a bell reminder now (managers, people in scope, open ones). */
    public function remind(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedManager($request);
        $d = $request->validate(['ids' => 'required|array|max:500', 'ids.*' => 'integer']);
        $rows = DB::table('hr_course_assignments as a')->join('hr_courses as c', 'c.id', '=', 'a.course_id')->where('a.agency_id', $aid)->whereIn('a.id', $d['ids'])
            ->whereIn('a.user_id', $this->hrPeople($request, $aid) ?: [0])->whereNull('a.completed_at')->get(['a.id', 'a.user_id', 'a.due_date', 'c.title']);
        foreach ($rows as $a) {
            $this->bell($aid, (int) $a->user_id, 'Reminder: ' . $a->title, $a->due_date ? 'Due ' . \Illuminate\Support\Carbon::parse($a->due_date)->format('M j') . '.' : 'Please finish it when you can.');
        }
        $this->hrNote($aid, 'hr.course_reminders_sent', 'agency', $aid, ['count' => $rows->count()]);

        return response()->json(['reminded' => $rows->count()]);
    }

    public function unassign(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        $this->hrNeedPerson($request, $aid, (int) $a->user_id);
        abort_if($a->completed_at, 422, 'A completed course stays on the record.');
        DB::table('hr_course_assignments')->where('id', $id)->delete();
        // Removed by a manager: a role rule on this course must not give it back to them.
        if (\Illuminate\Support\Facades\Schema::hasTable('hr_course_role_rules')) {
            foreach (DB::table('hr_course_role_rules')->where('agency_id', $aid)->where('course_id', $a->course_id)->whereNull('archived_at')->get(['id', 'skip_user_ids']) as $r) {
                $skip = array_values(array_unique(array_merge(array_map('intval', json_decode((string) ($r->skip_user_ids ?? ''), true) ?: []), [(int) $a->user_id])));
                DB::table('hr_course_role_rules')->where('id', $r->id)->update(['skip_user_ids' => json_encode($skip), 'updated_at' => now()]);
            }
        }
        $this->hrNote($aid, 'hr.course_unassigned', 'hr_course', (int) $a->course_id, ['user_id' => (int) $a->user_id]);

        return response()->json(['ok' => true]);
    }

    /** GET hr/course-assignments/{id}/open — what to show: a link, a video, a file or a training video. */
    public function open(Request $request, int $id)
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        $c = $this->ownCourse($aid, (int) $a->course_id);
        if ($c->content_kind === 'file') {
            abort_unless($c->file_path && Storage::disk('local')->exists($c->file_path), 404, 'The course file is missing.');
            return \App\Support\FileResponse::download($c->file_path, $c->file_name ?: basename($c->file_path), [], 'local', true);
        }
        $v = $c->content_kind === 'library' ? DB::table('training_videos')->where('id', $c->training_video_id)->first(['title', 'video_url', 'captions_url', 'poster_url']) : null;

        return response()->json(['kind' => $c->content_kind, 'title' => $c->title, 'description' => $c->description, 'url' => $c->url,
            'video' => $v ? ['title' => $v->title, 'url' => $v->video_url, 'captions' => $v->captions_url, 'poster' => $v->poster_url] : null,
            // Signature stamp for the person's own acknowledgement (2026-11-27).
            'ack_name' => $a->completed_at ? $a->acknowledged_name : null,
            'ack_stamp' => $a->completed_at && $a->acknowledged_name
                ? (\App\Support\SignatureStamp::lines($a->completed_at, $a->acknowledged_ip ?? null, $a->acknowledged_location ?? null, $aid) ?: null) : null]);
    }

    /** POST hr/course-assignments/{id}/complete {name?} — the person themselves only. */
    public function complete(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        abort_unless((int) $a->user_id === (int) $request->user()->id, 403, 'Only the person taking the course can mark it complete.');
        $c = $this->ownCourse($aid, (int) $a->course_id);
        // A course with a quiz is finished by passing it (POST .../quiz), never by marking it complete.
        abort_if(\App\Support\HrQuiz::has($c), 422, 'Pass the quiz to finish this course.');
        $name = trim((string) $request->input('name', ''));
        if ($c->needs_ack) abort_if(mb_strlen($name) < 2, 422, 'Type your name to confirm you finished and understood the course.');
        $r = HrTalent::completeCourse($aid, $a, $c->needs_ack ? mb_substr($name, 0, 120) : null, (int) $request->user()->id);
        // Signature stamp (2026-11-27): a typed-name acknowledgement records the signer's IP + approximate place.
        if ($c->needs_ack) {
            $stamp = \App\Support\SignatureStamp::capture($request);
            DB::table('hr_course_assignments')->where('id', $a->id)->whereNotNull('acknowledged_name')->whereNull('acknowledged_location')
                ->update(['acknowledged_ip' => $stamp['ip'], 'acknowledged_location' => $stamp['location']]);
        }
        $this->hrNote($aid, 'hr.course_completed', 'hr_course', (int) $c->id, ['certificate_no' => $r['certificate_no']]);

        return response()->json(['ok' => true] + $r);
    }

    // ── quizzes (HR Phase 4 batch 6, App\Support\HrQuiz) ───────────────────────────────────────────

    /** GET hr/courses/{id}/quiz — the quiz with its answers, for the editor (agency admins). */
    public function quiz(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);

        return response()->json(\App\Support\HrQuiz::forEditor($this->ownCourse($aid, $id)));
    }

    /** PUT hr/courses/{id}/quiz {pass_mark, max_attempts, questions[]} — replaces it; no questions = no quiz. */
    public function saveQuiz(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $c = $this->ownCourse($aid, $id);
        $d = $request->validate(['pass_mark' => 'nullable|integer|min:1|max:100', 'max_attempts' => 'nullable|integer|min:1|max:20',
            'questions' => 'present|array|max:' . \App\Support\HrQuiz::MAX_QUESTIONS, 'questions.*.prompt' => 'nullable|string|max:600',
            'questions.*.kind' => 'nullable|in:single,multi', 'questions.*.options' => 'nullable|array|max:8', 'questions.*.options.*' => 'nullable|string|max:240',
            'questions.*.correct' => 'nullable|array|max:8', 'questions.*.correct.*' => 'integer|min:0|max:7']);
        $r = \App\Support\HrQuiz::save($aid, $c, isset($d['pass_mark']) ? (int) $d['pass_mark'] : null, isset($d['max_attempts']) ? (int) $d['max_attempts'] : null, $d['questions']);
        $this->hrNote($aid, 'hr.course_quiz_saved', 'hr_course', $id, $r);

        return response()->json(['ok' => true] + $r);
    }

    /** GET hr/course-assignments/{id}/quiz — the person's own quiz: questions without answers, tries used and left. */
    public function myQuiz(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        abort_unless((int) $a->user_id === (int) $request->user()->id, 403, 'Only the person taking the course sees its quiz.');

        return response()->json(\App\Support\HrQuiz::forTaker($this->ownCourse($aid, (int) $a->course_id), $a));
    }

    /** POST hr/course-assignments/{id}/quiz {answers: {question id: [option indexes]}, name?} — one try; a pass completes the course. */
    public function submitQuiz(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        abort_unless((int) $a->user_id === (int) $request->user()->id, 403, 'Only the person taking the course can answer its quiz.');
        $c = $this->ownCourse($aid, (int) $a->course_id);
        $d = $request->validate(['answers' => 'present|array|max:' . \App\Support\HrQuiz::MAX_QUESTIONS, 'answers.*' => 'array|max:8', 'answers.*.*' => 'integer|min:0|max:7',
            'name' => 'nullable|string|max:120']);
        $name = trim((string) ($d['name'] ?? ''));
        if ($c->needs_ack) abort_if(mb_strlen($name) < 2, 422, 'Type your name to confirm you finished and understood the course.');
        $r = \App\Support\HrQuiz::submit($aid, $c, $a, $d['answers'], $c->needs_ack ? mb_substr($name, 0, 120) : null, (int) $request->user()->id);
        if ($c->needs_ack && ! empty($r['passed'])) {   // signature stamp (2026-11-27): the typed acknowledgement's IP + approximate place
            $stamp = \App\Support\SignatureStamp::capture($request);
            DB::table('hr_course_assignments')->where('id', $a->id)->whereNotNull('acknowledged_name')->whereNull('acknowledged_location')
                ->update(['acknowledged_ip' => $stamp['ip'], 'acknowledged_location' => $stamp['location']]);
        }
        $this->hrNote($aid, $r['passed'] ? 'hr.course_quiz_passed' : 'hr.course_quiz_failed', 'hr_course', (int) $c->id,
            ['score' => $r['score'], 'pass_mark' => $r['pass_mark']] + ($r['passed'] ? ['certificate_no' => $r['certificate_no'] ?? null] : []));

        return response()->json(['ok' => true] + $r);
    }

    /** POST hr/course-assignments/{id}/retry — a manager allows one more try (never for themselves). */
    public function allowRetry(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        abort_if((int) $a->user_id === (int) $request->user()->id, 403, 'Someone else allows you another try.');
        $this->hrNeedPerson($request, $aid, (int) $a->user_id);
        abort_if($a->completed_at, 422, 'This course is already complete.');
        $c = $this->ownCourse($aid, (int) $a->course_id);
        abort_unless(\App\Support\HrQuiz::has($c) && $c->max_attempts !== null, 422, 'This course has no limit on tries.');
        DB::table('hr_course_assignments')->where('id', $id)->update(['extra_attempts' => DB::raw('extra_attempts + 1'), 'updated_at' => now()]);
        $this->bell($aid, (int) $a->user_id, 'Another try: ' . $c->title, 'You can take the quiz again. Open it under My HR.');
        $this->hrNote($aid, 'hr.course_retry_allowed', 'hr_course', (int) $c->id, ['user_id' => (int) $a->user_id, 'assignment_id' => $id]);

        return response()->json(['ok' => true]);
    }

    /** GET hr/course-assignments/{id}/certificate — the completion certificate PDF (person or their managers). */
    public function certificate(Request $request, int $id): Response
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownAssignment($request, $aid, $id);
        abort_unless($a->completed_at, 404, 'This course is not complete yet.');
        $c = $this->ownCourse($aid, (int) $a->course_id);
        $u = DB::table('users')->where('id', $a->user_id)->first(['first_name', 'last_name']);
        $agency = DB::table('agencies')->where('id', $aid)->first(['name', 'brand_logo_url', 'logo_url', 'brand_primary_color']);
        $logo = $agency->brand_logo_url ?: ($agency->logo_url ?? null);
        if ($logo && str_starts_with((string) $logo, '/')) $logo = 'https://api.kiddietrac.com' . $logo;
        $tz = AgencyTime::tz($aid);
        $html = view('pdf.hr_course_certificate', [
            'agency' => $agency, 'logo' => $logo, 'colour' => $agency->brand_primary_color ?: '#1F6080',
            'person' => trim($u->first_name . ' ' . $u->last_name), 'course' => $c->title,
            'date' => \Illuminate\Support\Carbon::parse($a->completed_at, 'UTC')->setTimezone($tz)->format('F j, Y'),
            'number' => $a->certificate_no, 'hours' => $a->pd_hours_id ? (float) $c->pd_hours : null, 'ack' => $a->acknowledged_name,
            'ackStamp' => $a->acknowledged_name ? \App\Support\SignatureStamp::lines($a->completed_at, $a->acknowledged_ip ?? null, $a->acknowledged_location ?? null, $aid) : [],
        ])->render();
        $opts = new Options();
        $opts->set('isRemoteEnabled', true);
        $opts->set('defaultFont', 'DejaVu Sans');
        $pdf = new Dompdf($opts);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('letter', 'landscape');
        $pdf->render();
        $file = 'Certificate-' . preg_replace('/[^A-Za-z0-9]+/', '-', $c->title) . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', (string) $u->first_name) . '.pdf';

        return new Response($pdf->output(), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="' . $file . '"', 'Cache-Control' => 'no-cache, must-revalidate']);
    }
}
