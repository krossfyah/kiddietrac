<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_leads', 'website'))          $t->string('website')->nullable()->after('phone');
            if (! Schema::hasColumn('sales_leads', 'address'))          $t->string('address')->nullable()->after('website');
            if (! Schema::hasColumn('sales_leads', 'city'))             $t->string('city', 120)->nullable()->after('address');
            if (! Schema::hasColumn('sales_leads', 'province'))         $t->string('province', 120)->nullable()->after('city');
            if (! Schema::hasColumn('sales_leads', 'postal_code'))      $t->string('postal_code', 30)->nullable()->after('province');
            if (! Schema::hasColumn('sales_leads', 'country'))          $t->string('country', 120)->nullable()->after('postal_code');
            if (! Schema::hasColumn('sales_leads', 'current_solution')) $t->string('current_solution')->nullable()->after('country');
            if (! Schema::hasColumn('sales_leads', 'num_children'))     $t->unsignedInteger('num_children')->nullable()->after('current_solution');
            if (! Schema::hasColumn('sales_leads', 'num_locations'))    $t->unsignedInteger('num_locations')->nullable()->after('num_children');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $t) {
            $t->dropColumn(['website', 'address', 'city', 'province', 'postal_code', 'country', 'current_solution', 'num_children', 'num_locations']);
        });
    }
};
