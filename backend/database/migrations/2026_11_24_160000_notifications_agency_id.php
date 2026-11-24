<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Every bell row names the agency it is about (2026-11-24, Anthony: test agencies send nothing). Notify::write fills it;
   a push for a test agency is never sent, the row still shows in the bell. NULL = platform-level or unknown. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('notifications', 'agency_id')) {
            Schema::table('notifications', function (Blueprint $t) {
                $t->unsignedBigInteger('agency_id')->nullable()->after('user_id');
                $t->index('agency_id', 'notifications_agency_id_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('notifications', 'agency_id')) {
            Schema::table('notifications', function (Blueprint $t) {
                $t->dropIndex('notifications_agency_id_idx');
                $t->dropColumn('agency_id');
            });
        }
    }
};
