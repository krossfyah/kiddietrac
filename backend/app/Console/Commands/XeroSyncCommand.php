<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\XeroClient;
use App\Support\XeroSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * xero:sync — keeps Xero in step for agencies that switched on automatic sync in
 * Settings → Xero (off by default). Every 30 minutes; each agency is time-boxed and skipped
 * while a manual "Sync now" holds its lock.
 */
final class XeroSyncCommand extends Command
{
    protected $signature = 'xero:sync {--agency= : Only this agency}';
    protected $description = 'Push issued invoices, voids and payments to Xero for agencies with automatic sync on';

    public function handle(): int
    {
        $q = DB::table('agency_xero')->where('auto_sync', true)->whereNotNull('tenant_id')->whereNotNull('refresh_token');
        if ($this->option('agency')) $q->where('agency_id', (int) $this->option('agency'));
        foreach ($q->pluck('agency_id') as $agencyId) {
            $agencyId = (int) $agencyId;
            if (! DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) continue;
            $lock = Cache::lock('xero-sync-' . $agencyId, 300);
            if (! $lock->get()) { $this->line("agency {$agencyId}: busy"); continue; }
            try {
                if (! XeroClient::connected(XeroClient::row($agencyId))) continue;
                $o = XeroSync::agency($agencyId, null, 120);
                $this->line(sprintf('agency %d: %d created, %d updated, %d voided, %d failed, %d left', $agencyId, $o['created'], $o['updated'], $o['voided'], $o['failed'], $o['remaining']));
            } catch (\Throwable $e) {
                $this->error("agency {$agencyId}: " . $e->getMessage());
            } finally { $lock->release(); }
        }
        return self::SUCCESS;
    }
}
