<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Send a stored file to the browser (2026-10-30).
 *
 * The web PHP on Cloudways lists fpassthru in disable_functions, and Laravel's Storage::download() /
 * Storage::response() call fpassthru - so every private-file download built on them failed with
 * "Call to undefined function Illuminate\Filesystem\fpassthru()" since the move (e-documents, subsidy
 * documents, signed contracts, careers CVs, family files). This copies the stream in chunks with fread,
 * which is allowed. Use it for EVERY stored-file download; never Storage::download / ::response / fpassthru.
 */
final class FileResponse
{
    public static function download(string $path, ?string $name = null, array $headers = [], string $disk = 'local', bool $inline = false): StreamedResponse
    {
        $fs = Storage::disk($disk);
        $name = $name ?: basename($path);
        $fallback = preg_replace('/[^\x20-\x7E]|[%\/\\\\"]/', '_', $name) ?: 'file';
        $size = null;
        try { $size = $fs->size($path); } catch (\Throwable $e) {}
        $mime = $headers['Content-Type'] ?? null;
        if (! $mime) { try { $mime = $fs->mimeType($path) ?: null; } catch (\Throwable $e) {} }

        return new StreamedResponse(function () use ($fs, $path) {
            $s = $fs->readStream($path);
            if (! is_resource($s)) return;
            while (! feof($s)) {
                $chunk = fread($s, 1048576);
                if ($chunk === false) break;
                echo $chunk;
                flush();
            }
            fclose($s);
        }, 200, array_merge([
            'Content-Type' => $mime ?: 'application/octet-stream',
            'Content-Disposition' => HeaderUtils::makeDisposition($inline ? HeaderUtils::DISPOSITION_INLINE : HeaderUtils::DISPOSITION_ATTACHMENT, $name, $fallback),
            'Access-Control-Expose-Headers' => 'Content-Disposition',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], $size !== null ? ['Content-Length' => (string) $size] : [], $headers));
    }
}
