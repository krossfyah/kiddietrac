<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The addresses KiddieTrac invoices go to, saved per agency from the Email invoice popup (2026-10-25). Comma separated. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            if (! Schema::hasColumn('agencies', 'invoice_emails')) $t->text('invoice_emails')->nullable();
        });
    }

    public function down(): void {}
};
