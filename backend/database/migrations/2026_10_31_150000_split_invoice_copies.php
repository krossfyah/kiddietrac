<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Split billing follow-ups (2026-10-30):
   - split_invoice_sends: every email of a guardian's own invoice copy (INV-1234-A), so the office can see and resend.
   - guardians.*autopay*: each guardian of a split family saves their own card and autopays THEIR share. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('split_invoice_sends')) {
            Schema::create('split_invoice_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id')->index();
                $t->unsignedBigInteger('guardian_id')->index();
                $t->string('number', 60);
                $t->string('to_email', 180);
                $t->string('cc', 500)->nullable();
                $t->decimal('amount', 10, 2)->default(0);
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->boolean('attached')->default(true);
                $t->timestamp('created_at')->nullable();
            });
        }
        Schema::table('guardians', function (Blueprint $t) {
            if (! Schema::hasColumn('guardians', 'stripe_customer_id')) $t->string('stripe_customer_id', 64)->nullable();
            if (! Schema::hasColumn('guardians', 'autopay_enabled')) $t->boolean('autopay_enabled')->default(false);
            if (! Schema::hasColumn('guardians', 'autopay_payment_method_id')) $t->string('autopay_payment_method_id', 64)->nullable();
            if (! Schema::hasColumn('guardians', 'autopay_card_brand')) $t->string('autopay_card_brand', 20)->nullable();
            if (! Schema::hasColumn('guardians', 'autopay_card_last4')) $t->string('autopay_card_last4', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('split_invoice_sends');
        Schema::table('guardians', function (Blueprint $t) {
            foreach (['stripe_customer_id', 'autopay_enabled', 'autopay_payment_method_id', 'autopay_card_brand', 'autopay_card_last4'] as $c) {
                if (Schema::hasColumn('guardians', $c)) $t->dropColumn($c);
            }
        });
    }
};
