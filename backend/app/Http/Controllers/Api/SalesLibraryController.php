<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProtectedMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The sales reference library — superadmin only.
 *
 * Price sheets, comparison decks, contract templates: KiddieTrac's own material, not any
 * agency's. See the migration for why it is not in `documents`.
 *
 * GUARDED TWICE, ON PURPOSE. The route group carries role:platform_admin, and every
 * method asks again. A route guard is a statement about where a thing is mounted; it
 * says nothing once somebody mounts the controller somewhere else, and this one holds
 * commercial material for a whole platform. The cost of asking twice is a query.
 */
class SalesLibraryController extends Controller
{
    /** Everything under here is served through MediaFileController, never linked raw. */
    private const FOLDER = 'sales-library';

    private const MAX_MB = 25;

    /**
     * Superadmin, checked against the role table rather than a cached claim.
     *
     * `kt_user.roles` in the browser is a snapshot that is never refreshed, so a role
     * removed this morning is still in some sessions this afternoon. Anything that
     * decides access reads the live row.
     */
    private function assertSuperAdmin(Request $request): void
    {
        $ok = DB::table('role_assignments')
            ->where('user_id', (int) $request->user()->id)
            ->where('role', 'platform_admin')
            ->where('active', 1)
            ->exists();

        abort_unless($ok, 403, 'The sales library is for superadmins.');
    }

    /** GET /sales/library */
    public function index(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $rows = DB::table('sales_documents as d')
            ->leftJoin('users as u', 'u.id', '=', 'd.uploaded_by_id')
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.id')
            ->get([
                'd.id', 'd.title', 'd.notes', 'd.category', 'd.file_url',
                'd.file_type', 'd.file_size', 'd.created_at',
                'u.first_name as up_first', 'u.last_name as up_last',
            ]);

        return response()->json([
            'documents' => $rows->map(fn ($d) => [
                'id' => (int) $d->id,
                'title' => $d->title,
                'notes' => $d->notes,
                'category' => $d->category,
                'file_type' => $d->file_type,
                'file_size' => (int) $d->file_size,
                'uploaded_at' => $d->created_at,
                'uploaded_by' => trim(($d->up_first ?? '') . ' ' . ($d->up_last ?? '')) ?: null,
                /* Signed, bearer-free and short-lived — the same mechanism every other
                   protected file in the portal uses, and the only way the packaged app
                   can hand a document to the real browser. */
                'open_url' => ProtectedMedia::sign($d->file_url),
            ])->values()->all(),
            'categories' => $rows->pluck('category')->filter()->unique()->sort()->values()->all(),
        ]);
    }

    /** POST /sales/library */
    public function store(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:' . (self::MAX_MB * 1024)],
            'title' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'category' => ['nullable', 'string', 'max:60'],
        ]);

        $file = $request->file('file');

        /* The extension is taken from the CLIENT name, so it is user input: a document
           called `x.php` must not land under a folder the web server would execute.
           The folder is denied by .htaccess as well — this is the second lock. */
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension()));
        if (! preg_match('/^[a-z0-9]{1,8}$/', $ext) || in_array($ext, ['php', 'phtml', 'phar', 'htaccess', 'sh', 'cgi'], true)) {
            return response()->json(['message' => 'That file type cannot be stored here.'], 422);
        }

        $name = (string) Str::uuid() . '.' . $ext;
        $file->storeAs(self::FOLDER, $name, 'public');
        $publicPath = '/storage/' . self::FOLDER . '/' . $name;

        $title = trim((string) ($data['title'] ?? '')) ?: ($file->getClientOriginalName() ?: 'Reference document');

        $id = DB::table('sales_documents')->insertGetId([
            'title' => mb_substr($title, 0, 200),
            'notes' => ($n = trim((string) ($data['notes'] ?? ''))) !== '' ? $n : null,
            'category' => ($c = trim((string) ($data['category'] ?? ''))) !== '' ? mb_substr($c, 0, 60) : null,
            'file_url' => $publicPath,
            'file_type' => $file->getClientMimeType() ?: 'application/octet-stream',
            'file_size' => $file->getSize(),
            'uploaded_by_id' => (int) $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'sales.library_uploaded', $id, [
            'title' => $title,
            'category' => $data['category'] ?? null,
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
        ]);

        return response()->json(['id' => $id, 'message' => 'Document added to the library.']);
    }

    /** GET /sales/library/{doc}/download */
    public function download(Request $request, int $docId)
    {
        $this->assertSuperAdmin($request);

        $doc = DB::table('sales_documents')->where('id', $docId)->whereNull('deleted_at')
            ->first(['file_url', 'title', 'file_type']);
        abort_unless($doc, 404);

        $rel = ltrim(str_replace('/storage/', '', (string) $doc->file_url), '/');
        $disk = Storage::disk('public');
        abort_unless($disk->exists($rel), 404);

        $this->audit($request, 'sales.library_downloaded', $docId, ['title' => $doc->title]);

        return response()->file($disk->path($rel));
    }

    /** DELETE /sales/library/{doc} */
    public function destroy(Request $request, int $docId): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $doc = DB::table('sales_documents')->where('id', $docId)->whereNull('deleted_at')
            ->first(['id', 'title']);
        abort_unless($doc, 404);

        /* Soft delete. The file stays on disk: somebody removing a price sheet in a tidy-up
           is not the same as wanting it destroyed, and the row is what the list reads. */
        DB::table('sales_documents')->where('id', $docId)->update([
            'deleted_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit($request, 'sales.library_removed', $docId, ['title' => $doc->title]);

        return response()->json(['ok' => true, 'message' => 'Removed from the library.']);
    }

    /**
     * Name what was touched, not a count — a log saying "1 document removed" cannot
     * answer the only question anyone asks of it later.
     */
    private function audit(Request $request, string $action, int $id, array $payload): void
    {
        try {
            \App\Support\Audit::write([
                'user_id' => (int) $request->user()->id,
                'agency_id' => null,          // platform-level: it belongs to no agency
                'action' => $action,
                'entity_type' => 'sales_document',
                'entity_id' => $id,
                'payload' => json_encode($payload),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Sales library audit failed', ['action' => $action, 'id' => $id, 'e' => $e->getMessage()]);
        }
    }
}
