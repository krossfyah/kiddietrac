<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What has been put on a lead's history, by source (2026-11-05, Anthony: "when text messages, calls, emails come in
 * from a lead - attach these to the lead history (only in lead status)" / "backfill anything with leads that wasn't
 * updated"). One row per email (sha256 of its internetMessageId) or backfilled text (sms_messages.id), so the mail
 * watcher, a second mailbox holding the same email and the backfill never file the same thing twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sales_lead_filed')) return;
        Schema::create('sales_lead_filed', function (Blueprint $t) {
            $t->id();
            $t->string('source', 12);                 // email | sms
            $t->string('ref', 64);                    // sha256(internetMessageId) | sms_messages.id
            $t->unsignedBigInteger('lead_id');
            $t->unsignedBigInteger('activity_id')->nullable();
            $t->timestamp('created_at')->nullable();
            $t->unique(['source', 'ref']);
            $t->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_lead_filed');
    }
};
