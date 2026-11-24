<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* NSF, extra line items and payment-method surcharges on KiddieTrac invoices (2026-10-25). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('platform_invoice_lines', function (Blueprint $t) {
            // licence | late | nsf | surcharge | extra | manual (null = an older line: licence or plan)
            if (! Schema::hasColumn('platform_invoice_lines', 'kind')) $t->string('kind', 12)->nullable()->after('part_number');
            if (! Schema::hasColumn('platform_invoice_lines', 'tax_cents')) $t->integer('tax_cents')->default(0)->after('amount_cents');
            if (! Schema::hasColumn('platform_invoice_lines', 'payment_id')) $t->unsignedBigInteger('payment_id')->nullable()->after('licence_id');
            if (! Schema::hasColumn('platform_invoice_lines', 'extra_id')) $t->unsignedBigInteger('extra_id')->nullable()->after('payment_id');
        });
        Schema::table('platform_invoice_payments', function (Blueprint $t) {
            if (! Schema::hasColumn('platform_invoice_payments', 'returned_at')) $t->timestamp('returned_at')->nullable()->after('confirmed_at');
            if (! Schema::hasColumn('platform_invoice_payments', 'return_reason')) $t->string('return_reason', 255)->nullable()->after('returned_at');
        });
        if (! Schema::hasTable('platform_invoice_extras')) {
            Schema::create('platform_invoice_extras', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('description', 255);
                $t->string('part_number', 20)->nullable();
                $t->decimal('qty', 10, 2)->default(1);
                $t->integer('unit_cents');
                $t->boolean('taxable')->default(true);
                $t->boolean('recurring')->default(true);   // on every invoice, or only the next one
                $t->date('starts_on')->nullable();
                $t->date('ends_on')->nullable();
                $t->boolean('active')->default(true);
                $t->unsignedInteger('applied_count')->default(0);
                $t->unsignedBigInteger('last_invoice_id')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoice_extras');
    }
};
