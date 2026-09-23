<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Recurring billing, multi-currency and tax for platform invoices.
 *
 * Two deliberate shape decisions:
 *
 *  - amount_cents stays the TOTAL (subtotal + tax). Every existing reader — the MRR
 *    dashboard, the screen tiles, the reminder command, the PDF — already treats it as
 *    the amount owed. Redefining it as a pre-tax subtotal would silently under-report
 *    every one of them. The new subtotal_cents/tax_cents sit alongside it.
 *  - Tax is stored in BASIS POINTS, not a float. 13% is 1300. Percentages held as
 *    floats accumulate rounding error, and tax that is a cent off is a real problem.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            if (! Schema::hasColumn('agencies', 'billing_interval')) {
                // monthly | quarterly | annual — how often this agency is invoiced.
                $t->string('billing_interval', 12)->default('monthly')->after('billing_starts_at');
            }
            if (! Schema::hasColumn('agencies', 'next_invoice_at')) {
                /* The schedule lives here, not in a cron expression. Null means this
                   agency is not on recurring billing and will never be auto-raised. */
                $t->date('next_invoice_at')->nullable()->after('billing_interval');
            }
            if (! Schema::hasColumn('agencies', 'tax_rate_bps')) {
                $t->integer('tax_rate_bps')->default(0)->after('next_invoice_at');
            }
            if (! Schema::hasColumn('agencies', 'tax_label')) {
                // What the tax is CALLED on the invoice: HST, GST, VAT, Sales tax.
                $t->string('tax_label', 40)->nullable()->after('tax_rate_bps');
            }
            if (! Schema::hasColumn('agencies', 'tax_registration')) {
                // Our registration number, printed on invoices for this jurisdiction.
                $t->string('tax_registration', 60)->nullable()->after('tax_label');
            }
        });

        Schema::table('platform_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_invoices', 'subtotal_cents')) {
                $t->integer('subtotal_cents')->default(0)->after('amount_cents');
            }
            if (! Schema::hasColumn('platform_invoices', 'tax_cents')) {
                $t->integer('tax_cents')->default(0)->after('subtotal_cents');
            }
            if (! Schema::hasColumn('platform_invoices', 'tax_rate_bps')) {
                $t->integer('tax_rate_bps')->default(0)->after('tax_cents');
            }
            if (! Schema::hasColumn('platform_invoices', 'tax_label')) {
                $t->string('tax_label', 40)->nullable()->after('tax_rate_bps');
            }
        });

        /* Existing invoices predate tax: their total IS their subtotal. Without this
           backfill they would render with a 0.00 subtotal under a non-zero total. */
        \Illuminate\Support\Facades\DB::table('platform_invoices')
            ->where('subtotal_cents', 0)
            ->where('amount_cents', '>', 0)
            ->update(['subtotal_cents' => \Illuminate\Support\Facades\DB::raw('amount_cents')]);
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn(['billing_interval', 'next_invoice_at', 'tax_rate_bps', 'tax_label', 'tax_registration']);
        });
        Schema::table('platform_invoices', function (Blueprint $t) {
            $t->dropColumn(['subtotal_cents', 'tax_cents', 'tax_rate_bps', 'tax_label']);
        });
    }
};
