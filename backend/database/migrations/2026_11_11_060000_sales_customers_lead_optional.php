<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A customer that never came through Sales (iLearn, set up before Sales -> Customers) has no lead (2026-11-11). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_customers', function (Blueprint $t) {
            $t->unsignedBigInteger('lead_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sales_customers', function (Blueprint $t) {
            $t->unsignedBigInteger('lead_id')->nullable(false)->change();
        });
    }
};
