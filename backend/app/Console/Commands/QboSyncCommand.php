<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\QboClient;
use App\Support\QboSync;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * qbo:sync — keeps QuickBooks in step for agencies that switched on automatic sync in
 * Settings → QuickBooks (off by default). Every 30 minutes; each agency is time-boxed and skipped
 * while a manual "Sync now" holds its lock.
 */
final class QboSyncCommand extends Command
{
    protected $signature = 'qbo:sync {--agency= : Only this agency}';
    protected $description = 'Push issued invoices, voids and payments to QuickBooks for agencies with automatic sync on';

    public function handle(): int
    {
        $q = DB::table('agency_qbo')->where('auto_sync', true)->whereNotNull('realm_id')->whereNotNull('refresh_token');
        if ($this->option('agency')) $q->where('agency_id', (int) $this->option('agency'));
        foreach ($q->pluck('agency_id') as $agencyId) {
            $agencyId = (int) $agencyId;
            if (! DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) continue;
            $lock = Cache::lock('qbo-sync-' . $agencyId, 300);
            if (! $lock->get()) { $this->line("agency {$agencyId}: busy"); continue; }
            try {
                if (! QboClient::connected(QboClient::row($agencyId))) continue;
                $o = QboSync::agency($agencyId, null, 120);
                $this->line(sprintf('agency %d: %d created, %d updated, %d voided, %d failed, %d left', $agencyId, $o['created'], $o['updated'], $o['voided'], $o['failed'], $o['remaining']));
            } catch (\Throwable $e) {
                $this->error("agency {$agencyId}: " . $e->getMessage());
            } finally { $lock->release(); }
        }
        return self::SUCCESS;
    }
}
