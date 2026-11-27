<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\LeakGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * LIVE EGRESS SENTINEL - leak guard layer 4 (2026-11-27). LOG ONLY: it never blocks, never changes a response and
 * never stores a body.
 *
 * For a signed-in person who is not a platform admin, on a JSON response:
 *   - every response: a canary marker (KTCANARY-<agency>-...) of an agency the person does not belong to;
 *   - a sample (1 in KT_LEAK_SENTINEL_RATE, default 4): any "agency_id": N where N is neither the active agency nor
 *     one of the person's own agencies (LeakGuard::memberships - roles, centres, guardian families, reseller grants).
 * A person in two agencies seeing their own records, a reseller in its own agencies and the platform are therefore
 * never flagged. Platform-level areas (platform, sales, reseller) are not looked at.
 *
 * A hit is one leak_sentinel_hits row per day/route/person/foreign agency (hits counter). The hourly kt:leak-probe
 * alerts the platform about new rows (LeakGuard::alert). In kt:leak-probe itself, hits are collected in $seen.
 * Kill switch: KT_LEAK_SENTINEL=off.
 */
final class LeakSentinel
{
    /** In-process probe mode: collect, don't write. */
    public static bool $record = false;
    public static array $seen = [];

    private const MAX_BYTES = 3_000_000;
    /* Not looked at: platform-level areas, and /branding - a public brand lookup BY DESIGN (any agency's colours and
       logo for its sign-in page; names stripped for strangers since the 2026-11-05 sweep). */
    private const SKIP_PREFIX = '#^api/v1/(platform|sales|reseller|auth/me$|branding$)#';

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            $this->inspect($request, $response);
        } catch (Throwable $e) {
            // A watcher must never become the fault.
        }
        return $response;
    }

    private function inspect(Request $request, Response $response): void
    {
        if ($response->getStatusCode() >= 300 || env('KT_LEAK_SENTINEL') === 'off') {
            return;
        }
        if (! str_contains((string) $response->headers->get('Content-Type'), 'json')) {
            return;
        }
        $body = $response->getContent();
        if (! is_string($body) || $body === '' || strlen($body) > self::MAX_BYTES) {
            return;
        }
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return;
        }
        $path = $request->path();
        if (preg_match(self::SKIP_PREFIX, $path)) {
            return;
        }
        $uid = (int) $user->id;
        $active = (int) $request->headers->get('X-Active-Agency-Id');

        $found = [];   // foreign agency => signal
        if (str_contains($body, 'KTCANARY-') && preg_match_all(LeakGuard::MARKER_RE, $body, $m)) {
            foreach (array_unique($m[1]) as $ag) {
                if ((int) $ag !== $active) {
                    $found[(int) $ag] = 'canary';
                }
            }
        }
        $rate = max(1, (int) (env('KT_LEAK_SENTINEL_RATE') ?: 4));
        if ((self::$record || mt_rand(1, $rate) === 1) && str_contains($body, '"agency_id"')
            && preg_match_all('/"agency_id"\s*:\s*"?(\d+)/', $body, $m)) {
            foreach (array_unique($m[1]) as $ag) {
                $ag = (int) $ag;
                if ($ag && $ag !== $active && ! isset($found[$ag])) {
                    $found[$ag] = 'agency_id';
                }
            }
        }
        if (! $found || LeakGuard::isPlatformAdmin($uid)) {
            return;
        }
        $mine = array_flip(LeakGuard::memberships($uid));
        $route = $request->route() ? (string) $request->route()->uri() : $path;
        foreach ($found as $ag => $signal) {
            if (isset($mine[$ag])) {
                continue;   // their own other agency (a person in two agencies, a reseller): not a leak
            }
            if (self::$record) {
                self::$seen[] = ['foreign' => $ag, 'signal' => $signal, 'route' => $route];
                continue;
            }
            $now = now();
            DB::statement('INSERT INTO leak_sentinel_hits (day, method, route, user_id, active_agency_id, foreign_agency_id, `signal`, hits, first_at, last_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?) ON DUPLICATE KEY UPDATE hits = hits + 1, last_at = VALUES(last_at)',
                [$now->toDateString(), $request->method(), mb_substr($route, 0, 191), $uid, $active ?: null, $ag, $signal, $now, $now]);
        }
    }
}
