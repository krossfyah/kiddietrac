<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ReenrolReplies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Re-enrolment reminders (2026-11-28): families who have not answered a re-enrolment are reminded once, on the agency's
 * reminder day (Billing setup reenrol_reminder_days before the deadline, default 7), from 9 am agency time. Hourly so
 * each agency's own morning is caught; reenrollment_campaigns.reminder_sent_at is claimed first, so a second run does
 * nothing. See App\Support\ReenrolReplies::runReminders.
 */
final class ReenrolRemindersCommand extends Command
{
    protected $signature = 'engagement:reenrol-reminders {--agency= : one agency only} {--dry-run : list what would be sent and send nothing}';

    protected $description = 'Remind families who have not answered a re-enrolment campaign';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('reenrollment_campaigns')->where('status', 'sent')->whereNull('reminder_sent_at')
            ->whereDate('deadline', '>=', now()->subDay()->toDateString())->distinct();
        if ($this->option('agency')) $q->where('agency_id', (int) $this->option('agency'));
        foreach ($q->pluck('agency_id') as $aid) {
            $aid = (int) $aid;
            if (\App\Support\Features::skips($aid, 'sales_crm', 're-enrolment reminders')) continue;
            // Test agencies send nothing (TestAgency): no bell pushes, no mail.
            if (\App\Support\TestAgency::skips($aid, 're-enrolment reminders')) continue;
            try {
                foreach (ReenrolReplies::runReminders($aid, $dry) as $r) {
                    $this->line("Agency {$aid} campaign {$r['campaign']}: " . ($dry ? 'would remind ' : 'reminded ') . $r['families'] . ' families');
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("Agency {$aid}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
