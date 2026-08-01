<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_leads', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_leads', 'owner_name'))  $t->string('owner_name')->nullable()->after('current_solution');
            if (! Schema::hasColumn('sales_leads', 'owner_title')) $t->string('owner_title', 120)->nullable()->after('owner_name');
            if (! Schema::hasColumn('sales_leads', 'owner_email')) $t->string('owner_email', 190)->nullable()->after('owner_title');
            if (! Schema::hasColumn('sales_leads', 'owner_phone')) $t->string('owner_phone', 60)->nullable()->after('owner_email');
        });
    }

    public function down(): void
    {
        Schema::table('sales_leads', function (Blueprint $t) {
            $t->dropColumn(['owner_name', 'owner_title', 'owner_email', 'owner_phone']);
        });
    }
};
