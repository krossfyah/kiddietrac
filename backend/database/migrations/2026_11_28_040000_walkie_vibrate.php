<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📻 WALKIE VIBRATION (2026-11-28, Anthony: "walkie-talkie should also vibrate phone"). walkie_prefs.vibrate = the
 * person's "Walkie vibration" switch (My settings › Notifications, next to Walkie sounds). On by default.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('walkie_prefs', 'vibrate')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->boolean('vibrate')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('walkie_prefs', 'vibrate')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->dropColumn('vibrate');
            });
        }
    }
};
