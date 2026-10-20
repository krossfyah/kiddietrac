<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaign "Send from" (white-label, 2026-10-20) and paced, resumable email delivery.
 *  - sender_id: an rsvp_senders row for this agency; NULL = the agency's normal delivery.
 *  - email_progress: recipients already handled, so a paced Gmail send resumes next run.
 *  - email_from: the address the email actually left from, for the record.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $t) {
            if (! Schema::hasColumn('marketing_campaigns', 'sender_id')) {
                $t->unsignedBigInteger('sender_id')->nullable()->after('channel');
            }
            if (! Schema::hasColumn('marketing_campaigns', 'email_progress')) {
                $t->unsignedInteger('email_progress')->default(0)->after('email_failure_count');
            }
            if (! Schema::hasColumn('marketing_campaigns', 'email_from')) {
                $t->string('email_from', 190)->nullable()->after('email_progress');
            }
        });
    }

    public function down(): void
    {
        Schema::table('marketing_campaigns', function (Blueprint $t) {
            foreach (['email_from', 'email_progress', 'sender_id'] as $c) {
                if (Schema::hasColumn('marketing_campaigns', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
