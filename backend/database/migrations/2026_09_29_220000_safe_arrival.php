<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Safe Arrival (2026-09-29). One row per child per day once a check has started: when the
   parents were asked, when staff were alerted, and how it ended -- the record an
   inspector asks for when a child did not arrive. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safe_arrival_checks')) {
            return;
        }
        Schema::create('safe_arrival_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->unsignedBigInteger('room_id')->nullable();
            $t->unsignedBigInteger('child_id');
            $t->date('check_date');
            $t->time('expected_time')->nullable();          // wall clock, like children.expected_dropoff_time
            $t->timestamp('due_at')->nullable();            // expected + grace, UTC
            // notified -> escalated -> arrived | absent | resolved
            $t->string('status', 20)->default('notified');
            $t->timestamp('parents_notified_at')->nullable();
            $t->unsignedSmallInteger('parents_notified')->default(0);
            $t->timestamp('escalated_at')->nullable();
            $t->unsignedSmallInteger('staff_alerted')->default(0);
            $t->timestamp('arrived_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by_id')->nullable();
            $t->string('resolution', 30)->nullable();       // arrived | absent | parent_contacted | other
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['child_id', 'check_date']);
        });
        DB::statement('ALTER TABLE safe_arrival_checks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_arrival_checks');
    }
};
