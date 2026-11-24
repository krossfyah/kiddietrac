<?php

namespace App\Console\Commands;

use App\Support\WalkCleanup;
use Illuminate\Console\Command;

/** Close walks still open after their own (agency) day — quietly. See App\Support\WalkCleanup. */
class CloseForgottenWalksCommand extends Command
{
    protected $signature = 'walks:close-forgotten
        {--dry-run : List what would be closed}
        {--quiet-backfill : Close without the educator bell (for old walks)}
        {--ids= : Comma-separated walk ids to limit to}';

    protected $description = 'Close walks nobody ended once their day is over (no parent notifications)';

    public function handle(): int
    {
        $ids = $this->option('ids') ? array_values(array_filter(array_map('intval', explode(',', (string) $this->option('ids'))))) : null;
        $closed = WalkCleanup::closeForgotten(! $this->option('quiet-backfill'), (bool) $this->option('dry-run'), $ids);
        foreach ($closed as $c) {
            $this->line(($this->option('dry-run') ? '  would close' : '  closed') . " walk {$c['id']} at {$c['return_time']}");
        }
        $this->info(($this->option('dry-run') ? 'Would close ' : 'Closed ') . count($closed) . ' walk(s).');

        return self::SUCCESS;
    }
}
