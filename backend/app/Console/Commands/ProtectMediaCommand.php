<?php

namespace App\Console\Commands;

use App\Support\ProtectedMedia;
use Illuminate\Console\Command;

/**
 * KEEP THE PROTECTED FOLDERS OUT OF THE WEB ROOT (2026-11-10, PIPEDA review). nginx serves static files straight
 * from the web root, so a protected folder sitting in public/storage could be downloaded by its full path
 * (/kiddietrac/backend/public/storage/<folder>/<file>) whatever its .htaccess said. Each ProtectedMedia::FOLDERS
 * folder belongs in ProtectedMedia::home(), with public/storage/<folder> a link to it.
 *
 * Without --fix this only reports and fails when a folder is exposed. With --fix it moves the folder (a rename on
 * the same disk) and leaves the link; files keep their paths as the app sees them.
 */
class ProtectMediaCommand extends Command
{
    protected $signature = 'kt:protect-media {--fix : move exposed folders out of the web root}';
    protected $description = 'Check (or fix) that every protected media folder lives outside the web root';

    public function handle(): int
    {
        $home = ProtectedMedia::home();
        $pub = public_path('storage');
        if (! is_dir($home) && $this->option('fix')) {
            mkdir($home, 0775, true);
        }
        $homeReal = realpath($home) ?: $home;
        $exposed = 0;

        foreach (ProtectedMedia::FOLDERS as $folder) {
            $link = $pub . '/' . $folder;
            $target = $homeReal . '/' . $folder;

            if (is_link($link)) {
                $ok = realpath($link) === realpath($target);
                $this->line(($ok ? 'ok       ' : 'ODD LINK ') . $folder . ($ok ? '' : ' -> ' . readlink($link)));
                if (! $ok) $exposed++;
                continue;
            }
            if (! $this->option('fix')) {
                $this->line((is_dir($link) ? 'EXPOSED  ' : 'missing  ') . $folder);
                if (is_dir($link)) $exposed++;
                continue;
            }
            if (file_exists($target)) {
                $this->error('both     ' . $folder . ' (already in ' . $target . ' - left alone, sort out by hand)');
                $exposed++;
                continue;
            }
            if (is_dir($link)) {
                if (! rename($link, $target)) { $this->error('FAILED   ' . $folder . ' (move)'); $exposed++; continue; }
            } else {
                mkdir($target, 0775, true);
            }
            if (! is_file($target . '/.htaccess')) {
                file_put_contents($target . '/.htaccess', "# Written by KiddieTrac. These files are served ONLY through the signed\n"
                    . "# route /api/v1/media/f - see App\\Support\\ProtectedMedia.\nRequire all denied\n");
            }
            if (! symlink($target, $link)) { $this->error('FAILED   ' . $folder . ' (link) - files are in ' . $target); $exposed++; continue; }
            $this->info('moved    ' . $folder);
        }

        return $exposed ? self::FAILURE : self::SUCCESS;
    }
}
