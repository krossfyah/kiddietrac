<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Proposals (2026-11-07, Anthony: "the description of part#'s won't allow me to type unlimited ... and update date if
 * proposal changes"). A line's description was VARCHAR(255); it becomes TEXT. revised_at is the last time what the
 * customer reads changed - the proposal prints it as the issued date.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE sales_quote_items MODIFY description TEXT NOT NULL');
        if (! Schema::hasColumn('sales_quotes', 'revised_at')) {
            Schema::table('sales_quotes', function (Blueprint $t) {
                $t->timestamp('revised_at')->nullable()->after('sent_at');
            });
        }
    }

    public function down(): void
    {
        // Not narrowed back: longer descriptions would be cut.
        if (Schema::hasColumn('sales_quotes', 'revised_at')) {
            Schema::table('sales_quotes', function (Blueprint $t) { $t->dropColumn('revised_at'); });
        }
    }
};
