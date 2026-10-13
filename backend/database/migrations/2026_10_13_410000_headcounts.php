<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* ACTIVE SUPERVISION (2026-10-13). Ontario O. Reg. 137/15 s.11 as amended by O. Reg. 197/26
   (in force 2027-01-01): a written supervision policy saying when, how and how often children
   are counted — transitions, washroom trips, arrival/dismissal, field trips, playground gates —
   and what happens when a child is unaccounted for. `headcounts` is the record of each count.

   New s.6.1(2): the policy names who acts for the supervisor when the supervisor is off-site
   and something urgent happens. `centre_acting_supervisors` is that ordered list, per centre. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('headcounts')) {
            Schema::create('headcounts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedBigInteger('field_trip_id')->nullable();
                $t->unsignedBigInteger('taken_by');
                $t->string('context', 20);                 // transition | washroom | outdoor_in | outdoor_out | arrival | dismissal | field_trip | routine | other
                $t->string('location', 200)->nullable();
                $t->unsignedInteger('expected_count');
                $t->unsignedInteger('counted_count');
                $t->boolean('all_accounted');
                $t->json('expected_child_ids')->nullable();
                $t->json('seen_child_ids')->nullable();
                $t->json('missing_child_ids')->nullable();
                $t->text('note')->nullable();
                $t->dateTime('taken_at');                  // UTC
                $t->dateTime('alerted_at')->nullable();    // "Alert director" pressed
                $t->unsignedBigInteger('alerted_by')->nullable();
                $t->unsignedInteger('alert_recipients')->nullable();
                $t->dateTime('resolved_at')->nullable();   // every missing child found
                $t->unsignedBigInteger('resolved_by')->nullable();
                $t->string('resolution_note', 1000)->nullable();
                $t->timestamps();
                $t->index(['centre_id', 'taken_at']);
                $t->index(['room_id', 'taken_at']);
                $t->index(['agency_id', 'taken_at']);
            });
        }

        if (! Schema::hasTable('centre_acting_supervisors')) {
            Schema::create('centre_acting_supervisors', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedSmallInteger('position')->default(1);
                $t->unsignedBigInteger('set_by')->nullable();
                $t->timestamps();
                $t->unique(['centre_id', 'user_id']);
                $t->index(['centre_id', 'position']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('centre_acting_supervisors');
        Schema::dropIfExists('headcounts');
    }
};
