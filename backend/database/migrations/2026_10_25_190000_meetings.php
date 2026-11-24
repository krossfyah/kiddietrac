<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MEETINGS (2026-10-25, Anthony: "add ability to add a meeting in the calendar with adding
 * others, teams meeting etc (like how outlook does it). Under bookings add the ability to add a
 * meeting from there as well").
 *
 * scope 'platform' = the KiddieTrac team (Sales Calendar, Bookings): Teams made on sales@.
 * scope 'agency'   = an agency's own meeting on its Calendar, with a link it pastes.
 * Every attendee gets an invitation (.ics + Accept / Maybe / Decline); changes and cancellations
 * go to them too. App\Support\Meetings, MeetingController.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('meetings')) {
            Schema::create('meetings', function (Blueprint $t) {
                $t->id();
                $t->string('scope', 10);                              // platform | agency
                $t->unsignedBigInteger('agency_id')->nullable();      // agency scope only
                $t->unsignedBigInteger('centre_id')->nullable();      // optional: one centre's meeting
                $t->string('title', 200);
                $t->text('description')->nullable();
                $t->string('location', 255)->nullable();
                $t->dateTime('starts_at');                            // UTC
                $t->dateTime('ends_at');                              // UTC
                $t->boolean('all_day')->default(false);
                $t->string('timezone', 64);                           // the zone it was planned in
                $t->string('video', 10)->default('none');             // teams | link | none
                $t->text('join_url')->nullable();
                $t->string('graph_event_id', 255)->nullable();        // the sales@ Outlook event (platform Teams)
                $t->unsignedBigInteger('organizer_id');
                $t->string('status', 12)->default('scheduled');       // scheduled | cancelled
                $t->unsignedInteger('sequence')->default(0);          // iTIP SEQUENCE: bumps on every change
                $t->unsignedBigInteger('booking_id')->nullable();     // from Sales → Bookings
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->string('cancel_reason', 500)->nullable();
                $t->timestamp('cancelled_at')->nullable();
                $t->timestamps();
                $t->index(['scope', 'agency_id', 'starts_at']);
                $t->index(['organizer_id', 'starts_at']);
            });
        }
        if (! Schema::hasTable('meeting_attendees')) {
            Schema::create('meeting_attendees', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('meeting_id');
                $t->unsignedBigInteger('user_id')->nullable();        // a KiddieTrac user
                $t->string('email', 190);
                $t->string('name', 190)->nullable();
                $t->string('kind', 12)->default('guest');             // staff | parent | lead | contact | guest
                $t->boolean('optional')->default(false);
                $t->string('response', 10)->default('pending');       // pending | accepted | tentative | declined
                $t->timestamp('responded_at')->nullable();
                $t->timestamp('invited_at')->nullable();
                $t->timestamps();
                $t->unique(['meeting_id', 'email']);
                $t->index('user_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('meetings');
    }
};
