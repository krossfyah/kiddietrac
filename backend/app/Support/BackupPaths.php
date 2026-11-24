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

    /** True when the folder is inside a web root - a database dump must never be written there. */
    public static function insideWebRoot(string $dir): bool
    {
        $real = realpath($dir) ?: $dir;

        return (bool) preg_match('#/(public_html|www|htdocs)(/|$)#', $real);
    }
}
