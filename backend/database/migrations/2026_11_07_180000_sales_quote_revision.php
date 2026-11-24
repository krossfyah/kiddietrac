<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposal revisions (2026-11-07, Anthony: "when proposals changed or edited increment the proposal # or iteration").
 * revision 1 = the number as first issued (KT-Q-000022); a change after it went out makes it 2 (KT-Q-000022-R2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sales_quotes', 'revision')) {
            Schema::table('sales_quotes', function (Blueprint $t) {
                $t->unsignedSmallInteger('revision')->default(1)->after('number');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_quotes', 'revision')) {
            Schema::table('sales_quotes', function (Blueprint $t) { $t->dropColumn('revision'); });
        }
    }
};
