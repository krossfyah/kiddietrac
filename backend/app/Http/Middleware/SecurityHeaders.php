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
        /* Framing: DENY for the JSON API, an explicit allowlist for DOCUMENTS.

           This was an unconditional DENY, on the comment "JSON API is never framed".
           But this host also STREAMS DOCUMENTS -- /api/v1/media/f serves the signed
           PDFs and images that the forms library, the document viewer and the
           e-signature screens open in an <iframe>. DENY made the browser refuse
           them, which is the "api refused to connect" error on the forms library.

           SAMEORIGIN would not help: app.kiddietrac.com framing api.kiddietrac.com
           is cross-ORIGIN (same site, different origin), so it blocks too, and
           X-Frame-Options has no way to name an allowed origin. CSP frame-ancestors
           does, so documents get that instead and X-Frame-Options is dropped for
           them -- sending both invites disagreement between browsers.

           Origins come from config('cors.allowed_origins'): ONE list decides which
           front-ends are ours. */
        if ($this->servesDocument($response)) {
            $h->remove('X-Frame-Options');
            $h->set('Content-Security-Policy', "frame-ancestors 'self' " . $this->frameAncestors());
        } else {
            $h->set('X-Frame-Options', 'DENY');                              // JSON API is never framed
        }
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

    /**
     * Is this response a document a browser would legitimately embed?
     *
     * Keyed on the Content-Type rather than a route list, so a PDF or image added
     * by any future endpoint is covered without anyone remembering to register it.
     * Deliberately excludes text/html: an HTML body from this host is an error page
     * or a redirect, never something the portal frames on purpose.
     */
    private function servesDocument(Response $response): bool
    {
        $type = strtolower(trim((string) $response->headers->get('Content-Type')));
        if ($type === '' || str_contains($type, 'json')) {
            return false;
        }

        foreach (['application/pdf', 'image/', 'video/', 'audio/', 'officedocument', 'application/msword'] as $needle) {
            if (str_contains($type, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** The front-ends allowed to frame our documents, space separated. */
    private function frameAncestors(): string
    {
        $origins = (array) config('cors.allowed_origins', []);
        $clean = [];

        foreach ($origins as $o) {
            $o = trim((string) $o);
            // '*' would make the allowlist meaningless -- skip it rather than honour it.
            if ($o === '' || $o === '*') {
                continue;
            }
            $clean[$o] = true;
        }

        return implode(' ', array_keys($clean));
    }
}
