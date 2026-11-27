<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KIDDIETRAC HR, PHASE 2 (2026-11-28): the shared rules of hiring, onboarding, performance and courses.
 *
 * ONBOARDING TICKS ITSELF OFF from the real thing, read live (sync()): a policy task is done when the person has
 * acknowledged that policy's current version; a certificate task when an active, unexpired certificate of that type
 * is on their record; a course task when the course assignment is complete; a document task when a file was
 * uploaded to it. Custom tasks are ticked by the person or a manager. sync() runs on every read and every night.
 *
 * WHO IS NAMED: managers, reviewers and certificate signers are the agency's own people only (AgencySignatories):
 * never a super admin, integration account or reseller grant, even one holding agency_admin there.
 */
final class HrTalent
{
    public const KINDS = ['document' => 'Upload a document', 'certificate' => 'Certificate on file', 'policy' => 'Sign a policy',
        'course' => 'Finish a course', 'custom' => 'Other task'];
    public const ROLES = ['educator' => 'Educators', 'centre_director' => 'Centre directors', 'home_visitor' => 'Home visitors', 'agency_admin' => 'Agency admins'];
    public const STAGES = ['new' => 'New', 'reviewing' => 'Screening', 'interview' => 'Interview', 'offer' => 'Offer', 'hired' => 'Hired',
        'declined' => 'Declined', 'on_file' => 'On file', 'withdrawn' => 'Withdrawn'];
    public const CYCLES = ['probation' => 'Probation', 'annual' => 'Annual', 'custom' => 'Custom'];
    public const GOAL_STATUS = ['not_started' => 'Not started', 'on_track' => 'On track', 'at_risk' => 'At risk', 'done' => 'Done', 'dropped' => 'Dropped'];
    public const COURSE_KINDS = ['link' => 'A link', 'video' => 'A video link', 'file' => 'An uploaded file', 'library' => 'A KiddieTrac training video'];

    /** declined_days: delete declined applicants after N days (hr:declined-purge); null = Never. */
    public const DEFAULTS = ['keep_months' => 24, 'reply_to' => null, 'apply_intro' => null, 'declined_days' => 90];

    public const DEFAULT_SCALE = [['value' => 1, 'label' => 'Needs improvement'], ['value' => 2, 'label' => 'Developing'],
        ['value' => 3, 'label' => 'Meets expectations'], ['value' => 4, 'label' => 'Exceeds expectations'], ['value' => 5, 'label' => 'Outstanding']];
    public const DEFAULT_SECTIONS = [
        ['title' => 'Care and safety of children', 'prompt' => 'Supervision, health and safety routines, responding to children\'s needs.'],
        ['title' => 'Programming and learning', 'prompt' => 'Planning, observation and documentation, following children\'s interests.'],
        ['title' => 'Families and communication', 'prompt' => 'Daily communication with families, warmth, follow-through.'],
        ['title' => 'Teamwork and professionalism', 'prompt' => 'Reliability, working with the team, policies and professional learning.'],
    ];

    // ── settings (agencies.settings.hr_talent, separate from Phase 1's settings.hr) ─────────────────

    public static function settings(int $agencyId): array
    {
        $raw = json_decode((string) DB::table('agencies')->where('id', $agencyId)->value('settings'), true) ?: [];
        $s = is_array($raw['hr_talent'] ?? null) ? $raw['hr_talent'] : [];
        $out = array_replace(self::DEFAULTS, array_intersect_key($s, self::DEFAULTS));
        $out['keep_months'] = max(1, min(60, (int) $out['keep_months']));
        $out['reply_to'] = filter_var((string) $out['reply_to'], FILTER_VALIDATE_EMAIL) ? (string) $out['reply_to'] : null;
        $out['declined_days'] = $out['declined_days'] === null ? null : max(1, min(3650, (int) $out['declined_days']));

        return $out;
    }

