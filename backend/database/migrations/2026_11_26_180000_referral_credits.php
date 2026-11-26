<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* REFERRAL CREDITS (2026-11-26, Anthony: "wire up a way to apply a referral fee to a future invoice to one of our
   customers (one time)"). A one-time credit off a KiddieTrac customer's (agency's) platform invoice, as thanks for
   referring another lead/customer. App\Support\ReferralCredits. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('referral_credits')) return;
        Schema::create('referral_credits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');                     // the referrer: whose invoice is credited
            $t->unsignedBigInteger('referred_lead_id');              // who they referred (sales_leads)
            $t->string('type', 10);                                  // fixed | percent
            $t->decimal('value', 12, 2);                             // dollars (fixed) or percent (percent)
            $t->date('applies_from');                                // first day of the chosen invoice month
            $t->string('status', 20)->default('pending');            // pending | partially_applied | applied | cancelled
            $t->integer('applied_cents')->default(0);
            $t->integer('remaining_cents')->nullable();              // fixed only: what is still to come off
            $t->json('applied_invoice_ids')->nullable();
            $t->string('currency', 3)->default('CAD');
            $t->string('notes', 500)->nullable();
            $t->unsignedBigInteger('created_by_id')->nullable();
            $t->unsignedBigInteger('cancelled_by_id')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
            $t->index(['agency_id', 'status']);
            $t->index('referred_lead_id');
        });
        /* ONE CREDIT PER REFERRAL: unique across live credits only, so a cancelled one can be redone. live_lead_id is
           the lead while the credit is not cancelled, NULL after (NULLs never collide). */
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE referral_credits ADD live_lead_id BIGINT UNSIGNED
            GENERATED ALWAYS AS (IF(status <> 'cancelled', referred_lead_id, NULL)) STORED, ADD UNIQUE KEY referral_credits_live_lead_unique (live_lead_id)");
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_credits');
    }
};
