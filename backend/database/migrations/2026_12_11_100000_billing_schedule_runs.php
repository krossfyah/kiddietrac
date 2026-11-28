<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Automated billing schedules (2026-11-28, App\Support\BillingSchedules).
   billing_schedules.auto_since: when the schedule was saved with automation on. Rows saved before this existed did
   nothing for months; they are not woken up by a deploy, only by an admin saving them again.
   billing_schedule_runs: one row per family per billed period, UNIQUE, so a period is never invoiced twice. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_schedules', function (Blueprint $t) {
            if (! Schema::hasColumn('billing_schedules', 'auto_since')) $t->dateTime('auto_since')->nullable();
            if (! Schema::hasColumn('billing_schedules', 'updated_by_id')) $t->unsignedBigInteger('updated_by_id')->nullable();
        });
        if (! Schema::hasTable('billing_schedule_runs')) {
            Schema::create('billing_schedule_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('schedule_id')->nullable();
                $t->date('run_on');
                $t->date('period_start');
                $t->date('period_end');
                $t->string('status', 24);   // invoiced | charged | charge_failed | skipped | failed
                $t->unsignedBigInteger('invoice_id')->nullable();
                $t->decimal('amount', 10, 2)->nullable();
                $t->unsignedBigInteger('payment_run_id')->nullable();
                $t->string('message', 500)->nullable();
                $t->dateTime('created_at')->nullable();
                $t->dateTime('updated_at')->nullable();
                $t->unique(['family_id', 'period_start'], 'bsr_family_period');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_schedule_runs');
    }
};
