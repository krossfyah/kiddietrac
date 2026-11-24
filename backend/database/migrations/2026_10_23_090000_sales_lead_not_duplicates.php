<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lead pairs a person said are NOT the same prospect, so the duplicate check stops asking (2026-10-23). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_lead_not_duplicates')) return;
        Schema::create('sales_lead_not_duplicates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('lead_a');
            $t->unsignedBigInteger('lead_b');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['lead_a', 'lead_b']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_not_duplicates');
    }
};
