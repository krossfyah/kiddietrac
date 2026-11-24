<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A Sales campaign's email design (App\Support\CampaignDesigns); null = the standard layout. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_campaigns', 'design')) {
            Schema::table('sales_campaigns', function (Blueprint $t) { $t->string('design', 30)->nullable()->after('template'); });
        }
    }

    public function down(): void
    {
        Schema::table('sales_campaigns', function (Blueprint $t) { $t->dropColumn('design'); });
    }
};
