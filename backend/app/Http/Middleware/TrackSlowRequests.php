<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * "The system is freezing" — measured, not guessed.
 *
 * Nothing in the portal watched for slowness. The one performance problem on record —
 * auth/me costing ~17 queries and holding the API to about 28 req/s while the CPU sat
 * 70% idle — was found by hand, long after it started, because somebody said the system
 * felt frozen and a person went looking. This makes that a fact you can read.
 *
 * A slow request is recorded as an audit_logs row, not a log line: the audit viewer is a
 * screen somebody already opens, it is agency-scoped, and it renders a summary. A log
 * file is where the LAST version of this problem went to be ignored.
 *
 * Deliberately quiet:
 *   • only requests over the threshold are recorded at all (default 3s);
 *   • work that is legitimately slow — a PDF, an XLSX, an upload, a report — is skipped
 *     rather than filling the log with things behaving exactly as designed;
 *   • it records, it does not alert. Somebody reading a week of these can see which
 *     route is the problem; a ticket per slow request would be noise.
 */
final class TrackSlowRequests
{
    /** Anything slower than this is worth knowing about. Override with KT_SLOW_REQUEST_MS. */
    private const DEFAULT_THRESHOLD_MS = 3000;

    /** Paths where taking several seconds is the job, not a fault. */
    private const EXPECT_SLOW = [
        'pdf', 'xlsx', 'csv', 'export', 'download', 'upload', 'import',
        'reports/canned', 'report-cards/generate', 'backup', 'photos', 'videos',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);

        $response = $next($request);

        try {
            $ms = (int) round((microtime(true) - $started) * 1000);
            $threshold = (int) (env('KT_SLOW_REQUEST_MS') ?: self::DEFAULT_THRESHOLD_MS);
            if ($ms < $threshold) {
                return $response;
            }

            $path = (string) $request->path();
            foreach (self::EXPECT_SLOW as $frag) {
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
            } catch (\Throwable $e) { /* context is a bonus */ }

            $seconds = number_format($ms / 1000, 1);
            DB::table('audit_logs')->insert([
                'user_id'     => $userId,
                // Unstamped rows are invisible in EVERY agency, so a perf row with no
                // agency would be written and never seen — the exact trap the auto
                // sign-off entries fell into.
                'agency_id'   => $agencyId,
                'action'      => 'perf.slow_request',
                'entity_type' => 'request',
                'entity_id'   => null,
                'payload'     => json_encode([
                    'summary'  => 'Slow response: ' . $request->method() . ' /' . $path
                        . ' took ' . $seconds . 's',
                    'ms'       => $ms,
                    'method'   => $request->method(),
                    'path'     => mb_substr($path, 0, 200),
                    'route'    => optional($request->route())->getName() ?: null,
                    'status'   => $response->getStatusCode(),
                    'threshold_ms' => $threshold,
                ]),
                'ip_address'  => $request->ip(),
                'created_at'  => now(),
            ]);
        } catch (\Throwable $e) {
            // Measuring the response must never damage it.
        }

        return $response;
    }
}
