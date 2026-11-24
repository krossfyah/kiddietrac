<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** kiddietrac:roster-alerts — bell the team for withdrawals and new families nobody rang for yet (App\Support\RosterAlerts). */
final class RosterAlertsCommand extends Command
{
    protected $signature = 'kiddietrac:roster-alerts';
    protected $description = 'Bell admins, directors and the centre educators about new families and withdrawn children (once each)';

    public function handle(): int
    {
        $r = \App\Support\RosterAlerts::sweep();
        if (array_sum($r)) $this->line(json_encode($r));
        return self::SUCCESS;
    }
}
