<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security response headers for every API response (SOC 2 — Security / CC6).
 * The parent-portal frontend sets its own headers via .htaccess; this covers
 * api.kiddietrac.com, which previously returned almost none and leaked the PHP
 * version via X-Powered-By.
 *
 * Conservative on purpose: no Content-Security-Policy here (the API returns
 * JSON, not documents) and HSTS without includeSubDomains/preload until every
 * subdomain is confirmed HTTPS-only.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Strip tech/version disclosure where we can.
        if (! headers_sent()) {
            @header_remove('X-Powered-By');
        }
        $response->headers->remove('X-Powered-By');

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');                                  // JSON API is never framed
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $h->set('Cross-Origin-Resource-Policy', 'same-site');
        $h->set('X-Permitted-Cross-Domain-Policies', 'none');

        // HSTS only over HTTPS. 1 year; add includeSubDomains + preload later
        // once all *.kiddietrac.com hosts are verified HTTPS-only.
        if ($request->secure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
