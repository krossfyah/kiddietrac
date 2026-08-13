<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A crash that happens BEFORE sign-in — on the login screen, or in the app while
 * signed out — has no user and therefore no agency. Those are precisely the
 * crashes worth tracking, so a NOT NULL agency_id meant the auto-filed support
 * ticket failed exactly where the reporting mattered most. Rather than attribute
 * such a report to some arbitrary tenant, allow it to be filed with no agency:
 * a platform-level ticket, which is what it actually is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE support_tickets MODIFY agency_id BIGINT UNSIGNED NULL');
        // Same reasoning for the reporter: a pre-login crash has nobody signed in.
        DB::statement('ALTER TABLE support_tickets MODIFY raised_by_user_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE support_tickets MODIFY agency_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE support_tickets MODIFY raised_by_user_id BIGINT UNSIGNED NOT NULL');
    }
};
