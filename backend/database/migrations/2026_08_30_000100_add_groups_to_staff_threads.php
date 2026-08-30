<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Group conversations for Team messages (2026-08-30).
 *
 * staff_threads / staff_thread_participants were built as a participants model from the
 * start precisely so groups would be an add rather than a rewrite — the fan-out in
 * TeamChatController::post() already loops every other participant. What was missing was
 * a name for the group, a way to tell a group from a 1:1, and a message kind for the
 * "X added Y" line that makes a growing conversation readable.
 *
 * Nothing here changes an existing thread: is_group defaults to 0 and title stays null,
 * so every current 1:1 keeps behaving exactly as it does today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_threads', function (Blueprint $table) {
            // What the group is called. Null on a 1:1, where the name is the other person.
            $table->string('title', 120)->nullable()->after('agency_id');
            // Not derived from "more than two participants": a group of two that somebody
            // named is still a group, and it must not collapse back into a 1:1 the moment
            // a member leaves.
            $table->boolean('is_group')->default(false)->after('title');
        });

        Schema::table('staff_messages', function (Blueprint $table) {
            // "Sarah added Marcus" is part of the conversation, not a message from anyone.
            // The family `messages` table already carries this column; the staff one did
            // not, so there was no way to render a line that is not a chat bubble.
            $table->boolean('is_system')->default(false)->after('attachments');
        });
    }

    public function down(): void
    {
        Schema::table('staff_threads', function (Blueprint $table) {
            $table->dropColumn(['title', 'is_group']);
        });
        Schema::table('staff_messages', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });
    }
};
