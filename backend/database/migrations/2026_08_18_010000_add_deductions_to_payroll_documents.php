<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deductions, and payees who have no KiddieTrac account.
 *
 * KiddieTrac's own payslips are gross-only — it holds hours, not a payroll engine. iLearn
 * does run payroll: it records CPP, EI, income tax, vacation and benefits, and pays net.
 * Importing that history into a gross-only table would quietly drop the deductions, and a
 * payslip missing its CPP and EI is worse than no payslip.
 *
 * user_id becomes nullable because three of iLearn's payees have no KiddieTrac account at
 * all. Their documents are still part of the agency's payroll history and must appear in
 * the admin ledger; they simply have no self-view to appear in. Dropping them instead
 * would understate what the agency actually paid out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            // Nullable throughout: absent means "not modelled", which is different from zero.
            $table->decimal('cpp', 12, 2)->nullable()->after('gross');
            $table->decimal('ei', 12, 2)->nullable()->after('cpp');
            $table->decimal('income_tax', 12, 2)->nullable()->after('ei');
            $table->decimal('other_deductions', 12, 2)->nullable()->after('income_tax');
            $table->decimal('vacation_pay', 12, 2)->nullable()->after('other_deductions');
            $table->decimal('net', 12, 2)->nullable()->after('vacation_pay');
            $table->text('benefits')->nullable()->after('net');
            $table->string('payee_email', 190)->nullable()->after('payee_name');
            $table->string('pay_frequency', 32)->nullable()->after('unit_label');
        });

        // Two statements rather than one Blueprint change(): dropping and re-adding the
        // index around a nullable change is what actually breaks on MySQL here.
        DB::statement('ALTER TABLE payroll_documents MODIFY user_id BIGINT UNSIGNED NULL');

        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->index('payee_email');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->dropIndex(['payee_email']);
            $table->dropColumn([
                'cpp', 'ei', 'income_tax', 'other_deductions', 'vacation_pay',
                'net', 'benefits', 'payee_email', 'pay_frequency',
            ]);
        });
    }
};
