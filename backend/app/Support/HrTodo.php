<?php

declare(strict_types=1);

namespace App\Support;

use App\Http\Controllers\Api\HrFileController;
use App\Http\Controllers\Api\HrOnboardingController;
use App\Http\Controllers\Api\HrPunchFixController;
use App\Http\Controllers\Api\HrRotaController;
use App\Http\Controllers\Api\HrSafetyController;
use App\Http\Controllers\Api\ManagedFormController;
use App\Http\Controllers\Api\PolicyController;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * HR Phase 4 batch 10 (2026-11-27): ONE "My HR to-do" for a person, from every part of HR.
 *
 * Each source is read through the SAME endpoint method the person's own My HR section already uses
 * (HrOnboardingController::mine, HrFileController::mine, ManagedFormController::assigned, PolicyController::mine,
 * HrRotaController::mine, HrPunchFixController::mine, HrSafetyController::mine), with the person's own request.
 * So the to-do can never show something their section would not: the reader and its guard are one and the same
 * (active agency, staffIds = no outsiders, the person's own rows only). A source that refuses (403/404: not staff
 * here, feature off) simply adds nothing.
 *
 * Items: {key, kind, icon, label, title, due (Y-m-d|null), overdue, hash (screen to open) | section (My HR heading)}.
 * Sorted: overdue first, then by due date, undated last.
 *
 * The starter path (starter()) is a short guided checklist per role, computed live from the agency's data, each step
 * pointing at its screen and at the help article that explains it.
 */
final class HrTodo
{
    public const SECTIONS = ['onboarding', 'courses', 'reviews', 'documents', 'shifts', 'punchfix', 'hstasks', 'duties', 'availability', 'directory'];

