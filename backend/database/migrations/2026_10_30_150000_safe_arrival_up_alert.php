<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Safe Arrival: when directors/admins were alerted, separately from the room's educators (2026-10-30).
 * Used when the agency chooses "only if still open after the educators" (settings.safe_arrival.up_timing = after).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('safe_arrival_checks', function (Blueprint $t) {
            if (! Schema::hasColumn('safe_arrival_checks', 'up_alerted_at')) $t->timestamp('up_alerted_at')->nullable()->after('staff_unreached');
            if (! Schema::hasColumn('safe_arrival_checks', 'up_alerted')) $t->unsignedInteger('up_alerted')->default(0)->after('up_alerted_at');
        });
    }

    public function down(): void
    {
        Schema::table('safe_arrival_checks', function (Blueprint $t) {
            if (Schema::hasColumn('safe_arrival_checks', 'up_alerted')) $t->dropColumn('up_alerted');
            if (Schema::hasColumn('safe_arrival_checks', 'up_alerted_at')) $t->dropColumn('up_alerted_at');
        });
    }
};
