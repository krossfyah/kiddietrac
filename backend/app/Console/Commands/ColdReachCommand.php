<?php

namespace App\Console\Commands;

use App\Support\ColdReach;
use Illuminate\Console\Command;

/** Cold reach: spot replies and handle due follow-ups (App\Support\ColdReach::run). Every 15 minutes. */
class ColdReachCommand extends Command
{
    protected $signature = 'kt:cold-reach {--dry-run : count only, send and change nothing}';
    protected $description = 'Cold reach: detect replies, remind owners of due follow-ups, send automatic follow-up emails when enabled';

    public function handle(): int
    {
        $r = ColdReach::run((bool) $this->option('dry-run'));
        $this->line(json_encode($r));
        return self::SUCCESS;
    }
}
