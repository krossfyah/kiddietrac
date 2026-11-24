<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Educator rewards (2026-10-20): a bonus, payout or gift card given for a month's points.
   Points themselves are computed from the activity tables (App\Support\EducatorPoints);
   only the award is stored, with the points as they stood when it was made. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('educator_rewards')) {
            return;
        }
        Schema::create('educator_rewards', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('user_id')->index();
            $t->char('month', 7);                               // YYYY-MM the points were earned in
            $t->integer('points')->default(0);
            $t->decimal('amount', 10, 2)->default(0);
            $t->char('currency', 3)->default('CAD');
            // payroll_bonus | payout | gift_card_manual | gift_card_provider
            $t->string('method', 32);
            // pending_payroll | paid | sent | requested | cancelled | failed
            $t->string('status', 24);
            $t->string('brand', 80)->nullable();                // gift card brand (Amazon, Tim Hortons ...)
            $t->text('gift_code')->nullable();                  // encrypted (Crypt)
            $t->text('gift_link')->nullable();                  // encrypted (Crypt)
            $t->string('provider', 32)->nullable();
            $t->string('provider_order_id', 120)->nullable();
            $t->string('provider_status', 40)->nullable();
            $t->text('provider_response')->nullable();
            $t->string('reference', 120)->nullable();           // e-transfer / cheque reference
            $t->text('note')->nullable();
            $t->unsignedBigInteger('payroll_document_id')->nullable();
            $t->unsignedBigInteger('awarded_by_id')->nullable();
            $t->timestamp('paid_at')->nullable();
            $t->timestamp('seen_at')->nullable();               // the educator opened it
            $t->timestamps();
            $t->index(['agency_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('educator_rewards');
    }
};
