<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac's customers (2026-11-10, Anthony: "when I change lead to won it should create a customer account with
 * customer id based on the details of the services ... a new section called Customers under Sales ... tie this to
 * the account AR/AP"). One row per won lead, KT-CUS-0001, with the services and pricing of the winning proposal.
 * agency_id links it to the KiddieTrac agency set up through Approve, which is what ties it to AR (platform_invoices).
 * App\Support\SalesCustomers.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_customers')) return;
        Schema::create('sales_customers', function (Blueprint $t) {
            $t->id();
            $t->string('customer_number', 20)->nullable()->unique();
            $t->unsignedBigInteger('lead_id')->unique();
            $t->unsignedBigInteger('quote_id')->nullable();
            $t->unsignedBigInteger('agency_id')->nullable()->index();
            $t->string('company', 190)->nullable();
            $t->string('contact_name', 190)->nullable();
            $t->string('email', 190)->nullable();
            $t->string('phone', 60)->nullable();
            $t->string('city', 120)->nullable();
            $t->string('province', 120)->nullable();
            $t->string('country', 80)->nullable();
            $t->string('status', 20)->default('onboarding');   // onboarding | active | paused | cancelled
            $t->string('billing_period', 10)->nullable();
            $t->string('currency', 3)->default('CAD');
            $t->decimal('mrr', 12, 2)->default(0);             // recurring, after discounts, before tax, per month
            $t->decimal('one_time', 12, 2)->default(0);        // one-time fees, before tax
            $t->decimal('annual_value', 12, 2)->default(0);    // first-year value: 12 x MRR + one-time
            $t->json('services')->nullable();
            $t->text('notes')->nullable();
            $t->timestamp('won_at')->nullable();
            $t->unsignedBigInteger('won_by_user_id')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_customers');
    }
};
