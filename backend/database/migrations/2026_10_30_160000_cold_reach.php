<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales → Leads → Cold reach (2026-10-30). Prospects are people we reach out to who are NOT leads yet (a centre found
 * online, a list from an event). Every call, email and note is a touch; an email touch carries its own open-tracking
 * token. Converting a prospect creates a real sales_leads row and links it back.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_prospects')) {
            Schema::create('sales_prospects', function (Blueprint $t) {
                $t->id();
                $t->string('name', 160)->nullable();          // the person
                $t->string('organization', 200)->nullable();  // the centre / business
                $t->string('title', 120)->nullable();
                $t->string('email', 190)->nullable()->index();
                $t->string('phone', 40)->nullable();
                $t->string('website', 255)->nullable();
                $t->string('city', 120)->nullable();
                $t->string('province', 60)->nullable();
                $t->string('country', 2)->nullable();
                $t->string('source', 60)->nullable();
                $t->string('list_name', 120)->nullable()->index();  // which import / list it came from
                $t->string('consent_basis', 40)->default('implied_published');   // SalesCampaigns::CONSENT key
                $t->string('status', 30)->default('new')->index();  // new|contacted|engaged|replied|interested|not_interested|do_not_contact|converted
                $t->unsignedBigInteger('owner_id')->nullable()->index();
                $t->unsignedBigInteger('lead_id')->nullable()->index();
                $t->unsignedTinyInteger('follow_up_step')->default(0);   // follow-ups already done in the sequence
                $t->boolean('follow_ups_paused')->default(false);
                $t->unsignedTinyInteger('reminded_step')->nullable();   // the due step the owner was already told about
                $t->timestamp('next_follow_up_at')->nullable()->index();
                $t->timestamp('last_contacted_at')->nullable();
                $t->timestamp('last_opened_at')->nullable();
                $t->timestamp('replied_at')->nullable();
                $t->unsignedInteger('emails_sent')->default(0);
                $t->unsignedInteger('opens')->default(0);
                $t->unsignedInteger('calls')->default(0);
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('sales_prospect_touches')) {
            Schema::create('sales_prospect_touches', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('prospect_id')->index();
                $t->string('kind', 20);              // email|call|note|sms
                $t->string('outcome', 40)->nullable();   // call: no_answer|voicemail|connected|interested|not_interested|wrong_number; email: sent|failed
                $t->unsignedTinyInteger('step')->default(0);   // 0 = first touch, 1.. = follow-up number
                $t->boolean('automatic')->default(false);
                $t->string('subject', 255)->nullable();
                $t->mediumText('body')->nullable();
                $t->string('token', 40)->nullable()->unique();
                $t->timestamp('opened_at')->nullable();
                $t->unsignedInteger('open_count')->default(0);
                $t->string('error', 300)->nullable();
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_prospect_touches');
        Schema::dropIfExists('sales_prospects');
    }
};
