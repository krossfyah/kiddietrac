<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Features;
use App\Support\HrLeave;
use App\Support\HrTalent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * COURSES FOLLOW THE ROLE (2026-11-28, Anthony). A course assigned to a role also goes to whoever joins that role
 * later. The user create / role change screens do it at once (HrTalent::coursesForUser); this sweep catches every
 * other way a role row appears (imports, provisioning, a reactivated account). All the rules live in
 * HrTalent::syncRoleCourses (active role row only, no closed accounts or centres, never twice, never someone a
 * manager removed). Writes assignments (+ a bell, never on a test agency). One small query per rule.
 */
final class HrRoleCoursesCommand extends Command
{
    protected $signature = 'hr:role-courses {--agency= : one agency id only} {--dry-run : count, write nothing}';

    protected $description = 'KiddieTrac HR: give role courses to people who joined the role since';

    public function handle(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasTable('hr_course_role_rules')) return self::SUCCESS;
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = DB::table('hr_course_role_rules')->whereNull('archived_at')->when($only, fn ($q) => $q->where('agency_id', $only))
            ->distinct()->pluck('agency_id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            if (Features::skips($aid, HrLeave::FEATURE, 'HR role courses')) continue;
            try {
                $n = HrTalent::syncRoleCourses($aid, null, $dry);
                if ($n || $dry) $this->line("agency $aid: " . ($dry ? 'would assign ' : 'assigned ') . $n);
            } catch (\Throwable $e) {
                report($e);
                $this->error("agency $aid: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
