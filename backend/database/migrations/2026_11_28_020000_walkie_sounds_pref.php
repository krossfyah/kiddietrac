<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📻 WALKIE SOUNDS (2026-11-27, Anthony: "use a walkie talkie type sound like how zello has it"). walkie_prefs.sounds =
 * the person's "Walkie sounds" switch (the radio beeps; My settings › Notifications › Walkie-talkie). Default on.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('walkie_prefs', 'sounds')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->boolean('sounds')->default(true);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('walkie_prefs', 'sounds')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->dropColumn('sounds');
            });
        }
    }
};
