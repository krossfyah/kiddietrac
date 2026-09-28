<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live chat on the marketing site.
 *
 * Until now the website chat was Maya alone, written line by line to a JSONL file
 * nobody could answer from. These tables let the team see a conversation while it
 * is happening and reply into it:
 *
 *   web_chats           one row per visitor conversation, keyed by the session id
 *                       the page generates. Holds who has it (agent_id), the typing
 *                       stamps for both sides, and the last time the page polled -
 *                       which is how the inbox knows whether the visitor is still there.
 *   web_chat_messages   every line: visitor, bot (Maya), agent, system.
 *   web_chat_agents     who on the team is taking chats, and when their portal last
 *                       checked in. "Online" = available AND seen recently.
 *
 * utf8mb4 explicitly: the database default is latin1, and a chat is exactly where
 * emoji and accented names turn up.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_chats')) {
            Schema::create('web_chats', function (Blueprint $t) {
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->id();
                $t->string('session', 64)->unique();
                $t->string('name', 120)->nullable();
                $t->string('email', 160)->nullable();
                $t->string('status', 12)->default('open');
                $t->unsignedBigInteger('agent_id')->nullable();
                $t->string('agent_name', 120)->nullable();
                $t->timestamp('agent_joined_at')->nullable();
                $t->boolean('wants_human')->default(false);
                $t->timestamp('last_visitor_at')->nullable();
                $t->timestamp('last_staff_at')->nullable();
                $t->timestamp('visitor_seen_at')->nullable();
                $t->timestamp('visitor_typing_at')->nullable();
                $t->timestamp('staff_typing_at')->nullable();
                $t->string('staff_typing_name', 120)->nullable();
                $t->unsignedBigInteger('staff_read_id')->default(0);
                $t->timestamp('alerted_at')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('page', 255)->nullable();
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->timestamp('closed_at')->nullable();
                $t->timestamps();
                $t->index(['status', 'updated_at']);
            });
        }
        if (! Schema::hasTable('web_chat_messages')) {
            Schema::create('web_chat_messages', function (Blueprint $t) {
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->id();
                $t->unsignedBigInteger('web_chat_id');
                $t->string('sender', 10);
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('author', 120)->nullable();
                $t->text('body');
                $t->timestamp('created_at')->nullable();
                $t->index(['web_chat_id', 'id']);
            });
        }
        if (! Schema::hasTable('web_chat_agents')) {
            Schema::create('web_chat_agents', function (Blueprint $t) {
                $t->charset = 'utf8mb4';
                $t->collation = 'utf8mb4_unicode_ci';
                $t->unsignedBigInteger('user_id')->primary();
                $t->boolean('available')->default(true);
                $t->timestamp('last_seen_at')->nullable();
                $t->timestamp('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('web_chat_agents');
        Schema::dropIfExists('web_chat_messages');
        Schema::dropIfExists('web_chats');
    }
};
