<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 3 (2026-11-27): Canadian year end.
 *
 *  - payroll_slips: one tax slip (T4, T4A or RL-1) for one person, one tax year and one province of employment. Amounts
 *    only, by CRA / Revenu Quebec box (boxes JSON); the SIN is NEVER stored here (it is read from hr_staff_sin only when
 *    the person's slip PDF or the XML file is drawn). A slip is draft -> final; a final slip is never edited: an
 *    amendment (report_type A) or cancellation (C) is a new row (version + 1, replaces_slip_id) and the slip it replaces
 *    becomes replaced / cancelled when the amendment is finalized. sources = where every dollar came from (finalized
 *    engine lines, the opening balance, integration payslip ids) so the reconciliation can prove nothing is counted twice.
 *  - payroll_year_ends: one row per agency + tax year: when the slips were generated / finalized and by whom, the last
 *    reconciliation (JSON).
 *  - payroll_xml_exports: every CRA XML file handed out (who, when, which slips, the submission reference, a SHA-256 of
 *    the file). The file itself is never kept (it holds full SINs); it is rebuilt from the final slips on download.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_slips')) {
            Schema::create('payroll_slips', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedSmallInteger('tax_year');
                $t->char('country', 2)->default('CA');
                $t->string('kind', 8);                                   // T4 | T4A | RL1
                $t->unsignedBigInteger('user_id');
                $t->string('jurisdiction', 4)->nullable();               // T4 box 10 / RL-1 = QC; null on a T4A
                $t->unsignedBigInteger('centre_id')->nullable();         // the centre most of the year's pay was filed to (directors' scope)
                $t->string('status', 12)->default('draft');              // draft | final | replaced | cancelled | discarded
                $t->char('report_type', 1)->default('O');                // O original | A amended | C cancelled
                $t->unsignedSmallInteger('version')->default(1);
                $t->unsignedBigInteger('replaces_slip_id')->nullable();
                $t->string('surname', 100)->nullable();
                $t->string('given_name', 100)->nullable();
                $t->json('boxes')->nullable();
                $t->json('codes')->nullable();                           // box 28 exemptions, 29, 45 dental, 50
                $t->json('other_info')->nullable();                      // [{code, amount}] (T4 boxes 30-99)
                $t->json('manual')->nullable();                          // what an admin typed (union dues, donations, PA, dental, recipient BN)
                $t->json('employer')->nullable();                        // employer CPP / CPP2 / EI / QPIP shares (for the summary)
                $t->json('sources')->nullable();
                $t->json('warnings')->nullable();
                $t->string('reason', 500)->nullable();                   // why it was amended / cancelled
                $t->unsignedBigInteger('generated_by_id')->nullable();
                $t->unsignedBigInteger('finalized_by_id')->nullable();
                $t->timestamp('finalized_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'tax_year', 'kind', 'status']);
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('payroll_year_ends')) {
            Schema::create('payroll_year_ends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedSmallInteger('tax_year');
                $t->string('status', 12)->default('draft');              // draft | final
                $t->unsignedBigInteger('generated_by_id')->nullable();
                $t->timestamp('generated_at')->nullable();
                $t->unsignedBigInteger('finalized_by_id')->nullable();
                $t->timestamp('finalized_at')->nullable();
                $t->json('reconciliation')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'tax_year']);
            });
        }
        if (! Schema::hasTable('payroll_xml_exports')) {
            Schema::create('payroll_xml_exports', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedSmallInteger('tax_year');
                $t->string('kind', 8);                                   // T4 | T4A
                $t->char('report_type', 1);                              // O | A
                $t->string('sbmt_ref_id', 8);
                $t->string('schema_version', 16);
                $t->json('slip_ids');
                $t->char('sha256', 64);
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'tax_year']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_xml_exports');
        Schema::dropIfExists('payroll_year_ends');
        Schema::dropIfExists('payroll_slips');
    }
};
