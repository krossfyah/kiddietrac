<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Recurring invoicing per agency + invoice BCC (2026-10-25). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            if (! Schema::hasColumn('agencies', 'recurring_enabled')) $t->boolean('recurring_enabled')->default(false);      // used when recurring is "selected agencies"
            if (! Schema::hasColumn('agencies', 'billing_ends_at')) $t->date('billing_ends_at')->nullable();                  // stop issuing after this date
            if (! Schema::hasColumn('agencies', 'account_manager_user_id')) $t->unsignedBigInteger('account_manager_user_id')->nullable(); // the account's salesperson
            if (! Schema::hasColumn('agencies', 'invoice_bcc')) $t->string('invoice_bcc', 12)->default('default');            // default | none | superadmins | sales | both
        });
    }

    public function down(): void {}
};
