<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Database backup (SOC 2 — Availability A1). Dumps + gzips the database to a
 * directory OUTSIDE both webroots, keeps the last N daily copies, verifies the
 * archive, and raises a security_alert on failure. Scheduled daily.
 *
 *   php artisan db:backup [--keep=14]
 *
 * Restore:  gunzip < backups/kiddietrac-YYYY-MM-DD-HHMMSS.sql.gz | mysql -u<user> -p <db>
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep=14 : number of daily backups to retain}';
    protected $description = 'Dump + gzip the database to ../backups, verify it, retain the last N, alert on failure.';

    public function handle(): int
    {
        $conn = config('database.connections.' . config('database.default'));
        $dir = base_path('../backups');          // ~/kiddietrac/backups — outside backend/public + parent-portal
        if (! is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (! is_dir($dir) || ! is_writable($dir)) {
            return $this->failure('backup directory not writable: ' . $dir);
        }

        $file = $dir . '/kiddietrac-' . now()->format('Y-m-d-His') . '.sql.gz';

        // --single-transaction: consistent InnoDB dump without LOCK TABLES priv.
        // --no-tablespaces: avoids needing the PROCESS privilege (shared hosting).
        // Password passed via MYSQL_PWD env, never on the command line.
        $cmd = sprintf(
            'mysqldump --single-transaction --quick --no-tablespaces -h%s -P%s -u%s %s | gzip -9 > %s',
            escapeshellarg((string) ($conn['host'] ?? '127.0.0.1')),
            escapeshellarg((string) ($conn['port'] ?? 3306)),
            escapeshellarg((string) $conn['username']),
            escapeshellarg((string) $conn['database']),
            escapeshellarg($file)
        );

        $proc = Process::fromShellCommandline($cmd, null, ['MYSQL_PWD' => (string) $conn['password']], null, 600);
        $proc->run();

        if (! $proc->isSuccessful() || ! is_file($file) || filesize($file) < 200) {
            @unlink($file);
            return $this->failure('mysqldump failed: ' . trim($proc->getErrorOutput() ?: 'empty output'));
        }

        // Integrity check — a truncated/corrupt gzip is worse than a loud failure.
        $gz = new Process(['gzip', '-t', $file]);
        $gz->run();
        if (! $gz->isSuccessful()) {
            @unlink($file);
            return $this->failure('backup archive failed gzip integrity check');
        }

        $mb = round(filesize($file) / 1048576, 2);
        Log::info("[BACKUP] ok: {$file} ({$mb} MB)");
        $this->info("Backup written: {$file} ({$mb} MB)");

        // Retention — keep the newest N, delete the rest.
        $keep = max(1, (int) $this->option('keep'));
        $all = glob($dir . '/kiddietrac-*.sql.gz') ?: [];
        rsort($all);
        foreach (array_slice($all, $keep) as $old) {
            @unlink($old);
        }
        $this->info('Retaining ' . min(count($all), $keep) . ' backup(s) (keep=' . $keep . ').');

        return self::SUCCESS;
    }

    private function failure(string $msg): int
    {
        Log::error('[BACKUP] ' . $msg);
        // A failed backup is itself a monitoring event — surface it in Security alerts.
        try {
            DB::table('security_alerts')->insert([
                'type' => 'backup_failed', 'severity' => 'high', 'subject' => 'db:backup',
                'details' => 'Database backup failed: ' . substr($msg, 0, 400),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            // never let alerting mask the original failure
        }
        $this->error($msg);
        return self::FAILURE;
    }
}
