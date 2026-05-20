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
        $data = $request->validate(['locale' => 'required|string|in:en,fr,es']);
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
        if ($activeId) return $activeId;
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
