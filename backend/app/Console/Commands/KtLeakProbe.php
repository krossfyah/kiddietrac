<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Http\Middleware\LeakSentinel;
use App\Support\LeakGuard;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * kt:leak-probe - THE CROSS-AGENCY RUNTIME PROBE (leak guard layer 2, 2026-11-27).
 *
 * Every GET route under /api/v1 is read from the router (a new route is covered the day it ships, nobody edits a
 * list) and called IN-PROCESS through the HTTP kernel - middleware and all, no network, no token - as each canary
 * person of agency A (agency admin, director, educator, home visitor, parent, staff-who-is-a-parent, integration):
 *
 *   own           header = A                                    positive control: A's own marker should show up
 *   spoof-header  header = B (NormalizeActiveAgency must undo it)
 *   spoof-query   ?agency_id=B&centre_id=..&room_id=..&child_id=..&family_id=..&user_id=..  (B's canary ids)
 *   idor          {id} routes filled with B's canary ids (child, family, centre, room, user, conversation, invoice...)
 *   own-ids       the same {id} routes with A's ids                 (coverage only)
 *
 * A FINDING is B's canary marker (KTCANARY-<B>-...) or one of B's canary ids in a *_id field, in any response a
 * person of A received; plus anything the LeakSentinel middleware flagged during the call. Then A and B swap.
 *
 * Each call runs inside a transaction that is rolled back, with mail, notifications, queue, bus and HTTP faked, push
 * stubbed and pending bell pushes dropped. Only GET. Only canary people of test agencies. Never a real job.
 *
 *   kt:leak-probe --all              every route (nightly)
 *   kt:leak-probe --hourly           this hour's slice: crc32(uri) % 24 == hour (each route once a day, extra)
 *   kt:leak-probe --route=hr/people  routes whose uri contains this
 *   kt:leak-probe --gate             --all --no-alert; exit 1 on any finding (deploy gate)
 */
final class KtLeakProbe extends Command
{
    protected $signature = 'kt:leak-probe {--all} {--hourly} {--slice=} {--slices=24} {--route=} {--role=}
        {--gate : full run, no alert, exit 1 on findings} {--no-alert} {--quiet-ok} {--max-secs=0}';

    protected $description = 'Call every GET API route as canary people of one test agency and fail on any other agency\'s canary data.';

    /** Routes never called, with the reason. Regex on the uri (without api/v1/). */
    public const SKIP = [
        '#(^|/)(stream|sse|long-?poll|events/stream)(/|$)#' => 'long-lived stream, never ends',
        '#impersonat#' => 'mints view-as sessions',
        '#(^|/)(logout|sign-?out)(/|$)#' => 'ends a session',
        '#(oauth|callback|webhook|/connect$|redirect)#' => 'third-party hand-off, not a data read',
        '#^(public|marketing-site|c|cal|diag|email/(open|click|track))/#' => 'public / signed-link door: no signed-in person, scoped by its token',
        '#(^|/)(unsubscribe|pixel|track)(/|$)#' => 'email tracking link',
        '#^demo/#' => 'website demo bootstrap (mints demo sessions)',
    ];

    /** {param} name => canary kind. */
    private const PARAM = [
        'child' => 'child', 'childId' => 'child', 'cid' => 'child',
        'family' => 'family', 'familyId' => 'family',
        'centre' => 'centre', 'centreId' => 'centre',
        'room' => 'room',
        'user' => 'user:educator', 'uid' => 'user:educator', 'u' => 'user:educator', 'guardian' => 'user:guardian',
        'conversation' => 'conversation', 'invoice' => 'invoice', 'form' => 'custom_form',
        'doc' => 'document', 'file' => 'family_file', 'fileId' => 'family_file',
        'agencyId' => 'agency', 'aid' => 'agency',
    ];

    /** For {id}: the path segment before it => canary kind. */
    private const SEGMENT = [
        'children' => 'child', 'child' => 'child', 'families' => 'family', 'family' => 'family',
        'centres' => 'centre', 'centre' => 'centre', 'centers' => 'centre', 'rooms' => 'room', 'room' => 'room',
        'users' => 'user:educator', 'staff' => 'user:educator', 'people' => 'user:educator', 'educators' => 'user:educator',
        'employees' => 'user:educator', 'guardians' => 'user:guardian', 'parents' => 'user:guardian',
        'conversations' => 'conversation', 'chats' => 'conversation', 'messages' => 'message',
        'threads' => 'staff_thread', 'team-chat' => 'staff_thread', 'staff-chat' => 'staff_thread',
        'invoices' => 'invoice', 'announcements' => 'announcement', 'incidents' => 'incident',
        'notes' => 'child_note', 'child-notes' => 'child_note', 'quick-notes' => 'quick_note',
        'forms' => 'custom_form', 'custom-forms' => 'custom_form', 'documents' => 'document',
        'files' => 'family_file', 'family-files' => 'family_file', 'events' => 'daily_event', 'daily-events' => 'daily_event',
        'notifications' => 'notification:admin', 'employee-files' => 'hr_employee_file', 'agencies' => 'agency',
    ];

    /** *_id keys whose value, if it is one of B's canary ids of that kind, is a leak. */
    private const ID_KEYS = ['child_id' => 'child', 'family_id' => 'family', 'centre_id' => 'centre', 'room_id' => 'room',
        'conversation_id' => 'conversation', 'thread_id' => 'staff_thread', 'invoice_id' => 'invoice'];

    private const ROLES = ['agency_admin', 'centre_director', 'educator', 'home_visitor', 'guardian', 'staff_parent', 'integration'];

    private $kernel;
    private int $calls = 0;
    private float $callSecs = 0.0;
    private array $slow = [];
    private array $statusCounts = [];

    public function handle(): int
    {
        $t0 = microtime(true);
        if (function_exists('proc_nice')) {
            @proc_nice(10);   // the web comes first (kiddietrac-scheduler-cpu)
        }
        $gate = (bool) $this->option('gate');
        $all = $gate || $this->option('all');
        $mode = $all ? 'all' : ($this->option('route') ? 'route' : 'slice');

        foreach (LeakGuard::AGENCIES as $a) {
            KtLeakCanary::ensure($a);
        }
        [$a, $b] = LeakGuard::AGENCIES;
        $pairs = [[$a, $b], [$b, $a]];

        // ---- the routes ------------------------------------------------------------------------------------------
        $routes = [];
        $skipped = [];
        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true) || ! str_starts_with($r->uri(), 'api/v1/')) {
                continue;
            }
            $uri = substr($r->uri(), 7);
            foreach (self::SKIP as $re => $why) {
                if (preg_match($re, $uri)) {
                    $skipped[$why][] = $uri;
                    continue 2;
                }
            }
            $routes[$uri] = $r;
        }
        ksort($routes);
        $totalGet = count($routes) + array_sum(array_map('count', $skipped));
        if ($f = $this->option('route')) {
            $routes = array_filter($routes, fn ($r, $u) => str_contains($u, $f), ARRAY_FILTER_USE_BOTH);
        } elseif (! $all) {
            $slices = max(1, (int) $this->option('slices'));
            $slice = $this->option('slice') !== null ? (int) $this->option('slice') : ((int) now()->format('G')) % $slices;
            $routes = array_filter($routes, fn ($r, $u) => crc32($u) % $slices === $slice, ARRAY_FILTER_USE_BOTH);
            $mode = "slice {$slice}/{$slices}";
        }

        $runId = DB::table('leak_guard_runs')->insertGetId(['kind' => 'probe', 'mode' => $mode, 'started_at' => now()]);
        $this->line("Leak probe ({$mode}): " . count($routes) . " of {$totalGet} GET routes, agencies {$a} <-> {$b}");

        $this->fakeOutbound();
        $this->kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        LeakSentinel::$record = true;

        $findings = [];
        $ownSeen = [];      // "agency role" => routes where the own marker was visible
        $mapped = 0;
        $unmapped = [];
        $roleFilter = $this->option('role');
        $maxSecs = (int) $this->option('max-secs');
        $stopped = false;

        foreach ($pairs as [$A, $B]) {
            $canA = LeakGuard::canaries($A);
            $canB = LeakGuard::canaries($B);
            $people = [];
            foreach (self::ROLES as $role) {
                if ($roleFilter && $roleFilter !== $role) {
                    continue;
                }
                $uid = $canA['user:' . $role] ?? null;
                if ($uid && ($u = \App\Models\User::find($uid))) {
                    $people[$role] = $u;
                }
            }
            $bIds = [];
            foreach ($canB as $k => $id) {
                $bIds[preg_replace('/\d+$/', '', $k)][(string) $id] = true;
            }
            $spoofQuery = http_build_query(['agency_id' => $B, 'centre_id' => $canB['centre'] ?? 0, 'room_id' => $canB['room'] ?? 0,
                'child_id' => $canB['child'] ?? 0, 'family_id' => $canB['family'] ?? 0, 'user_id' => $canB['user:educator'] ?? 0]);

            $n = 0;
            foreach ($routes as $uri => $r) {
                if ($this->output->isVerbose() && ++$n % 100 === 0) {
                    $this->line("  agency {$A}: {$n} routes, {$this->calls} calls, " . count($findings) . ' findings so far');
                }
                if ($maxSecs && microtime(true) - $t0 > $maxSecs) {
                    $stopped = true;
                    break 2;
                }
                preg_match_all('/\{(\w+)\??\}/', $uri, $m);
                $params = $m[1];
                $idorPath = $ownPath = null;
                if ($params) {
                    $idorPath = $this->fill($uri, $params, $canB, $B);
                    $ownPath = $this->fill($uri, $params, $canA, $A);
                    if ($idorPath === null) {
                        $unmapped[$uri] = true;
                        continue;
                    }
                    $mapped++;
                }
                foreach ($people as $role => $u) {
                    $who = "agency {$A} {$role}";
                    $plans = $params
                        ? [['idor', $idorPath, $A], ['own-ids', $ownPath, $A]]
                        : [['own', $uri, $A], ['spoof-header', $uri, $B], ['spoof-query', $uri . '?' . $spoofQuery, $A]];
                    $ownStatus = null;
                    foreach ($plans as [$how, $path, $hdr]) {
                        if (($how === 'spoof-header' || $how === 'spoof-query') && in_array($ownStatus, [401, 403], true)) {
                            continue;   // refused by role in its own context - the other modes cannot open it (header is normalised)
                        }
                        [$status, $body, $sentinel] = $this->probeCall($path, $u, $hdr);
                        if ($how === 'own' || $how === 'idor') {
                            $ownStatus = $status;
                        }
                        foreach ($sentinel as $s) {
                            if ((int) $s['foreign'] !== $A) {
                                $findings["{$uri}|{$role}|{$how}|sentinel{$s['foreign']}"] = "{$who}: GET /{$uri} [{$how}] -> sentinel: response carried agency {$s['foreign']} ({$s['signal']})";
                            }
                        }
                        if ($body === null || $body === '') {
                            continue;
                        }
                        if (preg_match_all(LeakGuard::MARKER_RE, $body, $mm)) {
                            foreach (array_unique($mm[1]) as $ag) {
                                if ((int) $ag === $A) {
                                    if ($how === 'own' || $how === 'own-ids') {
                                        $ownSeen[$who][$uri] = true;
                                    }
                                } else {
                                    $findings["{$uri}|{$role}|{$how}|m{$ag}"] = "{$who}: GET /{$uri} [{$how}] -> agency {$ag} canary text (HTTP {$status})";
                                }
                            }
                        }
                        if ($how !== 'spoof-query' && preg_match_all('/"(' . implode('|', array_keys(self::ID_KEYS)) . ')"\s*:\s*"?(\d+)"?/', $body, $im, PREG_SET_ORDER)) {
                            foreach ($im as $hit) {
                                $kind = self::ID_KEYS[$hit[1]];
                                if (isset($bIds[$kind][$hit[2]]) && ($how !== 'idor' || ! $this->echoed($path, $hit[2]))) {
                                    $findings["{$uri}|{$role}|{$how}|{$hit[1]}"] = "{$who}: GET /{$uri} [{$how}] -> agency {$B} canary {$hit[1]}={$hit[2]} (HTTP {$status})";
                                }
                            }
                        }
                    }
                }
            }
        }
        LeakSentinel::$record = false;

        // ---- blind check: every role except integration must see its OWN canary somewhere ---------------------------
        $blind = [];
        foreach ($pairs as [$A]) {
            foreach (self::ROLES as $role) {
                if ($role === 'integration' || ($roleFilter && $roleFilter !== $role)) {
                    continue;
                }
                if ($all && empty($ownSeen["agency {$A} {$role}"])) {
                    $blind[] = "agency {$A} {$role}";
                }
            }
        }

        $secs = (int) round(microtime(true) - $t0);
        $lines = array_values($findings);
        sort($lines);
        $coverage = [
            'get_routes' => $totalGet, 'probed' => count($routes) - count($unmapped), 'id_routes_mapped' => $mapped,
            'id_routes_unmapped' => count($unmapped), 'skipped' => array_map('count', $skipped),
            'roles' => count(self::ROLES), 'own_visible' => array_map('count', $ownSeen), 'statuses' => $this->statusCounts,
            'avg_ms' => $this->calls ? round($this->callSecs / $this->calls * 1000, 1) : null,
            'stopped_early' => $stopped,
        ];
        arsort($this->slow);
        DB::table('leak_guard_runs')->where('id', $runId)->update([
            'finished_at' => now(), 'routes_checked' => count($routes) - count($unmapped), 'calls' => $this->calls,
            'findings' => count($lines), 'blind' => count($blind),
            'summary' => json_encode(['secs' => $secs, 'lines' => array_slice($lines, 0, 300), 'blind' => $blind, 'coverage' => $coverage,
                'slowest' => array_slice($this->slow, 0, 15, true), 'unmapped' => array_slice(array_keys($unmapped), 0, 400)], JSON_UNESCAPED_SLASHES),
        ]);

        $this->line("  {$this->calls} calls in {$secs}s (avg {$coverage['avg_ms']} ms); id routes: {$mapped} probed, " . count($unmapped) . ' not mappable');
        foreach ($coverage['own_visible'] as $who => $n) {
            if ($this->output->isVerbose()) {
                $this->line("  {$who}: own canary visible on {$n} routes");
            }
        }
        if ($blind) {
            $this->warn('  BLIND (own canary never visible): ' . implode(', ', $blind));
        }
        if ($lines) {
            $this->error(count($lines) . ' cross-agency finding(s):');
            foreach ($lines as $l) {
                $this->line('   ' . $l);
            }
        } elseif (! $this->option('quiet-ok')) {
            $this->info('Clean: no agency saw another agency\'s canary data.');
        }

        if (! $gate && ! $this->option('no-alert')) {
            // Mail/HTTP are faked in this process, so the platform alert goes out from a fresh one.
            try {
                $p = new \Symfony\Component\Process\Process([PHP_BINARY, base_path('artisan'), 'kt:leak-alert', '--run=' . $runId]);
                $p->setTimeout(120);
                $p->run();
                $this->line(trim($p->getOutput()));
            } catch (Throwable $e) {
                $this->warn('alert process failed: ' . $e->getMessage());
            }
        }

        return ($lines || ($all && $blind)) ? self::FAILURE : self::SUCCESS;
    }

    /** Fill every {param} with a canary id of the right kind, or null when one cannot be mapped. */
    private function fill(string $uri, array $params, array $can, int $agencyId): ?string
    {
        $path = $uri;
        foreach ($params as $p) {
            $kind = self::PARAM[$p] ?? null;
            if ($p === 'thread') {
                $kind = preg_match('#(staff|team)#', $uri) ? 'staff_thread' : 'conversation';
            }
            if ($kind === null && $p === 'id') {
                if (! preg_match('#([\w-]+)/\{id\??\}#', $uri, $sm)) {
                    return null;
                }
                $kind = self::SEGMENT[$sm[1]] ?? null;
            }
            if ($kind === null) {
                return null;
            }
            $val = $kind === 'agency' ? $agencyId : ($can[$kind] ?? null);
            if (! $val) {
                return null;
            }
            $path = preg_replace('/\{' . $p . '\??\}/', (string) $val, $path, 1);
        }
        return $path;
    }

    /** A refused id-swap often echoes the id it was asked for ({"child_id": 123, "error": ...}); that is not a leak. */
    private function echoed(string $path, string $id): bool
    {
        return (bool) preg_match('#/' . preg_quote($id, '#') . '(/|$|\?)#', $path);
    }

    /** @return array{0:int|string,1:?string,2:array} */
    private function probeCall(string $path, $user, int $headerAgency): array
    {
        $status = 0;
        $body = null;
        LeakSentinel::$seen = [];
        $t = microtime(true);
        DB::beginTransaction();
        try {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(30);   // one call never holds the run (a route stuck in a loop is reported as a timeout)
            }
            $req = Request::create('/api/v1/' . $path, 'GET');
            $req->headers->set('Accept', 'application/json');
            $req->headers->set('X-Active-Agency-Id', (string) $headerAgency);
            $req->server->set('REMOTE_ADDR', '127.0.0.1');
            app('auth')->forgetGuards();
            \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
            $res = $this->kernel->handle($req);
            $status = $res->getStatusCode();
            if ($status < 300 && ! ($res instanceof \Symfony\Component\HttpFoundation\StreamedResponse)
                && ! ($res instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse)) {
                $c = $res->getContent();
                $body = is_string($c) ? $c : null;
            }
        } catch (Throwable $e) {
            $status = str_contains($e->getMessage(), 'leak-probe timeout') ? 'timeout' : 'ex';
        } finally {
            if (function_exists('pcntl_alarm')) {
                pcntl_alarm(0);
            }
            while (DB::transactionLevel() > 0) {
                try {
                    DB::rollBack();
                } catch (Throwable $e) {
                    break;
                }
            }
            $this->dropPendingPush();
            // Sanctum::actingAs makes a Mockery token per call; the container keeps every one (300 MB over a full run).
            if ($this->calls % 200 === 0) {
                if (class_exists(\Mockery::class)) {
                    \Mockery::resetContainer();
                }
                gc_collect_cycles();
                $this->fakeMessaging();   // fresh fakes: the old ones keep every faked mail/job and slow each call down
            }
        }
        $dt = microtime(true) - $t;
        $this->calls++;
        $this->callSecs += $dt;
        $this->statusCounts[(string) $status] = ($this->statusCounts[(string) $status] ?? 0) + 1;
        $key = preg_replace('/\?.*/', '', $path);
        if ($dt > 1.0) {
            $this->slow[$key] = max($this->slow[$key] ?? 0, round($dt, 2));
        }

        return [$status, $body, LeakSentinel::$seen];
    }

    private function fakeOutbound(): void
    {
        // Cache writes are not rolled back: a GET that marks a popup as seen in the cache must not do it for real.
        config(['cache.default' => 'array']);
        app('cache')->setDefaultDriver('array');
        $this->fakeMessaging();
        \Illuminate\Support\Facades\Http::fake();
        // Thousands of expected "foreign agency header" warnings from the spoof calls stay out of laravel.log.
        config(['logging.default' => 'null']);
        app('log')->setDefaultDriver('null');
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGALRM, function () {
                throw new \RuntimeException('leak-probe timeout');
            });
        }
        app()->instance(\App\Services\FcmService::class, new class extends \App\Services\FcmService {
            public function sendToTokens(array $tokens, string $title, string $body, array $data = [], bool $urgent = false, ?array $sound = null): array
            {
                return [];
            }

            public function sendToUser(int $userId, string $title, string $body, string $link = '', bool $urgent = false, bool $forceUrgent = false, bool $webFallback = true, array $extra = []): array
            {
                return [];
            }
        });
        // 1,000+ calls as one person would trip the rate limiters and hide routes behind 429s.
        $pass = new class {
            public function handle($request, \Closure $next, ...$args)
            {
                return $next($request);
            }
        };
        app()->instance(\Illuminate\Routing\Middleware\ThrottleRequests::class, $pass);
        app()->instance(\Illuminate\Routing\Middleware\ThrottleRequestsWithRedis::class, $pass);
    }

    private function fakeMessaging(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Notification::fake();
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Bus::fake();
    }

    /** Bell rows written during a rolled-back call would still push at process end (Notify::flushPush). */
    private function dropPendingPush(): void
    {
        try {
            $rp = new \ReflectionProperty(\App\Support\Notify::class, 'pendingPush');
            $rp->setAccessible(true);
            $rp->setValue(null, []);
        } catch (Throwable $e) {
        }
    }
}
