<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A 6-digit code beside every reset link (2026-10-15): the person picks email, text or
 * a phone call on "Forgot password", then types the code on the sign-in page.
 *   code_hash        Hash::make of the code - never stored readable
 *   channel          email | sms | voice, where the code was sent
 *   code_attempts    wrong guesses; the code dies at 5
 *   code_expires_at  10 minutes, shorter than the link's hour
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('password_resets', 'code_hash')) {
            DB::statement('ALTER TABLE password_resets ADD COLUMN code_hash VARCHAR(255) NULL AFTER token');
        }
        if (! Schema::hasColumn('password_resets', 'channel')) {
            DB::statement('ALTER TABLE password_resets ADD COLUMN channel VARCHAR(10) NULL AFTER code_hash');
        }
        if (! Schema::hasColumn('password_resets', 'code_attempts')) {
            DB::statement('ALTER TABLE password_resets ADD COLUMN code_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER channel');
        }
        if (! Schema::hasColumn('password_resets', 'code_expires_at')) {
            DB::statement('ALTER TABLE password_resets ADD COLUMN code_expires_at TIMESTAMP NULL AFTER code_attempts');
        }
    }

    public function down(): void
    {
        foreach (['code_expires_at', 'code_attempts', 'channel', 'code_hash'] as $c) {
            if (Schema::hasColumn('password_resets', $c)) {
                DB::statement('ALTER TABLE password_resets DROP COLUMN ' . $c);
            }
        }
    }
};
