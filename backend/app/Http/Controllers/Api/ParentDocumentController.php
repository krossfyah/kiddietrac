<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ResolvesCentreContext;
use App\Http\Controllers\Controller;
use App\Services\IncidentReportFiler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * The documents a family may see about their own children.
 *
 * ALLOWLIST, not a filter. `documents` scoped to a child holds whatever staff have
 * attached — custody paperwork, a note from a doctor, an internal assessment —
 * and none of that is automatically the family's to read. So this returns only
 * categories explicitly published to parents, and a new category is invisible to
 * families until somebody decides otherwise. A denylist would leak the next
 * category anyone adds.
 *
 * File URLs go out through SignProtectedMedia, which rewrites /storage paths in
 * JSON responses into signed, expiring links; `child-documents` is a protected
 * folder, so a report of a child's injury is never on a guessable public path.
 */
class ParentDocumentController extends Controller
{
    use ResolvesCentreContext;

    /** Categories a family is allowed to see. Add deliberately. */
    private const PARENT_VISIBLE = [
        IncidentReportFiler::CATEGORY,
    ];

    /** The caller's own children. The one source of "whose documents are these". */
    private function myChildIds($user): array
    {
        return DB::table('children as c')
            ->join('guardians as g', 'g.family_id', '=', 'c.family_id')
            ->where('g.user_id', $user->id)
            ->whereNull('c.deleted_at')
            ->pluck('c.id')->unique()->values()->all();
    }

    /** GET /parent/documents — everything published to this family, newest first. */
    public function index(Request $request): JsonResponse
    {
        $childIds = $this->myChildIds($request->user());

        $docs = DB::table('documents as d')
            ->join('children as c', 'c.id', '=', 'd.scope_id')
            ->where('d.scope_type', 'child')
            // No children on file must return NOTHING, never everything.
            ->whereIn('d.scope_id', $childIds ?: [0])
            ->whereIn('d.category', self::PARENT_VISIBLE)
            ->orderByDesc('d.created_at')
            ->get([
                'd.id', 'd.title', 'd.category', 'd.file_url', 'd.file_type',
                'd.file_size', 'd.created_at',
                'c.id as child_id', 'c.first_name', 'c.last_name',
            ]);

        return response()->json([
            'documents' => $docs->map(fn ($d) => [
                'id'         => $d->id,
                'title'      => $d->title,
                'category'   => $d->category,
                'file_url'   => $d->file_url,
                'file_type'  => $d->file_type,
                'file_size'  => $d->file_size,
                'created_at' => $d->created_at,
                'child'      => [
                    'id'         => $d->child_id,
                    'first_name' => $d->first_name,
                    'last_name'  => $d->last_name,
                ],
            ])->values(),
        ]);
    }

    /**
     * GET /parent/documents/{id}/download — stream it through the API.
     *
     * The signed /storage link works in a browser, but the mobile wrapper cannot
     * always follow one, and a download deserves its own authorization check
     * rather than relying on possession of a link.
     */
    public function download(Request $request, int $id)
    {
        $doc = DB::table('documents')->where('id', $id)->first();
        abort_if(! $doc, 404);

        // Their own child, and a category families are allowed to see. Both, always.
        abort_unless($doc->scope_type === 'child'
            && in_array((int) $doc->scope_id, $this->myChildIds($request->user()), true)
            && in_array($doc->category, self::PARENT_VISIBLE, true), 403);

        $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
        abort_unless($rel !== '' && Storage::disk('public')->exists($rel), 404);

        return response(Storage::disk('public')->get($rel), 200, [
            'Content-Type'        => $doc->file_type ?: 'application/pdf',
            'Content-Disposition' => 'inline; filename="'
                . preg_replace('/[^A-Za-z0-9 .()\-]+/', '', (string) $doc->title) . '.pdf"',
        ]);
    }
}
