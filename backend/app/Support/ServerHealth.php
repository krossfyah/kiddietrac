<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SERVER HEALTH for the super admin (2026-10-22).
 *
 * Anthony, during the Cloudways readiness check: "is there APIs on cloudways that can be
 * used to check server stats, performance etc that we can include for the superadmin to
 * view" … "build it now and have it ready and add it under the platform overview section".
 *
 * Everything here is read from the machine the API runs on, so it needs no outside
 * account and works on any host:
 *   - the machine: CPU load and use, memory, disk, uptime;
 *   - PHP: version, limits, OPcache;
 *   - the database: version, size, connections, slow queries, busiest tables;
 *   - background work: queued jobs, the oldest one waiting, failures;
 *   - what people felt: server errors, slow requests, failed loads, rate limits (24 h,
 *     from the audit log the request middleware already writes);
 *   - certificates (days left on each host) and the newest database backup.
 * A sample is stored every 5 minutes (kt:server-health-sample) for the history charts;
 * the newest sample also proves the scheduler is running.
 *
 * On GoDaddy's shared hosting the machine figures describe the whole shared server, not
 * KiddieTrac's slice of it (the page says so). On a dedicated server (Cloudways) they are
 * ours.
 */
final class ServerHealth
{
    public const KEEP_DAYS = 14;

    public static function snapshot(): array
    {
        $out = [
            'generated_at' => now()->toIso8601String(),
            'host' => self::host(),
            'cpu' => self::cpu(),
            'memory' => self::memory(),
            'disk' => self::disk(),
            'php' => self::php(),
            'database' => self::database(),
            'queue' => self::queue(),
            'scheduler' => self::scheduler(),
            'requests' => self::requests(),
            'certificates' => self::certificates(),
            'backups' => self::backups(),
            // Leak guard (2026-11-27): last probe / integrity run and the live sentinel's hits. App\Support\LeakGuard.
            'leak_guard' => \App\Support\LeakGuard::status(),
        ];
        $out['alerts'] = self::alerts($out);

        return $out;
    }

    /* ── the machine ─────────────────────────────────────────────────────── */

