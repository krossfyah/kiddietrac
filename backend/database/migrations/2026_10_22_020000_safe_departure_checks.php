<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Safe Departure (2026-10-22): the end-of-day half of Safe Arrival. One row per child per
   day once a child is still signed in past their pickup / the centre's closing time: who
   was alerted and when, and how it was closed. See App\Support\SafeDeparture. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('safe_departure_checks')) {
            return;
        }
        Schema::create('safe_departure_checks', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->unsignedBigInteger('room_id')->nullable();
            $t->unsignedBigInteger('child_id');
            $t->date('check_date');
            $t->timestamp('checked_in_at')->nullable();      // the sign-in still open, UTC
            $t->time('expected_time')->nullable();           // pickup or closing, wall clock
            $t->timestamp('due_at')->nullable();             // expected + grace, UTC
            // educator -> escalated -> parents -> resolved (or unconfirmed: auto signed out)
            $t->string('status', 20)->default('educator');
            $t->timestamp('educator_alerted_at')->nullable();
            $t->unsignedSmallInteger('educators_alerted')->default(0);
            $t->timestamp('escalated_at')->nullable();
            $t->unsignedSmallInteger('staff_alerted')->default(0);
            $t->string('staff_unreached', 500)->nullable();
            $t->timestamp('parents_notified_at')->nullable();
            $t->unsignedSmallInteger('parents_notified')->default(0);
            $t->timestamp('auto_signed_out_at')->nullable();
            $t->timestamp('checked_out_at')->nullable();     // a real sign-out, UTC
            $t->timestamp('resolved_at')->nullable();
            $t->unsignedBigInteger('resolved_by_id')->nullable();
            $t->string('resolution', 30)->nullable();        // checked_out | picked_up | still_here | other
            $t->text('note')->nullable();
            $t->timestamps();
            $t->unique(['child_id', 'check_date']);
        });
        DB::statement('ALTER TABLE safe_departure_checks CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
    }

    public function down(): void
    {
        Schema::dropIfExists('safe_departure_checks');
    }
};
