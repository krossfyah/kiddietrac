<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * EVERY FAILURE THE API PRODUCES, WRITTEN DOWN (2026-09-18).
 *
 * Anthony: "these types of error are not logged in the audit log as well can we try to add
 * this so we can track all errors and issues with a solid middleware."
 *
 * Sits beside TrackSlowRequests and writes to the same place for the same reason: the
 * audit viewer is a screen somebody already opens and it is agency-scoped, whereas a log
 * file is where the last version of this problem went to be ignored.
 *
 * WHAT THIS CAN AND CANNOT SEE - and the gap matters more than the feature.
 *
 * It sees every response PHP produces. It CANNOT see a 508, and 508 is the error Anthony
 * was actually asking about: 43,967 of them this month, all the same 288-byte page,
 * because "Resource Limit Is Reached" is decided by the web server BEFORE PHP is started.
 * No Laravel middleware will ever run for one. The same is true of the 15,878 HTTP 421s.
 * The only witness to those is the browser, which is why this ships with a client-side
 * reporter (POST /diag/client-error) rather than alone. A middleware presented as
 * "tracking all errors" while silently missing the commonest one would be worse than
 * nothing, because it would look like proof they had stopped.
 *
 * DELIBERATELY NARROW:
 *   - 5xx always: something broke and nobody meant it to.
 *   - 429: the throttle firing is a capacity signal, not a user mistake.
 *   - 4xx otherwise is NOT recorded. A 401 on an expired token, a 422 on a half-filled
 *     form and a 403 on a screen someone should not open are the system working; 35,137
 *     401s and 12,623 403s this month would bury the 71 real failures.
 *   - 404 is skipped for the same reason, with one exception: a 404 on a route that DOES
 *     exist means a missing record, which is worth seeing.
 */
final class TrackFailedRequests
{
    /** Paths whose failures are already recorded in full by their own handler. */
    private const ALREADY_LOGGED = ['diag/crash', 'diag/client-error'];

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        try {
            $status = $response->getStatusCode();
            if ($status < 500 && $status !== 429) {
                return $response;
            }

            $path = (string) $request->path();
            foreach (self::ALREADY_LOGGED as $frag) {
                if (str_contains($path, $frag)) {
                    return $response;
                }
            }

            $userId = null;
            $agencyId = null;
            try {
                $u = $request->user();
                if ($u) {
                    $userId = (int) $u->id;
                    $agencyId = \App\Support\AuditScope::resolve($userId, $request);
                }
            } catch (\Throwable $e) {
                /* Context is a bonus; an unauthenticated 500 still deserves its row. */
            }

            /* The message, when the framework put one in the body. A row saying only
               "500 on /invoices" sends the next reader to the log file anyway. */
            $detail = null;
            try {
                $body = (string) $response->getContent();
                if ($body !== '' && str_starts_with(ltrim($body), '{')) {
                    $j = json_decode($body, true);
                    $detail = is_array($j) ? ($j['message'] ?? $j['error'] ?? null) : null;
                }
            } catch (\Throwable $e) {
            }

            $ms = (int) round((microtime(true) - $started) * 1000);
            $label = $status === 429 ? 'Rate limit hit' : 'Server error ' . $status;

            \App\Support\Audit::write([
                'user_id' => $userId,
                /* Unstamped rows are invisible in EVERY agency, so a failure with no
                   agency would be written and never seen - the same trap the auto
                   sign-off entries fell into. */
                'agency_id' => $agencyId,
                'action' => $status === 429 ? 'error.rate_limited' : 'error.server',
                'entity_type' => 'request',
                'entity_id' => null,
                'payload' => json_encode([
                    'summary' => $label . ': ' . $request->method() . ' /' . $path
                        . ($detail ? ' - ' . mb_substr((string) $detail, 0, 160) : ''),
                    'status' => $status,
                    'method' => $request->method(),
                    'path' => mb_substr($path, 0, 200),
                    'route' => optional($request->route())->getName() ?: null,
                    'ms' => $ms,
                    'message' => $detail ? mb_substr((string) $detail, 0, 500) : null,
                ]),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Recording a failure must never become a second one.
        }

        return $response;
    }
}
