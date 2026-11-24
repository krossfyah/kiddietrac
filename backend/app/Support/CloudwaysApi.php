<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cloudways API v2, read-only, for the Server health page (2026-10-22).
 *
 * Anthony: "did you wire up the cloudways as this is more important that godaddy (which
 * we are moving away from)". Until DNS moves, the portal everyone opens runs on GoDaddy,
 * so the only way it can see the new server is Cloudways' own API.
 *
 * Read from developers.cloudways.com (API playground) and confirmed against the live API:
 *   base  https://api.cloudways.com/api/v2      (v1 retired 2026-03-31)
 *   auth  Authorization: Bearer <access token>  (made in the Cloudways account; READ role is enough)
 *   GET /server                                 the account's servers
 *   GET /monitor_durations                      "1 Hour", "12 Hours", "1 Day", "7 Days", "1 Month" (no auth)
 *   GET /monitor_targets                        graph types per cloud (do, amazon, gce, vultr, linode)
 *   GET /server/monitor/summary?server_id&type  type bw = bandwidth, db = disk size per application
 *   GET /server/monitor/detail?server_id&target&duration&storage&timezone&output_format
 *
 * The response bodies of the monitor calls are not documented in detail, so they are read
 * defensively (series()) and the raw shape is never shown to anyone. The token is stored
 * encrypted (PlatformSettings::setSecret) and never sent back to the browser.
 */
final class CloudwaysApi
{
    public const BASE = 'https://api.cloudways.com/api/v2';
    public const TOKEN_KEY = 'cloudways.access_token';
    public const SERVER_KEY = 'cloudways.server_id';
    public const NEW_SERVER_IP = '165.22.225.244';

    /**
     * The graphs shown, from the targets Cloudways lists for a DigitalOcean server
     * (/monitor_targets, 2026-10-22: Idle CPU, Free Disk, Reads/Writes per second, Free
     * memory, Incoming/Outgoing network traffic, Monthly Bandwidth, Memcached…, Varnish…,
     * Auto-healing Restarts, MySQL Connections). target => [label, unit, show 100 − value].
     * Free Disk arrives already scaled to GB by Cloudways (scale(…disk.root.avail, 0.001)).
     */
    public const GRAPHS = [
        'Idle CPU' => ['Processor used', '%', true],
        'Free memory' => ['Free memory', ' GB', false, 1073741824],   // bytes
        'Free Disk' => ['Free disk', ' GB', false],
        'MySQL Connections' => ['Database connections', '', false],
        'Incoming network traffic' => ['Network in', '', false],
        'Outgoing network traffic' => ['Network out', '', false],
        'Reads per second' => ['Disk reads / sec', '', false],
        'Writes per second' => ['Disk writes / sec', '', false],
        'Auto-healing Restarts' => ['Auto-healing restarts', '', false],
    ];

    public static function token(): ?string
    {
        $t = PlatformSettings::getSecret(self::TOKEN_KEY);

        return $t !== null && trim($t) !== '' ? trim($t) : null;
    }

    public static function connected(): bool
    {
        return self::token() !== null;
    }

    /** @return array{ok:bool,status:int,data:mixed} */
    public static function get(string $path, array $query = [], ?string $token = null): array
    {
        $token = $token ?? self::token();
        try {
            $r = Http::withToken((string) $token)->acceptJson()->timeout(20)
                ->get(self::BASE . $path, $query);

            return ['ok' => $r->successful(), 'status' => $r->status(), 'data' => $r->json()];
        } catch (\Throwable $e) {
            return ['ok' => false, 'status' => 0, 'data' => null];
        }
    }

