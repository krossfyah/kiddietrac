<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The agency's owner, picked from its admins on the customer (2026-11-11): nothing on an agency says who owns it. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('sales_customers', 'owner_user_id')) {
            Schema::table('sales_customers', function (Blueprint $t) {
                $t->unsignedBigInteger('owner_user_id')->nullable()->after('agency_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('sales_customers', function (Blueprint $t) {
            $t->dropColumn('owner_user_id');
        });
    }
};