    private static function read(string $file): ?string
    {
        try {
            return is_readable($file) ? (string) @file_get_contents($file) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function host(): array
    {
        $name = (string) @gethostname();
        $up = self::read('/proc/uptime');
        $shared = str_contains($name, 'secureserver') || is_dir('/proc/lve') || is_dir('/var/cagefs');
        $model = null;
        if ($ci = self::read('/proc/cpuinfo')) {
            if (preg_match('/^model name\s*:\s*(.+)$/m', $ci, $m)) {
                $model = trim($m[1]);
            }
        }

        return [
            'name' => $name,
            'provider' => $shared ? 'GoDaddy shared hosting' : (str_contains($name, 'cloudways') ? 'Cloudways' : 'Dedicated server'),
            'shared' => $shared,
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'cpu_model' => $model,
            'cores' => self::cores(),
            'uptime_seconds' => $up ? (int) floatval(explode(' ', trim($up))[0]) : null,
            'web_server' => $_SERVER['SERVER_SOFTWARE'] ?? null,
            'app_env' => config('app.env'),
        ];
    }

    private static function cores(): ?int
    {
        $ci = self::read('/proc/cpuinfo');
        if ($ci && ($n = preg_match_all('/^processor\s*:/m', $ci)) > 0) {
            return $n;
        }

        return null;
    }

    /** Load averages, and real CPU use measured over a quarter second. */
    public static function cpu(): array
    {
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $pct = null;
        $a = self::cpuTimes();
        if ($a) {
            usleep(250000);
            $b = self::cpuTimes();
            if ($b && $b[1] > $a[1]) {
                $pct = round(100 * (1 - ($b[0] - $a[0]) / ($b[1] - $a[1])), 1);
            }
        }
        $cores = self::cores();

        return [
            'load_1' => $load ? round($load[0], 2) : null,
            'load_5' => $load ? round($load[1], 2) : null,
            'load_15' => $load ? round($load[2], 2) : null,
            'cores' => $cores,
            // Load per core: about 1.0 means every core is busy.
            'load_per_core' => ($load && $cores) ? round($load[0] / $cores, 2) : null,
            'used_pct' => $pct !== null ? max(0, min(100, $pct)) : null,
        ];
    }

    /** [idle, total] jiffies from /proc/stat. */
    private static function cpuTimes(): ?array
    {
        $s = self::read('/proc/stat');
        if (! $s || ! preg_match('/^cpu\s+(.+)$/m', $s, $m)) {
            return null;
        }
        $v = array_map('intval', preg_split('/\s+/', trim($m[1])));
        $idle = ($v[3] ?? 0) + ($v[4] ?? 0);   // idle + iowait

        return [$idle, array_sum($v)];
    }

    public static function memory(): array
    {
        $mi = self::read('/proc/meminfo');
        $kb = function ($k) use ($mi) {
            return ($mi && preg_match('/^' . $k . ':\s+(\d+)\s+kB/m', $mi, $m)) ? (int) $m[1] * 1024 : null;
        };
        $total = $kb('MemTotal');
        $avail = $kb('MemAvailable');
        $swapT = $kb('SwapTotal');
        $swapF = $kb('SwapFree');

        return [
            'total' => $total,
            'available' => $avail,
            'used' => ($total && $avail !== null) ? $total - $avail : null,
            'used_pct' => ($total && $avail !== null) ? round(100 * ($total - $avail) / $total, 1) : null,
            'swap_total' => $swapT,
            'swap_used' => ($swapT !== null && $swapF !== null) ? $swapT - $swapF : null,
            'php_peak' => memory_get_peak_usage(true),
        ];
    }

    public static function disk(): array
    {
        $path = base_path();
        $total = @disk_total_space($path) ?: null;
        $free = @disk_free_space($path) ?: null;
        // Uploads and backups: walking them takes a moment, so once an hour.
        $sizes = Cache::remember('kt-server-health:sizes', 3600, function () {
            return [
                'uploads' => self::dirSize(storage_path('app')),
                'logs' => self::dirSize(storage_path('logs')),
                'backups' => self::dirSize(self::backupDir()),
            ];
        });

        return [
            'total' => $total,
            'free' => $free,
            'used_pct' => ($total && $free !== null) ? round(100 * ($total - $free) / $total, 1) : null,
        ] + $sizes;
    }

    private static function dirSize(string $dir): ?int
    {
        if (! is_dir($dir)) {
            return null;
        }
        $n = 0;
        try {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $n += $f->getSize();
                }
            }
        } catch (\Throwable $e) {
        }

        return $n;
    }

