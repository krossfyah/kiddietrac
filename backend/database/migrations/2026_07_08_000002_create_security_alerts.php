<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Security monitoring alerts (SOC 2 — CC7.2/CC7.3). The security:alerts command
 * writes one row per detected anomaly; the portal can surface open alerts.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('security_alerts', function (Blueprint $t) {
            $t->id();
            $t->string('type', 60);              // brute_force_ip | mfa_hammering | credential_stuffing
            $t->string('severity', 20)->default('high');
            $t->string('subject', 191)->nullable();
            $t->text('details')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
            $t->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_alerts');
    }
};
