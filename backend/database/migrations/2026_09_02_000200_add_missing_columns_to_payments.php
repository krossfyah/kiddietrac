<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The payments ledger did not have the columns its writers use (2026-09-02).
 *
 * `payments` is what the invoice screen, the family ledger, the receipt PDF and the refunds
 * screen all read. Three of its writers referenced columns that do not exist, so every one
 * of them threw SQLSTATE[42S22] rather than recording anything:
 *
 *   InvoiceController::recordPayment  -> `reference`, `recorded_by_id`
 *   StripeParentPayController         -> `stripe_pi_id`, `updated_at`
 *
 * Two of those are name mismatches against columns that DO exist (reference_number,
 * stripe_payment_id) and are fixed in the code. The other two are genuinely missing and are
 * added here:
 *
 *   recorded_by_id — who keyed in a cash or cheque payment. For money that arrives with no
 *                    electronic trail, the person who wrote it down IS the audit trail.
 *   updated_at     — the table only had created_at, so a row amended later (a refund stamp,
 *                    a corrected reference) could not say when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (! Schema::hasColumn('payments', 'recorded_by_id')) {
                $table->unsignedBigInteger('recorded_by_id')->nullable()->after('notes');
            }
            if (! Schema::hasColumn('payments', 'updated_at')) {
                $table->timestamp('updated_at')->nullable()->after('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            foreach (['recorded_by_id', 'updated_at'] as $c) {
                if (Schema::hasColumn('payments', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
