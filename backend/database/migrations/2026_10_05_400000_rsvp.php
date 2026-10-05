<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Event RSVPs (2026-10-05). An agency keeps TEMPLATES (the questions to ask, the invite
   email, an image, the address that hears about sign-ups); an EVENT is created from a
   template and takes a SNAPSHOT of its fields, so editing the template later never changes
   what a past event asked. Invites are one row per address per event and carry every
   timestamp the report needs (queued, sent, opened, signed up, opted out). Responses keep
   the answers with the field labels they were given under. Opt-outs are per agency: once
   somebody unsubscribes, no further invite from that agency is sent to them. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rsvp_templates')) {
            Schema::create('rsvp_templates', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('name', 160);
                $t->text('description')->nullable();
                $t->longText('fields');                       // JSON [{key,label,type,required,options,placeholder,default,help}]
                $t->string('image_url', 500)->nullable();
                $t->string('email_subject', 200);
                $t->text('email_body');
                $t->text('confirmation_message')->nullable();
                $t->string('notify_email', 160)->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('rsvp_events')) {
            Schema::create('rsvp_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('template_id')->nullable()->index();
                $t->string('title', 160);
                $t->text('description')->nullable();
                $t->dateTime('starts_at')->nullable();        // UTC
                $t->dateTime('ends_at')->nullable();          // UTC
                $t->string('location', 250)->nullable();
                $t->string('image_url', 500)->nullable();
                $t->longText('fields');                       // snapshot of the template's fields
                $t->string('email_subject', 200);
                $t->text('email_body');
                $t->text('confirmation_message')->nullable();
                $t->string('notify_email', 160);
                $t->string('public_token', 48)->unique();
                $t->string('status', 12)->default('open');    // draft | open | closed
                $t->unsignedInteger('capacity')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('rsvp_invites')) {
            Schema::create('rsvp_invites', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('event_id')->index();
                $t->string('name', 160)->nullable();
                $t->string('email', 190)->index();
                $t->string('source', 12)->default('manual');  // manual | csv | guardian | staff | public
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('token', 48)->unique();
                $t->string('send_status', 14)->default('pending'); // pending | queued | sent | suppressed | failed | skipped | not_invited
                $t->string('send_error', 500)->nullable();
                $t->unsignedBigInteger('email_log_id')->nullable();
                $t->unsignedInteger('send_count')->default(0);
                $t->dateTime('queued_at', 3)->nullable();
                $t->dateTime('sent_at', 3)->nullable();
                $t->dateTime('opened_at', 3)->nullable();      // first visit to their RSVP link
                $t->dateTime('registered_at', 3)->nullable();
                $t->dateTime('opted_out_at', 3)->nullable();
                $t->unsignedBigInteger('invited_by_id')->nullable();
                $t->timestamps();
                $t->unique(['event_id', 'email']);
            });
        }
        if (! Schema::hasTable('rsvp_responses')) {
            Schema::create('rsvp_responses', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('event_id')->index();
                $t->unsignedBigInteger('invite_id')->nullable()->index();
                $t->string('email', 190)->index();
                $t->string('name', 160)->nullable();
                $t->longText('answers');                      // JSON {key: {label, value}}
                $t->string('via', 8)->default('invite');      // invite | link
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->string('confirm_status', 14)->nullable(); // queued | sent | suppressed | failed
                $t->string('notify_status', 14)->nullable();
                $t->dateTime('created_at', 3)->nullable();
                $t->dateTime('updated_at', 3)->nullable();
                $t->unique(['event_id', 'email']);
            });
        }
        if (! Schema::hasTable('rsvp_optouts')) {
            Schema::create('rsvp_optouts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('email', 190);
                $t->unsignedBigInteger('event_id')->nullable();
                $t->unsignedBigInteger('invite_id')->nullable();
                $t->string('ip', 45)->nullable();
                $t->dateTime('created_at', 3)->nullable();
                $t->unique(['agency_id', 'email']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rsvp_optouts');
        Schema::dropIfExists('rsvp_responses');
        Schema::dropIfExists('rsvp_invites');
        Schema::dropIfExists('rsvp_events');
        Schema::dropIfExists('rsvp_templates');
    }
};
