<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * web_chats.emailed_upto_id — the last web_chat_messages.id that went out in a transcript email (2026-11-01).
 *
 * Anthony: "why did the webchat email come in so late". It was a chat from two days earlier, mailed a second
 * time: the one-email-per-conversation guard was a 24-hour cache key, and the browser still held the session,
 * so leaving a page after the key expired sent it again. The guard now lives on the chat: a transcript goes out
 * only when it holds messages that have not been mailed yet. Chats already mailed are marked so they never repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_chats') || Schema::hasColumn('web_chats', 'emailed_upto_id')) {
            return;
        }
        Schema::table('web_chats', function (Blueprint $t) {
            $t->unsignedBigInteger('emailed_upto_id')->nullable()->after('status');
        });
        // Every chat that exists today has had its chance at an email; mark it as fully mailed.
        DB::statement('UPDATE web_chats c SET emailed_upto_id = (SELECT MAX(m.id) FROM web_chat_messages m WHERE m.web_chat_id = c.id)');
    }

    public function down(): void
    {
        if (Schema::hasColumn('web_chats', 'emailed_upto_id')) {
            Schema::table('web_chats', function (Blueprint $t) {
                $t->dropColumn('emailed_upto_id');
            });
        }
    }
};
