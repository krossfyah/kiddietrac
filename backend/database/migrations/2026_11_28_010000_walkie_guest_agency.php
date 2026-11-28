<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📻 WALKIE-TALKIE: A SUPER ADMIN AS A GUEST (2026-11-27, Anthony: "Give me full access").
 * walkie_prefs.guest_agency_id = the ONE agency a platform admin is in for the walkie (the agency he last used it in,
 * from X-Active-Agency-Id). App\Support\Walkie::guests() reads it; nothing else does.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('walkie_prefs', 'guest_agency_id')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->unsignedBigInteger('guest_agency_id')->nullable()->index();
                $t->dateTime('guest_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('walkie_prefs', 'guest_agency_id')) {
            Schema::table('walkie_prefs', function (Blueprint $t) {
                $t->dropIndex(['guest_agency_id']);
                $t->dropColumn(['guest_agency_id', 'guest_at']);
            });
        }
    }
};
