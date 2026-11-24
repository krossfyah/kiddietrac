<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Which certificate expiry alert has gone out (App\Support\CertExpiryAlerts, 2026-11-03): one row per certificate,
   agency and step ('b30' = the 30-day step, 'd0' = expiry day, 'w3' = third week expired). */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cert_alert_log')) return;
        Schema::create('cert_alert_log', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('cert_id');
            $t->unsignedBigInteger('agency_id');
            $t->string('step_key', 12);
            $t->timestamp('sent_at')->nullable();
            $t->unique(['cert_id', 'agency_id', 'step_key'], 'cert_alert_once');
            $t->index('agency_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cert_alert_log');
    }
};
