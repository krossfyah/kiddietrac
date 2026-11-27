<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * marketing_campaign_sends (2026-11-27): one row per person a campaign email was for, written as the
 * send happens (App\Support\CampaignSends). What happened at send time (sent / failed / held back) and
 * the email_logs row it became, so Marketing > Campaigns > Recipients & stats can show per-person
 * status, opens and bounces from the real log. Past campaigns are backfilled from the logs where a
 * match exists (source = 'backfill'). Additive and idempotent.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('marketing_campaign_sends')) return;
        Schema::create('marketing_campaign_sends', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->unsignedBigInteger('campaign_id');
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('email', 190);
            $t->string('first_name', 80)->nullable();
            $t->string('last_name', 80)->nullable();
            $t->string('outcome', 20);                 // sent | failed | suppressed | unknown
            $t->string('error', 500)->nullable();
            $t->string('tracking_token', 64)->nullable();
            $t->unsignedBigInteger('email_log_id')->nullable();
            $t->string('source', 12)->default('send'); // send | backfill
            $t->timestamp('sent_at')->nullable();
            $t->unique(['campaign_id', 'email']);
            $t->index('email_log_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_campaign_sends');
    }
};
