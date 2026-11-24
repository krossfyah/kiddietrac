<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lunch and breaks for educators (2026-11-05). See App\Support\StaffBreaks.
 *
 * time_punches.out_reason: why a shift row closed - NULL for a normal clock-out,
 * 'lunch' / 'break' when it closed for a break the person came (or will come) back from.
 * time_punch_breaks: one row per break, open while ended_at is NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('time_punches', 'out_reason')) {
            Schema::table('time_punches', function (Blueprint $t) {
                $t->string('out_reason', 16)->nullable()->after('punched_out_at');
            });
        }
        if (! Schema::hasTable('time_punch_breaks')) {
            Schema::create('time_punch_breaks', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id');
                $t->unsignedBigInteger('centre_id')->nullable();
                $t->unsignedBigInteger('punch_id');              // the shift row the break closed
                $t->unsignedBigInteger('return_punch_id')->nullable();  // the row it reopened / started
                $t->string('kind', 12);                          // lunch | break
                $t->boolean('paid')->default(false);             // the agency rule when it STARTED
                $t->timestamp('started_at');
                $t->timestamp('ended_at')->nullable();
                $t->string('ended_how', 16)->nullable();         // back | day_end | edited
                $t->timestamps();
                $t->index(['user_id', 'ended_at']);
                $t->index('punch_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('time_punch_breaks');
        if (Schema::hasColumn('time_punches', 'out_reason')) {
            Schema::table('time_punches', fn (Blueprint $t) => $t->dropColumn('out_reason'));
        }
    }
};
