<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tracks and enforces per-centre photo storage quotas.
 * Default quota: 1 GB per centre. Adjustable per-centre in storage_usage table.
 */
final class StorageQuotaService
{
    public const DEFAULT_QUOTA_BYTES = 1_073_741_824; // 1 GB

    /**
     * Get usage stats for a centre. Recalculates if last calc was >1hr ago.
     */
    public function getUsage(int $centreId): array
    {
        $row = DB::table('storage_usage')->where('centre_id', $centreId)->first();

        // Recalculate if stale (>1hr) or missing
        if (!$row || strtotime($row->last_calculated_at) < time() - 3600) {
            return $this->recalculate($centreId);
        }

        return [
            'centre_id' => $centreId,
            'used_bytes' => (int) $row->total_bytes,
            'used_files' => (int) $row->total_files,
            'quota_bytes' => (int) $row->quota_bytes,
            'used_pct' => $row->quota_bytes > 0
                ? min(100, (int) round($row->total_bytes / $row->quota_bytes * 100))
                : 0,
            'remaining_bytes' => max(0, (int) $row->quota_bytes - (int) $row->total_bytes),
            'used_human' => $this->humanBytes((int) $row->total_bytes),
            'quota_human' => $this->humanBytes((int) $row->quota_bytes),
        ];
    }

    /**
     * Recalculate usage from the media table for this centre.
     */
    public function recalculate(int $centreId): array
    {
        // v22p98: media has no child_id — it belongs to a ROOM (media.room_id).
        // Sum media file sizes for all rooms at this centre.
        $row = DB::table('media')
            ->join('rooms', 'rooms.id', '=', 'media.room_id')
            ->where('rooms.centre_id', $centreId)
            ->whereNull('media.deleted_at')
            ->whereNotNull('media.size_bytes')
            ->selectRaw('COALESCE(SUM(media.size_bytes), 0) as total_bytes, COUNT(*) as total_files')
            ->first();

        $totalBytes = (int) ($row->total_bytes ?? 0);
        $totalFiles = (int) ($row->total_files ?? 0);

        // Upsert
        $existing = DB::table('storage_usage')->where('centre_id', $centreId)->first();
        if ($existing) {
            DB::table('storage_usage')->where('centre_id', $centreId)->update([
                'total_bytes' => $totalBytes,
                'total_files' => $totalFiles,
                'last_calculated_at' => now(),
                'updated_at' => now(),
            ]);
            $quotaBytes = (int) $existing->quota_bytes;
        } else {
            $quotaBytes = self::DEFAULT_QUOTA_BYTES;
            DB::table('storage_usage')->insert([
                'centre_id' => $centreId,
                'total_bytes' => $totalBytes,
                'total_files' => $totalFiles,
                'quota_bytes' => $quotaBytes,
                'last_calculated_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [
            'centre_id' => $centreId,
            'used_bytes' => $totalBytes,
            'used_files' => $totalFiles,
            'quota_bytes' => $quotaBytes,
            'used_pct' => $quotaBytes > 0 ? min(100, (int) round($totalBytes / $quotaBytes * 100)) : 0,
            'remaining_bytes' => max(0, $quotaBytes - $totalBytes),
            'used_human' => $this->humanBytes($totalBytes),
            'quota_human' => $this->humanBytes($quotaBytes),
        ];
    }

    /**
     * Check if a centre has room for a file of $sizeBytes.
     * Returns true if upload is permitted, false otherwise.
     */
    public function canUpload(int $centreId, int $sizeBytes): bool
    {
        $usage = $this->getUsage($centreId);
        return $usage['used_bytes'] + $sizeBytes <= $usage['quota_bytes'];
    }

    /**
     * Increment usage incrementally after a successful upload.
     * Avoids full recalculation on every upload.
     */
    public function recordUpload(int $centreId, int $sizeBytes): void
    {
        DB::table('storage_usage')
            ->where('centre_id', $centreId)
            ->increment('total_bytes', $sizeBytes, [
                'total_files' => DB::raw('total_files + 1'),
                'updated_at' => now(),
            ]);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) return $bytes.' B';
        if ($bytes < 1024 ** 2) return round($bytes / 1024, 1).' KB';
        if ($bytes < 1024 ** 3) return round($bytes / 1024 ** 2, 1).' MB';
        return round($bytes / 1024 ** 3, 2).' GB';
    }
}
