<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-enrolment replies (2026-11-28, App\Support\ReenrolReplies).
 *  - reenrollment_responses: who answered, how (portal | email link), when they were reminded.
 *  - reenrollment_campaigns: who launched it, when the reminder went out (the guard against reminding twice).
 *  - reenrolment_reply_links: the signed link in the email. One family + one guardian + one campaign; only the
 *    SHA-256 of the token is kept; it works until the end of the deadline day in the agency's timezone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reenrollment_responses', function (Blueprint $t) {
            if (! Schema::hasColumn('reenrollment_responses', 'responded_by_user_id')) $t->unsignedBigInteger('responded_by_user_id')->nullable()->after('responded_at');
            if (! Schema::hasColumn('reenrollment_responses', 'responded_via')) $t->string('responded_via', 10)->nullable()->after('responded_by_user_id');
            if (! Schema::hasColumn('reenrollment_responses', 'reminded_at')) $t->dateTime('reminded_at')->nullable()->after('responded_via');
        });
        Schema::table('reenrollment_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('reenrollment_campaigns', 'created_by_id')) $t->unsignedBigInteger('created_by_id')->nullable()->after('total_renewed');
            if (! Schema::hasColumn('reenrollment_campaigns', 'reminder_sent_at')) $t->dateTime('reminder_sent_at')->nullable()->after('sent_at');
        });
        if (! Schema::hasTable('reenrolment_reply_links')) {
            Schema::create('reenrolment_reply_links', function (Blueprint $t) {
                $t->id();
                $t->char('token_hash', 64)->unique();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('campaign_id');
                $t->unsignedBigInteger('family_id');
                $t->unsignedBigInteger('guardian_user_id');
                $t->dateTime('expires_at');
                $t->dateTime('last_used_at')->nullable();
                $t->timestamp('created_at')->nullable();
                $t->index(['campaign_id', 'family_id'], 'idx_rrl_campaign_family');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reenrolment_reply_links');
        Schema::table('reenrollment_campaigns', function (Blueprint $t) {
            foreach (['created_by_id', 'reminder_sent_at'] as $c) if (Schema::hasColumn('reenrollment_campaigns', $c)) $t->dropColumn($c);
        });
        Schema::table('reenrollment_responses', function (Blueprint $t) {
            foreach (['responded_by_user_id', 'responded_via', 'reminded_at'] as $c) if (Schema::hasColumn('reenrollment_responses', $c)) $t->dropColumn($c);
        });
    }
};
