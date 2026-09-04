<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items on a payslip.
 *
 * A payslip has always been able to say what somebody was paid; it could not say what
 * the payment was MADE OF. A mileage reimbursement, a stat-holiday top-up, a uniform
 * deduction and a bonus all collapsed into one gross figure, and the only place the
 * detail survived was somebody's memory.
 *
 * JSON rather than a lines table: a payslip is issued once and read afterwards. It is
 * never queried across — nobody asks "every mileage line this year" of this table, the
 * payroll report does that from its own source — so a row per line would buy joins
 * nobody needs and a second thing to keep in step with the document it belongs to.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            // [{label, amount, kind: earning|deduction}] — what the gross is made of.
            $table->json('lines')->nullable()->after('benefits');
            /* The punches the hours were computed from, kept as evidence. A figure that
               cannot be traced back to the shifts behind it is a figure somebody has to
               take on trust, and payroll is the last place for that. */
            $table->json('hours_detail')->nullable()->after('lines');
        });
    }

    public function down(): void
    {
        Schema::table('payroll_documents', function (Blueprint $table) {
            $table->dropColumn(['lines', 'hours_detail']);
        });
    }
};
