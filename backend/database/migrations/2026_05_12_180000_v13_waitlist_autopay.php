<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // ─── Waitlist: track when applied + parent-visible note ─────
        if (Schema::hasTable('children') && ! Schema::hasColumn('children', 'applied_at')) {
            Schema::table('children', function (Blueprint $t) {
                $t->date('applied_at')->nullable()->after('withdrawn_at');
                $t->unsignedInteger('waitlist_position')->nullable()->after('applied_at');
                $t->date('expected_start_date')->nullable()->after('waitlist_position');
                $t->string('preferred_room_age_group', 40)->nullable()->after('expected_start_date');
                $t->text('waitlist_notes')->nullable()->after('preferred_room_age_group');
                $t->index('applied_at');
            });
        }

        // ─── Families: Stripe customer storage + autopay ────────────
        if (Schema::hasTable('families') && ! Schema::hasColumn('families', 'stripe_customer_id')) {
            Schema::table('families', function (Blueprint $t) {
                $t->string('stripe_customer_id', 120)->nullable()->after('billing_split');
                $t->boolean('autopay_enabled')->default(false)->after('stripe_customer_id');
                $t->string('autopay_payment_method_id', 120)->nullable()->after('autopay_enabled');
                $t->string('autopay_card_last4', 4)->nullable()->after('autopay_payment_method_id');
                $t->string('autopay_card_brand', 40)->nullable()->after('autopay_card_last4');
                $t->index('stripe_customer_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('children') && Schema::hasColumn('children', 'applied_at')) {
            Schema::table('children', function (Blueprint $t) {
                $t->dropColumn(['applied_at', 'waitlist_position', 'expected_start_date', 'preferred_room_age_group', 'waitlist_notes']);
            });
        }
        if (Schema::hasTable('families') && Schema::hasColumn('families', 'stripe_customer_id')) {
            Schema::table('families', function (Blueprint $t) {
                $t->dropColumn(['stripe_customer_id', 'autopay_enabled', 'autopay_payment_method_id', 'autopay_card_last4', 'autopay_card_brand']);
            });
        }
    }
};
