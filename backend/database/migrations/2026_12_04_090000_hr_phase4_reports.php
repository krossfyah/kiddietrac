<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 5 (2026-11-27): reports, probation, missing-info check.
 *
 * - hr_employee_files.probation_end_on: an override of the probation end (null = start date + the agency's probation
 *   length, agencies.settings.hr_compliance.probation_days). probation_passed_on: the manager confirmed it.
 *   probation_reminded_for: the end date the manager was last reminded about (a new end date re-arms the reminder).
 * - hr_leave_types.payout_on_leaving: is a balance of this type paid out when someone leaves? null = the default
 *   (the type that old Vacation requests map to, and the Overtime bank). Used by the leaving payout and leave liability.
 * Additive and idempotent. No new tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_employee_files', function (Blueprint $t) {
            if (! Schema::hasColumn('hr_employee_files', 'probation_end_on')) $t->date('probation_end_on')->nullable();
            if (! Schema::hasColumn('hr_employee_files', 'probation_passed_on')) $t->date('probation_passed_on')->nullable();
            if (! Schema::hasColumn('hr_employee_files', 'probation_reminded_for')) $t->date('probation_reminded_for')->nullable();
        });
        Schema::table('hr_leave_types', function (Blueprint $t) {
            if (! Schema::hasColumn('hr_leave_types', 'payout_on_leaving')) $t->boolean('payout_on_leaving')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_files', function (Blueprint $t) {
            foreach (['probation_end_on', 'probation_passed_on', 'probation_reminded_for'] as $c) {
                if (Schema::hasColumn('hr_employee_files', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('hr_leave_types', function (Blueprint $t) {
            if (Schema::hasColumn('hr_leave_types', 'payout_on_leaving')) $t->dropColumn('payout_on_leaving');
        });
    }
};
