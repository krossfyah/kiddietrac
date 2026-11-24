<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The lived-in website demo (2026-10-30; App\Support\WebDemoContent, App\Support\WebDemoPayments):
 *  - web_demo_payments: an invoice's figures before a simulated demo payment, so the nightly reset can put them back;
 *  - menu_items.image_url: a photo of the dish (the parent's weekly menu shows it when there is one).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_demo_payments')) {
            Schema::create('web_demo_payments', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('invoice_id')->index();
                $t->unsignedBigInteger('payment_id');
                $t->decimal('prev_amount_paid', 10, 2)->default(0);
                $t->decimal('prev_balance_due', 10, 2)->default(0);
                $t->string('prev_status', 20);
                $t->timestamp('created_at')->nullable();
            });
        }
        if (! Schema::hasColumn('menu_items', 'image_url')) {
            Schema::table('menu_items', function (Blueprint $t) { $t->string('image_url', 255)->nullable(); });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_demo_payments');
        if (Schema::hasColumn('menu_items', 'image_url')) {
            Schema::table('menu_items', function (Blueprint $t) { $t->dropColumn('image_url'); });
        }
    }
};
