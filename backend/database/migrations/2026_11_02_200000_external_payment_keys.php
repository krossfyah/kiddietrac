<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PAYMENTS RECORDED IN A PARTNER SYSTEM (2026-11-02, Anthony: "iL-INV-202610-0136-02 (invoice) was marked as paid
 * on ilearn - based on invoices marked paid, can you autosync to kiddietrac (full sweep)").
 *
 * Families billed THROUGH KiddieTrac (native invoices numbered iL-INV-YYYYMM-FFFF-NN) pay by Interac to the
 * partner's inbox. The partner records the money and the KiddieTrac invoice stayed unpaid. App\Support\
 * ExternalPaymentSync now mirrors such a payment onto the KiddieTrac invoice it names. These columns key the mirror
 * so a re-push never counts the same money twice:
 *   payments.external_source  - who recorded it ('ilearn');
 *   payments.external_id      - the partner's payment id ('payment-1779383327410');
 *   payments.external_ref     - the partner invoice the payment was pushed with ('invoice-1779383993908'), so a
 *                               payment the partner deletes or unlinks is withdrawn here on the next push.
 *   external_invoices.applied_to_invoice_id - the partner invoice was only a receipt shell for a KiddieTrac
 *                               invoice (auto-created from the e-Transfer); it is voided here so the money is not
 *                               shown twice, and this says where it went.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            if (! Schema::hasColumn('payments', 'external_source')) $t->string('external_source', 40)->nullable();
            if (! Schema::hasColumn('payments', 'external_id')) $t->string('external_id', 120)->nullable();
            if (! Schema::hasColumn('payments', 'external_ref')) $t->string('external_ref', 120)->nullable();
        });
        try {
            Schema::table('payments', function (Blueprint $t) {
                $t->unique(['external_source', 'external_id', 'external_ref', 'invoice_id'], 'payments_external_key_unique');
                $t->index(['external_source', 'external_ref'], 'payments_external_ref_idx');
            });
        } catch (\Throwable $e) {
            // already there
        }
        Schema::table('external_invoices', function (Blueprint $t) {
            if (! Schema::hasColumn('external_invoices', 'applied_to_invoice_id')) $t->unsignedBigInteger('applied_to_invoice_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $t) {
            try { $t->dropUnique('payments_external_key_unique'); } catch (\Throwable $e) {}
            try { $t->dropIndex('payments_external_ref_idx'); } catch (\Throwable $e) {}
            foreach (['external_source', 'external_id', 'external_ref'] as $c) {
                if (Schema::hasColumn('payments', $c)) $t->dropColumn($c);
            }
        });
        Schema::table('external_invoices', function (Blueprint $t) {
            if (Schema::hasColumn('external_invoices', 'applied_to_invoice_id')) $t->dropColumn('applied_to_invoice_id');
        });
    }
};
