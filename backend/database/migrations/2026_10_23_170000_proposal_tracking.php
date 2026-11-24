<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Proposal received / opened tracking (2026-10-23, App\Support\ProposalTracking). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_quotes', function (Blueprint $t) {
            foreach (['email_sent_at', 'email_opened_at', 'email_last_opened_at', 'pdf_downloaded_at'] as $c) {
                if (! Schema::hasColumn('sales_quotes', $c)) $t->timestamp($c)->nullable();
            }
            if (! Schema::hasColumn('sales_quotes', 'email_open_count')) $t->unsignedInteger('email_open_count')->default(0);
            if (! Schema::hasColumn('sales_quotes', 'email_send_status')) $t->string('email_send_status', 20)->nullable();
            if (! Schema::hasColumn('sales_quotes', 'email_send_error')) $t->string('email_send_error', 255)->nullable();
        });
        if (! Schema::hasTable('sales_quote_opens')) {
            Schema::create('sales_quote_opens', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('quote_id')->index();
                $t->string('kind', 12);
                $t->boolean('counted')->default(true);
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_quote_opens');
        Schema::table('sales_quotes', fn (Blueprint $t) => $t->dropColumn(['email_sent_at', 'email_opened_at', 'email_last_opened_at', 'pdf_downloaded_at', 'email_open_count', 'email_send_status', 'email_send_error']));
    }
};
