<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vacation hold credits that reach the invoice (2026-11-28, App\Support\VacationCredits).
 *
 * vacation_holds gains how its credit was worked out (the agency's rule at approval: mode, rate, scheduled days) and
 * where it stands: credit_state pending (approved, waits for the hold to end and the next invoice) | applied (fully on
 * invoices) | none (no credit: rule "none" or $0) | reversed (cancelled before invoicing). Rows decided before this
 * deploy keep credit_state NULL and are never put on an invoice by themselves (their old amount was the $30/day guess).
 *
 * vacation_hold_credit_lines: the invoice line each credit went on. UNIQUE (hold, invoice) and the locked hold row are
 * the guard against crediting twice; a line on a voided invoice no longer counts, so the credit returns to the next one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vacation_holds', function (Blueprint $t) {
            if (! Schema::hasColumn('vacation_holds', 'credit_state')) $t->string('credit_state', 12)->nullable()->after('credit_amount');
            if (! Schema::hasColumn('vacation_holds', 'credit_mode')) $t->string('credit_mode', 10)->nullable()->after('credit_state');
            if (! Schema::hasColumn('vacation_holds', 'credit_rate')) $t->decimal('credit_rate', 10, 2)->nullable()->after('credit_mode');
            if (! Schema::hasColumn('vacation_holds', 'credit_days')) $t->unsignedInteger('credit_days')->nullable()->after('credit_rate');
            if (! Schema::hasColumn('vacation_holds', 'credit_override')) $t->boolean('credit_override')->default(false)->after('credit_days');
            if (! Schema::hasColumn('vacation_holds', 'cancelled_at')) $t->dateTime('cancelled_at')->nullable()->after('decided_at');
            if (! Schema::hasColumn('vacation_holds', 'cancelled_by_id')) $t->unsignedBigInteger('cancelled_by_id')->nullable()->after('cancelled_at');
        });
        if (! Schema::hasTable('vacation_hold_credit_lines')) {
            Schema::create('vacation_hold_credit_lines', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('hold_id');
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('invoice_id');
                $t->unsignedBigInteger('invoice_line_id');
                $t->decimal('amount', 10, 2);
                $t->timestamp('created_at')->nullable();
                $t->unique(['hold_id', 'invoice_id'], 'uniq_vhcl_hold_invoice');
                $t->index('invoice_id', 'idx_vhcl_invoice');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vacation_hold_credit_lines');
        Schema::table('vacation_holds', function (Blueprint $t) {
            foreach (['credit_state', 'credit_mode', 'credit_rate', 'credit_days', 'credit_override', 'cancelled_at', 'cancelled_by_id'] as $c) {
                if (Schema::hasColumn('vacation_holds', $c)) $t->dropColumn($c);
            }
        });
    }
};
