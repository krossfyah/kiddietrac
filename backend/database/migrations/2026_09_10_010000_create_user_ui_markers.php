<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE A PERSON HAS READ UP TO — on the account, not on the device.
 *
 * The bell badge, the what's-new dot and the sales-chat badge each remember how far you
 * have read in localStorage. That works until you own two devices: clearing the bell on a
 * laptop leaves it lit on the phone, and every fresh browser starts by claiming everything
 * is unread. It is also lost by a private window, a cleared cache, or a new machine — so
 * the honest description of the old behaviour is "this badge is per-browser", which is not
 * what a badge means to anybody looking at one.
 *
 * A row per person per marker. Deliberately generic rather than three columns: these three
 * are the ones that exist today, and the next thing that needs a read position should not
 * need a migration to get one.
 *
 * The VALUE is a string because the three markers do not agree on a type — a timestamp, a
 * date, and a numeric id. Comparison is the controller's job; see UiMarkerController, which
 * compares numerically when both sides are numbers and lexically otherwise.
 * (Anthony, 2026-09-10)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_ui_markers', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('marker_key', 60);
            $t->string('marker_value', 190);
            $t->timestamp('updated_at')->nullable();

            /* One row per person per marker, enforced here rather than in code: two
               devices writing at the same moment is the ordinary case for this table,
               not an edge one. */
            $t->unique(['user_id', 'marker_key'], 'user_ui_markers_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ui_markers');
    }
};
