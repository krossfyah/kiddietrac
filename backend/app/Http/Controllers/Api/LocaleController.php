<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * v22p51 — i18n / locale management.
 *
 *   GET  /locale            → current locale + supported list
 *   GET  /locale/strings/{lang}  → JSON bundle for the front-end Lang shim
 *   POST /locale            → set user locale
 *
 * Strings live as JSON files in resources/lang/portal/{en,fr,es}.json so
 * the front-end can fetch the bundle and apply at boot. Agency overrides
 * (rare) come from agency_locale_overrides table.
 */
final class LocaleController extends Controller
{
    public function current(Request $request): JsonResponse
    {
        $u = $request->user();
        return response()->json([
            'locale' => $u->locale ?? 'en',
            'supported' => ['en', 'fr', 'es'],
            'agency_default' => DB::table('agencies')->where('id', $this->resolveAgencyId($request))->value('default_locale') ?? 'en',
        ]);
    }

    public function set(Request $request): JsonResponse
    {
        $data = $request->validate(['locale' => 'required|string|in:en,fr,es,hi']);
        DB::table('users')->where('id', $request->user()->id)->update([
            'locale' => $data['locale'], 'updated_at' => now(),
        ]);
        return response()->json(['locale' => $data['locale']]);
    }

    /**
     * Returns the full string bundle. Cached client-side.
     */
    public function strings(Request $request, string $lang): JsonResponse
    {
        if (!in_array($lang, ['en', 'fr', 'es'], true)) abort(404);
        $path = resource_path("lang/portal/{$lang}.json");
        $base = File::exists($path) ? json_decode(File::get($path), true) : [];
        $agencyId = $this->resolveAgencyId($request, false);
        if ($agencyId) {
            $overrides = DB::table('agency_locale_overrides')
                ->where('agency_id', $agencyId)
                ->where('locale', $lang)
                ->pluck('value', 'key_path')->toArray();
            foreach ($overrides as $k => $v) {
                data_set($base, $k, $v);
            }
        }
        return response()->json(['lang' => $lang, 'strings' => $base ?: (object) []]);
    }

    private function resolveAgencyId(Request $request, bool $strict = true): int
    {
        $activeId = (int) $request->header('X-Active-Agency-Id');
        // SECURITY (v22p94): only honour the header if the user is platform_admin
        // or holds an active role for that exact agency (else fall back below).
        if ($activeId && DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) return $activeId;
        // SECURITY (v22p98): a platform_admin with no valid SELECTED agency must NOT
        // fall through to their first role's agency (iLearn) — require an explicit
        // choice, else agency-scoped data leaked to a super-admin on a header-less call.
        if (DB::table('role_assignments')->where('user_id', $request->user()->id)->where('role', 'platform_admin')->where('active', true)->exists()) abort(400, 'Select an agency first.');
        if (!$request->user()) {
            if ($strict) abort(400);
            return 0;
        }
        $first = DB::table('role_assignments')
            ->where('user_id', $request->user()->id)
            ->where('active', true)
            ->value('agency_id');
        if (!$first) {
            if ($strict) abort(400);
            return 0;
        }
        return (int) $first;
    }
}
