<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One decision per agency per month on the SMS Incremental overage (2026-10-28): added to the next invoice (with the
 * platform_invoice_extras line it created) or waived. The counts and allowances are kept as they were when decided.
 */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('messaging_overage_charges')) {
            return;
        }
        Schema::create('messaging_overage_charges', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->char('month', 7);
            $t->unsignedInteger('texts')->default(0);
            $t->unsignedInteger('calls')->default(0);
            $t->unsignedInteger('text_threshold')->default(0);
            $t->unsignedInteger('call_threshold')->default(0);
            $t->unsignedInteger('text_blocks')->default(0);
            $t->unsignedInteger('call_blocks')->default(0);
            $t->unsignedInteger('unit_cents')->default(0);
            $t->unsignedInteger('amount_cents')->default(0);
            $t->string('status', 16);              // added | waived
            $t->unsignedBigInteger('extra_id')->nullable();
            $t->unsignedBigInteger('decided_by_id')->nullable();
            $t->timestamps();
            $t->unique(['agency_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_overage_charges');
    }
};
