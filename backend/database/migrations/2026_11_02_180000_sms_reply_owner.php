<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHOSE TEXT IS THIS A REPLY TO? (2026-11-02, Anthony: "for SMS being responded to it should show up in the message
 * centre - for example safia sent out an announcement today which included SMS and someone responded - Safia should
 * see this response to her announcement in the message centre").
 *
 * Filed on the INBOUND row at the moment it arrives (App\Support\SmsReplies::attribute), never derived later from
 * whoever happens to look:
 *   reply_to_sms_id  - the last text we sent that number in that agency before it answered;
 *   owner_user_id    - the person who sent that text (sms_messages.sent_by_user_id, or the announcement's author);
 *   announcement_id  - the announcement that text carried, when it was one (shown as the reply's context).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            if (! Schema::hasColumn('sms_messages', 'reply_to_sms_id')) $t->unsignedBigInteger('reply_to_sms_id')->nullable();
            if (! Schema::hasColumn('sms_messages', 'owner_user_id')) $t->unsignedBigInteger('owner_user_id')->nullable();
            if (! Schema::hasColumn('sms_messages', 'announcement_id')) $t->unsignedBigInteger('announcement_id')->nullable();
        });
        try {
            Schema::table('sms_messages', function (Blueprint $t) {
                $t->index(['agency_id', 'owner_user_id'], 'sms_messages_agency_owner_idx');
            });
        } catch (\Throwable $e) {
            // already there
        }
    }

    public function down(): void
    {
        Schema::table('sms_messages', function (Blueprint $t) {
            try { $t->dropIndex('sms_messages_agency_owner_idx'); } catch (\Throwable $e) {}
            foreach (['reply_to_sms_id', 'owner_user_id', 'announcement_id'] as $c) {
                if (Schema::hasColumn('sms_messages', $c)) $t->dropColumn($c);
            }
        });
    }
};
