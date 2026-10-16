<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per educator per day the daily-records check ran (2026-10-15): what was
 * missing, how many days in the window had gaps, whether it was escalated and emailed.
 * The unique key is what stops a second email for the same day.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('record_reminders')) return;
        Schema::create('record_reminders', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('agency_id')->index();
            $t->unsignedBigInteger('centre_id')->nullable()->index();
            $t->unsignedBigInteger('user_id');
            $t->date('for_date');
            $t->json('missing')->nullable();
            $t->unsignedSmallInteger('days_in_window')->default(0);
            $t->boolean('escalated')->default(false);
            $t->boolean('emailed')->default(false);
            $t->timestamp('created_at')->nullable();
            $t->unique(['user_id', 'for_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('record_reminders');
    }
};