    public static function saveSettings(int $agencyId, array $s): array
    {
        DB::transaction(function () use ($agencyId, $s) {
            $row = DB::table('agencies')->where('id', $agencyId)->lockForUpdate()->first(['settings']);
            $all = json_decode((string) ($row->settings ?? ''), true) ?: [];
            $all['hr_talent'] = array_intersect_key($s, self::DEFAULTS);
            DB::table('agencies')->where('id', $agencyId)->update(['settings' => json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        });

        return self::settings($agencyId);
    }

    // ── people ────────────────────────────────────────────────────────────────────────────────

    /** Admins and directors of the agency who may manage, review or sign (never an outsider). @return int[] */
    public static function managerIds(int $agencyId): array
    {
        $staff = HrLeave::staffIds($agencyId);
        $ids = DB::table('role_assignments')->whereIn('user_id', $staff ?: [0])->where('active', 1)
            ->whereIn('role', ['agency_admin', 'centre_director'])->distinct()->pluck('user_id')->map(fn ($v) => (int) $v)->all();
        // A leaver (HR file Terminated) is never offered as anyone's manager or approver (HR Phase 4, 2026-11-27).
        $gone = DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('status', 'terminated')->pluck('user_id')->map(fn ($v) => (int) $v)->all();

        return array_values(array_diff($ids, AgencySignatories::outsiderIds(), $gone));
    }

    /** Who hears about this person's overdue HR tasks: their file manager, else their centres' directors, else the agency admins. */
    public static function managersOf(int $agencyId, int $userId): array
    {
        $out = [];
        $mgr = (int) DB::table('hr_employee_files')->where('agency_id', $agencyId)->where('user_id', $userId)->value('manager_user_id');
        $valid = self::managerIds($agencyId);
        if ($mgr && in_array($mgr, $valid, true)) {
            $out = [$mgr];
        } else {
            $centres = CentreScope::centresOfUser($userId, $agencyId);
            $out = CentreScope::managers($agencyId, $centres)->where('ra.role', $centres ? 'centre_director' : 'agency_admin')
                ->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            if (! $out) {
                $out = CentreScope::managers($agencyId, [])->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            }
        }

        return array_values(array_diff(array_intersect($out, $valid), [$userId]));
    }

    // ── onboarding ────────────────────────────────────────────────────────────────────────────

    /** Start onboarding from a template (or an empty checklist). Course tasks assign the course. Returns the onboarding id. */
    public static function startOnboarding(int $agencyId, int $userId, ?int $templateId, string $startDate, ?int $actorId, ?int $applicationId = null): int
    {
        return (int) DB::transaction(function () use ($agencyId, $userId, $templateId, $startDate, $actorId, $applicationId) {
            $oid = (int) DB::table('hr_onboardings')->insertGetId(['agency_id' => $agencyId, 'user_id' => $userId, 'template_id' => $templateId,
                'application_id' => $applicationId, 'start_date' => $startDate, 'status' => 'active', 'created_by_id' => $actorId,
                'created_at' => now(), 'updated_at' => now()]);
            if ($templateId) {
                $tasks = DB::table('hr_onboarding_template_tasks')->where('template_id', $templateId)->where('agency_id', $agencyId)->orderBy('sort')->orderBy('id')->get();
                foreach ($tasks as $i => $t) {
                    self::addTask($agencyId, $oid, $userId, (array) $t, $startDate, $i, $actorId);
                }
            }
            return $oid;
        });
    }

    public static function addTask(int $agencyId, int $onboardingId, int $userId, array $t, string $startDate, int $sort, ?int $actorId): int
    {
        $due = Carbon::parse($startDate)->addDays((int) ($t['due_days'] ?? 0))->toDateString();
        $id = (int) DB::table('hr_onboarding_tasks')->insertGetId(['agency_id' => $agencyId, 'onboarding_id' => $onboardingId, 'user_id' => $userId,
            'kind' => $t['kind'], 'title' => $t['title'], 'detail' => $t['detail'] ?? null, 'ref_id' => $t['ref_id'] ?? null, 'ref_key' => $t['ref_key'] ?? null,
            'due_date' => $due, 'sort' => $sort, 'created_at' => now(), 'updated_at' => now()]);
        if ($t['kind'] === 'course' && ! empty($t['ref_id'])) {
            self::assignCourse($agencyId, (int) $t['ref_id'], $userId, $due, $actorId);
        }

        return $id;
    }

    /** Tick every open task whose real thing is done. Returns the number ticked. */
    public static function sync(int $agencyId, int $userId): int
    {
        $open = DB::table('hr_onboarding_tasks as t')->join('hr_onboardings as o', 'o.id', '=', 't.onboarding_id')
            ->where('t.agency_id', $agencyId)->where('t.user_id', $userId)->where('o.status', 'active')->whereNull('t.done_at')
            ->whereIn('t.kind', ['policy', 'certificate', 'course'])->get(['t.*']);
        $n = 0;
        $today = AgencyTime::today($agencyId);
        foreach ($open as $t) {
            $done = false;
            if ($t->kind === 'policy' && $t->ref_id) {
                $ver = DB::table('policies')->where('id', $t->ref_id)->where('agency_id', $agencyId)->value('current_version_id');
                $done = DB::table('policy_acknowledgements')->where('agency_id', $agencyId)->where('user_id', $userId)->where('policy_id', $t->ref_id)
                    ->when($ver, fn ($q) => $q->where('policy_version_id', $ver))->whereNotNull('acknowledged_at')->exists();
            } elseif ($t->kind === 'certificate' && $t->ref_key) {
                $done = DB::table('staff_certifications')->where('user_id', $userId)->where('active', 1)->where('cert_type', $t->ref_key)
                    ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))
                    ->where(fn ($w) => $w->whereNull('expires_at')->orWhere('expires_at', '>=', $today))->exists();
            } elseif ($t->kind === 'course' && $t->ref_id) {
                $done = DB::table('hr_course_assignments')->where('agency_id', $agencyId)->where('user_id', $userId)->where('course_id', $t->ref_id)
                    ->whereNotNull('completed_at')->exists();
            }
            if ($done) {
                DB::table('hr_onboarding_tasks')->where('id', $t->id)->whereNull('done_at')->update(['done_at' => now(), 'done_how' => 'auto', 'updated_at' => now()]);
                $n++;
            }
        }
        self::closeFinished($agencyId, $userId);

        return $n;
    }

