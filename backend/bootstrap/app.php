<?php

use App\Http\Middleware\AuditActivity;
use App\Http\Middleware\EnforceAuditorReadOnly;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\NormalizeJsonTimestamps;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register custom middleware alias used in route definitions
        $middleware->alias([
            'role' => EnsureRole::class,
        ]);

        // Make Sanctum stateful for the parent-portal SPA on app.kiddietrac.com
        $middleware->statefulApi();

        // CORS first in the API pipeline
        $middleware->api(prepend: [
            HandleCors::class,
        ]);

        // v22p94: pure-auditor accounts are read-only across the whole API.
        // SecurityHeaders: hardened response headers on every API response (SOC 2).
        $middleware->api(append: [
            // Onboarding gate — blocks all non-onboarding API calls until the
            // user has completed onboarding (server-side backstop; skips
            // impersonation tokens + platform admins). Kill switch:
            // ONBOARDING_GATE=false in .env + config:cache.
            EnsureOnboarded::class,
            EnforceAuditorReadOnly::class,
            SecurityHeaders::class,
            // Portal-wide activity audit — records every write action to audit_logs.
            AuditActivity::class,
            // Presence: stamps users.last_seen_at from ordinary traffic, throttled
            // to one write a minute per user. No heartbeat endpoint, no extra polling.
            \App\Http\Middleware\TrackPresence::class,
            // How long the response actually took. Anything over the threshold lands in
            // the audit log as perf.slow_request, so "the system is freezing" becomes a
            // route and a number instead of a feeling. See the middleware for why it is
            // deliberately quiet.
            \App\Http\Middleware\TrackSlowRequests::class,
        ]);

        // Trust GoDaddy / CloudFlare proxy headers if present
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render JSON for /api/* and explicit JSON requests
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );

        /* An unhandled server error becomes a support ticket, the way a CLIENT crash
           already did via POST /diag/crash.

           Until now this hook only chose a response format, so a 500 went to
           storage/logs/laravel-*.log and no further. On 2026-08-31 a clock-in was
           returning 500 — "Data truncated for column 'source'" — and the single line in
           that file was the only record; it surfaced because somebody happened to be
           testing that endpoint, not because anything reported it.

           ProblemReport decides what is worth filing (5xx and uncategorised faults, not
           a 422 or a 403), de-duplicates by subject so one broken endpoint is one ticket,
           and caps NEW tickets per hour so a fault in a loop cannot bury the queue it is
           meant to serve. It never throws: reporting a problem must not become one. */
        $exceptions->reportable(function (\Throwable $e): void {
            try {
                \App\Support\ProblemReport::fromException($e, request());
            } catch (\Throwable $inner) {
                // swallowed on purpose — see above
            }
        });
    })
    ->create();
