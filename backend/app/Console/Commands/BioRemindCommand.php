<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\BioReminders;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Remind providers who have not written their bio (2026-10-09). Runs every 15 minutes;
 * each agency's schedule (Settings → Notifications → Provider bios) decides when.
 */
class BioRemindCommand extends Command
{
    protected $signature = 'providers:remind-bio
        {--agency= : Only this agency}
        {--force : Ignore the schedule (still respects enabled)}
        {--dry : Show who would be reminded, send nothing}';

    protected $description = 'Remind providers without a bio to write one, on each agency\'s schedule';

    public function handle(): int
    {
        $q = DB::table('agencies')->whereNull('deleted_at');
        if ($this->option('agency')) $q->where('id', (int) $this->option('agency'));
        foreach ($q->pluck('id') as $agencyId) {
            $agencyId = (int) $agencyId;
            $s = BioReminders::read($agencyId);
            $force = (bool) $this->option('force');
            if (! $s['enabled']) continue;
            if (! $force && ! BioReminders::isDue($agencyId, $s)) continue;
            $people = BioReminders::missing($agencyId, $s);
            if ($this->option('dry')) {
                $this->line("agency {$agencyId}: " . $people->count() . ' without a bio: ' . $people->map(fn ($p) => $p->user_id . '(' . $p->length . ' chars, ' . $p->reminders . ' sent)')->implode(', '));
                continue;
            }
            if ($people->isEmpty()) continue;
            if (! $force && ! BioReminders::claim($agencyId)) continue;
            $this->line('agency ' . $agencyId . ': ' . json_encode(BioReminders::sendAgency($agencyId, $s)));
        }

        return self::SUCCESS;
    }
}
