<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Portal-wide activity audit (2026-07-08).
 *
 * Records EVERY state-changing API request (POST/PUT/PATCH/DELETE) — by any
 * signed-in user — into audit_logs, so the audit trail captures the whole
 * portal (chats/messages, billing, enrolments, settings, etc.) without having
 * to instrument each controller by hand. Successful reads and a small denylist
 * of high-frequency/noise endpoints are skipped. Both successes AND failures
 * are recorded (the HTTP status is stored), so failed attempts are visible too.
 *
 * Never throws: any logging error is swallowed so it can't break a request.
 */
class AuditActivity
{
    /** Path fragments we never audit (polling, tracking pixels, auth probes, read receipts). */
    private const SKIP = [
        'unread-count', 'auth/me', 'auth/refresh', '/e/o/', 'heartbeat',
        'presence', 'ping', '/read', 'typing', 'csrf',
        // push/device is a routine FCM token upsert that fires on EVERY app launch/
        // foreground — it flooded the audit trail with "Registered a device for push"
        // and isn't a meaningful user action. staff/punch is skipped here because
        // CareController@punch writes its OWN detailed audit entry (clock IN vs OUT
        // with the centre + duration), which the generic middleware entry can't.
        'push/device', 'push/subscribe', 'staff/punch',
        // integration/* = machine-to-machine sync between iLearn and KiddieTrac
        // (waitlist + contacts, every 5 min). It re-pushes the same rows on a schedule
        // and was writing ~1,500 identical "integration/waitlist" audit rows a DAY —
        // pure noise, not a person's action. Skip the whole integration namespace.
        'integration/',
    ];

