<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ONTARIO LICENSED AGE GROUP per room (2026-10-13; O. Reg. 137/15 s.8, s.8.1, Schedule 1).
 * rooms.age_group is an enum with one "school_age" and no family age group; Ontario licenses
 * six age categories plus family age groups. Null = derive from age_group (school_age →
 * primary/junior school age). Read only by App\Support\OntarioRatios.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rooms') && ! Schema::hasColumn('rooms', 'licensed_age_group')) {
            Schema::table('rooms', function (Blueprint $t) {
                $t->string('licensed_age_group', 20)->nullable()->after('age_group');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rooms') && Schema::hasColumn('rooms', 'licensed_age_group')) {
            Schema::table('rooms', function (Blueprint $t) {
                $t->dropColumn('licensed_age_group');
            });
        }
    }
};
