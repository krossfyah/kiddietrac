<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Send from (2026-11-09): the platform mailbox (email_accounts, no agency) a sales campaign goes out from; null = the usual sender. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sales_campaigns', 'from_account_id')) return;
        Schema::table('sales_campaigns', function (Blueprint $t) {
            $t->unsignedBigInteger('from_account_id')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('sales_campaigns', 'from_account_id')) {
            Schema::table('sales_campaigns', fn (Blueprint $t) => $t->dropColumn('from_account_id'));
        }
    }
};