    /** An onboarding whose every task is done is complete. */
    public static function closeFinished(int $agencyId, int $userId): void
    {
        foreach (DB::table('hr_onboardings')->where('agency_id', $agencyId)->where('user_id', $userId)->where('status', 'active')->pluck('id') as $oid) {
            $left = DB::table('hr_onboarding_tasks')->where('onboarding_id', $oid)->whereNull('done_at')->count();
            $all = DB::table('hr_onboarding_tasks')->where('onboarding_id', $oid)->count();
            if ($all > 0 && $left === 0) {
                DB::table('hr_onboardings')->where('id', $oid)->update(['status' => 'complete', 'completed_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    /** One onboarding with its tasks, shaped for the screen. */
    public static function shapeOnboarding(object $o): array
    {
        $tasks = DB::table('hr_onboarding_tasks')->where('onboarding_id', $o->id)->orderBy('sort')->orderBy('id')->get();
        $today = AgencyTime::today((int) $o->agency_id);
        $by = DB::table('users')->whereIn('id', $tasks->pluck('done_by_id')->filter()->all() ?: [0])->pluck('first_name', 'id');
        $done = $tasks->whereNotNull('done_at')->count();

        return ['id' => (int) $o->id, 'user_id' => (int) $o->user_id, 'start_date' => substr((string) $o->start_date, 0, 10), 'status' => $o->status,
            'template' => $o->template_id ? DB::table('hr_onboarding_templates')->where('id', $o->template_id)->value('name') : null,
            'done' => $done, 'total' => $tasks->count(), 'percent' => $tasks->count() ? (int) round($done * 100 / $tasks->count()) : 0,
            'overdue' => $tasks->filter(fn ($t) => ! $t->done_at && $t->due_date && substr((string) $t->due_date, 0, 10) < $today)->count(),
            'tasks' => $tasks->map(fn ($t) => ['id' => (int) $t->id, 'kind' => $t->kind, 'title' => $t->title, 'detail' => $t->detail,
                'ref_id' => $t->ref_id ? (int) $t->ref_id : null, 'ref_key' => $t->ref_key, 'due_date' => $t->due_date ? substr((string) $t->due_date, 0, 10) : null,
                'done' => (bool) $t->done_at, 'done_at' => $t->done_at, 'done_how' => $t->done_how, 'done_by' => $t->done_by_id ? ($by[$t->done_by_id] ?? null) : null,
                'has_file' => (bool) $t->file_path, 'file_name' => $t->file_name,
                'overdue' => ! $t->done_at && $t->due_date && substr((string) $t->due_date, 0, 10) < $today])->values()->all()];
    }

    // ── courses ───────────────────────────────────────────────────────────────────────────────

    /** Assign a course (once per person; a later assignment only moves an earlier due date). */
    public static function assignCourse(int $agencyId, int $courseId, int $userId, ?string $due, ?int $actorId, ?string $role = null): bool
    {
        $row = DB::table('hr_course_assignments')->where('course_id', $courseId)->where('user_id', $userId)->first();
        if ($row) {
            if ($due && ! $row->completed_at && (! $row->due_date || $due < substr((string) $row->due_date, 0, 10))) {
                DB::table('hr_course_assignments')->where('id', $row->id)->update(['due_date' => $due, 'updated_at' => now()]);
            }
            return false;
        }
        DB::table('hr_course_assignments')->insert(['agency_id' => $agencyId, 'course_id' => $courseId, 'user_id' => $userId, 'assigned_role' => $role,
            'due_date' => $due, 'assigned_by_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** Mark an assignment complete: certificate number, optional PD hours, onboarding ticks. */
    public static function completeCourse(int $agencyId, object $a, ?string $ackName, int $actorId): array
    {
        $course = DB::table('hr_courses')->where('id', $a->course_id)->where('agency_id', $agencyId)->first();
        abort_unless($course, 404, 'That course was not found.');
        $out = DB::transaction(function () use ($agencyId, $a, $ackName, $course, $actorId) {
            $fresh = DB::table('hr_course_assignments')->where('id', $a->id)->lockForUpdate()->first();
            if ($fresh->completed_at) {
                return ['certificate_no' => $fresh->certificate_no, 'pd' => (bool) $fresh->pd_hours_id];
            }
            $pfx = RefCode::prefix($agencyId) ?: 'KT';
            $last = DB::table('hr_course_assignments')->where('agency_id', $agencyId)->whereNotNull('certificate_no')
                ->where('certificate_no', 'like', $pfx . '-CRT-%')->lockForUpdate()->pluck('certificate_no')
                ->map(fn ($c) => (int) substr((string) strrchr((string) $c, '-'), 1))->max() ?: 0;
            $no = $pfx . '-CRT-' . str_pad((string) ($last + 1), 4, '0', STR_PAD_LEFT);
            $pdId = null;
            if ($course->counts_pd && (float) $course->pd_hours > 0 && \Illuminate\Support\Facades\Schema::hasTable('pd_hours')) {
                $pdId = (int) DB::table('pd_hours')->insertGetId(['agency_id' => $agencyId, 'user_id' => $a->user_id, 'taken_on' => AgencyTime::today($agencyId),
                    'title' => mb_substr('Course: ' . $course->title, 0, 190), 'provider' => mb_substr((string) DB::table('agencies')->where('id', $agencyId)->value('name'), 0, 120),
                    'hours' => (float) $course->pd_hours, 'status' => 'approved', 'reviewed_by' => null, 'reviewed_at' => now(), 'created_by' => $actorId,
                    'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('hr_course_assignments')->where('id', $a->id)->update(['completed_at' => now(), 'acknowledged_name' => $ackName,
                'certificate_no' => $no, 'pd_hours_id' => $pdId, 'updated_at' => now()]);

            return ['certificate_no' => $no, 'pd' => (bool) $pdId];
        });
        self::sync($agencyId, (int) $a->user_id);

        return $out;
    }

    // ── performance ───────────────────────────────────────────────────────────────────────────

    public static function reviewStatus(object $r): string
    {
        if ($r->status === 'cancelled') return 'cancelled';
        if ($r->employee_signed_at && $r->manager_signed_at) return 'complete';
        if ($r->manager_submitted_at && $r->self_submitted_at) return 'signing';
        if ($r->manager_submitted_at) return 'manager_done';
        if ($r->self_submitted_at) return 'self_done';

        return 'open';
    }

    public static function template(int $agencyId, ?int $templateId): array
    {
        $t = $templateId ? DB::table('hr_review_templates')->where('id', $templateId)->where('agency_id', $agencyId)->first() : null;

        return ['scale' => $t ? (json_decode((string) $t->scale, true) ?: self::DEFAULT_SCALE) : self::DEFAULT_SCALE,
            'sections' => $t ? (json_decode((string) $t->sections, true) ?: self::DEFAULT_SECTIONS) : self::DEFAULT_SECTIONS];
    }

    // ── courses that follow a role (2026-11-28, Anthony) ─────────────────────────────────────────

    /**
     * A course assigned to a ROLE keeps going to that role: everyone who holds it now, and anyone who joins it later
     * (added to the role, or their role changed to it). One rule, read live, called from the user create / role
     * change paths (coursesForUser) and swept every 15 minutes (hr:role-courses) for every other way a role row
     * appears (imports, provisioning). Only while the role row is active, never for a closed account (suspended,
     * deactivated, deleted, HR file terminated), never at a closed centre, never twice (assignCourse is one per
     * person per course), never for someone a manager removed from it (skip_user_ids), never for a super admin.
     * Due date: N days after the person gets it (due_days), like the first assignment.
     *
     * @return int how many new assignments (or, dry, how many it would make)
     */
    public static function syncRoleCourses(?int $agencyId = null, ?int $userId = null, bool $dry = false): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_course_role_rules')) return 0;
        $rules = DB::table('hr_course_role_rules as r')->join('hr_courses as c', 'c.id', '=', 'r.course_id')
            ->whereNull('r.archived_at')->whereNull('c.archived_at')->whereColumn('c.agency_id', 'r.agency_id')
            ->when($agencyId, fn ($q) => $q->where('r.agency_id', $agencyId))
            ->get(['r.*', 'c.title']);
        $new = 0;
        $outsiders = AgencySignatories::outsiderIds();
        foreach ($rules->groupBy('agency_id') as $aid => $list) {
            $aid = (int) $aid;
            if (! HrLeave::on($aid)) continue;
            $open = Live::centreIds($aid);
            $staff = HrLeave::staffIds($aid);
            $today = AgencyTime::today($aid);
            foreach ($list as $r) {
                $centres = $r->centre_ids !== null ? array_map('intval', json_decode((string) $r->centre_ids, true) ?: []) : null;
                $skip = array_map('intval', json_decode((string) ($r->skip_user_ids ?? ''), true) ?: []);
                $q = DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
                    ->where('ra.active', 1)->whereNull('ra.closed_with_account_at')->where('ra.role', $r->role)
                    ->whereNull('u.deleted_at')->whereNotIn('u.status', ['suspended', 'deactivated'])
                    ->whereIn('u.id', $staff ?: [0])
                    ->whereNotExists(fn ($w) => $w->from('hr_course_assignments as x')->whereColumn('x.user_id', 'u.id')->where('x.course_id', $r->course_id))
                    ->whereNotExists(fn ($w) => $w->from('hr_employee_files as f')->whereColumn('f.user_id', 'u.id')->where('f.agency_id', $aid)->where('f.status', 'terminated'))
                    ->when($userId, fn ($w) => $w->where('u.id', $userId));
                if ($centres !== null) {
                    $q->whereIn('ra.centre_id', array_values(array_intersect($centres, $open)) ?: [0]);
                } else {
                    $q->where(fn ($w) => $w->where(fn ($a) => $a->where('ra.agency_id', $aid)->whereNull('ra.centre_id'))->orWhereIn('ra.centre_id', $open ?: [0]));
                }
                $ids = array_values(array_diff($q->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all(), $skip, $outsiders));
                foreach ($ids as $u) {
                    if ($dry) { $new++; continue; }
                    $due = $r->due_days !== null ? Carbon::parse($today)->addDays((int) $r->due_days)->toDateString() : null;
                    if (! self::assignCourse($aid, (int) $r->course_id, $u, $due, $r->created_by_id ? (int) $r->created_by_id : null, $r->role)) continue;
                    $new++;
                    try {
                        Audit::write(['user_id' => null, 'agency_id' => $aid, 'action' => 'hr.course_assigned_by_role', 'entity_type' => 'hr_course',
                            'entity_id' => (int) $r->course_id, 'payload' => json_encode(['user_id' => $u, 'role' => $r->role, 'rule_id' => (int) $r->id])]);
                    } catch (\Throwable $e) {
                    }
                    if (! TestAgency::isAgency($aid)) {   // test agencies send nothing
                        try {
                            Notify::write(['user_id' => $u, 'agency_id' => $aid, 'type' => 'hr', 'title' => 'New course: ' . $r->title,
                                'body' => $due ? 'Due ' . Carbon::parse($due)->format('M j') . '. Open it under My HR.' : 'Open it under My HR.',
                                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]);
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                }
            }
        }

        return $new;
    }

    /** After someone's role was added or changed: their role courses, now. Never throws (the caller's save stands). */
    public static function coursesForUser(int $userId): void
    {
        try {
            if (! \Illuminate\Support\Facades\Schema::hasTable('hr_course_role_rules')) return;
            $aids = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->pluck('agency_id')
                ->merge(DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')->where('ra.user_id', $userId)->where('ra.active', 1)->pluck('c.agency_id'))
                ->filter()->map(fn ($v) => (int) $v)->unique();
            foreach ($aids as $aid) {
                if (DB::table('hr_course_role_rules')->where('agency_id', $aid)->whereNull('archived_at')->exists()) self::syncRoleCourses($aid, $userId);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Keep the rule behind a by-role assignment. Same course + role + centres = one rule (its due_days updated). */
    public static function saveRoleRule(int $agencyId, int $courseId, string $role, ?array $centreIds, ?int $dueDays, ?int $actorId): int
    {
        $norm = fn ($ids) => $ids === null ? null : json_encode(array_values(array_unique(array_map('intval', $ids))));
        $key = $norm($centreIds);
        $row = DB::table('hr_course_role_rules')->where('agency_id', $agencyId)->where('course_id', $courseId)->where('role', $role)
            ->whereNull('archived_at')->get()->first(fn ($r) => $norm($r->centre_ids === null ? null : (json_decode((string) $r->centre_ids, true) ?: [])) === $key);
        if ($row) {
            DB::table('hr_course_role_rules')->where('id', $row->id)->update(['due_days' => $dueDays, 'updated_at' => now()]);

            return (int) $row->id;
        }

        return (int) DB::table('hr_course_role_rules')->insertGetId(['agency_id' => $agencyId, 'course_id' => $courseId, 'role' => $role,
            'centre_ids' => $key, 'due_days' => $dueDays, 'created_by_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
    }
}
