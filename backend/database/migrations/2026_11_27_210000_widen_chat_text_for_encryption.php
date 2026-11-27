<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chat message text is sealed at rest from 2026-11-27 (App\Support\Sensitive, App\Support\ChatPrivacy).
 * Ciphertext is about 1.8x the text plus ~200 bytes, so a 5,000-character message of emoji could pass
 * TEXT's 64 KB. MEDIUMTEXT (16 MB) removes the question. Nullability and defaults are kept as they were.
 */
return new class extends Migration
{
    private const COLS = [
        ['messages', 'body', 'NOT NULL'],
        ['messages', 'translated_body', 'NULL DEFAULT NULL'],
        ['staff_messages', 'body', 'NOT NULL'],
        ['staff_messages', 'attachments', 'NULL DEFAULT NULL'],
        ['staff_parent_messages', 'body', 'NOT NULL'],
        ['content_reports', 'excerpt', 'NULL DEFAULT NULL'],
        ['parent_issue_messages', 'body', 'NOT NULL'],
    ];

    public function up(): void
    {
        foreach (self::COLS as [$t, $c, $null]) {
            if (! Schema::hasTable($t) || ! Schema::hasColumn($t, $c)) {
                continue;
            }
            $type = strtolower((string) (DB::selectOne(
                'SELECT DATA_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$t, $c]
            )->t ?? ''));
            if (in_array($type, ['text', 'tinytext', 'varchar'], true)) {
                DB::statement("ALTER TABLE `$t` MODIFY `$c` MEDIUMTEXT $null");
            }
        }
    }

    public function down(): void
    {
        // Deliberately not narrowed again: sealed values may no longer fit in TEXT.
    }
};
