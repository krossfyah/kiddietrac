<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 2 (2026-11-27, App\Support\HrTime).
 *  - hr_overtime_requests: extra hours a staff member (or their manager) logs; someone else approves them as pay (a line on
 *    the next manual payslip, payroll_document_id once paid) or banked (an "Overtime bank" leave-ledger row, source ot:<id>).
 *  - hr_timesheet_locks: a centre's timesheet for a period, approved by a director / admin and locked (status locked); an
 *    admin unlock keeps the row (status unlocked, who, when, why). Punch edits inside a locked period are refused.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_overtime_requests')) {
            Schema::create('hr_overtime_requests', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('user_id');
                $t->date('work_date');
                $t->unsignedSmallInteger('minutes');
                $t->string('reason', 500)->nullable();
                $t->string('preference', 8)->nullable();          // pay | bank (what the person asked for)
                $t->unsignedBigInteger('logged_by_id')->nullable();
                $t->string('status', 16)->default('submitted');    // submitted | approved | declined | withdrawn | paid
                $t->string('outcome', 8)->nullable();              // pay | bank (what the approver chose)
                $t->decimal('rate', 4, 2)->nullable();             // the multiplier applied (1.00, 1.50 ...)
                $t->decimal('bank_hours', 8, 2)->nullable();       // hours put in the bank (minutes / 60 x rate)
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->text('decision_comment')->nullable();
                $t->unsignedBigInteger('payroll_document_id')->nullable();
                $t->dateTime('paid_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
                $t->index(['user_id', 'work_date']);
            });
        }
        if (! Schema::hasTable('hr_timesheet_locks')) {
            Schema::create('hr_timesheet_locks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->date('period_start');
                $t->date('period_end');
                $t->string('status', 10)->default('locked');       // locked | unlocked
                $t->unsignedBigInteger('approved_by_id')->nullable();
                $t->dateTime('approved_at')->nullable();
                $t->string('note', 500)->nullable();
                $t->mediumText('summary')->nullable();             // JSON: the per-person totals as approved
                $t->unsignedBigInteger('unlocked_by_id')->nullable();
                $t->dateTime('unlocked_at')->nullable();
                $t->string('unlock_reason', 500)->nullable();
                $t->timestamps();
                $t->index(['centre_id', 'status', 'period_start']);
                $t->index(['agency_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_timesheet_locks');
        Schema::dropIfExists('hr_overtime_requests');
    }
};
