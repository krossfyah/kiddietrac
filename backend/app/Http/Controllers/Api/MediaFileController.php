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

        /* AN AVATAR IS NOT A PHOTOGRAPH. (2026-09-21)

           Anthony: "display pics/avatars take time to render and not instant (you can see
           the loading of the images)."

           Measured: 56 avatars, 9.4 MB, MEDIAN 72 KB, largest 1.5 MB — every one the
           original camera file, drawn into a 32-56px circle. The download is the small
           half of the cost; the DECODE is the big one. A 3000x4000 phone photo expands to
           roughly 48 MB of bitmap before the browser can draw a single pixel of it, and a
           roster with a dozen faces on it pays that twelve times over, on a phone. That is
           the popping-in.

           Served transparently: same signed URL, same route, no front-end change and
           nothing to migrate. The small copy is written once beside the original and
           reused after that; if it cannot be made (no imagick/gd, an unreadable file) the
           original is served exactly as before, because a slow avatar beats a missing one.

           384px, not 96: these are also what the avatar lightbox blows up, and a 96px
           source looks like a thumbnail when it does. 384 is ~20 KB and still sharper than
           any box it is drawn into. */
        /* CHILD PHOTOS TOO. Same reasoning, worse numbers: these are straight off a
           phone camera and are drawn into a 30px roster circle. Six of them on one
           screen was 13.1 MB. */
        if (str_starts_with($rel, 'avatars/') || str_starts_with($rel, 'child-photos/')) {
            try {
                $small = \App\Support\MediaThumb::make($abs, 384);
                if ($small && is_file($small)) {
                    $abs = $small;
                }
            } catch (\Throwable $e) {
                // keep the original
            }
        }

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
        /* FOUR HOURS, not one. The URL is stable for a six-hour window and the signature
           behind it is good for twelve to eighteen, so an hour was leaving the browser to
           re-ask for a file whose address had not changed and would not change. Still
           comfortably inside the window, so a cached copy can never outlive the signature
           that fetched it. */
        $res->headers->set('Cache-Control', 'private, max-age=14400, no-transform');

        /* A validator, so the re-ask after max-age costs 304 and no bytes. Last-Modified
           alone was being sent; an ETag is cheaper for the client to match and survives a
           file being touched without changing. */
        try {
            /* setEtag ONLY. setPublic() would strip `private` off the header above, and
               these are capability URLs — a proxy or CDN holding a copy would outlive both
               the signature and any revocation. That is the one thing this whole class
               exists to prevent. */
            $res->setEtag(md5($abs . '|' . filemtime($abs) . '|' . filesize($abs)));
        } catch (\Throwable $e) {
        }
        /* Mail proxies (Gmail, Outlook, Yahoo) fetch images server-side from an address
           that is nobody's browser session. Say plainly that no credentials are needed. */
        $res->headers->set('Access-Control-Allow-Origin', '*');
        $res->headers->set('X-Content-Type-Options', 'nosniff');

        return $res;
    }
}
