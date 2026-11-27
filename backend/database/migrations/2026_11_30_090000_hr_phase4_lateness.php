<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * KiddieTrac HR Phase 4, batch 1 (2026-11-27, App\Support\HrAttendance): a clock-in compared with its scheduled shift.
 *  - late_minutes: minutes after the shift start (0 = on time or early); null = not compared (no shift, not the first
 *    clock-in of the shift, automatic, contractor, or no HR add-on). The grace window is applied when reading.
 *  - late_shift_id: the shift it was compared with. late_checked_at: when (a correction after it re-checks the punch).
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('time_punches', 'late_minutes')) {
            Schema::table('time_punches', function (Blueprint $t) {
                $t->unsignedSmallInteger('late_minutes')->nullable();
                $t->unsignedBigInteger('late_shift_id')->nullable();
                $t->timestamp('late_checked_at')->nullable();
                $t->index(['user_id', 'late_checked_at'], 'tp_user_late_checked');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('time_punches', 'late_minutes')) {
            Schema::table('time_punches', function (Blueprint $t) {
                $t->dropIndex('tp_user_late_checked');
                $t->dropColumn(['late_minutes', 'late_shift_id', 'late_checked_at']);
            });
        }
    }
};
