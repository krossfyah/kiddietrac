<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* "Resend proposal" (2026-10-23): the CC and BCC the proposal was last emailed with. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_quotes', 'email_cc')) $t->text('email_cc')->nullable();
            if (! Schema::hasColumn('sales_quotes', 'email_bcc')) $t->text('email_bcc')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['email_cc', 'email_bcc']));
    }
};
