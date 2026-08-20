<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Partial refunds against a Zum payment.
 *
 * A separate table, not a column, because a payment can be refunded more than once:
 * £50 back this week and £25 next week against the same £200 charge. A single
 * refunded_at/refund_amount pair cannot express that, and childcare refunds are usually
 * partial — a closure credit, a withdrawn day, a sibling adjustment.
 *
 * zum_transactions.refunded_amount is the running total, kept alongside so the
 * "how much can still be refunded" question is one read rather than a sum over rows.
 * It is only ever moved by settled refunds.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('zum_transactions', 'refunded_amount')) {
            Schema::table('zum_transactions', function (Blueprint $table) {
                $table->decimal('refunded_amount', 12, 2)->default(0)->after('amount');
            });
        }

        if (! Schema::hasTable('zum_refunds')) {
            Schema::create('zum_refunds', function (Blueprint $table) {
                $table->id();
                // Our zum_transactions row, not Zum's id: this is our record of intent.
                $table->unsignedBigInteger('zum_transaction_id_local')->index();
                $table->string('zum_refund_id', 64)->nullable()->unique();
                $table->decimal('amount', 12, 2);
                $table->string('reason', 300)->nullable();
                // pending → submitted → settled | failed | cancelled
                $table->string('status', 24)->default('pending')->index();
                $table->unsignedBigInteger('requested_by_id')->nullable();
                $table->text('last_response')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zum_refunds');
        if (Schema::hasColumn('zum_transactions', 'refunded_amount')) {
            Schema::table('zum_transactions', function (Blueprint $table) {
                $table->dropColumn('refunded_amount');
            });
        }
    }
};
