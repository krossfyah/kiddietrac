<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// One row per browser session: how fast the portal's API calls were on that device (App\Support\NetQuality).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('client_net_samples')) return;
        Schema::create('client_net_samples', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->string('effective_type', 12)->nullable();
            $t->unsignedInteger('rtt_ms')->nullable();
            $t->unsignedInteger('downlink_kbps')->nullable();
            $t->unsignedInteger('api_median_ms')->nullable();
            $t->unsignedInteger('api_p90_ms')->nullable();
            $t->unsignedSmallInteger('api_samples')->default(0);
            $t->unsignedSmallInteger('failures')->default(0);
            $t->boolean('slow')->default(false);
            $t->string('platform', 16)->nullable();
            $t->timestamp('created_at')->nullable();
            $t->index('created_at');
            $t->index(['agency_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_net_samples');
    }
};
