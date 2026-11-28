<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📻 WALKIE: KEEP THE LAST CHAT (2026-11-28, Anthony: "when using walkie talkie and selected a user to chat keep that as
 * the last chat to make it easier to continue talking"). walkie_prefs.last_targets = JSON {agency id: channel key} - the
 * person or channel each person last talked to or picked, per agency, so the Walkie-talkie screen, the top-bar 📻 and
 * every device open on it again. WalkieController::me() hands it back only while it is still reachable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('walkie_prefs', 'last_targets')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->text('last_targets')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('walkie_prefs', 'last_targets')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->dropColumn('last_targets');
            });
        }
    }
};
