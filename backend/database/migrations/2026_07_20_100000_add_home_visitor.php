<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Home Visitor role — a practitioner attached to an AGENCY (not a single
 * centre) who visits families and files home-visit reports. They pick any
 * centre in their agency from a dropdown when logging a report.
 *   1) extend the role_assignments enum to accept 'home_visitor'
 *   2) create home_visit_reports
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) Add 'home_visitor' to the role enum (keep all existing values).
        DB::statement("ALTER TABLE role_assignments MODIFY COLUMN role ENUM(
            'agency_admin','centre_director','educator','guardian','auditor','platform_admin','home_visitor'
        ) NOT NULL");

        // 2) Home-visit reports.
        if (! Schema::hasTable('home_visit_reports')) {
            Schema::create('home_visit_reports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();   // chosen from the agency dropdown
                $t->unsignedBigInteger('home_visitor_id')->index();          // users.id (author)
                $t->unsignedBigInteger('family_id')->nullable()->index();
                $t->unsignedBigInteger('child_id')->nullable()->index();
                $t->string('family_name')->nullable();      // free-text if not linked to a record
                $t->string('child_name')->nullable();
                $t->date('visit_date');
                $t->string('visit_type', 40)->default('routine'); // initial|routine|follow_up|assessment|other
                $t->string('location', 40)->nullable();      // home|virtual|community|other
                $t->unsignedInteger('duration_minutes')->nullable();
                $t->string('present')->nullable();           // who was present
                $t->text('summary')->nullable();             // what happened
                $t->text('strengths')->nullable();
                $t->text('concerns')->nullable();
                $t->text('next_steps')->nullable();
                $t->date('follow_up_date')->nullable();
                $t->string('status', 20)->default('submitted'); // draft|submitted
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('home_visit_reports');
        // Leave the enum extended — narrowing it could fail if rows use the value.
    }
};
