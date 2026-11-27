<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KIDDIETRAC PAYROLL ENGINE, PHASE 2 (2026-11-27): pay runs, their lines, and opening year-to-date balances.
 *
 *  - payroll_runs: one pay run (draft -> review -> approved -> finalized; or discarded while not finalized). kind regular |
 *    adjustment (a correction to a finalized run: adjusts_run_id). A finalized run is never edited.
 *  - payroll_run_lines: one person in a run. Stores what went in (hours from HrTime, rate, extras, the calculator inputs,
 *    the year to date before this pay) and what came out (every deduction, net, employer side, the table version), so a
 *    past pay can always be explained without recalculating.
 *  - payroll_ytd_openings: what an agency that starts mid-year already paid someone this tax year elsewhere (so the CPP /
 *    EI maximums and the year-end slips are right). One row per person per tax year.
 *  - payroll_documents gains payroll_run_id + breakdown (JSON) for the payslips a run writes (source = engine). Every
 *    existing row keeps NULL in both, so nothing that reads payroll_documents today changes.
 * Country-neutral (country column, jurisdiction codes) so the US phase slots in.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payroll_runs')) {
            Schema::create('payroll_runs', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id')->nullable();        // null = every centre (admins only)
                $t->char('country', 2)->default('CA');
                $t->string('reference', 32)->nullable();               // PR-2026-07
                $t->string('kind', 12)->default('regular');            // regular | adjustment
                $t->unsignedBigInteger('adjusts_run_id')->nullable();
                $t->date('period_start');
                $t->date('period_end');
                $t->date('pay_date');
                $t->unsignedSmallInteger('periods');                   // pay periods a year (52, 26, 24, 12)
                $t->string('status', 12)->default('draft');            // draft | review | approved | finalized | discarded
                $t->json('totals')->nullable();
                $t->string('notes', 1000)->nullable();
                $t->string('return_reason', 500)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->unsignedBigInteger('submitted_by_id')->nullable();
                $t->timestamp('submitted_at')->nullable();
                $t->unsignedBigInteger('approved_by_id')->nullable();
                $t->timestamp('approved_at')->nullable();
                $t->unsignedBigInteger('finalized_by_id')->nullable();
                $t->timestamp('finalized_at')->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'status']);
                $t->index(['agency_id', 'pay_date']);
            });
        }
        if (! Schema::hasTable('payroll_run_lines')) {
            Schema::create('payroll_run_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('run_id');
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->boolean('included')->default(true);
                $t->string('state', 12)->default('ok');                // ok | warning | error
                $t->string('pay_type', 12)->nullable();                // hourly | salary
                $t->decimal('rate', 12, 2)->nullable();
                $t->unsignedInteger('minutes')->default(0);            // paid minutes from HrTime
                $t->decimal('hours', 8, 2)->default(0);
                $t->decimal('regular', 12, 2)->default(0);
                $t->decimal('overtime', 12, 2)->default(0);
                $t->decimal('vacation_pay', 12, 2)->default(0);
                $t->decimal('bonus', 12, 2)->default(0);               // non-periodic (bonus, retro): typed in the draft
                $t->decimal('benefits_cash', 12, 2)->default(0);
                $t->decimal('benefits_noncash', 12, 2)->default(0);
                $t->decimal('reimbursements', 12, 2)->default(0);      // HR claims: not taxable, added to net
                $t->decimal('other_deduction', 12, 2)->default(0);     // after tax (e.g. an overpayment being recovered)
                $t->string('other_label', 120)->nullable();
                $t->decimal('gross', 12, 2)->default(0);
                $t->decimal('cpp', 12, 2)->default(0);                 // CPP or QPP base
                $t->decimal('cpp2', 12, 2)->default(0);
                $t->decimal('ei', 12, 2)->default(0);
                $t->decimal('qpip', 12, 2)->default(0);
                $t->decimal('federal_tax', 12, 2)->default(0);
                $t->decimal('provincial_tax', 12, 2)->default(0);      // Quebec tax for QC
                $t->decimal('income_tax', 12, 2)->default(0);
                $t->decimal('net', 12, 2)->default(0);
                $t->decimal('employer_cpp', 12, 2)->default(0);
                $t->decimal('employer_cpp2', 12, 2)->default(0);
                $t->decimal('employer_ei', 12, 2)->default(0);
                $t->decimal('employer_qpip', 12, 2)->default(0);
                $t->string('jurisdiction', 8)->nullable();
                $t->string('table_version', 64)->nullable();
                $t->json('extras')->nullable();                        // overtime + claim lines this pay carries, hours detail
                $t->json('inputs')->nullable();                        // the exact CaCalculator input
                $t->json('result')->nullable();                        // factors, editions, warnings
                $t->json('ytd_before')->nullable();
                $t->string('ytd_hash', 40)->nullable();                // finalize refuses if the year to date moved since
                $t->json('warnings')->nullable();
                $t->string('error', 500)->nullable();
                $t->string('note', 500)->nullable();
                $t->unsignedBigInteger('payroll_document_id')->nullable();
                $t->timestamps();
                $t->unique(['run_id', 'user_id']);
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('payroll_ytd_openings')) {
            Schema::create('payroll_ytd_openings', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedSmallInteger('tax_year');
                $t->date('as_of');                                     // paid up to and including this date elsewhere
                $t->decimal('gross', 12, 2)->default(0);
                $t->decimal('pensionable', 12, 2)->default(0);
                $t->decimal('insurable', 12, 2)->default(0);
                $t->decimal('cpp', 12, 2)->default(0);
                $t->decimal('cpp2', 12, 2)->default(0);
                $t->decimal('ei', 12, 2)->default(0);
                $t->decimal('qpip', 12, 2)->default(0);
                $t->decimal('income_tax', 12, 2)->default(0);
                $t->decimal('bonus', 12, 2)->default(0);
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('entered_by_id')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id', 'tax_year']);
            });
        }
        if (! Schema::hasColumn('payroll_documents', 'payroll_run_id')) {
            Schema::table('payroll_documents', function (Blueprint $t) {
                $t->unsignedBigInteger('payroll_run_id')->nullable()->after('source');
                $t->longText('breakdown')->nullable()->after('lines');
                $t->index('payroll_run_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payroll_documents', 'payroll_run_id')) {
            Schema::table('payroll_documents', function (Blueprint $t) {
                $t->dropIndex(['payroll_run_id']);
                $t->dropColumn(['payroll_run_id', 'breakdown']);
            });
        }
        Schema::dropIfExists('payroll_ytd_openings');
        Schema::dropIfExists('payroll_run_lines');
        Schema::dropIfExists('payroll_runs');
    }
};
