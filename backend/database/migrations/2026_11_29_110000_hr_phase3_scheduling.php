<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 3, item 10 (2026-11-26): scheduling upgrades on the EXISTING shifts table (App\Support\HrAvailability).
 *  - hr_availability: a person's recurring weekly availability (no rows = available any time);
 *  - hr_unavailability: one-off "can't work" dates (all day, or a time window);
 *  - hr_shift_swaps: give a shift away or swap it, accepted by a colleague, approved by a director / admin.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('hr_availability')) {
            Schema::create('hr_availability', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->unsignedTinyInteger('dow');                 // ISO: 1 = Monday ... 7 = Sunday
                $t->time('start_time');
                $t->time('end_time');
                $t->timestamps();
                $t->index(['agency_id', 'user_id']);
            });
        }
        if (! Schema::hasTable('hr_unavailability')) {
            Schema::create('hr_unavailability', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->date('date_from');
                $t->date('date_to');
                $t->time('start_time')->nullable();            // null = all day
                $t->time('end_time')->nullable();
                $t->string('reason', 200)->nullable();
                $t->timestamps();
                $t->index(['agency_id', 'user_id', 'date_from']);
            });
        }
        if (! Schema::hasTable('hr_shift_swaps')) {
            Schema::create('hr_shift_swaps', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id')->index();
                $t->unsignedBigInteger('centre_id')->nullable()->index();
                $t->unsignedBigInteger('shift_id')->index();
                $t->unsignedBigInteger('from_user_id')->index();
                $t->string('kind', 16)->default('giveaway');    // giveaway | swap
                $t->unsignedBigInteger('to_user_id')->nullable(); // named colleague; null = anyone at the centre (giveaway)
                $t->unsignedBigInteger('swap_shift_id')->nullable(); // the colleague's shift in a swap
                $t->unsignedBigInteger('accepted_by_id')->nullable();
                $t->dateTime('accepted_at')->nullable();
                $t->string('status', 16)->default('open');      // open | accepted | approved | declined | cancelled
                $t->string('note', 300)->nullable();
                $t->unsignedBigInteger('decided_by_id')->nullable();
                $t->dateTime('decided_at')->nullable();
                $t->string('decision_comment', 500)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_shift_swaps');
        Schema::dropIfExists('hr_unavailability');
        Schema::dropIfExists('hr_availability');
    }
};
