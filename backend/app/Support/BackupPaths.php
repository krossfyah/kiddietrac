<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Where database backups and their encryption keyring live (2026-10-26).
 *
 * Both used to be worked out from the app's own folder, which was right on GoDaddy (the app sat outside the web root)
 * and wrong on Cloudways (the app sits inside public_html, so "../backups" is a folder the web server can reach).
 * Set KT_BACKUP_DIR and KT_BACKUP_GNUPG in .env to absolute paths outside the web root; without them the old
 * defaults apply. env() is read directly on purpose: this app never caches config.
 */
class BackupPaths
{
    public static function dir(): string
    {
        $d = trim((string) env('KT_BACKUP_DIR', ''));

        return $d !== '' ? rtrim($d, '/') : base_path('../backups');
    }

    /** The keyring holding ONLY the backups public key. */
    public static function gnupgHome(): string
    {
        $h = trim((string) env('KT_BACKUP_GNUPG', ''));

        return $h !== '' ? rtrim($h, '/') : dirname(base_path(), 2) . '/.kt-backup-gnupg';
    }

    /**
     * THE OFF-SITE COPY, AS CLOUDWAYS REPORTS IT (2026-11-27, help sweep). The evidence tile said "same host, no
     * off-site copy", which stopped being true at the move: Cloudways backs up the whole server (files, including
     * these encrypted dumps, and the database) to its own off-server storage on the schedule set in its panel.
     * Read from the Cloudways API (read-only token, cached an hour) so the tile states what is actually set,
     * plus the time of the local copy Cloudways leaves in local_backups/. null = not confirmed (no token, API
     * down, or no backups set): then the screens say so instead of guessing.
     *
     * @return array{provider:string,every:string,keep_days:int,local_copy_at:?string}|null
     */
    public static function offsite(): ?array
    {
        try {
            if (! CloudwaysApi::connected()) {
                return null;
            }
            $s = \Illuminate\Support\Facades\Cache::remember('kt-backup-offsite', 3600, function () {
                $r = CloudwaysApi::get('/server');
                if (! $r['ok']) {
                    return ['none' => true];
                }
                $list = (array) ($r['data']['servers'] ?? []);
                $mine = null;
                foreach ($list as $srv) {
                    if (is_array($srv) && (string) ($srv['public_ip'] ?? '') === CloudwaysApi::NEW_SERVER_IP) {
                        $mine = $srv;
                    }
                }
                $mine = $mine ?: (count($list) === 1 && is_array($list[0] ?? null) ? $list[0] : null);
                if (! $mine || empty($mine['backup_frequency'])) {
                    return ['none' => true];
                }

                return ['every' => (string) $mine['backup_frequency'], 'keep_days' => (int) ($mine['backup_retention'] ?? 0)];
            });
            if (! empty($s['none'])) {
                return null;
            }
            $local = dirname(base_path(), 3) . '/local_backups/backup.tgz';
            $at = @filemtime($local);

            return [
                'provider' => 'Cloudways',
                'every' => preg_replace('/^(\d+)h$/', '$1 hours', $s['every']),
                'keep_days' => $s['keep_days'],
                'local_copy_at' => $at ? date('Y-m-d H:i', $at) : null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** True when the folder is inside a web root - a database dump must never be written there. */
    public static function insideWebRoot(string $dir): bool
    {
        $real = realpath($dir) ?: $dir;

        return (bool) preg_match('#/(public_html|www|htdocs)(/|$)#', $real);
    }
}
