<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Moves an MP4's `moov` atom to the front so it can start playing immediately.
 *
 * Phones write MP4s with the sample index (`moov`) AFTER the media data (`mdat`) —
 * they only know the final index once recording stops. A browser cannot decode a
 * single frame until it has read `moov`, so with the index at the end of the file it
 * downloads essentially the WHOLE video before playback begins. Measured on a real
 * upload: an 11.9 MB clip had `moov` at 99.9% of the file, so tapping play meant
 * waiting for all 11.9 MB.
 *
 * This is the classic "faststart" transformation (what `ffmpeg -movflags +faststart`
 * does). ffmpeg is not installed on this host, so it is done here: rewrite the file
 * as ftyp + moov + mdat, and add the resulting shift to every absolute sample offset
 * inside `moov` (the stco / co64 chunk-offset tables) so they still point at the
 * right bytes.
 *
 * Every failure path leaves the original file untouched: a slow video beats a broken
 * one. The media bytes themselves are copied verbatim — only offsets change.
 */
final class Mp4FastStart
{
    /** Atoms that contain other atoms and must be walked to reach stco / co64. */
    private const CONTAINERS = [
        'moov', 'trak', 'mdia', 'minf', 'stbl', 'edts', 'dinf', 'udta', 'mvex',
    ];

    /**
     * @return array{status:string, bytes_moved?:int, moov_at?:float}
     *   status: 'ok' | 'already' | 'skipped' | 'failed'
     */
    public static function process(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) return ['status' => 'skipped'];

        $size = filesize($path);
        if ($size < 32) return ['status' => 'skipped'];

        $fh = fopen($path, 'rb');
        if (! $fh) return ['status' => 'skipped'];