    public static function items(Request $request, int $aid, int $me): array
    {
        $today = AgencyTime::today($aid);
        $out = [];
        $add = function (string $kind, string $icon, string $label, string $title, ?string $due, array $go) use (&$out, $today) {
            $due = $due ? substr($due, 0, 10) : null;
            $out[] = ['key' => $kind . ':' . count($out), 'kind' => $kind, 'icon' => $icon, 'label' => $label, 'title' => $title, 'due' => $due,
                'overdue' => $due !== null && $due < $today, 'hash' => $go['hash'] ?? null, 'section' => $go['section'] ?? null];
        };

        // Onboarding steps, courses (quizzes included) and reviews.
        if ($t = self::read(fn () => app(HrOnboardingController::class)->mine($request))) {
            foreach ((array) ($t['onboarding'] ?? []) as $o) {
                if (($o['status'] ?? '') !== 'active') continue;
                foreach ((array) ($o['tasks'] ?? []) as $k) {
                    if (empty($k['done'])) $add('onboarding', '🧭', 'Onboarding step', (string) $k['title'], $k['due_date'] ?? null, ['section' => 'onboarding']);
                }
            }
            foreach ((array) ($t['courses'] ?? []) as $c) {
                if (empty($c['completed_at'])) $add('course', '🎓', 'Course to finish', (string) $c['title'], $c['due_date'] ?? null, ['section' => 'courses']);
            }
            $ids = array_map(fn ($r) => (int) $r['id'], (array) ($t['reviews'] ?? []));
            $signedByMe = $ids ? DB::table('hr_reviews')->whereIn('id', $ids)->where('user_id', $me)->whereNotNull('employee_signed_at')->pluck('id')->map(fn ($v) => (int) $v)->all() : [];
            foreach ((array) ($t['reviews'] ?? []) as $r) {
                $st = (string) ($r['status'] ?? '');
                if ($st === 'open' || $st === 'manager_done') $add('review', '⭐', 'Your part of a review', (string) $r['title'], $r['due_date'] ?? null, ['section' => 'reviews']);
                elseif ($st === 'signing' && ! in_array((int) $r['id'], $signedByMe, true)) $add('review_sign', '⭐', 'Review to sign', (string) $r['title'], $r['due_date'] ?? null, ['section' => 'reviews']);
            }
        }

        // Company documents to read or accept.
        if ($d = self::read(fn () => app(HrFileController::class)->mine($request))) {
            foreach ((array) ($d['company'] ?? []) as $f) {
                foreach ((array) ($f['documents'] ?? []) as $doc) {
                    $title = (string) ($doc['title'] ?? $doc['name'] ?? 'Document');
                    if (! empty($doc['requires_accept']) && empty($doc['accepted_at'])) $add('doc_accept', '📂', 'Document to read and accept', $title, null, ['section' => 'documents']);
                    elseif (empty($doc['read_at'])) $add('doc_read', '📂', 'Document to read', $title, null, ['section' => 'documents']);
                }
            }
        }

        // Forms to sign: HR letters, tax forms (TD1) and other one-off forms. A staff daily chart (reusable) is not a to-do.
        if ($m = self::read(fn () => app(ManagedFormController::class)->assigned($request))) {
            $forms = array_values(array_filter((array) ($m['forms'] ?? []), fn ($f) => empty($f['reusable']) || ! empty($f['returned_at'])));
            $ids = array_map(fn ($f) => (int) $f['id'], $forms);
            $letters = $ids ? DB::table('hr_letters')->whereIn('managed_form_id', $ids)->pluck('managed_form_id')->map(fn ($v) => (int) $v)->all() : [];
            $tax = ($ids && Schema::hasTable('payroll_tax_forms')) ? DB::table('payroll_tax_forms')->where('user_id', $me)->whereIn('managed_form_id', $ids)->pluck('managed_form_id')->map(fn ($v) => (int) $v)->all() : [];
            foreach ($forms as $f) {
                $id = (int) $f['id'];
                $label = in_array($id, $tax, true) ? 'Tax form to sign' : (in_array($id, $letters, true) ? 'Letter to sign' : (! empty($f['returned_at']) ? 'Form sent back to you' : 'Form to sign'));
                $add('form', '✍️', $label, (string) ($f['title'] ?? 'Form'), null, ['hash' => 'my-forms']);
            }
        }

        // Policies and safe work procedures to sign.
        if ($p = self::read(fn () => app(PolicyController::class)->mine($request))) {
            foreach ((array) ($p['outstanding'] ?? []) as $x) {
                $swp = ($x['category'] ?? '') === 'Safe work procedure';
                $add('policy', '📘', $swp ? 'Safe work procedure to accept' : 'Policy to sign', (string) $x['title'], null, ['hash' => 'my-policies']);
            }
        }

        // Shifts the rota asks them to accept.
        if ($s = self::read(fn () => app(HrRotaController::class)->mine($request))) {
            foreach ((array) ($s['shifts'] ?? []) as $x) {
                $add('shift', '✅', 'Shift to accept', trim(self::day((string) $x['date']) . ', ' . $x['start'] . '–' . $x['end'] . ' · ' . ($x['centre'] ?? '')), (string) $x['date'], ['section' => 'shifts']);
            }
        }

        // A clock-out missing from an earlier day, not already asked about and not inside a locked timesheet.
        if ($c = self::read(fn () => app(HrPunchFixController::class)->mine($request))) {
            foreach ((array) ($c['punches'] ?? []) as $x) {
                $date = (string) ($x['in']['date'] ?? '');   // local {date, time, label}
                if (! empty($x['out']) || ! empty($x['pending']) || ! empty($x['locked']) || ! empty($x['auto']) || $date === '' || $date >= $today) continue;
                $add('punch', '🕒', 'Clock-out missing', self::day($date) . ' · ' . ($x['centre'] ?? ''), null, ['section' => 'punchfix']);
            }
        }

        // H&S tasks given to them, and a certificate their H&S duty needs.
        if ($h = self::read(fn () => app(HrSafetyController::class)->mine($request))) {
            foreach ((array) ($h['tasks'] ?? []) as $x) {
                if (! in_array($x['status'] ?? '', ['open', 'in_progress'], true)) continue;
                $add('hs_task', '🧰', 'H&S task', (string) ($x['title'] ?? 'Task'), $x['due_date'] ?? null, ['section' => 'hstasks']);
            }
            foreach ((array) ($h['duties'] ?? []) as $x) {
                $st = $x['cert']['state'] ?? 'none';
                if (empty($x['active']) || ! in_array($st, ['missing', 'expired', 'soon'], true)) continue;
                $what = trim((string) (($x['cert']['label'] ?? '') ?: 'certificate'));
                $add('duty_cert', '🧯', $st === 'missing' ? 'Certificate needed' : 'Certificate to renew', $what . ' · ' . (string) ($x['duty_label'] ?? ''), $x['cert']['expires'] ?? null, ['section' => 'duties']);
            }
        }

        // Their own certificates running out in the next 30 days (the newest of each kind), or already out.
        $soon = Carbon::parse($today)->addDays(30)->toDateString();
        $certs = DB::table('staff_certifications')->where('user_id', $me)->where('agency_id', $aid)->where('active', 1)->get(['cert_type', 'title', 'expires_at']);
        $best = [];
        foreach ($certs as $x) {
            $k = (string) ($x->cert_type ?: $x->title);
            $exp = $x->expires_at ? substr((string) $x->expires_at, 0, 10) : '9999-12-31';
            if (! isset($best[$k]) || $exp > $best[$k][0]) $best[$k] = [$exp, (string) ($x->title ?: $x->cert_type)];
        }
        foreach ($best as [$exp, $title]) {
            if ($exp <= $soon) $add('cert', '❤️', $exp < $today ? 'Certificate expired' : 'Certificate to renew', $title, $exp, ['hash' => 'my-certificates']);
        }

        usort($out, function ($a, $b) {
            if ($a['overdue'] !== $b['overdue']) return $a['overdue'] ? -1 : 1;
            if (($a['due'] === null) !== ($b['due'] === null)) return $a['due'] === null ? 1 : -1;

            return strcmp((string) $a['due'], (string) $b['due']);
        });

        return $out;
    }

