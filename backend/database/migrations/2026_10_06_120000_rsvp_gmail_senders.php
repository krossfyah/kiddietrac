<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/* RSVP sender accounts that connect Gmail by signing in (2026-10-06).

   This host blocks all outbound SMTP, so a sender is now usually type 'gmail_oauth': it
   holds the connected Google address and an encrypted refresh token (never sent to the
   browser) and sends through the Gmail API over HTTPS. 'smtp' rows keep working in code
   for a host that allows it. A Gmail sender has no SMTP server and, until it is connected,
   no address — so both columns become nullable. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rsvp_senders', function (Blueprint $t) {
            if (! Schema::hasColumn('rsvp_senders', 'type')) $t->string('type', 20)->default('smtp')->after('agency_id');
            if (! Schema::hasColumn('rsvp_senders', 'google_email')) $t->string('google_email', 190)->nullable();
            if (! Schema::hasColumn('rsvp_senders', 'google_refresh_enc')) $t->text('google_refresh_enc')->nullable();
            if (! Schema::hasColumn('rsvp_senders', 'google_access_enc')) $t->text('google_access_enc')->nullable();
            if (! Schema::hasColumn('rsvp_senders', 'google_token_expires_at')) $t->timestamp('google_token_expires_at')->nullable();
            if (! Schema::hasColumn('rsvp_senders', 'google_connected_at')) $t->timestamp('google_connected_at')->nullable();
            if (! Schema::hasColumn('rsvp_senders', 'google_status')) $t->string('google_status', 20)->nullable();
        });
        DB::statement('ALTER TABLE rsvp_senders MODIFY smtp_host VARCHAR(190) NULL');
        DB::statement('ALTER TABLE rsvp_senders MODIFY from_address VARCHAR(190) NULL');
    }

    public function down(): void
    {
        Schema::table('rsvp_senders', function (Blueprint $t) {
            foreach (['type', 'google_email', 'google_refresh_enc', 'google_access_enc', 'google_token_expires_at', 'google_connected_at', 'google_status'] as $c) {
                if (Schema::hasColumn('rsvp_senders', $c)) $t->dropColumn($c);
            }
        });
    }
};
