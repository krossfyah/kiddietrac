<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProtectedMedia;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves an uploaded file, but only against a signature we issued.
 *
 * The `signed` middleware has already proved the URL came from us and has not
 * expired before this runs. What is left to prove is that the path is one we are
 * willing to serve at all — a valid signature on a path that has since been
 * tampered with, or that points outside the protected folders, is still refused.
 */
class MediaFileController extends Controller
{
    public function show(Request $request): Response
    {
        $rel = ProtectedMedia::decode($request->query('p'));
        if ($rel === null) {
            abort(404);
        }

        return $this->serve($rel);
    }

    /**
     * The email variant: the whole capability is one path segment, verified here
     * rather than by the `signed` middleware. See ProtectedMedia::signForEmail for
     * why an email link must not carry a query string.
     */
    public function email(Request $request, string $token): Response
    {
        $rel = ProtectedMedia::verifyEmailToken($token);
        if ($rel === null) {
            abort(404);
        }

        return $this->serve($rel);
    }

    private function serve(string $rel): Response
    {

        $disk = Storage::disk('public');
        if (! $disk->exists($rel)) {
            abort(404);
        }

        $abs = $disk->path($rel);

        /* Belt and braces on traversal. The signature covers the path, and decode()
           already refused '..', but this is the line where a string becomes a file
           read — so it is checked here too, against the resolved real path. */
        $root = realpath($disk->path(''));
        $real = realpath($abs);
        if ($root === false || $real === false || ! str_starts_with($real, $root)) {
            abort(404);
        }

        /* inline: these are opened in a tab or rendered in an <img>, not downloaded.
           The filename is the stored one, which is already sanitised on upload. */
        $res = new BinaryFileResponse($real);
        $res->setContentDisposition('inline', basename($real));

        /* Private, and no shared cache. A signed URL is a capability — a proxy or a
           CDN holding a copy would outlive both the signature and any revocation. */
        /* An hour, not ten minutes. This is only worth anything because the signed URL is
           now STABLE for a six-hour window (ProtectedMedia::stableExpiry) — while the
           expiry was recomputed per request, every response carried a brand-new URL and no
           cache entry was ever reachable, whatever this header said. Safe against a stale
           photo too: a new upload is written under a fresh UUID, so changing an avatar
           changes its URL. Kept well inside the stability window so a cached copy can never
           outlive the signature that fetched it. (Anthony, 2026-09-09) */
        $res->headers->set('Cache-Control', 'private, max-age=3600, no-transform');
        /* Mail proxies (Gmail, Outlook, Yahoo) fetch images server-side from an address
           that is nobody's browser session. Say plainly that no credentials are needed. */
        $res->headers->set('Access-Control-Allow-Origin', '*');
        $res->headers->set('X-Content-Type-Options', 'nosniff');

        return $res;
    }
}
