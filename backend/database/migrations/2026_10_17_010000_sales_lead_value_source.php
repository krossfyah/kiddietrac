<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a lead's estimated value came from (2026-10-16, Anthony: "based on the proposal sent add
 * this to our estimated revenue for that prospect"). value_quote_id = the proposal that set it
 * (NULL = typed by hand); value_currency = that proposal's currency. See SalesController::syncLeadValue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_leads', 'value_quote_id')) $t->unsignedBigInteger('value_quote_id')->nullable()->after('value');
            if (! Schema::hasColumn('sales_leads', 'value_currency')) $t->string('value_currency', 3)->nullable()->after('value_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', fn (Blueprint $t) => $t->dropColumn(['value_quote_id', 'value_currency']));
    }
};
