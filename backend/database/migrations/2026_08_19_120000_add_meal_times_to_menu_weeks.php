<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Serving times for a menu week.
 *
 * A menu recorded what was being served and never when. Parents asked, and staff had
 * no answer in the system.
 *
 * One time per MEAL, not per cell: a centre serves lunch at the same time every day,
 * and twenty-five time pickers a week would be a worse question than not asking. Per
 * week rather than per centre so a summer or holiday timetable can differ without
 * rewriting the centre.
 *
 * TEXT holding JSON rather than a JSON column: json columns on this host carry a
 * json_valid CHECK constraint that has bitten writes here before, and this value is
 * only ever read and written whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_weeks', function (Blueprint $table) {
            $table->text('meal_times')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('menu_weeks', function (Blueprint $table) {
            $table->dropColumn('meal_times');
        });
    }
};
