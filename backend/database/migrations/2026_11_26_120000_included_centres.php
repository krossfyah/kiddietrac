<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Centres a plan or add-on includes (2026-11-26, Anthony: "Reseller bundle includes 5 centres, CONFIGURABLE ... per
   product"). App\Support\CentreCap. NULL = the product sets no centre cap. The Reseller bundle starts at 5. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_products', 'included_centres')) {
            Schema::table('sales_products', function (Blueprint $t) {
                $t->unsignedInteger('included_centres')->nullable()->after('feature_keys');
            });
        }
        DB::table('sales_products')->where('kind', 'addon')->whereNull('included_centres')
            ->whereRaw("JSON_CONTAINS(COALESCE(feature_keys, JSON_ARRAY()), JSON_QUOTE('reseller'))")
            ->update(['included_centres' => 5]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_products', 'included_centres')) {
            Schema::table('sales_products', function (Blueprint $t) {
                $t->dropColumn('included_centres');
            });
        }
    }
};
