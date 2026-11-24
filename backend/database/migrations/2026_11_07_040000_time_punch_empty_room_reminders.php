<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Empty-room clock-out reminders (2026-11-07) - staff:empty-room-clock-out.
 *
 * Anthony: "with educators who have no kids checked in and are still clocked in - send app reminders that they are
 * still clocked in and to clock out". Per open punch: when the last reminder went and how many have gone, so each
 * one is sent once (claimed before sending) and the repeats stop at the cap. Reset when children are back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('time_punches', function (Blueprint $t) {
            if (! Schema::hasColumn('time_punches', 'empty_reminded_at')) $t->timestamp('empty_reminded_at')->nullable();
            if (! Schema::hasColumn('time_punches', 'empty_reminders')) $t->unsignedTinyInteger('empty_reminders')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('time_punches', function (Blueprint $t) {
            $t->dropColumn(['empty_reminded_at', 'empty_reminders']);
        });
    }
};
