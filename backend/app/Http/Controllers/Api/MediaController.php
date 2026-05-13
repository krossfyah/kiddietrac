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

        $photos = DB::table('media')
            ->join('media_child_tags', 'media_child_tags.media_id', '=', 'media.id')
            ->where('media_child_tags.child_id', $childId)
            ->where('media.media_type', 'photo')
            ->whereNull('media.deleted_at')
            ->orderByDesc('media.taken_at')
            ->limit($limit)
            ->select('media.*')
            ->get();

        return response()->json([
            'photos' => $photos->map(fn ($m) => [
                'id' => $m->id,
                'url' => '/storage/' . $m->storage_path,
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
}
