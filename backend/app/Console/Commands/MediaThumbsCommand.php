<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\MediaThumb;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfills gallery thumbnails for photos uploaded before thumbnails existed.
 *
 * Every existing row has thumbnail_url pointing at the FULL-SIZE image, so the
 * galleries have been downloading megabytes per tile. This walks the table, makes a
 * 480px still beside each original and repoints thumbnail_url at it.
 */
class MediaThumbsCommand extends Command
{
    protected $signature = 'media:thumbs
        {--limit=500 : Maximum rows to process}
        {--force : Rebuild even when thumbnail_url already differs from url}
        {--dry-run : Report what would change, write nothing}';

    protected $description = 'Generate missing gallery thumbnails for photos and repoint thumbnail_url';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $rows = DB::table('photos')
            ->where(fn ($q) => $q->where('media_type', '!=', 'video')->orWhereNull('media_type'))
            ->orderByDesc('id')
            ->limit((int) $this->option('limit'))
            ->get(['id', 'url', 'thumbnail_url']);

        $made = 0; $skipped = 0; $saved = 0; $missing = 0;
        foreach ($rows as $r) {
            $url = (string) $r->url;
            // Seeded demo rows point at picsum.photos and have nothing local to resize.
            if (! str_starts_with($url, '/storage/')) { $skipped++; continue; }
            if (! $this->option('force') && $r->thumbnail_url && $r->thumbnail_url !== $url) { $skipped++; continue; }

            $rel = preg_replace('#^/storage/#', '', $url);
            $abs = storage_path('app/public/'.$rel);
            if (! is_file($abs)) { $missing++; continue; }

            $before = filesize($abs);
            $thumb = MediaThumb::make($abs);
            if (! $thumb) { $skipped++; continue; }
            $web = MediaThumb::webPath($thumb);
            if (! $web) { $skipped++; continue; }

            $after = is_file($thumb) ? filesize($thumb) : $before;
            $saved += max(0, $before - $after);
            $this->line(sprintf('  #%-4s %6s KB -> %5s KB  %s', $r->id, round($before / 1024), round($after / 1024), $web));

            if (! $dry) DB::table('photos')->where('id', $r->id)->update(['thumbnail_url' => $web]);
            $made++;
        }

        $this->info(sprintf(
            '%s%d thumbnail(s), %d skipped, %d file(s) missing. Tile payload cut by ~%s MB.',
            $dry ? '[dry-run] would make ' : 'Made ',
            $made, $skipped, $missing, number_format($saved / 1048576, 1)
        ));

        return self::SUCCESS;
    }
}
