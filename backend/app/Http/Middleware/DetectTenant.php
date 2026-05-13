<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * v12-big: Resolve the request's tenant agency from the Host header.
 *
 * Resolution order:
 *   1. ?tenant=<slug>  (debug / dev override; only honoured if APP_ENV != production)
 *   2. custom_domain match (e.g. "childcare.acmedaycare.com" → agency)
 *   3. subdomain match on *.kiddietrac.com (e.g. "acme.kiddietrac.com" → agency)
 *   4. Default: agency_id = 1 (Kiddietrac itself)
 *
 * Attaches `$request->tenant_agency_id` and `$request->tenant_agency` so
 * downstream controllers can scope responses without re-running the lookup.
 *
 * Lookups are cached for 60s per host to avoid hammering the DB.
 */
final class DetectTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $cacheKey = 'tenant:host:' . $host;

        $agency = Cache::remember($cacheKey, 60, function () use ($host, $request) {
            // 1. Debug override (dev only)
            if (env('APP_ENV') !== 'production' && $request->has('tenant')) {
                $a = DB::table('agencies')->where('slug', $request->input('tenant'))->first();
                if ($a) return $a;
            }
            // 2. Custom domain
            $a = DB::table('agencies')->where('custom_domain', $host)->first();
            if ($a) return $a;
            // 3. Subdomain on *.kiddietrac.com
            if (str_ends_with($host, '.kiddietrac.com')) {
                $sub = substr($host, 0, -strlen('.kiddietrac.com'));
                if ($sub && $sub !== 'app' && $sub !== 'api' && $sub !== 'www') {
                    $a = DB::table('agencies')->where('subdomain', $sub)->first();
                    if ($a) return $a;
                    // Try slug as fallback
                    $a = DB::table('agencies')->where('slug', $sub)->first();
                    if ($a) return $a;
                }
            }
            // 4. Default
            return DB::table('agencies')->where('id', 1)->first();
        });

        if ($agency) {
            $request->attributes->set('tenant_agency_id', $agency->id);
            $request->attributes->set('tenant_agency', $agency);
            // Also share with controllers via app() container
            app()->instance('tenant.agency', $agency);
        }

        return $next($request);
    }
}
