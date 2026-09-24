<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sms_messages holds only what WE sent.
 *
 * Telnyx has been delivering replies to /sms/telnyx/inbound/{agency} all along - the
 * webhook verifies them and acts on STOP, START and HELP - but nothing was ever stored.
 * A keyword reply worked and left no trace; anything else a parent typed was read by
 * the handler and dropped. "Did she reply?" had no answer.
 *
 * One additive column. Everything already on file was sent BY us, so the default is
 * the truth for every existing row and no backfill is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sms_messages', 'direction')) {
            return;
        }
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->string('direction', 3)->default('out')->after('agency_id');
            // The list reads "this agency's traffic, newest first" and now also
            // "…the replies", so the pair is what gets filtered on.
            $table->index(['agency_id', 'direction'], 'sms_messages_agency_direction_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('sms_messages', 'direction')) {
            return;
        }
        Schema::table('sms_messages', function (Blueprint $table) {
            $table->dropIndex('sms_messages_agency_direction_idx');
            $table->dropColumn('direction');
        });
    }
};
