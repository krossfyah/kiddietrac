<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Turning a problem into something somebody can actually work through.
 *
 * The portal already did this for CLIENT crashes: POST /diag/crash writes the report to
 * a log, de-duplicates it and opens a support ticket. Server-side there was nothing —
 * bootstrap/app.php only told Laravel to render JSON, so an unhandled exception went to
 * storage/logs/laravel-*.log and stopped there.
 *
 * That is not theoretical. On 2026-08-31 a clock-in was returning 500 ("Data truncated
 * for column 'source'") and the only record of it was one line in a log file nobody
 * opens. It was found because somebody happened to be testing that endpoint.
 *
 * This is the shared half: file a ticket, or append to the open one if the same problem
 * is already being tracked. Both the crash endpoint and the server-side handler use it,
 * so the two cannot drift apart.
 */
final class ProblemReport
{
    /** Tickets this class may open in one hour before it goes quiet. */
    private const HOURLY_CAP = 20;

    /**
     * File a technical ticket, or append to the open one with the same subject.
     *
     * De-duplication is by SUBJECT, matching the crash endpoint: the same fault
     * reported twenty times is one problem, not twenty tickets.
     *
     * @return int|null  the ticket id, or null if nothing was filed
     */
    public static function fileTicket(
        string $subject,
        string $body,
        ?int $agencyId = null,
        ?int $userId = null,
        string $priority = 'high'
    ): ?int {
        try {
            $subject = mb_substr(trim($subject) !== '' ? $subject : 'Unknown problem', 0, 190);

            $existing = DB::table('support_tickets')
                ->where('category', 'technical')->where('status', 'open')
                ->where('subject', $subject)
                ->orderByDesc('id')->first();

            if ($existing) {
                DB::table('support_tickets')->where('id', $existing->id)->update([
                    'body' => mb_substr($existing->body . "\n\n--- happened again ---\n" . $body, 0, 60000),
                    'updated_at' => now(),
                ]);
                return (int) $existing->id;
            }

            /* A NEW subject is the expensive case: something failing in a loop with a
               varying message (an id in the text, a different column each time) would
               file a ticket per occurrence and bury the queue it is meant to serve.
               Appending to an existing ticket is never capped — that costs nothing and
               is exactly what you want during an incident. */
            $recent = DB::table('support_tickets')
                ->where('category', 'technical')
                ->where('created_at', '>=', now()->subHour())
                ->count();
            if ($recent >= self::HOURLY_CAP) {
                Log::error('ProblemReport: hourly ticket cap reached, not filing', [
                    'subject' => $subject, 'cap' => self::HOURLY_CAP,
                ]);
                return null;
            }

            return (int) DB::table('support_tickets')->insertGetId([
                'agency_id'         => $agencyId,
                'centre_id'         => null,
                'raised_by_user_id' => $userId,
                'category'          => 'technical',
                'priority'          => $priority,
                'subject'           => $subject,
                'body'              => mb_substr($body, 0, 60000),
                'status'            => 'open',
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);
        } catch (Throwable $e) {
            // Reporting a problem must never become one.
            Log::error('ProblemReport: could not file a ticket', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * An unhandled server exception, as a ticket.
     *
     * Deliberately NOT everything Laravel reports. A 422 from validation, a 401, a 403,
     * a 404 and a 429 are the application working correctly and saying no — filing those
     * would drown the real faults, which are the 500s.
     */
    public static function fromException(Throwable $e, $request = null): ?int
    {
        try {
            if (! self::isServerFault($e)) {
                return null;
            }

            $class = class_basename($e);
            $msg = trim((string) $e->getMessage());
            // One line, and short: this is the de-duplication key. A message carrying an
            // id or a timestamp would otherwise make every occurrence a new ticket.
            $firstLine = trim((string) (explode("\n", $msg)[0] ?? ''));
            $subject = 'Server error: ' . $class . ($firstLine !== '' ? ' — ' . mb_substr($firstLine, 0, 120) : '');

            $userId = null;
            $agencyId = null;
            $route = '';
            $method = '';
            $path = '';
            $ip = '';
            $agent = '';
            try {
                if ($request) {
                    $method = (string) $request->method();
                    $path = mb_substr((string) $request->path(), 0, 200);
                    $route = optional($request->route())->getName() ?: '';
                    $ip = (string) $request->ip();
                    $agent = mb_substr((string) $request->userAgent(), 0, 200);
                    $u = $request->user();
                    if ($u) {
                        $userId = (int) $u->id;
                        $agencyId = AuditScope::resolve($userId, $request);
                    }
                }
            } catch (Throwable $inner) { /* context is a bonus, never a blocker */ }

            $body = "Automatically filed from an unhandled server error.\n\n"
                . 'When: ' . now()->toDateTimeString() . " UTC\n"
                . 'Where: ' . $method . ' /' . $path . ($route ? '  (route: ' . $route . ')' : '') . "\n"
                . 'Who: ' . ($userId ? 'user #' . $userId : 'not signed in')
                . ($agencyId ? ' · agency #' . $agencyId : '') . '   IP: ' . $ip . "\n"
                . 'Client: ' . ($agent ?: 'not reported') . "\n\n"
                . 'Exception: ' . get_class($e) . "\n"
                . 'Message: ' . mb_substr($msg, 0, 2000) . "\n"
                . 'At: ' . $e->getFile() . ':' . $e->getLine() . "\n\n"
                . "Trace:\n" . mb_substr((string) $e->getTraceAsString(), 0, 6000);

            return self::fileTicket($subject, $body, $agencyId, $userId, 'high');
        } catch (Throwable $inner) {
            Log::error('ProblemReport: could not report an exception', ['error' => $inner->getMessage()]);
            return null;
        }
    }

    /**
     * Is this OUR fault, or the application correctly refusing?
     *
     * Anything carrying an HTTP status below 500 is a considered answer — validation,
     * auth, not-found, rate limiting. Only a 5xx, or an exception with no status at all,
     * is a fault worth waking somebody for.
     */
    private static function isServerFault(Throwable $e): bool
    {
        if ($e instanceof \Illuminate\Validation\ValidationException) return false;
        if ($e instanceof \Illuminate\Auth\AuthenticationException) return false;
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) return false;
        if ($e instanceof \Illuminate\Session\TokenMismatchException) return false;

        if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface) {
            return $e->getStatusCode() >= 500;
        }
        return true;
    }
}
