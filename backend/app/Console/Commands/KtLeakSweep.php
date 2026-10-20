<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * NOTHING CLOSED IN ANYTHING CURRENT (2026-10-09).
 *
 * Anthony: "closed and archived providers and children are showing up in emails and on
 * roster ... we cannot ever have this happen in production and there needs to be guards
 * in place". Each reader wrote its own "is it still here?" filter and many had none, so
 * the only reliable check is the outside one: ask every current-state endpoint, as an
 * agency admin, and see whether a closed centre or a withdrawn child comes back; and read
 * the last day's sent mail for the same names.
 *
 * Part 1 (endpoints): every GET under /api/v1 whose path is about centres, rooms,
 * children, rosters, attendance, dashboards, reminders... is called in-process inside a
 * transaction that is rolled back, with mail, queues, HTTP and notifications faked. Paths
 * in HISTORICAL are about the past by design (ledgers, centre history, crash reports).
 *
 * Part 2 (mail): sent email_logs rows from the last --hours that name a centre closed, or
 * a child withdrawn, BEFORE the email was sent. Subjects in LEGIT_MAIL are about the
 * departure itself (leaving notices, final invoices, statements).
 *
 * Runs nightly and after every deploy (DEPLOY-PROTOCOL). Exit 1 + email to the platform
 * admins when anything is found.
 *
 *   php artisan kt:leak-sweep                       all agencies with closed records
 *   php artisan kt:leak-sweep --agency=2 --no-mail  one agency, print only
 *   php artisan kt:leak-sweep --skip-routes         mail check only (fast)
 */
class KtLeakSweep extends Command
{
    protected $signature = 'kt:leak-sweep {--agency= : one agency id} {--as= : user id to call as (default: first active platform admin)}
        {--hours=26 : how far back to read sent mail} {--skip-routes} {--skip-mail} {--no-mail : print only}';
    protected $description = 'Check that no current-state endpoint or recent email shows a closed centre or withdrawn child.';

    /** Endpoints that are about the past by design. Regex on the route URI. */
    public const HISTORICAL = [
        '#^api/v1/platform/crash-reports#',          // stack traces quote whatever screen was open
        '#^api/v1/admin/account-ledgers#',           // money owed by / paid to families that have left
        '#^api/v1/admin/centres/\{[^}]+\}/history#', // a centre's history is its past children
        '#^api/v1/provider/payroll-documents#',      // pay records for work done before a closure
        '#^api/v1/provider/chats#',                  // past conversations keep their participants
        '#^api/v1/provider/observations#',           // the learning record of a child who has left
        '#^api/v1/integration/pull/new#',            // the feed TELLS the source system about departures
    ];

    /** Mail that is about leaving, so it names what has closed. Regex on the subject.
        Also the platform's own alerts (this sweep and kt:integrity-check), which name the
        closed records BY DESIGN — counting them made every sweep re-find the previous
        day's alert and raise a CRITICAL forever (2026-10-13). */
    public const LEGIT_MAIL = '/^\[KiddieTrac\] (Leak sweep|Data check)|leaving|de-?enrol|depart|off-?board|statement|receipt|invoice|refund|credit note|final|closed account|access (has been )?(paused|closed)|your records|withdraw/i';

    private const PATHS = '#(roster|attendance|children|child|centres|centre|provider|staff|educator|famil|dashboard|today|ratio|check|report|summary|digest|people|users|directory|enrol|room|immuni|allerg|medic|birthday|safe|absence|closure|calendar|home|overview|stats|count|zone|late|pickup|option)#i';
    private const SKIP = '#(export|download|pdf|csv|xlsx|file|photo|avatar|image|logo|qr|ical|\.ics|stream|poll|callback|webhook|unsubscribe|oauth|google|track|pixel|preview|print|zip|backup|signed|token|logout|impersonat)#i';

