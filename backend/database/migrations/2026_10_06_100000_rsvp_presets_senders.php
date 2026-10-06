<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Event RSVPs, round 2 (2026-10-06).

   SENDER ACCOUNTS: an agency keeps a short list of mail accounts and each event picks one
   (or the agency's own email). The password is Crypt-encrypted and never sent to the
   browser. rsvp_events.sender_id NULL means "the agency's own email".

   PRESETS: built-in templates live in code (App\Support\RsvpPresets); an event made from
   one records which in preset_key, with template_id NULL. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rsvp_senders')) {
            Schema::create('rsvp_senders', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->string('label', 120);
                $t->string('from_name', 120)->nullable();
                $t->string('from_address', 190);
                $t->string('reply_to', 190)->nullable();
                $t->string('smtp_host', 190);
                $t->unsignedInteger('smtp_port')->default(587);
                $t->string('smtp_encryption', 8)->default('tls');
                $t->string('smtp_user', 190)->nullable();
                $t->text('smtp_pass_enc')->nullable();
                $t->timestamp('verified_at')->nullable();
                $t->string('last_test_status', 20)->nullable();
                $t->string('last_test_error', 500)->nullable();
                $t->timestamp('last_test_at')->nullable();
                $t->unsignedBigInteger('created_by_id')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        Schema::table('rsvp_events', function (Blueprint $t) {
            if (! Schema::hasColumn('rsvp_events', 'sender_id')) {
                $t->unsignedBigInteger('sender_id')->nullable()->after('notify_email')->index();
            }
            if (! Schema::hasColumn('rsvp_events', 'preset_key')) {
                $t->string('preset_key', 40)->nullable()->after('template_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rsvp_events', function (Blueprint $t) {
            if (Schema::hasColumn('rsvp_events', 'sender_id')) $t->dropColumn('sender_id');
            if (Schema::hasColumn('rsvp_events', 'preset_key')) $t->dropColumn('preset_key');
        });
        Schema::dropIfExists('rsvp_senders');
    }
};
