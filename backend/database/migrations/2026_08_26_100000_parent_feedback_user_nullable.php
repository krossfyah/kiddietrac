<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `parent_feedback.user_id` must be nullable.
 *
 * The column was NOT NULL because the original v22p55 feature was only reachable by a
 * signed-in parent. Feedback left from the daily summary email arrives through a SIGNED
 * LINK, which identifies a FAMILY and a child — not a person. There is no user to record,
 * and inventing one (0, or the first guardian) would put a name against words they may
 * not have written.
 *
 * Raw SQL because doctrine/dbal is not installed, so ->change() is unavailable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('parent_feedback') || ! Schema::hasColumn('parent_feedback', 'user_id')) {
            return;
        }
        DB::statement('ALTER TABLE `parent_feedback` MODIFY `user_id` BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        // Deliberately not reverted: rows created by the email link have a genuine NULL
        // here, and forcing the column back to NOT NULL would fail against real data.
    }
};
