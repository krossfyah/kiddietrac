<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every invoice reminder and resend, one row per recipient per day (App\Support\InvoiceReminders, 2026-10-31).
 * The unique key is what stops a second reminder for the same invoice to the same person on the same day; the rows
 * also count overdue reminders for "stop after N" and give "last reminder sent" in the due-now preview.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('invoice_reminder_log')) return;
        Schema::create('invoice_reminder_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->string('invoice_kind', 10);              // native | external
            $t->unsignedBigInteger('invoice_id');
            $t->string('recipient', 190);
            $t->unsignedBigInteger('guardian_id')->nullable();
            $t->string('reason', 12);                    // before | due | overdue | resend
            $t->integer('days')->default(0);
            $t->date('sent_on');
            $t->json('copies')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['invoice_kind', 'invoice_id', 'recipient', 'sent_on', 'reason'], 'irl_once_a_day');
            $t->index(['invoice_kind', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_reminder_log');
    }
};
