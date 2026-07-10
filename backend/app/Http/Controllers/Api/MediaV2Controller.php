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
        $isStaff = DB::table('role_assignments')->where('user_id', $u->id)
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
            $centreIds = DB::table('centres')->where('agency_id', $agencyId)->pluck('id');
            $q->whereIn('v.centre_id', $centreIds);
        }
        $rows = $q->get();
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
        return response()->json(['data' => $rows]);
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
        $hasUpload = DB::table('role_assignments')->where('user_id', $u->id)
            ->whereIn('role', ['agency_admin', 'centre_director', 'educator', 'platform_admin'])
            ->where('active', 1)->exists();
        abort_unless($hasUpload, 403);

        $file = $request->file('video');
        $path = $file->storeAs('videos/' . now()->format('Y/m'),
            $u->id . '-' . time() . '-' . preg_replace('/[^A-Za-z0-9._-]/', '', $file->getClientOriginalName()),
            'public');

        $centreId = (int) ($request->input('centre_id')
            ?: DB::table('role_assignments')->where('user_id', $u->id)->where('active', 1)->value('centre_id') ?: 1);

        $childIds = $request->input('child_ids');
        if (is_string($childIds)) $childIds = json_decode($childIds, true);
        if (!is_array($childIds)) $childIds = [];
        // SECURITY (v22p94): the uploader must have access to every tagged child.
        foreach ($childIds as $cid) {
            abort_unless($this->canAccessChildId($u, (int) $cid), 403);
        }

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

        if (!empty($childIds)) {
            $familyIds = DB::table('children')->whereIn('id', $childIds)->pluck('family_id')->unique();
            $gids = DB::table('guardians')->whereIn('family_id', $familyIds)->pluck('user_id');
            foreach ($gids as $gid) {
                DB::table('notifications')->insert([
                    'user_id' => $gid, 'type' => 'video',
                    'title' => 'New video shared',
                    'body' => $request->input('caption') ?: 'A new moment is on the feed.',
                    'data' => json_encode(['link' => '#videos', 'video_id' => $id]),
                    'created_at' => now(),
                ]);
            }
        }
        return response()->json(['id' => $id, 'url' => '/storage/' . $path], 201);
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