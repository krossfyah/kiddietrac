<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Batch payment run (2026-09-29): one row per auto-pay run (manual or nightly) with per-invoice results. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('payment_runs')) return;
        Schema::create('payment_runs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->nullable()->index();
            $t->unsignedBigInteger('started_by')->nullable();
            $t->string('source', 20)->default('manual');
            $t->string('status', 20)->default('completed');
            $t->unsignedInteger('candidates')->default(0);
            $t->unsignedInteger('succeeded')->default(0);
            $t->unsignedInteger('failed')->default(0);
            $t->unsignedInteger('skipped')->default(0);
            $t->decimal('total_charged', 12, 2)->default(0);
            $t->longText('results')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_runs');
    }
};
