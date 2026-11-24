<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** One receipt / PAID email per payment per address (App\Support\PaidMail, 2026-10-31). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('payment_paid_mails')) {
            Schema::create('payment_paid_mails', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('payment_id');
                $t->string('email', 190);
                $t->boolean('settled')->default(false);
                $t->unsignedTinyInteger('attachments')->default(0);
                $t->timestamp('created_at')->nullable();
                $t->unique(['payment_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_paid_mails');
    }
};
