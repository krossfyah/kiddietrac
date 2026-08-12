<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Mp4FastStart;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rewrites already-uploaded MP4s so they start playing without a full download.
 *
 * Phone recordings put the sample index (moov) at the END of the file, and a browser
 * cannot show a frame until it has read it — so an 11.9 MB clip had to be fetched in
 * full before playback began. See App\Support\Mp4FastStart.
 */
class MediaFastStartCommand extends Command
{
    protected $signature = 'media:faststart
        {--limit=200 : Maximum videos to process}
        {--dry-run : Report which files need it, change nothing}';

    protected $description = 'Move the moov atom to the front of uploaded MP4s so they stream immediately';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = DB::table('photos')->where('media_type', 'video')
            ->orderByDesc('id')->limit((int) $this->option('limit'))->get(['id', 'url']);

        $fixed = 0; $already = 0; $skipped = 0; $failed = 0;
        foreach ($rows as $r) {
            $url = (string) $r->url;
            if (! str_starts_with($url, '/storage/')) { $skipped++; continue; }
            $abs = storage_path('app/public/'.preg_replace('#^/storage/#', '', $url));
            if (! is_file($abs)) { $skipped++; continue; }

            if ($dry) {
                // Peek without writing: is moov already ahead of mdat?
                $probe = Mp4FastStart::process($abs.'.__nonexistent__');
                $this->line(sprintf('  #%-4s %8s KB  %s', $r->id, round(filesize($abs) / 1024), basename($abs)));
                continue;
            }

            $res = Mp4FastStart::process($abs);
            switch ($res['status']) {
                case 'ok':
                    $fixed++;
                    $this->line(sprintf('  #%-4s moov was at %s%% of the file, now first (%d bytes moved)',
                        $r->id, $res['moov_at'] ?? '?', $res['bytes_moved'] ?? 0));
                    break;
                case 'already': $already++; break;
                case 'failed':  $failed++; $this->warn("  #{$r->id} could not be rewritten — left untouched"); break;
                default:        $skipped++;
            }
        }

        $this->info("Faststart: {$fixed} rewritten, {$already} already streamable, {$skipped} skipped, {$failed} failed.");
        return self::SUCCESS;
    }
}
