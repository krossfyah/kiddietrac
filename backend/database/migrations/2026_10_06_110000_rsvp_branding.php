<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Event RSVPs — branding (2026-10-06).

   Anthony: "make event RSVP not branding to the agency with a setting and use no kiddietrac
   or agency header or footers with this setting".

   'agency' — the agency-branded look every RSVP had until now (the default, so nothing
   already sent or set up changes). 'none' — no KiddieTrac or agency name, logo, header or
   footer in the invitation, the confirmation, the sign-up notices or the public page: only
   the event's own image, title, text, details and questions, and a plain unsubscribe line.
   Templates carry a default; each event keeps its own. */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['rsvp_templates', 'rsvp_events'] as $table) {
            if (! Schema::hasColumn($table, 'branding')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->string('branding', 10)->default('agency');
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['rsvp_templates', 'rsvp_events'] as $table) {
            if (Schema::hasColumn($table, 'branding')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropColumn('branding');
                });
            }
        }
    }
};
