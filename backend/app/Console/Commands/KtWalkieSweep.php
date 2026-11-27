<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Controllers\Api\WalkieController;
use App\Support\Walkie;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * 📻 WALKIE-TALKIE HOUSEKEEPING (2026-11-27, App\Support\Walkie). In kt:minute's "two" group.
 *  1. A talk whose pieces stopped arriving (the speaker's phone lost signal) is ended and kept.
 *  2. The live, encrypted pieces in parent-portal/wt/<dir>/ are deleted a couple of minutes after a talk ends
 *     (and any stray folder older than 15 minutes), with the live key.
 *  3. Hourly: recordings older than each agency's retention (default 30 days) are deleted, with who-heard rows.
 * Touches no person and sends nothing.
 */
final class KtWalkieSweep extends Command
{
    protected $signature = 'kt:walkie-sweep {--dry : say what would go, change nothing}';
    protected $description = 'Walkie-talkie: end silent talks, delete live pieces, apply each agency\'s retention';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry');
        $stale = DB::table('walkie_transmissions')->where('status', 'live')->where('last_chunk_at', '<', now()->subSeconds(20))->pluck('id');
        foreach ($stale as $id) if (! $dry) WalkieController::finish((int) $id);

        $old = DB::table('walkie_transmissions')->whereNull('live_swept_at')->where('status', 'done')
            ->where('ended_at', '<', now()->subSeconds(Walkie::LIVE_KEEP_SECS))->get(['id', 'live_dir']);
        foreach ($old as $t) {
            if ($dry) continue;
            Walkie::dropLive($t->live_dir);
            DB::table('walkie_transmissions')->where('id', $t->id)->update(['live_swept_at' => now(), 'live_key' => null]);
        }
        $known = DB::table('walkie_transmissions')->whereNull('live_swept_at')->pluck('live_dir')->filter()->flip();
        $strays = 0;
        foreach (glob(Walkie::liveRoot() . '/*', GLOB_ONLYDIR) ?: [] as $d) {
            $name = basename($d);
            if (! preg_match('/^[a-f0-9]{32}$/', $name) || isset($known[$name]) || filemtime($d) > time() - 900) continue;
            $strays++;
            if (! $dry) Walkie::dropLive($name);
        }

        $purged = 0;
        if ($dry || Cache::add('kt-walkie-retention', 1, 3600)) {
            foreach (DB::table('walkie_transmissions')->distinct()->pluck('agency_id') as $aid) {
                $days = Walkie::settings((int) $aid)['retention_days'];
                $ids = DB::table('walkie_transmissions')->where('agency_id', $aid)->where('started_at', '<', now()->subDays($days))->pluck('id')->all();
                $purged += count($ids);
                if ($dry || ! $ids) continue;
                foreach (array_chunk($ids, 500) as $part) {
                    DB::table('walkie_recipients')->whereIn('transmission_id', $part)->delete();
                    DB::table('walkie_chunks')->whereIn('transmission_id', $part)->delete();
                    $dirs = DB::table('walkie_transmissions')->whereIn('id', $part)->whereNull('live_swept_at')->pluck('live_dir');
                    foreach ($dirs as $dir) Walkie::dropLive($dir);
                    DB::table('walkie_transmissions')->whereIn('id', $part)->delete();
                }
            }
        }
        $this->line(($dry ? '[dry] ' : '') . 'ended ' . count($stale) . ', live folders ' . count($old) . ', strays ' . $strays . ', past retention ' . $purged);

        return self::SUCCESS;
    }
}
