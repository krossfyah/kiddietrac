<?php

namespace App\Console\Commands;

use App\Support\Supplies;
use Illuminate\Console\Command;

/** Supplies from home (2026-10-23): tell whoever asked when a request is still open after its day. */
class SupplyOverdueCommand extends Command
{
    protected $signature = 'kt:supply-overdue {--dry : List what would be told, send nothing}';

    protected $description = 'Tell staff about supply requests still open after the day they were needed';

    public function handle(): int
    {
        $out = Supplies::overdue((bool) $this->option('dry'));
        foreach ($out as $line) $this->line(($this->option('dry') ? '[dry] ' : '') . $line);
        $this->info(count($out) . ' overdue');
        return self::SUCCESS;
    }
}
