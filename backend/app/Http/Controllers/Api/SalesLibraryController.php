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
            ->leftJoin('sales_folders as f', 'f.id', '=', 'd.folder_id')
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.id')
            ->get([
                'd.id', 'd.title', 'd.file_name', 'd.notes', 'd.category', 'd.file_url',
                'd.file_type', 'd.file_size', 'd.created_at', 'd.folder_id',
                'f.name as folder_name',
                'u.first_name as up_first', 'u.last_name as up_last',
            ]);

        return response()->json([
            'folders' => DB::table('sales_folders')->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($f) => [
                    'id' => (int) $f->id,
                    'name' => $f->name,
                    'count' => (int) DB::table('sales_documents')
                        ->where('folder_id', $f->id)->whereNull('deleted_at')->count(),
                ])->values()->all(),
            'documents' => $rows->map(fn ($d) => [
                'id' => (int) $d->id,
                'title' => $d->title,
                /* THE NAME WITH ITS EXTENSION.
                   `title` is what somebody typed and usually says nothing about the
                   format. `file_name` holds the uploaded name — but only for rows filed
                   since that column existed, and falling back to the bare title gave
                   "KiddieTrac Presentation 09232026" with no .pptx anywhere on the row.

                   THE STORED PATH IS THE ONE SOURCE THAT IS ALWAYS RIGHT: store() writes
                   the file as `<uuid>.<ext>`, so every row that has a file has its real
                   extension in file_url whatever else is missing. Derive from there and
                   an eight-year-old row displays correctly without touching the data. */
                'file_name' => self::displayName($d->file_name, $d->title, $d->file_url),
                'extension' => self::extensionOf($d->file_url) ?: self::extensionOf($d->file_name ?: $d->title),
                'kind' => self::kindOf($d->file_url ?: ($d->file_name ?: $d->title), $d->file_type),
                'folder_id' => $d->folder_id ? (int) $d->folder_id : null,
                'folder' => $d->folder_name,
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
            'folder_id' => ['nullable', 'integer'],
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

        /* A folder id from the client is user input: check it exists before trusting
           it, or a typo files the document into a folder nobody can open. */
        $folderId = null;
        if (! empty($data['folder_id'])) {
            $folderId = DB::table('sales_folders')->where('id', (int) $data['folder_id'])->value('id');
            $folderId = $folderId ? (int) $folderId : null;
        }

        $id = DB::table('sales_documents')->insertGetId([
            'title' => mb_substr($title, 0, 200),
            'file_name' => mb_substr((string) ($file->getClientOriginalName() ?: $name), 0, 255),
            'folder_id' => $folderId,
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
     * PATCH /sales/library/{doc} — move a file into a folder, or out of one.
     *
     * `folder_id` null means the root. Sent explicitly rather than inferred from its
     * absence, so "move to All files" is a thing the caller asks for rather than
     * something that happens when a field is forgotten.
     */
    public function move(Request $request, int $docId): JsonResponse
    {
        $this->assertSuperAdmin($request);

        $data = $request->validate(['folder_id' => ['present', 'nullable', 'integer']]);

        $doc = DB::table('sales_documents')->where('id', $docId)->whereNull('deleted_at')
            ->first(['id', 'title', 'file_name', 'folder_id']);
        abort_unless($doc, 404);

        $folderId = null;
        $folderName = null;
        if (! empty($data['folder_id'])) {
            $folder = DB::table('sales_folders')->where('id', (int) $data['folder_id'])->first(['id', 'name']);
            // A folder id from the client is user input; a stale one must not file the
            // document somewhere nobody can open.
            abort_unless($folder, 422, 'That folder no longer exists.');
            $folderId = (int) $folder->id;
            $folderName = $folder->name;
        }

        DB::table('sales_documents')->where('id', $docId)
            ->update(['folder_id' => $folderId, 'updated_at' => now()]);

        $this->audit($request, 'sales.library_moved', $docId, [
            'file' => $doc->file_name ?: $doc->title,
            'from_folder_id' => $doc->folder_id ? (int) $doc->folder_id : null,
            'to_folder_id' => $folderId,
            'to_folder' => $folderName,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $folderName ? ('Moved to ' . $folderName . '.') : 'Moved to All files.',
        ]);
    }

    /** POST /sales/library/folders */
    public function folderStore(Request $request): JsonResponse
    {
        $this->assertSuperAdmin($request);
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);
        $name = trim($data['name']);
        if ($name === '') {
            return response()->json(['message' => 'Give the folder a name.'], 422);
        }

        /* Case-insensitively unique, so "Pricing" and "pricing" are the same shelf.
           The DB unique index is the real guard; this is what turns a duplicate into a
           sentence instead of a 500. */
        $existing = DB::table('sales_folders')->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first(['id']);
        if ($existing) {
            return response()->json(['id' => (int) $existing->id, 'message' => 'That folder already exists.']);
        }

        $id = DB::table('sales_folders')->insertGetId([
            'name' => $name,
            'created_by_id' => (int) $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit($request, 'sales.library_folder_created', $id, ['name' => $name]);

        return response()->json(['id' => $id, 'message' => 'Folder created.']);
    }

    /** DELETE /sales/library/folders/{folder} */
    public function folderDestroy(Request $request, int $folderId): JsonResponse
    {
        $this->assertSuperAdmin($request);
        $folder = DB::table('sales_folders')->where('id', $folderId)->first(['id', 'name']);
        abort_unless($folder, 404);

        /* THE FILES SURVIVE. Deleting a folder is tidying the shelf, not throwing away
           what was on it — everything inside moves back to the root, where it is still
           findable. Destroying somebody's contract templates because they renamed a
           folder is not a recoverable mistake. */
        $moved = DB::table('sales_documents')->where('folder_id', $folderId)
            ->update(['folder_id' => null, 'updated_at' => now()]);
        DB::table('sales_folders')->where('id', $folderId)->delete();

        $this->audit($request, 'sales.library_folder_deleted', $folderId, [
            'name' => $folder->name,
            'files_moved_to_root' => $moved,
        ]);

        return response()->json([
            'ok' => true,
            'message' => $moved
                ? ('Folder deleted. ' . $moved . ' file' . ($moved === 1 ? '' : 's') . ' moved to All files.')
                : 'Folder deleted.',
        ]);
    }

    /**
     * The name to show: the uploaded one, else the typed title with the real extension
     * put back on it. Never invents an extension that is not in the stored path.
     */
    private static function displayName(?string $fileName, ?string $title, ?string $fileUrl): string
    {
        $name = trim((string) $fileName);
        if ($name !== '') {
            return $name;
        }
        $t = trim((string) $title) ?: 'Document';
        $ext = self::extensionOf($fileUrl);
        if (! $ext) {
            return $t;
        }
        // Do not end up with "deck.pptx.pptx" when the title already carries it.
        return mb_strtolower(self::extensionOf($t) ?: '') === $ext ? $t : ($t . '.' . $ext);
    }

    /** The bit after the last dot, when it looks like an extension at all. */
    private static function extensionOf(?string $name): ?string
    {
        $n = (string) $name;
        if (! str_contains($n, '.')) {
            return null;
        }
        $ext = mb_strtolower(trim(substr($n, strrpos($n, '.') + 1)));

        return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : null;
    }

    /** A word a person would use for the format, not a MIME string. */
    private static function kindOf(?string $name, ?string $mime): string
    {
        $ext = self::extensionOf($name) ?: '';
        $m = mb_strtolower((string) $mime);
        $map = [
            'pdf' => 'PDF',
            'doc' => 'Word', 'docx' => 'Word', 'rtf' => 'Word',
            'xls' => 'Spreadsheet', 'xlsx' => 'Spreadsheet', 'csv' => 'Spreadsheet',
            'ppt' => 'Presentation', 'pptx' => 'Presentation', 'key' => 'Presentation',
            'jpg' => 'Image', 'jpeg' => 'Image', 'png' => 'Image', 'gif' => 'Image',
            'webp' => 'Image', 'heic' => 'Image', 'svg' => 'Image',
            'zip' => 'Archive', 'rar' => 'Archive', '7z' => 'Archive',
            'txt' => 'Text', 'md' => 'Text',
            'mp4' => 'Video', 'mov' => 'Video', 'webm' => 'Video',
        ];
        if (isset($map[$ext])) {
            return $map[$ext];
        }
        if (str_contains($m, 'pdf')) { return 'PDF'; }
        if (str_contains($m, 'image')) { return 'Image'; }
        if (str_contains($m, 'video')) { return 'Video'; }
        if (str_contains($m, 'sheet') || str_contains($m, 'excel')) { return 'Spreadsheet'; }
        if (str_contains($m, 'presentation') || str_contains($m, 'powerpoint')) { return 'Presentation'; }
        if (str_contains($m, 'word')) { return 'Word'; }

        return $ext !== '' ? mb_strtoupper($ext) : 'File';
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
