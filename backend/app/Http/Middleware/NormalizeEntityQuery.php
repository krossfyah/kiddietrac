<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Repairs a query string whose separators arrived HTML-encoded.
 *
 * An <img src> in an email is written by Blade as
 *   …/media/f?expires=1&amp;p=2&amp;signature=3
 * which is correct HTML. A client that fetches the attribute WITHOUT decoding the
 * entities first asks for `expires`, `amp;p` and `amp;signature` — so `signature` is
 * missing, the signed middleware refuses the request, and the recipient sees a broken
 * image. Proven against production mail: the decoded URL returned 200 and the
 * entity-literal one returned 403, which is exactly why the agency logo (no query
 * string) rendered while the provider's photo did not.
 *
 * New emails no longer carry a query string at all (ProtectedMedia::signForEmail mints
 * a path-only token). This exists for the ones ALREADY SITTING IN INBOXES, which cannot
 * be reissued — it renames the mangled keys back before the signature is checked, so
 * the URL that was signed is the URL that gets validated.
 *
 * Deliberately narrow: it only ever strips a leading `amp;` from a key, and only when
 * the un-prefixed key is not already present. Nothing else about the request changes.
 */
class NormalizeEntityQuery
{
    public function handle(Request $request, Closure $next)
    {
        $query = $request->query();
        $fixed = [];
        $touched = false;

        foreach ($query as $key => $value) {
            if (str_starts_with($key, 'amp;') && ! array_key_exists(substr($key, 4), $query)) {
                $fixed[substr($key, 4)] = $value;
                $touched = true;
                continue;
            }
            $fixed[$key] = $value;
        }

        if (! $touched) {
            return $next($request);
        }

        /* Rewrite the request itself, not just the bag: the signed middleware
           validates against the full URL, so the query string on the underlying
           request has to be the repaired one. */
        $request->query->replace($fixed);
        $request->server->set('QUERY_STRING', http_build_query($fixed));

        $base = strtok($request->fullUrl(), '?');
        $request->server->set('REQUEST_URI', $request->getPathInfo() . '?' . http_build_query($fixed));
        $request->overrideGlobals();

        return $next($request);
    }
}
