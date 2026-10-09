<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SalesActivity;
use App\Support\ProtectedMedia;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * TRANSCRIPTS AND RECORDINGS ON A LEAD'S ACTIVITY (2026-10-09).
 *
 * Anthony: "add ability to upload transcript from MS teams meetings and video to activity
 * and history". Teams recordings run to hundreds of megabytes and this host takes 64 MB a
 * request, so files arrive in CHUNKS (raw bodies, ~8 MB each) and are assembled here.
 * Transcripts (.vtt / .srt / .txt / .docx) are read into plain text, speaker by speaker.
 *
 * Stored on the public disk under sales-activity/, which an .htaccess denies: like every
 * protected file in the portal it is served only through the signed /media/f route
 * (ProtectedMedia), which also answers Range requests, so a recording plays and seeks.
 *
 * Who: the same people who may edit the entry (its author, or a platform admin).
 */
class SalesActivityFileController extends Controller
{
    private const FOLDER = 'sales-activity';
    private const MAX_BYTES = 2 * 1024 * 1024 * 1024;   // 2 GB
    private const MAX_CHUNKS = 400;
    private const KINDS = [
        'transcript' => ['vtt', 'srt', 'txt', 'docx'],
        'video' => ['mp4', 'mov', 'm4v', 'webm', 'mkv'],
        'audio' => ['m4a', 'mp3', 'wav', 'aac', 'ogg'],
        'file' => ['pdf', 'doc', 'pptx', 'xlsx', 'csv', 'png', 'jpg', 'jpeg'],
    ];

