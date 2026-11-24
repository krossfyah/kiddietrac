<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales mailbox + sales campaigns (2026-10-26, Anthony).
 *
 *  - email_accounts.shared_scope: 'sales' marks the ONE shared sales@ mailbox that every sales rep and superadmin
 *    opens from Sales -> Email. Personal mailboxes keep shared_scope NULL and stay private to their owner.
 *  - sales_campaigns / sales_campaign_recipients: an introduction email sent to prospects, with opens, clicks and
 *    responses (interested, booked a demo, replied, unsubscribed) tracked per person and turned into leads.
 *  - sales_suppressions: addresses that unsubscribed (CASL) - never emailed by a campaign again.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('email_accounts', 'shared_scope')) {
            Schema::table('email_accounts', function (Blueprint $t) {
                $t->string('shared_scope', 20)->nullable()->after('agency_id')->index();
            });
        }

        if (! Schema::hasTable('sales_campaigns')) {
            Schema::create('sales_campaigns', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160);
                $t->string('subject', 200);
                $t->string('preheader', 200)->nullable();
                $t->string('headline', 200)->nullable();
                $t->text('intro')->nullable();
                $t->json('highlights')->nullable();
                $t->string('video', 60)->default('a-day-with-kiddietrac');
                $t->boolean('show_plans')->default(true);
                $t->string('consent_basis', 40)->default('implied_published');
                $t->string('status', 20)->default('draft');          // draft | sending | sent | paused
                $t->unsignedBigInteger('created_by')->nullable();
                $t->unsignedBigInteger('sent_by')->nullable();
                $t->timestamp('send_started_at')->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->unsignedInteger('recipient_count')->default(0);
                $t->unsignedInteger('sent_count')->default(0);
                $t->unsignedInteger('open_count')->default(0);
                $t->unsignedInteger('click_count')->default(0);
                $t->unsignedInteger('response_count')->default(0);
                $t->unsignedInteger('unsub_count')->default(0);
                $t->timestamps();
                $t->softDeletes();
            });
        }

        if (! Schema::hasTable('sales_campaign_recipients')) {
            Schema::create('sales_campaign_recipients', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('campaign_id')->index();
                $t->unsignedBigInteger('lead_id')->nullable()->index();
                $t->string('email', 190);
                $t->string('name', 160)->nullable();
                $t->string('company', 190)->nullable();
                $t->string('consent_basis', 40)->nullable();
                $t->char('token', 40)->unique();
                $t->string('status', 20)->default('queued');          // queued | sent | failed | skipped
                $t->string('skip_reason', 60)->nullable();
                $t->string('error', 300)->nullable();
                $t->timestamp('sent_at')->nullable();
                $t->timestamp('opened_at')->nullable();
                $t->unsignedInteger('open_count')->default(0);
                $t->timestamp('clicked_at')->nullable();
                $t->unsignedInteger('click_count')->default(0);
                $t->string('last_link', 60)->nullable();
                $t->timestamp('responded_at')->nullable();
                $t->string('response', 20)->nullable();               // interested | booked | replied | unsubscribed
                $t->unsignedBigInteger('followup_activity_id')->nullable();
                $t->timestamps();
                $t->unique(['campaign_id', 'email']);
                $t->index(['campaign_id', 'status']);
                $t->index('email');
            });
        }

        if (! Schema::hasTable('sales_suppressions')) {
            Schema::create('sales_suppressions', function (Blueprint $t) {
                $t->id();
                $t->string('email', 190)->unique();
                $t->string('reason', 40)->default('unsubscribed');
                $t->unsignedBigInteger('campaign_id')->nullable();
                $t->unsignedBigInteger('added_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_suppressions');
        Schema::dropIfExists('sales_campaign_recipients');
        Schema::dropIfExists('sales_campaigns');
        if (Schema::hasColumn('email_accounts', 'shared_scope')) {
            Schema::table('email_accounts', function (Blueprint $t) { $t->dropColumn('shared_scope'); });
        }
    }
};
