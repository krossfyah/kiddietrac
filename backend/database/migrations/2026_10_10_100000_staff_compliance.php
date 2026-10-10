<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* STAFF COMPLIANCE (2026-10-10, roadmap Phase 1, Anthony: "go ahead").
   - users.hire_date / users.registry_number: the date deadlines count from, and a staff
     registry number (Ohio Professional Registry OPIN) where the jurisdiction has one.
     Never backfilled: an unknown hire date stays unknown.
   - pd_hours: a professional development ledger (staff add their own; a director/admin
     approves), counted per the jurisdiction's training year.
   - household_residents + household_resident_checks: adults living in a home daycare, with
     their own background checks (Ohio checks every adult resident). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'hire_date')) $t->date('hire_date')->nullable();
            if (! Schema::hasColumn('users', 'registry_number')) $t->string('registry_number', 40)->nullable();
        });
        if (! Schema::hasTable('pd_hours')) {
            Schema::create('pd_hours', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->date('taken_on');
                $t->string('title', 190);
                $t->string('provider', 190)->nullable();
                $t->decimal('hours', 6, 2);
                $t->string('certificate_path', 500)->nullable();
                $t->string('certificate_name', 190)->nullable();
                $t->string('status', 12)->default('pending');   // pending | approved | rejected
                $t->unsignedBigInteger('reviewed_by')->nullable();
                $t->dateTime('reviewed_at')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->index(['user_id', 'taken_on']);
                $t->index(['agency_id', 'status']);
            });
        }
        if (! Schema::hasTable('household_residents')) {
            Schema::create('household_residents', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->string('full_name', 190);
                $t->string('relationship', 80)->nullable();
                $t->date('date_of_birth')->nullable();
                $t->date('moved_in_on')->nullable();
                $t->date('moved_out_on')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->dateTime('deleted_at')->nullable();
                $t->timestamps();
                $t->index('centre_id');
            });
        }
        if (! Schema::hasTable('household_resident_checks')) {
            Schema::create('household_resident_checks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('resident_id');
                $t->string('check_type', 40);
                $t->string('reference', 120)->nullable();
                $t->date('issued_at')->nullable();
                $t->date('expires_at')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by');
                $t->timestamps();
                $t->index('resident_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('household_resident_checks');
        Schema::dropIfExists('household_residents');
        Schema::dropIfExists('pd_hours');
        Schema::table('users', function (Blueprint $t) {
            if (Schema::hasColumn('users', 'registry_number')) $t->dropColumn('registry_number');
            if (Schema::hasColumn('users', 'hire_date')) $t->dropColumn('hire_date');
        });
    }
};
