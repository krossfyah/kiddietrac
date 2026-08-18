<?php

use App\Http\Middleware\AuditActivity;
use App\Http\Middleware\EnforceAuditorReadOnly;
use App\Http\Middleware\EnsureOnboarded;
use App\Http\Middleware\EnsureRole;
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
        ]);

        // Trust GoDaddy / CloudFlare proxy headers if present
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Always render JSON for /api/* and explicit JSON requests
        $exceptions->shouldRenderJsonWhen(
            fn ($request, $e) => $request->is('api/*') || $request->expectsJson()
        );
    })
    ->create();
