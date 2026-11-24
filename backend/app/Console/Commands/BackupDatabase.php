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
 *
 * ENCRYPTED BACKUPS (2026-10-21): when Platform setting backup.gpg_recipient holds a key
 * fingerprint, each verified dump is encrypted to that PUBLIC key (keyring ~/.kt-backup-gnupg)
 * and the plain .sql.gz is deleted, leaving kiddietrac-*.sql.gz.gpg. The PRIVATE key is not on
 * this server: Anthony keeps it (KiddieTrac-Backup-Key folder). Restore on a machine with it:
 *   gpg --decrypt kiddietrac-YYYY-MM-DD-HHMMSS.sql.gz.gpg | gunzip | mysql -u<user> -p <db>
 * A backup that cannot be encrypted is KEPT in plain form (a backup beats no backup) and a
 * high-severity 'backup_not_encrypted' security alert is raised.
 */
class BackupDatabase extends Command
{
    protected $signature = 'db:backup {--keep= : daily copies to retain (default: the portal setting, else 14)}';
    protected $description = 'Dump + gzip the database to ../backups, verify it, retain the last N, alert on failure.';

    public function handle(): int
    {
        /* SWITCHED OFF IS A DELIBERATE STATE, NOT A FAILURE. (2026-09-21)
           Turning backups off from the portal must not file a 'backup_failed' alert every
           night - that is exactly how a real failure learns to be ignored. */
        if (\App\Support\PlatformSettings::get('backup.enabled', '1') === '0') {
            $this->info('Database backups are switched off in Platform settings.');

            return self::SUCCESS;
        }

        $conn = config('database.connections.' . config('database.default'));
        $dir = \App\Support\BackupPaths::dir();   // KT_BACKUP_DIR, outside every web root (2026-10-26)
        if (\App\Support\BackupPaths::insideWebRoot($dir)) {
            return $this->failure('backup directory is inside the web root, refusing to write a dump there: ' . $dir
                . ' (set KT_BACKUP_DIR in .env to a folder outside public_html)');
        }
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

        /* ENCRYPT IT, then keep only the encrypted copy. The dump sat on this server in plain
           SQL, next to the site: anyone who got into the hosting account got every family's
           record, and the backups were the easiest place to find them all at once. */
        $encrypted = false;
        $recipient = trim((string) \App\Support\PlatformSettings::get('backup.gpg_recipient', ''));
        if ($recipient !== '') {
            $out = $file . '.gpg';
            $home = $this->gpgHome();
            $enc = new Process(['gpg', '--homedir', $home, '--batch', '--yes', '--no-tty', '--trust-model', 'always',
                '--recipient', $recipient, '--output', $out, '--encrypt', $file], null, null, null, 900);
            $enc->run();
            // Proof it is an OpenPGP message to that key. Without the private key gpg cannot go
            // further than the key packet, which is exactly the point.
            $chk = new Process(['gpg', '--homedir', $home, '--batch', '--no-tty', '--list-packets', $out], null, null, null, 120);
            $chk->run();
            $packets = $chk->getOutput() . $chk->getErrorOutput();
            if ($enc->isSuccessful() && is_file($out) && filesize($out) >= filesize($file) * 0.9
                && stripos($packets, 'pubkey enc packet') !== false) {
                @unlink($file);
                $file = $out;
                $encrypted = true;
            } else {
                @unlink($out);
                Log::error('[BACKUP] encryption failed, plain backup kept: ' . substr(trim($enc->getErrorOutput()), 0, 300));
                try {
                    DB::table('security_alerts')->insert([
                        'type' => 'backup_not_encrypted', 'severity' => 'high', 'subject' => 'db:backup',
                        'details' => 'Tonight\'s database backup could not be encrypted, so it was kept unencrypted: '
                            . substr(trim($enc->getErrorOutput()) ?: 'no output', 0, 300),
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } catch (Throwable $e) {
                }
            }
        }

        $mb = round(filesize($file) / 1048576, 2);
        Log::info("[BACKUP] ok: {$file} ({$mb} MB)" . ($encrypted ? ' encrypted' : ''));
        $this->info("Backup written: {$file} ({$mb} MB)");

        // Retention — keep the newest N, delete the rest.
        /* The portal setting is the default; --keep still wins for a one-off run. */
        $keep = (int) ($this->option('keep')
            ?: \App\Support\PlatformSettings::get('backup.keep', 14));
        $keep = max(1, min(90, $keep));
        $all = glob($dir . '/kiddietrac-*.sql.gz*') ?: [];   // plain .sql.gz and encrypted .sql.gz.gpg
        rsort($all);
        foreach (array_slice($all, $keep) as $old) {
            @unlink($old);
        }
        $this->info('Retaining ' . min(count($all), $keep) . ' backup(s) (keep=' . $keep . ').');

        /* What happened, where the portal can read it without shelling out. */
        try {
            \App\Support\PlatformSettings::set('backup.last_ok_at', now()->toDateTimeString());
            \App\Support\PlatformSettings::set('backup.last_size_mb', (string) $mb);
            \App\Support\PlatformSettings::set('backup.last_error', '');
            \App\Support\PlatformSettings::set('backup.last_encrypted', $encrypted ? '1' : '0');
        } catch (Throwable $e) {
        }

        return self::SUCCESS;
    }

    /** The keyring holding ONLY the backups public key, outside every webroot. */
    private function gpgHome(): string
    {
        return \App\Support\BackupPaths::gnupgHome();
    }

    private function failure(string $msg): int
    {
        Log::error('[BACKUP] ' . $msg);
        try {
            \App\Support\PlatformSettings::set('backup.last_error',
                now()->toDateTimeString() . ' - ' . substr($msg, 0, 300));
        } catch (Throwable $e) {
        }
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
