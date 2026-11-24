<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AiBundle;
use App\Support\FoodPhotos;
use App\Support\UsageMeter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Food photos for the weekly menu (2026-10-31) - see App\Support\FoodPhotos.
 *
 *   GET  /operations/food-images              the library + this agency's most-used foods + the auto setting
 *   POST /operations/food-images/match        {names[]} -> best local match per dish (no AI)
 *   POST /operations/menu/photo               an agency's own photo (multipart "photo") -> /storage/photos/menu/<agency>/…
 *   PUT  /operations/menu/photo-settings      {auto} - directors / admins only
 *   POST /ai/assist/food-photo                {names[]} -> the AI picks a library food for dishes matching could not place
 *                                             (AI Bundle: EnforcePlanFeatures 'ai/assist' + AiBundle::on)
 * Staff of the active agency only (route group: educator, director, admin, platform).
 */
class FoodPhotoController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $use = FoodPhotos::usage($agencyId);
        $lib = array_map(fn ($f) => [
            'id' => (int) $f['id'], 'slug' => $f['slug'], 'name' => $f['name'], 'synonyms' => $f['synonyms'],
            'allergens' => $f['allergens'], 'category' => $f['category'], 'url' => $f['url'], 'thumb_url' => $f['thumb_url'],
            'credit' => trim(($f['author'] ? $f['author'] . ', ' : '') . ($f['licence'] ?: '')), 'source_url' => $f['source_url'],
            'used' => $use[(int) $f['id']] ?? 0,
        ], FoodPhotos::library());

        return response()->json([
            'data' => $lib,
            'auto' => FoodPhotos::autoOn($agencyId),
            'can_manage' => $this->canManage($request, $agencyId),
            'ai' => AiBundle::on($agencyId),
        ]);
    }

    public function match(Request $request): JsonResponse
    {
        $d = $request->validate(['names' => 'required|array|max:80', 'names.*' => 'nullable|string|max:255']);
        $this->agencyId($request);
        $out = [];
        foreach ($d['names'] as $n) {
            $n = trim((string) $n);
            if ($n === '' || array_key_exists($n, $out)) continue;
            $s = FoodPhotos::suggest($n, 4);
            $out[$n] = ['best' => ($s && $s[0]['score'] >= FoodPhotos::AUTO) ? $s[0] : null, 'suggestions' => $s,
                'confident' => FoodPhotos::confident($s)];
        }

        return response()->json(['matches' => $out]);
    }

    public function upload(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        $request->validate(['photo' => 'required|file|max:12288|mimes:jpg,jpeg,png,webp,heic,heif']);
        $file = $request->file('photo');
        $raw = @file_get_contents($file->getRealPath());
        $im = $raw ? @imagecreatefromstring($raw) : false;
        if (! $im) {
            return response()->json(['message' => 'That photo could not be read. Try a JPEG or PNG.'], 422);
        }
        // Square-ish 4:3 crop from the centre, then 640px and a 200px thumb, as WebP (a phone photo is 3-8 MB).
        $w = imagesx($im); $h = imagesy($im);
        $tw = $w; $th = (int) round($w * 3 / 4);
        if ($th > $h) { $th = $h; $tw = (int) round($h * 4 / 3); }
        $sx = (int) (($w - $tw) / 2); $sy = (int) (($h - $th) / 2);
        $name = Str::uuid()->toString();
        $dir = 'photos/menu/' . $agencyId;
        $paths = [];
        foreach ([[640, 480, ''], [200, 150, '-thumb']] as [$ow, $oh, $suffix]) {
            $out = imagecreatetruecolor($ow, $oh);
            imagecopyresampled($out, $im, 0, 0, $sx, $sy, $ow, $oh, $tw, $th);
            ob_start(); imagewebp($out, null, 80); $bytes = ob_get_clean();
            imagedestroy($out);
            Storage::disk('public')->put($dir . '/' . $name . $suffix . '.webp', $bytes);
            $paths[] = '/storage/' . $dir . '/' . $name . $suffix . '.webp';
        }
        imagedestroy($im);

        // Signed on the way out by SignProtectedMedia; the stored form goes back on save (FoodPhotos::stored).
        return response()->json(['url' => $paths[0], 'thumb_url' => $paths[1]]);
    }

    public function settings(Request $request): JsonResponse
    {
        $agencyId = $this->agencyId($request);
        abort_unless($this->canManage($request, $agencyId), 403, 'Only a director or admin can change this.');
        $d = $request->validate(['auto' => 'required|boolean']);
        FoodPhotos::setAuto($agencyId, (bool) $d['auto']);

        return response()->json(['auto' => (bool) $d['auto']]);
    }

    /**
     * The AI picks a library food for each dish local matching could not place. It is given ONLY the dish names and
     * the library's slugs/names - never a child, a family or anything personal - and may answer "none".
     */
    public function ai(Request $request): JsonResponse
    {
        $d = $request->validate(['names' => 'required|array|max:40', 'names.*' => 'nullable|string|max:120']);
        $agencyId = $this->agencyId($request);
        abort_unless(AiBundle::on($agencyId), 403, 'Choosing food photos with AI is part of the AI Bundle.');
        $names = array_values(array_unique(array_filter(array_map(fn ($n) => trim((string) $n), $d['names']))));
        if (! $names) return response()->json(['matches' => []]);
        $key = config('services.anthropic.key') ?: config('services.anthropic.api_key');
        if (! $key) return response()->json(['message' => 'AI photo matching is not available right now.'], 503);

        $lib = FoodPhotos::library();
        $catalogue = implode("\n", array_map(fn ($f) => $f['slug'] . ' = ' . $f['name'], $lib));
        $list = implode("\n", array_map(fn ($i, $n) => ($i + 1) . '. ' . $n, array_keys($names), $names));
        $system = 'You match dishes on a childcare centre menu to the closest photo in a fixed library. '
            . 'Answer ONLY with JSON: an object whose keys are the dish numbers as strings and whose values are a library slug '
            . 'from the list, or "none" when no photo would honestly show that dish (do not force a poor match; a photo of a '
            . 'different dish misleads parents). Prefer the photo a parent would recognise as the same food.';
        UsageMeter::forAgency($agencyId);
        try {
            $r = Http::timeout(30)->withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json'])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model' => config('services.anthropic.model') ?: 'claude-haiku-4-5-20251001',
                    'max_tokens' => 800,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => "LIBRARY (slug = food):\n" . $catalogue . "\n\nDISHES:\n" . $list]],
                ]);
        } catch (\Throwable $e) {
            Log::warning('food photo AI: no answer', ['agency' => $agencyId, 'error' => $e->getMessage()]);
            return response()->json(['message' => 'The AI did not answer just now. Please try again in a moment.'], 503);
        }
        if (! $r->successful()) {
            Log::warning('food photo AI failed', ['agency' => $agencyId, 'status' => $r->status(), 'body' => mb_substr($r->body(), 0, 300)]);
            return response()->json(['message' => 'AI photo matching is not available right now. Please try again later.'], 503);
        }
        $text = (string) (\App\Support\AiConfig::text($r->json()) ?? '');
        $json = preg_match('/\{.*\}/s', $text, $m) ? json_decode($m[0], true) : null;
        $out = [];
        foreach ($names as $i => $n) {
            $slug = is_array($json) ? ($json[(string) ($i + 1)] ?? null) : null;
            $f = ($slug && $slug !== 'none') ? FoodPhotos::bySlug((string) $slug) : null;
            $out[$n] = $f ? ['id' => (int) $f['id'], 'slug' => $f['slug'], 'name' => $f['name'], 'url' => $f['url'],
                'thumb_url' => $f['thumb_url'], 'allergens' => $f['allergens'], 'score' => null, 'ai' => true] : null;
        }

        return response()->json(['matches' => $out]);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────

    /** The active agency, as OperationsController / AiAssistController resolve it: the header only when the user holds it. */
    private function agencyId(Request $request): int
    {
        $uid = $request->user()->id;
        $activeId = (int) $request->header('X-Active-Agency-Id');
        if ($activeId && DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->where(function ($w) use ($activeId) { $w->where('agency_id', $activeId)->orWhere('role', 'platform_admin'); })->exists()) {
            return $activeId;
        }
        if (DB::table('role_assignments')->where('user_id', $uid)->where('role', 'platform_admin')->where('active', true)->exists()) {
            abort(400, 'Select an agency first.');
        }
        $first = DB::table('role_assignments')->where('user_id', $uid)->where('active', true)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator'])->value('agency_id');
        abort_unless($first, 403);

        return (int) $first;
    }

    private function canManage(Request $request, int $agencyId): bool
    {
        return DB::table('role_assignments')->where('user_id', $request->user()->id)->where('active', true)
            ->where(function ($w) use ($agencyId) {
                $w->where(fn ($x) => $x->where('agency_id', $agencyId)->whereIn('role', ['agency_admin', 'centre_director']))
                  ->orWhere('role', 'platform_admin');
            })->exists();
    }
}
