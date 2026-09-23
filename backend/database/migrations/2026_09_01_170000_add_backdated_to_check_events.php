<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a check-in or check-out that was entered after the fact.
 *
 * Every path that records attendance stamps occurred_at = now(), so a missed
 * check-in could not be added later at the time it actually happened — a director's
 * only option was to check the child in now, which puts a wrong time into the record
 * that drives ratios and billing.
 *
 * Backdating is allowed for directors and admins, but it must never be invisible:
 * an attendance record that says a child arrived at 09:00 should also say that a
 * human typed it in at 16:30, and who. created_at already carries the second half;
 * this flag makes the fact of it queryable without comparing two timestamps in
 * every report that touches attendance.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('check_events') || Schema::hasColumn('check_events', 'backdated')) {
            return;
        }

        Schema::table('check_events', function (Blueprint $t) {
            $t->boolean('backdated')->default(false)->after('is_automatic');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('check_events') && Schema::hasColumn('check_events', 'backdated')) {
            Schema::table('check_events', function (Blueprint $t) {
                $t->dropColumn('backdated');
            });
        }
    }
};
