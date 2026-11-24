<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * The files attached to a sent email, kept beside its email_logs row (2026-10-30, Anthony: "with email logs when
 * you click on it show any attachments as well and allow to be clickable to view or download").
 *
 * Saved on the PRIVATE disk (storage/app/private/email-attachments/{log id}/...), served only through the
 * platform-admin email-log route. Inline images (logos, cid: pictures inside the HTML) are part of the design,
 * not attachments, and are skipped. A file over 20 MB is listed but not kept. Never throws: logging must never
 * get in the way of sending.
 */
final class EmailLogAttachments
{
    public const MAX_BYTES = 20 * 1024 * 1024;

    public static function store(int $logId, $msg): void
    {
        try {
            if ($logId <= 0 || ! is_object($msg) || ! method_exists($msg, 'getAttachments') || ! Schema::hasTable('email_log_attachments')) return;
            if (DB::table('email_log_attachments')->where('email_log_id', $logId)->exists()) return;   // already recorded
            $n = 0;
            foreach ($msg->getAttachments() as $part) {
                $disp = method_exists($part, 'getDisposition') ? $part->getDisposition() : 'attachment';
                if ($disp === 'inline') continue;
                $n++;
                $name = method_exists($part, 'getFilename') ? (string) $part->getFilename() : '';
                $name = trim(preg_replace('/[\x00-\x1F\/\\\\]+/u', ' ', $name)) ?: ('attachment-' . $n);
                $mime = method_exists($part, 'getMediaType') ? ($part->getMediaType() . '/' . $part->getMediaSubtype()) : 'application/octet-stream';
                $body = (string) $part->getBody();
                $size = strlen($body);
                $path = null;
                if ($size > 0 && $size <= self::MAX_BYTES) {
                    $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);
                    $path = 'email-attachments/' . $logId . '/' . $n . '-' . substr($safe, -120);
                    if (! Storage::disk('local')->put($path, $body)) $path = null;
                }
                DB::table('email_log_attachments')->insert([
                    'email_log_id' => $logId, 'filename' => mb_substr($name, 0, 255), 'mime' => mb_substr($mime, 0, 120),
                    'size' => $size, 'path' => $path, 'created_at' => now()->format(Audit::TS),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('email attachment capture failed', ['log' => $logId, 'error' => $e->getMessage()]);
        }
    }

    /** The attachments of one email, for the preview. */
    public static function forLog(int $logId): array
    {
        if (! Schema::hasTable('email_log_attachments')) return [];
        return DB::table('email_log_attachments')->where('email_log_id', $logId)->orderBy('id')
            ->get(['id', 'filename', 'mime', 'size', 'path'])
            ->map(fn ($a) => ['id' => (int) $a->id, 'filename' => $a->filename, 'mime' => $a->mime, 'size' => (int) $a->size, 'kept' => (bool) $a->path])
            ->all();
    }
}
