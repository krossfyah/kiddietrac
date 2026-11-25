<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Outlook / Google calendars connected through the mail client (2026-11-25, Anthony: "make it so the calendar is
   connected to the email that will be setup via the mail client ... (optional)"). App\Support\ExternalCalendar.

   calendar_links        a calendar someone switched on: their own (scope personal) or the agency's (scope agency,
                         one per agency). The sign-in itself stays on email_accounts (oauth_token, flag 'cal').
   calendar_event_links  a KiddieTrac meeting written to one of those calendars: the provider's id, so a change moves
                         it and a cancel removes it, and the import never shows it a second time. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_links')) {
            Schema::create('calendar_links', function (Blueprint $t) {
                $t->id();
                $t->string('scope', 16);                              // personal | agency
                $t->unsignedBigInteger('user_id')->index();           // who connected it
                $t->unsignedBigInteger('agency_id')->nullable()->index();
                $t->unsignedBigInteger('email_account_id')->index();
                $t->string('provider', 16);                           // microsoft | google
                $t->string('address', 190)->nullable();               // the mailbox whose calendar it is
                $t->string('status', 16)->default('connected');       // connected | error
                $t->string('status_detail', 500)->nullable();
                $t->timestamp('last_read_at')->nullable();
                $t->timestamp('last_error_at')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('calendar_event_links')) {
            Schema::create('calendar_event_links', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('meeting_id')->index();
                $t->unsignedBigInteger('calendar_link_id')->index();
                $t->string('external_id', 512);
                $t->string('ical_uid', 512)->nullable()->index();
                $t->string('etag', 255)->nullable();
                $t->boolean('with_attendees')->default(false);        // the provider sent the invitations
                $t->timestamp('responses_read_at')->nullable();
                $t->timestamps();
                $t->unique(['meeting_id', 'calendar_link_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_event_links');
        Schema::dropIfExists('calendar_links');
    }
};
