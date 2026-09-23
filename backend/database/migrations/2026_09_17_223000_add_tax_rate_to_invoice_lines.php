<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-line tax, so an added charge can carry it and the invoice can be RECOMPUTED.
 *
 * Anthony, 2026-09-17: "add ability to add a line item to add or deduct additional
 * charges with a description and to add optional tax."
 *
 * WHY A COLUMN AND NOT A SEPARATE TAX LINE. invoices.tax_amount is a single figure for
 * the whole invoice. Without knowing which line produced which part of it, removing a
 * taxed line later could only guess at how much tax to take back off — and a guess in
 * that direction either leaves a family owing tax on a charge that no longer exists or
 * quietly writes off real tax. Storing the rate beside the line makes every total
 * derivable from the lines alone, so recalculate() can rebuild subtotal, tax and total
 * from scratch every time rather than adjusting figures incrementally.
 *
 * NULL means "no tax on this line", which is what every existing line is — tuition in
 * Ontario childcare is exempt. Nothing is backfilled and no total changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('invoice_lines', 'tax_rate')) {
            return;
        }
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->decimal('tax_rate', 5, 2)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('invoice_lines', 'tax_rate')) {
            return;
        }
        Schema::table('invoice_lines', function (Blueprint $t) {
            $t->dropColumn('tax_rate');
        });
    }
};
