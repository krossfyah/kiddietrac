<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A text message becomes a chat message, and a chat reply can go back as a text.
 *
 * Two additive columns, both nullable, both meaning "ordinary in-app" when null - so
 * every conversation and message already on file keeps behaving exactly as it did.
 *
 *   conversations.channel  'sms' marks the thread that is wired to a handset. Only
 *                          that thread relays staff replies out as texts; every other
 *                          conversation is untouched, which is the whole point of
 *                          marking it rather than guessing from the subject.
 *
 *   messages.via           'sms' on the individual message, so the thread can show
 *                          which lines arrived by text and which were typed in the
 *                          app. A thread can hold both once somebody opts out.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('conversations', 'channel')) {
            Schema::table('conversations', function (Blueprint $table) {
                $table->string('channel', 12)->nullable()->after('subject');
            });
        }
        if (! Schema::hasColumn('messages', 'via')) {
            Schema::table('messages', function (Blueprint $table) {
                $table->string('via', 12)->nullable()->after('body');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('conversations', 'channel')) {
            Schema::table('conversations', fn (Blueprint $t) => $t->dropColumn('channel'));
        }
        if (Schema::hasColumn('messages', 'via')) {
            Schema::table('messages', fn (Blueprint $t) => $t->dropColumn('via'));
        }
    }
};
