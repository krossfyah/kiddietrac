<?php

namespace App\Console\Commands;

use App\Support\AgencySubscribers;
use Illuminate\Console\Command;

/** Agency contacts onto the KiddieTrac subscriber list (App\Support\AgencySubscribers). Hourly; also the backfill. */
class AgencySubscribersCommand extends Command
{
    protected $signature = 'kiddietrac:agency-subscribers {--agency= : One agency only} {--dry : List without adding}';
    protected $description = 'Add onboarded agencies\' contacts to the KiddieTrac subscriber list';

    public function handle(): int
    {
        $r = AgencySubscribers::sync($this->option('agency') ? (int) $this->option('agency') : null, (bool) $this->option('dry'));
        foreach ($r['added'] as $e) $this->line(($this->option('dry') ? 'would add ' : 'added ') . $e);
        $this->info(count($r['added']) . ' added, ' . $r['already'] . ' already subscribed, ' . $r['unsubscribed'] . ' unsubscribed (left alone)'
            . ($this->option('dry') ? ' — dry run' : ''));
        return self::SUCCESS;
    }
}
