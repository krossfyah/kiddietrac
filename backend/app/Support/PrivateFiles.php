<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * FILES THAT NEVER TOUCH THE PUBLIC DISK (2026-11-28).
 *
 * Background-check scans (police / vulnerable-sector checks) and scanned business cards were
 * written to the `public` disk, whose root is public/storage INSIDE the web root. They were only
 * kept off the open web by a symlink trick (ProtectedMedia::home) and were then handed out as a
 * twelve-hour bearer link (/media/f) that named a path and nothing else: no person, no agency,
 * and any path the server would sign. Worse, both screens took the file "URL" back from the
 * client, so an admin could save `/storage/child-documents/<another agency's file>` as a
 * background-check document and the list would sign it on the way out.
 *
 * Now:
 *   - the file lives on the PRIVATE disk (`local`, storage/app/private, outside the web root),
 *     under <folder>/<agency id>/...; the row stores `private:<path>`, never a URL, and a stored
 *     reference is only ever honoured inside its own kind's folder for the row's own agency;
 *   - the screen asks an AUTHENTICATED, agency-scoped endpoint for the file (the same scope as the
 *     list: active agency, CentreScope for directors), which mints a link valid TTL_MINUTES;
 *   - that link is bound to the person and the agency it was minted for, and the door
 *     (PrivateFileController, GET /media/p/{kind}/{id}) re-checks BOTH against the record when it is
 *     opened: a person who left the agency, or a record that moved, gets 404 even inside the TTL.
 *
 * A signature at all, rather than the bearer token, because these open in <img>, a new tab and the
 * house document viewer, none of which can send a header.
 */
final class PrivateFiles
{
    public const DISK = 'local';

    public const PREFIX = 'private:';

    /** Short: the link is minted on the click that opens it. */
    public const TTL_MINUTES = 10;

    /** kind (in the URL) => folder on the private disk. */
    public const KINDS = [
        'bgc' => 'background-checks',
        'card' => 'contact-cards',
    ];

    private const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public static function disk()
    {
        return Storage::disk(self::DISK);
    }

    /** File an upload under <folder>/<agency>/<sub>/ and return the reference to store on the row. */
    public static function put(UploadedFile $file, string $kind, int $agencyId, string $sub = ''): string
    {
        $folder = self::KINDS[$kind] ?? null;
        abort_unless($folder && $agencyId > 0, 500, 'That file could not be saved.');
        $ext = strtolower((string) ($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin'));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'bin';
        $dir = $folder . '/' . $agencyId . ($sub !== '' ? '/' . trim(preg_replace('#[^A-Za-z0-9/_-]#', '', $sub), '/') : '');
        $path = self::disk()->putFileAs($dir, $file, Str::random(40) . '.' . $ext);
        abort_unless(is_string($path) && $path !== '', 500, 'That file could not be saved.');

        return self::PREFIX . $path;
    }

    /** Is this a stored private reference (of any kind)? */
    public static function isRef(?string $value): bool
    {
        return str_starts_with((string) $value, self::PREFIX);
    }

    /**
     * The private-disk path a stored reference stands for - only inside this kind's folder for this
     * agency, so a value that was tampered with (or copied from another agency's row) is never served.
     */
    public static function path(?string $ref, string $kind, int $agencyId): ?string
    {
        $r = (string) $ref;
        $folder = self::KINDS[$kind] ?? null;
        if (! $folder || $agencyId <= 0 || ! str_starts_with($r, self::PREFIX)) {
            return null;
        }
        $p = substr($r, strlen(self::PREFIX));
        if ($p === '' || str_contains($p, '..') || str_contains($p, "\0") || str_contains($p, '\\') || str_starts_with($p, '/')) {
            return null;
        }

        return str_starts_with($p, $folder . '/' . $agencyId . '/') ? $p : null;
    }

    /** A short-lived link to one record's file, bound to the person and agency it is minted for. */
    public static function link(string $kind, int $id, int $agencyId, int $userId, ?string $downloadName = null): string
    {
        $params = ['kind' => $kind, 'id' => $id, 'a' => $agencyId, 'u' => $userId];
        $n = ProtectedMedia::safeName($downloadName);
        if ($n !== null) {
            $params['n'] = $n;
        }

        return URL::temporarySignedRoute('media.private', now()->addMinutes(self::TTL_MINUTES), $params);
    }

    public static function mime(string $path): string
    {
        try {
            return (string) (self::disk()->mimeType($path) ?: 'application/octet-stream');
        } catch (\Throwable $e) {
            return 'application/octet-stream';
        }
    }

    public static function previewable(string $mime): bool
    {
        return in_array($mime, self::INLINE, true);
    }

    /** What an "open" endpoint answers: the link plus what the house document viewer needs. */
    public static function openAnswer(string $kind, int $id, int $agencyId, int $userId, string $path, string $name): array
    {
        $mime = self::mime($path);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $file = ProtectedMedia::safeName($name . ($ext ? '.' . $ext : '')) ?? ('file.' . ($ext ?: 'bin'));

        return [
            'url' => self::link($kind, $id, $agencyId, $userId, $file),
            'name' => $file,
            'mime' => $mime,
            'previewable' => self::previewable($mime),
            'expires_in' => self::TTL_MINUTES * 60,
        ];
    }

    /**
     * The person a door link was minted for, standing in a request of their own: the agency header the
     * link carries and that user, so the controllers' own scope checks (AgencyRole, CentreScope,
     * resolveAgencyId) run exactly as they did when the link was minted. Null when the account is gone
     * or switched off.
     */
    public static function actingRequest(int $userId, int $agencyId): ?Request
    {
        if ($userId <= 0 || $agencyId <= 0) {
            return null;
        }
        $user = \App\Models\User::query()->whereKey($userId)->whereNull('deleted_at')->first();
        if (! $user || in_array((string) $user->status, Audience::OFF_STATUSES, true)) {
            return null;
        }
        if (! DB::table('agencies')->where('id', $agencyId)->whereNull('deleted_at')->exists()) {
            return null;
        }
        $r = Request::create('/', 'GET');
        $r->headers->set('X-Active-Agency-Id', (string) $agencyId);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    /** Stream a private file: never cached by a shared cache, never sniffed, PDFs and images inline only. */
    public static function stream(string $path, ?string $name = null): Response
    {
        $disk = self::disk();
        abort_unless($disk->exists($path), 404);
        $mime = self::mime($path);
        $inline = self::previewable($mime);
        $file = ProtectedMedia::safeName($name) ?? basename($path);

        $headers = [
            'Content-Type' => $inline ? $mime : 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $file) . '"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ];
        // Not on a PDF: a CSP on the document can stop the browser's own PDF viewer from drawing it.
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'";
        }

        return response()->file($disk->path($path), $headers);
    }

    /** Remove a stored file (a replaced document). Only ever inside its own folder and agency. */
    public static function delete(?string $ref, string $kind, int $agencyId): void
    {
        $p = self::path($ref, $kind, $agencyId);
        if ($p !== null) {
            try { self::disk()->delete($p); } catch (\Throwable $e) { report($e); }
        }
    }
}
