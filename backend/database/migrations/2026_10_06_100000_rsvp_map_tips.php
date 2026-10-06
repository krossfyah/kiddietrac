<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* A map of the venue and tips for attending, in the RSVP confirmation email (2026-10-06).
   show_map: on by default — a location is what makes a map possible.
   map_pin:  optional exact spot (a Google Maps link or "lat, lng"), for venues the
             address lookup cannot place precisely — rural numbers often are not mapped.
   attend_tips: one tip per line. */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('rsvp_events', function (Blueprint $t) {
            if (! Schema::hasColumn('rsvp_events', 'show_map')) $t->boolean('show_map')->default(true);
            if (! Schema::hasColumn('rsvp_events', 'map_pin')) $t->string('map_pin', 500)->nullable();
            if (! Schema::hasColumn('rsvp_events', 'attend_tips')) $t->text('attend_tips')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rsvp_events', function (Blueprint $t) {
            foreach (['show_map', 'map_pin', 'attend_tips'] as $c) if (Schema::hasColumn('rsvp_events', $c)) $t->dropColumn($c);
        });
    }
};