    /**
     * Several GETs at once (2026-10-28). Same request as get() - token, JSON, 20s timeout - each keyed by
     * name, each answered in get()'s shape. The Server health page made 13 Cloudways calls one after
     * another (~240ms each, 3-4s a load, logged as perf.slow_request on every cache miss); all but the
     * first are independent, so they now go together and the page waits for the slowest one.
     *
     * @param  array<string, array{0:string,1:array}>  $reqs  name => [path, query]
     * @return array<string, array{ok:bool,status:int,data:mixed}>
     */
    public static function getMany(array $reqs, ?string $token = null): array
    {
        $token = (string) ($token ?? self::token());
        $out = [];
        try {
            $res = Http::pool(function ($pool) use ($reqs, $token) {
                $list = [];
                foreach ($reqs as $name => [$path, $query]) {
                    $list[] = $pool->as((string) $name)->withToken($token)->acceptJson()->timeout(20)->get(self::BASE . $path, $query);
                }

                return $list;
            });
            foreach ($reqs as $name => $_) {
                $r = $res[$name] ?? null;
                $out[$name] = ($r instanceof \Illuminate\Http\Client\Response)
                    ? ['ok' => $r->successful(), 'status' => $r->status(), 'data' => $r->json()]
                    : ['ok' => false, 'status' => 0, 'data' => null];   // a connection error comes back as an exception object
            }
        } catch (\Throwable $e) {
            foreach ($reqs as $name => [$path, $query]) {
                $out[$name] = self::get($path, $query, $token);   // the pool itself failed: one by one, as before
            }
        }

        return $out;
    }

    /** The account's servers, in a fixed shape. */
    public static function servers(?string $token = null): array
    {
        $r = self::get('/server', [], $token);
        if (! $r['ok']) {
            return ['ok' => false, 'status' => $r['status'], 'servers' => []];
        }
        $list = $r['data']['servers'] ?? (is_array($r['data']) && array_is_list($r['data']) ? $r['data'] : []);
        $out = [];
        foreach ((array) $list as $s) {
            if (! is_array($s)) {
                continue;
            }
            $out[] = [
                'id' => (string) ($s['id'] ?? ''),
                'label' => (string) ($s['label'] ?? ''),
                'ip' => (string) ($s['public_ip'] ?? ($s['ip'] ?? '')),
                'cloud' => (string) ($s['cloud'] ?? ''),
                'region' => (string) ($s['region'] ?? ''),
                'size' => (string) ($s['instance_type'] ?? ($s['server_size'] ?? '')),
                'status' => (string) ($s['status'] ?? ''),
                'platform' => (string) ($s['platform'] ?? ''),
                'apps' => isset($s['apps']) && is_array($s['apps']) ? array_map(fn ($a) => [
                    'id' => (string) ($a['id'] ?? ''), 'label' => (string) ($a['label'] ?? ''), 'app' => (string) ($a['application'] ?? ''),
                    'sys_user' => (string) ($a['sys_user'] ?? ''),
                ], $s['apps']) : [],
            ];
        }

        return ['ok' => true, 'status' => $r['status'], 'servers' => $out];
    }

    /** The KiddieTrac server: the one chosen, else the one at the new IP, else the only one. */
    public static function server(array $servers): ?array
    {
        $want = (string) PlatformSettings::get(self::SERVER_KEY, '');
        foreach ($servers as $s) {
            if ($want !== '' && $s['id'] === $want) {
                return $s;
            }
        }
        foreach ($servers as $s) {
            if ($s['ip'] === self::NEW_SERVER_IP) {
                return $s;
            }
        }

        return count($servers) === 1 ? $servers[0] : null;
    }

