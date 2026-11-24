<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* HEADCOUNTS COUNT TOWARDS SAFE ARRIVAL (2026-11-03). A room headcount now also lists the room's children who are
   scheduled today but not signed in yet, so an educator can tick a child who is there. These two columns record that
   list and the ticks, apart from the signed-in count (expected/seen/missing stay exactly as before, so the
   supervision record and its "N of M accounted for" do not change). App\Support\SafeArrival::headcountCounted()
   reads them. NULL = the list was not shown (an older app, Safe Arrival off, or headcounts set to be ignored). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('headcounts', function (Blueprint $t) {
            if (! Schema::hasColumn('headcounts', 'unsigned_offered_child_ids')) {
                $t->json('unsigned_offered_child_ids')->nullable()->after('missing_child_ids');
            }
            if (! Schema::hasColumn('headcounts', 'unsigned_seen_child_ids')) {
                $t->json('unsigned_seen_child_ids')->nullable()->after('unsigned_offered_child_ids');
            }
        });
    }

    public function down(): void
    {
        Schema::table('headcounts', function (Blueprint $t) {
            $t->dropColumn(['unsigned_offered_child_ids', 'unsigned_seen_child_ids']);
        });
    }
};
