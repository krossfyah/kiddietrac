<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Server health history (2026-10-22): one row every 5 minutes from
   kt:server-health-sample, kept 14 days. See App\Support\ServerHealth. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('server_health_samples')) {
            return;
        }
        Schema::create('server_health_samples', function (Blueprint $t) {
            $t->id();
            $t->dateTime('sampled_at')->index();     // UTC, written by the app
            $t->decimal('load_1', 6, 2)->nullable();
            $t->decimal('cpu_pct', 5, 1)->nullable();
            $t->decimal('mem_pct', 5, 1)->nullable();
            $t->decimal('disk_pct', 5, 1)->nullable();
            $t->decimal('db_ms', 8, 2)->nullable();
            $t->unsignedInteger('db_connections')->nullable();
            $t->unsignedInteger('queue_pending')->nullable();
            $t->unsignedInteger('queue_oldest')->nullable();   // seconds the oldest job had waited
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_health_samples');
    }
};