    /** Everything the page shows, cached 5 minutes per duration. */
    public static function overview(string $duration): array
    {
        if (! self::connected()) {
            return ['connected' => false];
        }

        return Cache::remember('kt-cloudways:overview:' . md5($duration), 300, function () use ($duration) {
            $sv = self::servers();
            if (! $sv['ok']) {
                return ['connected' => true, 'ok' => false,
                    'problem' => in_array($sv['status'], [401, 403], true)
                        ? 'Cloudways did not accept the access token. It may have expired or been revoked — create a new one and connect again.'
                        : 'Cloudways did not answer just now. Please try again in a few minutes.'];
            }
            $server = self::server($sv['servers']);
            if (! $server) {
                return ['connected' => true, 'ok' => false, 'servers' => $sv['servers'],
                    'problem' => 'The token works, but none of its servers is at ' . self::NEW_SERVER_IP . '. Choose the KiddieTrac server below.'];
            }
            $out = ['connected' => true, 'ok' => true, 'server' => $server, 'servers' => $sv['servers'], 'duration' => $duration];

            $appNames = [];
            foreach ($server['apps'] as $a) {
                $appNames[$a['id']] = $a['label'];
                if (($a['sys_user'] ?? '') !== '') {
                    $appNames[$a['sys_user']] = $a['label'];   // the disk summary names apps by system user
                }
            }
            /* Every call below depends only on the server, so they go out together (getMany); each graph
               is still dropped afterwards when this cloud does not list it, exactly as before. */
            $reqs = [
                'sum:bw' => ['/server/monitor/summary', ['server_id' => $server['id'], 'type' => 'bw']],
                'sum:db' => ['/server/monitor/summary', ['server_id' => $server['id'], 'type' => 'db']],
                'targets' => ['/monitor_targets', []],
            ];
            foreach (self::GRAPHS as $target => $spec) {
                $reqs['g:' . $target] = ['/server/monitor/detail', [
                    'server_id' => $server['id'], 'target' => $target, 'duration' => $duration,
                    'storage' => 'false', 'timezone' => 'UTC', 'output_format' => 'json',
                ]];
            }
            $got = self::getMany($reqs);

            foreach (['bw' => 'bandwidth', 'db' => 'disk_per_app'] as $type => $key) {
                $r = $got['sum:' . $type];
                $out[$key] = $r['ok'] ? self::summary($r['data'], $appNames) : null;
            }

            $targets = [];
            $t = $got['targets'];
            if ($t['ok']) {
                $byCloud = $t['data']['targets'] ?? [];
                $list = $byCloud[$server['cloud']] ?? [];
                foreach ((array) $list as $x) {
                    $targets[] = is_array($x) ? (string) ($x['target'] ?? ($x['name'] ?? ($x['value'] ?? ''))) : (string) $x;
                }
                $targets = array_values(array_filter($targets));
            }
            $out['targets'] = $targets;
            $graphs = [];
            foreach (self::GRAPHS as $target => $spec) {
                [$label, $unit, $invert] = $spec;
                $div = $spec[3] ?? 1;
                if ($targets && ! in_array($target, $targets, true)) {
                    continue;   // this cloud does not offer it
                }
                $r = $got['g:' . $target];
                $pts = $r['ok'] ? self::series($r['data']) : [];
                if ($invert) {
                    $pts = array_map(fn ($p) => [$p[0], round(100 - $p[1], 2)], $pts);
                }
                if ($div != 1) {
                    $pts = array_map(fn ($p) => [$p[0], round($p[1] / $div, 2)], $pts);
                }
                $graphs[] = ['target' => $target, 'label' => $label, 'unit' => $unit, 'points' => $pts];
            }
            $out['graphs'] = $graphs;
            $out['fetched_at'] = now()->toIso8601String();

            return $out;
        });
    }

    /** "cpu_idle" → "CPU idle". */
    public static function label(string $t): string
    {
        $t = str_replace(['_', '-'], ' ', $t);
        $t = preg_replace('/\b(cpu|ram|io|db|php|mysql|bw)\b/i', '§$1', $t);

        return ucfirst(preg_replace_callback('/§(\w+)/', fn ($m) => strtoupper($m[1]), $t));
    }

    /**
     * Summary rows. Cloudways answers {content:[{name, datapoint:[value, unix time], type}]}
     * (bandwidth_monthly in MB; type db = one row per app, disk use in MB). Returns
     * [label, value_mb] rows; anything else falls back to the generic flattening below.
     */
    public static function summary($data, array $appNames = []): array
    {
        $rows = $data['content'] ?? null;
        if (is_array($rows) && $rows && isset($rows[0]['datapoint'])) {
            $out = [];
            foreach ($rows as $r) {
                $v = $r['datapoint'][0] ?? null;
                if (! is_numeric($v)) {
                    continue;
                }
                $name = (string) ($r['name'] ?? '');
                $label = $name === 'bandwidth_monthly' ? 'This month' : ($appNames[$name] ?? ($r['type'] === 'apps' ? 'App ' . $name : str_replace('_', ' ', $name)));
                $out[] = [$label, round((float) $v, 2), 'MB'];
            }

            return $out;
        }

        return self::summaryGeneric($data);
    }

