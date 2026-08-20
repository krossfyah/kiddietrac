<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zum Rails: who they think our people are, and what money we have asked them to move.
 *
 * Two tables rather than columns bolted onto users and invoices, because a payment is
 * not a property of an invoice — one invoice can be attempted twice, and a payout has no
 * invoice at all.
 *
 * zum_transactions is OUR record. It is written before the API call, so a request that
 * never returns still leaves a trace, and it is what the webhook updates. Status here is
 * the truth the portal reads; the create response only ever means "accepted", never
 * "settled", because an e-Transfer settles later.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('zum_users')) {
            Schema::create('zum_users', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id')->unique();
                // Their id, kept as a string: it is theirs to shape, not ours to assume.
                $table->string('zum_user_id', 64)->index();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('zum_transactions')) {
            Schema::create('zum_transactions', function (Blueprint $table) {
                $table->id();
                // in  = collect from a parent (AccountsReceivable)
                // out = pay a staff member  (AccountsPayable)
                $table->string('direction', 4)->index();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('invoice_id')->nullable()->index();
                $table->unsignedBigInteger('payroll_document_id')->nullable()->index();
                $table->decimal('amount', 12, 2);
                $table->string('method', 24)->default('Interac');
                // pending → submitted → settled | failed | cancelled
                $table->string('status', 24)->default('pending')->index();
                $table->string('zum_transaction_id', 64)->nullable()->unique();
                $table->text('last_response')->nullable();
                $table->timestamp('settled_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('zum_transactions');
        Schema::dropIfExists('zum_users');
    }
};
