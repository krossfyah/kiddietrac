<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\CountersignReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remind admins/directors about completed forms waiting for their counter-signature.
 *
 * Runs every 15 minutes; each agency's own schedule (Settings → Notifications) decides
 * whether its digest is due. The day is only claimed when there is something to say, so a
 * form that crosses the waiting threshold later in the day is still reminded about.
 */
class CountersignRemindCommand extends Command
{
    protected $signature = 'forms:remind-countersign
        {--agency= : Only this agency}
        {--force : Ignore the schedule (still respects enabled)}
        {--dry : Show what would be sent, send nothing}';

    protected $description = 'Send each reviewer one digest of completed forms waiting for counter-signature, on each agency\'s schedule';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        foreach ($q->pluck('id') as $agencyId) {
            $agencyId = (int) $agencyId;
            $s = CountersignReminders::read($agencyId);
            $force = (bool) $this->option('force');
            if (! $s['enabled']) continue;
            if (! $force && ! CountersignReminders::isDue($agencyId, $s)) continue;

            if ($this->option('dry')) {
                $items = CountersignReminders::pending($agencyId, $s);
                $map = CountersignReminders::reviewers($agencyId, $s, $items);
                $this->line("agency {$agencyId}: " . $items->count() . ' waiting, ' . count($map) . ' reviewer(s): '
                    . implode(', ', array_map(fn ($o) => $o['user']->user_id . '(' . count($o['items']) . ')', $map)));
                continue;
            }
            if (CountersignReminders::pending($agencyId, $s)->isEmpty()) continue;
            if (! $force && ! CountersignReminders::claim($agencyId)) continue;
            $r = CountersignReminders::sendAgency($agencyId, $s);
            $this->line('agency ' . $agencyId . ': ' . json_encode($r));
        }

        return self::SUCCESS;
    }
}
