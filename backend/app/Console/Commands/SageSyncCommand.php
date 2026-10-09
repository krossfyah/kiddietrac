<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\SageClient;
use App\Support\SageSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * sage:sync — keeps Sage Accounting (cloud) in step for agencies that switched on automatic sync in
 * Settings → Sage (off by default). Every 30 minutes; each agency is time-boxed and skipped
 * while a manual "Sync now" holds its lock.
 */
final class SageSyncCommand extends Command
{
    protected $signature = 'sage:sync {--agency= : Only this agency}';
    protected $description = 'Push issued invoices, voids and payments to Sage Accounting for agencies with automatic sync on';

    public function handle(): int
    {
        $q = DB::table('agency_sage')->where('auto_sync', true)->whereNotNull('business_id')->whereNotNull('refresh_token');
        if ($this->option('agency')) $q->where('agency_id', (int) $this->option('agency'));
        foreach ($q->pluck('agency_id') as $agencyId) {
            $agencyId = (int) $agencyId;
            if (! DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) continue;
            $lock = Cache::lock('sage-sync-' . $agencyId, 300);
            if (! $lock->get()) { $this->line("agency {$agencyId}: busy"); continue; }
            try {
                if (! SageClient::connected(SageClient::row($agencyId))) continue;
                $o = SageSync::agency($agencyId, null, 120);
                $this->line(sprintf('agency %d: %d created, %d updated, %d voided, %d failed, %d left', $agencyId, $o['created'], $o['updated'], $o['voided'], $o['failed'], $o['remaining']));
            } catch (\Throwable $e) {
                $this->error("agency {$agencyId}: " . $e->getMessage());
            } finally { $lock->release(); }
        }
        return self::SUCCESS;
    }
}
