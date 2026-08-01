<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MediaController extends Controller
{
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,heic,webp', 'max:8192'],
            'child_ids' => ['required'],
            'caption' => ['nullable', 'string', 'max:500'],
            'taken_at' => ['nullable', 'date'],
            'room_id' => ['nullable', 'integer'],
        ]);

        $childIds = $request->input('child_ids');
        if (is_string($childIds)) {
            $childIds = array_filter(array_map('intval', explode(',', $childIds)));
        }
        $childIds = array_unique(array_filter(array_map('intval', (array) $childIds)));
        if (empty($childIds)) {
            return response()->json(['message' => 'At least one child_id required'], 422);
        }
        // SECURITY (v22p94): the uploader must have access to EVERY tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChild($request->user(), (int) $cid), 403);
        }

        $roomId = $request->input('room_id');
        if (!$roomId) {
            $roomId = DB::table('enrollments')
                ->where('child_id', $childIds[0])
                ->whereNull('end_date')
                ->value('room_id');
        }

        $file = $request->file('photo');
        $year = now()->format('Y');
        $month = now()->format('m');
        $uuid = Str::uuid()->toString();
        $extension = strtolower($file->getClientOriginalExtension());
        $relativePath = "media/{$year}/{$month}/{$uuid}.{$extension}";

        Storage::disk('public')->putFileAs("media/{$year}/{$month}", $file, "{$uuid}.{$extension}");

        $takenAt = $request->input('taken_at') ?: now();

        $mediaId = DB::table('media')->insertGetId([
            'room_id' => $roomId,
            'media_type' => 'photo',
            'storage_path' => $relativePath,
            'size_bytes' => $file->getSize(),
            'caption' => $request->input('caption'),
            'taken_at' => $takenAt,
            'taken_by_id' => $request->user()->id,
            'created_at' => now(),
        ]);

        foreach ($childIds as $childId) {
            DB::table('media_child_tags')->updateOrInsert(
                ['media_id' => $mediaId, 'child_id' => $childId],
                ['auto_tagged' => false]
            );
        }

        return response()->json([
            'message' => 'Photo uploaded',
            'media_id' => $mediaId,
            'tagged_children' => count($childIds),
            'url' => '/storage/' . $relativePath,
        ], 201);
    }

    public function forChild(Request $request, int $childId): JsonResponse
    {
        if (!$this->canAccessChild($request->user(), $childId)) {
            abort(403);
        }
        $limit = min(50, (int) $request->input('limit', 30));

        // Read the table educators actually upload into.
        //
        // This used to query `media` + `media_child_tags` — which is EMPTY. Every
        // photo an educator shares goes to `photos` (PhotoFeedController::upload,
        // tagging children in a child_ids JSON column), so the parent gallery has
        // been showing parents nothing at all while their child's photos existed
        // the whole time. Videos (media_type = 'video') come through here too.
        $rows = DB::table('photos')
            ->whereJsonContains('child_ids', (int) $childId)
            ->orderByDesc('taken_at')
            ->limit($limit)
            ->get(['id', 'url', 'thumbnail_url', 'media_type', 'caption', 'taken_at']);

        return response()->json([
            'photos' => $rows->map(fn ($m) => [
                'id' => $m->id,
                'url' => $m->url,
                'thumbnail_url' => $m->thumbnail_url,
                'media_type' => $m->media_type ?: 'image',
                'caption' => $m->caption,
                'taken_at' => $m->taken_at,
                'date_display' => Carbon::parse($m->taken_at)->format('M j, g:i A'),
            ])->all(),
        ]);
    }

    public function observationsForChild(Request $request, int $childId): JsonResponse
    {
        if (!$this->canAccessChild($request->user(), $childId)) {
            abort(403);
        }
        $observations = DB::table('observations')
            ->where('child_id', $childId)
            ->orderByDesc('observed_at')
            ->limit(20)
            ->get();

        return response()->json([
            'observations' => $observations->map(fn ($o) => [
                'id' => $o->id,
                'domain' => $o->domain,
                'title' => $o->title,
                'body' => $o->body,
                'observed_at' => $o->observed_at,
                'date_display' => Carbon::parse($o->observed_at)->format('M j'),
            ])->all(),
        ]);
    }

    public function createObservation(Request $request): JsonResponse
    {
        $data = $request->validate([
            'child_id' => ['required', 'integer'],
            'domain' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string'],
            'media_id' => ['nullable', 'integer'],
            'observed_at' => ['nullable', 'date'],
        ]);
        abort_unless($this->canAccessChild($request->user(), (int) $data['child_id']), 403); // v22p94

        $id = DB::table('observations')->insertGetId([
            'child_id' => $data['child_id'],
            'framework' => 'ELECT',
            'domain' => $data['domain'],
            'title' => $data['title'],
            'body' => $data['body'],
            'media_ids' => isset($data['media_id']) ? json_encode([$data['media_id']]) : null,
            'observed_at' => $data['observed_at'] ?: now(),
            'recorded_by_id' => $request->user()->id,
            'shared_with_family' => true,
            'created_at' => now(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Observation recorded'], 201);
    }

    private function canAccessChild($user, int $childId): bool
    {
        $child = DB::table('children')->where('id', $childId)->first();
        if (!$child) return false;

        $isGuardian = DB::table('guardians')
            ->where('user_id', $user->id)
            ->where('family_id', $child->family_id)
            ->exists();
        if ($isGuardian) return true;

        $family = DB::table('families')->where('id', $child->family_id)->first();
        if (!$family) return false;
        return DB::table('role_assignments')
            ->where('user_id', $user->id)
            ->where('active', true)
            ->where('centre_id', $family->centre_id)
            ->exists();
    }

    // ---------------------------------------------------------------- Authenticated media proxy
    // Photos live on external CDNs / storage that do NOT send CORS headers, so the
    // browser can DISPLAY them in an <img> but the app's fetch() cannot read the bytes
    // to trigger a Save (this is why parent photo "download" did nothing while payslips
    // — which the API itself serves — worked). This streams a media URL back THROUGH the
    // API (same-origin to the app, with the standard CORS + Content-Disposition:
    // attachment), so the blob download works everywhere, exactly like the payslip PDF.
    public function download(Request $request)
    {
        $url  = (string) $request->query('url', '');
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $request->query('name', 'photo'));
        if ($name === '' || trim($name, '-') === '') {
            $name = 'photo';
        }

        $parts = parse_url($url);
        if (! $parts || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return response()->json(['message' => 'Invalid URL.'], 422);
        }
        // SSRF guard: resolve the host and refuse private / reserved ranges.
        $ip = @gethostbyname($parts['host']);
        if (! filter_var($ip, FILTER_VALIDATE_IP)
            || ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return response()->json(['message' => 'Host not allowed.'], 422);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4, // this host has no IPv6 route
            CURLOPT_USERAGENT      => 'KiddieTrac/1.0 (+media-proxy)',
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $ctype  = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false || $status < 200 || $status >= 400) {
            return response()->json(['message' => 'Could not fetch the file.'], 502);
        }
        if ($ctype && stripos($ctype, 'image/') !== 0) {
            return response()->json(['message' => 'Not an image.'], 415);
        }
        if (! preg_match('/\.(jpg|jpeg|png|webp|gif)$/i', $name)) {
            $ext = stripos($ctype, 'png') !== false ? 'png'
                : (stripos($ctype, 'webp') !== false ? 'webp'
                : (stripos($ctype, 'gif') !== false ? 'gif' : 'jpg'));
            $name .= '.' . $ext;
        }

        return response($body, 200, [
            'Content-Type'        => $ctype ?: 'image/jpeg',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control'       => 'private, max-age=0',
        ]);
    }
}