    /** Request fields to redact from the stored payload. */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'secret', 'api_key', 'apikey', 'client_secret', 'card', 'card_number',
        'cvv', 'cvc', 'ssn', 'sin', 'kiosk_pin', 'pin',
    ];

    /**
     * Claim any audit row this request wrote without an agency stamp.
     *
     * The audit view matches al.agency_id exactly, so an unstamped row is invisible to
     * everyone. Most controllers write their own richer audit entries — staff.clock_in,
     * child.check_in_by_staff, chat.email_notified — and almost none of them stamp.
     * Rather than patching 27 call sites and hoping the 28th remembers, the middleware
     * that already knows the agency adopts whatever the request left behind.
     *
     * Bounded to rows written by THIS actor during THIS request, so it can never reach
     * across to another tenant's row: same actor, same agency, same few seconds.
     */
    /**
     * Set by a DB listener when this request really does INSERT into audit_logs.
     *
     * Without it, claimUnstamped ran AuditScope::resolve() plus an UPDATE on every
     * request in the system — including the reads that make up the overwhelming
     * majority of traffic and never write an audit row at all. Three writes per read
     * was what pinned throughput at ~28 req/s with the CPU idle. (2026-08-29)
     */
    private bool $auditRowWritten = false;

    private function claimUnstamped(Request $request, $user, ?string $startedAt): void
    {
        if (! $user || ! $startedAt) {
            return;
        }
        // Nothing was written, so there is nothing unstamped to adopt.
        if (! $this->auditRowWritten) {
            return;
        }
        try {
            $agencyId = \App\Support\AuditScope::resolve((int) $user->id, $request);
            if (! $agencyId) {
                return;
            }
            DB::table('audit_logs')
                ->whereNull('agency_id')
                ->where('user_id', $user->id)
                ->where('created_at', '>=', $startedAt)
                ->update(['agency_id' => $agencyId]);
        } catch (\Throwable $e) {
            // An audit stamp is worth having, never worth failing a request over.
        }
    }

    public function handle(Request $request, Closure $next): Response
    {
        /* Watch for a real audit insert. Cheaper than the UPDATE it avoids, and exact:
           whatever writes an audit row — controller, observer, service — trips this. */
        \Illuminate\Support\Facades\DB::listen(function ($q) {
            if (! $this->auditRowWritten
                && stripos($q->sql, 'insert into `audit_logs`') !== false) {
                $this->auditRowWritten = true;
            }
        });

        // Taken BEFORE anything runs, so the window covers every row this request
        // goes on to write.
        $startedAt = now()->toDateTimeString();

        $response = $next($request);
        try {
            $this->record($request, $response);
            // Controllers write their own richer entries — staff.clock_in,
            // child.check_in_by_staff — and almost none of them stamp the agency.
            // Adopt whatever this request left unstamped, here, once.
            $this->claimUnstamped($request, $request->user(), $startedAt);
        } catch (Throwable $e) {
            // Auditing must never break the actual request.
        }
        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $method = strtoupper($request->method());
        $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);

        /* A read that FAILS is worth keeping; a read that works is not.

           Educators could not download the Daily Supervision Check for a whole day
           and nothing anywhere recorded it, because a download is a GET and this
           returned before writing a row. The cause (a signed URL having its host
           rewritten to the portal's, so every request 404'd) was invisible until
           somebody complained.

           Auditing all reads would drown the table — reads are most of the traffic.
           Auditing the ones that come back 4xx/5xx costs almost nothing and is the
           signal somebody actually needs. */
        if (! $isWrite) {
            if ($method !== 'GET') {
                return;
            }
            $st = $response->getStatusCode();
            if ($st < 400) {
                return;                 // a working read
            }
            if (in_array($st, [401, 429], true)) {
                // Ordinary traffic: an expired token in somebody's pocket, or a rate
                // limit doing its job. Real login failures are audited by AuthController.
                return;
            }
            if ($st === 404 && $request->route() === null) {
                // No such route at all — scanners and stale bookmarks. Only a 404
                // from a route we do serve says something about us.
                return;
            }
        }
        $user = $request->user();
        if (! $user) {
            return; // unauthenticated (e.g. login attempts) — handled by AuthController's own audit
        }
        $path = $request->path();
        foreach (self::SKIP as $frag) {
            if (stripos($path, $frag) !== false) {
                return;
            }
        }
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        $status = $response->getStatusCode();
        $ok = $status >= 200 && $status < 300;

        /* action = "post:admin/centres" (route name if present), capped at 80 chars.

           getName() returns null for an unnamed route only while the route cache is cold.
           `artisan route:cache` gives every unnamed route a placeholder name --
           "generated::" plus sixteen random characters -- so on a cached production app
           this was never null, the readable fallback never ran, and the log filled with
           strings that mean nothing and CHANGE on every cache rebuild. A placeholder is
           the absence of a name, so treat it as one. */
        $routeName = optional($request->route())->getName();
        if ($routeName !== null && str_starts_with($routeName, 'generated::')) {
            $routeName = null;
        }
        $action = $routeName ?: (strtolower($method) . ':' . $path);
        if (! $ok) {
            $action .= ' [fail]';
        }
        $action = substr($action, 0, 80);

        // Best-effort entity from the first numeric route param.
        $entityId = null;
        $entityType = null;
        foreach ((array) optional($request->route())->parameters() as $k => $v) {
            if (is_numeric($v)) {
                $entityId = (int) $v;
                /* A parameter literally called "id" names nothing -- "id#10" tells a
                   reader less than the path does. Keep the number, take the type from
                   the path instead by leaving $entityType null for the block below. */
                $entityType = in_array(strtolower((string) $k), ['id', 'i', 'n'], true)
                    ? null
                    : substr((string) $k, 0, 80);
                break;
            }
        }
        if (! $entityType) {
            // Derive a coarse entity type from the path (first non-admin segment).
            $seg = array_values(array_filter(explode('/', $path), function ($s) {
                return $s !== '' && $s !== 'admin' && $s !== 'api' && ! preg_match('/^v\d+$/', $s) && ! is_numeric($s);
            }));
            $entityType = isset($seg[0]) ? substr($seg[0], 0, 80) : null;
        }

        $payload = [
            'method'  => $method,
            'path'    => $path,
            'status'  => $status,
            'input'   => $this->safeInput($request),
        ];
        $headerAgency = $request->header('X-Active-Agency-Id');
        if ($headerAgency) {
            $payload['active_agency_id'] = (int) $headerAgency;
        }

        // The agency this action BELONGS to — stamped so the per-agency audit log
        // can filter strictly by it (no cross-tenant leakage). A platform_admin is
        // tagged to the agency they've switched into; everyone else to their own.
        $agencyId = \App\Support\AuditScope::resolve((int) ($user->id ?? 0), $request);

        \App\Support\Audit::write([
            'user_id'     => $user->id ?? null,
            'agency_id'   => $agencyId,
            'action'      => $action,
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'payload'     => json_encode($payload),
            'ip_address'  => substr((string) $request->ip(), 0, 45),
            'user_agent'  => substr((string) $request->userAgent(), 0, 500),
            'created_at'  => now(),
        ]);
    }

    /** Redacted, size-capped snapshot of the request body for the audit record. */
    private function safeInput(Request $request): array
    {
        $input = $request->except(self::REDACT);
        // Redact any nested key whose name looks sensitive.
        array_walk_recursive($input, function (&$v, $k) {
            if (is_string($k)) {
                $lk = strtolower($k);
                foreach (self::REDACT as $bad) {
                    if (strpos($lk, $bad) !== false) { $v = '[redacted]'; return; }
                }
            }
            if (is_string($v) && strlen($v) > 500) {
                $v = substr($v, 0, 500) . '…';
            }
        });
        $json = json_encode($input);
        if ($json !== false && strlen($json) > 4000) {
            return ['_truncated' => true, 'keys' => array_slice(array_keys($input), 0, 40)];
        }
        return $input;
    }
}