    public function handle(): int
    {
        $agencies = $this->option('agency') ? [(int) $this->option('agency')] : $this->agenciesWithClosedRecords();
        $asId = (int) ($this->option('as') ?: DB::table('role_assignments as ra')->join('users as u', 'u.id', '=', 'ra.user_id')
            ->where('ra.role', 'platform_admin')->where('ra.active', 1)->where('u.status', 'active')->whereNull('u.deleted_at')
            ->orderBy('u.id')->value('u.id'));
        $found = [];

        foreach ($agencies as $agencyId) {
            $needles = $this->needles($agencyId);
            $this->line("agency $agencyId: " . count($needles['centres']) . ' closed centres, ' . count($needles['children']) . ' withdrawn children');
            if (! $this->option('skip-routes') && $asId) {
                foreach ($this->sweepRoutes($agencyId, $asId, $needles) as $line) {
                    $found['Current-state endpoints showing closed records'][] = ['line' => "agency $agencyId: $line"];
                }
            }
            if (! $this->option('skip-mail')) {
                foreach ($this->sweepMail($agencyId, $needles) as $line) {
                    $found['Emails sent about closed records'][] = ['line' => "agency $agencyId: $line"];
                }
            }
        }

        foreach ($found as $title => $rows) {
            $this->error($title . ' (' . count($rows) . ')');
            foreach ($rows as $r) $this->line('   ' . $r['line']);
        }
        if (! $found) {
            $this->info('Clean: no closed centre or withdrawn child in any current endpoint or recent email.');
            return self::SUCCESS;
        }

        Log::critical('[LEAK SWEEP] closed records surfaced', array_map('count', $found));
        if (! $this->option('no-mail')) {
            KtIntegrityCheck::alertPlatformAdmins(
                '[KiddieTrac] Leak sweep — closed providers/children are showing',
                'A current screen or a recent email included a centre that is closed or a child who has left. Each line names the endpoint or email and the record.',
                $found
            );
        }

        return self::FAILURE;
    }

