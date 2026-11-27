<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 4 (2026-11-27): the employee document vault, pay + role history, the leaving pack.
 *
 * - hr_doc_folders: the agency's own company folders (handbook, policies...). visibility admins|everyone|people
 *   (+ people_ids). A new folder is admins-only until someone opens it up.
 * - hr_documents: one file. user_id set = on that person's HR file (visibility employee|managers); folder_id set =
 *   a company document (the folder decides who sees it). The file itself lives under ProtectedMedia 'hr-documents'
 *   (never served without a signature). Sick notes are doc_type sick_note + time_off_request_id + the dates covered;
 *   they carry NO free text, so no health detail is ever stored outside the image the person chose to share.
 * - hr_document_receipts: who opened / accepted a company document.  hr_document_alerts: which reminder went (once each).
 * - hr_pay_changes: pay / role / hours changes with an effective date; future ones apply themselves on the day
 *   (hr:file-tasks). before = what it replaced, written when it applied. Never edited after it applied.
 * - hr_leavings: one leaving (checklist, ROE prep facts, leave payout). Undo keeps the row (status undone);
 *   a returning employee keeps the old row (rehired_on) and starts a new employment.
 * - hr_employee_files.hours_per_week: contracted hours (part of the history).
 * Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_doc_folders')) {
            Schema::create('hr_doc_folders', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('name', 120);
                $t->string('visibility', 12)->default('admins');
                $t->json('people_ids')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_documents')) {
            Schema::create('hr_documents', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->nullable()->index();
                $t->unsignedBigInteger('folder_id')->nullable()->index();
                $t->string('doc_type', 24)->default('other');
                $t->string('title', 200);
                $t->string('file_path', 255);
                $t->string('file_name', 180)->nullable();
                $t->string('file_mime', 100)->nullable();
                $t->unsignedInteger('file_size')->default(0);
                $t->string('visibility', 12)->nullable();
                $t->boolean('requires_accept')->default(false);
                $t->date('expires_on')->nullable();
                $t->date('follow_up_on')->nullable();
                $t->string('follow_up_note', 255)->nullable();
                $t->unsignedBigInteger('time_off_request_id')->nullable()->index();
                $t->date('covers_from')->nullable();
                $t->date('covers_to')->nullable();
                $t->unsignedBigInteger('uploaded_by_id')->nullable();
                $t->timestamp('archived_at')->nullable();
                $t->unsignedBigInteger('archived_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('hr_document_receipts')) {
            Schema::create('hr_document_receipts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('document_id');
                $t->unsignedBigInteger('user_id')->index();
                $t->timestamp('read_at')->nullable();
                $t->timestamp('accepted_at')->nullable();
                $t->unique(['document_id', 'user_id'], 'hr_doc_receipt_unique');
            });
        }
        if (! Schema::hasTable('hr_document_alerts')) {
            Schema::create('hr_document_alerts', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('document_id');
                $t->string('step_key', 16);
                $t->timestamp('sent_at')->nullable();
                $t->unique(['document_id', 'step_key'], 'hr_doc_alert_unique');
            });
        }
        if (! Schema::hasTable('hr_pay_changes')) {
            Schema::create('hr_pay_changes', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->date('effective_on');
                $t->json('changes');
                $t->json('before')->nullable();
                $t->string('reason', 255)->nullable();
                $t->string('status', 12)->default('scheduled');   // scheduled|applied|cancelled
                $t->string('source', 16)->default('change');      // change|file_edit
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamp('applied_at')->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->unsignedBigInteger('cancelled_by_id')->nullable();
                $t->timestamps();
                $t->index(['status', 'effective_on']);
            });
        }
        if (! Schema::hasTable('hr_leavings')) {
            Schema::create('hr_leavings', function (Blueprint $t) {
                $t->bigIncrements('id');
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('status', 12)->default('open');         // open|completed|undone
                $t->date('last_day')->nullable();
                $t->date('notice_on')->nullable();
                $t->string('reason_code', 2)->nullable();
                $t->string('reason_note', 500)->nullable();
                $t->json('checklist')->nullable();
                $t->json('payout')->nullable();
                $t->date('employment_start')->nullable();
                $t->unsignedBigInteger('started_by_id')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->unsignedBigInteger('completed_by_id')->nullable();
                $t->timestamp('undone_at')->nullable();
                $t->unsignedBigInteger('undone_by_id')->nullable();
                $t->string('undo_reason', 500)->nullable();
                $t->date('rehired_on')->nullable();
                $t->unsignedBigInteger('rehired_by_id')->nullable();
                $t->timestamps();
            });
        }
        if (Schema::hasTable('hr_employee_files') && ! Schema::hasColumn('hr_employee_files', 'hours_per_week')) {
            Schema::table('hr_employee_files', function (Blueprint $t) {
                $t->decimal('hours_per_week', 5, 2)->nullable()->after('employment_type');
            });
        }
    }

    public function down(): void
    {
        foreach (['hr_leavings', 'hr_pay_changes', 'hr_document_alerts', 'hr_document_receipts', 'hr_documents', 'hr_doc_folders'] as $t) {
            Schema::dropIfExists($t);
        }
        if (Schema::hasColumn('hr_employee_files', 'hours_per_week')) {
            Schema::table('hr_employee_files', fn (Blueprint $t) => $t->dropColumn('hours_per_week'));
        }
    }
};
