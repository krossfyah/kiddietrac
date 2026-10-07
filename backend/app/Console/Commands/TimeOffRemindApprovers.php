<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\TimeOffReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remind approvers about time-off requests still waiting for a decision.
 *
 * Runs every 15 minutes; each agency's own schedule (Settings → Clock settings →
 * Time-off approvals) decides whether its digest is due. Claiming the day's run is a
 * conditional UPDATE, so two overlapping runs cannot both send.
 */
class TimeOffRemindApprovers extends Command
{
    protected $signature = 'timeoff:remind-approvers
        {--agency= : Only this agency}
        {--force : Ignore the schedule (still respects enabled, unless --ignore-enabled)}
        {--ignore-enabled : With --force, send even if the agency has reminders switched off}
        {--dry : Show what would be sent, send nothing}';

    protected $description = 'Send each approver one digest of pending time-off requests, on each agency\'s schedule';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        foreach ($q->pluck('id') as $agencyId) {
            $agencyId = (int) $agencyId;
            $s = TimeOffReminders::read($agencyId);
            $force = (bool) $this->option('force');
            if (! $s['enabled'] && ! ($force && $this->option('ignore-enabled'))) continue;
            if (! $force && ! TimeOffReminders::isDue($agencyId, $s)) continue;

            if ($this->option('dry')) {
                $reqs = TimeOffReminders::pending($agencyId, $s);
                $map = TimeOffReminders::approvers($agencyId, $s, $reqs);
                $this->line("agency {$agencyId}: " . $reqs->count() . ' pending, ' . count($map) . ' approver(s): '
                    . implode(', ', array_map(fn ($o) => $o['user']->user_id . '(' . count($o['requests']) . ')', $map)));
                continue;
            }
            // Only claim (and so use up the day) when there is something to say: an agency
            // with nothing pending at 9:00 is checked again later and reminded if a request
            // crosses the waiting threshold during the day.
            if (TimeOffReminders::pending($agencyId, $s)->isEmpty()) continue;
            if (! $force && ! TimeOffReminders::claim($agencyId)) continue;
            $r = TimeOffReminders::sendAgency($agencyId, $s);
            $this->line('agency ' . $agencyId . ': ' . json_encode($r));
        }

        return self::SUCCESS;
    }
}
