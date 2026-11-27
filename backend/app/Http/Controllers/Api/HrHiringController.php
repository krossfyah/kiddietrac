<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\HrAccess;
use App\Http\Controllers\Controller;
use App\Support\AgencyTime;
use App\Support\Brand;
use App\Support\CareersMail;
use App\Support\CentreScope;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Live;
use App\Support\TestAgency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * KIDDIETRAC HR, PHASE 2 — HIRING FOR AGENCIES (2026-11-28). KiddieTrac's own careers pipeline, per agency: the same
 * career_applications + career_application_events tables, the same letters (CareersMail, now in the agency's name),
 * the same private files (storage/app/private/careers/{id}) and the same retention job (kt:careers-retention). An
 * agency row has agency_id set; Platform -> Careers only ever reads agency_id NULL.
 *
 *  GET  hr/public/jobs/{slug}        the agency's open posts for its public apply page (jobs.html?a=slug), no sign-in
 *  POST hr/public/jobs/{slug}/apply  an application (RejectTestVisitors + throttle, honeypot), CV required
 *  GET  hr/hiring                    posts + applicants this caller manages (admins: agency; directors: own centres)
 *  ...  hr/jobs, hr/applications/*   posts, stages, letters, interview booking, Hire
 *
 * HIRE creates the account through the Users screen's own invite (AdminController::createUser: set-password email),
 * fills the employee file (job title, type, centre, start date, manager) and starts onboarding. Agency admins only:
 * the invite flow it reuses is theirs.
 */
final class HrHiringController extends Controller
{
    use HrAccess;

    private const FILE_RULE = ['file', 'mimes:pdf,doc,docx,rtf,txt', 'max:8192'];
    private const OPEN = ['new', 'reviewing', 'interview', 'offer'];

    // ── public apply page ─────────────────────────────────────────────────────────────────

    private function publicAgency(string $slug): object
    {
        $a = DB::table('agencies')->where('slug', $slug)->whereNull('deleted_at')->first();
        abort_unless($a && HrLeave::on((int) $a->id), 404, 'This page is not available.');

        return $a;
    }

    /** The agency's public face: its own name and logo, KiddieTrac only where the licence shows it. */
    public static function org(int $aid, ?object $actor = null): array
    {
        $b = Brand::forAgency($aid);
        $a = DB::table('agencies')->where('id', $aid)->first(['name', 'slug', 'brand_logo_url', 'logo_url', 'brand_primary_color', 'brand_support_email']);
        $logo = $a->brand_logo_url ?: ($a->logo_url ?? null);
        if ($logo && str_starts_with((string) $logo, '/')) {
            $logo = 'https://api.kiddietrac.com' . $logo;
        }
        $s = HrTalent::settings($aid);
        $reply = $s['reply_to'] ?: ($a->brand_support_email ?: ($actor->email ?? null));

        return ['agency_id' => $aid, 'name' => (string) $a->name, 'logo' => $logo ?: null, 'colour' => $a->brand_primary_color ?: null,
            'careers_url' => 'https://app.kiddietrac.com/jobs.html?a=' . rawurlencode((string) $a->slug),
            'reply_to' => filter_var((string) $reply, FILTER_VALIDATE_EMAIL) ? (string) $reply : null,
            'white_label' => (bool) ($b['white_label'] ?? false), 'keep_months' => $s['keep_months']];
    }

    public function publicJobs(string $slug): JsonResponse
    {
        $a = $this->publicAgency($slug);
        $org = self::org((int) $a->id);
        $centres = DB::table('centres')->whereIn('id', Live::centreIds((int) $a->id) ?: [0])->get(['id', 'name', 'city'])->keyBy('id');
        $posts = DB::table('hr_job_posts')->where('agency_id', $a->id)->where('status', 'open')->orderByDesc('created_at')->get()
            ->filter(fn ($p) => ! $p->centre_id || isset($centres[$p->centre_id]))
            ->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title, 'type' => HrLeave::EMPLOYMENT[$p->employment_type] ?? null,
                'centre' => $p->centre_id ? $centres[$p->centre_id]->name : null, 'city' => $p->centre_id ? ($centres[$p->centre_id]->city ?? null) : null,
                'pay' => $this->payText($p), 'description' => $p->description])->values();

        return response()->json(['agency' => ['name' => $org['name'], 'logo' => $org['logo'], 'colour' => $org['colour'], 'white_label' => $org['white_label']],
            'intro' => HrTalent::settings((int) $a->id)['apply_intro'], 'posts' => $posts, 'keep_months' => $org['keep_months'],
            // seo1127: JobPosting data per open post, only when the agency switched Google Jobs on (else null = noindex).
            'google_jobs' => (fn ($g) => $g === null ? null : (object) $g)(\App\Support\HrGoogleJobs::forPublic((int) $a->id, $org))]);
    }

    private function payText(object $p): ?string
    {
        if ($p->pay_min === null && $p->pay_max === null) return null;
        $f = fn ($v) => '$' . rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $u = $p->pay_unit === 'year' ? ' a year' : ' an hour';
        if ($p->pay_min !== null && $p->pay_max !== null && (float) $p->pay_min !== (float) $p->pay_max) return $f($p->pay_min) . '–' . $f($p->pay_max) . $u;

        return $f($p->pay_min ?? $p->pay_max) . $u;
    }

    public function publicApply(Request $request, string $slug): JsonResponse
    {
        if (trim((string) $request->input('website', '')) !== '') {
            return response()->json(['ok' => true]);   // the honeypot: a field people never see
        }
        $a = $this->publicAgency($slug);
        $aid = (int) $a->id;
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'job_post_id' => 'required|integer',
            'first_name' => 'required|string|max:80', 'last_name' => 'required|string|max:80',
            'email' => 'required|email|max:160', 'phone' => 'nullable|string|max:40', 'linkedin' => 'nullable|string|max:255',
            'cover_text' => 'nullable|string|max:6000',
            'resume' => array_merge(['required'], self::FILE_RULE), 'cover_file' => array_merge(['nullable'], self::FILE_RULE),
        ], [
            'resume.required' => 'Please attach your resume / CV.', 'resume.mimes' => 'Your resume needs to be a PDF or Word document.',
            'cover_file.mimes' => 'Your cover letter needs to be a PDF or Word document.', 'resume.max' => 'Your resume is larger than 8 MB.',
            'cover_file.max' => 'Your cover letter is larger than 8 MB.',
        ]);
        if ($v->fails()) {
            return response()->json(['ok' => false, 'message' => $v->errors()->first()], 422);
        }
        $d = $v->validated();
        $post = DB::table('hr_job_posts')->where('id', $d['job_post_id'])->where('agency_id', $aid)->where('status', 'open')->first();
        abort_unless($post && (! $post->centre_id || in_array((int) $post->centre_id, Live::centreIds($aid), true)), 422, 'This job is no longer open.');
        $linkedin = trim((string) ($d['linkedin'] ?? ''));
        if ($linkedin !== '' && ! preg_match('~^https?://~i', $linkedin)) $linkedin = 'https://' . $linkedin;

        $id = (int) DB::table('career_applications')->insertGetId([
            'agency_id' => $aid, 'job_post_id' => $post->id, 'centre_id' => $post->centre_id,
            'role' => $post->title, 'first_name' => trim($d['first_name']), 'last_name' => trim($d['last_name']),
            'email' => strtolower(trim($d['email'])), 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'linkedin' => $linkedin ?: null,
            'cover_text' => trim((string) ($d['cover_text'] ?? '')) ?: null, 'status' => 'new',
            'ip' => substr((string) $request->ip(), 0, 45), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paths = [];
        foreach (['resume' => 'resume_path', 'cover_file' => 'cover_path'] as $field => $col) {
            $f = $request->file($field);
            if (! $f) continue;
            $ext = strtolower($f->getClientOriginalExtension() ?: 'pdf');
            $name = ($field === 'resume' ? 'Resume' : 'Cover-letter') . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($d['first_name'] . ' ' . $d['last_name'])) . '.' . $ext;
            $paths[$col] = Storage::disk('local')->putFileAs('careers/' . $id, $f, $name);
        }
        if ($paths) DB::table('career_applications')->where('id', $id)->update($paths);
        $this->logStatus($id, null, 'new', null);
        try {   // signed out: the applicant's own action, written here (no user; never the files or the letter text)
            \App\Support\Audit::write(['user_id' => null, 'agency_id' => $aid, 'action' => 'hr.job_application_received', 'entity_type' => 'career_application',
                'entity_id' => $id, 'payload' => json_encode(['job_post_id' => (int) $post->id, 'signed_out' => true]),
                'ip_address' => substr((string) $request->ip(), 0, 45), 'user_agent' => substr((string) $request->userAgent(), 0, 500), 'created_at' => now()]);
        } catch (\Throwable $e) {
        }
        $app = DB::table('career_applications')->find($id);
        try {
            CareersMail::send('received', $app, ['org' => self::org($aid)]);   // tenant mail: a test agency sends nothing
        } catch (\Throwable $e) {
            report($e);
        }
        // The hiring managers hear about it in the portal (never for a test agency: they send nothing).
        if (! TestAgency::isAgency($aid)) {
            $to = CentreScope::managers($aid, $post->centre_id ? [(int) $post->centre_id] : [])->distinct()->pluck('u.id')->map(fn ($x) => (int) $x)->all();
            $to = array_values(array_intersect($to, HrTalent::managerIds($aid)));
            $rows = array_map(fn ($u) => ['user_id' => $u, 'agency_id' => $aid, 'type' => 'hr', 'title' => 'New applicant: ' . $post->title,
                'body' => trim($app->first_name) . ' applied on your jobs page.', 'data' => json_encode(['link' => '#hr?tab=hiring', 'kind' => 'team']), 'created_at' => now()], $to);
            if ($rows) \App\Support\Notify::write($rows);
        }

        return response()->json(['ok' => true]);
    }

    // ── the hiring screen ─────────────────────────────────────────────────────────────────

    /** Centre ids this caller hires for: null = the whole agency (admins). */
    private function scope(Request $request, int $aid): ?array
    {
        $this->hrNeedManager($request);

        return CentreScope::ids($request, $aid);
    }

    /*
     * READER AND GUARD MOVE TOGETHER (2026-11-28, Anthony). A director READS their own centres' posts and applicants and
     * the agency-wide ones (no centre); they CHANGE only their own centres' (CentreScope::canRead shared / canWrite, the
     * house rule for agency-wide rows). Agency-wide = read-only for directors: view, files, comments; no edit, letters,
     * stage moves or hire. Admins: everything. $write picks the guard; every write path passes true.
     */
    private function postQuery(Request $request, int $aid, bool $write = false)
    {
        $sc = $this->scope($request, $aid);

        return DB::table('hr_job_posts')->where('agency_id', $aid)->when($sc !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('centre_id', $sc ?: [0])
            ->when(! $write, fn ($o) => $o->orWhereNull('centre_id'))));
    }

    private function appQuery(Request $request, int $aid, bool $write = false)
    {
        $sc = $this->scope($request, $aid);

        return DB::table('career_applications')->where('agency_id', $aid)->when($sc !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('centre_id', $sc ?: [0])
            ->when(! $write, fn ($o) => $o->orWhereNull('centre_id'))));
    }

    private function ownPost(Request $request, int $aid, int $id, bool $write = false): object
    {
        $p = $this->postQuery($request, $aid)->where('id', $id)->first();
        abort_unless($p, 404, 'That job post was not found.');
        abort_if($write && ! CentreScope::canWrite(CentreScope::ids($request, $aid), $p->centre_id), 403, 'This job is for the whole agency. Only an agency admin can change it.');

        return $p;
    }

    private function ownApp(Request $request, int $aid, int $id, bool $write = false): object
    {
        $a = $this->appQuery($request, $aid)->where('id', $id)->first();
        abort_unless($a, 404, 'That application was not found.');
        abort_if($write && ! CentreScope::canWrite(CentreScope::ids($request, $aid), $a->centre_id), 403, 'This applicant is for a job for the whole agency. Only an agency admin can change it.');

        return $a;
    }

    /** POST hr/applications/{id}/comment {note} — anyone who can see the applicant (agency-wide ones too). */
    public function comment(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id);
        $note = trim((string) $request->validate(['note' => 'required|string|max:2000'])['note']);
        abort_if($note === '', 422, 'Write a comment first.');
        DB::table('career_application_events')->insert(['application_id' => $id, 'from_status' => $a->status, 'to_status' => $a->status, 'note' => $note,
            'user_id' => $request->user()->id, 'created_at' => now()]);
        $this->hrNote($aid, 'hr.applicant_commented', 'career_application', $id);

        return response()->json(['ok' => true], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $posts = $this->postQuery($request, $aid)->orderByRaw("status = 'open' DESC")->orderByDesc('created_at')->get();
        $sc = CentreScope::ids($request, $aid);
        $rw = fn ($row) => CentreScope::canWrite($sc, $row->centre_id);
        $apps = $this->appQuery($request, $aid)->orderByDesc('created_at')->limit(1000)->get();
        $centres = DB::table('centres')->where('agency_id', $aid)->pluck('name', 'id');
        $counts = $apps->groupBy('job_post_id')->map(fn ($g) => $g->countBy('status'));
        $meet = DB::table('meetings')->whereIn('id', $apps->pluck('meeting_id')->filter()->all() ?: [0])->get()->keyBy('id');

        return response()->json([
            'posts' => $posts->map(fn ($p) => ['id' => (int) $p->id, 'title' => $p->title, 'centre_id' => $p->centre_id ? (int) $p->centre_id : null,
                'centre' => $p->centre_id ? ($centres[$p->centre_id] ?? null) : null, 'employment_type' => $p->employment_type,
                'pay_min' => $p->pay_min !== null ? (float) $p->pay_min : null, 'pay_max' => $p->pay_max !== null ? (float) $p->pay_max : null,
                'pay_unit' => $p->pay_unit, 'pay' => $this->payText($p), 'description' => $p->description, 'status' => $p->status,
                'created_at' => $p->created_at, 'applicants' => (int) array_sum(($counts[$p->id] ?? collect())->all()),
                'open_applicants' => (int) collect(($counts[$p->id] ?? collect())->all())->only(self::OPEN)->sum(), 'read_only' => ! $rw($p)])->values(),
            'applications' => $apps->map(fn ($a) => ['id' => (int) $a->id, 'job_post_id' => $a->job_post_id ? (int) $a->job_post_id : null, 'role' => $a->role,
                'name' => trim($a->first_name . ' ' . $a->last_name), 'email' => $a->email, 'phone' => $a->phone, 'status' => $a->status,
                'centre' => $a->centre_id ? ($centres[$a->centre_id] ?? null) : null, 'created_at' => $a->created_at,
                'has_resume' => (bool) $a->resume_path, 'has_cover' => (bool) ($a->cover_path || $a->cover_text), 'keep_until' => $a->keep_until,
                'hired_user_id' => $a->hired_user_id ? (int) $a->hired_user_id : null, 'read_only' => ! $rw($a),
                'interview' => $a->meeting_id && isset($meet[$a->meeting_id]) ? \App\Support\Meetings::whenText($meet[$a->meeting_id]) : null])->values(),
            'stages' => HrTalent::STAGES, 'employment_types' => HrLeave::EMPLOYMENT, 'can_hire' => true,
            'hire_roles' => $this->hrIsAdmin($request) ? ['educator', 'centre_director', 'home_visitor'] : ['educator', 'home_visitor'],
            'centres' => Live::centres(DB::table('centres'), 'centres')->where('agency_id', $aid)
                ->when($sc !== null, fn ($q) => $q->whereIn('id', $sc ?: [0]))->orderBy('name')->get(['id', 'name']),
            'public_url' => self::org($aid)['careers_url'], 'settings' => HrTalent::settings($aid), 'can_settings' => $this->hrIsAdmin($request),
        ]);
    }

    private function postRules(): array
    {
        return ['title' => 'required|string|max:120', 'centre_id' => 'nullable|integer',
            'employment_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::EMPLOYMENT)),
            'pay_min' => 'nullable|numeric|min:0|max:1000000', 'pay_max' => 'nullable|numeric|min:0|max:1000000', 'pay_unit' => 'nullable|in:hour,year',
            'description' => 'nullable|string|max:8000'];
    }

    private function postRow(Request $request, int $aid, array $d): array
    {
        if (! empty($d['centre_id'])) {
            abort_unless(in_array((int) $d['centre_id'], Live::centreIds($aid), true) && CentreScope::canWrite(CentreScope::ids($request, $aid), (int) $d['centre_id']), 422, 'Choose one of your centres.');
        } else {
            abort_unless(CentreScope::ids($request, $aid) === null, 422, 'Choose the centre this job is at.');
        }
        if (isset($d['pay_min'], $d['pay_max'])) abort_if((float) $d['pay_max'] < (float) $d['pay_min'], 422, 'The top of the pay range is below the bottom.');

        return ['title' => trim($d['title']), 'centre_id' => $d['centre_id'] ?? null, 'employment_type' => $d['employment_type'] ?? null,
            'pay_min' => $d['pay_min'] ?? null, 'pay_max' => $d['pay_max'] ?? null, 'pay_unit' => ($d['pay_min'] ?? $d['pay_max'] ?? null) !== null ? ($d['pay_unit'] ?? 'hour') : null,
            'description' => isset($d['description']) ? trim($d['description']) : null, 'updated_at' => now()];
    }

    public function createPost(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->scope($request, $aid);
        $row = $this->postRow($request, $aid, $request->validate($this->postRules()));
        $id = (int) DB::table('hr_job_posts')->insertGetId($row + ['agency_id' => $aid, 'status' => 'open', 'created_by_id' => $request->user()->id, 'created_at' => now()]);
        $this->hrNote($aid, 'hr.job_post_created', 'hr_job_post', $id, ['title' => $row['title']]);

        return response()->json(['id' => $id], 201);
    }

    public function updatePost(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->ownPost($request, $aid, $id, true);
        $row = $this->postRow($request, $aid, $request->validate($this->postRules()));
        DB::table('hr_job_posts')->where('id', $id)->update($row);
        $this->hrNote($aid, 'hr.job_post_updated', 'hr_job_post', $id, ['title' => $row['title']]);

        return response()->json(['ok' => true]);
    }

    /** POST hr/jobs/{id}/close and /reopen */
    public function closePost(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->ownPost($request, $aid, $id, true);
        $open = str_ends_with($request->path(), '/reopen');
        DB::table('hr_job_posts')->where('id', $id)->update(['status' => $open ? 'open' : 'closed', 'closed_at' => $open ? null : now(), 'updated_at' => now()]);
        $this->hrNote($aid, $open ? 'hr.job_post_reopened' : 'hr.job_post_closed', 'hr_job_post', $id);

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id);
        $ev = DB::table('career_application_events')->where('application_id', $id)->orderBy('created_at')->orderBy('id')->get();
        $by = $this->hrNames($ev->pluck('user_id')->filter()->map(fn ($v) => (int) $v)->all());
        $m = $a->meeting_id ? DB::table('meetings')->where('id', $a->meeting_id)->first() : null;

        return response()->json(['application' => ['id' => (int) $a->id, 'role' => $a->role, 'first_name' => $a->first_name, 'last_name' => $a->last_name,
            'name' => trim($a->first_name . ' ' . $a->last_name), 'email' => $a->email, 'phone' => $a->phone, 'linkedin' => $a->linkedin,
            'cover_text' => $a->cover_text, 'status' => $a->status, 'has_resume' => (bool) $a->resume_path, 'has_cover' => (bool) $a->cover_path,
            'created_at' => $a->created_at, 'keep_until' => $a->keep_until, 'centre_id' => $a->centre_id ? (int) $a->centre_id : null,
            'job_post_id' => $a->job_post_id ? (int) $a->job_post_id : null, 'hired_user_id' => $a->hired_user_id ? (int) $a->hired_user_id : null,
            'last_email_kind' => $a->last_email_kind, 'last_emailed_at' => $a->last_emailed_at,
            'read_only' => ! CentreScope::canWrite(CentreScope::ids($request, $aid), $a->centre_id),
            'interview' => $m ? ['when' => \App\Support\Meetings::whenText($m), 'where' => $m->location, 'join_url' => $m->join_url, 'status' => $m->status] : null],
            'events' => $ev->map(fn ($e) => ['from' => $e->from_status, 'to' => $e->to_status, 'note' => $e->note ?? null, 'at' => $e->created_at, 'by' => $e->user_id ? ($by[(int) $e->user_id] ?? null) : null])->values(),
            'stages' => HrTalent::STAGES]);
    }

    public function file(Request $request, int $id, string $kind)
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id);
        $path = $kind === 'cover' ? $a->cover_path : $a->resume_path;
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'File not found.');
        $this->hrNote($aid, 'hr.applicant_file_opened', 'career_application', $id, ['kind' => $kind]);
        if (str_contains($request->path(), '/view/')) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'docx') {
                $html = \App\Support\DocxHtml::fromFile(Storage::disk('local')->path($path));
                if ($html === null) return response()->json(['message' => 'This Word file could not be read. Download it instead.'], 422);
                return response()->json(['html' => $html, 'name' => basename($path)]);
            }
            if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'txt'], true)) {
                return \App\Support\FileResponse::download($path, basename($path), [], 'local', true);
            }
            return response()->json(['message' => 'This kind of file (.' . $ext . ') cannot be shown here. Download it instead.'], 422);
        }

        return \App\Support\FileResponse::download($path, basename($path));
    }

    /** PATCH hr/applications/{id} {status} — move a stage (no email; the letters are their own actions). */
    public function move(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id, true);
        $to = (string) $request->validate(['status' => 'required|in:new,reviewing,interview,offer,declined,withdrawn'])['status'];
        $this->setStatus($a, $to, (int) $request->user()->id);
        $this->hrNote($aid, 'hr.applicant_moved', 'career_application', $id, ['from' => $a->status, 'to' => $to]);

        return response()->json(['ok' => true]);
    }

    /** POST hr/applications/bulk-status {ids[], status} */
    public function bulkMove(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $d = $request->validate(['ids' => 'required|array|max:200', 'ids.*' => 'integer', 'status' => 'required|in:new,reviewing,interview,offer,declined,withdrawn']);
        $rows = $this->appQuery($request, $aid, true)->whereIn('id', $d['ids'])->get();
        foreach ($rows as $a) {
            if ($a->status !== 'hired') $this->setStatus($a, $d['status'], (int) $request->user()->id);
        }
        $this->hrNote($aid, 'hr.applicants_moved', 'agency', $aid, ['count' => $rows->count(), 'to' => $d['status']]);

        return response()->json(['moved' => $rows->where('status', '!=', 'hired')->count()]);
    }

    private function setStatus(object $a, string $to, ?int $by, array $extra = []): void
    {
        $stamp = $to === 'declined' ? ($a->status === 'declined' && ($a->declined_at ?? null) ? $a->declined_at : now()) : null;   // hr:declined-purge counts from here
        DB::table('career_applications')->where('id', $a->id)->update(array_merge(['status' => $to, 'declined_at' => $stamp, 'updated_at' => now()], $extra));
        if ($a->status !== $to) $this->logStatus((int) $a->id, $a->status, $to, $by);
    }

    private function logStatus(int $id, ?string $from, string $to, ?int $userId): void
    {
        DB::table('career_application_events')->insert(['application_id' => $id, 'from_status' => $from, 'to_status' => $to, 'user_id' => $userId, 'created_at' => now()]);
    }

    // ── letters: preview / interview / keep on file / decline (CareersMail, in the agency's name) ─────

    private function slot(Request $request, int $aid): array
    {
        $d = $request->validate(['date' => 'required|date_format:Y-m-d', 'time' => 'required|date_format:H:i', 'minutes' => 'nullable|integer|min:15|max:180',
            'how' => 'required|in:in_person,phone,video', 'location' => 'nullable|string|max:200', 'link' => 'nullable|url|max:500']);
        $tz = AgencyTime::tz($aid);
        $start = Carbon::createFromFormat('Y-m-d H:i', $d['date'] . ' ' . $d['time'], $tz);
        abort_if($start->isPast(), 422, 'Pick a time in the future.');
        abort_if($d['how'] === 'video' && empty($d['link']), 422, 'Add the video call link.');
        abort_if($d['how'] === 'in_person' && trim((string) ($d['location'] ?? '')) === '', 422, 'Add where the interview is.');
        $mins = (int) ($d['minutes'] ?? 30);

        return ['start' => $start, 'end' => $start->copy()->addMinutes($mins), 'minutes' => $mins, 'tz' => $tz, 'how' => $d['how'],
            'location' => trim((string) ($d['location'] ?? '')), 'link' => $d['how'] === 'video' ? ($d['link'] ?? null) : null];
    }

    private function meetingOpts(object $m, array $s, ?object $att): array
    {
        return ['when' => \App\Support\Meetings::whenText($m),
            'how' => ['in_person' => 'In person', 'phone' => 'We will call you', 'video' => 'Video call (link below)'][$s['how']],
            'where' => $m->location ?: '', 'minutes' => $s['minutes'], 'join_url' => $m->join_url, 'video' => 'none',
            'rsvp' => $att ? [\App\Support\Meetings::rsvpUrl($att, 'accepted'), \App\Support\Meetings::rsvpUrl($att, 'tentative'), \App\Support\Meetings::rsvpUrl($att, 'declined')] : null];
    }

    public function preview(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id, true);
        $kind = (string) $request->validate(['kind' => 'required|in:interview,on_file,decline'])['kind'];
        $o = ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000), 'org' => self::org($aid, $request->user())];
        if ($kind === 'interview') {
            $s = $this->slot($request, $aid);
            $m = (object) ['id' => 0, 'scope' => 'agency', 'agency_id' => $aid, 'timezone' => $s['tz'], 'all_day' => 0,
                'starts_at' => $s['start']->copy()->utc()->format('Y-m-d H:i:s'), 'ends_at' => $s['end']->copy()->utc()->format('Y-m-d H:i:s'),
                'video' => 'none', 'join_url' => $s['link'], 'location' => $s['location']];
            $o['meeting'] = $this->meetingOpts($m, $s, null);
            $o['meeting']['rsvp'] = ['#', '#', '#'];
        }
        if ($kind === 'on_file') $o['keep_until'] = now(AgencyTime::tz($aid))->addMonths(HrTalent::settings($aid)['keep_months'])->toDateString();
        $mail = CareersMail::build($kind, $a, $o);

        return response()->json(['subject' => $mail['subject'], 'html' => $mail['html'], 'to' => $a->email]);
    }

    /** We pick the time: an agency meeting (organiser = the caller), the applicant invited as a guest with an .ics. */
    public function interview(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id, true);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $s = $this->slot($request, $aid);
        $user = $request->user();
        $name = trim($a->first_name . ' ' . $a->last_name);
        $agencyName = (string) DB::table('agencies')->where('id', $aid)->value('name');
        $mid = (int) DB::table('meetings')->insertGetId([
            'scope' => 'agency', 'agency_id' => $aid, 'centre_id' => $a->centre_id,
            'title' => 'Interview: ' . $a->role . ' (' . $name . ')', 'description' => 'An interview for the ' . $a->role . ' role at ' . $agencyName . '.',
            'location' => $s['location'] !== '' ? $s['location'] : null, 'starts_at' => $s['start']->copy()->utc(), 'ends_at' => $s['end']->copy()->utc(),
            'all_day' => 0, 'timezone' => $s['tz'], 'video' => 'none', 'join_url' => $s['link'], 'organizer_id' => $user->id, 'status' => 'scheduled',
            'sequence' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $m = DB::table('meetings')->where('id', $mid)->first();
        $attId = (int) DB::table('meeting_attendees')->insertGetId(['meeting_id' => $mid, 'user_id' => null, 'email' => $a->email, 'name' => $name, 'kind' => 'guest',
            'optional' => 0, 'response' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $att = DB::table('meeting_attendees')->where('id', $attId)->first();
        $org = DB::table('users')->where('id', $user->id)->first(['id', 'first_name', 'last_name', 'email']);
        $ics = \App\Support\Meetings::ics($m, 'REQUEST', [$att], $org);
        $ok = CareersMail::send('interview', $a, ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000),
            'meeting' => $this->meetingOpts($m, $s, $att), 'org' => self::org($aid, $user)], null, $ics);
        if ($ok) DB::table('meeting_attendees')->where('id', $attId)->update(['invited_at' => now()]);
        $this->setStatus($a, 'interview', (int) $user->id, ['meeting_id' => $mid, 'last_email_kind' => 'interview', 'last_emailed_at' => now()]);
        $this->hrNote($aid, 'hr.applicant_interview_booked', 'career_application', $id, ['meeting_id' => $mid]);

        return response()->json(['ok' => true, 'sent' => $ok, 'meeting_id' => $mid, 'when' => \App\Support\Meetings::whenText($m)]);
    }

    public function keep(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id, true);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $until = now(AgencyTime::tz($aid))->addMonths(HrTalent::settings($aid)['keep_months'])->toDateString();
        $ok = CareersMail::send('on_file', $a, ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000), 'keep_until' => $until,
            'org' => self::org($aid, $request->user())]);
        $this->setStatus($a, 'on_file', (int) $request->user()->id, ['keep_until' => $until, 'last_email_kind' => 'on_file', 'last_emailed_at' => now()]);
        $this->hrNote($aid, 'hr.applicant_kept_on_file', 'career_application', $id, ['keep_until' => $until]);

        return response()->json(['ok' => true, 'sent' => $ok, 'keep_until' => $until]);
    }

    public function decline(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $a = $this->ownApp($request, $aid, $id, true);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $ok = CareersMail::send('decline', $a, ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000), 'org' => self::org($aid, $request->user())]);
        $this->setStatus($a, 'declined', (int) $request->user()->id, ['keep_until' => null, 'last_email_kind' => 'decline', 'last_emailed_at' => now()]);
        $this->hrNote($aid, 'hr.applicant_declined', 'career_application', $id);

        return response()->json(['ok' => true, 'sent' => $ok]);
    }

    // ── Hire ──────────────────────────────────────────────────────────────────────────────

    public function hire(Request $request, int $id): JsonResponse
    {
        $aid = $this->hrAgency($request);
        // Admins: anyone. Directors (2026-11-28, Anthony): applicants to their own centres' posts (not agency-wide ones),
        // into one of their own centres, as an educator or home visitor (a new director is an admin's call).
        $a = $this->ownApp($request, $aid, $id, true);
        $sc = CentreScope::ids($request, $aid);
        abort_if($a->status === 'hired' || $a->hired_user_id, 422, 'This person has already been hired.');
        $d = $request->validate([
            'role' => 'required|in:educator,centre_director,home_visitor', 'centre_id' => 'nullable|integer',
            'start_date' => 'required|date_format:Y-m-d', 'job_title' => 'nullable|string|max:120',
            'employment_type' => 'nullable|in:' . implode(',', array_keys(HrLeave::EMPLOYMENT)),
            'manager_user_id' => 'nullable|integer', 'template_id' => 'nullable|integer', 'username' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:180', 'send_invite' => 'nullable|boolean',
        ]);
        if (! empty($d['centre_id'])) abort_unless(in_array((int) $d['centre_id'], Live::centreIds($aid), true), 422, 'Choose one of your centres.');
        if ($sc !== null) {
            abort_if(empty($d['centre_id']) || ! CentreScope::canWrite($sc, (int) $d['centre_id']), 422, 'Choose one of your centres.');
            abort_if($d['role'] === 'centre_director', 403, 'Only an agency admin can hire a centre director.');
        }
        if (! empty($d['manager_user_id'])) abort_unless(in_array((int) $d['manager_user_id'], HrTalent::managerIds($aid), true), 422, 'Choose a manager from this agency.');
        if (! empty($d['template_id'])) abort_unless(DB::table('hr_onboarding_templates')->where('id', $d['template_id'])->where('agency_id', $aid)->whereNull('archived_at')->exists(), 422, 'Choose one of your onboarding checklists.');

        // The Users screen's own create + invite, exactly as an admin adding someone there (validation, username rule, invite email).
        $sub = Request::create('/api/v1/users', 'POST', array_filter([
            'email' => $d['email'] ?? $a->email, 'first_name' => $a->first_name, 'last_name' => $a->last_name, 'phone' => $a->phone,
            'role' => $d['role'], 'centre_id' => $d['centre_id'] ?? null, 'send_invite' => (bool) ($d['send_invite'] ?? true), 'username' => $d['username'] ?? null,
        ], fn ($v) => $v !== null));
        $sub->headers->set('X-Active-Agency-Id', (string) $aid);
        $sub->headers->set('Accept', 'application/json');
        $sub->setUserResolver(fn () => $request->user());
        if ($sc !== null) $sub->attributes->set('kt_hr_hire_agency', $aid);   // a director: AdminController::createUser honours it (scope checked above)
        $resp = app(AdminController::class)->createUser($sub);
        $body = json_decode((string) $resp->getContent(), true) ?: [];
        if ($resp->getStatusCode() >= 300 || empty($body['id'])) {
            return response()->json(['message' => $body['message'] ?? 'The account could not be created.', 'errors' => $body['errors'] ?? null], $resp->getStatusCode() >= 400 ? $resp->getStatusCode() : 422);
        }
        $uid = (int) $body['id'];
        $post = $a->job_post_id ? DB::table('hr_job_posts')->where('id', $a->job_post_id)->first() : null;
        $file = ['job_title' => $d['job_title'] ?? ($post->title ?? $a->role), 'employment_type' => $d['employment_type'] ?? ($post->employment_type ?? null),
            'status' => 'active', 'manager_user_id' => $d['manager_user_id'] ?? null, 'primary_centre_id' => $d['centre_id'] ?? null,
            'updated_by_id' => $request->user()->id, 'updated_at' => now()];
        DB::transaction(function () use ($aid, $uid, $file, $d) {
            \App\Support\HrLeave::syncContractorFlag($uid, DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->value('employment_type'), $file['employment_type'] ?? null);
            if (DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->exists()) {
                DB::table('hr_employee_files')->where('agency_id', $aid)->where('user_id', $uid)->update($file);
            } else {
                DB::table('hr_employee_files')->insert($file + ['agency_id' => $aid, 'user_id' => $uid, 'created_at' => now()]);
            }
            DB::table('users')->where('id', $uid)->update(['hire_date' => $d['start_date']]);
        });
        $tpl = $d['template_id'] ?? DB::table('hr_onboarding_templates')->where('agency_id', $aid)->whereNull('archived_at')
            ->where(fn ($q) => $q->where('role', $d['role'])->orWhereNull('role'))->orderByRaw('role IS NULL')->orderBy('id')->value('id');
        $oid = HrTalent::startOnboarding($aid, $uid, $tpl ? (int) $tpl : null, $d['start_date'], (int) $request->user()->id, (int) $a->id);
        $this->setStatus($a, 'hired', (int) $request->user()->id, ['hired_user_id' => $uid, 'keep_until' => null]);
        HrTalent::coursesForUser($uid);   // courses that go to everyone in their role
        $this->hrNote($aid, 'hr.applicant_hired', 'career_application', $id, ['user_id' => $uid, 'onboarding_id' => $oid]);

        return response()->json(['ok' => true, 'user_id' => $uid, 'onboarding_id' => $oid, 'invite_sent' => (bool) ($body['invite_sent'] ?? false), 'note' => $body['note'] ?? null], 201);
    }

    // ── settings ──────────────────────────────────────────────────────────────────────────

    public function saveSettings(Request $request): JsonResponse
    {
        $aid = $this->hrAgency($request);
        $this->hrNeedAdmin($request);
        $d = $request->validate(['keep_months' => 'required|integer|min:1|max:60', 'reply_to' => 'nullable|email|max:180', 'apply_intro' => 'nullable|string|max:1500',
            'declined_days' => 'nullable|integer|min:1|max:3650']);
        // declined_days: null = Never; left out = unchanged.
        $days = $request->exists('declined_days') ? ($d['declined_days'] ?? null) : HrTalent::settings($aid)['declined_days'];
        $out = HrTalent::saveSettings($aid, ['keep_months' => (int) $d['keep_months'], 'reply_to' => $d['reply_to'] ?? null, 'apply_intro' => isset($d['apply_intro']) ? trim($d['apply_intro']) : null,
            'declined_days' => $days === null ? null : (int) $days]);
        $this->hrNote($aid, 'hr.hiring_settings_updated', 'agency', $aid, ['keep_months' => $out['keep_months'], 'declined_days' => $out['declined_days']]);

        return response()->json(['settings' => $out]);
    }
}
