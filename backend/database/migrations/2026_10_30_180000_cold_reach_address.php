<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Cold reach prospects keep the business street address and postal code (smart import, 2026-10-30). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_prospects', 'address')) $t->string('address', 255)->nullable()->after('website');
            if (! Schema::hasColumn('sales_prospects', 'postal_code')) $t->string('postal_code', 20)->nullable()->after('province');
        });
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) { $t->dropColumn(['address', 'postal_code']); });
    }
};
