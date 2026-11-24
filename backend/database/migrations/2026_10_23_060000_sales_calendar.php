<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The sales calendar (2026-10-23, Anthony: "build out a sales calendar under sales to show
 * all of the bookings and whereabouts of sales staff including allowing sales staff to show
 * their availability and book/block time off").
 *   sales_availability     a person's weekly working hours (several spans per weekday allowed)
 *   sales_calendar_events  meetings, blocked time, time off and whereabouts, per person
 * Times are stored UTC; the calendar is shown in Eastern Time like the bookings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sales_availability')) {
            Schema::create('sales_availability', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->unsignedTinyInteger('weekday');          // 0 = Sunday … 6 = Saturday
                $t->string('start_time', 5);                 // HH:MM, Eastern
                $t->string('end_time', 5);
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('sales_calendar_events')) {
            Schema::create('sales_calendar_events', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('user_id')->index();
                $t->string('kind', 16);                      // meeting | block | time_off | where
                $t->string('title', 200)->nullable();
                $t->dateTime('starts_at')->index();
                $t->dateTime('ends_at')->index();
                $t->boolean('all_day')->default(false);
                $t->string('where', 30)->nullable();         // office | remote | road | client | out
                $t->string('location', 200)->nullable();
                $t->text('notes')->nullable();
                $t->unsignedBigInteger('lead_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
            DB::statement('ALTER TABLE sales_calendar_events CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_availability');
        Schema::dropIfExists('sales_calendar_events');
    }
};
