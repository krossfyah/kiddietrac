<?php

use App\Http\Middleware\EnsureRole;
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
