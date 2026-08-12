<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-account username + relax the unique-email rule so one person can
 * hold several accounts under the SAME email, disambiguated by username at login.
 * Username is nullable + unique (MySQL allows many NULLs in a unique index), so
 * existing users are unaffected and keep signing in with their email.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'username')) {
            DB::statement('ALTER TABLE users ADD COLUMN username VARCHAR(50) NULL AFTER email');
            DB::statement('ALTER TABLE users ADD UNIQUE users_username_unique (username)');
        }
        // Drop the UNIQUE index on email → plain index (lookups still fast).
        $hasUnique = DB::select("SHOW INDEX FROM users WHERE Key_name = 'email' AND Non_unique = 0");
        if ($hasUnique) {
            DB::statement('ALTER TABLE users DROP INDEX email');
            DB::statement('ALTER TABLE users ADD INDEX users_email_idx (email)');
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'username')) {
            try { DB::statement('ALTER TABLE users DROP INDEX users_username_unique'); } catch (\Throwable $e) {}
            DB::statement('ALTER TABLE users DROP COLUMN username');
        }
        // Note: not restoring the email UNIQUE index automatically — duplicates
        // may now exist. Deduplicate first if you truly need it back.
    }
};
