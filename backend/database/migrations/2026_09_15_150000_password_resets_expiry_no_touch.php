<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * password_resets.expires_at must not move when the row is touched.
 *
 * The column carried MySQL's `on update current_timestamp()`. An expiry is decided once,
 * when the link is minted; nothing that later touches the row — marking it used,
 * rotating a token — is a reason to change when it expires.
 *
 * It was worse than merely wrong here, because this host runs two clocks: PHP in UTC and
 * MySQL in MST, about seven hours apart (see the audit-log notes on the same subject).
 * So an UPDATE did not just reset the expiry to "now", it reset it to a now that is seven
 * hours in the PAST, and `where('expires_at', '>', now())` then read the row as long
 * expired. Found 2026-09-15: a row created at 14:06:10 with a 15:06:10 expiry came back
 * reading 07:07:22 after an unrelated UPDATE to its token column.
 *
 * No live flow depended on a row surviving an update — every UPDATE today is on a token
 * being invalidated or consumed anyway — which is why this never surfaced as a broken
 * reset link. It is removed as a trap rather than as a fix for a current outage, and it
 * explains expiry values in the existing rows that otherwise read as nonsense.
 *
 * DEFAULT CURRENT_TIMESTAMP is deliberately kept: inserts that omit the column still
 * behave as they did. Only the ON UPDATE clause goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('password_resets')) {
            return;
        }

        DB::statement('ALTER TABLE `password_resets` MODIFY `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    {
        if (! Schema::hasTable('password_resets')) {
            return;
        }

        DB::statement('ALTER TABLE `password_resets` MODIFY `expires_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
    }
};
