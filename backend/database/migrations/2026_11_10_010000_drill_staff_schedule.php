<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each educator's map and fire drill schedule (2026-11-09, Anthony: maps "added from the educators kebab by
 * management"; "the fire drill schedule needs to be configurable to set a specific time/day for each educator").
 * drill_maps.user_id: whose map it is (null = an older centre-wide map). drill_staff_dates.sched_*: see
 * App\Support\DrillSchedule. drill_schedule_sends: one reminder per person + occurrence + kind, so a reminder never
 * goes out twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('drill_maps') && ! Schema::hasColumn('drill_maps', 'user_id')) {
            Schema::table('drill_maps', function (Blueprint $t) {
                $t->unsignedBigInteger('user_id')->nullable()->after('centre_id');
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (Schema::hasTable('drill_staff_dates') && ! Schema::hasColumn('drill_staff_dates', 'sched_repeat')) {
            Schema::table('drill_staff_dates', function (Blueprint $t) {
                $t->string('sched_repeat', 20)->nullable()->after('note');
                $t->unsignedTinyInteger('sched_weekday')->nullable()->after('sched_repeat');
                $t->unsignedTinyInteger('sched_week')->nullable()->after('sched_weekday');
                $t->date('sched_date')->nullable()->after('sched_week');
                $t->string('sched_time', 5)->nullable()->after('sched_date');
            });
        }
        if (! Schema::hasTable('drill_schedule_sends')) {
            Schema::create('drill_schedule_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->string('occurrence', 16);
                $t->string('kind', 20);
                $t->timestamp('created_at')->nullable();
                $t->unique(['agency_id', 'user_id', 'occurrence', 'kind'], 'drill_sched_sends_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('drill_schedule_sends');
    }
};
