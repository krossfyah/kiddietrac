<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A discount line's own rate on THIS proposal (2026-11-10, Anthony: "if I select the 10% discount, I can edit this during
 * the proposal edit screen to something to my liking"). The catalogue discount gives the starting rate; the line keeps
 * whatever it was changed to. Null = an older line, which still follows its catalogue discount.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quote_items', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quote_items', 'discount_type')) $t->string('discount_type', 10)->nullable()->after('part_number');
            if (! Schema::hasColumn('sales_quote_items', 'discount_value')) $t->decimal('discount_value', 10, 2)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quote_items', function (Blueprint $t) {
            $t->dropColumn(['discount_type', 'discount_value']);
        });
    }
};
