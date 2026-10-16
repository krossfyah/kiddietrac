<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Editable "Prepared for" / "Prepared by" on proposals (2026-10-16, Anthony: "make the prepared
 * for and prepared by fields editable ... the prepared by should be using kiddietrac's sales
 * email and sales number and not my personal email"). NULL = work it out from the lead and the
 * proposal defaults, so proposals made before this change print the sales contact too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quotes', 'prepared_for')) $t->json('prepared_for')->nullable()->after('conditions');
            if (! Schema::hasColumn('sales_quotes', 'prepared_by')) $t->json('prepared_by')->nullable()->after('prepared_for');
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['prepared_for', 'prepared_by']));
    }
};
