<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LEAK GUARD (2026-11-27, Anthony: "leaks should have a guard to constantly check for cross leaks").
 *
 *  leak_canaries        every planted canary row (agency, table, row id, marker) - kt:leak-canary
 *  leak_guard_runs      one row per kt:leak-probe / kt:leak-integrity run (counts + findings json)
 *  leak_sentinel_hits   the live egress sentinel (LeakSentinel middleware): one row per route/user/foreign agency/day,
 *                       never a response body.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('leak_canaries')) {
            Schema::create('leak_canaries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('kind', 40);          // child, family, user:educator, message ...
                $t->string('table_name', 64);
                $t->unsignedBigInteger('row_id');
                $t->string('marker', 40);
                $t->timestamp('created_at')->nullable();
                $t->unique(['agency_id', 'kind']);
                $t->index(['table_name', 'row_id']);
            });
        }
        if (! Schema::hasTable('leak_guard_runs')) {
            Schema::create('leak_guard_runs', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 20);          // probe | integrity
                $t->string('mode', 40)->nullable(); // all | slice 3/24 | gate
                $t->timestamp('started_at')->nullable();
                $t->timestamp('finished_at')->nullable();
                $t->unsignedInteger('routes_checked')->default(0);
                $t->unsignedInteger('calls')->default(0);
                $t->unsignedInteger('findings')->default(0);
                $t->unsignedInteger('blind')->default(0);
                $t->mediumText('summary')->nullable();   // json: coverage + findings (no response bodies)
                $t->timestamp('alerted_at')->nullable();
                $t->index(['kind', 'id']);
            });
        }
        if (! Schema::hasTable('leak_sentinel_hits')) {
            Schema::create('leak_sentinel_hits', function (Blueprint $t) {
                $t->id();
                $t->date('day');
                $t->string('method', 8);
                $t->string('route', 191);
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('active_agency_id')->nullable();
                $t->unsignedBigInteger('foreign_agency_id');
                $t->string('signal', 20);        // agency_id | canary
                $t->unsignedInteger('hits')->default(1);
                $t->timestamp('first_at')->nullable();
                $t->timestamp('last_at')->nullable();
                $t->timestamp('alerted_at')->nullable();
                $t->unique(['day', 'route', 'user_id', 'foreign_agency_id', 'signal'], 'leak_sentinel_uniq');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leak_sentinel_hits');
        Schema::dropIfExists('leak_guard_runs');
        Schema::dropIfExists('leak_canaries');
    }
};
