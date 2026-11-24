<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * support_ticket_sla_alerts — one row per ticket and breach kind ('respond' / 'resolve'), so kt:ticket-sla
 * alerts once when a ticket misses its SLA and then once a day while it stays breached (2026-11-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('support_ticket_sla_alerts')) {
            return;
        }
        Schema::create('support_ticket_sla_alerts', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id');
            $t->string('kind', 10);
            $t->unsignedInteger('times')->default(0);
            $t->timestamp('first_sent_at')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
            $t->unique(['ticket_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_sla_alerts');
    }
};
