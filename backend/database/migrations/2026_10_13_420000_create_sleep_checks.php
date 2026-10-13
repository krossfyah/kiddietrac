<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SLEEP SUPERVISION CHECKS (2026-10-13; Ontario O. Reg. 137/15 s.33.1, Licensing Manual
 * Part 5). A documented direct visual check of every sleeping child under 24 months, at
 * the frequency the centre's sleep policy sets. A check belongs to the nap it was made
 * during: nap_event_id is the daily_events row of type nap_start.
 *
 * sleep_check_alerts remembers which overdue alerts were sent, so the every-minute
 * scheduler tells people once per missed check rather than once per minute.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sleep_checks')) {
            Schema::create('sleep_checks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('centre_id');
                $t->unsignedBigInteger('room_id')->nullable();
                $t->unsignedBigInteger('child_id');
                $t->unsignedBigInteger('nap_event_id');
                $t->unsignedBigInteger('checked_by');
                $t->dateTime('checked_at');                      // UTC
                $t->enum('position', ['back', 'side', 'tummy', 'sitting', 'other'])->default('back');
                $t->boolean('breathing_ok')->default(true);
                $t->boolean('colour_ok')->default(true);
                $t->string('note', 1000)->nullable();
                $t->timestamps();
                $t->index(['nap_event_id', 'checked_at']);
                $t->index(['centre_id', 'checked_at']);
                $t->index(['child_id', 'checked_at']);
                $t->index(['agency_id', 'checked_at']);
            });
        }

        if (! Schema::hasTable('sleep_check_alerts')) {
            Schema::create('sleep_check_alerts', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('nap_event_id');
                $t->dateTime('due_at');                          // UTC, the check that was missed
                $t->string('level', 16);                         // room | director
                $t->unsignedInteger('recipients')->default(0);
                $t->timestamp('created_at')->nullable();
                $t->unique(['nap_event_id', 'due_at', 'level'], 'sleep_alert_once');
            });
        }

        Schema::table('children', function (Blueprint $t) {
            if (! Schema::hasColumn('children', 'sleep_position_direction')) {
                $t->boolean('sleep_position_direction')->default(false);
            }
            if (! Schema::hasColumn('children', 'sleep_position_direction_note')) {
                $t->string('sleep_position_direction_note', 1000)->nullable();
            }
            if (! Schema::hasColumn('children', 'sleep_position_direction_at')) {
                $t->dateTime('sleep_position_direction_at')->nullable();
            }
            if (! Schema::hasColumn('children', 'sleep_position_direction_by')) {
                $t->unsignedBigInteger('sleep_position_direction_by')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_check_alerts');
        Schema::dropIfExists('sleep_checks');
        Schema::table('children', function (Blueprint $t) {
            foreach (['sleep_position_direction', 'sleep_position_direction_note', 'sleep_position_direction_at', 'sleep_position_direction_by'] as $c) {
                if (Schema::hasColumn('children', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
