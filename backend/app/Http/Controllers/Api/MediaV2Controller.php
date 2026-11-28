<?php
declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * v22p58 — Reactions + video uploads/feed. (Photo upload lives in MediaController.)
 */
final class MediaV2Controller extends Controller
{
    use ResolvesCentreContext;

    public function listReactions(Request $request, string $type, int $id): JsonResponse
    {
        abort_unless(in_array($type, ['photo', 'video', 'observation']), 400);
        // SECURITY (v22p95): a reaction list reveals who engaged with a specific
        // media item — only expose it to users who can access that item.
        abort_unless($this->canAccessReactionTarget($request->user(), $type, $id), 403);
        $rows = DB::table('reactions as r')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->where('target_type', $type)
            ->where('target_id', $id)
            ->select('r.reaction', 'r.user_id', 'r.created_at',
                DB::raw("CONCAT(u.first_name,' ',u.last_name) as user_name"))
            ->get();
        $byReaction = $rows->groupBy('reaction')->map->count();
        return response()->json([
            'data' => $rows,
            'counts' => $byReaction,
            'total' => $rows->count(),
            'mine' => $rows->where('user_id', $request->user()->id)->pluck('reaction')->values(),
        ]);
    }

    public function addReaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => 'required|in:photo,video,observation',
            'target_id' => 'required|integer',
            'reaction' => 'required|in:heart,smile,wow,celebrate,clap',
        ]);
        // SECURITY (v22p95): may only react to media the user can actually access.
        abort_unless($this->canAccessReactionTarget($request->user(), $data['target_type'], (int) $data['target_id']), 403);
        DB::table('reactions')->insertOrIgnore([
            'target_type' => $data['target_type'],
            'target_id' => $data['target_id'],
            'user_id' => $request->user()->id,
            'reaction' => $data['reaction'],
            'created_at' => now(),
        ]);
        return response()->json(['status' => 'added']);
    }

    public function removeReaction(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => 'required|in:photo,video,observation',
            'target_id' => 'required|integer',
            'reaction' => 'required|in:heart,smile,wow,celebrate,clap',
        ]);
        DB::table('reactions')
            ->where('target_type', $data['target_type'])
            ->where('target_id', $data['target_id'])
            ->where('user_id', $request->user()->id)
            ->where('reaction', $data['reaction'])
            ->delete();
        return response()->json(['status' => 'removed']);
    }

    public function videoFeed(Request $request): JsonResponse
    {
        $u = $request->user();
        $isStaff = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        $q = DB::table('videos as v')
            ->leftJoin('users as up', 'up.id', '=', 'v.uploaded_by_id')
            ->leftJoin('centres as c', 'c.id', '=', 'v.centre_id')
            ->orderByDesc('v.taken_at')
            ->select('v.id', 'v.url', 'v.thumbnail_url', 'v.duration_seconds',
                'v.caption', 'v.taken_at', 'v.centre_id', 'c.name as centre_name',
                DB::raw("CONCAT(up.first_name,' ',up.last_name) as uploader_name"),
                'v.child_ids')
            ->limit(80);

        if (!$isStaff) {
            $familyIds = DB::table('guardians')->where('user_id', $u->id)->pluck('family_id');
            $childIds = DB::table('children')->whereIn('family_id', $familyIds)
                ->whereNull('deleted_at')->pluck('id')->all();
            if (empty($childIds)) return response()->json(['data' => []]);
            $q->where(function ($q) use ($childIds) {
                foreach ($childIds as $cid) $q->orWhereRaw("JSON_CONTAINS(child_ids, ?)", [(string) $cid]);
            });
        } else {
            $agencyId = (int) ($request->header('X-Active-Agency-Id')
                ?: DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('agency_id'));
            /* Same fix as the photo feed, and for the same reason: an educator at
               centre 18 was served every video belonging to centre 16. Scoped to the
               centres this person is actually assigned to; fails closed. */
            $centreIds = $this->visibleCentreIds($request);
            $q->whereIn('v.centre_id', $centreIds ?: [0]);
        }
        $rows = $q->get();
        // Photo permissions hold for video too: a family sees a video only when every child on it allows that.
        if (! $isStaff) $rows = \App\Support\MediaConsent::filter($rows, $childIds);
        $videoIds = $rows->pluck('id');
        $reactions = DB::table('reactions')
            ->where('target_type', 'video')->whereIn('target_id', $videoIds)
            ->select('target_id', 'reaction', DB::raw('COUNT(*) as c'))
            ->groupBy('target_id', 'reaction')->get()->groupBy('target_id');
        $rows->transform(function ($v) use ($reactions) {
            $v->reactions = $reactions->get($v->id, collect())->mapWithKeys(fn ($r) => [$r->reaction => (int) $r->c]);
            unset($v->child_ids);
            return $v;
        });
        return response()->json(['data' => $rows, 'can_upload' => $isStaff]);
    }

    public function uploadVideo(Request $request): JsonResponse
    {
        $u = $request->user();
        $request->validate([
            'video' => 'required|file|mimes:mp4,mov,webm|max:51200',
            'caption' => 'nullable|string|max:500',
            'child_ids' => 'nullable',
            'duration_seconds' => 'nullable|integer|min:1|max:600',
        ]);
        $hasUpload = \App\Support\AgencyRole::constrain(DB::table('role_assignments'))->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($hasUpload, 403);

        /* The video's centre must be one you work at, in this agency (2026-11-05 leak sweep): centre_id was taken
           from the request unchecked, and with none sent it fell back to the first centre on any of your roles
           (any agency) and then to centre 1, so videos were filed into other centres' and other agencies'
           feeds. With none sent it is now the first centre you may see here; with none of those, refused. */
        /* VIDEOS REACH FAMILIES THROUGH THEIR TAGS (2026-11-27). The upload used to send no children, so the
           parent feed (which shows a family only videos tagging one of its children) never showed a single video
           and nobody was told. A video now needs at least one tagged child, exactly like a photo, and ids are
           stored as integers: FormData sends "123", which JSON_CONTAINS in the parent feed would never match. */
        $childIds = $request->input('child_ids');
        if (is_string($childIds)) $childIds = json_decode($childIds, true);
        if (!is_array($childIds)) $childIds = [];
        $childIds = collect($childIds)->flatten()
            ->filter(fn ($v) => is_numeric($v) && (int) $v > 0)
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
        if (! $childIds) {
            return response()->json(['message' => 'Tag at least one child so their family sees the video.'], 422);
        }
        // SECURITY (v22p94): the uploader must have access to every tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($u, $cid), 403);
        }
        // PHOTO PERMISSIONS apply to video too: a child whose family said "do not photograph" cannot be tagged.
        if ($why = \App\Support\MediaConsent::untaggable($childIds)) {
            return response()->json(['message' => str_replace('share this photo', 'share this video', $why), 'reason' => 'no_photo_consent'], 422);
        }

        /* The video's centre must be one you work at, in this agency (2026-11-05 leak sweep): centre_id was taken
           from the request unchecked, and with none sent it fell back to the first centre on any of your roles
           (any agency) and then to centre 1, so videos were filed into other centres' and other agencies'
           feeds. With none sent it is the first tagged child's centre when you may see it, else the first centre
           you may see here; with none of those, refused. */
        $visibleCentres = $this->visibleCentreIds($request);
        $childCentre = (int) DB::table('children as c')->join('families as f', 'f.id', '=', 'c.family_id')
            ->where('c.id', $childIds[0])->value('f.centre_id');
        $fallback = in_array($childCentre, $visibleCentres, true) ? $childCentre : ($visibleCentres[0] ?? 0);
        $centreId = (int) ($request->input('centre_id') ?: $fallback);
        $agencyId = (int) $this->resolveAgencyId($request);
        if (! $centreId || ! $agencyId
            || (int) DB::table('centres')->where('id', $centreId)->value('agency_id') !== $agencyId
            || ! (in_array($centreId, $visibleCentres, true) || $this->authorizeCentreAccess($u, $centreId))) {
            return response()->json(['message' => 'Choose a centre you work at before sharing a video.'], 422);
        }

        $file = $request->file('video');
        $path = $file->storeAs('videos/' . now()->format('Y/m'),
            $u->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName()),
            'public');

        $id = DB::table('videos')->insertGetId([
            'centre_id' => $centreId,
            'uploaded_by_id' => $u->id,
            'url' => '/storage/' . $path,
            'thumbnail_url' => null,
            'duration_seconds' => $request->input('duration_seconds'),
            'caption' => $request->input('caption'),
            'child_ids' => json_encode($childIds),
            'taken_at' => now(),
            'file_size_bytes' => $file->getSize(),
            'created_at' => now(),
        ]);

        /* Tell the families allowed to see it, the same way a photo does: photo permissions decide which families
           (familiesWhoMaySee), the parent's "photos & videos" notice choice decides whether they are told, and
           Notify::write rings the phone. */
        $mayFam = \App\Support\MediaConsent::familiesWhoMaySee((object) ['child_ids' => json_encode($childIds), 'hidden_at' => null]);
        $seen = $mayFam ? array_merge(...array_values($mayFam)) : [];
        $keptFrom = count(array_diff($childIds, $seen));
        if ($mayFam) {
            $body = $request->input('caption') ?: 'A new moment is on the feed.';
            foreach (DB::table('guardians')->whereIn('family_id', array_keys($mayFam))->pluck('user_id')->unique() as $gid) {
                if (! \App\Support\ChildMomentNotify::wants((int) $gid, \App\Support\ChildMomentNotify::PREF_PHOTOS)) { continue; }
                \App\Support\Notify::write([
                    'user_id' => $gid, 'type' => 'video',
                    'title' => 'New video shared',
                    'body' => $body,
                    'data' => json_encode(['link' => '#videos', 'video_id' => $id]),
                    'created_at' => now(),
                ]);
            }
        }
        $notice = $keptFrom ? \App\Support\MediaConsent::keptFromNotice($childIds) : null;
        return response()->json(['id' => $id, 'url' => '/storage/' . $path, 'kept_from' => $keptFrom,
            'notice' => $notice ? str_replace('a photo', 'a video', $notice) : null], 201);
    }

    /**
     * SECURITY (v22p95): resolve a reaction target (photo/video/observation) to
     * its owning centre/child and confirm the caller may access it. Staff of the
     * item's centre, a guardian of any tagged child, or platform_admin pass.
     */
    private function canAccessReactionTarget($user, string $type, int $id): bool
    {
        if ($this->isPlatformAdminUser($user)) return true;

        if ($type === 'observation') {
            $row = DB::table('observations')->where('id', $id)->first();
            return $row ? $this->canAccessChildId($user, (int) $row->child_id) : false;
        }

        $table = $type === 'video' ? 'videos' : 'photos';
        $row = DB::table($table)->where('id', $id)->first();
        if (!$row) return false;
        if ($this->authorizeCentreAccess($user, (int) $row->centre_id)) return true;
        $childIds = $row->child_ids ? (json_decode($row->child_ids, true) ?: []) : [];
        foreach ($childIds as $cid) {
            if ($this->canAccessChildId($user, (int) $cid)) return true;
        }
        return false;
    }
}