    private function agenciesWithClosedRecords(): array
    {
        $a = DB::table('centres')->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereIn('status', \App\Support\Live::CLOSED_CENTRE_STATUSES))
            ->pluck('agency_id')->all();
        $b = DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where(fn ($q) => $q->where('ch.enrollment_status', 'withdrawn')->orWhereNotNull('ch.deleted_at'))->pluck('c.agency_id')->all();
        return array_values(array_unique(array_map('intval', array_filter(array_merge($a, $b)))));
    }

    /** Names of what is closed, with WHEN it closed (mail sent before that is fine). */
    private function needles(int $agencyId): array
    {
        $live = DB::table('children')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('enrollment_status')->orWhere('enrollment_status', '!=', 'withdrawn'))
            ->get(['first_name', 'last_name'])->map(fn ($c) => mb_strtolower(trim($c->first_name . ' ' . $c->last_name)))->flip();
        $liveCentres = DB::table('centres')->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', \App\Support\Live::CLOSED_CENTRE_STATUSES))
            ->pluck('name')->map(fn ($n) => mb_strtolower(trim($n)))->flip();

        $centres = [];
        foreach (DB::table('centres')->where('agency_id', $agencyId)
            ->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhereIn('status', \App\Support\Live::CLOSED_CENTRE_STATUSES))
            ->get(['id', 'name', 'deleted_at', 'updated_at']) as $c) {
            $n = trim((string) $c->name);
            if ($n !== '' && ! isset($liveCentres[mb_strtolower($n)])) {
                $centres[$n] = ['what' => "closed centre #{$c->id}", 'since' => (string) ($c->deleted_at ?: $c->updated_at)];
            }
        }
        $children = [];
        foreach (DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('c.agency_id', $agencyId)
            ->where(fn ($q) => $q->where('ch.enrollment_status', 'withdrawn')->orWhereNotNull('ch.deleted_at'))
            ->get(['ch.id', 'ch.first_name', 'ch.last_name', 'ch.withdrawn_at', 'ch.deleted_at', 'ch.updated_at', 'f.departure_applied_at']) as $c) {
            $n = trim($c->first_name . ' ' . $c->last_name);
            if ($n === '' || isset($live[mb_strtolower($n)])) continue;   // a live child shares the name
            // Mail is only wrong once the withdrawal was RECORDED (a notice given in advance
            // has a future withdrawn_at, and the family may be told about it before then).
            $since = max(array_filter([(string) $c->departure_applied_at, (string) $c->deleted_at,
                $c->withdrawn_at ? substr((string) $c->withdrawn_at, 0, 10) . ' 23:59:59' : '']) ?: [(string) $c->updated_at]);
            $children[$n] = ['what' => "withdrawn child #{$c->id}", 'since' => $since];
        }
        return ['centres' => $centres, 'children' => $children];
    }

    private function sweepRoutes(int $agencyId, int $asId, array $needles): array
    {
        $all = $needles['centres'] + $needles['children'];
        if (! $all) return [];
        config(['mail.default' => 'array']);
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Bus::fake();
        \Illuminate\Support\Facades\Http::fake();
        \Illuminate\Support\Facades\Notification::fake();

        $user = \App\Models\User::find($asId);
        $centreIds = \App\Support\Live::centreIds($agencyId);
        $roomIds = DB::table('rooms')->whereIn('centre_id', $centreIds)->limit(20)->pluck('id')->all();
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $hits = [];
        $calls = 0;

        foreach (Route::getRoutes() as $r) {
            if (! in_array('GET', $r->methods(), true)) continue;
            $uri = $r->uri();
            if (! str_starts_with($uri, 'api/v1/') || ! preg_match(self::PATHS, $uri) || preg_match(self::SKIP, $uri)) continue;
            foreach (self::HISTORICAL as $h) if (preg_match($h, $uri)) continue 2;

            preg_match_all('/\{(\w+)\??\}/', $uri, $m);
            $paths = [$uri];
            foreach ($m[1] as $p) {
                $vals = preg_match('/centre/i', $p) ? array_slice($centreIds, 0, 8) : (preg_match('/room/i', $p) ? $roomIds : null);
                if ($vals === null) { $paths = []; break; }
                $next = [];
                foreach ($paths as $v) foreach ($vals as $val) $next[] = preg_replace('/\{' . $p . '\??\}/', (string) $val, $v);
                $paths = $next;
            }
            foreach ($paths as $path) {
                DB::beginTransaction();
                try {
                    $req = Request::create('/' . $path, 'GET');
                    $req->headers->set('Accept', 'application/json');
                    $req->headers->set('X-Active-Agency-Id', (string) $agencyId);
                    app('auth')->forgetGuards();
                    \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);
                    $res = $kernel->handle($req);
                    $calls++;
                    if ($res->getStatusCode() < 300) {
                        $body = self::withoutHistoricalFields((string) $res->getContent());
                        foreach ($all as $name => $info) {
                            if (stripos($body, $name) !== false || stripos($body, trim(json_encode($name), '"')) !== false) {
                                $hits[$uri][$info['what'] . ' ' . $name] = true;
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // a crashing endpoint is not this check's subject
                } finally {
                    DB::rollBack();
                }
            }
        }
        $this->line("  endpoints called: $calls");
        $out = [];
        foreach ($hits as $uri => $whats) $out[] = '/' . $uri . ' — ' . implode('; ', array_keys($whats));
        return $out;
    }

    /** JSON keys that are explicitly about the past inside an otherwise current response
     *  (the dashboard's archived_centres, the calendar's departures overlay). Dropped
     *  before scanning, so the rest of the response is still checked. */
    /* + deactivations (2026-10-20, support ticket #158): integration/pull/users lists staff who
       have LEFT under "deactivations" so iLearn can close them; a home provider is named like
       her closed centre, so the departure notice read as a leak. The current part is still checked. */
    public const HISTORICAL_KEYS = '/^(archived(_[a-z_]+)?|departures?|departed(_[a-z_]+)?|deactivations?|history|leaving(_[a-z_]+)?)$/i';

    public static function withoutHistoricalFields(string $body): string
    {
        $j = json_decode($body, true);
        if (! is_array($j)) {
            return $body;
        }
        /* An item dated before today is the past by definition (a calendar's absences
           earlier this month, when the child was still enrolled). Today and later are
           still checked: a withdrawn child on a FUTURE date is exactly the leak. */
        $today = now()->toDateString();
        $past = function ($x) use ($today) {
            if (! is_array($x)) return false;
            foreach (['date', 'day', 'end_date'] as $dk) {
                if (isset($x[$dk]) && is_string($x[$dk]) && preg_match('/^\d{4}-\d{2}-\d{2}/', $x[$dk])) {
                    return substr($x[$dk], 0, 10) < $today;
                }
            }
            return false;
        };
        $strip = function ($v) use (&$strip, $past) {
            if (! is_array($v)) return $v;
            foreach ($v as $k => $x) {
                if (is_string($k) && preg_match(self::HISTORICAL_KEYS, $k)) { unset($v[$k]); continue; }
                if (is_int($k) && $past($x)) { unset($v[$k]); continue; }
                $v[$k] = $strip($x);
            }
            return $v;
        };

        return json_encode($strip($j), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function sweepMail(int $agencyId, array $needles): array
    {
        $all = $needles['centres'] + $needles['children'];
        if (! $all) return [];
        $out = [];
        DB::table('email_logs')->where('agency_id', $agencyId)->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))
            ->where('status', '!=', 'suppressed')->orderBy('id')->select(['id', 'subject', 'created_at', 'body_html'])
            ->chunk(200, function ($rows) use ($all, &$out) {
                foreach ($rows as $r) {
                    if (preg_match(self::LEGIT_MAIL, (string) $r->subject)) continue;
                    $text = html_entity_decode(strip_tags((string) $r->body_html)) . ' ' . $r->subject;
                    foreach ($all as $name => $info) {
                        if ((string) $r->created_at > $info['since'] && stripos($text, $name) !== false) {
                            $out[] = "email #{$r->id} ({$r->created_at}) \"" . mb_substr((string) $r->subject, 0, 70) . "\" — {$info['what']} {$name}";
                        }
                    }
                }
            });
        return $out;
    }
}
