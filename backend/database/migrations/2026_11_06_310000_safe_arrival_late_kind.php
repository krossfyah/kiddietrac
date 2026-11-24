<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RUNNING LATE, BOTH ENDS OF THE DAY (2026-11-06, Anthony: "the late function should apply for parents dropping and
 * picking up late to supress the safe arrival alerts and notify the educator about the situation").
 *   kind   arrival (drop-off; Safe Arrival) | departure (pickup; the end-of-day check, App\Support\SafeDeparture)
 *   source staff | parent
 * One row per child, day and kind.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('safe_arrival_late') || Schema::hasColumn('safe_arrival_late', 'kind')) {
            return;
        }
        Schema::table('safe_arrival_late', function (Blueprint $t) {
            $t->string('kind', 10)->default('arrival')->after('child_id');
            $t->string('source', 10)->default('staff')->after('note');
        });
        Schema::table('safe_arrival_late', function (Blueprint $t) {
            $t->unique(['child_id', 'late_date', 'kind'], 'safe_arrival_late_child_day_kind_unique');
        });
        Schema::table('safe_arrival_late', function (Blueprint $t) {
            $t->dropUnique('safe_arrival_late_child_id_late_date_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('safe_arrival_late', 'kind')) {
            return;
        }
        Schema::table('safe_arrival_late', function (Blueprint $t) {
            $t->unique(['child_id', 'late_date'], 'safe_arrival_late_child_id_late_date_unique');
            $t->dropUnique('safe_arrival_late_child_day_kind_unique');
            $t->dropColumn(['kind', 'source']);
        });
    }
};