    /** A flat list of [label, value] from a summary body, whatever its nesting. */
    public static function summaryGeneric($data): array
    {
        $out = [];
        $walk = function ($v, $k = '') use (&$walk, &$out) {
            if (count($out) >= 30) {
                return;
            }
            if (is_array($v)) {
                $isRow = isset($v['name']) || isset($v['label']) || isset($v['app']);
                $val = $v['value'] ?? ($v['size'] ?? ($v['usage'] ?? ($v['bw'] ?? ($v['datapoint'] ?? null))));
                if ($isRow && (is_numeric($val) || is_string($val))) {
                    $out[] = [(string) ($v['name'] ?? ($v['label'] ?? $v['app'])), $val];

                    return;
                }
                foreach ($v as $kk => $vv) {
                    $walk($vv, is_int($kk) ? $k : (string) $kk);
                }

                return;
            }
            if ((is_numeric($v) || (is_string($v) && strlen($v) < 40)) && $k !== '' && ! in_array($k, ['status', 'operation_id', 'task_id'], true)) {
                $out[] = [$k, $v];
            }
        };
        $walk($data);

        return $out;
    }

    /**
     * Points [unix seconds, value] from a graph body. Accepts [[t,v],…], [{x|timestamp|time, y|value},…],
     * or {datapoints:[…]} nested anywhere; keeps the longest series found.
     */
    public static function series($data): array
    {
        if (is_array($data) && isset($data['content']) && is_string($data['content'])) {
            $inner = json_decode($data['content'], true);
            if (is_array($inner)) {
                $data = $inner;
            }
        }
        $best = [];
        $try = function ($arr) use (&$best) {
            if (! is_array($arr) || ! array_is_list($arr) || count($arr) < 2) {
                return;
            }
            $pts = [];
            foreach ($arr as $p) {
                $t = $v = null;
                if (is_array($p) && array_is_list($p) && count($p) >= 2 && is_numeric($p[0]) && is_numeric($p[1])) {
                    [$a, $b] = [(float) $p[0], (float) $p[1]];
                    /* Cloudways (Graphite) sends [value, unix time]. Free memory is in bytes, so
                       the value can be as large as a timestamp: take the second as the time
                       whenever it looks like one, and only then try the other way round. */
                    if ($b >= 1e9 && $b < 5e9) { $t = $b; $v = $a; } elseif ($a >= 1e9 && $a < 5e9) { $t = $a; $v = $b; }
                } elseif (is_array($p)) {
                    $t = $p['x'] ?? ($p['timestamp'] ?? ($p['time'] ?? ($p['date'] ?? null)));
                    $v = $p['y'] ?? ($p['value'] ?? ($p['avg'] ?? null));
                    if (is_string($t) && ! is_numeric($t)) { $t = strtotime($t) ?: null; }
                }
                if ($t !== null && is_numeric($v)) {
                    $t = (float) $t;
                    if ($t > 1e12) { $t /= 1000; }   // milliseconds
                    $pts[] = [(int) $t, round((float) $v, 2)];
                }
            }
            if (count($pts) > count($best)) {
                $best = $pts;
            }
        };
        $walk = function ($v) use (&$walk, $try) {
            if (! is_array($v)) {
                return;
            }
            $try($v);
            foreach ($v as $vv) {
                if (is_array($vv)) {
                    $walk($vv);
                }
            }
        };
        $walk($data);
        usort($best, fn ($a, $b) => $a[0] <=> $b[0]);

        // At most ~300 points for the page.
        if (count($best) > 300) {
            $step = (int) ceil(count($best) / 300);
            $best = array_values(array_filter($best, fn ($p, $i) => $i % $step === 0, ARRAY_FILTER_USE_BOTH));
        }

        return $best;
    }
}
