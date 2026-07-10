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
 * to instrument each controller by hand. Reads (GET/HEAD) and a small denylist
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
    ];

    /** Request fields to redact from the stored payload. */
    private const REDACT = [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'token', 'secret', 'api_key', 'apikey', 'client_secret', 'card', 'card_number',
        'cvv', 'cvc', 'ssn', 'sin', 'kiosk_pin', 'pin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        try {
            $this->record($request, $response);
        } catch (Throwable $e) {
            // Auditing must never break the actual request.
        }
        return $response;
    }

    private function record(Request $request, Response $response): void
    {
        $method = strtoupper($request->method());
        if (! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
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

        // action = "post:admin/centres" (route name if present), capped at 80 chars.
        $routeName = optional($request->route())->getName();
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
                $entityType = substr((string) $k, 0, 80);
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
        $agencyId = $request->header('X-Active-Agency-Id');
        if ($agencyId) {
            $payload['active_agency_id'] = (int) $agencyId;
        }

        DB::table('audit_logs')->insert([
            'user_id'     => $user->id ?? null,
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
