<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\TuitionIncreases;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tuition increases: tell families on their notice day and apply the new rates on the effective date (2026-11-28).
 * Hourly; each agency's "today" is its own timezone, and every step is recorded so a second run does nothing.
 * See App\Support\TuitionIncreases.
 */
final class TuitionIncreasesCommand extends Command
{
    protected $signature = 'billing:tuition-increases {--agency= : one agency only} {--dry-run : list what would happen and change nothing}';

    protected $description = 'Notify families of tuition increases and apply them on their effective date';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $q = DB::table('tuition_increases')->whereNull('applied_at')->distinct();
        if ($this->option('agency')) $q->where('agency_id', (int) $this->option('agency'));
        foreach ($q->pluck('agency_id') as $aid) {
            $aid = (int) $aid;
            if (\App\Support\Features::skips($aid, 'billing', 'tuition increases')) continue;
            try {
                $r = TuitionIncreases::runAgency($aid, $dry);
                if ($r['increases']) {
                    $this->line("Agency {$aid}: " . ($dry ? 'would run ' . json_encode($r['increases']) : "{$r['notified']} families told, {$r['applied']} fees changed"));
                }
            } catch (\Throwable $e) {
                report($e);
                $this->error("Agency {$aid}: " . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
