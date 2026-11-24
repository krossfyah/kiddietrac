<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* The agency a super admin (or multi-agency admin) last switched to; sign-in opens there
   (2026-10-23, AuthController::setActiveAgency / landing). */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'last_agency_id')) $t->unsignedBigInteger('last_agency_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('last_agency_id'));
    }
};
