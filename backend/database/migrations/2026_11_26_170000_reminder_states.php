<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Reminder toasts in the portal (2026-11-26, Anthony: "wire up reminders for upcoming events/meetings as a toast in
   the portal"; he chose "Like Outlook"). One row per person per reminder they dismissed or snoozed, so the choice
   follows them to every tab and device and a reload never brings a dismissed reminder back. The key carries the
   item's start time: an event that moves gets a new key, so its reminder comes back once. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('reminder_states')) return;
        Schema::create('reminder_states', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('item_key', 120);
            $t->string('state', 12);                 // dismissed | snoozed
            $t->timestamp('snooze_until')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'item_key'], 'reminder_state_once');
            $t->index(['user_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reminder_states');
    }
};