    /** POST /sales/activities/{activity}/upload?upload_id=&index=&total=&name=&size= (raw body = one chunk) */
    public function upload(Request $request, int $activity): JsonResponse
    {
        $a = SalesActivity::findOrFail($activity);
        if (! SalesController::canEditActivity($a)) {
            return response()->json(['message' => 'Only the person who wrote this entry, or a platform admin, can add files to it.'], 403);
        }
        $uploadId = (string) $request->query('upload_id', '');
        $index = (int) $request->query('index', -1);
        $total = (int) $request->query('total', 0);
        $name = trim((string) $request->query('name', ''));
        $size = (int) $request->query('size', 0);
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $uploadId) || $index < 0 || $total < 1 || $total > self::MAX_CHUNKS || $index >= $total || $name === '') {
            return response()->json(['message' => 'Bad upload request.'], 422);
        }
        if ($size > self::MAX_BYTES) {
            return response()->json(['message' => 'That file is larger than 2 GB.'], 422);
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $kind = self::kindFor($ext);
        if (! $kind) {
            return response()->json(['message' => 'That file type is not accepted here. Transcripts: .vtt .docx .txt .srt; recordings: .mp4 .mov .webm .m4a .mp3.'], 422);
        }

        $tmp = storage_path('app/sales-uploads/' . $a->id . '-' . $uploadId);
        if (! is_dir($tmp) && ! @mkdir($tmp, 0750, true) && ! is_dir($tmp)) {
            return response()->json(['message' => 'Could not prepare the upload.'], 500);
        }
        // The chunk is the raw request body; stream it to disk rather than into memory.
        $in = fopen('php://input', 'rb');
        $out = fopen($tmp . '/' . $index . '.part', 'wb');
        $bytes = stream_copy_to_stream($in, $out);
        fclose($in); fclose($out);
        if (! $bytes) {
            return response()->json(['message' => 'An empty chunk arrived; please try again.'], 422);
        }

        // Not the last piece yet: say how many have arrived.
        $have = count(glob($tmp . '/*.part') ?: []);
        if ($have < $total) {
            return response()->json(['ok' => true, 'received' => $have, 'total' => $total]);
        }

        // ── All pieces are here: assemble, check, store ──────────────────────
        $stored = (string) Str::uuid() . '.' . $ext;
        $relDir = self::FOLDER . '/' . $a->lead_id;
        $disk = Storage::disk('public');
        $disk->makeDirectory($relDir);
        self::protectFolder($disk->path(self::FOLDER));
        $final = $disk->path($relDir . '/' . $stored);
        $dst = fopen($final, 'wb');
        $written = 0;
        for ($i = 0; $i < $total; $i++) {
            $part = $tmp . '/' . $i . '.part';
            if (! is_file($part)) { fclose($dst); @unlink($final); return response()->json(['message' => 'A piece of the file went missing; please upload it again.'], 422); }
            $src = fopen($part, 'rb');
            $written += stream_copy_to_stream($src, $dst);
            fclose($src);
        }
        fclose($dst);
        foreach (glob($tmp . '/*.part') ?: [] as $p) { @unlink($p); }
        @rmdir($tmp);
        self::sweepAbandoned();
        if ($size && $written !== $size) {
            @unlink($final);
            return response()->json(['message' => 'The file did not arrive complete (' . $written . ' of ' . $size . ' bytes). Please upload it again.'], 422);
        }

        $text = $kind === 'transcript' ? self::transcriptText($final, $ext) : null;
        $mime = @mime_content_type($final) ?: null;
        $id = DB::table('sales_activity_files')->insertGetId([
            'activity_id' => $a->id, 'lead_id' => $a->lead_id, 'kind' => $kind,
            'original_name' => mb_substr($name, 0, 255), 'file_url' => '/storage/' . $relDir . '/' . $stored,
            'mime' => $mime ? mb_substr($mime, 0, 120) : null, 'size' => $written,
            'transcript_text' => $text !== null ? mb_substr($text, 0, 1000000) : null,
            'uploaded_by_id' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        try {
            \App\Support\Audit::write([
                'user_id' => auth()->id(),
                'agency_id' => auth()->id() ? \App\Support\AuditScope::resolve((int) auth()->id(), $request) : null,
                'action' => 'sales.activity_file_added', 'entity_type' => 'sales_lead', 'entity_id' => $a->lead_id,
                'payload' => json_encode(['summary' => 'Added ' . $kind . ' "' . $name . '" to an activity on lead #' . $a->lead_id,
                    'activity_id' => $a->id, 'file_id' => $id, 'bytes' => $written]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true, 'file' => self::present(DB::table('sales_activity_files')->find($id))], 201);
    }

    /** DELETE /sales/activity-files/{file} */
    public function destroy(Request $request, int $file): JsonResponse
    {
        $f = DB::table('sales_activity_files')->find($file);
        abort_unless($f, 404);
        $a = SalesActivity::find($f->activity_id);
        if (! $a || ! SalesController::canEditActivity($a)) {
            return response()->json(['message' => 'Only the person who wrote this entry, or a platform admin, can remove its files.'], 403);
        }
        $rel = ltrim(str_replace('/storage/', '', (string) $f->file_url), '/');
        Storage::disk('public')->delete($rel);
        DB::table('sales_activity_files')->where('id', $f->id)->delete();
        try {
            \App\Support\Audit::write([
                'user_id' => auth()->id(),
                'agency_id' => auth()->id() ? \App\Support\AuditScope::resolve((int) auth()->id(), $request) : null,
                'action' => 'sales.activity_file_removed', 'entity_type' => 'sales_lead', 'entity_id' => $f->lead_id,
                'payload' => json_encode(['summary' => 'Removed ' . $f->kind . ' "' . $f->original_name . '" from an activity on lead #' . $f->lead_id]),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return response()->json(['ok' => true]);
    }

    /** For the lead detail: files of these activities, grouped by activity id. */
    public static function forActivities(array $activityIds): array
    {
        if (! $activityIds) return [];
        $out = [];
        foreach (DB::table('sales_activity_files')->whereIn('activity_id', $activityIds)->orderBy('id')->get() as $f) {
            $out[(int) $f->activity_id][] = self::present($f);
        }
        return $out;
    }

    public static function present(object $f): array
    {
        return [
            'id' => (int) $f->id, 'kind' => $f->kind, 'name' => $f->original_name,
            'size' => (int) $f->size, 'mime' => $f->mime,
            'url' => ProtectedMedia::sign($f->file_url),
            'transcript' => $f->transcript_text,
            'created_at' => $f->created_at ? \Illuminate\Support\Carbon::parse($f->created_at)->toDateTimeString() : null,
        ];
    }

    private static function kindFor(string $ext): ?string
    {
        foreach (self::KINDS as $k => $exts) {
            if (in_array($ext, $exts, true)) return $k;
        }
        return null;
    }

    /**
     * A Teams transcript as readable text: "Speaker: what they said", one turn per line,
     * consecutive lines from the same speaker joined. .vtt is what Teams downloads; .docx is
     * the other option it offers.
     */
    public static function transcriptText(string $path, string $ext): ?string
    {
        try {
            if ($ext === 'docx') {
                $zip = new \ZipArchive();
                if ($zip->open($path) !== true) return null;
                $xml = (string) $zip->getFromName('word/document.xml');
                $zip->close();
                $xml = preg_replace('#</w:p>#', "\n", $xml);
                $xml = preg_replace('#<w:tab/>#', "\t", $xml);
                $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
                $text = html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
                return trim(preg_replace("/\n{3,}/", "\n\n", $text));
            }
            $raw = (string) file_get_contents($path);
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            if ($ext === 'txt') return trim($raw);

            $turns = []; $last = null;
            foreach (preg_split('/\r?\n\r?\n/', str_replace("\r\n", "\n", $raw)) as $block) {
                $lines = array_values(array_filter(array_map('trim', explode("\n", $block)), fn ($l) => $l !== ''));
                /* A cue is: an optional identifier line (Teams writes ids like "0f1c…/15-0"),
                   the "start --> end" timing line, then the words. Keep only what follows the
                   timing line; drop the header and NOTE / STYLE blocks. */
                $timing = null;
                foreach ($lines as $k => $l) { if (strpos($l, '-->') !== false) { $timing = $k; break; } }
                if ($timing !== null) {
                    $lines = array_slice($lines, $timing + 1);
                } elseif ($lines && preg_match('/^(WEBVTT|NOTE\b|STYLE\b|REGION\b)/', $lines[0])) {
                    continue;
                }
                if (! $lines) continue;
                $txt = implode(' ', $lines);
                $who = null;
                if (preg_match('#<v\s+([^>]+)>#', $txt, $m)) { $who = trim($m[1]); }
                $txt = trim(html_entity_decode(strip_tags(preg_replace('#<v\s+[^>]+>#', '', $txt)), ENT_QUOTES, 'UTF-8'));
                if ($who === null && preg_match('/^([^:]{2,60}):\s+(.+)$/', $txt, $m)) { $who = $m[1]; $txt = $m[2]; }
                if ($txt === '') continue;
                if ($last !== null && $who !== null && $who === $turns[$last]['who']) { $turns[$last]['text'] .= ' ' . $txt; continue; }
                $turns[] = ['who' => $who, 'text' => $txt]; $last = count($turns) - 1;
            }
            return trim(implode("\n", array_map(fn ($t) => ($t['who'] ? $t['who'] . ': ' : '') . $t['text'], $turns)));
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The folder is served only via the signed route — deny direct access. */
    private static function protectFolder(string $abs): void
    {
        $f = $abs . '/.htaccess';
        if (! is_file($f)) {
            @file_put_contents($f, "# Written by KiddieTrac. These files are served ONLY through the signed\n"
                . "# route /api/v1/media/f — see App\\Support\\ProtectedMedia.\nRequire all denied\n");
        }
    }

    /** Pieces of uploads nobody finished, older than a day. */
    private static function sweepAbandoned(): void
    {
        foreach (glob(storage_path('app/sales-uploads/*'), GLOB_ONLYDIR) ?: [] as $dir) {
            if (@filemtime($dir) < time() - 86400) {
                foreach (glob($dir . '/*.part') ?: [] as $p) { @unlink($p); }
                @rmdir($dir);
            }
        }
    }
}
