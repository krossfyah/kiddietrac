<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 7 (2026-11-27): health & safety.
 *
 * - hr_risk_assessments: one per activity/area of a centre. items = JSON list of
 *   {hazard, who, likelihood 1-5, severity 1-5, controls, residual_likelihood, residual_severity}.
 *   status draft | signed (a director of the centre or an admin signed it, never the person who last
 *   edited it) | archived. Any edit after signing returns it to draft. review_on drives reminders.
 *   is_example = made from a starter template and not changed yet.
 * - hr_chemicals: the WHMIS inventory per centre. The safety data sheet is a private file
 *   (ProtectedMedia folder safety-files), sds_date = the date printed on the SDS, sds_review_on = when to check it.
 * - hr_safety_duties: named health & safety duties (first aider, fire warden ...), per person, at one centre
 *   or agency-wide (centre_id null), with the certificate type the duty needs (checked against
 *   staff_certifications at read time, never copied).
 * - tasks: category ('hs' = a health & safety task), evidence_required, the evidence file + note + who/when.
 *   An H&S task cannot be marked done without its evidence (TaskController + HrSafetyController).
 * - centre_hazards: an optional photo (private, ProtectedMedia folder safety-files).
 * - reminded_for / alerted_for: the last reminder step sent, so a reminder goes once per step.
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_risk_assessments')) {
            Schema::create('hr_risk_assessments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->string('title', 200);
                $t->string('area', 160)->nullable();
                $t->string('template_key', 40)->nullable();
                $t->boolean('is_example')->default(false);
                $t->longText('items')->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('owner_user_id')->nullable();
                $t->date('review_on')->nullable();
                $t->string('status', 12)->default('draft');
                $t->unsignedBigInteger('prepared_by')->nullable();
                $t->unsignedBigInteger('signed_by')->nullable();
                $t->timestamp('signed_at')->nullable();
                $t->string('signed_name', 190)->nullable();
                $t->string('reminded_for', 40)->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_chemicals')) {
            Schema::create('hr_chemicals', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->index();
                $t->string('product', 200);
                $t->string('supplier', 200)->nullable();
                $t->string('location', 200)->nullable();
                $t->string('hazard_classes', 400)->nullable();   // JSON list of WHMIS pictogram keys
                $t->string('quantity', 80)->nullable();
                $t->text('notes')->nullable();
                $t->string('sds_path', 255)->nullable();
                $t->string('sds_name', 180)->nullable();
                $t->string('sds_mime', 100)->nullable();
                $t->unsignedInteger('sds_size')->nullable();
                $t->date('sds_date')->nullable();
                $t->date('sds_review_on')->nullable();
                $t->string('reminded_for', 40)->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_safety_duties')) {
            Schema::create('hr_safety_duties', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('duty', 24);
                $t->string('label', 120)->nullable();
                $t->string('cert_type', 40)->nullable();
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->string('notes', 500)->nullable();
                $t->string('alerted_for', 40)->nullable();
                $t->timestamp('ended_at')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
        Schema::table('tasks', function (Blueprint $t) {
            if (! Schema::hasColumn('tasks', 'category')) $t->string('category', 20)->nullable()->index();
            if (! Schema::hasColumn('tasks', 'evidence_required')) $t->boolean('evidence_required')->default(false);
            if (! Schema::hasColumn('tasks', 'evidence_path')) $t->string('evidence_path', 255)->nullable();
            if (! Schema::hasColumn('tasks', 'evidence_name')) $t->string('evidence_name', 180)->nullable();
            if (! Schema::hasColumn('tasks', 'evidence_mime')) $t->string('evidence_mime', 100)->nullable();
            if (! Schema::hasColumn('tasks', 'evidence_note')) $t->string('evidence_note', 1000)->nullable();
            if (! Schema::hasColumn('tasks', 'evidence_by')) $t->unsignedBigInteger('evidence_by')->nullable();
            if (! Schema::hasColumn('tasks', 'evidence_at')) $t->timestamp('evidence_at')->nullable();
        });
        Schema::table('centre_hazards', function (Blueprint $t) {
            if (! Schema::hasColumn('centre_hazards', 'photo_path')) $t->string('photo_path', 255)->nullable();
            if (! Schema::hasColumn('centre_hazards', 'photo_name')) $t->string('photo_name', 180)->nullable();
            if (! Schema::hasColumn('centre_hazards', 'photo_mime')) $t->string('photo_mime', 100)->nullable();
        });
    }

    public function down(): void
    {
        // Additive; nothing is dropped automatically (records of safety work are kept).
    }
};
