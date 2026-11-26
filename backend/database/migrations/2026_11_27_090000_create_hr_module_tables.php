<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC HR, PHASE 1 (2026-11-27, Anthony approved the "KiddieTrac HR Module Scope").
 *
 * - hr_leave_types / hr_leave_rules: each agency's own leave types and entitlement rules. Nothing is built in and
 *   nothing is created here: there is no provincial law and no ESA default (Anthony's decision).
 * - hr_leave_ledger: every change to a balance, never edited in place. Balance = SUM(amount).
 * - hr_employee_files: the employee file fields KiddieTrac did not have (start date stays users.hire_date).
 * - hr_staff_sin: the SIN, encrypted, in its own table (the staff_payout_methods *_enc + *_hint pattern).
 * - time_off_requests gains two NULLABLE columns. Existing requests are not touched: their request_type maps onto an
 *   agency's leave types through hr_leave_types.maps_request_type when the agency sets that up.
 *
 * Idempotent (hasTable / hasColumn) and additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_leave_types')) {
            Schema::create('hr_leave_types', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('name', 80);
                $t->string('colour', 9)->default('#0E7490');
                $t->boolean('paid')->default(true);
                $t->string('unit', 8)->default('days');                 // days | hours
                $t->boolean('visible_to_staff')->default(true);
                $t->boolean('needs_approval')->default(true);
                $t->string('maps_request_type', 20)->nullable();        // vacation|sick|personal|bereavement|jury|other
                $t->string('accrual', 16)->default('lump');             // lump | pay_period | hours_worked | none
                $t->unsignedSmallInteger('periods_per_year')->nullable(); // 12 | 24 | 26 | 52
                $t->decimal('hours_per_unit', 8, 2)->nullable();        // hours worked to earn 1 unit
                $t->string('carry_mode', 8)->default('none');           // none | all | capped
                $t->decimal('carry_cap', 8, 2)->nullable();
                $t->unsignedSmallInteger('carry_expiry_months')->nullable();
                $t->boolean('is_example')->default(false);
                $t->integer('sort')->default(0);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'name']);
            });
        }

        if (! Schema::hasTable('hr_leave_rules')) {
            Schema::create('hr_leave_rules', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('leave_type_id')->index();
                $t->string('employment_type', 16)->nullable();          // null = any
                $t->decimal('min_years', 5, 2)->default(0);
                $t->decimal('amount', 8, 2);                            // per leave year, in the type's unit
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('hr_leave_ledger')) {
            Schema::create('hr_leave_ledger', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('leave_type_id');
                $t->date('entry_date');
                $t->decimal('amount', 9, 2);
                $t->string('kind', 16);   // grant|accrual|carry_forfeit|expiry|adjustment|taken|returned
                $t->unsignedBigInteger('time_off_request_id')->nullable()->index();
                $t->string('source_key', 60)->nullable();
                $t->string('reason', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['user_id', 'leave_type_id']);
                // One accrual per period/grant: a rerun of the nightly job adds nothing.
                $t->unique(['leave_type_id', 'user_id', 'source_key'], 'hr_ledger_source_unique');
            });
        }

        if (! Schema::hasTable('hr_employee_files')) {
            Schema::create('hr_employee_files', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->string('job_title', 120)->nullable();
                $t->string('employment_type', 16)->nullable();          // full_time|part_time|casual|student|contract
                $t->string('status', 16)->default('active');            // active|on_leave|terminated
                $t->date('end_date')->nullable();
                $t->string('end_reason', 255)->nullable();
                $t->unsignedBigInteger('manager_user_id')->nullable();
                $t->unsignedBigInteger('primary_centre_id')->nullable();
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('hr_staff_sin')) {
            Schema::create('hr_staff_sin', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->text('sin_enc');
                $t->string('sin_hint', 16);
                $t->unsignedBigInteger('updated_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id']);
            });
        }

        if (Schema::hasTable('time_off_requests')) {
            if (! Schema::hasColumn('time_off_requests', 'leave_type_id')) {
                Schema::table('time_off_requests', function (Blueprint $t) {
                    $t->unsignedBigInteger('leave_type_id')->nullable()->after('request_type');
                });
            }
            if (! Schema::hasColumn('time_off_requests', 'leave_amount')) {
                Schema::table('time_off_requests', function (Blueprint $t) {
                    $t->decimal('leave_amount', 8, 2)->nullable()->after('leave_type_id');
                });
            }
        }
    }

    public function down(): void
    {
        // Additive and holding people's records: never dropped by a rollback.
    }
};
