<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PASSWORDS DATED IN THE FUTURE (2026-09-21)
 *
 * The backfill that gave every account a password age staggered it by ADDING up to 60
 * days to created_at. For anyone created in the last two months that lands ahead of the
 * clock: 17 accounts ended up with a password_changed_at in the future, the furthest
 * seven weeks out.
 *
 * Two consequences, both silent. The age is negative, so daysLeft() reports more than the
 * 90-day maximum and the account never comes due - the rotation we just turned on simply
 * skips them. And the "last password reset" field in user management shows a date that
 * has not happened yet.
 *
 * Re-staggered the way the backfill should have done it: BACKWARDS from now, MOD(id, 60)
 * so it stays deterministic, leaving each of them between 30 and 90 days - a full first
 * cycle, spread out, and no longer exempt.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'password_changed_at')) {
            return;
        }

        /* Nested DATE_SUB(DATE_ADD(...)) rather than `INTERVAL a DAY - INTERVAL b DAY`:
           MariaDB rejects interval arithmetic inside DATE_ADD. */
        $n = DB::update("
            UPDATE users
               SET password_changed_at = DATE_SUB(
                     DATE_ADD(NOW(), INTERVAL (MOD(id, 60)) DAY), INTERVAL 60 DAY)
             WHERE password_changed_at IS NOT NULL
               AND password_changed_at > NOW()
        ");

        echo "  password_changed_at pulled back out of the future: {$n} account(s)\n";
    }

    public function down(): void
    {
        /* Nothing to restore - the previous values were wrong, not merely different. */
    }
};
