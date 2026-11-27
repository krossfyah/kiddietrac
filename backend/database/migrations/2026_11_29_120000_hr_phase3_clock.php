<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 3, item 11 (2026-11-26, App\Support\HrClock):
 *  - time_punches.geo_status / geo_distance_m: the geofence answer at clock-in (never the location itself);
 *  - hr_overtime_notices: one row per person, agency week and level (warn | over), so each warning goes once.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('time_punches', 'geo_status')) {
            Schema::table('time_punches', function (Blueprint $t) {
                $t->string('geo_status', 16)->nullable();          // inside | outside | no_location; null = not checked
                $t->unsignedInteger('geo_distance_m')->nullable();
            });
        }
        if (! Schema::hasTable('hr_overtime_notices')) {
            Schema::create('hr_overtime_notices', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->date('week_start');
                $t->string('level', 8);                            // warn | over
                $t->decimal('hours', 6, 2);
                $t->timestamp('created_at')->nullable();
                $t->unique(['user_id', 'week_start', 'level']);
                $t->index(['agency_id', 'week_start']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_overtime_notices');
        if (Schema::hasColumn('time_punches', 'geo_status')) {
            Schema::table('time_punches', function (Blueprint $t) {
                $t->dropColumn(['geo_status', 'geo_distance_m']);
            });
        }
    }
};
