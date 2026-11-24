<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * v22p59 — AI photo classification.
 * Uses Anthropic Claude Vision to identify which children appear in a photo.
 * Confidence + auto-vs-manual tracked in photo_tags.
 */
final class PhotoTagController extends Controller
{
    use ResolvesCentreContext;

    public function listTags(Request $request, int $photoId): JsonResponse
    {
        // SECURITY (v22p95): tags expose co-tagged children's names — restrict to
        // staff of the photo's centre (platform_admin sees any).
        $photo = DB::table('photos')->where('id', $photoId)->first();
        abort_unless($photo, 404);
        $u = $request->user();
        abort_unless($this->isPlatformAdminUser($u) || $this->authorizeCentreAccess($u, (int) $photo->centre_id), 403);
        $tags = DB::table('photo_tags as pt')
            ->join('children as c', 'c.id', '=', 'pt.child_id')
            ->where('pt.photo_id', $photoId)
            ->select('pt.*', DB::raw("CONCAT(c.first_name,' ',c.last_name) as child_name"))
            ->get();
        return response()->json(['data' => $tags]);
    }

    public function classifyPhoto(Request $request, int $photoId): JsonResponse
    {
        $photo = DB::table('photos')->where('id', $photoId)->first();
        abort_unless($photo, 404);
        // SECURITY (v22p95): only staff of the photo's centre may AI-classify it.
        $u = $request->user();
        abort_unless($this->isPlatformAdminUser($u) || $this->authorizeCentreAccess($u, (int) $photo->centre_id), 403);

        // Photo AI tagging is part of the AI Bundle (2026-10-28).
        if (!\App\Support\AiBundle::forCentre((int) $photo->centre_id)) {
            return response()->json(['message' => 'AI photo tagging is part of the AI Bundle. Ask your KiddieTrac contact to add it to your licence.'], 403);
        }
        $key = config('services.anthropic.key');
        if (!$key) return response()->json(['error' => 'AI not configured'], 503);
        /* PHOTO CONSENT (2026-11-06, App\Support\MediaConsent): a hidden photo, or one of a child whose family said
           "do not photograph", is never sent to the AI; and those children's names are never in the list it gets. */
        if (! empty($photo->hidden_at) || \App\Support\MediaConsent::untaggable(\App\Support\MediaConsent::tagged($photo))) {
            return response()->json(['message' => 'This photo cannot be tagged: a child on it has no photo consent.'], 422);
        }

        // Candidate children at the centre with photos on file (for matching)
        $candidates = DB::table('children as c')
            ->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('f.centre_id', $photo->centre_id)
            ->where('c.enrollment_status', 'enrolled')
            ->whereNull('c.deleted_at')
            ->select('c.id', 'c.first_name', 'c.last_name', 'c.photo_url')
            ->get();
        $lv = \App\Support\MediaConsent::forChildren($candidates->pluck('id')->all());
        $candidates = $candidates->reject(fn ($c) => ($lv[(int) $c->id]['level'] ?? 'family') === \App\Support\MediaConsent::NONE)->values();

        $childList = $candidates->map(fn ($c) => "{$c->id}: {$c->first_name} {$c->last_name}")->implode("\n");
        $prompt = "You are helping staff at a childcare centre tag a photo with which children appear in it. "
            . "Look at the photo and tell me which of these enrolled children are present. "
            . "Return JSON only:\n"
            . '{"children":[{"id":N,"confidence":0.0to1.0,"name":"..."}],"notes":"..."}'
            . "\n\nEnrolled children at this centre:\n{$childList}";

        try {
            /* v22p69 base64 fix */
            // Read image from disk and send as base64 (more reliable than URL-fetch).
            $relPath = ltrim($photo->url, '/');
            // Path may be /storage/photos/... which is a public symlink — strip /storage/ and read from storage/app/public/
            if (str_starts_with($relPath, 'storage/')) {
                $diskPath = storage_path('app/public/' . substr($relPath, 8));
            } else {
                $diskPath = public_path($relPath);
            }
            if (!is_file($diskPath)) {
                return response()->json(['error' => 'Image file not found on server', 'path' => $diskPath], 500);
            }
            $bytes = file_get_contents($diskPath);
            $mime = mime_content_type($diskPath) ?: 'image/jpeg';
            // Anthropic accepts: image/jpeg, image/png, image/gif, image/webp
            $supported = ['image/jpeg' => 1, 'image/png' => 1, 'image/gif' => 1, 'image/webp' => 1];
            if (!isset($supported[$mime])) {
                return response()->json(['error' => "Image type {$mime} not supported by Claude vision (jpeg/png/gif/webp only)"], 415);
            }
            $b64 = base64_encode($bytes);

            $res = Http::withHeaders([
                'x-api-key' => $key,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(60)->post('https://api.anthropic.com/v1/messages', [
                'model' => config('services.anthropic.model'),
                'max_tokens' => 600,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => $b64]],
                        ['type' => 'text', 'text' => $prompt],
                    ],
                ]],
            ]);

            if ($res->status() === 400) {
                // Surface the actual Anthropic error for debugging
                \Illuminate\Support\Facades\Log::warning('Claude vision 400', ['body' => $res->body()]);
                return response()->json([
                    'error' => 'AI rejected the image',
                    'detail' => $res->json('error.message') ?? $res->body(),
                ], 502);
            }
            if (!$res->ok()) {
                DB::table('photos')->where('id', $photoId)->update([
                    'ai_status' => 'failed', 'ai_classified_at' => now(),
                ]);
                return response()->json(['error' => 'AI failed', 'status' => $res->status()], 502);
            }
            $raw = \App\Support\AiConfig::text($res->json()) ?? '';
            $jsonStart = strpos($raw, '{');
            $jsonEnd = strrpos($raw, '}');
            $payload = $jsonStart !== false
                ? json_decode(substr($raw, $jsonStart, $jsonEnd - $jsonStart + 1), true)
                : null;

            $tagged = 0;
            foreach (($payload['children'] ?? []) as $row) {
                if (empty($row['id'])) continue;
                DB::table('photo_tags')->updateOrInsert(
                    ['photo_id' => $photoId, 'child_id' => (int) $row['id']],
                    [
                        'confidence' => (float) ($row['confidence'] ?? 0),
                        'ai_tagged' => 1,
                        'confirmed' => 0,
                        'created_at' => now(),
                    ]
                );
                $tagged++;
            }
            DB::table('photos')->where('id', $photoId)->update([
                'ai_status' => 'done', 'ai_classified_at' => now(),
            ]);
            return response()->json(['tagged' => $tagged, 'children' => $payload['children'] ?? []]);
        } catch (\Throwable $e) {
            Log::warning('Photo AI failed', ['photo' => $photoId, 'msg' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 502);
        }
    }

    public function confirmTag(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo_id' => 'required|integer',
            'child_id' => 'required|integer',
            'confirmed' => 'required|boolean',
        ]);
        abort_unless($this->canAccessChildId($request->user(), (int) $data['child_id']), 403); // v22p94
        /* The photo must be at a centre you work at, and the child in the same agency (2026-11-05 leak sweep):
           photo_id was never checked, so staff could tag their own child onto another centre's or another
           agency's photo (or remove tags from it), which then shows that photo in the wrong family's feed. */
        $photo = DB::table('photos')->where('id', (int) $data['photo_id'])->first();
        abort_unless($photo, 404);
        $agencyId = (int) $this->resolveAgencyId($request);
        $photoAgency = (int) DB::table('centres')->where('id', (int) $photo->centre_id)->value('agency_id');
        $childAgency = (int) DB::table('children as ch')->join('families as f', 'f.id', '=', 'ch.family_id')
            ->join('centres as c', 'c.id', '=', 'f.centre_id')
            ->where('ch.id', (int) $data['child_id'])->value('c.agency_id');
        abort_unless($agencyId && $photoAgency === $agencyId && $childAgency === $agencyId
            && \App\Support\CentreScope::canWrite(\App\Support\CentreScope::ids($request, $agencyId), (int) $photo->centre_id), 403);
        if ($data['confirmed'] && ($why = \App\Support\MediaConsent::untaggable([(int) $data['child_id']]))) {
            return response()->json(['message' => $why, 'reason' => 'no_photo_consent'], 422);
        }
        if ($data['confirmed']) {
            DB::table('photo_tags')->updateOrInsert(
                ['photo_id' => $data['photo_id'], 'child_id' => $data['child_id']],
                [
                    'confirmed' => 1, 'tagged_by_user_id' => $request->user()->id,
                    'created_at' => now(),
                ]
            );
        } else {
            DB::table('photo_tags')
                ->where('photo_id', $data['photo_id'])
                ->where('child_id', $data['child_id'])
                ->delete();
        }
        return response()->json(['status' => $data['confirmed'] ? 'confirmed' : 'removed']);
    }
}
