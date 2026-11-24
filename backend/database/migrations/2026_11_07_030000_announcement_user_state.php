<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A parent's own read / removed state for each item in News (2026-11-07) - AnnouncementController::parentState.
 *
 * Anthony: "no kebab to delete or mark read here". A parent cannot delete the centre's announcement, so "Delete" is
 * removed-from-MY-News: hidden_at on this person's row, never touching the announcement or anyone else's inbox.
 * item_key is "a:<announcements.id>" or "p:<personal announcement id>" so both kinds in the list share one table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('announcement_user_state')) return;
        Schema::create('announcement_user_state', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('item_key', 24);
            $t->timestamp('read_at')->nullable();
            $t->timestamp('hidden_at')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_user_state');
    }
};
