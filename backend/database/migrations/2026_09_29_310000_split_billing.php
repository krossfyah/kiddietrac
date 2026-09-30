<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Split family billing (2026-09-29): who paid (payments.payer_guardian_id) and the payer
   percentages frozen onto an issued invoice (invoices.split_snapshot). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'payer_guardian_id')) {
            Schema::table('payments', function (Blueprint $t) {
                $t->unsignedBigInteger('payer_guardian_id')->nullable()->after('family_id')->index();
            });
        }
        if (! Schema::hasColumn('invoices', 'split_snapshot')) {
            Schema::table('invoices', function (Blueprint $t) {
                $t->text('split_snapshot')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'payer_guardian_id')) Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('payer_guardian_id'));
        if (Schema::hasColumn('invoices', 'split_snapshot')) Schema::table('invoices', fn (Blueprint $t) => $t->dropColumn('split_snapshot'));
    }
};
