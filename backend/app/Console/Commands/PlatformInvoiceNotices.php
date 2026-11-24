<?php

namespace App\Console\Commands;

use App\Support\PlatformAccounts;
use Illuminate\Console\Command;

/** Due-soon, due-today and overdue emails for platform invoices (2026-10-25). Settings: Sales → Invoices → Billing emails. */
class PlatformInvoiceNotices extends Command
{
    protected $signature = 'platform:invoice-notices {--dry : list what would be sent, send nothing} {--to= : send every notice to this one address instead (testing)}';
    protected $description = 'Email due-soon, due-today and overdue notices for KiddieTrac invoices';

    public function handle(): int
    {
        $to = trim((string) $this->option('to')) ?: null;
        if ($to && ! filter_var($to, FILTER_VALIDATE_EMAIL)) { $this->error('--to must be an email address'); return self::FAILURE; }
        $out = array_merge(PlatformAccounts::lateFees((bool) $this->option('dry'), $to), PlatformAccounts::notices((bool) $this->option('dry'), $to));
        foreach ($out as $l) $this->line('  ' . $l);
        $this->info(count($out) . ' notice(s)' . ($this->option('dry') ? ' (dry run)' : ''));
        return self::SUCCESS;
    }
}
