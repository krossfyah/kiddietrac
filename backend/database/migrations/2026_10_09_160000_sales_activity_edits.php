<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A CORRECTED NOTE SAYS IT WAS CORRECTED (2026-10-09).
 * Anthony: "allow me to edit notes that I have added to leads to correct anything".
 * updated_at also moves when a follow-up is ticked done, so it cannot say "edited";
 * these two say who changed the wording and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_activities', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_activities', 'edited_at')) $t->timestamp('edited_at')->nullable();
            if (! Schema::hasColumn('sales_activities', 'edited_by_id')) $t->unsignedBigInteger('edited_by_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_activities', function (Blueprint $t) {
            if (Schema::hasColumn('sales_activities', 'edited_by_id')) $t->dropColumn('edited_by_id');
            if (Schema::hasColumn('sales_activities', 'edited_at')) $t->dropColumn('edited_at');
        });
    }
};
