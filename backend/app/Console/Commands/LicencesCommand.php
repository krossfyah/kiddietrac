<?php

namespace App\Console\Commands;

use App\Support\Licences;
use Illuminate\Console\Command;

/** Licences (2026-10-24): renewals, grace, expiry and reminders. App\Support\Licences::daily. */
class LicencesCommand extends Command
{
    protected $signature = 'kt:licences {--dry : List what would happen, change and send nothing}';

    protected $description = 'Renew, bill, warn about and expire agency licences';

    public function handle(): int
    {
        $out = Licences::daily((bool) $this->option('dry'));
        foreach ($out as $line) $this->line(($this->option('dry') ? '[dry] ' : '') . $line);
        $this->info(count($out) . ' licence action(s)');
        return self::SUCCESS;
    }
}
