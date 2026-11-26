<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrTalent;
use App\Support\Notify;
use App\Support\TestAgency;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR phase 2: the morning reminders (2026-11-28). Per agency with the HR add-on (Features::skips):
 *  - ticks onboarding tasks whose real thing is now done (HrTalent::sync), first;
 *  - onboarding tasks and course assignments due in 2 days or today, or overdue (every 3rd day): the person, and once
 *    overdue their manager (file manager, else their centre's directors);
 *  - reviews due in 3 days or overdue: whoever still has a part to write or a signature to give.
 * Bell rows through Notify::write (type `hr`, StaffNotifyPrefs topic staff_hr: the person's own reminders always reach
 * them; a manager can turn the team ones off). At most one reminder a day per row (reminded_on). Test agencies send
 * nothing, so they are skipped.
 */
final class HrTalentRemindCommand extends Command
{
    protected $signature = 'hr:talent-remind {--agency= : one agency id only} {--dry-run : count what it would send, send nothing}';

    protected $description = 'KiddieTrac HR: onboarding, course and review reminders (bell + phone), agency dates';

    private array $rows = [];

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = collect(['hr_onboarding_tasks', 'hr_course_assignments', 'hr_reviews'])
            ->flatMap(fn ($t) => DB::table($t)->when($only, fn ($q) => $q->where('agency_id', $only))->distinct()->pluck('agency_id'))
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        foreach ($ids as $aid) {
            if (! DB::table('agencies')->where('id', $aid)->whereNull('deleted_at')->exists()) continue;
            if (Features::skips($aid, HrLeave::FEATURE, 'HR reminders')) { $this->line("agency $aid: HR add-on off, skipped"); continue; }
            if (TestAgency::isAgency($aid) && ! $dry) { $this->line("agency $aid: test agency, sends nothing"); continue; }
            try {
                $n = $this->agency($aid, $dry);
                $this->line("agency $aid: " . ($dry ? 'would send ' : 'sent ') . $n . ' reminder(s)');
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }

    /** Remind today? 2 days before, on the day, then every 3rd day overdue. */
    private function due(?string $date, string $today, int $before = 2): ?int
    {
        if (! $date) return null;
        $d = (int) Carbon::parse($today)->diffInDays(Carbon::parse(substr($date, 0, 10)), false);   // days left (negative = overdue)
        if ($d === $before || $d === 0 || ($d < 0 && (-$d) % 3 === 1)) return $d;

        return null;
    }

    private function agency(int $aid, bool $dry): int
    {
        $today = AgencyTime::today($aid);
        $staff = HrLeave::staffIds($aid);
        $this->rows = [];
        if (! $dry) {
            foreach (DB::table('hr_onboardings')->where('agency_id', $aid)->where('status', 'active')->distinct()->pluck('user_id') as $u) HrTalent::sync($aid, (int) $u);
        }
        $when = fn (int $d, string $date) => $d > 0 ? 'due ' . Carbon::parse($date)->format('M j') : ($d === 0 ? 'due today' : (-$d) . ' day' . (-$d === 1 ? '' : 's') . ' overdue');
        $names = fn ($ids) => DB::table('users')->whereIn('id', $ids ?: [0])->pluck('first_name', 'id');

        // Onboarding tasks.
        $tasks = DB::table('hr_onboarding_tasks as t')->join('hr_onboardings as o', 'o.id', '=', 't.onboarding_id')->where('t.agency_id', $aid)
            ->where('o.status', 'active')->whereNull('t.done_at')->whereIn('t.user_id', $staff ?: [0])
            ->where(fn ($q) => $q->whereNull('t.reminded_on')->orWhere('t.reminded_on', '<', $today))->get(['t.*']);
        $first = $names($tasks->pluck('user_id')->all());
        foreach ($tasks as $t) {
            $d = $this->due($t->due_date, $today);
            if ($d === null) continue;
            $this->add($aid, (int) $t->user_id, 'Onboarding: ' . $t->title, ucfirst($when($d, (string) $t->due_date)) . '. Open My HR to finish it.', '#my-hr');
            if ($d < 0) {
                foreach (HrTalent::managersOf($aid, (int) $t->user_id) as $m) {
                    $this->add($aid, $m, 'Onboarding overdue: ' . ($first[$t->user_id] ?? ''), $t->title . ' · ' . $when($d, (string) $t->due_date), '#hr?tab=onboarding', true);
                }
            }
            if (! $dry) DB::table('hr_onboarding_tasks')->where('id', $t->id)->update(['reminded_on' => $today]);
        }

        // Course assignments.
        $courses = DB::table('hr_course_assignments as a')->join('hr_courses as c', 'c.id', '=', 'a.course_id')->where('a.agency_id', $aid)
            ->whereNull('a.completed_at')->whereNull('c.archived_at')->whereIn('a.user_id', $staff ?: [0])
            ->where(fn ($q) => $q->whereNull('a.reminded_on')->orWhere('a.reminded_on', '<', $today))->get(['a.*', 'c.title']);
        $first = $names($courses->pluck('user_id')->all());
        foreach ($courses as $a) {
            $d = $this->due($a->due_date, $today);
            if ($d === null) continue;
            $this->add($aid, (int) $a->user_id, 'Course: ' . $a->title, ucfirst($when($d, (string) $a->due_date)) . '. Open it under My HR.', '#my-hr');
            if ($d < 0 && (-$d) === 1) {
                foreach (HrTalent::managersOf($aid, (int) $a->user_id) as $m) {
                    $this->add($aid, $m, 'Course overdue: ' . ($first[$a->user_id] ?? ''), $a->title, '#hr?tab=courses', true);
                }
            }
            if (! $dry) DB::table('hr_course_assignments')->where('id', $a->id)->update(['reminded_on' => $today]);
        }

        // Reviews: whoever still owes a part or a signature.
        $reviews = DB::table('hr_reviews')->where('agency_id', $aid)->whereNotIn('status', ['complete', 'cancelled'])->whereIn('user_id', $staff ?: [0])
            ->where(fn ($q) => $q->whereNull('reminded_on')->orWhere('reminded_on', '<', $today))->get();
        $first = $names($reviews->pluck('user_id')->all());
        foreach ($reviews as $r) {
            $d = $this->due($r->due_date, $today, 3);
            if ($d === null) continue;
            $w = $when($d, (string) $r->due_date);
            if (! $r->self_submitted_at) $this->add($aid, (int) $r->user_id, 'Your self review', $r->title . ' · ' . $w, '#my-hr');
            if (! $r->manager_submitted_at) $this->add($aid, (int) $r->manager_user_id, 'Review to write: ' . ($first[$r->user_id] ?? ''), $r->title . ' · ' . $w, '#hr?tab=performance', true);
            if ($r->self_submitted_at && $r->manager_submitted_at) {
                if (! $r->employee_signed_at) $this->add($aid, (int) $r->user_id, 'Your review is waiting for your signature', $r->title, '#my-hr');
                if (! $r->manager_signed_at) $this->add($aid, (int) $r->manager_user_id, 'Review to sign: ' . ($first[$r->user_id] ?? ''), $r->title, '#hr?tab=performance', true);
            }
            if (! $dry) DB::table('hr_reviews')->where('id', $r->id)->update(['reminded_on' => $today]);
        }

        $n = count($this->rows);
        if (! $dry && $this->rows) Notify::write($this->rows);

        return $n;
    }

    /** One bell row. $team: a notice about someone else (the staff_hr topic can mute it); a person's own reminder always reaches them. */
    private function add(int $aid, int $uid, string $title, string $body, string $link, bool $team = false): void
    {
        if ($uid <= 0) return;
        $this->rows[] = ['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr', 'title' => $title, 'body' => $body,
            'data' => json_encode(['link' => $link, 'kind' => $team ? 'team' : 'own']), 'created_at' => now()];
    }
}
