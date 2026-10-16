<?php

use App\Http\Controllers\Api\SalesController;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Terms & Conditions on proposals (2026-10-16, Anthony: "add the terms and conditions").
 * Each proposal keeps its own copy, so a later edit of the default never changes one already
 * sent. Drafts get the standard text now; proposals already sent are left as they went out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quotes', 'conditions')) $t->mediumText('conditions')->nullable()->after('terms');
        });
        DB::table('sales_quotes')->where('status', 'draft')->whereNull('conditions')->whereNull('deleted_at')
            ->update(['conditions' => SalesController::DEFAULT_CONDITIONS]);
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn('conditions'));
    }
};