    public static function php(): array
    {
        $op = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'memory_limit' => ini_get('memory_limit'),
            'upload_max' => ini_get('upload_max_filesize'),
            'max_execution' => (int) ini_get('max_execution_time'),
            'opcache' => $op ? [
                'enabled' => (bool) ($op['opcache_enabled'] ?? false),
                'hit_rate' => isset($op['opcache_statistics']['opcache_hit_rate']) ? round($op['opcache_statistics']['opcache_hit_rate'], 1) : null,
                'memory_used_pct' => isset($op['memory_usage']['used_memory'], $op['memory_usage']['free_memory'])
                    ? round(100 * $op['memory_usage']['used_memory'] / max(1, $op['memory_usage']['used_memory'] + $op['memory_usage']['free_memory']), 1) : null,
                'scripts' => $op['opcache_statistics']['num_cached_scripts'] ?? null,
            ] : null,
            'laravel' => app()->version(),
        ];
    }

    /* ── the database ────────────────────────────────────────────────────── */

    public static function database(): array
    {
        $out = ['ok' => false];
        try {
            $t = microtime(true);
            DB::select('select 1');
            $out['ping_ms'] = round((microtime(true) - $t) * 1000, 2);
            $out['ok'] = true;
            $out['version'] = (string) (DB::selectOne('select version() v')->v ?? '');
            $st = [];
            foreach (DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Slow_queries','Uptime','Questions','Max_used_connections','Aborted_connects')") as $r) {
                $st[$r->Variable_name] = (int) $r->Value;
            }
            $max = DB::selectOne("SHOW VARIABLES LIKE 'max_connections'");
            $out['connections'] = $st['Threads_connected'] ?? null;
            $out['running'] = $st['Threads_running'] ?? null;
            $out['max_connections'] = $max ? (int) $max->Value : null;
            $out['max_used_connections'] = $st['Max_used_connections'] ?? null;
            $out['slow_queries'] = $st['Slow_queries'] ?? null;
            $out['uptime_seconds'] = $st['Uptime'] ?? null;
            $out['queries_per_second'] = ! empty($st['Uptime']) ? round(($st['Questions'] ?? 0) / $st['Uptime'], 1) : null;
            $out['time_zone'] = (string) (DB::selectOne('select @@global.time_zone z')->z ?? '');
            $sizes = Cache::remember('kt-server-health:db-sizes', 1800, function () {
                $db = DB::getDatabaseName();
                $rows = DB::select('select table_name t, table_rows r, data_length + index_length b from information_schema.tables where table_schema = ? order by b desc', [$db]);

                return [
                    'size' => array_sum(array_map(fn ($r) => (int) $r->b, $rows)),
                    'tables' => count($rows),
                    'largest' => array_map(fn ($r) => ['table' => $r->t, 'rows' => (int) $r->r, 'bytes' => (int) $r->b], array_slice($rows, 0, 8)),
                ];
            });
            $out += $sizes;
        } catch (\Throwable $e) {
            $out['problem'] = 'The database could not be read.';
        }

        return $out;
    }

    /* ── background work ─────────────────────────────────────────────────── */

    public static function queue(): array
    {
        $out = ['pending' => null, 'by_queue' => [], 'oldest_seconds' => null, 'failed_24h' => null, 'failed_total' => null];
        try {
            if (Schema::hasTable('jobs')) {
                $out['pending'] = DB::table('jobs')->count();
                foreach (DB::table('jobs')->selectRaw('queue, count(*) n')->groupBy('queue')->get() as $r) {
                    $out['by_queue'][$r->queue] = (int) $r->n;
                }
                $oldest = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
                $out['oldest_seconds'] = $oldest ? max(0, time() - (int) $oldest) : 0;
            }
            if (Schema::hasTable('failed_jobs')) {
                $out['failed_total'] = DB::table('failed_jobs')->count();
                $out['failed_24h'] = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
            }
        } catch (\Throwable $e) {
        }

        return $out;
    }

    /** The 5-minute sample doubles as the scheduler's heartbeat. */
    public static function scheduler(): array
    {
        $last = null;
        try {
            $last = Schema::hasTable('server_health_samples') ? DB::table('server_health_samples')->max('sampled_at') : null;
        } catch (\Throwable $e) {
        }
        $age = $last ? now()->diffInSeconds(\Illuminate\Support\Carbon::parse($last), true) : null;

        return ['last_sample_at' => $last, 'seconds_since' => $age !== null ? (int) $age : null, 'running' => $age !== null && $age < 900];
    }

    /** What people felt in the last 24 hours, from the request middleware's audit rows. */
    public static function requests(): array
    {
        $since = now()->subDay();
        $count = function (string $action) use ($since) {
            try {
                return DB::table('audit_logs')->where('action', $action)->where('created_at', '>=', $since)->count();
            } catch (\Throwable $e) {
                return null;
            }
        };
        $top = [];
        try {
            foreach (DB::table('audit_logs')->whereIn('action', ['error.server', 'perf.slow_request', 'error.client_request'])
                ->where('created_at', '>=', $since)->orderByDesc('id')->limit(400)->get(['action', 'payload']) as $r) {
                $p = json_decode((string) $r->payload, true) ?: [];
                $path = (string) ($p['path'] ?? '');
                if ($path === '') {
                    continue;
                }
                $path = preg_replace('#/\d+(?=/|$)#', '/{id}', '/' . ltrim(preg_replace('#^/?api/v1/#', '', $path), '/'));
                $k = $r->action . '|' . $path;
                $top[$k] = ($top[$k] ?? 0) + 1;
            }
        } catch (\Throwable $e) {
        }
        arsort($top);
        $list = [];
        foreach (array_slice($top, 0, 8, true) as $k => $n) {
            [$a, $p] = explode('|', $k, 2);
            $list[] = ['kind' => $a, 'path' => $p, 'count' => $n];
        }

        return [
            'server_errors' => $count('error.server'),
            'slow' => $count('perf.slow_request'),
            'failed_loads' => $count('error.client_request'),
            'rate_limited' => $count('error.rate_limited'),
            'top' => $list,
        ];
    }

    /* ── certificates and backups ────────────────────────────────────────── */

    public static function certificates(): array
    {
        return Cache::remember('kt-server-health:certs', 6 * 3600, function () {
            $out = [];
            foreach (['app.kiddietrac.com', 'api.kiddietrac.com', 'www.kiddietrac.com', 'kiddietrac.com'] as $h) {
                $row = ['host' => $h, 'days_left' => null, 'expires' => null, 'issuer' => null];
                try {
                    $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $h]]);
                    $c = @stream_socket_client('ssl://' . $h . ':443', $en, $es, 6, STREAM_CLIENT_CONNECT, $ctx);
                    if ($c) {
                        $cert = stream_context_get_params($c)['options']['ssl']['peer_certificate'] ?? null;
                        $info = $cert ? openssl_x509_parse($cert) : null;
                        if ($info && ! empty($info['validTo_time_t'])) {
                            $row['expires'] = date('Y-m-d', $info['validTo_time_t']);
                            $row['days_left'] = (int) floor(($info['validTo_time_t'] - time()) / 86400);
                            $row['issuer'] = $info['issuer']['O'] ?? ($info['issuer']['CN'] ?? null);
                            $names = (string) ($info['extensions']['subjectAltName'] ?? '');
                            $row['matches'] = str_contains($names, 'DNS:' . $h) || str_contains($names, 'DNS:*.' . substr($h, strpos($h, '.') + 1));
                        }
                        fclose($c);
                    }
                } catch (\Throwable $e) {
                }
                $out[] = $row;
            }

            return $out;
        });
    }

    public static function backupDir(): string
    {
        return \App\Support\BackupPaths::dir();
    }

    public static function backups(): array
    {
        $dir = self::backupDir();
        $files = is_dir($dir) ? (glob($dir . '/kiddietrac-*.sql.gz*') ?: []) : [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));
        $newest = $files[0] ?? null;

        return [
            'count' => count($files),
            'newest_at' => $newest ? date('c', filemtime($newest)) : null,
            'newest_hours' => $newest ? round((time() - filemtime($newest)) / 3600, 1) : null,
            'newest_size' => $newest ? filesize($newest) : null,
            'encrypted' => $newest ? str_ends_with($newest, '.gpg') : null,
        ];
    }

    /* ── plain-English warnings ──────────────────────────────────────────── */

    public static function alerts(array $s): array
    {
        $a = [];
        $add = function ($level, $text) use (&$a) { $a[] = ['level' => $level, 'text' => $text]; };
        if (($s['cpu']['used_pct'] ?? 0) >= 90) $add('red', 'The processor is almost fully busy (' . $s['cpu']['used_pct'] . '%).');
        elseif (($s['cpu']['load_per_core'] ?? 0) >= 1.5) $add('amber', 'The server is under heavy load (' . $s['cpu']['load_per_core'] . ' per core).');
        if (! ($s['host']['shared'] ?? false)) {
            if (($s['memory']['used_pct'] ?? 0) >= 90) $add('red', 'Memory is nearly full (' . $s['memory']['used_pct'] . '%).');
        }
        if (($s['disk']['used_pct'] ?? 0) >= 90) $add('red', 'The disk is ' . $s['disk']['used_pct'] . '% full.');
        elseif (($s['disk']['used_pct'] ?? 0) >= 80) $add('amber', 'The disk is ' . $s['disk']['used_pct'] . '% full.');
        if (! ($s['database']['ok'] ?? false)) $add('red', 'The database did not answer.');
        if (($s['queue']['oldest_seconds'] ?? 0) > 900) $add('red', 'Emails and background jobs are stuck: the oldest has waited ' . round($s['queue']['oldest_seconds'] / 60) . ' minutes.');
        if (($s['queue']['failed_24h'] ?? 0) > 0) $add('amber', $s['queue']['failed_24h'] . ' background job(s) failed in the last 24 hours.');
        if (! ($s['scheduler']['running'] ?? false)) $add('red', 'Scheduled jobs do not appear to be running (no check-in in the last 15 minutes).');
        if (($s['requests']['server_errors'] ?? 0) >= 20) $add('amber', $s['requests']['server_errors'] . ' server errors in the last 24 hours.');
        if (($s['requests']['failed_loads'] ?? 0) >= 50) $add('amber', $s['requests']['failed_loads'] . ' requests never reached the server in the last 24 hours (the host turned them away, or the person\'s connection dropped).');
        $lg = $s['leak_guard'] ?? [];
        foreach (['probe' => 'route probe', 'integrity' => 'data check'] as $k => $label) {
            if (! empty($lg[$k]['findings'])) $add('red', 'Leak guard ' . $label . ': ' . $lg[$k]['findings'] . ' cross-agency finding(s) in the last run.');
            elseif (! empty($lg[$k]['blind'])) $add('amber', 'Leak guard ' . $label . ' could not see its own test records (' . $lg[$k]['blind'] . ' roles) - it may be missing leaks.');
        }
        if (! empty($lg['sentinel_7d'])) $add('red', 'Leak guard sentinel: ' . $lg['sentinel_7d'] . ' live response(s) in 7 days carried data of an agency the person does not belong to.');
        if (array_key_exists('probe', $lg) && (! $lg['probe'] || strtotime((string) $lg['probe']['at']) < time() - 3 * 3600)) $add('amber', 'Leak guard: the route probe has not run in the last 3 hours.');
        foreach ($s['certificates'] ?? [] as $c) {
            if ($c['days_left'] === null) $add('amber', 'Could not read the certificate for ' . $c['host'] . '.');
            elseif ($c['days_left'] < 14) $add('red', 'The certificate for ' . $c['host'] . ' expires in ' . $c['days_left'] . ' days.');
            elseif (isset($c['matches']) && ! $c['matches']) $add('red', 'The certificate served for ' . $c['host'] . ' is not for that name.');
        }
        $b = $s['backups'] ?? [];
        if (! ($b['count'] ?? 0)) $add('red', 'No database backup was found on this server.');
        elseif (($b['newest_hours'] ?? 0) > 30) $add('red', 'The newest database backup is ' . round($b['newest_hours']) . ' hours old.');

        return $a;
    }

    /* ── history ─────────────────────────────────────────────────────────── */

    public static function sample(): void
    {
        $cpu = self::cpu();
        /* CPU ACROSS THE WHOLE PERIOD (2026-10-28). This runs from the scheduler, on the 5-minute tick, and
           cpu()'s quarter-second reading landed inside the tick's own burst: recorded 4x a second across
           14:40, the CPU was 2-56% before it and 96-100% from 14:40:02 as schedule:run and its jobs started.
           So the history said 92% on average (often 100%) for a server whose load average was 0.67. The
           counters from the previous sample are kept, and the point is the CPU used between the two - the
           real average over those five minutes. The first sample after a restart (nothing kept, or a
           counter reset) keeps the old instantaneous reading. */
        try {
            $now = self::cpuTimes();
            $prev = \Illuminate\Support\Facades\Cache::get('kt-health:cpu-prev');
            if ($now) {
                \Illuminate\Support\Facades\Cache::put('kt-health:cpu-prev', $now, 3600);
                if (is_array($prev) && count($prev) === 2 && $now[1] > $prev[1] && $now[0] >= $prev[0]) {
                    $cpu['used_pct'] = max(0, min(100, round(100 * (1 - ($now[0] - $prev[0]) / ($now[1] - $prev[1])), 1)));
                }
            }
        } catch (\Throwable $e) {
        }
        $mem = self::memory();
        $disk = self::disk();
        $db = self::database();
        $q = self::queue();
        DB::table('server_health_samples')->insert([
            'sampled_at' => now(),
            'load_1' => $cpu['load_1'],
            'cpu_pct' => $cpu['used_pct'],
            'mem_pct' => $mem['used_pct'],
            'disk_pct' => $disk['used_pct'],
            'db_ms' => $db['ping_ms'] ?? null,
            'db_connections' => $db['connections'] ?? null,
            'queue_pending' => $q['pending'],
            'queue_oldest' => $q['oldest_seconds'],
        ]);
        DB::table('server_health_samples')->where('sampled_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
    }

    public static function history(int $hours): array
    {
        if (! Schema::hasTable('server_health_samples')) {
            return [];
        }

        return DB::table('server_health_samples')->where('sampled_at', '>=', now()->subHours($hours))
            ->orderBy('sampled_at')->get(['sampled_at', 'load_1', 'cpu_pct', 'mem_pct', 'disk_pct', 'db_ms', 'db_connections', 'queue_pending', 'queue_oldest'])
            ->map(fn ($r) => (array) $r)->all();
    }
}
