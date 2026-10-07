<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* When each agency was last sent its time-off approval reminders (App\Support\TimeOffReminders).
   One row per agency; last_sent_on is the agency-local date, so "already sent today" is a
   single conditional UPDATE that overlapping scheduler runs cannot both win. */
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('timeoff_reminder_runs')) return;
        Schema::create('timeoff_reminder_runs', function (Blueprint $t) {
            $t->unsignedBigInteger('agency_id')->primary();
            $t->date('last_sent_on')->nullable();
            $t->timestamp('last_sent_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timeoff_reminder_runs');
    }
};
