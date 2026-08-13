<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Knowledge base — the agency's own articles, written and searched by everyone
 * in it, whatever their role. Distinct from Help, which ships with the product
 * and is role-filtered from files on disk.
 *
 * Every query is scoped through resolveAgencyId(), never the raw
 * X-Active-Agency-Id header: a stale agency id left in one browser has already
 * caused both a wrongful 403 and, worse, forms silently filed to the wrong
 * agency. Anyone in the agency may read and write; only the author or someone
 * who manages the agency may edit or remove another person's article.
 */
class KnowledgeBaseController extends Controller
{
    use ResolvesCentreContext;

    private const MANAGER_ROLES = ['platform_admin', 'agency_admin', 'centre_director'];

    /** GET /kb — search + filter. Everyone in the agency sees the same list. */
    public function index(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $q = trim((string) $request->query('q', ''));
        $category = trim((string) $request->query('category', ''));

        $rows = DB::table('kb_articles as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.created_by_id')
            ->where('a.agency_id', $agencyId)
            ->whereNull('a.deleted_at')
            ->when($category !== '', fn ($qb) => $qb->where('a.category', $category))
            ->when($q !== '', function ($qb) use ($q) {
                // Search title, body and tags together: someone looking for "ratio"
                // should find the article whether the word is in its heading or
                // three paragraphs down.
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $q) . '%';
                $qb->where(function ($w) use ($like) {
                    $w->where('a.title', 'like', $like)
                      ->orWhere('a.body', 'like', $like)
                      ->orWhere('a.tags', 'like', $like);
                });
            })
            ->orderByDesc('a.updated_at')
            ->limit(300)
            ->get([
                'a.id', 'a.title', 'a.category', 'a.tags', 'a.views',
                'a.created_by_id', 'a.created_at', 'a.updated_at',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as author_name"),
                DB::raw('SUBSTRING(a.body, 1, 240) as excerpt'),
            ]);

        $categories = DB::table('kb_articles')
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereNotNull('category')->where('category', '!=', '')
            ->distinct()->orderBy('category')->pluck('category');

        return response()->json([
            'data' => $rows,
            'categories' => $categories,
            'total' => $rows->count(),
        ]);
    }

    /** GET /kb/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $a = DB::table('kb_articles as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.created_by_id')
            ->where('a.id', $id)->where('a.agency_id', $agencyId)->whereNull('a.deleted_at')
            ->first([
                'a.*',
                DB::raw("TRIM(CONCAT(COALESCE(u.first_name,''),' ',COALESCE(u.last_name,''))) as author_name"),
                'u.photo_url as author_photo_url',
            ]);
        if (! $a) return response()->json(['message' => 'Article not found'], 404);

        DB::table('kb_articles')->where('id', $id)->increment('views');
        $a->views = (int) $a->views + 1;
        $a->can_edit = $this->canManage($request, (int) ($a->created_by_id ?? 0), $agencyId);

        return response()->json(['article' => $a]);
    }

    /** POST /kb — any signed-in member of the agency may contribute. */
    public function store(Request $request): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $data = $request->validate([
            'title'    => ['required', 'string', 'min:3', 'max:200'],
            'body'     => ['required', 'string', 'min:10', 'max:20000'],
            'category' => ['nullable', 'string', 'max:60'],
            'tags'     => ['nullable', 'string', 'max:200'],
        ]);

        $now = now();
        $id = DB::table('kb_articles')->insertGetId([
            'agency_id'     => $agencyId,
            'title'         => trim($data['title']),
            'body'          => trim($data['body']),
            'category'      => isset($data['category']) ? (trim($data['category']) ?: null) : null,
            'tags'          => isset($data['tags']) ? (trim($data['tags']) ?: null) : null,
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);

        return response()->json(['ok' => true, 'id' => $id], 201);
    }

    /** PUT /kb/{id} — the author, or anyone who manages the agency. */
    public function update(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $a = DB::table('kb_articles')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Article not found'], 404);
        if (! $this->canManage($request, (int) $a->created_by_id, $agencyId)) {
            return response()->json(['message' => 'You can only edit your own articles.'], 403);
        }

        $data = $request->validate([
            'title'    => ['required', 'string', 'min:3', 'max:200'],
            'body'     => ['required', 'string', 'min:10', 'max:20000'],
            'category' => ['nullable', 'string', 'max:60'],
            'tags'     => ['nullable', 'string', 'max:200'],
        ]);

        DB::table('kb_articles')->where('id', $id)->update([
            'title'         => trim($data['title']),
            'body'          => trim($data['body']),
            'category'      => isset($data['category']) ? (trim($data['category']) ?: null) : null,
            'tags'          => isset($data['tags']) ? (trim($data['tags']) ?: null) : null,
            'updated_by_id' => $request->user()->id,
            'updated_at'    => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    /** DELETE /kb/{id} — soft delete, same permission as editing. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $agencyId = $this->resolveAgencyId($request);
        if (! $agencyId) return response()->json(['message' => 'No agency access'], 403);

        $a = DB::table('kb_articles')->where('id', $id)->where('agency_id', $agencyId)->whereNull('deleted_at')->first();
        if (! $a) return response()->json(['message' => 'Article not found'], 404);
        if (! $this->canManage($request, (int) $a->created_by_id, $agencyId)) {
            return response()->json(['message' => 'You can only remove your own articles.'], 403);
        }

        DB::table('kb_articles')->where('id', $id)->update(['deleted_at' => now()]);
        return response()->json(['ok' => true]);
    }

    /** The author always; otherwise someone holding a managing role in THIS agency. */
    private function canManage(Request $request, int $authorId, int $agencyId): bool
    {
        $user = $request->user();
        if (! $user) return false;
        if ($authorId && (int) $user->id === (int) $authorId) return true;

        return DB::table('role_assignments')
            ->where('user_id', $user->id)->where('active', true)
            ->whereIn('role', self::MANAGER_ROLES)
            ->where(function ($q) use ($agencyId) {
                // A platform admin manages any agency; everyone else only their own.
                $q->where('role', 'platform_admin')->orWhere('agency_id', $agencyId);
            })
            ->exists();
    }
}
