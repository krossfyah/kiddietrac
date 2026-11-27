<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\LeakGuard;
use Illuminate\Console\Command;

/**
 * kt:leak-alert - send the platform-only leak guard alert for a run (and any live sentinel hits not yet alerted).
 * Its own process because kt:leak-probe fakes mail and HTTP in-process. Also on its own, hourly via the probe.
 */
final class KtLeakAlert extends Command
{
    protected $signature = 'kt:leak-alert {--run= : leak_guard_runs id}';

    protected $description = 'Alert platform admins (bell + email + ticket) about leak guard findings.';

    public function handle(): int
    {
        $n = LeakGuard::alert($this->option('run') ? (int) $this->option('run') : null);
        $this->line($n ? "Leak guard alert: {$n} line(s) sent to the platform." : 'Leak guard alert: nothing new to send.');
        return self::SUCCESS;
    }
}