    /** A source endpoint's JSON as an array, or null when it refuses or fails (it then adds nothing). */
    private static function read(callable $fn): ?array
    {
        try {
            $r = $fn();
            if (! $r || ! method_exists($r, 'getData') || $r->getStatusCode() >= 400) return null;

            return (array) $r->getData(true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private static function day(string $ymd): string
    {
        try { return Carbon::parse($ymd)->format('D M j'); } catch (\Throwable $e) { return $ymd; }
    }

    // ── starter paths ───────────────────────────────────────────────────────────────────────

    public const PATHS = ['admin', 'director', 'educator', 'home_visitor'];

    /**
     * The person's starter path: {path, steps:[{key,label,detail,done,hash,help}], done, total}.
     * $scope = CentreScope ids for a director (null for an admin). Steps are live checks; nothing is stored but "hidden".
     */
    public static function starter(Request $request, int $aid, int $me, string $path, ?array $scope): array
    {
        $today = AgencyTime::today($aid);
        $viewed = fn (array $slugs) => DB::table('help_article_views')->where('user_id', $me)->whereIn('slug', $slugs)->exists();
        $steps = [];
        $step = function (string $key, string $label, string $detail, bool $done, string $hash, string $help) use (&$steps) {
            $steps[] = compact('key', 'label', 'detail', 'done', 'hash', 'help');
        };

        if ($path === 'admin' || $path === 'director') {
            $c = HrReports::checklist($aid, $scope, false, $path === 'admin', $today);
            $ck = [];
            foreach ($c['items'] as $i) $ck[$i['key']] = $i;
            if ($path === 'admin') {
                $step('types', 'Add your leave types and how much people get', $ck['types']['detail'] . ', ' . $ck['rules']['detail'], $ck['types']['done'] && $ck['rules']['done'], 'time-off?tab=types', 'hr-leave-types');
                $step('files', 'Complete everyone\'s HR file', $ck['files']['detail'], $ck['files']['done'], 'hr-reports?report=missing', 'hr-reports');
                $step('approvers', 'Give everyone a manager who approves their requests', $ck['approvers']['detail'], $ck['approvers']['done'], 'hr-people', 'hr-people-recognition');
                $set = json_decode((string) DB::table('agencies')->where('id', $aid)->value('settings'), true) ?: [];
                $rules = isset($set['hr_attendance']) || isset($set['hr_time']);
                $step('rules', 'Choose your attendance and overtime rules', $rules ? 'Saved' : 'Lateness grace, absence score, overtime rate', $rules, 'hr-settings', 'hr-attendance');
                $docs = DB::table('hr_documents')->where('agency_id', $aid)->whereNull('user_id')->whereNull('archived_at')->count();
                $step('docs', 'Share your handbook and company documents', $docs . ' company document' . ($docs === 1 ? '' : 's'), $docs > 0, 'hr-documents', 'hr-documents-leaving');
                $step('invited', 'Invite your staff and have them sign in', $ck['invited']['detail'], $ck['invited']['done'], 'hr-reports?report=invites', 'hr-reports');
                $step('guide', 'Read the HR guide', 'Five minutes: what each part of Human Resources does', $viewed(['hr']), 'help/hr', 'hr');
            } else {
                $step('files', 'Check your team\'s HR files', $ck['files']['detail'], $ck['files']['done'], 'hr-reports?report=missing', 'hr-reports');
                [$people] = HrReports::population($aid, $scope);
                $people = array_values(array_diff($people, [$me]));
                $pend = $people ? DB::table('time_off_requests')->whereIn('user_id', $people)->where('status', 'pending')->count() : 0;
                $step('timeoff', 'Answer the time-off requests waiting for you', $pend ? $pend . ' waiting' : 'Nothing waiting', $pend === 0, 'time-off', 'time-off');
                $mon = Carbon::parse($today)->startOfWeek(Carbon::MONDAY)->addWeek();
                $next = $scope ? DB::table('shifts as s')->join('rooms as r', 'r.id', '=', 's.room_id')->whereIn('r.centre_id', $scope)->where('s.status', '!=', 'cancelled')
                    ->where('s.starts_at', '>=', $mon->toDateString())->where('s.starts_at', '<', $mon->copy()->addWeek()->toDateString())->count() : 0;
                $step('rota', 'Put next week\'s shifts on the rota', $next . ' shift' . ($next === 1 ? '' : 's') . ' next week', $next > 0, 'schedule', 'hr-rota-publish');
                $locked = $scope ? DB::table('hr_timesheet_locks')->where('agency_id', $aid)->whereIn('centre_id', $scope)->where('status', 'locked')
                    ->where('period_end', '>=', Carbon::parse($today)->subDays(45)->toDateString())->exists() : false;
                $step('lock', 'Approve and lock a pay period\'s timesheets', $locked ? 'Done this month' : 'None approved in the last 45 days', $locked, 'timesheets?tab=lock', 'hr-overtime-timesheets');
                $mine = count(self::items($request, $aid, $me));
                $step('own', 'Clear your own My HR to-do', $mine ? $mine . ' waiting' : 'Nothing waiting', $mine === 0, 'my-hr', 'my-hr');
                $step('guide', 'Read the HR guide', 'What each part of Human Resources does', $viewed(['hr']), 'help/hr', 'hr');
            }
        } else {
            $pr = DB::table('user_profiles')->where('user_id', $me)->first(['emergency_contact_name', 'emergency_contact_phone']);
            $ec = $pr && trim((string) $pr->emergency_contact_name) !== '' && trim((string) $pr->emergency_contact_phone) !== '';
            $step('contact', 'Add your emergency contact', $ec ? 'On file' : 'Who we call if something happens to you at work', $ec, 'settings', 'my-hr');
            $pol = count(PolicyLibrary::outstandingFor($aid, $me));
            $step('policies', 'Read and sign your policies', $pol ? $pol . ' to sign' : 'All signed', $pol === 0, 'my-policies', 'policies-to-review');
            $certs = DB::table('staff_certifications')->where('user_id', $me)->where('agency_id', $aid)->where('active', 1)->count();
            $step('certs', 'Add your certificates (first aid, CPR, ...)', $certs . ' on file', $certs > 0, 'my-certificates', 'my-hr');
            if ($path === 'educator') {
                $av = DB::table('hr_availability')->where('agency_id', $aid)->where('user_id', $me)->exists();
                $step('availability', 'Tell us when you can work', $av ? 'Availability set' : 'Your usual days and hours, for the rota', $av, 'my-hr?go=availability', 'my-hr');
                $to = DB::table('time_off_requests')->where('user_id', $me)->exists() || $viewed(['requesting-time-off', 'leave-balances']);
                $step('timeoff', 'See your leave balances and how to ask for time off', $to ? 'Done' : 'Two minutes in the guide', $to, 'help/requesting-time-off', 'requesting-time-off');
            }
            $sh = DB::table('hr_directory_shares')->where('agency_id', $aid)->where('user_id', $me)->exists();
            $step('directory', 'Choose what the team directory shows about you', $sh ? 'Chosen' : 'Nothing is shared until you choose', $sh, 'my-hr?go=directory', 'my-hr');
            $mine = count(self::items($request, $aid, $me));
            $step('todo', 'Clear your My HR to-do', $mine ? $mine . ' waiting' : 'Nothing waiting', $mine === 0, 'my-hr', 'my-hr');
        }
        $done = count(array_filter($steps, fn ($s) => $s['done']));

        return ['path' => $path, 'steps' => $steps, 'done' => $done, 'total' => count($steps)];
    }
}
