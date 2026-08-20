<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Payment provider credentials, per agency.
 *
 * Zum Rails and Stripe were both configured from .env, which means one set of keys for
 * the whole platform. That is wrong for a system where each agency is a separate
 * business banking its own money: an agency's takings must land in that agency's account,
 * and one agency's keys must never move another's money.
 *
 * Secrets live in `secrets`, a Crypt-encrypted JSON blob, following the same pattern as
 * the QuickBooks client secret already on the agencies row. Only `enabled` and `mode`
 * stay in the clear, because those are the two things worth querying across agencies
 * ("who is live on cards?") and neither is a credential.
 *
 * Nothing here is ever sent to a browser. The API returns booleans and last-four hints.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_payment_providers')) {
            return;
        }

        Schema::create('agency_payment_providers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            // 'zumrails' | 'stripe'
            $table->string('provider', 32);
            $table->boolean('enabled')->default(false);
            // 'sandbox' | 'production' — in the clear so a live agency is visible at a
            // glance, and so nobody has to decrypt to answer "is this one still on test?"
            $table->string('mode', 16)->default('sandbox');
            // Encrypted JSON. Everything credential-ish goes in here, including usernames
            // and base URLs: a base URL identifies an environment, and there is no reason
            // to hold any of it in the clear.
            $table->text('secrets')->nullable();
            $table->unsignedBigInteger('updated_by_id')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_payment_providers');
    }
};
