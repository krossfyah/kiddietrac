<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invoices FROM the platform TO an agency (2026-08-25).
 *
 * Every existing invoice table runs the other way — `invoices` and `external_invoices`
 * are family→agency, `expense_invoices` is agency→supplier, `payee_invoices` is
 * agency→staff. Nothing recorded what an agency owes KiddieTrac, so agencies.plan_amount_cents
 * was a number with no document, no due date and no payment record behind it.
 *
 * Money is stored in CENTS as an integer. agencies.plan_amount_cents is already cents, so
 * matching it avoids a units conversion at the one place the two meet — and the existing
 * `invoices` table using decimals is precisely why a $1,200 figure had to be read twice
 * today to be sure of it.
 *
 * (agency_id, period_start) is unique: the monthly run must be safe to re-run after a
 * partial failure without double-billing anyone.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('platform_invoices')) {
            return;
        }

        Schema::create('platform_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->string('number', 40)->unique();

            /* The month being billed. Dates, not timestamps — a billing period is a
               calendar month, not an instant, and timezone drift on an instant would
               put a period in the wrong month for agencies west of UTC. */
            $table->date('period_start');
            $table->date('period_end');

            $table->string('plan_code', 40)->nullable();
            $table->integer('amount_cents');
            $table->integer('amount_paid_cents')->default(0);
            $table->string('currency', 8)->default('CAD');

            /* draft  — raised, not yet sent
               issued — sent to the agency
               paid   — settled in full
               void   — cancelled; never counts toward revenue or arrears */
            $table->string('status', 16)->default('draft');

            $table->timestamp('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_id')->nullable();
            $table->timestamps();

            $table->index('agency_id');
            $table->index('status');
            $table->index('due_at');
            /* Re-running the monthly job must not raise a second invoice for a month
               already billed. Enforced here rather than trusted to the command. */
            $table->unique(['agency_id', 'period_start'], 'platform_invoices_agency_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_invoices');
    }
};
