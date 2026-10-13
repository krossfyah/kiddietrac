<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* ONTARIO ECE WORKFORCE FUNDING (2026-10-13) — WEG + CWELCC Workforce Compensation.

   workforce_wage_components  one row per person per effective date: base wage, 2024 GOF
                              enhancement kept, and optional overrides of the computed
                              WEG / WCF increase / wage-floor top-up / wage stability.
                              Never deleted: a changed wage is a new row (history is the
                              record the funder audits), an edit is audited.
   workforce_funding_receipts what the agency was paid by its CMSM/DSSAB, and when — the
                              start of the 32-day clock. Voided, never deleted.
   workforce_pay_lines        the component lines actually put on each payslip (hours x
                              rate = amount), the source of the year-end reconciliation.

   Records must be kept seven years (Toronto WEG terms (g)); nothing purges these tables. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('workforce_wage_components')) {
            Schema::create('workforce_wage_components', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->date('effective_from');
                $t->date('effective_to')->nullable();
                $t->decimal('base_wage', 8, 2)->nullable();
                $t->decimal('gof', 8, 2)->default(0);
                $t->string('eligibility_override', 24)->nullable();
                $t->decimal('weg_override', 8, 2)->nullable();
                $t->decimal('wcf_increase_override', 8, 2)->nullable();
                $t->decimal('floor_override', 8, 2)->nullable();
                $t->decimal('stability', 8, 2)->default(0);
                $t->string('notes', 1000)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('updated_by')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id', 'effective_from'], 'wwc_agency_user_from');
            });
        }
        if (! Schema::hasTable('workforce_funding_receipts')) {
            Schema::create('workforce_funding_receipts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->date('received_on');
                $t->decimal('amount', 12, 2);
                $t->string('stream', 12)->default('both');   // weg | wcf | both
                $t->date('period_from')->nullable();
                $t->date('period_to')->nullable();
                $t->string('reference', 120)->nullable();
                $t->string('notes', 1000)->nullable();
                $t->timestamp('voided_at')->nullable();
                $t->string('void_reason', 500)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'received_on']);
            });
        }
        if (! Schema::hasTable('workforce_pay_lines')) {
            Schema::create('workforce_pay_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('payroll_document_id')->index();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->date('period_start')->nullable();
                $t->date('period_end')->nullable();
                $t->date('paid_on')->nullable();
                $t->string('category', 24)->nullable();      // rece_program | rece_supervisor | hcc_visitor | non_rece | not_eligible
                $t->string('component', 16);                  // base | gof | weg | wcf_increase | wcf_floor | stability
                $t->decimal('hours', 10, 2)->nullable();
                $t->decimal('rate', 10, 2)->nullable();
                $t->decimal('amount', 12, 2);
                $t->timestamps();
                $t->index(['agency_id', 'period_end']);
            });
        }
    }

    public function down(): void
    {
        // Funding records are kept seven years; never dropped by a rollback.
    }
};
