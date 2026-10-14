<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Discount plans in Sales → Plans & pricing (2026-10-14, Anthony: "plans and pricing we need
 * ability to add a plan that can discount"). A product is now either a priced plan or a
 * discount: percent off or a fixed amount off, applied to the whole proposal, its recurring
 * items or its one-time items. For a discount, `price` holds the percent or the amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_products', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_products', 'kind')) $t->string('kind', 12)->default('plan')->after('name');
            if (! Schema::hasColumn('sales_products', 'discount_type')) $t->string('discount_type', 10)->nullable()->after('kind');
            if (! Schema::hasColumn('sales_products', 'applies_to')) $t->string('applies_to', 12)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('sales_products', fn (Blueprint $t) => $t->dropColumn(['kind', 'discount_type', 'applies_to']));
    }
};