        try {
            $atoms = self::topLevel($fh, $size);
            if (! $atoms) return ['status' => 'skipped'];

            $moovIdx = $mdatIdx = null;
            foreach ($atoms as $i => $a) {
                if ($a['type'] === 'moov' && $moovIdx === null) $moovIdx = $i;
                if ($a['type'] === 'mdat' && $mdatIdx === null) $mdatIdx = $i;
            }
            if ($moovIdx === null || $mdatIdx === null) return ['status' => 'skipped'];

            // Already streamable.
            if ($atoms[$moovIdx]['offset'] < $atoms[$mdatIdx]['offset']) {
                return ['status' => 'already'];
            }

            $moov = $atoms[$moovIdx];
            $moovBuf = self::readAt($fh, $moov['offset'], $moov['size']);
            if ($moovBuf === null || strlen($moovBuf) !== $moov['size']) return ['status' => 'failed'];

            // In the new layout everything that used to sit before `moov` stays put,
            // and `moov` is inserted just ahead of `mdat`, so every byte from `mdat`
            // onwards shifts forward by exactly the size of `moov`.
            $delta = $moov['size'];
            $patched = self::shiftChunkOffsets($moovBuf, $delta);
            if ($patched === null) return ['status' => 'failed'];

            $tmp = $path.'.faststart.tmp';
            $out = fopen($tmp, 'wb');
            if (! $out) return ['status' => 'failed'];

            try {
                // 1. every atom before mdat, except the old moov
                foreach ($atoms as $i => $a) {
                    if ($i === $moovIdx) continue;
                    if ($a['offset'] >= $atoms[$mdatIdx]['offset']) continue;
                    if (! self::copyRange($fh, $out, $a['offset'], $a['size'])) throw new \RuntimeException('copy head');
                }
                // 2. moov, with offsets corrected
                if (fwrite($out, $patched) !== strlen($patched)) throw new \RuntimeException('write moov');
                // 3. mdat and everything after it, except the old moov
                foreach ($atoms as $i => $a) {
                    if ($i === $moovIdx) continue;
                    if ($a['offset'] < $atoms[$mdatIdx]['offset']) continue;
                    if (! self::copyRange($fh, $out, $a['offset'], $a['size'])) throw new \RuntimeException('copy tail');
                }
            } catch (\Throwable $e) {
                fclose($out);
                @unlink($tmp);
                return ['status' => 'failed'];
            }
            fclose($out);

            // The rewrite must not lose or invent bytes.
            if (filesize($tmp) !== $size) { @unlink($tmp); return ['status' => 'failed']; }

            // And moov must now genuinely come first.
            $check = fopen($tmp, 'rb');
            $newAtoms = $check ? self::topLevel($check, $size) : [];
            if ($check) fclose($check);
            $okOrder = false;
            foreach ($newAtoms as $a) {
                if ($a['type'] === 'moov') { $okOrder = true; break; }
                if ($a['type'] === 'mdat') break;
            }
            if (! $okOrder) { @unlink($tmp); return ['status' => 'failed']; }

            if (! @rename($tmp, $path)) { @unlink($tmp); return ['status' => 'failed']; }

            return ['status' => 'ok', 'bytes_moved' => $delta, 'moov_at' => round(100 * $moov['offset'] / $size, 1)];
        } finally {
            if (is_resource($fh)) fclose($fh);
        }
    }

    /** Top-level atom list: [['type'=>..,'offset'=>..,'size'=>..], ...] */
    private static function topLevel($fh, int $size): array
    {
        $atoms = [];
        $pos = 0;
        while ($pos + 8 <= $size) {
            fseek($fh, $pos);
            $head = fread($fh, 8);
            if ($head === false || strlen($head) < 8) break;
            $len = unpack('N', substr($head, 0, 4))[1];
            $type = substr($head, 4, 4);

            if ($len === 1) {
                // 64-bit size in the following 8 bytes (how phones write a big mdat).
                $ext = fread($fh, 8);
                if ($ext === false || strlen($ext) < 8) break;
                $len = (int) unpack('J', $ext)[1];
            } elseif ($len === 0) {
                $len = $size - $pos;              // "to end of file"
            }
            if ($len < 8 || $pos + $len > $size) break;

            $atoms[] = ['type' => $type, 'offset' => $pos, 'size' => $len];
            $pos += $len;
        }
        return $atoms;
    }

    /**
     * Add $delta to every entry of every chunk-offset table inside a moov buffer.
     * Walks the container tree rather than scanning for the fourcc, so a stray
     * "stco" inside a metadata string can never be mistaken for a table.
     */
    private static function shiftChunkOffsets(string $buf, int $delta): ?string
    {
        $walk = function (int $start, int $end) use (&$walk, &$buf, $delta): bool {
            $p = $start;
            while ($p + 8 <= $end) {
                $len = unpack('N', substr($buf, $p, 4))[1];
                $type = substr($buf, $p + 4, 4);
                $header = 8;
                if ($len === 1) {
                    if ($p + 16 > $end) return false;
                    $len = (int) unpack('J', substr($buf, $p + 8, 8))[1];
                    $header = 16;
                } elseif ($len === 0) {
                    $len = $end - $p;
                }
                if ($len < $header || $p + $len > $end) return false;

                if ($type === 'stco' || $type === 'co64') {
                    // version(1) + flags(3) + entry_count(4), then the table
                    $tbl = $p + $header + 4;
                    if ($tbl + 4 > $end) return false;
                    $count = unpack('N', substr($buf, $tbl, 4))[1];
                    $q = $tbl + 4;
                    $wide = $type === 'co64';
                    $step = $wide ? 8 : 4;
                    if ($q + $count * $step > $end) return false;
                    for ($i = 0; $i < $count; $i++) {
                        if ($wide) {
                            $v = (int) unpack('J', substr($buf, $q, 8))[1] + $delta;
                            $buf = substr_replace($buf, pack('J', $v), $q, 8);
                        } else {
                            $v = unpack('N', substr($buf, $q, 4))[1] + $delta;
                            // A 32-bit table cannot hold the shifted value — bail out
                            // rather than silently wrapping and corrupting playback.
                            if ($v > 0xFFFFFFFF) return false;
                            $buf = substr_replace($buf, pack('N', $v), $q, 4);
                        }
                        $q += $step;
                    }
                } elseif (in_array($type, self::CONTAINERS, true)) {
                    if (! $walk($p + $header, $p + $len)) return false;
                }
                $p += $len;
            }
            return true;
        };

        // Skip moov's own 8-byte header and walk its children.
        return $walk(8, strlen($buf)) ? $buf : null;
    }

    private static function readAt($fh, int $offset, int $len): ?string
    {
        if (fseek($fh, $offset) !== 0) return null;
        $data = '';
        while (strlen($data) < $len) {
            $chunk = fread($fh, min(1 << 20, $len - strlen($data)));
            if ($chunk === false || $chunk === '') break;
            $data .= $chunk;
        }
        return $data;
    }

    private static function copyRange($fh, $out, int $offset, int $len): bool
    {
        if (fseek($fh, $offset) !== 0) return false;
        $left = $len;
        while ($left > 0) {
            $chunk = fread($fh, (int) min(1 << 20, $left));
            if ($chunk === false || $chunk === '') return false;
            if (fwrite($out, $chunk) !== strlen($chunk)) return false;
            $left -= strlen($chunk);
        }
        return true;
    }
}
