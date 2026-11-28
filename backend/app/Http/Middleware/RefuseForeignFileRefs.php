<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\FileRefs;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * A SAVE MAY ONLY NAME THIS AGENCY'S FILES (2026-11-28, data-leakage rule, P0).
 *
 * SignProtectedMedia signs every protected `/storage/...` path in every reply, whoever's file it is. So an endpoint
 * that stores a path sent by the browser - photo_url, document_url, file_url, evidence_url, a menu item's image, a
 * link pasted into notes, a signature "data" field - would serve another agency's file to everyone who reads that
 * row, as soon as someone typed its path in. Rather than trust ~60 controllers to each remember, every write request
 * is read here once: any protected or private file it names must belong to the ACTIVE agency (App\Support\FileRefs:
 * the path, the upload ledger, or the rows already holding it), or to the caller. Anything else is 422.
 *
 * Not checked: GET/HEAD/OPTIONS; requests with no signed-in person (public doors are scoped by their own token);
 * platform-only areas (platform/, sales/, marketing-site/) for platform admins, which have no agency context.
 * Kill switch: KT_FILE_REF_GUARD=off (no checks) or =log (log what would be refused, refuse nothing).
 */
final class RefuseForeignFileRefs
{
    private const PLATFORM_PREFIXES = ['api/v1/platform/', 'api/v1/sales/', 'api/v1/marketing-site/'];

    public function handle(Request $request, Closure $next): Response
    {
        $mode = strtolower((string) env('KT_FILE_REF_GUARD', 'on'));
        if ($mode === 'off' || in_array($request->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }
        $input = $request->all();
        if (! $input || ! ($found = FileRefs::inInput($input))) {
            return $next($request);
        }
        $user = Auth::guard('sanctum')->user();
        if (! $user) {
            return $next($request);
        }
        $isPlatform = DB::table('role_assignments')->where('user_id', $user->id)->where('role', 'platform_admin')->where('active', 1)->exists();
        $path = ltrim($request->path(), '/');
        if ($isPlatform) {
            foreach (self::PLATFORM_PREFIXES as $p) {
                if (str_starts_with($path, $p)) return $next($request);
            }
        }
        // NormalizeActiveAgency has already made this one of the caller's agencies (or removed it).
        $agencyId = (int) $request->header('X-Active-Agency-Id');
        if ($isPlatform && $agencyId === 0) {
            return $next($request);   // a platform admin outside any agency: there is no agency to cross into
        }

        $all = [];
        foreach ($found as $refs) foreach ($refs as $r) $all[$r] = true;
        $indexed = FileRefs::indexed(array_keys($all));
        $refused = [];
        foreach ($found as $key => $refs) {
            foreach ($refs as $rel) {
                if (! FileRefs::allows($rel, $agencyId, (int) $user->id, $indexed[$rel] ?? [])) {
                    $refused[$key] = $rel;
                }
            }
        }
        if (! $refused) {
            return $next($request);
        }
        Log::warning('file-ref guard: refused a reference to a file outside the active agency', [
            'route' => $request->method() . ' ' . $path, 'user_id' => $user->id, 'agency_id' => $agencyId,
            'fields' => array_keys($refused), 'files' => array_values(array_unique($refused)), 'mode' => $mode,
        ]);
        if ($mode === 'log') {
            return $next($request);
        }
        $msg = 'One of the files in this request is not one of this agency\'s files. Upload it again here.';
        return response()->json([
            'message' => $msg,
            'reason' => 'foreign_file',
            'errors' => array_map(fn () => [$msg], $refused),
        ], 422);
    }
}
