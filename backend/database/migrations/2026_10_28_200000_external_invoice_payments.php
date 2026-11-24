<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The payments behind an integrated (iLearn) invoice - date, amount, method, reference - so a parent's account
   ledger can show WHEN they paid, not only that an invoice reads paid (2026-10-28). Sent by the partner with each
   invoice; null for a row the partner has not re-sent yet. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('external_invoices', 'payments')) {
            Schema::table('external_invoices', function (Blueprint $t) {
                $t->json('payments')->nullable()->after('items');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('external_invoices', 'payments')) {
            Schema::table('external_invoices', function (Blueprint $t) {
                $t->dropColumn('payments');
            });
        }
    }
};
