<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One row per out-of-ratio episode per room (App\Support\RatioAlerts): alerted once, closed when back in ratio. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ratio_breach_alerts')) return;
        Schema::create('ratio_breach_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id');
            $t->unsignedBigInteger('room_id');
            $t->string('status', 20);                 // breach | over_group
            $t->unsignedInteger('children')->default(0);
            $t->decimal('staff', 6, 1)->default(0);
            $t->unsignedInteger('required')->default(0);
            $t->text('recipients')->nullable();       // json user ids told
            $t->timestamp('started_at')->nullable();
            $t->timestamp('alerted_at')->nullable();
            $t->timestamp('cleared_at')->nullable();
            $t->boolean('cleared_quietly')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->index(['room_id', 'cleared_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratio_breach_alerts');
    }
};
