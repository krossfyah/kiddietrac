<?php

namespace App\Console\Commands;

use App\Support\BillingSchedules;
use App\Support\Features;
use Illuminate\Console\Command;

/**
 * billing:schedules (2026-11-28): raise each family's scheduled invoice on its day (agency timezone) and charge it
 * by autopay where the family uses it. Hourly so each agency's day starts on time; App\Support\BillingSchedules
 * claims each family + period once, so an hour with nothing due does nothing.
 */
final class BillingSchedulesCommand extends Command
{
    protected $signature = 'billing:schedules {--agency= : one agency only} {--family=* : only these families} {--dry-run : say what would happen, change nothing}';
    protected $description = 'Raise (and autopay) invoices from families\' billing schedules on their dates';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $only = array_map('intval', (array) $this->option('family')) ?: null;
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : BillingSchedules::agencies();
        foreach ($agencies as $aid) {
            if (Features::skips($aid, 'billing', 'billing schedules')) { $this->line("Agency {$aid}: billing is not in its plan"); continue; }
            foreach (BillingSchedules::runAgency($aid, $dry, $only) as $r) {
                $this->line("Agency {$aid} · {$r['family']} · {$r['run_on']} · {$r['status']} · {$r['message']}" . ($r['invoice_id'] ? " · invoice {$r['invoice_id']}" : ''));
            }
        }
        return 0;
    }
}
