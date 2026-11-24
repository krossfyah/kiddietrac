<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** What a prospect matched when it was imported (App\Support\ProspectMatcher): already contacted, a lead, a customer... */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_prospects', 'match_info')) {
            Schema::table('sales_prospects', function (Blueprint $t) { $t->json('match_info')->nullable(); });
        }
    }

    public function down(): void
    {
        Schema::table('sales_prospects', function (Blueprint $t) { $t->dropColumn('match_info'); });
    }
};
