<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Which mailbox and which email a lead-history entry came from, so "View full email" can open the whole message
   (2026-11-24, Anthony: "some of the logged history are truncated, can you add a button to view the entire message").
   The entry itself holds only Outlook's ~255-character preview. message_id is the internetMessageId: it survives the
   email being moved to another folder, where Graph's own id does not. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_lead_filed', function (Blueprint $t) {
            if (! Schema::hasColumn('sales_lead_filed', 'account_id')) $t->unsignedBigInteger('account_id')->nullable()->after('activity_id');
            if (! Schema::hasColumn('sales_lead_filed', 'message_id')) $t->string('message_id', 512)->nullable()->after('account_id');
            $t->index('activity_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_lead_filed', function (Blueprint $t) {
            $t->dropIndex(['activity_id']);
            $t->dropColumn(['account_id', 'message_id']);
        });
    }
};
