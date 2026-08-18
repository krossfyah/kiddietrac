<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger of payroll documents that have been issued.
 *
 * Payslips were computed on the fly from punches and never stored, which means there was
 * no answer to "what did we issue this person, and when" — the one question a payroll
 * record exists to answer. A recomputed payslip also silently changes if a punch is later
 * edited or a rate is corrected, so last month's payslip stops matching what the person
 * was actually paid. Rows here are a snapshot: the units, rate and gross AS ISSUED.
 *
 * external_key makes the backfill idempotent. Re-running it updates rather than duplicates,
 * which matters because history will be imported more than once as more of it arrives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('centre_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();

            // Denormalised so a historical document keeps the role it was issued under,
            // even after somebody changes job.
            $table->string('staff_group', 16)->default('other');
            $table->string('role_label', 40)->nullable();
            $table->string('payee_name', 120)->nullable();

            $table->string('kind', 16)->default('payslip');   // payslip | invoice
            $table->string('reference', 64)->nullable();
            $table->date('period_start')->nullable()->index();
            $table->date('period_end')->nullable();

            $table->decimal('units', 10, 2)->default(0);
            $table->string('unit_label', 16)->default('hours');
            $table->decimal('rate', 10, 2)->default(0);
            $table->decimal('gross', 12, 2)->default(0);
            $table->string('currency', 8)->default('CAD');

            $table->string('status', 16)->default('issued');  // issued | paid | void
            $table->string('source', 16)->default('kiddietrac');
            $table->string('external_source', 32)->nullable();
            $table->string('external_id', 64)->nullable();
            $table->string('external_key', 120);

            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique('external_key');
            $table->index(['agency_id', 'staff_group', 'period_start']);
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_documents');
    }
};
