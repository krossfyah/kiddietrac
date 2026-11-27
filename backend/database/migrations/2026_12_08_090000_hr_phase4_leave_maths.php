<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 9 (2026-11-27): leave maths extras (App\Support\HrLeaveMaths).
 *
 * - hr_shutdowns: an agency admin's choice for one centre closure: deduct leave (from a type, people left out) or don't.
 *   status none | applied | reversed. The closure's dates/type/reason are kept as a snapshot, so a removed closure's
 *   deduction can still be reviewed and reversed. round = how many times rows were written (source_key shut:<id>:<round>).
 * - hr_shutdown_lines: per person, per round: the days counted and the amount (current = the latest round).
 * - hr_leave_rechecks: "amount may be wrong - review": a booked request (or an applied shutdown) whose amount would come
 *   out differently after a closure changed. open | corrected | kept | cleared. Nothing is changed until a manager decides.
 * Additive and idempotent. Existing tables are not altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_shutdowns')) {
            Schema::create('hr_shutdowns', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('closure_id');
                $t->unsignedBigInteger('centre_id');
                $t->date('closure_from');
                $t->date('closure_to');
                $t->string('closure_type', 40)->nullable();
                $t->string('closure_reason', 200)->nullable();
                $t->string('choice', 8);                  // deduct | none
                $t->string('status', 10)->default('none'); // none | applied | reversed
                $t->unsignedBigInteger('leave_type_id')->nullable();
                $t->json('excluded_user_ids')->nullable();
                $t->unsignedInteger('round')->default(0);
                $t->decimal('amount_total', 10, 2)->default(0);
                $t->unsignedInteger('people_count')->default(0);
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->timestamp('decided_at')->nullable();
                $t->unsignedBigInteger('reversed_by_id')->nullable();
                $t->timestamp('reversed_at')->nullable();
                $t->string('reverse_reason', 300)->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'closure_id']);
            });
        }
        if (! Schema::hasTable('hr_shutdown_lines')) {
            Schema::create('hr_shutdown_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('shutdown_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('leave_type_id');
                $t->unsignedInteger('round');
                $t->json('days')->nullable();
                $t->decimal('amount', 9, 2)->default(0);
                $t->decimal('ledger_amount', 9, 2)->nullable();
                $t->boolean('current')->default(1);
                $t->timestamp('created_at')->nullable();
                $t->index(['user_id', 'leave_type_id']);
            });
        }
        if (! Schema::hasTable('hr_leave_rechecks')) {
            Schema::create('hr_leave_rechecks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('time_off_request_id')->nullable()->index();
                $t->unsignedBigInteger('shutdown_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->unsignedBigInteger('leave_type_id');
                $t->decimal('booked', 9, 2);
                $t->decimal('fresh', 9, 2);
                $t->string('unit', 8)->default('days');
                $t->string('cause', 255)->nullable();
                $t->unsignedBigInteger('closure_id')->nullable();
                $t->string('status', 10)->default('open');
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('resolved_by_id')->nullable();
                $t->timestamp('resolved_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_leave_rechecks');
        Schema::dropIfExists('hr_shutdown_lines');
        Schema::dropIfExists('hr_shutdowns');
    }
};
