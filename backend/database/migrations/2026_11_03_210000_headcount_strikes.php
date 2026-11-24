<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * 2026-11-03 (Anthony: "if there are missed head counts add this to the educator daily summary and also in the daily
 * overview section where headcount lives, mark this as a strike").
 *
 * One row per "Headcount due" reminder sent to an educator (headcounts:remind writes it as pending). Fifteen minutes
 * later headcounts:strikes decides it: the room was counted -> cleared; the educator clocked out -> cleared; otherwise
 * -> strike, and the centre's directors are told. due_at is the reminder time (UTC, like every other stamp).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('headcount_strikes')) return;
        Schema::create('headcount_strikes', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id');
            $t->unsignedBigInteger('centre_id');
            $t->unsignedBigInteger('room_id');
            $t->unsignedBigInteger('user_id');
            $t->dateTime('due_at');
            $t->string('status', 10)->default('pending');   // pending | strike | cleared
            $t->string('cleared_by', 20)->nullable();        // counted | clocked_out
            $t->dateTime('decided_at')->nullable();
            $t->dateTime('created_at')->nullable();
            $t->unique(['user_id', 'room_id', 'due_at'], 'hc_strike_once');
            $t->index(['centre_id', 'status', 'due_at'], 'hc_strike_centre');
            $t->index(['status', 'due_at'], 'hc_strike_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('headcount_strikes');
    }
};
