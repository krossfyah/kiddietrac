<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * v22p55 — Photo feed.
 *  GET  /photos/feed         scope by user role; parents see their child's photos only
 *  POST /photos              upload, returns photo row; agency_admin/centre_director/educator
 */
final class PhotoFeedController extends Controller
{
    use ResolvesCentreContext;

    public function feed(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();

        $q = DB::table('photos as p')
            ->leftJoin('users as up', 'up.id', '=', 'p.uploaded_by_id')
            ->leftJoin('centres as c', 'c.id', '=', 'p.centre_id')
            ->orderByDesc('p.taken_at')
            ->select(
                'p.id', 'p.url', 'p.thumbnail_url', 'p.media_type', 'p.caption', 'p.taken_at',
                'p.centre_id', 'c.name as centre_name',
                DB::raw("CONCAT(up.first_name,' ',up.last_name) as uploader_name"),
                'p.child_ids'
            )->limit(120);

        if (!$isStaff) {
            $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
            $childIds = DB::table('children')->whereIn('family_id', $familyIds)->whereNull('deleted_at')->pluck('id')->all();
            if (empty($childIds)) return response()->json(['data' => []]);
            $q->where(function ($q) use ($childIds) {
                foreach ($childIds as $cid) $q->orWhereRaw("JSON_CONTAINS(child_ids, ?)", [(string) $cid]);
            });
        } else {
            // SECURITY: never trust X-Active-Agency-Id blindly — only honour it if the
            // caller actually holds an active role in that agency (or is platform_admin).
            // Otherwise fall back to their own agency, so staff can't read another
            // agency's children's photos by spoofing the header.
            $hdr = (int) $request->header('X-Active-Agency-Id');
            $isPlatform = DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->where('role', 'platform_admin')->exists();
            $allowed = $hdr && ($isPlatform || DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->where('agency_id', $hdr)->exists());
            $agencyId = $allowed ? $hdr : (int) DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id');
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
            $q->whereIn('p.centre_id', $centreIds);
        }
        $rows = $q->get();
        $allChildIds = $rows->pluck('child_ids')->flatMap(fn ($v) => $v ? (json_decode($v, true) ?: []) : [])->unique();
        $childNames = DB::table('children')->whereIn('id', $allChildIds)->whereNull('deleted_at')
            ->select('id', DB::raw("CONCAT(first_name,' ',last_name) as name"))->get()->keyBy('id');
        $rows->transform(function ($p) use ($childNames) {
            $ids = $p->child_ids ? (json_decode($p->child_ids, true) ?: []) : [];
            $p->child_names = collect($ids)->map(fn ($id) => $childNames[$id]->name ?? null)->filter()->values();
            unset($p->child_ids);
            return $p;
        });
        return response()->json(['data' => $rows]);
    }

    public function upload(Request $request): JsonResponse
    {
        $u = $request->user();
        // Educators can now share a short VIDEO clip as well as a photo — the
        // moments parents most want (first steps, a song, a wobble across the
        // room) don't survive as stills. Capped at 30MB, which is about 30
        // seconds of phone video and inside PHP's 32MB upload limit; anything
        // longer is a file transfer, not a moment.
        $request->validate([
            'photo' => 'required|file|mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/quicktime,video/webm,video/3gpp|max:30720',
            'caption' => 'nullable|string|max:500',
            'child_ids' => 'nullable',
            'centre_id' => 'nullable|integer',
            // A still captured from the video in the browser. There is no ffmpeg here,
            // so without this a video tile has nothing to show until the file downloads.
            'poster' => 'nullable|file|mimetypes:image/jpeg,image/png|max:2048',
        ]);
        $hasUpload = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($hasUpload, 403);

        $file = $request->file('photo');
        $path = $file->storeAs('photos/' . now()->format('Y/m'), $u->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName()), 'public');

