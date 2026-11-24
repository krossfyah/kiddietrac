<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* Split billing v2 (2026-10-30, Anthony: "do we have split payment setup today with multiple guardians paying an
   invoice, fees etc" + "add configuration within families to setup the split payment settings").
   - family_billing_splits: every split setting a family has had, with the date it applies from (history + audit).
   - families.billing_split: was enum(single, split_50_50, custom); now holds the v2 mode name too.
   - invoice_lines.payer_guardian_id: a line one guardian owes outright (their own late fee), outside the split rules. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('family_billing_splits')) {
            Schema::create('family_billing_splits', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('family_id')->index();
                $t->string('mode', 20);
                $t->text('config');
                $t->date('effective_from');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['family_id', 'effective_from']);
            });
        }
        DB::statement("ALTER TABLE families MODIFY billing_split VARCHAR(20) NOT NULL DEFAULT 'single'");
        if (! Schema::hasColumn('invoice_lines', 'payer_guardian_id')) {
            Schema::table('invoice_lines', function (Blueprint $t) {
                $t->unsignedBigInteger('payer_guardian_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('family_billing_splits');
        if (Schema::hasColumn('invoice_lines', 'payer_guardian_id')) Schema::table('invoice_lines', fn (Blueprint $t) => $t->dropColumn('payer_guardian_id'));
    }
};
