<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns StripeBillingController already expects (2026-08-25).
 *
 * The agency subscription flow is written — /billing/connect, /billing/subscribe,
 * /billing/cancel, /billing/status and a signature-verified webhook all exist — but the
 * columns its own docblock lists were never added, so it has nowhere to record a
 * subscription once Stripe creates one.
 *
 * All nullable with no default: nothing changes for any existing agency, and the flow
 * stays inert until STRIPE_MONTHLY_PRICE_ID and a real STRIPE_WEBHOOK_SECRET are set.
 * This removes a blocker; it does not switch billing on.
 *
 * stripe_account_id is the agency's Connect account; stripe_subscription_id is what THEY
 * pay the platform. Different directions of money, deliberately separate fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (! Schema::hasColumn('agencies', 'stripe_account_id')) {
                $table->string('stripe_account_id', 80)->nullable()->after('billing_starts_at');
            }
            if (! Schema::hasColumn('agencies', 'stripe_subscription_id')) {
                $table->string('stripe_subscription_id', 80)->nullable()->after('stripe_account_id');
            }
            if (! Schema::hasColumn('agencies', 'stripe_subscription_status')) {
                $table->string('stripe_subscription_status', 40)->nullable()->after('stripe_subscription_id');
            }
        });

        /* Indexed because the webhook looks an agency up BY subscription id on every
           Stripe event — without it that is a full scan per callback. */
        Schema::table('agencies', function (Blueprint $table) {
            try {
                $table->index('stripe_subscription_id');
            } catch (\Throwable $e) {
                // already indexed
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            try {
                $table->dropIndex(['stripe_subscription_id']);
            } catch (\Throwable $e) {
            }
            foreach (['stripe_subscription_status', 'stripe_subscription_id', 'stripe_account_id'] as $c) {
                if (Schema::hasColumn('agencies', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
