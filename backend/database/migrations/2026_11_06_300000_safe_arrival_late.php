<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RUNNING LATE (2026-11-06). A family tells the centre their child will be in late - "running late, here by
 * 10:15" - and a staff member logs it from the child's quick actions on Today, or from the Safe Arrival board.
 * Safe Arrival then waits for that time (plus the agency's grace) before asking or alerting anyone. One row per
 * child per day; logging it again moves the time. Cancelling stamps cancelled_at, nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safe_arrival_late')) {
            return;
        }
        Schema::create('safe_arrival_late', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable();
            $t->unsignedBigInteger('child_id');
            $t->date('late_date');
            $t->time('arrive_by');                          // wall clock in the agency's zone, like expected_dropoff_time
            $t->string('note', 500)->nullable();
            $t->unsignedBigInteger('reported_by_id')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->unsignedBigInteger('cancelled_by_id')->nullable();
            $t->timestamps();
            $t->unique(['child_id', 'late_date']);
        });
        DB::statement('ALTER TABLE safe_arrival_late CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_arrival_late');
    }
};
