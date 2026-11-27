<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * LEAK GUARD (2026-11-27). Anthony: "leaks should have a guard to constantly check for cross leaks".
 *
 * Four layers, one place for what they share:
 *   1. canaries      kt:leak-canary plants marked records (KTCANARY-<agency>-<hex>) in TWO test agencies
 *                    (6 and 18 - test agencies send nothing). Every planted row is listed in leak_canaries.
 *   2. probe         kt:leak-probe calls every GET API route in-process as canary people of agency A (seven roles,
 *                    own header, spoofed header, spoofed query ids, B's ids on {id} routes) and fails on any B marker.
 *   3. integrity     kt:leak-integrity: SQL rows whose agency disagrees with their parent's.
 *   4. sentinel      LeakSentinel middleware: live responses carrying an agency_id the signed-in person has no
 *                    membership in, or a canary marker of another agency. Log only, never blocks.
 * Findings alert the PLATFORM only (TicketAlerts: bell + HQ inbox + platform admins) and file one support ticket.
 */
final class LeakGuard
{
    /** The canary pair. Both are test agencies (settings.demo) - see kiddietrac-test-agencies-send-nothing. */
    public const AGENCIES = [6, 18];

    /** A marker. 10 hex chars after the agency, unguessable and never a real word. */
    public const MARKER_RE = '/KTCANARY-(\d+)-[0-9a-f]{10}/';

    public const EMAIL_DOMAIN = 'leakguard.invalid';

    public const TICKET_SUBJECT = 'Leak guard: cross-agency data found (P0)';

    /** Is this user id one of the planted canary people? */
    public static function isCanaryUser(int $userId): bool
    {
        static $ids = null;
        if ($ids === null) {
            try {
                $ids = array_flip(DB::table('leak_canaries')->where('table_name', 'users')->pluck('row_id')->map(fn ($v) => (int) $v)->all());
            } catch (Throwable $e) {
                $ids = [];
            }
        }
        return isset($ids[$userId]);
    }

    /** kind => row_id for one agency's canaries. */
    public static function canaries(int $agencyId): array
    {
        return DB::table('leak_canaries')->where('agency_id', $agencyId)->pluck('row_id', 'kind')
            ->map(fn ($v) => (int) $v)->all();
    }

    public static function marker(int $agencyId): ?string
    {
        return DB::table('leak_canaries')->where('agency_id', $agencyId)->value('marker');
    }

    /**
     * Every agency this person legitimately belongs to: a role row (incl. reseller grants and the integration role),
     * a centre named on a role row, or a guardian of a family there. Cached 10 minutes.
     *
     * @return int[]
     */
    public static function memberships(int $userId): array
    {
        return Cache::remember('leakguard:member:' . $userId, 600, function () use ($userId) {
            $a = DB::table('role_assignments')->where('user_id', $userId)->where('active', 1)->whereNotNull('agency_id')->pluck('agency_id');
            $b = DB::table('role_assignments as ra')->join('centres as c', 'c.id', '=', 'ra.centre_id')
                ->where('ra.user_id', $userId)->where('ra.active', 1)->pluck('c.agency_id');
            $c = DB::table('guardians as g')->join('families as f', 'f.id', '=', 'g.family_id')->join('centres as c', 'c.id', '=', 'f.centre_id')
                ->where('g.user_id', $userId)->pluck('c.agency_id');
            return $a->merge($b)->merge($c)->map(fn ($v) => (int) $v)->unique()->values()->all();
        });
    }

    public static function isPlatformAdmin(int $userId): bool
    {
        return (bool) Cache::remember('leakguard:pa:' . $userId, 600, fn () => DB::table('role_assignments')
            ->where('user_id', $userId)->where('active', 1)->where('role', 'platform_admin')->exists() ? 1 : 0);
    }

    /** Server health card: the last run of each kind + open sentinel hits. */
    public static function status(): array
    {
        try {
            $last = function (string $kind, ?string $mode = null) {
                $r = DB::table('leak_guard_runs')->where('kind', $kind)->whereNotNull('finished_at')
                    ->where(fn ($q) => $q->whereNull('mode')->orWhere('mode', 'not like', '% of #%'))   // not a full run's working slices
                    ->when($mode, fn ($q) => $q->where('mode', $mode))->orderByDesc('id')->first();
                if (! $r) {
                    return null;
                }
                $s = json_decode((string) $r->summary, true) ?: [];
                return [
                    'at' => (string) $r->finished_at, 'mode' => $r->mode, 'routes' => (int) $r->routes_checked,
                    'calls' => (int) $r->calls, 'findings' => (int) $r->findings, 'blind' => (int) $r->blind,
                    'secs' => $s['secs'] ?? null, 'lines' => array_slice($s['lines'] ?? [], 0, 12),
                    'coverage' => $s['coverage'] ?? null,
                ];
            };
            return [
                'probe' => $last('probe'),
                'probe_full' => $last('probe', 'all'),
                'integrity' => $last('integrity'),
                'sentinel_7d' => (int) DB::table('leak_sentinel_hits')->where('day', '>=', now()->subDays(7)->toDateString())->count(),
                'sentinel_lines' => DB::table('leak_sentinel_hits')->where('day', '>=', now()->subDays(7)->toDateString())
                    ->orderByDesc('last_at')->limit(8)->get()
                    ->map(fn ($h) => "{$h->method} /{$h->route}: user {$h->user_id} in agency {$h->active_agency_id} got agency {$h->foreign_agency_id} ({$h->signal}, {$h->hits}x, {$h->day})")->all(),
            ];
        } catch (Throwable $e) {
            return ['error' => 'not installed'];
        }
    }

    /**
     * Alert the platform about a run's findings (and any sentinel hits not yet alerted).
     * Same findings as the last alert within 24h = silent (a check that repeats itself every hour gets muted).
     */
    public static function alert(?int $runId): int
    {
        $lines = [];
        $title = 'Cross-agency data';
        if ($runId) {
            $run = DB::table('leak_guard_runs')->where('id', $runId)->first();
            if ($run && (int) $run->findings > 0) {
                $s = json_decode((string) $run->summary, true) ?: [];
                foreach ($s['lines'] ?? [] as $l) {
                    $lines[] = '[' . $run->kind . '] ' . $l;
                }
            }
            if ($run && (int) $run->blind > 0) {
                $lines[] = '[' . $run->kind . '] probe is BLIND: canary records were not visible to their own agency (' . (int) $run->blind . ' roles). Re-plant with kt:leak-canary.';
            }
        }
        $hits = DB::table('leak_sentinel_hits')->whereNull('alerted_at')->orderBy('id')->limit(50)->get();
        foreach ($hits as $h) {
            $lines[] = "[sentinel] {$h->method} /{$h->route}: user #{$h->user_id} (agency {$h->active_agency_id}) was sent agency {$h->foreign_agency_id} data ({$h->signal}, {$h->hits}x on {$h->day})";
        }
        if (! $lines) {
            return 0;
        }

        $sig = md5(implode("\n", array_map(fn ($l) => preg_replace('/\d+x on [\d-]+|\(\d+ roles\)/', '', $l), $lines)));
        $sigKey = 'leakguard:alert-sig:' . (isset($run) && $run ? $run->kind : 'sentinel');
        $prev = Cache::get($sigKey);
        if ($hits->isNotEmpty()) {
            DB::table('leak_sentinel_hits')->whereIn('id', $hits->pluck('id'))->update(['alerted_at' => now()]);
        }
        if ($prev === $sig) {
            return 0;
        }
        Cache::put($sigKey, $sig, 86400);

        $body = "Leak guard found data from one agency reaching people of another. Each line names the check, the route or table, and the record.\n\n"
            . implode("\n", array_slice($lines, 0, 80))
            . "\n\nRepro: php8.3 artisan kt:leak-probe --all --no-alert (or --route=<uri>) / kt:leak-integrity --no-alert. Platform > Server health > Leak guard.";
        $ticket = ProblemReport::fileTicket(self::TICKET_SUBJECT, $body, null, null, 'high');
        $e = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:Arial,sans-serif;max-width:760px;"><h2 style="font-size:18px;margin:0 0 8px;">' . $e(self::TICKET_SUBJECT) . '</h2>'
            . '<p style="font-size:14px;">Leak guard found data from one agency reaching people of another. Treat as P0.</p><ul style="font-size:13px;padding-left:18px;">';
        foreach (array_slice($lines, 0, 60) as $l) {
            $html .= '<li>' . $e($l) . '</li>';
        }
        $html .= '</ul><p style="font-size:12px;color:#64748B;">Automated guard (kt:leak-probe / kt:leak-integrity / live sentinel). Platform admins only.'
            . ($ticket ? ' Support ticket #' . (int) $ticket . '.' : '') . '</p></div>';
        try {
            TicketAlerts::bell((int) ($ticket ?: 0), 'Leak guard: ' . count($lines) . ' cross-agency finding(s)', mb_substr($lines[0], 0, 190));
            TicketAlerts::email('[KiddieTrac] ' . self::TICKET_SUBJECT, $html);
        } catch (Throwable $ex) {
            Log::critical('[LEAK GUARD] alert failed: ' . $ex->getMessage());
        }
        if ($runId) {
            DB::table('leak_guard_runs')->where('id', $runId)->update(['alerted_at' => now()]);
        }
        Log::critical('[LEAK GUARD] ' . count($lines) . ' finding(s)', ['ticket' => $ticket]);

        return count($lines);
    }
}
