<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tie a reset/invite token to a specific user id. Email alone is no longer a
 * unique key (multiple accounts may share one email), so set-password links
 * must resolve the exact account. Nullable → old rows keep working via email.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('password_resets', 'user_id')) {
            DB::statement('ALTER TABLE password_resets ADD COLUMN user_id BIGINT UNSIGNED NULL AFTER email');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('password_resets', 'user_id')) {
            DB::statement('ALTER TABLE password_resets DROP COLUMN user_id');
        }
    }
};
