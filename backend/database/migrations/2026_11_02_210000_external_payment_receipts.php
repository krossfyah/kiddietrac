<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Receipts for payments mirrored from a partner (2026-11-02). One claim per partner payment, ever, so a re-push,
 * a reversal and re-apply, or the nightly catch-up can never send a second receipt. Every payment mirrored before
 * receipts existed is claimed here as 'backfill' and never receives one.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'receipt_sent_at')) {
            Schema::table('payments', function (Blueprint $t) {
                $t->dateTime('receipt_sent_at')->nullable();
            });
        }
        if (! Schema::hasTable('external_payment_receipts')) {
            Schema::create('external_payment_receipts', function (Blueprint $t) {
                $t->id();
                $t->string('external_source', 40);
                $t->string('external_id', 120);
                $t->unsignedBigInteger('payment_id')->nullable();
                $t->string('outcome', 40);
                $t->dateTime('created_at')->nullable();
                $t->unique(['external_source', 'external_id'], 'external_payment_receipts_key');
            });
        }
        $rows = DB::table('payments')->whereNotNull('external_source')->whereNotNull('external_id')
            ->orderBy('id')->get(['id', 'external_source', 'external_id']);
        foreach ($rows as $r) {
            DB::table('external_payment_receipts')->insertOrIgnore([
                'external_source' => $r->external_source, 'external_id' => $r->external_id,
                'payment_id' => $r->id, 'outcome' => 'backfill', 'created_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('external_payment_receipts');
        if (Schema::hasColumn('payments', 'receipt_sent_at')) {
            Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('receipt_sent_at'));
        }
    }
};
