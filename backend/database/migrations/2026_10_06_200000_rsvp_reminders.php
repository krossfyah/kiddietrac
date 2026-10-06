<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Event reminders (2026-10-06): "add a reminder feature in the event to be triggered by a
   schedule or manually to send out a reminder about the upcoming event".

   rsvp_reminders: one row per reminder. A schedule is either an offset before the event
   starts (offset_minutes — moves with the event if its time changes) or an absolute
   send_at (UTC). A manual "send now" is a row too, so every send has a record.

   rsvp_reminder_sends: one row per person per reminder. Every RSVP — invited or through
   the shareable link — has an rsvp_invites row, so the invite is the person; the unique
   key is what guarantees nobody gets the same reminder twice, whatever runs twice. */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('rsvp_reminders')) {
            Schema::create('rsvp_reminders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('event_id')->index();
                $t->string('audience', 20)->default('registered');          // registered | no_response | both
                $t->unsignedInteger('offset_minutes')->nullable();          // before the event starts
                $t->dateTime('send_at')->nullable();                        // absolute, UTC
                $t->string('subject', 200)->nullable();
                $t->text('message')->nullable();
                $t->string('status', 20)->default('scheduled');             // scheduled | sending | sent | cancelled | skipped
                $t->string('status_note', 255)->nullable();
                $t->boolean('manual')->default(false);
                $t->unsignedInteger('recipient_count')->default(0);
                $t->unsignedInteger('sent_count')->default(0);
                $t->unsignedInteger('failed_count')->default(0);
                $t->dateTime('sent_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->index(['status', 'send_at']);
            });
        }
        if (! Schema::hasTable('rsvp_reminder_sends')) {
            Schema::create('rsvp_reminder_sends', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('reminder_id');
                $t->unsignedBigInteger('event_id')->index();
                $t->unsignedBigInteger('invite_id')->index();
                $t->string('email', 190);
                $t->string('status', 20)->default('queued');               // queued | sent | suppressed | failed | skipped
                $t->string('error', 500)->nullable();
                $t->unsignedBigInteger('email_log_id')->nullable();
                $t->dateTime('sent_at')->nullable();
                $t->timestamps();
                $t->unique(['reminder_id', 'invite_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_reminder_sends');
        Schema::dropIfExists('rsvp_reminders');
    }
};
