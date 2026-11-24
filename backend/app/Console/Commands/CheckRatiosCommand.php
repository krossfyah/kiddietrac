<?php

namespace App\Console\Commands;

use App\Support\RatioAlerts;
use Illuminate\Console\Command;

/** Rooms out of ratio right now: alert staff once per episode, say when it is fixed (App\Support\RatioAlerts). */
class CheckRatiosCommand extends Command
{
    protected $signature = 'kiddietrac:check-ratios {--agency= : one agency only} {--dry-run : evaluate and print, write and send nothing}';
    protected $description = 'Alert staff when a room goes out of ratio (once per episode) and when it is back in ratio';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $r = RatioAlerts::run($dry, $this->option('agency') ? (int) $this->option('agency') : null);
        $this->line(($dry ? '[dry run] ' : '') . $r['rooms'] . ' rooms checked · ' . count($r['alerted']) . ' new breach(es) · '
            . $r['ongoing'] . ' ongoing · ' . count($r['cleared']) . ' back in ratio · ' . $r['off'] . ' agencies with alerts off');
        foreach ($r['alerted'] as $a) $this->line('  ALERT a' . $a['agency'] . ' room ' . $a['room'] . ' (' . $a['status'] . ') → ' . $a['to'] . ' people: ' . $a['body']);
        foreach ($r['cleared'] as $c) $this->line('  CLEARED a' . $c['agency'] . ' room ' . $c['room'] . ' ' . $c['place']);
        return self::SUCCESS;
    }
}
