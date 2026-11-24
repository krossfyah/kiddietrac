<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * JOB APPLICATIONS FROM www.kiddietrac.com/careers (2026-10-30).
 *
 * Anthony: "the career page doesnt allow to upload cover letter seperately including resume seperately".
 * Worse than that: the page's Submit only closed the window and said "Application submitted! Confirmation
 * sent" - nothing was saved or sent, so every application was lost. Now:
 *   POST /marketing-site/careers/apply  (public, multipart) - CV required, cover letter as a file and/or text.
 *   Files are private (storage/app/private/careers/{id}), never public URLs.
 *   info@kiddietrac.com gets the application with both files attached (Anthony's choice); Reply-To the applicant.
 *   The applicant gets a confirmation (first name only).
 *   Platform -> Careers: GET /platform/careers, GET /platform/careers/{id}/file/{kind}, PATCH /platform/careers/{id}.
 *   Platform -> Careers -> Insights: GET /platform/careers/stats (2026-11-02); status changes go to career_application_events.
 */
class CareersController extends Controller
{
    private const FILE_RULE = ['file', 'mimes:pdf,doc,docx,rtf,txt', 'max:8192'];
    public const STATUSES = ['new', 'reviewing', 'interview', 'offer', 'hired', 'on_file', 'declined', 'withdrawn'];   // on_file 2026-11-24

    private function cors(JsonResponse $r): JsonResponse
    {
        return $r->header('Access-Control-Allow-Origin', '*');
    }

    public function apply(Request $request): JsonResponse
    {
        // Spam trap: a field people never see.
        if (trim((string) $request->input('website', '')) !== '') {
            return $this->cors(response()->json(['ok' => true]));
        }
        $v = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'role'       => 'required|string|max:120',
            'first_name' => 'required|string|max:80',
            'last_name'  => 'required|string|max:80',
            'email'      => 'required|email|max:160',
            'phone'      => 'nullable|string|max:40',
            'linkedin'   => 'nullable|string|max:255',
            'cover_text' => 'nullable|string|max:6000',
            'resume'     => array_merge(['required'], self::FILE_RULE),
            'cover_file' => array_merge(['nullable'], self::FILE_RULE),
        ], [
            'resume.required' => 'Please attach your resume / CV.',
            'resume.mimes' => 'Your resume needs to be a PDF or Word document.',
            'cover_file.mimes' => 'Your cover letter needs to be a PDF or Word document.',
            'resume.max' => 'Your resume is larger than 8 MB.',
            'cover_file.max' => 'Your cover letter is larger than 8 MB.',
        ]);
        if ($v->fails()) {
            return $this->cors(response()->json(['ok' => false, 'message' => $v->errors()->first()], 422));
        }
        $d = $v->validated();
        $email = strtolower(trim($d['email']));
        $linkedin = trim((string) ($d['linkedin'] ?? ''));
        if ($linkedin !== '' && ! preg_match('~^https?://~i', $linkedin)) $linkedin = 'https://' . $linkedin;

        $id = DB::table('career_applications')->insertGetId([
            'role' => trim($d['role']), 'first_name' => trim($d['first_name']), 'last_name' => trim($d['last_name']),
            'email' => $email, 'phone' => trim((string) ($d['phone'] ?? '')) ?: null, 'linkedin' => $linkedin ?: null,
            'cover_text' => trim((string) ($d['cover_text'] ?? '')) ?: null, 'status' => 'new',
            'ip' => substr((string) $request->ip(), 0, 45), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paths = [];
        foreach (['resume' => 'resume_path', 'cover_file' => 'cover_path'] as $field => $col) {
            $f = $request->file($field);
            if (! $f) continue;
            $ext = strtolower($f->getClientOriginalExtension() ?: 'pdf');
            $base = $field === 'resume' ? 'Resume' : 'Cover-letter';
            $name = $base . '-' . preg_replace('/[^A-Za-z0-9]+/', '-', trim($d['first_name'] . ' ' . $d['last_name'])) . '.' . $ext;
            $paths[$col] = Storage::disk('local')->putFileAs('careers/' . $id, $f, $name);
        }
        if ($paths) DB::table('career_applications')->where('id', $id)->update($paths);
        $this->logStatus($id, null, 'new', null);
        $app = DB::table('career_applications')->find($id);

        $this->notifyTeam($app);
        $this->confirmApplicant($app);

        return $this->cors(response()->json(['ok' => true, 'id' => $id]));
    }

    private function notifyTeam(object $a): void
    {
        try {
            $full = trim($a->first_name . ' ' . $a->last_name);
            $rows = ['Role' => $a->role, 'Name' => $full, 'Email' => $a->email, 'Phone' => $a->phone ?: '(not provided)',
                'LinkedIn / portfolio' => $a->linkedin ?: '(not provided)', 'Resume' => $a->resume_path ? 'Attached' : 'Missing',
                'Cover letter' => $a->cover_path ? 'Attached' : ($a->cover_text ? 'Typed (below)' : 'None')];
            $sections = [['heading' => 'Applicant', 'rows' => $rows]];
            if ($a->cover_text) $sections[] = ['heading' => 'Cover letter', 'text' => $a->cover_text];
            $html = \App\Support\SalesMail::render([
                'preheader' => 'New application: ' . $a->role . ' — ' . $full,
                'eyebrow' => 'New job application', 'icon' => '💼',
                'title' => $full, 'subtitle' => $a->role,
                'intro' => 'Someone applied on www.kiddietrac.com/careers. The resume and cover letter are attached; reply to this email to write to them.',
                'sections' => $sections,
                'button' => ['label' => 'Open Platform → Careers', 'url' => 'https://app.kiddietrac.com/dashboard.html#platform-careers'],
            ]);
            Mail::html($html, function ($m) use ($a, $full) {
                \App\Support\MailScope::platform($m);
                $m->to('info@kiddietrac.com')->subject('Job application: ' . $a->role . ' — ' . $full)->replyTo($a->email, $full);
                foreach (['resume_path', 'cover_path'] as $c) {
                    if ($a->$c && Storage::disk('local')->exists($a->$c)) {
                        $m->attach(Storage::disk('local')->path($a->$c), ['as' => basename($a->$c)]);
                    }
                }
            });
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function confirmApplicant(object $a): void
    {
        try {
            \App\Support\CareersMail::send('received', $a);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── Platform → Careers ─────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $rows = DB::table('career_applications')->orderByDesc('id')->limit(500)->get();
        return response()->json(['applications' => $rows->map(function ($r) {
            return ['id' => $r->id, 'role' => $r->role, 'name' => trim($r->first_name . ' ' . $r->last_name), 'email' => $r->email,
                'phone' => $r->phone, 'linkedin' => $r->linkedin, 'cover_text' => $r->cover_text, 'status' => $r->status,
                'has_resume' => (bool) $r->resume_path, 'has_cover' => (bool) $r->cover_path, 'created_at' => $r->created_at,
                'keep_until' => $r->keep_until ?? null, 'meeting_id' => $r->meeting_id ?? null,
                'last_email_kind' => $r->last_email_kind ?? null, 'last_emailed_at' => $r->last_emailed_at ?? null];
        })->all(), 'statuses' => self::STATUSES]);
    }

    public function file(Request $request, int $id, string $kind)
    {
        $col = $kind === 'cover' ? 'cover_path' : 'resume_path';
        $path = DB::table('career_applications')->where('id', $id)->value($col);
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'File not found.');
        return \App\Support\FileResponse::download($path, basename($path));
    }

    /**
     * GET /platform/careers/{id}/view/{kind} (2026-11-04, Anthony: "have the ability to view resumes with a view
     * function"). A PDF or picture comes back inline for the document viewer; a Word file (.docx) comes back as
     * {html, name} - App\Support\DocxHtml - because a browser cannot draw one. An old .doc or anything else says so.
     */
    public function view(Request $request, int $id, string $kind)
    {
        $col = $kind === 'cover' ? 'cover_path' : 'resume_path';
        $path = DB::table('career_applications')->where('id', $id)->value($col);
        abort_unless($path && Storage::disk('local')->exists($path), 404, 'File not found.');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext === 'docx') {
            $html = \App\Support\DocxHtml::fromFile(Storage::disk('local')->path($path));
            if ($html === null) return response()->json(['message' => 'This Word file could not be read. Download it instead.'], 422);
            return response()->json(['html' => $html, 'name' => basename($path)]);
        }
        if (in_array($ext, ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'txt'], true)) {
            return \App\Support\FileResponse::download($path, basename($path), [], 'local', true);
        }
        return response()->json(['message' => 'This kind of file (.' . $ext . ') cannot be shown here. Download it instead.'], 422);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['status' => 'required|in:' . implode(',', self::STATUSES)]);
        $old = DB::table('career_applications')->where('id', $id)->value('status');
        abort_if($old === null, 404, 'Application not found.');
        $keep = $data['status'] === 'on_file'
            ? ['keep_until' => DB::table('career_applications')->where('id', $id)->value('keep_until') ?: now(\App\Support\BookingMail::TZ)->addMonths(\App\Support\CareersMail::KEEP_MONTHS)->toDateString()]
            : ['keep_until' => null];
        DB::table('career_applications')->where('id', $id)->update(array_merge(['status' => $data['status'], 'updated_at' => now()], $keep));
        if ($old !== $data['status']) $this->logStatus($id, $old, $data['status'], optional($request->user())->id);
        return response()->json(['ok' => true]);
    }

    // ── Outcomes (2026-11-24): book a meeting / keep on file / decline politely ─────────────
    // Anthony: "for resumes submitted wire up a book a meeting, add to our database for future considerations
    // and or politely decline". Each sends ONE letter (App\Support\CareersMail) to the applicant's own address,
    // signed "The KiddieTrac Team", replies to info@. Preview first: POST .../preview returns the letter unsent.

    private function application(int $id): object
    {
        $a = DB::table('career_applications')->where('id', $id)->first();
        abort_if(! $a, 404, 'Application not found.');
        return $a;
    }

    /** The meeting fields from the request: date (Y-m-d) + time (H:i) in Toronto time, minutes. */
    private function slot(Request $request): array
    {
        $d = $request->validate([
            'date' => 'required|date_format:Y-m-d',
            'time' => 'required|date_format:H:i',
            'minutes' => 'nullable|integer|min:15|max:180',
            'video' => 'nullable|in:teams,none',
            'location' => 'nullable|string|max:200',
        ]);
        $tz = \App\Support\BookingMail::TZ;
        $start = \Illuminate\Support\Carbon::createFromFormat('Y-m-d H:i', $d['date'] . ' ' . $d['time'], $tz);
        abort_if($start->isPast(), 422, 'Pick a time in the future.');
        $mins = (int) ($d['minutes'] ?? 30);
        return ['start' => $start, 'end' => $start->copy()->addMinutes($mins), 'minutes' => $mins, 'tz' => $tz,
            'video' => $d['video'] ?? 'teams', 'location' => trim((string) ($d['location'] ?? ''))];
    }

    private function meetingOpts(object $m, int $minutes, ?object $att): array
    {
        return ['when' => \App\Support\Meetings::whenText($m),
            'how' => $m->video === 'teams' ? 'Microsoft Teams video call (link below)' : ($m->join_url ? 'Video call (link below)' : ($m->location ? 'In person' : 'We will call you')),
            'where' => $m->location ?: '', 'minutes' => $minutes, 'join_url' => $m->join_url, 'video' => $m->video,
            'rsvp' => $att ? [\App\Support\Meetings::rsvpUrl($att, 'accepted'), \App\Support\Meetings::rsvpUrl($att, 'tentative'), \App\Support\Meetings::rsvpUrl($att, 'declined')] : null];
    }

    /** The letter as it will be sent, without sending it. kind: interview | on_file | decline. */
    public function preview(Request $request, int $id): JsonResponse
    {
        $a = $this->application($id);
        $kind = (string) $request->validate(['kind' => 'required|in:interview,on_file,decline'])['kind'];
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 2000);
        $o = ['note' => $note];
        if ($kind === 'interview') {
            $s = $this->slot($request);
            $m = (object) ['id' => 0, 'scope' => 'platform', 'agency_id' => null, 'timezone' => $s['tz'], 'all_day' => 0,
                'starts_at' => $s['start']->copy()->utc()->format('Y-m-d H:i:s'), 'ends_at' => $s['end']->copy()->utc()->format('Y-m-d H:i:s'),
                'video' => $s['video'], 'join_url' => $s['video'] === 'teams' ? 'https://teams.microsoft.com/' : null, 'location' => $s['location']];
            $o['meeting'] = $this->meetingOpts($m, $s['minutes'], null);
            $o['meeting']['rsvp'] = ['#', '#', '#'];
        }
        if ($kind === 'on_file') $o['keep_until'] = now(\App\Support\BookingMail::TZ)->addMonths(\App\Support\CareersMail::KEEP_MONTHS)->toDateString();
        $mail = \App\Support\CareersMail::build($kind, $a, $o);
        return response()->json(['subject' => $mail['subject'], 'html' => $mail['html'], 'to' => $a->email]);
    }

    /** We pick the time: a platform meeting on the team calendar (Teams), the applicant invited as a guest. */
    public function meeting(Request $request, int $id): JsonResponse
    {
        $a = $this->application($id);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $s = $this->slot($request);
        $note = mb_substr(trim((string) $request->input('note', '')), 0, 2000);
        $user = $request->user();
        $name = trim($a->first_name . ' ' . $a->last_name);

        $mid = DB::table('meetings')->insertGetId([
            'scope' => 'platform', 'agency_id' => null, 'centre_id' => null,
            'title' => 'Interview: ' . $a->role . ' with KiddieTrac (' . $name . ')',
            'description' => 'A conversation about the ' . $a->role . ' role at KiddieTrac. Application #' . $a->id . '.',
            'location' => $s['location'] !== '' ? $s['location'] : null,
            'starts_at' => $s['start']->copy()->utc(), 'ends_at' => $s['end']->copy()->utc(), 'all_day' => 0, 'timezone' => $s['tz'],
            'video' => $s['video'] === 'teams' ? 'teams' : 'none', 'organizer_id' => $user->id, 'status' => 'scheduled', 'sequence' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $m = DB::table('meetings')->where('id', $mid)->first();
        $teamsNote = null;
        if ($m->video === 'teams') {
            $t = \App\Support\Meetings::teamsCreate($m);
            if (! empty($t['url'])) {
                DB::table('meetings')->where('id', $mid)->update(['join_url' => $t['url'], 'graph_event_id' => $t['event_id'] ?? null]);
            } else {
                DB::table('meetings')->where('id', $mid)->update(['video' => 'none']);
                $teamsNote = 'The Teams link could not be made, so the invitation went without one. Add a link from Meetings if you need it.';
            }
            $m = DB::table('meetings')->where('id', $mid)->first();
        }
        $attId = DB::table('meeting_attendees')->insertGetId([
            'meeting_id' => $mid, 'user_id' => null, 'email' => $a->email, 'name' => $name, 'kind' => 'guest', 'optional' => 0,
            'response' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $att = DB::table('meeting_attendees')->where('id', $attId)->first();
        $org = DB::table('users')->where('id', $user->id)->first(['id', 'first_name', 'last_name', 'email']);
        $ics = \App\Support\Meetings::ics($m, 'REQUEST', [$att], $org);

        $ok = \App\Support\CareersMail::send('interview', $a, ['note' => $note, 'meeting' => $this->meetingOpts($m, $s['minutes'], $att)], null, $ics);
        if ($ok) DB::table('meeting_attendees')->where('id', $attId)->update(['invited_at' => now()]);
        $this->outcome($a, 'interview', 'interview', $user->id, ['meeting_id' => $mid]);
        return response()->json(['ok' => true, 'sent' => $ok, 'meeting_id' => $mid, 'when' => \App\Support\Meetings::whenText($m), 'join_url' => $m->join_url, 'note' => $teamsNote]);
    }

    /** Keep on file for future roles, KEEP_MONTHS months (kt:careers-retention removes it after). */
    public function keep(Request $request, int $id): JsonResponse
    {
        $a = $this->application($id);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $until = now(\App\Support\BookingMail::TZ)->addMonths(\App\Support\CareersMail::KEEP_MONTHS)->toDateString();
        $ok = \App\Support\CareersMail::send('on_file', $a, ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000), 'keep_until' => $until]);
        $this->outcome($a, 'on_file', 'on_file', $request->user()->id, ['keep_until' => $until]);
        return response()->json(['ok' => true, 'sent' => $ok, 'keep_until' => $until]);
    }

    /** A kind no. */
    public function decline(Request $request, int $id): JsonResponse
    {
        $a = $this->application($id);
        abort_unless(filter_var($a->email, FILTER_VALIDATE_EMAIL), 422, 'This application has no valid email address.');
        $ok = \App\Support\CareersMail::send('decline', $a, ['note' => mb_substr(trim((string) $request->input('note', '')), 0, 2000)]);
        $this->outcome($a, 'declined', 'decline', $request->user()->id, ['keep_until' => null]);
        return response()->json(['ok' => true, 'sent' => $ok]);
    }

    private function outcome(object $a, string $status, string $emailKind, ?int $userId, array $extra = []): void
    {
        DB::table('career_applications')->where('id', $a->id)->update(array_merge([
            'status' => $status, 'last_email_kind' => $emailKind, 'last_emailed_at' => now(), 'updated_at' => now(),
        ], $extra));
        if ($a->status !== $status) $this->logStatus((int) $a->id, $a->status, $status, $userId);
    }

    /** One row per status change, so time-to-review / time-to-hire can be measured (2026-11-02). */
    private function logStatus(int $id, ?string $from, string $to, ?int $userId): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('career_application_events')) return;
            DB::table('career_application_events')->insert([
                'application_id' => $id, 'from_status' => $from, 'to_status' => $to,
                'user_id' => $userId, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ── Platform → Careers → Insights (2026-11-02) ─────────────────────
    //
    // Anthony: "career section add stats, graphs, charts - useful info for me to review".
    // GET /platform/careers/stats?days=30|90|365|0&role=<exact role>   (role:platform_admin, like the list)
    // Counts come from SQL; week / weekday / hour buckets are made in PHP because they must be in the
    // AGENCY timezone (the MariaDB session is MST, not UTC, so SQL date functions would be wrong).
    // Only what the platform records: no source/referrer, no posting dates, no interview dates.

    private const STAGES = ['new', 'reviewing', 'interview', 'offer', 'hired'];

    /** The roles posted on www.kiddietrac.com/careers right now (the static page is the only source). */
    private function postings(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('kt.careers.postings.v1', 600, function () {
            $candidates = array_filter([
                env('KT_CAREERS_PAGE'),
                base_path('../../public_html/p/careers.html'),
            ]);
            $html = null;
            foreach ($candidates as $f) {
                if ($f && is_readable($f)) { $html = (string) @file_get_contents($f); break; }
            }
            if (! $html) return ['found' => false, 'items' => []];
            $out = [];
            if (preg_match_all('~<div class="card" data-dept="([a-z]+)"[^>]*?openJobApply\(\'([^\']+)\'\)"[^>]*>(.*?)</p></div>~s', $html, $m, PREG_SET_ORDER)) {
                foreach ($m as $c) {
                    $title = html_entity_decode($c[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                    if (isset($out[$title])) continue;
                    $body = $c[3];
                    $loc = preg_match('~margin-top:3px">([^<]*)</div>~', $body, $x) ? html_entity_decode(trim($x[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
                    $dept = preg_match('~badge-teal">([^<]*)</span>~', $body, $x) ? html_entity_decode(trim($x[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
                    $type = preg_match('~badge-gray">([^<]*)</span>~', $body, $x) ? html_entity_decode(trim($x[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
                    $out[$title] = ['role' => $title, 'department' => $dept ?: ucfirst($c[1]), 'location' => $loc, 'type' => $type];
                }
            }
            return ['found' => true, 'items' => array_values($out)];
        });
    }

    private static function median(array $v): ?float
    {
        if (! $v) return null;
        sort($v);
        $n = count($v);
        return $n % 2 ? (float) $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    }

    public function stats(Request $request): JsonResponse
    {
        $days = (int) $request->query('days', 90);
        if (! in_array($days, [0, 30, 90, 365], true)) $days = 90;
        $role = trim((string) $request->query('role', ''));
        $tz = \App\Support\AgencyTime::tz();
        $nowUtc = \Illuminate\Support\Carbon::now('UTC');
        $since = $days ? \App\Support\AgencyTime::boundaryUtc(null, 'day', -($days - 1)) : null;
        $weekStart = \App\Support\AgencyTime::boundaryUtc(null, 'week');
        $todayStart = \App\Support\AgencyTime::boundaryUtc(null, 'day');
        $hasEvents = \Illuminate\Support\Facades\Schema::hasTable('career_application_events');

        $base = function () use ($role) {
            $q = DB::table('career_applications');
            if ($role !== '') $q->where('role', $role);
            return $q;
        };
        $inPeriod = function () use ($base, $since) {
            $q = $base();
            if ($since) $q->where('created_at', '>=', $since);
            return $q;
        };

        $allTime = (int) $base()->count();
        $period = (int) $inPeriod()->count();
        $thisWeek = (int) $base()->where('created_at', '>=', $weekStart)->count();
        $today = (int) $base()->where('created_at', '>=', $todayStart)->count();
        $prev = null;
        if ($days) {
            $prevFrom = \App\Support\AgencyTime::boundaryUtc(null, 'day', -($days * 2 - 1));
            $prev = (int) $base()->where('created_at', '>=', $prevFrom)->where('created_at', '<', $since)->count();
        }

        $byStatus = array_fill_keys(self::STATUSES, 0);
        foreach ($inPeriod()->select('status', DB::raw('COUNT(*) AS c'))->groupBy('status')->get() as $r) {
            $byStatus[$r->status] = (int) $r->c;
        }
        $openNow = array_fill_keys(['new', 'reviewing', 'interview', 'offer'], 0);
        foreach ($base()->whereIn('status', array_keys($openNow))->select('status', DB::raw('COUNT(*) AS c'))->groupBy('status')->get() as $r) {
            $openNow[$r->status] = (int) $r->c;
        }

        $materials = $inPeriod()->selectRaw(
            "SUM(CASE WHEN resume_path IS NOT NULL THEN 1 ELSE 0 END) AS resume,
             SUM(CASE WHEN cover_path IS NOT NULL OR (cover_text IS NOT NULL AND cover_text <> '') THEN 1 ELSE 0 END) AS cover,
             SUM(CASE WHEN cover_path IS NOT NULL THEN 1 ELSE 0 END) AS cover_file,
             SUM(CASE WHEN linkedin IS NOT NULL AND linkedin <> '' THEN 1 ELSE 0 END) AS linkedin,
             SUM(CASE WHEN phone IS NOT NULL AND phone <> '' THEN 1 ELSE 0 END) AS phone,
             COUNT(DISTINCT email) AS people"
        )->first();

        // Per posting (role), with where each one stands.
        $postings = $this->postings();
        $posted = [];
        foreach ($postings['items'] as $p) $posted[$p['role']] = $p;
        $roleRows = DB::table('career_applications')->when($since, fn ($q) => $q->where('created_at', '>=', $since))
            ->select('role', DB::raw('COUNT(*) AS total'),
                DB::raw("SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS waiting"),
                DB::raw("SUM(CASE WHEN status IN ('interview','offer','hired') THEN 1 ELSE 0 END) AS interviewed"),
                DB::raw("SUM(CASE WHEN status = 'hired' THEN 1 ELSE 0 END) AS hired"),
                DB::raw("SUM(CASE WHEN status = 'declined' THEN 1 ELSE 0 END) AS declined"),
                DB::raw("SUM(CASE WHEN status = 'withdrawn' THEN 1 ELSE 0 END) AS withdrawn"),
                DB::raw('MAX(created_at) AS last_at'))
            ->groupBy('role')->orderByDesc('total')->get();
        $roles = [];
        foreach ($roleRows as $r) {
            $p = $posted[$r->role] ?? null;
            $roles[$r->role] = ['role' => $r->role, 'total' => (int) $r->total, 'waiting' => (int) $r->waiting,
                'interviewed' => (int) $r->interviewed, 'hired' => (int) $r->hired, 'declined' => (int) $r->declined,
                'withdrawn' => (int) $r->withdrawn, 'last_at' => $r->last_at, 'posted' => (bool) $p,
                'department' => $p['department'] ?? null, 'location' => $p['location'] ?? null, 'type' => $p['type'] ?? null];
        }
        $allTimeByRole = DB::table('career_applications')->select('role', DB::raw('COUNT(*) AS c'))->groupBy('role')->pluck('c', 'role')->all();
        $postingRows = [];
        foreach ($postings['items'] as $p) {
            $postingRows[] = $p + ['period' => $roles[$p['role']]['total'] ?? 0, 'all_time' => (int) ($allTimeByRole[$p['role']] ?? 0)];
        }
        $byDept = [];
        $byLocation = [];
        foreach ($roles as $r) {
            if ($role !== '' && $r['role'] !== $role) continue;
            $d = $r['department'] ?: 'Not on the site now';
            $l = $r['location'] ?: 'Not on the site now';
            $byDept[$d] = ($byDept[$d] ?? 0) + $r['total'];
            $byLocation[$l] = ($byLocation[$l] ?? 0) + $r['total'];
        }
        arsort($byDept);
        arsort($byLocation);

        // Time buckets in the agency's zone. Only the instant and status leave the database.
        $rows = $inPeriod()->orderBy('created_at')->get(['id', 'status', 'created_at', 'updated_at']);
        $first = $rows->first();
        $spanStart = $since ? $since->copy() : ($first ? \Illuminate\Support\Carbon::parse($first->created_at, 'UTC') : $nowUtc->copy());
        $spanDays = max(1, (int) $spanStart->diffInDays($nowUtc));
        $unit = $spanDays > 190 ? 'month' : 'week';
        $series = [];
        $cur = $spanStart->copy()->setTimezone($tz);
        $cur = $unit === 'month' ? $cur->startOfMonth() : $cur->startOfWeek();
        $endLocal = $nowUtc->copy()->setTimezone($tz);
        $guard = 0;
        while ($cur <= $endLocal && $guard++ < 400) {
            $series[$cur->format('Y-m-d')] = ['start' => $cur->format('Y-m-d'), 'count' => 0, 'progressed' => 0];
            $cur = $unit === 'month' ? $cur->copy()->addMonthNoOverflow() : $cur->copy()->addWeek();
        }
        $dow = array_fill(0, 7, 0);
        $hour = array_fill(0, 24, 0);
        foreach ($rows as $r) {
            $t = \Illuminate\Support\Carbon::parse($r->created_at, 'UTC')->setTimezone($tz);
            $k = ($unit === 'month' ? $t->copy()->startOfMonth() : $t->copy()->startOfWeek())->format('Y-m-d');
            if (isset($series[$k])) {
                $series[$k]['count']++;
                if (in_array($r->status, ['interview', 'offer', 'hired'], true)) $series[$k]['progressed']++;
            }
            $dow[(int) $t->format('N') - 1]++;
            $hour[(int) $t->format('G')]++;
        }

        // Funnel + response times from the status history.
        $ids = $rows->pluck('id')->all();
        $events = [];
        if ($hasEvents && $ids) {
            foreach (array_chunk($ids, 1000) as $chunk) {
                foreach (DB::table('career_application_events')->whereIn('application_id', $chunk)->orderBy('created_at')->orderBy('id')
                    ->get(['application_id', 'from_status', 'to_status', 'created_at']) as $e) {
                    $events[$e->application_id][] = $e;
                }
            }
        }
        $stageIdx = array_flip(self::STAGES);
        $reached = array_fill_keys(self::STAGES, 0);
        $firstResponse = [];
        $toHire = [];
        $toDecision = [];
        $unmeasured = 0;
        foreach ($rows as $r) {
            $max = $stageIdx[$r->status] ?? (in_array($r->status, ['declined', 'on_file'], true) ? 1 : 0);
            $created = \Illuminate\Support\Carbon::parse($r->created_at, 'UTC');
            $responded = null;
            foreach ($events[$r->id] ?? [] as $e) {
                if (isset($stageIdx[$e->to_status])) $max = max($max, $stageIdx[$e->to_status]);
                if (in_array($e->to_status, ['declined', 'on_file'], true)) $max = max($max, 1);
                if ($responded === null && $e->from_status === 'new' && $e->to_status !== 'new') {
                    $responded = \Illuminate\Support\Carbon::parse($e->created_at, 'UTC');
                    $firstResponse[] = max(0, $created->diffInMinutes($responded)) / 60;
                }
                if ($e->to_status === 'hired' && $e->from_status !== 'hired') $toHire[] = max(0, $created->diffInMinutes(\Illuminate\Support\Carbon::parse($e->created_at, 'UTC'))) / 1440;
                if (in_array($e->to_status, ['hired', 'declined'], true)) $toDecision[$r->id] = max(0, $created->diffInMinutes(\Illuminate\Support\Carbon::parse($e->created_at, 'UTC'))) / 1440;
            }
            if ($responded === null && $r->status !== 'new') $unmeasured++;
            for ($i = 0; $i <= $max; $i++) $reached[self::STAGES[$i]]++;
        }

        // Waiting on us: never opened (status new), and in-progress ones with no change for 7+ days.
        $stale = $base()->where(function ($q) use ($nowUtc) {
            $q->where('status', 'new')
              ->orWhere(function ($q2) use ($nowUtc) {
                  $q2->whereIn('status', ['reviewing', 'interview', 'offer'])->where('updated_at', '<', $nowUtc->copy()->subDays(7));
              });
        })->orderBy('created_at')->limit(50)->get(['id', 'role', 'first_name', 'last_name', 'status', 'created_at', 'updated_at']);
        $staleRows = $stale->map(function ($a) use ($nowUtc) {
            $c = \Illuminate\Support\Carbon::parse($a->created_at, 'UTC');
            $u = \Illuminate\Support\Carbon::parse($a->updated_at ?: $a->created_at, 'UTC');
            return ['id' => $a->id, 'role' => $a->role, 'name' => trim($a->first_name . ' ' . $a->last_name), 'status' => $a->status,
                'created_at' => $a->created_at, 'age_days' => (int) floor($c->diffInHours($nowUtc) / 24),
                'idle_days' => (int) floor($u->diffInHours($nowUtc) / 24)];
        })->all();

        $round = fn ($v, $p = 1) => $v === null ? null : round($v, $p);
        $tracked = $hasEvents ? '2026-11-02' : null; // status changes are recorded from this day on

        return response()->json([
            'days' => $days, 'role' => $role, 'timezone' => $tz, 'unit' => $unit,
            'generated_at' => $nowUtc->toIso8601String(),
            'kpi' => [
                'open_postings' => $postings['found'] ? count($postings['items']) : null,
                'period' => $period, 'previous_period' => $prev, 'all_time' => $allTime,
                'this_week' => $thisWeek, 'today' => $today,
                'awaiting_review' => $openNow['new'], 'in_progress' => $openNow['reviewing'] + $openNow['interview'] + $openNow['offer'],
                'open_now' => $openNow,
                'hired' => $byStatus['hired'], 'declined' => $byStatus['declined'], 'withdrawn' => $byStatus['withdrawn'],
                'people' => (int) ($materials->people ?? 0),
                'first_response_hours_median' => $round(self::median($firstResponse)),
                'first_response_hours_avg' => $firstResponse ? $round(array_sum($firstResponse) / count($firstResponse)) : null,
                'first_response_n' => count($firstResponse), 'first_response_unmeasured' => $unmeasured,
                'days_to_hire_median' => $round(self::median($toHire)), 'days_to_hire_n' => count($toHire),
                'days_to_decision_median' => $round(self::median(array_values($toDecision))), 'days_to_decision_n' => count($toDecision),
            ],
            'by_status' => $byStatus,
            'funnel' => array_map(fn ($s) => ['stage' => $s, 'count' => $reached[$s]], self::STAGES),
            'materials' => [
                'resume' => (int) ($materials->resume ?? 0), 'cover' => (int) ($materials->cover ?? 0),
                'cover_file' => (int) ($materials->cover_file ?? 0), 'linkedin' => (int) ($materials->linkedin ?? 0),
                'phone' => (int) ($materials->phone ?? 0),
            ],
            'series' => array_values($series),
            'by_weekday' => $dow, 'by_hour' => $hour,
            'by_role' => array_values(array_filter($roles, fn ($r) => $role === '' || $r['role'] === $role)),
            'by_department' => array_map(fn ($k, $v) => ['label' => $k, 'count' => $v], array_keys($byDept), $byDept),
            'by_location' => array_map(fn ($k, $v) => ['label' => $k, 'count' => $v], array_keys($byLocation), $byLocation),
            'postings' => $postingRows, 'postings_found' => $postings['found'],
            'roles_all' => array_values(array_unique(array_merge(array_keys($allTimeByRole), array_column($postings['items'], 'role')))),
            'stale' => $staleRows,
            'history_since' => $tracked,
            'not_tracked' => ['Where applicants heard about the job (no source or referrer is recorded)', 'When each posting went up', 'Interview dates'],
        ]);
    }
}
