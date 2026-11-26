<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Pictures on quick notes (2026-11-26, Anthony: "ability to add screenshots to notes as well wire this up").
 *
 *  - A picture inherits its note's audience. Every read goes through QuickNotes::find() first (the owner for a private
 *    note; staff of the ACTIVE agency, at the note's centre, for a shared one) and fails closed with 404.
 *  - Files are on the private disk, per agency: quick-notes/<agency id|personal>/<note id>/<random>.webp. Never a
 *    public /storage link; QuickNotesController::pictureShow streams them to a signed-in person.
 *  - The phone already shrinks to ~1920px and drops EXIF; the server decodes and re-encodes every upload anyway
 *    (GD writes no metadata), so a file that skipped the page still carries no location.
 *  - Up to MAX per note. Removing one, or deleting the note (QuickNotesController::destroy, the demo reset), deletes
 *    the files and the rows: purge().
 */
final class QuickNotePictures
{
    public const MAX = 5;
    public const MAX_KB = 10240;          // 10 MB before upload
    public const MAX_SIDE = 2560;         // server cap; the page already sends ~1920
    public const TYPES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    private static function disk()
    {
        return Storage::disk('local');
    }

    /** [note id => [{id, width, height}]] for the notes being shown. */
    public static function forNotes(array $noteIds): array
    {
        $out = [];
        if (! $noteIds) return $out;
        foreach (DB::table('quick_note_pictures')->whereIn('note_id', $noteIds)->orderBy('id')->get(['id', 'note_id', 'width', 'height']) as $p) {
            $out[(int) $p->note_id][] = ['id' => (int) $p->id, 'width' => (int) $p->width, 'height' => (int) $p->height];
        }
        return $out;
    }

    public static function count(int $noteId): int
    {
        return (int) DB::table('quick_note_pictures')->where('note_id', $noteId)->count();
    }

    /** Decode, shrink, re-encode (no metadata survives) and file one picture for a note. Returns the new row id. */
    public static function store(object $note, UploadedFile $file, int $uid): int
    {
        $raw = @file_get_contents($file->getRealPath());
        abort_unless(is_string($raw) && $raw !== '', 422, 'That picture could not be read. Try a PNG or JPG.');
        $img = @imagecreatefromstring($raw);
        abort_unless($img !== false, 422, 'That picture could not be read. Try a PNG or JPG.');
        $w = imagesx($img); $h = imagesy($img);
        abort_unless($w > 0 && $h > 0, 422, 'That picture could not be read. Try a PNG or JPG.');
        $scale = min(1, self::MAX_SIDE / max($w, $h));
        if ($scale < 1) {
            $nw = max(1, (int) round($w * $scale)); $nh = max(1, (int) round($h * $scale));
            $dst = imagecreatetruecolor($nw, $nh);
            imagealphablending($dst, false); imagesavealpha($dst, true);
            imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img); $img = $dst; $w = $nw; $h = $nh;
        } elseif (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagesavealpha($img, true);
        ob_start(); imagewebp($img, null, 82); $webp = (string) ob_get_clean();
        imagedestroy($img);
        abort_unless($webp !== '', 422, 'That picture could not be saved. Please try again.');

        $dir = 'quick-notes/' . ($note->agency_id ? (int) $note->agency_id : 'personal') . '/' . (int) $note->id;
        $path = $dir . '/' . Str::random(32) . '.webp';
        self::disk()->put($path, $webp);

        return (int) DB::table('quick_note_pictures')->insertGetId([
            'note_id' => (int) $note->id, 'agency_id' => $note->agency_id ? (int) $note->agency_id : null, 'user_id' => $uid,
            'path' => $path, 'mime' => 'image/webp', 'width' => $w, 'height' => $h, 'bytes' => strlen($webp), 'created_at' => now(),
        ]);
    }

    /** The bytes of a picture, or null when its file is gone. The CALLER has already checked the note. */
    public static function bytes(object $pic): ?string
    {
        $d = self::disk();
        if (! $pic->path || ! str_starts_with((string) $pic->path, 'quick-notes/') || ! $d->exists($pic->path)) return null;
        return $d->get($pic->path);
    }

    /** Delete pictures (files and rows): one by id, or every picture of these notes. Returns how many went. */
    public static function purge(array $noteIds = [], array $pictureIds = []): int
    {
        $q = DB::table('quick_note_pictures');
        if ($pictureIds) $q->whereIn('id', array_map('intval', $pictureIds));
        elseif ($noteIds) $q->whereIn('note_id', array_map('intval', $noteIds));
        else return 0;
        $rows = $q->get(['id', 'path']);
        $d = self::disk();
        foreach ($rows as $r) {
            if ($r->path && str_starts_with((string) $r->path, 'quick-notes/')) {
                try { $d->delete($r->path); } catch (\Throwable $e) { report($e); }
            }
        }
        if ($rows->isNotEmpty()) DB::table('quick_note_pictures')->whereIn('id', $rows->pluck('id')->all())->delete();
        return $rows->count();
    }
}
