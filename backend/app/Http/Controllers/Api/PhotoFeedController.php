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
 *  PATCH /photos/{id}/children  change who is tagged (2026-10-20)
 */
final class PhotoFeedController extends Controller
{
    use ResolvesCentreContext;

    public function feed(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
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
                'p.child_ids', 'p.hidden_at'
            )->limit($isStaff ? 120 : 400);
        // A hidden photo (no photo consent, App\Support\MediaConsent) is shown to nobody.
        $q->whereNull('p.hidden_at');

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
            $isPlatform = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)->where('active', 1)->where('role', 'platform_admin')->exists();
            $allowed = $hdr && ($isPlatform || DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->where('agency_id', $hdr)->exists());
            $agencyId = $allowed ? $hdr : (int) DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id');
            /* THE CENTRES THEY WORK AT, not every centre the agency owns. The check
               above stops another AGENCY's photos being read; it did nothing about
               another CENTRE's. Measured 2026-09-03: an educator at centre 18 was
               served 12 photographs of children at centre 16.

               visibleCentreIds() answers this by role and fails closed — an admin
               still gets the whole agency. `?: [0]` so an empty list means no rows
               rather than no filter. */
            $centreIds = $this->visibleCentreIds($request);
            $q->whereIn('p.centre_id', $centreIds ?: [0]);
        }
        $rows = $q->get();
        /* PHOTO CONSENT (2026-11-06): a parent sees a photo only if every OTHER child on it may appear in photos other
           families see, and no child on it is at "do not photograph". Over-fetched above so 120 still come back. */
        $consent = [];
        if (! $isStaff) {
            $rows = \App\Support\MediaConsent::filter($rows, \App\Support\MediaConsent::familyChildren($familyIds->all()))->take(120)->values();
        } else {
            $consent = \App\Support\MediaConsent::forChildren($rows->flatMap(fn ($p) => \App\Support\MediaConsent::tagged($p))->all());
        }
        $allChildIds =$rows->pluck('child_ids')->flatMap(fn ($v) => $v ? (json_decode($v, true) ?: []) : [])->unique();
        $childNames = DB::table('children')->whereIn('id', $allChildIds)->whereNull('deleted_at')
            ->select('id', DB::raw("CONCAT(first_name,' ',last_name) as name"))->get()->keyBy('id');
        /* A PARENT SEES ONLY THEIR OWN CHILDREN'S NAMES ON A GROUP PHOTO (2026-10-20).
           Tagging several children is now one tap, so a group photo routinely carries
           other families' children - and this listed every one of them, full name, to
           every family tagged. The photo is shared; the class list is not. */
        $own = $isStaff ? null : array_flip(array_map('intval', $childIds));
        $rows->transform(function ($p) use ($childNames, $own, $isStaff, $consent) {
            unset($p->hidden_at);
            $ids = $p->child_ids ? (json_decode($p->child_ids, true) ?: []) : [];
            $ids = array_values(array_filter(array_map('intval', $ids)));
            $shown = $own === null ? $ids : array_values(array_filter($ids, fn ($id) => isset($own[$id])));
            $p->child_names = collect($shown)->map(fn ($id) => $childNames[$id]->name ?? null)->filter()->values();
            unset($p->child_ids);
            // Staff edit the tags, so they get the ids as well.
            if ($isStaff) {
                $p->child_ids = array_values(array_filter($ids, fn ($id) => isset($childNames[$id])));
                // Each tagged child's photo permission, so the tagger can show it (none | family | group).
                $p->consent = (object) collect($p->child_ids)->mapWithKeys(fn ($id) => [$id => $consent[$id]['level'] ?? 'family'])->all();
            }
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
            /* SEVERAL PHOTOS, ONE PING (2026-11-13). Sharing several photos at once posts them one by one; every one
               but the last says batch_quiet=1 (filed, timeline written, nobody's phone rung), and the last says
               batch_total=N so families hear "3 new photos shared" once instead of three times. */
            'batch_quiet' => 'nullable|boolean',
            'batch_total' => 'nullable|integer|min:1|max:50',
        ]);
        $hasUpload = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($hasUpload, 403);

        /* The photo's centre must be one you work at, in this agency (2026-11-05 leak sweep): centre_id was taken
           from the request unchecked, and with none sent it fell back to the first centre on any of your roles
           (any agency) and then to centre 1, so photos were filed into other centres' and other agencies'
           galleries. With none sent it is now the first centre you may see here; with none of those, refused. */
        $visibleCentres = $this->visibleCentreIds($request);
        $centreId = (int) ($request->input('centre_id') ?: ($visibleCentres[0] ?? 0));
        $agencyId = (int) $this->resolveAgencyId($request);
        if (! $centreId || ! $agencyId
            || (int) DB::table('centres')->where('id', $centreId)->value('agency_id') !== $agencyId
            || ! (in_array($centreId, $visibleCentres, true) || $this->authorizeCentreAccess($u, $centreId))) {
            return response()->json(['message' => 'Choose a centre you work at before sharing a photo.'], 422);
        }

        $file = $request->file('photo');
        $path = $file->storeAs('photos/' . now()->format('Y/m'), $u->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName()), 'public');

        $childIds = $request->input('child_ids');
        if (is_string($childIds)) $childIds = json_decode($childIds, true);
        if (!is_array($childIds)) $childIds = [];
        /* Flatten, and keep only real ids.
           A client that wrapped the list twice sent [[92,44,48]]; the loop below then
           cast that inner array with (int), which in PHP is 1, and cheerfully ran the
           access check against child #1. It happened to fail closed only because no
           child #1 exists — had one existed and been reachable, the guard would have
           PASSED and the photo would have been filed against a nonsense list. An id
           this code cannot recognise must be rejected, never coerced. */
        $childIds = collect($childIds)->flatten()
            ->filter(fn ($v) => is_numeric($v) && (int) $v > 0)
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        // SECURITY (v22p94): the uploader must have access to every tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($u, $cid), 403);
        }
        // PHOTO CONSENT: a child whose family said "do not photograph" cannot be tagged. Before the file is stored.
        if ($why = \App\Support\MediaConsent::untaggable($childIds)) {
            return response()->json(['message' => $why, 'reason' => 'no_photo_consent'], 422);
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
        // PHOTO CONSENT: only the families allowed to see this photo get it on the timeline or hear about it - a
        // child whose family has not agreed to group photos keeps the photo from everyone else's family.
        $mayFam = \App\Support\MediaConsent::familiesWhoMaySee((object) ['child_ids' => json_encode($childIds), 'hidden_at' => null]);
        $seen = $mayFam ? array_merge(...array_values($mayFam)) : [];
        $keptFrom = count(array_diff($childIds, $seen));
        foreach ($seen as $cid) {
            try {
                $roomId = DB::table('enrollments')->where('child_id', $cid)
                    ->whereNull('end_date')
                    ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'enrollments'))
                    ->value('room_id');
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

        // notify guardians for tagged children (once per batch - see batch_quiet above)
        if (!empty($mayFam) && ! $request->boolean('batch_quiet')) {
            $guardianIds = DB::table('guardians')->whereIn('family_id', array_keys($mayFam))->pluck('user_id');
            $photoBody = $request->input('caption') ?: 'A new moment was added.';
            $many = (int) $request->input('batch_total', 1);
            $photoTitle = $many > 1 ? $many . ' new photos shared' : 'New photo shared';
            foreach ($guardianIds as $gid) {
                if (! \App\Support\ChildMomentNotify::wants((int) $gid, \App\Support\ChildMomentNotify::PREF_PHOTOS)) { continue; }
                \App\Support\Notify::write([
                    'user_id' => $gid, 'type' => 'photo',
                    'title' => $photoTitle, 'body' => $photoBody,
                    'data' => json_encode(['link' => '#photos', 'photo_id' => $id]),
                    'created_at' => now(),
                ]);
                try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, $photoTitle . ' 📸', $photoBody, '#photos'); } catch (\Throwable $e) {}
            }
        }

        return response()->json(['id' => $id, 'url' => '/storage/' . $path, 'media_type' => $isVideo ? 'video' : 'image',
            // How many tagged children's families will NOT see it (another child on it is "family only").
            'kept_from' => $keptFrom,
            'notice' => $keptFrom ? \App\Support\MediaConsent::keptFromNotice($childIds) : null], 201);
    }

    /**
     * PATCH /photos/{id}/children — change who is tagged in a photo already shared.
     *
     * Anthony (2026-10-20): "have ability to tag in photos based on childrens name in the
     * room as a feature as well which gets logged onto that childrens feed (easy for
     * educator to tag multiple kids)". `child_ids` is the full new list. A child ADDED gets
     * the moment on their timeline and their family is told, exactly as if they had been
     * tagged at upload; a child REMOVED loses the timeline entry (the photo is no longer
     * theirs to see). A family that already had a child on the photo is not told twice.
     */
    public function tagChildren(Request $request, int $id): JsonResponse
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($isStaff, 403);
        $request->validate(['child_ids' => 'present|array|max:60', 'child_ids.*' => 'integer|min:1']);

        $photo = DB::table('photos')->where('id', $id)->first();
        // Same rule as the feed: a photo at a centre this person cannot see does not exist for them.
        if (! $photo || ! in_array((int) $photo->centre_id, array_map('intval', $this->visibleCentreIds($request) ?: []), true)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $old = array_values(array_filter(array_map('intval', json_decode((string) $photo->child_ids, true) ?: [])));
        $new = array_values(array_unique(array_filter(array_map('intval', $request->input('child_ids', [])))));
        $added = array_values(array_diff($new, $old));
        $removed = array_values(array_diff($old, $new));
        /* Reachable is not enough: a super admin can reach every child on the platform, and
           tagging one from another agency would show this photo to that agency's family.
           Every child added must be enrolled in the photo's own agency. */
        $photoAgency = (int) (DB::table('centres')->where('id', $photo->centre_id)->value('agency_id') ?? 0);
        // PHOTO CONSENT: a child whose family said "do not photograph" cannot be tagged.
        if ($why = \App\Support\MediaConsent::untaggable($added)) {
            return response()->json(['message' => $why, 'reason' => 'no_photo_consent'], 422);
        }
        foreach ($added as $cid) {
            abort_unless($this->canAccessChildId($u, $cid), 403);
            $sameAgency = DB::table('enrollments as e')->join('rooms as r', 'r.id', '=', 'e.room_id')
                ->join('centres as c', 'c.id', '=', 'r.centre_id')
                ->where('e.child_id', $cid)->where('c.agency_id', $photoAgency)->exists();
            if (! $sameAgency) {
                return response()->json(['message' => 'One of those children is not at this agency, so the tags were not changed.'], 422);
            }
        }

        DB::table('photos')->where('id', $id)->update(['child_ids' => json_encode($new)]);

        /* PHOTO CONSENT: adding a family-only child to a group photo takes it away from the other families on it, so
           the timeline is redone from who may see it now: entries removed for children whose family no longer may,
           added (and the family told) only for newly tagged children whose family may. */
        $mayFam = \App\Support\MediaConsent::familiesWhoMaySee((object) ['child_ids' => json_encode($new), 'hidden_at' => $photo->hidden_at ?? null]);
        $seen = $mayFam ? array_merge(...array_values($mayFam)) : [];
        $lost = array_values(array_diff($new, $seen));
        if ($lost) DB::table('daily_events')->where('photo_id', $id)->whereIn('child_id', $lost)->whereNull('deleted_at')->update(['deleted_at' => now()]);
        $addedAll = $added;   // the audit and the reply report every tag made
        $added = array_values(array_intersect($added, $seen));

        $isVideo = ($photo->media_type ?? 'image') === 'video';
        foreach ($added as $cid) {
            try {
                $roomId = DB::table('enrollments')->where('child_id', $cid)
                    ->whereNull('end_date')
                    ->tap(fn ($q) => \App\Support\CareSchedule::constrain($q, 'enrollments'))
                    ->value('room_id');
                DB::table('daily_events')->insert([
                    'child_id' => (int) $cid,
                    'room_id' => $roomId,
                    'event_type' => 'note',
                    // The moment happened when the photo was taken, not when the tag was added.
                    'occurred_at' => $photo->taken_at ?: now(),
                    'payload' => json_encode([
                        'kind' => 'media',
                        'media_type' => $isVideo ? 'video' : 'photo',
                        'photo_id' => $id,
                        'note' => (string) $photo->caption,
                    ]),
                    'notes' => (string) $photo->caption,
                    'photo_id' => $id,
                    'recorded_by_id' => $u->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
        if ($removed) {
            DB::table('daily_events')->where('photo_id', $id)->whereIn('child_id', $removed)->delete();
        }

        $told = 0;
        if ($added) {
            $already = DB::table('children')->whereIn('id', array_diff($new, $added) ?: [0])->pluck('family_id')->unique()->all();
            $familyIds = DB::table('children')->whereIn('id', $added)->pluck('family_id')->unique()
                ->reject(fn ($f) => in_array($f, $already))->values();
            $guardianIds = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id')->unique();
            $body = $photo->caption ?: 'A new moment was added.';
            foreach ($guardianIds as $gid) {
                if (! \App\Support\ChildMomentNotify::wants((int) $gid, \App\Support\ChildMomentNotify::PREF_PHOTOS)) { continue; }
                \App\Support\Notify::write([
                    'user_id' => $gid, 'type' => 'photo',
                    'title' => $isVideo ? 'New video shared' : 'New photo shared', 'body' => $body,
                    'data' => json_encode(['link' => '#photos', 'photo_id' => $id]),
                    'created_at' => now(),
                ]);
                try { app(\App\Services\FcmService::class)->sendToUser((int) $gid, ($isVideo ? 'New video shared' : 'New photo shared') . ' 📸', $body, '#photos'); } catch (\Throwable $e) {}
                $told++;
            }
        }

        try {
            $names = DB::table('children')->whereIn('id', array_merge($addedAll, $removed) ?: [0])
                ->get(['id', 'first_name', 'last_name'])->keyBy('id');
            $nm = fn ($ids) => implode(', ', array_map(fn ($c) => trim(($names[$c]->first_name ?? '') . ' ' . ($names[$c]->last_name ?? '')) ?: ('#' . $c), $ids));
            \App\Support\Audit::write([
                'user_id' => (int) $u->id,
                'agency_id' => (int) (DB::table('centres')->where('id', $photo->centre_id)->value('agency_id') ?? 0) ?: null,
                'action' => 'photo.tagged',
                'entity_type' => 'photo',
                'entity_id' => $id,
                'payload' => json_encode([
                    'summary' => 'Changed who is tagged in a ' . ($isVideo ? 'video' : 'photo') . '.'
                        . ($addedAll ? ' Added: ' . $nm($addedAll) . '.' : '') . ($removed ? ' Removed: ' . $nm($removed) . '.' : ''),
                    'added' => $addedAll, 'removed' => $removed,
                ]),
                'ip_address' => $request->ip(),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'child_ids' => $new, 'added' => count($addedAll), 'removed' => count($removed), 'families_told' => $told,
            'notice' => \App\Support\MediaConsent::keptFromNotice($new)]);
    }
}