        $centreId = (int) ($request->input('centre_id') ?: DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('centre_id') ?: 1);
        $childIds = $request->input('child_ids');
        if (is_string($childIds)) $childIds = json_decode($childIds, true);
        if (!is_array($childIds)) $childIds = [];
        // SECURITY (v22p94): the uploader must have access to every tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($u, (int) $cid), 403);
        }

        // A video has no still to use as its thumbnail (no ffmpeg here), so the
        // clients render a <video> element with preload=metadata and let the
        // browser show the first frame. media_type is what tells them which.
        $isVideo = str_starts_with((string) $file->getMimeType(), 'video/');

        // Build a REAL thumbnail. thumbnail_url used to be set to the full-size path,
        // so every gallery tile pulled the whole photo down — ~5 MB for a phone shot
        // rendered into a 220px box. Falls back to the original if the resize fails.
        // A phone writes the MP4 index (moov) at the END of the file, so a browser must
        // download the whole clip before it can show frame one. Move it to the front
        // now, while we still have the file in hand.
        if ($isVideo) {
            try {
                \App\Support\Mp4FastStart::process(
                    \Illuminate\Support\Facades\Storage::disk('public')->path($path)
                );
            } catch (\Throwable $e) {
                // leave the upload exactly as it arrived
            }
        }

        // A video's poster arrives as its own upload; shrink it like any other image
        // so the tile stays light.
        $thumb = null;
        if ($isVideo && $request->hasFile('poster')) {
            try {
                $pPath = $request->file('poster')->storeAs(
                    'photos/' . now()->format('Y/m'),
                    $u->id . '-' . time() . '-poster.jpg',
                    'public'
                );
                $pAbs = \Illuminate\Support\Facades\Storage::disk('public')->path($pPath);
                $made = \App\Support\MediaThumb::make($pAbs);
                $thumb = $made ? \App\Support\MediaThumb::webPath($made) : '/storage/' . $pPath;
            } catch (\Throwable $e) {
                $thumb = null;
            }
        }
        if (! $isVideo) {
            try {
                $abs = \Illuminate\Support\Facades\Storage::disk('public')->path($path);
                $made = \App\Support\MediaThumb::make($abs);
                if ($made) $thumb = \App\Support\MediaThumb::webPath($made);
            } catch (\Throwable $e) {
                $thumb = null;
            }
        }

        $id = DB::table('photos')->insertGetId([
            'centre_id' => $centreId,
            'uploaded_by_id' => $u->id,
            'url' => '/storage/' . $path,
            'thumbnail_url' => $thumb ?: ($isVideo ? null : '/storage/' . $path),
            'media_type' => $isVideo ? 'video' : 'image',
            'caption' => $request->input('caption'),
            'child_ids' => json_encode($childIds),
            'taken_at' => now(),
            'created_at' => now(),
        ]);

        // Put the moment on the child's TIMELINE too, so the day reads as a story
        // rather than the photo living only in the gallery. daily_events.event_type
        // is an enum, so we file it as 'note' and carry the real shape in payload —
        // formatEvent() turns that into "A new photo was shared" for the parent.
        // photo_id is an existing column on daily_events, made for exactly this.
        // Best-effort: a timeline hiccup must never fail an upload that succeeded.
        foreach ($childIds as $cid) {
            try {
                $roomId = DB::table('enrollments')->where('child_id', $cid)
                    ->whereNull('end_date')->value('room_id');
                DB::table('daily_events')->insert([
                    'child_id' => (int) $cid,
                    'room_id' => $roomId,
                    'event_type' => 'note',
                    'occurred_at' => now(),
                    'payload' => json_encode([
                        'kind' => 'media',
                        'media_type' => $isVideo ? 'video' : 'photo',
                        'photo_id' => $id,
                        'note' => (string) $request->input('caption'),
                    ]),
                    'notes' => (string) $request->input('caption'),
                    'photo_id' => $id,
                    'recorded_by_id' => $u->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        // notify guardians for tagged children
        if (!empty($childIds)) {
            $familyIds = DB::table('children')->whereIn('id', $childIds)->pluck('family_id')->unique();
            $guardianIds = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id');
            $photoBody = $request->input('caption') ?: 'A new moment was added.';
            foreach ($guardianIds as $gid) {
                DB::table('notifications')->insert([
                    'user_id' => $gid, 'type' => 'photo',
                    'title' => 'New photo shared', 'body' => $photoBody,
                    'data' => json_encode(['link' => '#photos', 'photo_id' => $id]),
                    'created_at' => now(),
                ]);
                try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, 'New photo shared 📸', $photoBody, '#photos'); } catch (\Throwable $e) {}
            }
        }

        return response()->json(['id' => $id, 'url' => '/storage/' . $path, 'media_type' => $isVideo ? 'video' : 'image'], 201);
    }
}
