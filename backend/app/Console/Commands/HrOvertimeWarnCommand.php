<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\AgencyTime;
use App\Support\CentreScope;
use App\Support\Features;
use App\Support\HrClock;
use App\Support\HrLeave;
use App\Support\Notify;
use App\Support\TestAgency;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * KiddieTrac HR Phase 3, item 11 (2026-11-26): the weekly overtime warning (App\Support\HrClock). Hourly. Per agency
 * with the HR add-on (Features::skips) and the warning on: each staff member's worked hours this agency week (closed
 * punches + the open one). At warn_at_hours (default 40) and again at overtime_hours (default 44, Ontario ESA) the person
 * and their centre's directors (no director: the agency admins) get a bell + phone notice, once per week per level
 * (hr_overtime_notices). Contractors are skipped unless the agency turns that off. Test agencies send nothing.
 */
final class HrOvertimeWarnCommand extends Command
{
    protected $signature = 'hr:overtime-warn {--agency= : one agency id only} {--dry-run : list who would be told, send nothing}';

    protected $description = 'KiddieTrac HR: weekly overtime warning to the person and their director (bell + phone)';

    public function handle(): int
    {
        $only = $this->option('agency') ? (int) $this->option('agency') : null;
        $dry = (bool) $this->option('dry-run');
        $ids = DB::table('agencies')->whereNull('deleted_at')->when($only, fn ($q) => $q->where('id', $only))->pluck('id')->map(fn ($v) => (int) $v)->all();
        foreach ($ids as $aid) {
            if (! Features::on($aid, HrLeave::FEATURE)) {
                if ($only) Features::skips($aid, HrLeave::FEATURE, 'HR overtime warning');
                continue;
            }
            if (TestAgency::isAgency($aid) && ! $dry) { $this->line("agency $aid: test agency, sends nothing"); continue; }
            try {
                $n = $this->agency($aid, $dry);
                if ($n || $only) $this->line("agency $aid: " . ($dry ? 'would warn ' : 'warned ') . $n . ' person(s)');
            } catch (\Throwable $e) {
                $this->error("agency $aid: " . $e->getMessage());
                report($e);
            }
        }

        return self::SUCCESS;
    }

    private function agency(int $aid, bool $dry): int
    {
        $s = HrClock::settings($aid);
        if (! $s['overtime_on']) return 0;
        $staff = HrLeave::staffIds($aid);
        if ($s['skip_contractors']) $staff = array_values(array_diff($staff, HrClock::contractorIds($aid, $staff)));
        $hours = array_intersect_key(HrClock::weekHours($aid, $staff), array_flip($staff));
        $week = Carbon::now(AgencyTime::tz($aid))->startOfWeek()->toDateString();
        $n = 0;
        foreach ($hours as $uid => $h) {
            $level = $h >= $s['overtime_hours'] ? 'over' : ($h >= $s['warn_at_hours'] ? 'warn' : null);
            if (! $level) continue;
            if (DB::table('hr_overtime_notices')->where('user_id', $uid)->where('week_start', $week)->where('level', $level)->exists()) continue;
            $u = DB::table('users')->where('id', $uid)->first(['first_name', 'preferred_name']);
            $first = trim((string) ($u->preferred_name ?: $u->first_name ?? ''));
            if ($dry) { $this->line("  $first ($uid): $h h, $level"); $n++; continue; }
            $ins = DB::table('hr_overtime_notices')->insertOrIgnore(['agency_id' => $aid, 'user_id' => $uid, 'week_start' => $week, 'level' => $level, 'hours' => $h, 'created_at' => now()]);
            if (! $ins) continue;   // another run got there first
            if ($level === 'over') DB::table('hr_overtime_notices')->insertOrIgnore(['agency_id' => $aid, 'user_id' => $uid, 'week_start' => $week, 'level' => 'warn', 'hours' => $h, 'created_at' => now()]);
            $hh = rtrim(rtrim(number_format($h, 1), '0'), '.');
            $ot = rtrim(rtrim(number_format($s['overtime_hours'], 1), '0'), '.');
            $rows = [['user_id' => $uid, 'agency_id' => $aid, 'type' => 'hr',
                'title' => $level === 'over' ? 'You are into overtime this week' : 'You are close to overtime this week',
                'body' => 'You have worked ' . $hh . ' hours this week. Overtime starts after ' . $ot . ' hours. Check with your director before working more.',
                'data' => json_encode(['link' => '#my-hr', 'kind' => 'own']), 'created_at' => now()]];
            $centres = CentreScope::centresOfUser($uid, $aid);
            $mgrs = CentreScope::managers($aid, $centres)->where('ra.role', 'centre_director')->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            if (! $mgrs) $mgrs = CentreScope::managers($aid, [])->distinct()->pluck('u.id')->map(fn ($v) => (int) $v)->all();
            // Never the platform super admin (not agency staff), never the person themselves.
            $plat = DB::table('role_assignments')->where('role', 'platform_admin')->where('active', 1)->pluck('user_id')->map(fn ($v) => (int) $v)->all();
            foreach (array_diff(array_unique($mgrs), [$uid], $plat) as $m) {
                $rows[] = ['user_id' => $m, 'agency_id' => $aid, 'type' => 'hr',
                    'title' => $first . ($level === 'over' ? ' is into overtime this week' : ' is close to overtime this week'),
                    'body' => $first . ' has worked ' . $hh . ' hours this week. Overtime starts after ' . $ot . ' hours.',
                    'data' => json_encode(['link' => '#hr?tab=rota', 'kind' => 'team']), 'created_at' => now()];
            }
            Notify::write($rows);
            $n++;
        }

        return $n;
    }
}
