<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\GeoIp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Download this month's DB-IP "IP to City Lite" database (CC BY 4.0) for App\Support\GeoIp.
 *
 * DB-IP publishes one file per month at a predictable address. Written to a temporary
 * file, checked by opening it, and only then swapped in, so a failed or partial download
 * never replaces a working database. Last month's file is tried when this month's is
 * not up yet (it appears on the 1st, not always at midnight).
 *
 *   php artisan geoip:update
 */
class GeoIpUpdate extends Command
{
    protected $signature = 'geoip:update';

    protected $description = 'Download the monthly DB-IP City Lite database used for sign-in locations';

    public function handle(): int
    {
        $dir = storage_path('app/geoip');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $target = storage_path('app/' . GeoIp::FILE);

        foreach ([now(), now()->subMonthNoOverflow()] as $month) {
            $url = 'https://download.db-ip.com/free/dbip-city-lite-' . $month->format('Y-m') . '.mmdb.gz';
            $gz = $dir . '/download.mmdb.gz';
            $tmp = $dir . '/download.mmdb';
            try {
                $res = Http::timeout(300)->withOptions(['sink' => $gz])->get($url);
                if (! $res->successful() || ! is_file($gz) || filesize($gz) < 1_000_000) {
                    $this->warn("Not available: {$url} (HTTP {$res->status()})");
                    @unlink($gz);
                    continue;
                }
                $in = gzopen($gz, 'rb');
                $out = fopen($tmp, 'wb');
                while (! gzeof($in)) {
                    fwrite($out, gzread($in, 1 << 20));
                }
                gzclose($in);
                fclose($out);
                @unlink($gz);

                // Prove it opens and answers before it replaces anything.
                $probe = new \MaxMind\Db\Reader($tmp);
                $hit = $probe->get('8.8.8.8');
                $probe->close();
                if (! is_array($hit)) {
                    throw new \RuntimeException('database opened but answered nothing');
                }
                rename($tmp, $target);
                $this->info('Installed ' . basename($url) . ' (' . round(filesize($target) / 1048576) . ' MB)');

                return self::SUCCESS;
            } catch (\Throwable $e) {
                @unlink($gz);
                @unlink($tmp);
                $this->error("Failed {$url}: " . $e->getMessage());
            }
        }

        return self::FAILURE;
    }
}
