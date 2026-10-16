<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Notes on a bus route and on each child's stop (2026-10-16, Anthony: "bus route should
   also allow you to choose children and add notes"). */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('bus_routes', 'notes')) {
            Schema::table('bus_routes', fn (Blueprint $t) => $t->text('notes')->nullable()->after('vehicle_label'));
        }
        if (! Schema::hasColumn('bus_route_children', 'notes')) {
            Schema::table('bus_route_children', fn (Blueprint $t) => $t->text('notes')->nullable()->after('stop_order'));
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('bus_routes', 'notes')) Schema::table('bus_routes', fn (Blueprint $t) => $t->dropColumn('notes'));
        if (Schema::hasColumn('bus_route_children', 'notes')) Schema::table('bus_route_children', fn (Blueprint $t) => $t->dropColumn('notes'));
    }
};
